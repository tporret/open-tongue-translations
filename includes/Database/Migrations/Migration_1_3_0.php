<?php
/**
 * Migration 1.3.0 — Create the ott_integrity_log table.
 *
 * Records every TagProtector token-mismatch event so administrators can
 * identify strings that need an Exclusion Rule to prevent HTML mangling.
 *
 * up()   — create table via dbDelta() (idempotent; safe to call multiple times).
 * down() — drop table and roll back the version option.
 *
 * @package OpenToungeTranslations\Database\Migrations
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database\Migrations;

/**
 * Class Migration_1_3_0
 */
final class Migration_1_3_0 implements MigrationInterface {

	/** Schema version stored in wp_options after this migration runs. */
	public const VERSION = '1.3.0';

	/** @var \wpdb */
	private readonly \wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Create the ott_integrity_log table.
	 *
	 * Columns:
	 *   id              — auto-increment primary key.
	 *   source_hash     — MD5 of source_text, used for de-duplication queries.
	 *   source_text     — the original HTML string that caused the mismatch (mediumtext).
	 *   target_lang     — BCP-47 locale the translation was attempted into.
	 *   expected_tokens — how many [[OTT_TAG_n]] placeholders were in the protected string.
	 *   found_tokens    — how many survived in the translated output (0 = completely garbled).
	 *   request_url     — the REQUEST_URI at the time of the failure.
	 *   occurred_at     — timestamp of the failure.
	 */
	public function up(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $this->db->prefix . 'ott_integrity_log';
		$charset_collate = $this->db->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  source_hash char(32) NOT NULL,
  source_text mediumtext NOT NULL,
  target_lang varchar(20) NOT NULL,
  expected_tokens int(11) NOT NULL DEFAULT 0,
  found_tokens int(11) NOT NULL DEFAULT 0,
  request_url varchar(2048) NOT NULL DEFAULT '',
  occurred_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_source_hash (source_hash),
  KEY idx_target_lang (target_lang),
  KEY idx_occurred_at (occurred_at)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'ott_db_version', self::VERSION );
	}

	/**
	 * Drop the ott_integrity_log table and remove the version option.
	 */
	public function down(): void {
		$table = $this->db->prefix . 'ott_integrity_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'ott_db_version' );
	}
}
