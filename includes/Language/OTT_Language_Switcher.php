<?php
/**
 * Front-end language switcher — shortcode and Gutenberg block.
 *
 * Exposes two authoring surfaces that render the same language selector UI:
 *
 *   [open_tongue_switcher]               WordPress shortcode
 *   ott/language-switcher block          Native Gutenberg block (PHP skeleton;
 *                                        JS build assets are TBD under
 *                                        assets/blocks/language-switcher/)
 *
 * When a visitor clicks a language option, a lightweight fetch() call is
 * dispatched to POST /wp-json/ott/v1/set-lang (registered by
 * OTT_Language_Router). On success the page is reloaded so the new language
 * is applied by the output-buffer interceptor from the start of the response.
 *
 * @package OpenToungeTranslations\Language
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Language;

/**
 * Class OTT_Language_Switcher
 *
 * Registers the shortcode, block, and front-end assets. Instantiated once
 * inside Plugin::boot() after the language service and router are wired.
 */
final class OTT_Language_Switcher {

	/**
	 * @param OTT_Language_Service $service Catalogue of supported languages.
	 * @param OTT_Language_Router  $router  Current-request language resolver.
	 */
	public function __construct(
		private readonly OTT_Language_Service $service,
		private readonly OTT_Language_Router  $router,
	) {}

	/**
	 * Register all hooks for the switcher.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'open_tongue_switcher', [ $this, 'renderShortcode' ] );
		add_action( 'init', [ $this, 'registerBlock' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueAssets' ] );
	}

	// ------------------------------------------------------------------
	// Shortcode
	// ------------------------------------------------------------------

	/**
	 * Render the [open_tongue_switcher] shortcode.
	 *
	 * Accepted attributes:
	 *   style  "select" (default) | "list"  — controls the rendered markup.
	 *
	 * @param array<string, string>|string $atts Raw shortcode attributes.
	 *
	 * @return string HTML output (always escaped).
	 */
	public function renderShortcode( array|string $atts ): string {
		/** @var array<string, string> $atts */
		$atts = shortcode_atts(
			[ 'style' => 'select' ],
			is_array( $atts ) ? $atts : [],
			'open_tongue_switcher'
		);

		$languages   = $this->service->getLanguages();
		$currentLang = $this->router->getEffectiveLang();

		if ( empty( $languages ) ) {
			return '';
		}

		$restUrl = esc_url( rest_url( 'ott/v1/set-lang' ) );
		$nonce   = wp_create_nonce( 'wp_rest' );

		return $atts['style'] === 'list'
			? $this->renderList( $languages, $currentLang, $restUrl, $nonce )
			: $this->renderSelect( $languages, $currentLang, $restUrl, $nonce );
	}

	// ------------------------------------------------------------------
	// Gutenberg block
	// ------------------------------------------------------------------

	/**
	 * Register the ott/language-switcher Gutenberg block (PHP side).
	 *
	 * The render_callback delegates to the shortcode so both surfaces
	 * produce identical markup. Once the JS build pipeline is in place,
	 * a block.json + JS bundle should be registered with register_block_type()
	 * using the directory form instead.
	 *
	 * @return void
	 */
	public function registerBlock(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'ott/language-switcher',
			[
				'api_version'     => 2,
				'title'           => __( 'OTT Language Switcher', 'open-tongue-translations' ),
				'category'        => 'widgets',
				'icon'            => 'translation',
				'description'     => __( 'Displays a language selector powered by Open Tongue Translations.', 'open-tongue-translations' ),
				'render_callback' => function ( array $attributes ): string {
					$style = isset( $attributes['style'] )
						? sanitize_text_field( (string) $attributes['style'] )
						: 'select';

					return $this->renderShortcode( [ 'style' => $style ] );
				},
				'attributes'      => [
					'style' => [
						'type'    => 'string',
						'default' => 'select',
						'enum'    => [ 'select', 'list' ],
					],
				],
			]
		);
	}

	// ------------------------------------------------------------------
	// Assets
	// ------------------------------------------------------------------

	/**
	 * Enqueue the minimal inline JavaScript needed to power the switcher.
	 *
	 * A virtual script handle (no src) is registered so that
	 * wp_add_inline_script() can attach the code without requiring a
	 * separate .js file. The script is placed in the footer to avoid
	 * render-blocking.
	 *
	 * @return void
	 */
	public function enqueueAssets(): void {
		wp_register_script( 'ott-switcher', false, [], '1.0.0', true );
		wp_enqueue_script( 'ott-switcher' );
		wp_add_inline_script( 'ott-switcher', $this->getInlineSwitcherJs() );
	}

	// ------------------------------------------------------------------
	// Private render helpers
	// ------------------------------------------------------------------

	/**
	 * Render a <select> dropdown language picker.
	 *
	 * @param array<int, array{code: string, name: string}> $languages
	 * @param string                                        $currentLang
	 * @param string                                        $restUrl     Pre-escaped REST URL.
	 * @param string                                        $nonce
	 *
	 * @return string
	 */
	private function renderSelect(
		array $languages,
		string $currentLang,
		string $restUrl,
		string $nonce,
	): string {
		$options = '';

		foreach ( $languages as $lang ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $lang['code'] ),
				selected( $currentLang, $lang['code'], false ),
				esc_html( $lang['name'] ),
			);
		}

		return sprintf(
			'<div class="ott-switcher-wrap">'
			. '<select class="ott-lang-select" data-rest-url="%s" data-nonce="%s" aria-label="%s">%s</select>'
			. '</div>',
			esc_attr( $restUrl ),
			esc_attr( $nonce ),
			esc_attr( __( 'Select language', 'open-tongue-translations' ) ),
			$options,
		);
	}

	/**
	 * Render an unordered list of language links.
	 *
	 * @param array<int, array{code: string, name: string}> $languages
	 * @param string                                        $currentLang
	 * @param string                                        $restUrl     Pre-escaped REST URL.
	 * @param string                                        $nonce
	 *
	 * @return string
	 */
	private function renderList(
		array $languages,
		string $currentLang,
		string $restUrl,
		string $nonce,
	): string {
		$items = '';

		foreach ( $languages as $lang ) {
			$activeClass = $lang['code'] === $currentLang ? ' class="ott-active"' : '';

			$items .= sprintf(
				'<li%s><a href="#" class="ott-lang-link" data-lang="%s" data-rest-url="%s" data-nonce="%s">%s</a></li>',
				$activeClass,
				esc_attr( $lang['code'] ),
				esc_attr( $restUrl ),
				esc_attr( $nonce ),
				esc_html( $lang['name'] ),
			);
		}

		return sprintf( '<ul class="ott-lang-list">%s</ul>', $items );
	}

	// ------------------------------------------------------------------
	// Inline JavaScript
	// ------------------------------------------------------------------

	/**
	 * Return the inline JS string that wires up both the <select> and the
	 * <ul> list to the OTT REST endpoint.
	 *
	 * Uses vanilla fetch() — no jQuery or wp.apiFetch dependency — so it
	 * loads equally well on minimal themes.
	 *
	 * @return string
	 */
	private function getInlineSwitcherJs(): string {
		// The indented heredoc content starts at column 0 intentionally so
		// the ouput is not padded with tab characters.
		return <<<'JS'
(function () {
	'use strict';

	/**
	 * Call the OTT REST endpoint to set the visitor's preferred language,
	 * then reload the page so the new language is applied from the start
	 * of the next response.
	 *
	 * @param {string} restUrl  Full URL to /wp-json/ott/v1/set-lang
	 * @param {string} nonce    wp_rest nonce
	 * @param {string} lang     BCP-47 language code
	 */
	function switchLang(restUrl, nonce, lang) {
		fetch(restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			body: JSON.stringify({ lang: lang }),
		})
		.then(function (response) { return response.json(); })
		.then(function (data) {
			if (data && data.success) {
				window.location.reload();
			}
		})
		.catch(function (err) {
			console.error('[OTT] Language switch failed:', err);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {

		// -- <select> dropdown ------------------------------------------
		document.querySelectorAll('.ott-lang-select').forEach(function (el) {
			el.addEventListener('change', function () {
				switchLang(el.dataset.restUrl, el.dataset.nonce, el.value);
			});
		});

		// -- <ul> link list ---------------------------------------------
		document.querySelectorAll('.ott-lang-link').forEach(function (el) {
			el.addEventListener('click', function (e) {
				e.preventDefault();
				switchLang(el.dataset.restUrl, el.dataset.nonce, el.dataset.lang);
			});
		});
	});
}());
JS;
	}
}
