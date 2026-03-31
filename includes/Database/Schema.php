<?php
/**
 * Database schema manager for the plugin's translations cache table.
 *
 * This class is the single source of truth for the table name and schema version.
 * Every other class that references the table must call Schema::tableName() —
 * never a string literal — so renames propagate automatically.
 *
 * Uses WordPress's dbDelta() for idempotent CREATE / ALTER operations.
 * Reference: https://developer.wordpress.org/reference/functions/dbdelta/
 *
 * @package OpenToungeTranslations\Database
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database;

/**
 * Class Schema
 *
 * Owns table creation, upgrade detection, and destruction. No business logic
 * lives here — pure DDL only.
 *
 * The public static tableName() method is intentionally static so that
 * TranslationRepository and CacheManager can call it without holding a
 * Schema instance.
 */
final class Schema {

	/**
	 * The WordPress option key used to track the current schema version.
	 */
	private const VERSION_OPTION = 'ltp_db_version';

	/**
	 * The schema version this class implements.
	 * Bump this whenever the SQL definition changes.
	 */
	private const CURRENT_VERSION = '1.0.1';

	/**
	 * Per-request memoised table name (avoids repeated $wpdb->prefix reads).
	 */
	private static ?string $tableName = null;

	/**
	 * Return the fully-qualified table name including the WordPress table prefix.
	 *
	 * This is the ONLY method any external class should call to obtain the table
	 * name. Never use a string literal such as 'wp_libre_translations' elsewhere.
	 *
	 * @return string e.g. 'wp_libre_translations'
	 */
	public static function tableName(): string {
		if ( self::$tableName === null ) {
			global $wpdb;
			self::$tableName = $wpdb->prefix . 'libre_translations';
		}

		return self::$tableName;
	}

	/**
	 * Create or upgrade the translations table using dbDelta().
	 *
	 * Compares the stored schema version against CURRENT_VERSION and returns
	 * immediately if they match — this guard ensures dbDelta() is NOT executed
	 * on every request, only when the code version has changed.
	 *
	 * dbDelta() formatting rules (enforced below):
	 *  - Two spaces before PRIMARY KEY opening parenthesis.
	 *  - Each column/key on its own line with two-space indent.
	 *  - KEY keyword (not INDEX). UNIQUE KEY for unique indexes.
	 *  - $wpdb->get_charset_collate() appended — never hardcoded collation.
	 *
	 * @return void
	 */
	public function createOrUpgrade(): void {
		$currentVersion = (string) get_option( self::VERSION_OPTION, '0.0.0' );

		if ( version_compare( $currentVersion, self::CURRENT_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table         = self::tableName();
		$charsetCollate = $wpdb->get_charset_collate();

		// dbDelta() requires a very specific SQL format.
		// See: https://developer.wordpress.org/reference/functions/dbdelta/#user-contributed-notes
		//
		// Rules enforced here:
		//  1. Two spaces of indent before every column and key line.
		//  2. PRIMARY KEY  (id) — note TWO spaces before the opening parenthesis.
		//  3. UNIQUE KEY and KEY — each on its own line.
		//  4. No backtick quoting on column names (supported but avoided for safety).
		//  5. Charset collate appended — never hardcoded.
		//
		// dbDelta() cannot diff ON UPDATE CURRENT_TIMESTAMP — if that clause were
		// present it would attempt to re-execute CREATE TABLE on every version mismatch,
		// producing a redundant query and a false schema-changed log entry. The last_used
		// column is managed explicitly by TranslationRepository (SET last_used = NOW()),
		// so the automatic trigger is unnecessary and has been removed entirely.
		//
		// RULE: never add ON UPDATE CURRENT_TIMESTAMP to this table. Any future
		// changes to the last_used column must be performed via ALTER TABLE inside a
		// numbered migration, not via dbDelta().
		$sql = "CREATE TABLE {$table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  string_hash CHAR(32) NOT NULL,
  source_lang VARCHAR(10) NOT NULL,
  target_lang VARCHAR(10) NOT NULL,
  source_text MEDIUMTEXT NOT NULL,
  translated_text MEDIUMTEXT NOT NULL,
  context VARCHAR(255) NOT NULL DEFAULT '',
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_hash_lang (string_hash, target_lang),
  KEY idx_target_lang (target_lang),
  KEY idx_last_used (last_used),
  KEY idx_is_manual (is_manual)
) {$charsetCollate};";

		// 1.0.0 → 1.0.1: strip ON UPDATE CURRENT_TIMESTAMP from last_used.
		// dbDelta() cannot detect this clause change, so we issue an explicit ALTER.
		// Fresh installs (currentVersion === '0.0.0') skip this — the CREATE TABLE
		// above already defines last_used without the trigger.
		if (
			version_compare( $currentVersion, '1.0.0', '>=' ) &&
			version_compare( $currentVersion, '1.0.1', '<' )
		) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$table} MODIFY COLUMN last_used DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
			);

			if ( ! empty( $wpdb->last_error ) ) {
				error_log(
					sprintf(
						'[OpenTongue] Schema 1.0.0→1.0.1 ALTER TABLE error: %s',
						$wpdb->last_error
					)
				);
			}
		}

		dbDelta( $sql );

		if ( ! empty( $wpdb->last_error ) ) {
			error_log(
				sprintf(
					'[OpenTongue] Schema::createOrUpgrade() — dbDelta error: %s',
					$wpdb->last_error
				)
			);
			return;
		}

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );

		error_log(
			sprintf(
				'[OpenTongue] Schema upgraded from %s to %s.',
				$currentVersion,
				self::CURRENT_VERSION
			)
		);
	}

	/**
	 * Drop the translations table and remove the schema version option.
	 *
	 * Called ONLY by the uninstall routine. Not called on deactivation.
	 * This is a destructive, irreversible operation.
	 *
	 * @return void
	 */
	public function drop(): void {
		global $wpdb;

		$table = self::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( self::VERSION_OPTION );

		// Reset memoised name so tests can reinitialise cleanly.
		self::$tableName = null;

		error_log( '[OpenTongue] Schema::drop() — table dropped and version option removed.' );
	}
}
