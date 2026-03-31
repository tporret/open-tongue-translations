<?php
/**
 * The only class in the plugin that is permitted to execute SQL queries.
 *
 * All $wpdb interactions are consolidated here. No other class — not drivers,
 * not cache managers, not interceptors — may call $wpdb directly. This boundary
 * makes it trivial to audit every SQL statement the plugin runs.
 *
 * @internal
 * @package OpenToungeTranslations\Database
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database;

/**
 * Class TranslationRepository
 *
 * Provides typed read and write access to the {prefix}libre_translations table.
 * Every query is prepared via $wpdb->prepare() — zero raw string interpolation
 * for user-controlled values.
 *
 * Table name is always retrieved via Schema::tableName() — never a string literal.
 *
 * @internal This class is an infrastructure detail. Consumers should interact
 *           with the plugin via CacheManager, not this repository directly.
 */
final class TranslationRepository {

	/**
	 * Find a single translation row by its hash and target language.
	 *
	 * Also updates the last_used timestamp on a hit so the pruning job can
	 * correctly identify stale rows. The update uses a separate lightweight
	 * query to avoid locking the row during the read.
	 *
	 * @param string $hash      MD5 hash of the source text (from TranslationRecord::computeHash()).
	 * @param string $targetLang BCP-47 target language code.
	 *
	 * @return TranslationRecord|null The record, or null on a cache miss.
	 */
	public function find( string $hash, string $targetLang ): ?TranslationRecord {
		global $wpdb;

		$table = Schema::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE string_hash = %s AND target_lang = %s LIMIT 1",
				$hash,
				$targetLang
			),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		// Touch last_used so infrequently-accessed rows rank correctly for pruning.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_used = NOW() WHERE string_hash = %s AND target_lang = %s",
				$hash,
				$targetLang
			)
		);

		return TranslationRecord::fromRow( $row );
	}

	/**
	 * Fetch multiple translation rows in a single IN query.
	 *
	 * Designed for OutputBufferInterceptor which may request 200+ strings per
	 * page. Uses a single SELECT … WHERE string_hash IN (…) to avoid N+1 queries.
	 *
	 * @param string[] $hashes     Array of MD5 source-text hashes to look up.
	 * @param string   $targetLang BCP-47 target language code.
	 *
	 * @return TranslationRecord[] Indexed by string_hash for O(1) lookups by caller.
	 */
	public function findBatch( array $hashes, string $targetLang ): array {
		if ( empty( $hashes ) ) {
			return [];
		}

		global $wpdb;

		$table = Schema::tableName();

		// Build one %s placeholder per hash — never loop individual queries.
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE string_hash IN ({$placeholders}) AND target_lang = %s",
				...array_merge( $hashes, [ $targetLang ] )
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		$records = [];

		foreach ( $rows as $row ) {
			$record                   = TranslationRecord::fromRow( $row );
			$records[ $record->hash ] = $record;
		}

		return $records;
	}

	/**
	 * Load all translation rows for a given target locale.
	 *
	 * Used by CacheManager::warmLocale() to pre-populate the object cache.
	 * A hard limit prevents OOM errors on tables with millions of rows.
	 *
	 * @param string $targetLang BCP-47 target language code.
	 * @param int    $limit      Maximum rows to return. Defaults to 5000.
	 *
	 * @return TranslationRecord[] Indexed by string_hash.
	 */
	public function findByLocale( string $targetLang, int $limit = 5000 ): array {
		global $wpdb;

		$table = Schema::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE target_lang = %s ORDER BY last_used DESC LIMIT %d",
				$targetLang,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		$records = [];

		foreach ( $rows as $row ) {
			$record                   = TranslationRecord::fromRow( $row );
			$records[ $record->hash ] = $record;
		}

		return $records;
	}

	/**
	 * Insert or update a single translation record.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so a single query handles both
	 * first-time inserts and subsequent API re-translations.
	 *
	 * CRITICAL: the IF(is_manual = 1, …) guard means human-edited translations
	 * are NEVER overwritten by the machine translation API. Once a translator
	 * sets is_manual = 1, that row's translated_text is immutable via this method.
	 *
	 * @param TranslationRecord $record The record to insert or update.
	 *
	 * @return bool True on success, false if $wpdb->last_error is non-empty.
	 */
	public function upsert( TranslationRecord $record ): bool {
		global $wpdb;

		$table = Schema::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(string_hash, source_lang, target_lang, source_text, translated_text, context, is_manual)
				VALUES (%s, %s, %s, %s, %s, %s, %d)
				ON DUPLICATE KEY UPDATE
					translated_text = IF(is_manual = 1, translated_text, VALUES(translated_text)),
					source_text     = IF(is_manual = 1, source_text, VALUES(source_text)),
					last_used       = NOW()",
				$record->hash,
				$record->sourceLang,
				$record->targetLang,
				$record->sourceText,
				$record->translatedText,
				$record->context,
				(int) $record->isManual
			)
		);

		if ( ! empty( $wpdb->last_error ) ) {
			error_log(
				sprintf(
					'[OpenTongue] TranslationRepository::upsert() error: %s',
					$wpdb->last_error
				)
			);
			return false;
		}

		return $result !== false;
	}

	/**
	 * Insert or update multiple translation records in batched multi-row queries.
	 *
	 * Input is chunked at 500 records per INSERT to avoid exceeding MySQL's
	 * max_allowed_packet limit on shared hosting environments (commonly 1–16 MB).
	 * MEDIUMTEXT columns can hold up to 16 MB each, so a single row can be large;
	 * 500-row chunks stay well within safe packet sizes for average strings.
	 *
	 * The same IF(is_manual = 1, …) guard as upsert() applies to every row.
	 *
	 * @param TranslationRecord[] $records Array of records to insert or update.
	 *
	 * @return int Total number of rows affected across all chunks.
	 */
	public function upsertBatch( array $records ): int {
		if ( empty( $records ) ) {
			return 0;
		}

		global $wpdb;

		$table         = Schema::tableName();
		$chunks        = array_chunk( $records, 500 );
		$totalAffected = 0;

		foreach ( $chunks as $chunk ) {
			$rowPlaceholders = [];
			$values          = [];

			foreach ( $chunk as $record ) {
				$rowPlaceholders[] = '(%s, %s, %s, %s, %s, %s, %d)';
				array_push(
					$values,
					$record->hash,
					$record->sourceLang,
					$record->targetLang,
					$record->sourceText,
					$record->translatedText,
					$record->context,
					(int) $record->isManual
				);
			}

			$placeholderString = implode( ', ', $rowPlaceholders );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(string_hash, source_lang, target_lang, source_text, translated_text, context, is_manual)
					VALUES {$placeholderString}
					ON DUPLICATE KEY UPDATE
						translated_text = IF(is_manual = 1, translated_text, VALUES(translated_text)),
						source_text     = IF(is_manual = 1, source_text, VALUES(source_text)),
						last_used       = NOW()",
					...$values
				)
			);

			if ( ! empty( $wpdb->last_error ) ) {
				error_log(
					sprintf(
						'[OpenTongue] TranslationRepository::upsertBatch() chunk error: %s',
						$wpdb->last_error
					)
				);
				continue;
			}

			if ( $result !== false ) {
				$totalAffected += (int) $result;
			}
		}

		return $totalAffected;
	}

	/**
	 * Delete stale rows older than the given cutoff date.
	 *
	 * Limited to $batchSize rows per query to avoid long table locks on large
	 * tables. Callers (PruningJob) must loop until fewer than $batchSize rows
	 * are returned to drain large backlogs.
	 *
	 * Human-edited rows (is_manual = 1) are NEVER deleted.
	 *
	 * @param \DateTimeImmutable $cutoff     Rows with last_used older than this are eligible.
	 * @param int                $batchSize  Maximum rows to delete per call. Default 1000.
	 *
	 * @return int Number of rows deleted in this call.
	 */
	public function pruneOlderThan( \DateTimeImmutable $cutoff, int $batchSize = 1000 ): int {
		global $wpdb;

		$table      = Schema::tableName();
		$cutoffStr  = $cutoff->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE last_used < %s AND is_manual = 0 LIMIT %d",
				$cutoffStr,
				$batchSize
			)
		);

		if ( $result === false ) {
			error_log(
				sprintf(
					'[OpenTongue] TranslationRepository::pruneOlderThan() error: %s',
					$wpdb->last_error
				)
			);
			return 0;
		}

		return (int) $result;
	}

	/**
	 * Return a count of cached translations grouped by target locale.
	 *
	 * Used by the admin dashboard to display per-language cache statistics.
	 *
	 * @return array<string, int> Map of target_lang => row count, e.g. ['fr' => 12500, 'de' => 8200].
	 */
	public function countByLocale(): array {
		global $wpdb;

		$table = Schema::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT target_lang, COUNT(*) AS cnt FROM {$table} GROUP BY target_lang",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		$result = [];

		foreach ( $rows as $row ) {
			$result[ (string) $row['target_lang'] ] = (int) $row['cnt'];
		}

		return $result;
	}
}
