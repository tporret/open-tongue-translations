<?php
/**
 * L2 cache driver: persistent database fallback via TranslationRepository.
 *
 * Acts as the read layer when the object cache (L1) misses. Write operations
 * (set / setMany) are intentionally no-ops on this driver because database
 * writes for new translations always originate from the interception pipeline
 * calling CacheManager::store(), which writes records with full context
 * (source_text, source_lang, context) directly through TranslationRepository.
 *
 * The cache interface contract is satisfied for reads; the write methods are
 * documented as no-ops with clear rationale.
 *
 * @package OpenToungeTranslations\Cache
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cache;

use OpenToungeTranslations\Cache\Contracts\TranslationCacheInterface;
use OpenToungeTranslations\Database\TranslationRepository;

/**
 * Class DatabaseCacheDriver
 *
 * Thin read-only wrapper around TranslationRepository. CacheManager uses this
 * as its L2 layer: when the object cache misses, a single findBatch() call
 * retrieves all outstanding hashes from the DB in one query.
 */
final class DatabaseCacheDriver implements TranslationCacheInterface {

	/**
	 * @param TranslationRepository $repo The repository to delegate DB reads to.
	 */
	public function __construct(
		private readonly TranslationRepository $repo,
	) {}

	/**
	 * Retrieve a single translated string from the database.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return string|null The translated string, or null on a DB miss.
	 */
	public function get( string $hash, string $locale ): ?string {
		$record = $this->repo->find( $hash, $locale );

		return $record?->translatedText;
	}

	/**
	 * Retrieve multiple translated strings from the database in a single query.
	 *
	 * Directly delegates to TranslationRepository::findBatch() to issue one
	 * SELECT … WHERE string_hash IN (…) query regardless of how many hashes
	 * are requested. This is the performance cornerstone of the L2 layer.
	 *
	 * @param string[] $hashes Array of MD5 source-text hashes.
	 * @param string   $locale BCP-47 target language code.
	 *
	 * @return array<string, string> Map of hash => translated_text for DB hits.
	 */
	public function getMany( array $hashes, string $locale ): array {
		$records = $this->repo->findBatch( $hashes, $locale );

		$result = [];
		foreach ( $records as $hash => $record ) {
			$result[ $hash ] = $record->translatedText;
		}

		return $result;
	}

	/**
	 * No-op: database writes for new translations are performed by CacheManager
	 * using TranslationRepository::upsertBatch() with full TranslationRecord
	 * objects (which carry source_text, source_lang, context). The cache
	 * interface's set() signature only provides hash + translated_text, which
	 * is insufficient to construct a valid database row.
	 *
	 * TTL is ignored — the database layer uses last_used + pruning instead of
	 * time-bounded expiry.
	 *
	 * @param string $hash   Ignored.
	 * @param string $locale Ignored.
	 * @param string $value  Ignored.
	 * @param int    $ttl    Ignored.
	 *
	 * @return void
	 */
	public function set( string $hash, string $locale, string $value, int $ttl = 86400 ): void {
		// Intentional no-op. See class docblock.
	}

	/**
	 * No-op: same rationale as set(). Bulk DB writes happen via
	 * CacheManager::store(TranslationRecord[]) → TranslationRepository::upsertBatch().
	 *
	 * TTL is ignored — the database layer does not support time-bounded expiry.
	 *
	 * @param array<string, string> $translations Ignored.
	 * @param string                $locale       Ignored.
	 * @param int                   $ttl          Ignored.
	 *
	 * @return void
	 */
	public function setMany( array $translations, string $locale, int $ttl = 86400 ): void {
		// Intentional no-op. See class docblock.
	}

	/**
	 * Delete a single translation row from the database.
	 *
	 * Useful for targeted cache busting after a human editor updates a translation.
	 * The row is hard-deleted regardless of is_manual status — the admin UI is
	 * responsible for calling this only when appropriate.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return void
	 */
	public function delete( string $hash, string $locale ): void {
		global $wpdb;

		$table = \OpenToungeTranslations\Database\Schema::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE string_hash = %s AND target_lang = %s",
				$hash,
				$locale
			)
		);
	}

	/**
	 * Purge all translations for a given locale from the database.
	 *
	 * Excludes human-edited rows (is_manual = 1) — those are permanent until
	 * explicitly removed by an admin.
	 *
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return void
	 */
	public function flushLocale( string $locale ): void {
		global $wpdb;

		$table = \OpenToungeTranslations\Database\Schema::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE target_lang = %s AND is_manual = 0",
				$locale
			)
		);
	}
}
