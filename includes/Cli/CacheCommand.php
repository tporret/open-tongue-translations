<?php
/**
 * WP-CLI command: wp ott cache
 *
 * Provides three sub-commands for managing the OTT translation cache:
 *
 *   wp ott cache warm    — pre-populate the object cache from the database
 *   wp ott cache flush   — invalidate cached translations per locale or all
 *   wp ott cache status  — display cache metrics and system info
 *
 * @package OpenToungeTranslations\Cli
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cli;

use OpenToungeTranslations\Cache\CacheManager;
use OpenToungeTranslations\Database\TranslationRepository;

/**
 * Class CacheCommand
 */
final class CacheCommand extends BaseCommand {

	public function __construct(
		private readonly CacheManager $cacheManager,
		private readonly TranslationRepository $repo,
	) {}

	// =========================================================================
	// Subcommand: warm
	// =========================================================================

	/**
	 * Pre-populate the object cache with translations from the database.
	 *
	 * ## OPTIONS
	 *
	 * [--locale=<locale>]
	 * : BCP-47 target language code to warm. May be specified multiple times.
	 *
	 * [--all-locales]
	 * : Warm every locale that has rows in the database.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott cache warm --locale=fr
	 *   wp ott cache warm --locale=fr --locale=de
	 *   wp ott cache warm --all-locales
	 *
	 * @subcommand warm
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function warm( array $args, array $assocArgs ): void {
		$locales = $this->resolveLocales( $assocArgs );

		if ( empty( $locales ) ) {
			$this->log( 'Specify --locale=<locale> or --all-locales.', 'error' );
		}

		$rows = [];

		foreach ( $locales as $locale ) {
			$this->log( sprintf( 'Warming locale "%s"…', $locale ) );
			$before = (int) round( microtime( true ) * 1000 );
			$this->cacheManager->warmLocale( $locale );
			$elapsed = (int) round( microtime( true ) * 1000 ) - $before;

			$count = $this->repo->countByLocale()[ $locale ] ?? 0;

			$rows[] = [
				'locale'     => $locale,
				'db_rows'    => $count,
				'elapsed_ms' => $elapsed,
				'status'     => 'warmed',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'locale', 'db_rows', 'elapsed_ms', 'status' ] );
		$this->log( 'Cache warm complete.', 'success' );
	}

	// =========================================================================
	// Subcommand: flush
	// =========================================================================

	/**
	 * Flush the OTT translation cache for one or all locales.
	 *
	 * ## OPTIONS
	 *
	 * [--locale=<locale>]
	 * : Flush only this locale's version counter (L1) and DB rows (L2).
	 *   Omit to flush every active locale.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott cache flush --locale=fr --yes
	 *   wp ott cache flush --yes
	 *
	 * @subcommand flush
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function flush( array $args, array $assocArgs ): void {
		$locale = isset( $assocArgs['locale'] ) ? (string) $assocArgs['locale'] : null;

		if ( $locale !== null ) {
			$this->confirm( sprintf( 'Flush cache for locale "%s"?', $locale ), $assocArgs );
			$this->cacheManager->flushLocale( $locale );
			$this->log( sprintf( 'Cache flushed for locale "%s".', $locale ), 'success' );
			return;
		}

		$this->confirm( 'Flush ALL OTT locale caches? This cannot be undone.', $assocArgs );

		$localeCounts = $this->repo->countByLocale();

		if ( empty( $localeCounts ) ) {
			$this->log( 'No locale data found in the database.', 'warning' );
			return;
		}

		foreach ( array_keys( $localeCounts ) as $l ) {
			$this->cacheManager->flushLocale( $l );
			$this->log( sprintf( 'Flushed locale "%s".', $l ) );
		}

		$this->log( sprintf( 'All %d locale(s) flushed.', count( $localeCounts ) ), 'success' );
	}

	// =========================================================================
	// Subcommand: status
	// =========================================================================

	/**
	 * Display cache metrics and object cache backend information.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott cache status
	 *   wp ott cache status --format=json
	 *
	 * @subcommand status
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function status( array $args, array $assocArgs ): void {
		$format    = (string) ( $assocArgs['format'] ?? 'table' );
		$backend   = wp_using_ext_object_cache() ? 'persistent (Redis/Memcached)' : 'non-persistent (in-memory)';
		$lastPrune = (string) get_option( 'ott_last_prune', 'never' );
		$counts    = $this->repo->countByLocale();
		$totalRows = array_sum( $counts );

		$rows = [];

		foreach ( $counts as $locale => $count ) {
			$rows[] = [
				'locale'         => $locale,
				'db_rows'        => $count,
				'cache_backend'  => $backend,
				'last_prune'     => $lastPrune,
			];
		}

		if ( empty( $rows ) ) {
			$rows[] = [
				'locale'        => '(none)',
				'db_rows'       => 0,
				'cache_backend' => $backend,
				'last_prune'    => $lastPrune,
			];
		}

		$this->log( sprintf( 'Object cache backend: %s', $backend ) );
		$this->log( sprintf( 'Total DB rows across all locales: %d', $totalRows ) );
		$this->log( sprintf( 'Last prune run: %s', $lastPrune ) );

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[ 'locale', 'db_rows', 'cache_backend', 'last_prune' ]
		);
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Resolve the list of locales to operate on.
	 *
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return string[]
	 */
	private function resolveLocales( array $assocArgs ): array {
		if ( ! empty( $assocArgs['all-locales'] ) ) {
			return array_keys( $this->repo->countByLocale() );
		}

		if ( isset( $assocArgs['locale'] ) ) {
			// WP-CLI passes multiple --locale flags as an array when using
			// the 'repeating' argument type. Handle both string and array.
			$raw = $assocArgs['locale'];
			return is_array( $raw )
				? array_map( 'strval', $raw )
				: [ (string) $raw ];
		}

		return [];
	}
}
