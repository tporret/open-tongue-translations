<?php
/**
 * Detects active static cache layers and wires their invalidation hooks.
 *
 * Multiple compat classes may be active simultaneously on the same site
 * (e.g. WP Rocket serving static HTML + Cloudflare as a CDN in front).
 * This manager instantiates every relevant compat layer and registers them
 * all — both will receive every cache-invalidation event.
 *
 * The purgePost() method on this class is the single entry point all other
 * plugin code should call when a translation changes. It fires the plugin's
 * custom action which each compat layer hooks into independently.
 *
 * @package OpenToungeTranslations\Compat
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Compat;

/**
 * Class StaticCacheCompatManager
 *
 * Coordinates all static-cache compatibility layers. Registered on
 * 'plugins_loaded' at priority 99 so that WP Rocket and Cloudflare plugin
 * hooks, functions, and classes are guaranteed to have been defined first.
 */
final class StaticCacheCompatManager {

	/**
	 * Registered compat layer instances.
	 *
	 * @var array<WpRocketCompat|CloudflareCompat>
	 */
	private array $layers = [];

	/**
	 * Instantiate all compat layers and record them for registration.
	 *
	 * All layers are always instantiated; isActive() inside each layer gates
	 * whether it actually registers hooks.
	 */
	public function __construct() {
		$this->layers = [
			new WpRocketCompat(),
			new CloudflareCompat(),
		];
	}

	/**
	 * Register all active compat layers with WordPress hooks.
	 *
	 * Must be called from plugins_loaded (priority 99) after all plugins have
	 * had their own load hooks run. Each compat layer's register() method checks
	 * isActive() internally and is a no-op if the corresponding plugin is absent.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->layers as $layer ) {
			$layer->register();
		}
	}

	/**
	 * Fire the 'ltp_translation_updated' action for a specific post and locale.
	 *
	 * This is the single, authoritative entry point for cache invalidation
	 * throughout the plugin. All other code that needs to signal a translation
	 * change must call this method — never do_action() directly — so that the
	 * call site is traceable.
	 *
	 * @param int    $postId WordPress post ID whose translation was updated.
	 * @param string $locale BCP-47 locale of the translation that changed.
	 *
	 * @return void
	 */
	public function purgePost( int $postId, string $locale ): void {
		/**
		 * Fires when a post's translation has been updated and static caches
		 * for that post should be invalidated.
		 *
		 * @param int    $postId WordPress post ID.
		 * @param string $locale BCP-47 locale that was updated.
		 */
		do_action( 'ltp_translation_updated', $postId, $locale );
	}

	/**
	 * Fire the 'ltp_content_updated' action for any translatable URL.
	 *
	 * Use this method (instead of purgePost) when the translated content is not
	 * backed by a WP post — widgets, nav menus, template parts, REST endpoints,
	 * etc. It accepts a fully-qualified URL so every compat layer can perform a
	 * targeted CDN/static-cache purge without needing a post ID.
	 *
	 * @param string $url    Absolute URL of the page whose translation changed.
	 * @param string $locale BCP-47 locale of the translation that changed.
	 *
	 * @return void
	 */
	public function purgeUrl( string $url, string $locale ): void {
		/**
		 * Fires when any translated content (not necessarily a post) has changed
		 * and static caches for that URL should be invalidated.
		 *
		 * @param string $url    Absolute URL of the affected page.
		 * @param string $locale BCP-47 locale that was updated.
		 */
		do_action( 'ltp_content_updated', $url, $locale );
	}
}
