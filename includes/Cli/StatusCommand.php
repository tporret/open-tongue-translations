<?php
/**
 * WP-CLI command: wp ott status
 *
 * Runs a comprehensive health check across all OTT subsystems and outputs a
 * colour-coded results table. Exits with code 1 if any critical check fails,
 * enabling use in CI/CD pipelines.
 *
 * @package OpenToungeTranslations\Cli
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cli;

use OpenToungeTranslations\Compat\CloudflareCompat;
use OpenToungeTranslations\Compat\WpRocketCompat;
use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;
use OpenToungeTranslations\Database\Schema;
use OpenToungeTranslations\Database\TranslationRepository;

/**
 * Class StatusCommand
 *
 * ## Exit codes
 * 0 — all ✓ checks passed (warnings are allowed)
 * 1 — one or more ✗ checks failed (WP_CLI::error() is called)
 */
final class StatusCommand extends BaseCommand {

	/**
	 * Required PHP extensions for full plugin functionality.
	 */
	private const REQUIRED_EXTENSIONS = [ 'curl', 'mbstring', 'dom' ];

	/**
	 * Check symbol for a passing check.
	 */
	private const PASS = '✓';

	/**
	 * Check symbol for a failing check.
	 */
	private const FAIL = '✗';

	/**
	 * Check symbol for a advisory warning.
	 */
	private const WARN = '!';

	public function __construct(
		private readonly TranslationClientInterface $client,
		private readonly TranslationRepository $repo,
	) {}

	// =========================================================================
	// Subcommand: (default)
	// =========================================================================

	/**
	 * Run a full health check across all plugin subsystems.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Also display active locales, DB row counts per locale, and PHP
	 *   extension availability.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott status
	 *   wp ott status --verbose
	 *   wp ott status --format=json
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		$verbose = ! empty( $assocArgs['verbose'] );
		$format  = (string) ( $assocArgs['format'] ?? 'table' );
		$failed  = false;
		$rows    = [];

		// --- Check 1: DB tables exist -----------------------------------------
		global $wpdb;
		$requiredTables = [
			$wpdb->prefix . 'libre_translations',
			$wpdb->prefix . 'ott_exclusion_rules',
		];

		foreach ( $requiredTables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
			$rows[] = [
				'check'  => $exists ? self::PASS : self::FAIL,
				'system' => 'DB table',
				'detail' => $table . ( $exists ? ' (exists)' : ' (MISSING)' ),
			];
			if ( ! $exists ) {
				$failed = true;
			}
		}

		// --- Check 2: Translation API reachable --------------------------------
		try {
			$apiOk  = $this->client->healthCheck();
			$rows[] = [
				'check'  => $apiOk ? self::PASS : self::FAIL,
				'system' => 'Translation API',
				'detail' => $apiOk ? 'LibreTranslate reachable' : 'LibreTranslate UNREACHABLE',
			];
			if ( ! $apiOk ) {
				$failed = true;
			}
		} catch ( \Throwable $e ) {
			$rows[] = [
				'check'  => self::FAIL,
				'system' => 'Translation API',
				'detail' => 'Exception: ' . $e->getMessage(),
			];
			$failed = true;
		}

		// --- Check 3: Object cache backend active ------------------------------
		$hasExtCache = wp_using_ext_object_cache();
		$rows[]      = [
			'check'  => $hasExtCache ? self::PASS : self::WARN,
			'system' => 'Object cache',
			'detail' => $hasExtCache
				? 'Persistent backend active (Redis/Memcached)'
				: 'No persistent object cache — translations served from DB on every miss',
		];

		// --- Check 4: WP-Cron scheduled hooks ---------------------------------
		$cronScheduled = (bool) wp_next_scheduled( 'ltp_prune_translations' );
		$rows[]        = [
			'check'  => $cronScheduled ? self::PASS : self::FAIL,
			'system' => 'WP-Cron',
			'detail' => $cronScheduled
				? 'ltp_prune_translations scheduled'
				: 'ltp_prune_translations NOT scheduled — run wp ott status after activating the plugin',
		];
		if ( ! $cronScheduled ) {
			$failed = true;
		}

		// --- Check 5: URL strategy + rewrite rules ----------------------------
		$urlStrategy  = (string) get_option( 'ott_url_strategy', '(not configured)' );
		$rewritesFlushed = (bool) get_option( 'rewrite_rules' );
		$rows[]        = [
			'check'  => $rewritesFlushed ? self::PASS : self::WARN,
			'system' => 'URL strategy',
			'detail' => sprintf(
				'Strategy: %s | Rewrite rules: %s',
				$urlStrategy,
				$rewritesFlushed ? 'flushed' : 'NOT flushed — run wp rewrite flush'
			),
		];

		// --- Check 6: Compat plugins detected ---------------------------------
		$rocketActive = ( new WpRocketCompat() )->isActive();
		$cfActive     = ( new CloudflareCompat() )->isActive();

		$rows[] = [
			'check'  => self::PASS,
			'system' => 'Compat: WP Rocket',
			'detail' => $rocketActive ? 'Detected — purge hooks registered' : 'Not active',
		];

		$rows[] = [
			'check'  => self::PASS,
			'system' => 'Compat: Cloudflare',
			'detail' => $cfActive ? 'Detected — purge hooks registered' : 'Not active',
		];

		// --- Verbose extras ---------------------------------------------------
		if ( $verbose ) {
			$localeCounts = $this->repo->countByLocale();

			if ( empty( $localeCounts ) ) {
				$rows[] = [
					'check'  => self::WARN,
					'system' => 'Active locales',
					'detail' => 'No translation rows found in database',
				];
			} else {
				foreach ( $localeCounts as $locale => $count ) {
					$rows[] = [
						'check'  => self::PASS,
						'system' => sprintf( 'Locale: %s', $locale ),
						'detail' => sprintf( '%d row(s) in DB', $count ),
					];
				}
			}

			foreach ( self::REQUIRED_EXTENSIONS as $ext ) {
				$loaded = extension_loaded( $ext );
				$rows[] = [
					'check'  => $loaded ? self::PASS : self::FAIL,
					'system' => sprintf( 'PHP ext: %s', $ext ),
					'detail' => $loaded ? 'Loaded' : 'MISSING — required for HTML-aware translation',
				];
				if ( ! $loaded ) {
					$failed = true;
				}
			}
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'check', 'system', 'detail' ] );

		if ( $failed ) {
			// WP_CLI::error() exits with code 1.
			$this->log( 'One or more checks failed. See table above.', 'error' );
		}

		$this->log( 'All checks passed.', 'success' );
	}
}
