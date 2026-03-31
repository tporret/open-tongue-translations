<?php
/**
 * Contract for all translation cache drivers.
 *
 * Both the object-cache layer (Redis/Memcached via wp_cache_*) and the
 * database fallback layer implement this interface so that CacheManager can
 * treat them identically during read-through resolution.
 *
 * @package OpenToungeTranslations\Cache\Contracts
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cache\Contracts;

/**
 * Interface TranslationCacheInterface
 *
 * Abstraction over any key-value store that can hold translated strings keyed
 * by (hash, locale) pairs. All implementations must be safe to call on every
 * WordPress request — no heavy I/O in constructors, no throwing to callers.
 */
interface TranslationCacheInterface {

	/**
	 * Retrieve a single translated string.
	 *
	 * @param string $hash   MD5 hash of the source text (from TranslationRecord::computeHash()).
	 * @param string $locale BCP-47 target language code (e.g. 'fr', 'de').
	 *
	 * @return string|null The cached translated string, or null on a cache miss.
	 */
	public function get( string $hash, string $locale ): ?string;

	/**
	 * Retrieve multiple translated strings in a single round-trip.
	 *
	 * @param string[] $hashes Array of MD5 source-text hashes.
	 * @param string   $locale BCP-47 target language code.
	 *
	 * @return array<string, string> Map of hash => translated_text for cache hits only.
	 *                               Missing hashes are absent from the returned array.
	 */
	public function getMany( array $hashes, string $locale ): array;

	/**
	 * Store a single translated string.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 * @param string $value  The translated string to cache.
	 * @param int    $ttl    Time-to-live in seconds. 0 = no expiry.
	 *
	 * @return void
	 */
	public function set( string $hash, string $locale, string $value, int $ttl = 86400 ): void;

	/**
	 * Store multiple translated strings in a single operation.
	 *
	 * @param array<string, string> $translations Map of hash => translated_text.
	 * @param string                $locale       BCP-47 target language code.
	 * @param int                   $ttl          Time-to-live in seconds. 0 = no expiry.
	 *
	 * @return void
	 */
	public function setMany( array $translations, string $locale, int $ttl = 86400 ): void;

	/**
	 * Remove a single cached entry.
	 *
	 * @param string $hash   MD5 hash of the source text.
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return void
	 */
	public function delete( string $hash, string $locale ): void;

	/**
	 * Invalidate all cached translations for a given locale.
	 *
	 * Implementations must ensure previously cached entries for $locale are no
	 * longer returned — exact mechanism (key prefix delete, version increment,
	 * table truncate) is left to the driver.
	 *
	 * @param string $locale BCP-47 target language code.
	 *
	 * @return void
	 */
	public function flushLocale( string $locale ): void;
}
