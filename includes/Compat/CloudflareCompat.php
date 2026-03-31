<?php
/**
 * Cloudflare CDN cache compatibility layer.
 *
 * Detects the active Cloudflare integration mode and purges the CDN cache
 * for affected URLs when translations are updated. Supports two modes:
 *
 *   Mode A — Cloudflare WordPress Plugin (CF\Integration\DefaultIntegration):
 *             delegates via do_action() to the plugin's own purge actions.
 *
 *   Mode B — Direct API: credentials stored in WordPress options. Issues a
 *             wp_remote_post() call to the Cloudflare REST API. Fire-and-forget.
 *
 * Both modes are detected at hook execution time (not registration time) so
 * plugin load order does not affect correctness.
 *
 * @package OpenToungeTranslations\Compat
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Compat;

/**
 * Class CloudflareCompat
 *
 * Integrates with the Cloudflare CDN to purge cached pages when translations
 * change. Wraps all outbound HTTP calls in try/catch — a Cloudflare API failure
 * must never disrupt the WordPress request that triggered the purge.
 */
final class CloudflareCompat {

	/**
	 * Detect whether any Cloudflare integration is available.
	 *
	 * Returns true when either the Cloudflare plugin is active (Mode A) or
	 * direct-API credentials are configured in WordPress options (Mode B).
	 *
	 * @return bool True when at least one Cloudflare purge path is available.
	 */
	public function isActive(): bool {
		return $this->isPluginModeActive() || $this->isDirectApiModeActive();
	}

	/**
	 * Register action hooks with WordPress.
	 *
	 * Bails when neither Cloudflare integration mode is available.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->isActive() ) {
			return;
		}

		// Priority 20 — fires after the translation commit.
		add_action( 'ltp_translation_updated', [ $this, 'purgePost' ], 20, 2 );
		add_action( 'ltp_content_updated', [ $this, 'purgeByUrl' ], 20, 2 );
		add_action( 'ltp_locale_flushed', [ $this, 'purgeAll' ], 20, 1 );
	}

	/**
	 * Purge the Cloudflare CDN cache for a specific URL.
	 *
	 * Called via the 'ltp_content_updated' action for non-post content (widgets,
	 * nav menus, template parts) where no post ID is available.
	 *
	 * @param string $url    Absolute URL whose cached version should be purged.
	 * @param string $locale BCP-47 locale that was updated (informational).
	 *
	 * @return void
	 */
	public function purgeByUrl( string $url, string $locale ): void {
		if ( $url === '' ) {
			return;
		}

		if ( $this->isPluginModeActive() ) {
			$this->purgeViaPlugin( [ $url ] );
		} elseif ( $this->isDirectApiModeActive() ) {
			$this->purgeViaApi( [ $url ] );
		}
	}

	/**
	 * Purge the Cloudflare CDN cache for a single post URL.
	 *
	 * Derives the purge URL from get_permalink(). Appends the locale as a
	 * query parameter so language-specific cached variants are targeted.
	 *
	 * TODO: Make the URL strategy configurable (query param vs. path prefix
	 *       e.g. /fr/page/) once the settings UI is built in a later task.
	 *
	 * @param int    $postId WordPress post ID whose translation changed.
	 * @param string $locale BCP-47 locale that was updated.
	 *
	 * @return void
	 */
	public function purgePost( int $postId, string $locale ): void {
		$permalink = get_permalink( $postId );

		if ( $permalink === false || $permalink === '' ) {
			error_log(
				sprintf(
					'[OpenTongue] CloudflareCompat::purgePost() — could not resolve permalink for post %d.',
					$postId
				)
			);
			return;
		}

		// TODO: URL strategy — currently appends ?lang={locale}. Replace with
		//       path-prefix strategy (e.g. /fr/slug) once URL config is available.
		$url = add_query_arg( 'lang', $locale, $permalink );

		if ( $this->isPluginModeActive() ) {
			$this->purgeViaPlugin( [ $url ] );
		} elseif ( $this->isDirectApiModeActive() ) {
			$this->purgeViaApi( [ $url ] );
		}
	}

	/**
	 * Purge the entire Cloudflare CDN cache (all URLs).
	 *
	 * Called when a full locale flush occurs. Any page on the site may
	 * contain translated strings for that locale, so a targeted purge is
	 * not sufficient.
	 *
	 * @param string $locale BCP-47 locale that was flushed (informational).
	 *
	 * @return void
	 */
	public function purgeAll( string $locale ): void {
		error_log(
			sprintf(
				'[OpenTongue] CloudflareCompat: purging entire CDN cache (locale "%s" flushed).',
				$locale
			)
		);

		if ( $this->isPluginModeActive() ) {
			$this->purgeEverythingViaPlugin();
		} elseif ( $this->isDirectApiModeActive() ) {
			$this->purgeEverythingViaApi();
		}
	}

	// -------------------------------------------------------------------------
	// Private: mode detection
	// -------------------------------------------------------------------------

	/**
	 * Determine whether the Cloudflare WordPress Plugin is active (Mode A).
	 *
	 * @return bool
	 */
	private function isPluginModeActive(): bool {
		return class_exists( 'CF\Integration\DefaultIntegration' );
	}

	/**
	 * Determine whether direct API credentials are configured (Mode B).
	 *
	 * @return bool True when both zone ID and API token options are non-empty.
	 */
	private function isDirectApiModeActive(): bool {
		$zoneId   = (string) get_option( 'ltp_cf_zone_id', '' );
		$apiToken = (string) get_option( 'ltp_cf_api_token', '' );

		return $zoneId !== '' && $apiToken !== '';
	}

	// -------------------------------------------------------------------------
	// Private: Mode A helpers (Cloudflare Plugin)
	// -------------------------------------------------------------------------

	/**
	 * Purge specific URLs via the Cloudflare plugin's action hook.
	 *
	 * @param string[] $urls URLs to purge.
	 *
	 * @return void
	 */
	private function purgeViaPlugin( array $urls ): void {
		try {
			/**
			 * Fires a purge request through the Cloudflare WordPress Plugin.
			 *
			 * @param string[] $urls URLs to purge from the CDN.
			 */
			do_action( 'cloudflare_purge_by_url', $urls );
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] CloudflareCompat::purgeViaPlugin() exception: %s',
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Issue a full-domain purge via the Cloudflare plugin.
	 *
	 * @return void
	 */
	private function purgeEverythingViaPlugin(): void {
		try {
			/**
			 * Triggers a full CDN purge via the Cloudflare WordPress Plugin.
			 */
			do_action( 'cloudflare_purge_everything' );
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] CloudflareCompat::purgeEverythingViaPlugin() exception: %s',
					$e->getMessage()
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Private: Mode B helpers (Direct Cloudflare API)
	// -------------------------------------------------------------------------

	/**
	 * Purge specific URLs via the Cloudflare REST API.
	 *
	 * POST /zones/{zone_id}/purge_cache  body: {"files": ["$url"]}
	 * Fire-and-forget: logs on non-200 but never throws.
	 *
	 * @param string[] $urls URLs to purge.
	 *
	 * @return void
	 */
	private function purgeViaApi( array $urls ): void {
		$zoneId   = (string) get_option( 'ltp_cf_zone_id', '' );
		$apiToken = (string) get_option( 'ltp_cf_api_token', '' );

		if ( $zoneId === '' || $apiToken === '' ) {
			return;
		}

		$endpoint = sprintf(
			'https://api.cloudflare.com/client/v4/zones/%s/purge_cache',
			rawurlencode( $zoneId )
		);

		try {
			$response = wp_remote_post(
				$endpoint,
				[
					'timeout' => 10,
					'headers' => [
						'Authorization' => 'Bearer ' . $apiToken,
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode( [ 'files' => $urls ] ),
				]
			);

			if ( is_wp_error( $response ) ) {
				error_log(
					sprintf(
						'[OpenTongue] CloudflareCompat::purgeViaApi() WP_Error: %s',
						$response->get_error_message()
					)
				);
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code !== 200 ) {
				error_log(
					sprintf(
						'[OpenTongue] CloudflareCompat::purgeViaApi() non-200 response: %d — body: %s',
						$code,
						wp_remote_retrieve_body( $response )
					)
				);
			}
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] CloudflareCompat::purgeViaApi() exception: %s',
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Purge everything from the Cloudflare CDN via the REST API.
	 *
	 * POST /zones/{zone_id}/purge_cache  body: {"purge_everything": true}
	 * Fire-and-forget.
	 *
	 * @return void
	 */
	private function purgeEverythingViaApi(): void {
		$zoneId   = (string) get_option( 'ltp_cf_zone_id', '' );
		$apiToken = (string) get_option( 'ltp_cf_api_token', '' );

		if ( $zoneId === '' || $apiToken === '' ) {
			return;
		}

		$endpoint = sprintf(
			'https://api.cloudflare.com/client/v4/zones/%s/purge_cache',
			rawurlencode( $zoneId )
		);

		try {
			$response = wp_remote_post(
				$endpoint,
				[
					'timeout' => 10,
					'headers' => [
						'Authorization' => 'Bearer ' . $apiToken,
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode( [ 'purge_everything' => true ] ),
				]
			);

			if ( is_wp_error( $response ) ) {
				error_log(
					sprintf(
						'[OpenTongue] CloudflareCompat::purgeEverythingViaApi() WP_Error: %s',
						$response->get_error_message()
					)
				);
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code !== 200 ) {
				error_log(
					sprintf(
						'[OpenTongue] CloudflareCompat::purgeEverythingViaApi() non-200: %d',
						$code
					)
				);
			}
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] CloudflareCompat::purgeEverythingViaApi() exception: %s',
					$e->getMessage()
				)
			);
		}
	}
}
