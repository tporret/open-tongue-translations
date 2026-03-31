<?php
/**
 * Plugin Name:       Open Tongue Translations
 * Plugin URI:        https://github.com/open-tongue-translations
 * Description:       A privacy-first WordPress translation plugin. Core guarantee: no translation data ever leaves the host server.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            tporret
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-tongue-translations
 * Domain Path:       /languages
 *
 * @package OpenToungeTranslations
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 autoloader for the OpenToungeTranslations\ namespace.
 * Maps namespace segments to files under includes/.
 */
spl_autoload_register( function ( string $class ): void {
	$prefix   = 'OpenToungeTranslations\\';
	$base_dir = __DIR__ . '/includes/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

/**
 * Activation: create DB table and schedule the pruning cron job.
 */
register_activation_hook( __FILE__, function (): void {
	$schema = new OpenToungeTranslations\Database\Schema();
	$schema->createOrUpgrade();

	OpenToungeTranslations\Maintenance\PruningJob::scheduleOnActivation();
} );

/**
 * Deactivation: remove the scheduled pruning cron job.
 * The DB table is intentionally preserved on deactivation.
 */
register_deactivation_hook( __FILE__, function (): void {
	OpenToungeTranslations\Maintenance\PruningJob::clearOnDeactivation();
} );

/**
 * Boot the plugin after all plugins are loaded so that WP functions
 * and option values are guaranteed to be available.
 *
 * WP-CLI commands are registered inside Plugin::boot() using fully-wired
 * dependency instances, so they must not be registered here directly.
 */
add_action( 'plugins_loaded', function (): void {
	$plugin = new OpenToungeTranslations\Core\Plugin();
	$plugin->boot();
} );

/**
 * Initialise the admin settings UI on every admin request (including AJAX),
 * but never on the front end.
 */
add_action( 'plugins_loaded', function (): void {
	if ( is_admin() ) {
		OpenToungeTranslations\Admin\OTT_Admin_Settings::get_instance()->init();
	}
} );

/**
 * Safety net: if WP-CLI is available but plugins_loaded has already fired
 * (e.g. when WP-CLI bootstraps the environment), register commands via the
 * `cli_init` hook which always fires after `plugins_loaded`.
 *
 * In normal execution Plugin::boot() handles command registration; this block
 * acts only as a guard to ensure commands are never silently unavailable.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_hook( 'before_wp_load', static function (): void {
		// Intentionally empty — forces WP-CLI to complete WP bootstrap before
		// our commands attempt to access wpdb or wp_options.
	} );
}
