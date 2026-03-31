<?php
/**
 * Migration 1.0.0 — initial schema creation.
 *
 * Delegates DDL work to Schema::createOrUpgrade() and Schema::drop() so that
 * there is no SQL duplication between the migration and the live schema manager.
 * This migration is also the rollback target for a complete plugin uninstall.
 *
 * @package OpenToungeTranslations\Database\Migrations
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database\Migrations;

use OpenToungeTranslations\Database\Schema;

/**
 * Class Migration_1_0_0
 *
 * Creates the {prefix}libre_translations table (up) and drops it (down).
 * Pure DDL — no business logic, no data seeding.
 */
final class Migration_1_0_0 implements MigrationInterface {

	/**
	 * Apply the initial schema: create the translations cache table.
	 *
	 * Delegates to Schema::createOrUpgrade() which uses dbDelta() for
	 * idempotent table creation. Safe to call multiple times.
	 *
	 * @return void
	 */
	public function up(): void {
		error_log( '[OpenTongue] Running migration 1.0.0 up() — creating translations table.' );

		$schema = new Schema();
		$schema->createOrUpgrade();

		error_log( '[OpenTongue] Migration 1.0.0 up() complete.' );
	}

	/**
	 * Reverse the migration: drop the translations table.
	 *
	 * Called exclusively during plugin uninstall. All cached translation data
	 * will be permanently lost. Never called on deactivation.
	 *
	 * @return void
	 */
	public function down(): void {
		error_log( '[OpenTongue] Running migration 1.0.0 down() — dropping translations table.' );

		$schema = new Schema();
		$schema->drop();

		error_log( '[OpenTongue] Migration 1.0.0 down() complete.' );
	}
}
