<?php
/**
 * Interception layer 2: PHP output buffer over complete page HTML.
 *
 * Hooks into `template_redirect` to open an output buffer before WordPress
 * starts sending the theme template. When the buffer flushes (end of the
 * request), processBuffer() receives the entire rendered HTML, extracts
 * visible text nodes (excluding script/style/code/pre/textarea/noscript
 * blocks), batches them into a single API call, and re-injects the
 * translated text before the response is sent to the client.
 *
 * This layer captures strings that are printed directly (echo, printf) and
 * therefore bypass the gettext filter — e.g. hardcoded theme text or
 * third-party output that doesn't use translation functions.
 *
 * @package OpenToungeTranslations\Interception
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Interception;

use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;
use OpenToungeTranslations\Exclusion\ExclusionEngine;

/**
 * Class OutputBufferInterceptor
 *
 * Buffers the complete page HTML on front-end requests and translates all
 * visible text nodes in a single batched API call.
 *
 * ## Text extraction strategy — regex (scaffold implementation)
 *
 * DOMDocument is the ideal tool for parsing HTML into a node tree, but it
 * triggers warnings on malformed markup (missing doctype, unclosed tags,
 * HTML5 elements) and recovers with lossy corrections that can subtly
 * alter the document structure.
 *
 * This scaffold uses a two-pass regex approach instead:
 *   Pass 1 — replace excluded blocks (script, style, …) with opaque
 *             placeholders so their content is invisible to pass 2.
 *   Pass 2 — extract non-whitespace text nodes (content between tags)
 *             and replace them with indexed placeholders.
 *   API call — join all text with a unique separator, translate once,
 *               split on the same separator, and restore placeholders.
 *
 * TODO: Replace the regex strategy with a DOMDocument + libxml
 *       LIBXML_NOERROR implementation once we have a robust test suite of
 *       real-world HTML from target sites. DOMDocument is safer for heavily
 *       nested or JavaScript-template-heavy pages.
 *
 * TODO: Evaluate LibreTranslate's batch endpoint (POST /translate with
 *       q as a JSON array) to replace the separator-join approach. Batch
 *       mode eliminates separator collision risk and may improve throughput.
 */
final class OutputBufferInterceptor {

	/**
	 * Separator injected between batched text nodes before the API call.
	 *
	 * Must be a string that:
	 *   a) Will not appear in normal page content.
	 *   b) Is unlikely to be altered by the translation engine.
	 *
	 * TODO: If the translation backend mangles this separator, switch to
	 *       the LibreTranslate batch (array) API instead.
	 */
	private const BATCH_SEPARATOR = "\n\x00OTT_SEP\x00\n";

	/**
	 * HTML tags whose content must NEVER be translated.
	 * Changing this list affects both passes of the regex strategy.
	 */
	private const EXCLUDED_TAGS = [ 'script', 'style', 'code', 'pre', 'textarea', 'noscript' ];

	/** @var ExclusionEngine|null Injected after construction by Plugin::boot(). */
	private ?ExclusionEngine $exclusionEngine = null;

	/**
	 * @param TranslationClientInterface $client     Resolved translation driver.
	 * @param string                     $targetLang BCP-47 tag for the desired output language.
	 */
	public function __construct(
		private readonly TranslationClientInterface $client,
		private readonly string $targetLang,
	) {}

	/**
	 * Inject the ExclusionEngine after construction.
	 *
	 * Called by Plugin::boot() once the engine is fully wired.
	 *
	 * @param ExclusionEngine $engine
	 */
	public function setExclusionEngine( ExclusionEngine $engine ): void {
		$this->exclusionEngine = $engine;
	}

	/**
	 * Register the template_redirect action with WordPress.
	 *
	 * Priority 1 — runs early so we open the buffer before any other plugin
	 * that might echo content on the same hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'startBuffer' ], 1 );
	}

	/**
	 * Open the output buffer after confirming this is a front-end page request.
	 *
	 * Does nothing on admin screens or AJAX calls so WP-Admin and REST remain
	 * unaffected.
	 *
	 * @return void
	 */
	public function startBuffer(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		ob_start( [ $this, 'processBuffer' ] );
	}

	/**
	 * Output-buffer callback: translate all visible text nodes in the page HTML.
	 *
	 * Called automatically by PHP when the buffer is flushed at the end of the
	 * request. Must return a string — returning anything else (or throwing) would
	 * corrupt the response. Any exception is caught, logged, and the original
	 * $html is returned unchanged.
	 *
	 * @param string $html The complete rendered page HTML.
	 *
	 * @return string The HTML with visible text nodes translated, or the original
	 *                $html if any step fails.
	 */
	public function processBuffer( string $html ): string {
		if ( trim( $html ) === '' ) {
			return $html;
		}

		try {
			return $this->translateHtml( $html );
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] OutputBufferInterceptor: exception during processBuffer — returning original HTML. Error: %s',
					$e->getMessage()
				)
			);
			return $html;
		}
	}

	// -------------------------------------------------------------------------
	// Private implementation
	// -------------------------------------------------------------------------

	/**
	 * Perform the two-pass extraction, batch translation, and re-injection.
	 *
	 * @param string $html Raw page HTML.
	 *
	 * @return string HTML with translated text nodes.
	 */
	private function translateHtml( string $html ): string {
		// --- Pre-pass: mask excluded DOM regions (css_selector / xpath rules) -
		$exclusionMap = [];
		if ( $this->exclusionEngine !== null ) {
			[ 'html' => $html, 'map' => $exclusionMap ] = $this->exclusionEngine->maskExcluded( $html );
		}

		// --- Pass 1: hide excluded tag blocks behind opaque placeholders ------
		/** @var array<string, string> $blockPlaceholders key → original block HTML */
		$blockPlaceholders = [];

		$tagList       = implode( '|', self::EXCLUDED_TAGS );
		$blockPattern  = sprintf( '#<(%s)[^>]*>.*?</\1>#si', $tagList );

		$html = (string) preg_replace_callback(
			$blockPattern,
			function ( array $match ) use ( &$blockPlaceholders ): string {
				$placeholder                      = '<!--OTT_BLOCK_' . count( $blockPlaceholders ) . '-->';
				$blockPlaceholders[ $placeholder ] = $match[0];
				return $placeholder;
			},
			$html
		);

		// --- Pass 2: extract non-whitespace text nodes ------------------------
		/** @var array<string, string> $textPlaceholders key → original text */
		$textPlaceholders = [];

		// Match text that sits between HTML tags (or at start/end of document).
		// The negative look-around ensures we don't capture tag content or
		// comment internals — only raw character data.
		$textPattern = '/(?<=>|^)([^<]+)(?=<|$)/s';

		$html = (string) preg_replace_callback(
			$textPattern,
			function ( array $match ) use ( &$textPlaceholders ): string {
				$node = $match[1];

				// Skip whitespace-only nodes — they carry no translatable content.
				if ( trim( $node ) === '' ) {
					return $node;
				}

				$placeholder                       = '<!--OTT_TEXT_' . count( $textPlaceholders ) . '-->';
				$textPlaceholders[ $placeholder ]  = $node;
				return $placeholder;
			},
			$html
		);

		// --- Batch API call ---------------------------------------------------
		if ( ! empty( $textPlaceholders ) ) {
			$originals = array_values( $textPlaceholders );
			$keys      = array_keys( $textPlaceholders );
			$combined  = implode( self::BATCH_SEPARATOR, $originals );

			$translatedCombined = $this->client->translate(
				text: $combined,
				sourceLang: 'auto',
				targetLang: $this->targetLang,
			);

			$translatedParts = explode( self::BATCH_SEPARATOR, $translatedCombined );

			foreach ( $keys as $index => $placeholder ) {
				// Fall back to the original node if the engine returns fewer
				// parts than we sent (e.g. it stripped the separator).
				$translated = $translatedParts[ $index ] ?? $textPlaceholders[ $placeholder ];
				$html       = str_replace( $placeholder, $translated, $html );
			}
		}

		// --- Restore excluded block placeholders ------------------------------
		foreach ( $blockPlaceholders as $placeholder => $original ) {
			$html = str_replace( $placeholder, $original, $html );
		}

		// --- Post-pass: unmask excluded DOM regions ---------------------------
		if ( $this->exclusionEngine !== null && ! empty( $exclusionMap ) ) {
			$html = $this->exclusionEngine->unmaskExcluded( $html, $exclusionMap );
		}

		return $html;
	}
}
