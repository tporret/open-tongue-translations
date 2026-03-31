<?php
/**
 * Two-level read-through cache orchestrator.
 *
 * Resolves translation lookups through:
 *   L1 — ObjectCacheDriver (Redis / Memcached / in-process array)
 *   L2 — DatabaseCacheDriver (MySQL via TranslationRepository)
 *
 * On a resolve() call, any hash that misses L1 is fetched from L2.
 * L2 hits are written back to L1 (backfill) so subsequent requests skip the DB.
 * Hashes that miss both levels must be translated by the API caller.
 *
 * Database writes for newly translated strings always carry full context
 * (source_text, source_lang, context) and therefore bypass the cache interface's
 * set/setMany — which only have hash + value — and go directly through the
 * TranslationRepository. L1 is populated simultaneously via ObjectCacheDriver.
 *
 * @package OpenToungeTranslations\Cache
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cache;

use OpenToungeTranslations\Cache\Contracts\TranslationCacheInterface;
use OpenToungeTranslations\Database\TranslationRecord;
use OpenToungeTranslations\Database\TranslationRepository;

/**
 * Class CacheManager
 *
 * Wires L1 and L2 together into a read-through cache. Both drivers are
 * injected at construction so the wiring can be tested independently of
 * WordPress infrastructure.
 */
final class CacheManager {

	/**
	 * @param TranslationCacheInterface $l1   Object cache driver (fast, per-request or persistent).
	 * @param TranslationCacheInterface $l2   Database cache driver (slow, always persistent).
	 * @param TranslationRepository     $repo Direct DB access for full-record writes and warm-up.
	 */
	public function __construct(
		private readonly TranslationCacheInterface $l1,
		private readonly TranslationCacheInterface $l2,
		private readonly TranslationRepository $repo,
	) {}

	/**
	 * Resolve a batch of hashes against both cache layers.
	 *
	 * Algorithm:
	 *  1. Batch-get from L1. Collect hashes that missed.
	 *  2. Batch-get misses from L2.
	 *  3. Write L2 hits back into L1 (backfill / read-repair).
	 *  4. Return merged hash => translated_text map.
	 *
	 * Hashes absent from the returned map are cache misses at both levels —
	 * the caller must invoke the translation API and then call store().
	 *
	 * @param string[] $hashes Array of MD5 source-text hashes to resolve.
	 * @param string   $locale BCP-47 target language code.
	 *
	 * @return array<string, string> Map of hash => translated_text for all hits.
	 */
	public function resolve( array $hashes, string $locale ): array {
		if ( empty( $hashes ) ) {
			return [];
		}

		// --- Step 1: L1 lookup -----------------------------------------------
		$l1Hits = $this->l1->getMany( $hashes, $locale );

		$misses = array_values(
			array_filter( $hashes, fn( string $h ) => ! isset( $l1Hits[ $h ] ) )
		);

		if ( empty( $misses ) ) {
			return $l1Hits;
		}

		// --- Step 2: L2 lookup for the misses ---------------------------------
		$l2Hits = $this->l2->getMany( $misses, $locale );

		// --- Step 3: Backfill L1 with L2 hits ---------------------------------
		if ( ! empty( $l2Hits ) ) {
			$this->l1->setMany( $l2Hits, $locale );
		}

		// --- Step 4: Merge and return -----------------------------------------
		return array_merge( $l1Hits, $l2Hits );
	}

	/**
	 * Persist a batch of new translations to both cache layers simultaneously.
	 *
	 * L1 (object cache) is written via the cache interface's setMany().
	 * L2 (database) is written via TranslationRepository::upsertBatch() which
	 * accepts full TranslationRecord objects — the only way to construct a valid
	 * database row with all required fields (source_text, source_lang, context).
	 * L2 errors are caught and logged; they never propagate to the caller because
	 * an L2 write failure is not page-breaking (L1 still serves the translation).
	 *
	 * @param TranslationRecord[] $records Fully-hydrated records to persist.
	 * @param string              $locale  BCP-47 target language code.
	 *
	 * @return void
	 */
	public function store( array $records, string $locale ): void {
		if ( empty( $records ) ) {
			return;
		}

		// Build hash => translated_text map for L1 (which only needs these two fields).
		$hashMap = [];
		foreach ( $records as $record ) {
			$hashMap[ $record->hash ] = $record->translatedText;
		}

		// Write to L1 — fast, should not throw.
		$this->l1->setMany( $hashMap, $locale );

		// Write to L2 (DB) — fire-and-forget; page render must never be blocked.
		try {
			$this->repo->upsertBatch( $records );
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] CacheManager::store() — L2 write failed: %s',
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Pre-populate L1 with all cached translations for a locale in a single batch.
	 *
	 * Intended for WP-CLI warm-up commands:
	 *   wp ltp cache warm --locale=fr
	 *
	 * Loads up to 5000 most-recently-used rows from the DB (TranslationRepository
	 * default limit) and writes them to the object cache. On sites with > 5000
	 * cached strings, run the command multiple times or increase the repository limit.
	 *
	 * TODO: Expose `wp ltp cache warm` as a WP-CLI sub-command in a dedicated
	 *       Cli/WarmCacheCommand.php class that calls this method.
	 *
	 * @param string $locale BCP-47 target language code to warm.
	 *
	 * @return void
	 */
	public function warmLocale( string $locale ): void {
		// OOM guard: loading 5 000 MEDIUMTEXT rows can exceed 128 MB on sites with
		// large page bodies. Sample memory before the load; abort and log if the
		// headroom is insufficient. The threshold is 80 % of memory_limit so the
		// remainder is available to the rest of the request.
		$memoryLimit = $this->resolveMemoryLimit();
		$memoryBefore = memory_get_usage( true );

		if ( $memoryLimit > 0 ) {
			$available = (int) ( $memoryLimit * 0.20 ); // 20 % headroom
			if ( ( $memoryLimit - $memoryBefore ) < $available ) {
				error_log(
					sprintf(
						'[OpenTongue] CacheManager::warmLocale() — insufficient memory headroom for locale "%s". ' .
						'Available: %d MB, Limit: %d MB. Skipping warm-up.',
						$locale,
						(int) ( ( $memoryLimit - $memoryBefore ) / 1024 / 1024 ),
						(int) ( $memoryLimit / 1024 / 1024 )
					)
				);
				return;
			}
		}

		$records = $this->repo->findByLocale( $locale );

		if ( empty( $records ) ) {
			error_log(
				sprintf( '[OpenTongue] CacheManager::warmLocale() — no records found for locale "%s".', $locale )
			);
			return;
		}

		$memoryAfter = memory_get_usage( true );
		$allocated   = $memoryAfter - $memoryBefore;

		// Warn when the load consumed > 32 MB so operators can tune $limit or
		// implement a paginated warm strategy before hitting the ceiling.
		if ( $allocated > 32 * 1024 * 1024 ) {
			error_log(
				sprintf(
					'[OpenTongue] CacheManager::warmLocale() — loaded locale "%s" but consumed %d MB. ' .
					'Consider a paginated warm strategy for large translation tables.',
					$locale,
					(int) ( $allocated / 1024 / 1024 )
				)
			);
		}

		$hashMap = [];
		foreach ( $records as $hash => $record ) {
			$hashMap[ $hash ] = $record->translatedText;
		}

		$this->l1->setMany( $hashMap, $locale );

		error_log(
			sprintf(
				'[OpenTongue] CacheManager::warmLocale() — warmed %d entries for locale "%s".',
				count( $hashMap ),
				$locale
			)
		);
	}

	/**
	 * Resolve the PHP memory_limit ini value to bytes.
	 *
	 * Returns 0 when the limit is disabled (-1) or cannot be parsed, which
	 * causes the OOM guard to be skipped rather than producing a false positive.
	 *
	 * @return int Memory limit in bytes, or 0 when unavailable/unlimited.
	 */
	private function resolveMemoryLimit(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( $raw === '' || $raw === '-1' ) {
			return 0;
		}

		$value = (int) $raw;
		$unit  = strtolower( substr( $raw, -1 ) );

		return match ( $unit ) {
			'g'     => $value * 1024 * 1024 * 1024,
			'm'     => $value * 1024 * 1024,
			'k'     => $value * 1024,
			default => $value,
		};
	}

	/**
	 * Invalidate all cached translations for a given locale in both layers.
	 *
	 * L1 uses the version-counter pattern (ObjectCacheDriver::flushLocale) — O(1).
	 * L2 physically removes non-manual rows from the database.
	 *
	 * After this call, do_action('ltp_locale_flushed', $locale) is fired so that
	 * static-cache compat layers (WP Rocket, Cloudflare) can purge their caches.
	 *
	 * @param string $locale BCP-47 target language code to flush.
	 *
	 * @return void
	 */
	public function flushLocale( string $locale ): void {
		$this->l1->flushLocale( $locale );
		$this->l2->flushLocale( $locale );

		/**
		 * Fires after a locale's cached translations have been purged from both
		 * the object cache and the database.
		 *
		 * @param string $locale BCP-47 locale that was flushed.
		 */
		do_action( 'ltp_locale_flushed', $locale );
	}
}
