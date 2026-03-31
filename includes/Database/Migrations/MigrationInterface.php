<?php
/**
 * Contract for all versioned database migrations.
 *
 * Each migration class implements exactly this interface and is responsible
 * for one schema version transition. Migrations have no constructor args —
 * they rely on the global $wpdb and WordPress functions only.
 *
 * @package OpenToungeTranslations\Database\Migrations
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database\Migrations;

/**
 * Interface MigrationInterface
 *
 * Defines the forward (up) and rollback (down) contract for every database
 * migration. The up() method is called on plugin activation and upgrades;
 * down() is called exclusively on plugin uninstall — never on deactivation.
 */
interface MigrationInterface {

	/**
	 * Apply the migration: create tables, add columns, insert seed data, etc.
	 *
	 * Implementations must be idempotent — running up() on an already-migrated
	 * database must not raise errors or corrupt existing data. dbDelta() handles
	 * this for DDL; data migrations must guard with IF NOT EXISTS checks.
	 *
	 * @return void
	 */
	public function up(): void;

	/**
	 * Reverse the migration: drop tables or columns added by up().
	 *
	 * Called ONLY during plugin uninstall. Must leave the database in the state
	 * it was in before up() ran. Implementations should use DROP TABLE IF EXISTS
	 * and similar defensive DDL.
	 *
	 * @return void
	 */
	public function down(): void;
}
