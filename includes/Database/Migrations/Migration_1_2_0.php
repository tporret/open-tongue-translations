<?php
/**
 * Migration 1.2.0 — Create the ott_exclusion_rules table.
 *
 * This migration creates the table that stores HTML exclusion rules.
 * Rules can be scoped globally, to a post type, or to a specific post ID.
 *
 * up()   — create table via dbDelta() (idempotent; safe to call multiple times).
 * down() — drop table and remove the version option (used in uninstall / rollback).
 *
 * @package OpenToungeTranslations\Database\Migrations
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database\Migrations;

/**
 * Class Migration_1_2_0
 */
final class Migration_1_2_0 implements MigrationInterface {

	/** Schema version stored in wp_options after this migration runs. */
	public const VERSION = '1.2.0';

	/** @var \wpdb */
	private readonly \wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Create the ott_exclusion_rules table.
	 *
	 * dbDelta() is the canonical WordPress way to create tables:
	 *   - Safe to run multiple times (no-op if table already matches).
	 *   - Requires exactly two spaces between column definition and the key list.
	 *   - PRIMARY KEY must be defined inline with the column, not as a separate KEY.
	 *
	 * Index notes:
	 *   - idx_scope uses scope_value(191) to stay within the 767-byte index limit
	 *     on MySQL 5.6 / Latin1 tables. WordPress installs on utf8mb4 should also
	 *     not exceed this limit since 191 × 4 = 764 bytes < 767.
	 */
	public function up(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table          = $this->db->prefix . 'ott_exclusion_rules';
		$charset_collate = $this->db->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  rule_type enum('css_selector','regex','xpath') NOT NULL,
  rule_value text NOT NULL,
  scope enum('global','post_type','post_id') NOT NULL DEFAULT 'global',
  scope_value varchar(255) DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) UNSIGNED NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rule_type (rule_type),
  KEY idx_scope (scope, scope_value(191)),
  KEY idx_is_active (is_active)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'ott_db_version', self::VERSION );
	}

	/**
	 * Drop the ott_exclusion_rules table and remove the version option.
	 *
	 * Called during plugin uninstall or explicit rollback via WP-CLI.
	 * Uses IF EXISTS so the operation is safe even if up() was never run.
	 */
	public function down(): void {
		$table = $this->db->prefix . 'ott_exclusion_rules';

		$this->db->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( 'ott_db_version' );
	}
}
