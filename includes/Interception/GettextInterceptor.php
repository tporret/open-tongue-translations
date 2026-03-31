<?php
/**
 * Interception layer 1: WordPress gettext filter.
 *
 * Hooks into WordPress's translation pipeline via the `gettext` filter to
 * translate individual strings as they pass through __(), _e(), and friends.
 * A re-entrancy guard prevents the infinite recursion that would occur if
 * the HTTP call inside translate() itself triggered additional gettext lookups.
 *
 * @package OpenToungeTranslations\Interception
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Interception;

use OpenToungeTranslations\Cache\CacheManager;
use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;
use OpenToungeTranslations\Database\TranslationRecord;

/**
 * Class GettextInterceptor
 *
 * Sits on the `gettext` filter and forwards translated strings to the
 * configured translation backend. Because WordPress calls this filter for
 * EVERY translated string — including strings generated inside the HTTP
 * client library — a $processing guard is mandatory to avoid a call stack
 * that recurses until PHP hits its stack depth limit.
 *
 * Bypass domains let site operators exclude entire text domains (e.g. a
 * WooCommerce shop that ships its own pre-translated strings) from API calls.
 */
final class GettextInterceptor {

	/**
	 * Re-entrancy guard.
	 *
	 * Set to true for the duration of a translate() call to prevent
	 * gettext lookups triggered inside the HTTP client from recursing
	 * back into this interceptor.
	 */
	private bool $processing = false;

	/**
	 * @param TranslationClientInterface $client      Resolved translation driver.
	 * @param string                     $targetLang  BCP-47 tag for the desired output language.
	 * @param CacheManager               $cache       Two-level cache (L1 object cache + L2 DB).
	 */
	public function __construct(
		private readonly TranslationClientInterface $client,
		private readonly string $targetLang,
		private readonly CacheManager $cache,
	) {}

	/**
	 * Register the gettext filter with WordPress.
	 *
	 * Priority 10 — runs after core string resolution, before most theme/plugin filters.
	 *
	 * @return void
	 */
	public function register(): void {
		// Do not intercept gettext during WP-CLI runs. CLI commands (wp ott translate)
		// handle translation explicitly; hooking gettext here would fire for every
		// __() call in WP-CLI's own output, generating hundreds of failed API requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Do not intercept gettext on admin pages. WordPress calls __() for every
		// admin UI string (menu items, notices, column headers, …) — that can be
		// hundreds of uncached API calls per page load, causing timeouts. Front-end
		// translation is handled by OutputBufferInterceptor for page HTML and this
		// filter for front-end gettext strings only.
		if ( is_admin() ) {
			return;
		}

		add_filter( 'gettext', [ $this, 'intercept' ], 10, 3 );
	}

	/**
	 * Filter callback: translate a single gettext string.
	 *
	 * Skips translation when:
	 *   1. A translation call is already in-flight ($processing guard).
	 *   2. The text domain is in the configured bypass list.
	 *   3. The current locale already matches the target language
	 *      (avoids a round-trip that would return the string unchanged).
	 *   4. The string is empty or whitespace-only.
	 *
	 * @param string $translation The string as resolved by WordPress's .po/.mo loading.
	 * @param string $text        The original source text passed to __() etc.
	 * @param string $domain      The text domain the string belongs to.
	 *
	 * @return string The (possibly translated) string. Never null, never throws.
	 */
	public function intercept( string $translation, string $text, string $domain ): string {
		// Guard 1 — re-entrancy: the API call itself may trigger gettext lookups
		// (e.g. WP_Http logging, cURL error messages). Without this guard the
		// intercept method would call translate() → translate() infinitely.
		if ( $this->processing ) {
			return $translation;
		}

		// Guard 2 — bypass domains: skip domains the operator has opted out of.
		/** @var string[] $bypassDomains */
		$bypassDomains = (array) get_option( 'ltp_bypass_domains', [] );

		if ( in_array( $domain, $bypassDomains, strict: true ) ) {
			return $translation;
		}

		// Guard 3 — locale parity: if WordPress is already running in the target
		// language there is nothing to translate.
		if ( get_locale() === $this->targetLang ) {
			return $translation;
		}

		// Guard 4 — empty strings produce no useful translation.
		if ( trim( $translation ) === '' ) {
			return $translation;
		}

		// Guard 5 — cache hit: check L1 then L2 before touching the API.
		$hash     = TranslationRecord::computeHash( $translation, 'gettext' );
		$cacheHit = $this->cache->resolve( [ $hash ], $this->targetLang );

		if ( isset( $cacheHit[ $hash ] ) ) {
			return $cacheHit[ $hash ];
		}

		// Cache miss — call the API. Use the processing guard so that any
		// gettext calls triggered inside the HTTP stack don't recurse here.
		$this->processing = true;

		try {
			$translated = $this->client->translate(
				text: $translation,
				sourceLang: 'auto',
				targetLang: $this->targetLang,
			);

			// Persist to both cache layers so the next request is a cache hit.
			$record = new TranslationRecord(
				hash:           $hash,
				sourceLang:     'auto',
				targetLang:     $this->targetLang,
				sourceText:     $translation,
				translatedText: $translated,
				context:        'gettext',
				isManual:       false,
			);
			$this->cache->store( [ $record ], $this->targetLang );

		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] GettextInterceptor exception: %s',
					$e->getMessage()
				)
			);
			$translated = $translation;
		} finally {
			// Always release the lock — even on exception — so subsequent
			// strings in the same request are not silently skipped.
			$this->processing = false;
		}

		return $translated;
	}
}
