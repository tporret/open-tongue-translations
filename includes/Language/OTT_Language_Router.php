<?php
/**
 * Language routing and selection logic.
 *
 * Determines the effective target language for each request by consulting,
 * in priority order:
 *   1. A manually set visitor cookie  (explicit user override).
 *   2. The HTTP Accept-Language header (auto-detection, if enabled).
 *   3. The global ltp_target_lang option (site-wide default).
 *
 * Also enforces the optional "Validation Mode" gate, which restricts live
 * translation to administrators so that managers can proof-read output before
 * making it visible to all visitors.
 *
 * @package OpenToungeTranslations\Language
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Language;

/**
 * Class OTT_Language_Router
 *
 * Request-scoped singleton. Plugin::boot() creates it once; all other code
 * calls OTT_Language_Router::getInstance() to retrieve the same instance.
 *
 * ## Hook timeline
 *
 * plugins_loaded  → Plugin::boot() creates and calls register()
 * init (pri 1)    → handleAutoDetect(): sets cookie if browser detection fires
 * init (pri 5)    → Plugin::boot() closure: registers interceptors using the
 *                   now-resolved language and translation-allowed flag.
 * rest_api_init   → registerRestEndpoint(): exposes POST /ott/v1/set-lang
 */
final class OTT_Language_Router {

	/**
	 * Cookie name written to the visitor's browser on language selection.
	 * HttpOnly + SameSite=Lax. Name is intentionally short to minimise
	 * cookie header overhead on every request.
	 */
	public const COOKIE_NAME = 'ott_user_lang';

	/**
	 * How long the preference cookie persists (1 year).
	 */
	private const COOKIE_TTL = YEAR_IN_SECONDS;

	/**
	 * Singleton instance, scoped to the current PHP request.
	 */
	private static ?self $instance = null;

	/**
	 * Memoised resolved language for the current request.
	 * Null until getEffectiveLang() is first called.
	 */
	private ?string $resolvedLang = null;

	/**
	 * Memoised translation-allowed flag.
	 * Null until isTranslationAllowed() is first called.
	 */
	private ?bool $allowed = null;

	/**
	 * @param OTT_Language_Service $service Catalogue of supported languages.
	 */
	private function __construct(
		private readonly OTT_Language_Service $service,
	) {}

	// ------------------------------------------------------------------
	// Singleton access
	// ------------------------------------------------------------------

	/**
	 * Return (and optionally create) the singleton instance.
	 *
	 * The first call must pass the language service; subsequent calls may
	 * omit it (the cached instance already holds a reference).
	 *
	 * @param OTT_Language_Service $service Injected on the first call by Plugin::boot().
	 *
	 * @return self
	 */
	public static function getInstance( OTT_Language_Service $service ): self {
		if ( self::$instance === null ) {
			self::$instance = new self( $service );
		}

		return self::$instance;
	}

	// ------------------------------------------------------------------
	// Bootstrap
	// ------------------------------------------------------------------

	/**
	 * Register WordPress hooks.
	 *
	 * Called once from Plugin::boot() during plugins_loaded. The router
	 * stays dormant on WP-CLI runs where there is no HTTP context.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Resolve auto-detection early, before page output starts.
		add_action( 'init', [ $this, 'handleAutoDetect' ], 1 );

		// REST endpoint for the front-end language switcher.
		add_action( 'rest_api_init', [ $this, 'registerRestEndpoint' ] );
	}

	// ------------------------------------------------------------------
	// Language resolution
	// ------------------------------------------------------------------

	/**
	 * Return the BCP-47 language code that should be used to translate the
	 * current request, or the site default if no override is in effect.
	 *
	 * Priority (highest to lowest):
	 *   1. ott_user_lang cookie — set by visitor via the language switcher.
	 *   2. Accept-Language header — when ltp_auto_detect_locale is enabled.
	 *   3. ltp_target_lang option — the site-wide default.
	 *
	 * The result is memoised for the lifetime of the PHP process.
	 *
	 * @return string BCP-47 language code (e.g. "fr", "de", "zh").
	 */
	public function getEffectiveLang(): string {
		if ( $this->resolvedLang !== null ) {
			return $this->resolvedLang;
		}

		// 1. Explicit visitor override via cookie.
		$cookieLang = $this->readCookieLang();

		if ( $cookieLang !== null ) {
			$this->resolvedLang = $cookieLang;
			return $this->resolvedLang;
		}

		// 2. Browser detection (Accept-Language header).
		if ( (bool) get_option( 'ltp_auto_detect_locale', false ) ) {
			$detected = $this->detectFromAcceptLanguage();

			if ( $detected !== null ) {
				$this->resolvedLang = $detected;
				return $this->resolvedLang;
			}
		}

		// 3. Site-wide default.
		$this->resolvedLang = (string) get_option( 'ltp_target_lang', 'en' );

		return $this->resolvedLang;
	}

	/**
	 * Decide whether translation is permitted for this request.
	 *
	 * When ltp_validation_mode is enabled the translated output is only
	 * served to users with the manage_options capability. Guests and
	 * non-admin users receive the original, untranslated site. This lets
	 * administrators proof-read machine translations before making them
	 * public.
	 *
	 * The result is memoised. Must be called after init so that
	 * current_user_can() has access to the authenticated user object.
	 *
	 * @return bool True when translation should proceed for this request.
	 */
	public function isTranslationAllowed(): bool {
		if ( $this->allowed !== null ) {
			return $this->allowed;
		}

		if ( (bool) get_option( 'ltp_validation_mode', false ) ) {
			$this->allowed = current_user_can( 'manage_options' );
		} else {
			$this->allowed = true;
		}

		return $this->allowed;
	}

	// ------------------------------------------------------------------
	// Auto-detection (init hook)
	// ------------------------------------------------------------------

	/**
	 * If browser-locale detection is enabled and no cookie is already set,
	 * parse the Accept-Language header, match against the supported language
	 * catalogue, and write the preference cookie.
	 *
	 * Fires on init at priority 1 — before any output — so setcookie()
	 * can safely write the Set-Cookie response header.
	 *
	 * @return void
	 */
	public function handleAutoDetect(): void {
		if ( ! (bool) get_option( 'ltp_auto_detect_locale', false ) ) {
			return;
		}

		// If the visitor already has a cookie (manual or previously detected),
		// do not overwrite their preference every request.
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return;
		}

		$detected = $this->detectFromAcceptLanguage();

		if ( $detected !== null ) {
			$this->writeCookie( $detected );
		}
	}

	// ------------------------------------------------------------------
	// REST endpoint
	// ------------------------------------------------------------------

	/**
	 * Register the language-switch REST route.
	 *
	 * POST /wp-json/ott/v1/set-lang  { "lang": "fr" }
	 *
	 * No authentication required — any visitor may change their own
	 * preference. The lang value is validated against the supported
	 * language catalogue before the cookie is written.
	 *
	 * @return void
	 */
	public function registerRestEndpoint(): void {
		register_rest_route(
			'ott/v1',
			'/set-lang',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handleSetLang' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'lang' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( string $value ): bool {
							// Reject empty strings before hitting isCodeSupported.
							return $value !== '';
						},
					],
				],
			]
		);
	}

	/**
	 * Handle POST /ott/v1/set-lang.
	 *
	 * Validates the requested language against the catalogue, writes the
	 * preference cookie, and returns the new active language so the client
	 * can confirm the switch before reloading.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handleSetLang( \WP_REST_Request $request ): \WP_REST_Response {
		$lang = sanitize_text_field( (string) $request->get_param( 'lang' ) );

		if ( ! $this->service->isCodeSupported( $lang ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_lang',
					'message' => __( 'The requested language code is not supported.', 'open-tongue-translations' ),
				],
				400
			);
		}

		$this->writeCookie( $lang );
		$this->resolvedLang = $lang;

		return new \WP_REST_Response(
			[
				'success' => true,
				'lang'    => $lang,
			],
			200
		);
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Read and validate the ott_user_lang cookie.
	 *
	 * The cookie value is run through sanitize_text_field and then checked
	 * against the language catalogue to prevent an attacker from injecting
	 * an arbitrary string into the translation pipeline.
	 *
	 * @return string|null Validated BCP-47 code, or null if absent/invalid.
	 */
	private function readCookieLang(): ?string {
		$raw  = $_COOKIE[ self::COOKIE_NAME ] ?? '';
		$lang = sanitize_text_field( (string) $raw );

		if ( $lang === '' ) {
			return null;
		}

		// Validate against the supported catalogue to prevent cookie injection.
		if ( ! $this->service->isCodeSupported( $lang ) ) {
			return null;
		}

		return $lang;
	}

	/**
	 * Parse the HTTP Accept-Language header and find the best matching
	 * language from the catalogue.
	 *
	 * Implements RFC 4647 §3.4 "Lookup" matching at the primary subtag level:
	 *   - "fr-CA" is tried as "fr-ca" first, then stripped to "fr".
	 *   - Matching is case-insensitive.
	 *   - The highest-weighted locale that maps to a supported code wins.
	 *
	 * @return string|null Best matching BCP-47 code, or null if no match.
	 */
	private function detectFromAcceptLanguage(): ?string {
		$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

		if ( $header === '' ) {
			return null;
		}

		$supported = $this->service->getLanguageCodes();

		if ( empty( $supported ) ) {
			return null;
		}

		// Parse "en-US,en;q=0.9,fr;q=0.8" into [lang => weight] pairs.
		$locales = [];

		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );

			if ( preg_match( '/^([a-zA-Z]{1,8}(?:-[a-zA-Z0-9]{1,8})*)(?:;q=([01](?:\.\d{1,3})?))?$/i', $part, $m ) ) {
				$locales[] = [
					'lang'   => strtolower( $m[1] ),
					'weight' => isset( $m[2] ) ? (float) $m[2] : 1.0,
				];
			}
		}

		// Sort by weight descending (highest preference first).
		usort( $locales, static fn ( array $a, array $b ): int => $b['weight'] <=> $a['weight'] );

		foreach ( $locales as $locale ) {
			$code = $locale['lang'];

			// Exact match (e.g. "fr" or "zh-tw" if catalogue has "zh-tw").
			if ( in_array( $code, $supported, strict: true ) ) {
				return $code;
			}

			// Primary subtag fallback: "fr-ca" → "fr".
			$primary = strtolower( explode( '-', $code )[0] );

			if ( $primary !== $code && in_array( $primary, $supported, strict: true ) ) {
				return $primary;
			}
		}

		return null;
	}

	/**
	 * Write the language preference cookie to the response.
	 *
	 * Uses the array-syntax setcookie() form (PHP 7.3+) for explicit SameSite
	 * control. Also injects the value into $_COOKIE so it is immediately
	 * readable within the current request without a round-trip.
	 *
	 * @param string $lang BCP-47 language code to persist.
	 *
	 * @return void
	 */
	private function writeCookie( string $lang ): void {
		setcookie(
			self::COOKIE_NAME,
			$lang,
			[
				'expires'  => time() + self::COOKIE_TTL,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		// Make the new value readable in the current request.
		$_COOKIE[ self::COOKIE_NAME ] = $lang;
	}
}
