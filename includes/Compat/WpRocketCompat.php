<?php
/**
 * WP Rocket static cache compatibility layer.
 *
 * Hooks into the plugin's custom translation-updated actions and purges
 * WP Rocket's static HTML cache for the affected post or entire domain.
 * Only registers hooks when WP Rocket is actually active — no-op otherwise.
 *
 * @package OpenToungeTranslations\Compat
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Compat;

/**
 * Class WpRocketCompat
 *
 * Listens for 'ltp_translation_updated' and 'ltp_locale_flushed' and delegates
 * to WP Rocket's cache purge API. Both hooks run at priority 20 so they fire
 * after the translation is fully committed to the database.
 */
final class WpRocketCompat {

	/**
	 * Detect whether WP Rocket is installed and active.
	 *
	 * Checks for the canonical WP Rocket function and version constant so the
	 * detection works regardless of whether the plugin is active as a regular
	 * plugin or a must-use plugin.
	 *
	 * @return bool True when WP Rocket is available.
	 */
	public function isActive(): bool {
		return function_exists( 'rocket_clean_post' ) || defined( 'WP_ROCKET_VERSION' );
	}

	/**
	 * Register action hooks with WordPress.
	 *
	 * Bails immediately when WP Rocket is not active so the hooks are never
	 * registered on sites that don't need them.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->isActive() ) {
			return;
		}

		// Priority 20 — fires after the translation commit at priority 10.
		add_action( 'ltp_translation_updated', [ $this, 'purgePost' ], 20, 2 );
		add_action( 'ltp_content_updated', [ $this, 'purgeByUrl' ], 20, 2 );
		add_action( 'ltp_locale_flushed', [ $this, 'purgeDomain' ], 20, 1 );
	}

	/**
	 * Purge the WP Rocket cache for a single post.
	 *
	 * Called when a specific post's translation is updated. Purging at post
	 * level is surgical — it leaves the rest of the site cache intact.
	 *
	 * @param int    $postId The WordPress post ID whose translation changed.
	 * @param string $locale BCP-47 locale that was updated (informational).
	 *
	 * @return void
	 */
	public function purgePost( int $postId, string $locale ): void {
		if ( ! function_exists( 'rocket_clean_post' ) ) {
			return;
		}

		try {
			rocket_clean_post( $postId );

			error_log(
				sprintf(
					'[OpenTongue] WpRocketCompat: purged post %d cache (locale: %s).',
					$postId,
					$locale
				)
			);
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] WpRocketCompat::purgePost() exception: %s',
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Purge the WP Rocket cache for a specific URL.
	 *
	 * Called via the 'ltp_content_updated' action for non-post content (widgets,
	 * nav menus, template parts) where no post ID is available. Delegates to
	 * rocket_clean_files() which accepts an array of absolute URLs.
	 *
	 * @param string $url    Absolute URL whose cached version should be purged.
	 * @param string $locale BCP-47 locale that was updated (informational).
	 *
	 * @return void
	 */
	public function purgeByUrl( string $url, string $locale ): void {
		if ( $url === '' || ! function_exists( 'rocket_clean_files' ) ) {
			return;
		}

		try {
			rocket_clean_files( [ $url ] );

			error_log(
				sprintf(
					'[OpenTongue] WpRocketCompat: purged URL cache for "%s" (locale: %s).',
					$url,
					$locale
				)
			);
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] WpRocketCompat::purgeByUrl() exception: %s',
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Purge the WP Rocket cache for the entire domain.
	 *
	 * Called when an entire locale's cache is flushed (e.g. a language pack
	 * update or a bulk re-translation). Purges all static HTML files across
	 * the domain because any page may contain translated content.
	 *
	 * @param string $locale BCP-47 locale that was flushed (informational).
	 *
	 * @return void
	 */
	public function purgeDomain( string $locale ): void {
		if ( ! function_exists( 'rocket_clean_domain' ) ) {
			return;
		}

		try {
			rocket_clean_domain();

			error_log(
				sprintf(
					'[OpenTongue] WpRocketCompat: purged full domain cache (locale: %s flushed).',
					$locale
				)
			);
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] WpRocketCompat::purgeDomain() exception: %s',
					$e->getMessage()
				)
			);
		}
	}
}
