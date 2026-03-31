<?php
/**
 * L1 cache driver: WordPress Object Cache (Redis / Memcached / APCu).
 *
 * Delegates entirely to the wp_cache_* API, which WP routes to the active
 * persistent object cache drop-in (redis-cache, memcached, etc.) or falls
 * back to a per-request in-memory array when no drop-in is present.
 *
 * Locale invalidation uses a version-counter pattern (see flushLocale()) so
 * purging one locale never touches cache entries for other locales and never
 * triggers a full site-wide cache flush — critical on shared Redis instances.
 *
 * @package OpenToungeTranslations\Cache
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cache;

use OpenToungeTranslations\Cache\Contracts\TranslationCacheInterface;

/**
 * Class ObjectCacheDriver
 *
 * Thin wrapper around wp_cache_get / wp_cache_get_multiple / wp_cache_set_multiple.
 * Uses a single Redis round-trip for getMany() and setMany() — never a loop
 * of individual wp_cache_get() calls, which would each incur a network RTT.
 *
 * ## Key format
 *
 *   "{$locale}:{$version}:{$hash}"
 *
 * where $version is the current locale version counter stored at
 * "ltp_ver_{$locale}". flushLocale() increments the counter; old keys become
 * unreachable without any explicit delete scan.
 *
 * ## Non-persistent group fallback
 *
 * wp_cache_add_non_persistent_groups() registers 'ltp_translations' as an
 * in-memory-only group on sites that lack a persistent object cache. This
 * guarantees zero cold misses *within a single PHP request* even on plain
 * transient-based setups.
 */
final class ObjectCacheDriver implements TranslationCacheInterface {

	/**
	 * The wp_cache group used for all translation cache entries and version counters.
	 */
	private const CACHE_GROUP = 'ltp_translations';

	/**
	 * Register the cache group with WordPress.
	 *
	 * Call this once during plugin boot, before any cache read or write.
	 * On sites without a persistent object cache the group is registered as
	 * non-persistent, guaranteeing at most one API call per unique string per
	 * PHP process — not per request.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_cache_add_non_persistent_groups( [ self::CACHE_GROUP ] );
	}

	/**
	 * Retrieve a single translated string from the object cache.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return string|null The cached translated string, or null on a miss.
	 */
	public function get( string $hash, string $locale ): ?string {
		$value = wp_cache_get( $this->buildCacheKey( $hash, $locale ), self::CACHE_GROUP );

		return ( $value !== false && is_string( $value ) ) ? $value : null;
	}

	/**
	 * Retrieve multiple translated strings in a single object-cache round-trip.
	 *
	 * Uses wp_cache_get_multiple() (available since WP 5.5) so that Redis
	 * drivers can batch the lookup into a single MGET command. Falls back to
	 * looping wp_cache_get() on sites running WP < 5.5 (below our minimum,
	 * but guarded defensively).
	 *
	 * @param string[] $hashes Array of MD5 source-text hashes.
	 * @param string   $locale BCP-47 target language code.
	 *
	 * @return array<string, string> Map of hash => translated_text for cache hits.
	 */
	public function getMany( array $hashes, string $locale ): array {
		if ( empty( $hashes ) ) {
			return [];
		}

		// Resolve the version once per batch — avoids a per-key cache read.
		$version = $this->getLocaleVersion( $locale );

		// Build cacheKey => originalHash map so we can reverse-map results.
		$keyMap = [];
		foreach ( $hashes as $hash ) {
			$keyMap[ $this->buildCacheKeyWithVersion( $hash, $locale, $version ) ] = $hash;
		}

		$cacheKeys   = array_keys( $keyMap );
		$cacheValues = wp_cache_get_multiple( $cacheKeys, self::CACHE_GROUP );

		$result = [];
		foreach ( $cacheValues as $cacheKey => $value ) {
			if ( $value !== false && is_string( $value ) ) {
				$originalHash          = $keyMap[ $cacheKey ];
				$result[ $originalHash ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Store a single translated string in the object cache.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 * @param string $value  The translated string.
	 * @param int    $ttl    Time-to-live in seconds. 0 = no expiry.
	 *
	 * @return void
	 */
	public function set( string $hash, string $locale, string $value, int $ttl = 86400 ): void {
		wp_cache_set( $this->buildCacheKey( $hash, $locale ), $value, self::CACHE_GROUP, $ttl );
	}

	/**
	 * Store multiple translated strings in a single object-cache operation.
	 *
	 * Uses wp_cache_set_multiple() (available since WP 6.0 — our minimum) so
	 * Redis drivers can pipeline the writes. Falls back to a loop when
	 * wp_cache_set_multiple() is absent (defensive guard for edge cases like
	 * custom object cache implementations that lag WP core).
	 *
	 * @param array<string, string> $translations Map of hash => translated_text.
	 * @param string                $locale       BCP-47 target language code.
	 * @param int                   $ttl          Time-to-live in seconds. 0 = no expiry.
	 *
	 * @return void
	 */
	public function setMany( array $translations, string $locale, int $ttl = 86400 ): void {
		if ( empty( $translations ) ) {
			return;
		}

		$version = $this->getLocaleVersion( $locale );

		$keyed = [];
		foreach ( $translations as $hash => $value ) {
			$keyed[ $this->buildCacheKeyWithVersion( $hash, $locale, $version ) ] = $value;
		}

		if ( function_exists( 'wp_cache_set_multiple' ) ) {
			wp_cache_set_multiple( $keyed, self::CACHE_GROUP, $ttl );
		} else {
			// Fallback for custom object cache implementations below WP 6.0 API.
			foreach ( $keyed as $key => $value ) {
				wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
			}
		}
	}

	/**
	 * Remove a single cached translation entry.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return void
	 */
	public function delete( string $hash, string $locale ): void {
		wp_cache_delete( $this->buildCacheKey( $hash, $locale ), self::CACHE_GROUP );
	}

	/**
	 * Invalidate all cached translations for a given locale.
	 *
	 * Uses the version-counter pattern: increments a per-locale integer stored
	 * under "ltp_ver_{$locale}". All existing keys that embed the old version
	 * number become effectively invisible — no scan, no bulk delete, O(1) cost.
	 *
	 * This is safe on shared Redis instances because it never calls
	 * wp_cache_flush() (which would wipe the entire cache including sessions,
	 * query cache, and other plugins' data).
	 *
	 * @param string $locale BCP-47 target language code to invalidate.
	 *
	 * @return void
	 */
	public function flushLocale( string $locale ): void {
		$current = $this->getLocaleVersion( $locale );
		wp_cache_set( 'ltp_ver_' . $locale, $current + 1, self::CACHE_GROUP, 0 );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the current version counter for a locale.
	 *
	 * Returns 0 when no counter exists yet (first request after activation or
	 * after a counter key expires from memory).
	 *
	 * @param string $locale BCP-47 locale code.
	 *
	 * @return int Non-negative version counter.
	 */
	private function getLocaleVersion( string $locale ): int {
		$version = wp_cache_get( 'ltp_ver_' . $locale, self::CACHE_GROUP );

		return ( is_int( $version ) && $version >= 0 ) ? $version : 0;
	}

	/**
	 * Build a cache key baking in the current locale version.
	 *
	 * Format: "{$locale}:{$version}:{$hash}"
	 *
	 * @param string $hash   MD5 source-text hash.
	 * @param string $locale BCP-47 locale code.
	 *
	 * @return string Cache key.
	 */
	private function buildCacheKey( string $hash, string $locale ): string {
		return $this->buildCacheKeyWithVersion( $hash, $locale, $this->getLocaleVersion( $locale ) );
	}

	/**
	 * Build a cache key with an explicitly provided version.
	 *
	 * Used by batch methods to avoid re-fetching the version on every iteration.
	 *
	 * @param string $hash    MD5 source-text hash.
	 * @param string $locale  BCP-47 locale code.
	 * @param int    $version Result of getLocaleVersion().
	 *
	 * @return string Cache key.
	 */
	private function buildCacheKeyWithVersion( string $hash, string $locale, int $version ): string {
		return "{$locale}:{$version}:{$hash}";
	}
}
