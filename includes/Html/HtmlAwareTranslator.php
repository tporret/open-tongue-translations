<?php
/**
 * HTML-aware translation pipeline orchestrator.
 *
 * Implements TranslationClientInterface as a decorator over the raw LibreTranslate
 * client. Inserts tag tokenisation, attribute preservation, and exclusion masking
 * in the correct order so the API never receives raw markup or protected content.
 *
 * ## Pipeline (execute in this exact order — see translate())
 *
 *  STEP 1 — Exclusion: short-circuit if the entire text is excluded.
 *  STEP 2 — Exclusion masking: tokenise excluded DOM regions.
 *  STEP 3 — Attribute preservation: tokenise href/src/data-* values.
 *  STEP 4 — Tag tokenisation: replace HTML tags with [[OTT_TAG_n]] tokens.
 *  STEP 5 — LibreTranslate API call with format='html'.
 *  STEP 6 — Restore tags.
 *  STEP 7 — Restore attributes.
 *  STEP 8 — Restore exclusion regions.
 *  STEP 9 — Integrity check: tag count in output must match input.
 *
 * @package OpenToungeTranslations\Html
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Html;

use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;
use OpenToungeTranslations\Exclusion\ExclusionEngine;

/**
 * Class HtmlAwareTranslator
 *
 * Acts as a drop-in replacement for any TranslationClientInterface implementation.
 * All interceptors and CLI commands receive this class — the raw driver is
 * encapsulated and never exposed to callers.
 */
final class HtmlAwareTranslator implements TranslationClientInterface {

	public function __construct(
		private readonly TranslationClientInterface $innerClient,
		private readonly TagProtector $tagProtector,
		private readonly AttributePreserver $attrPreserver,
		private readonly ExclusionEngine $exclusionEngine,
	) {}

	/**
	 * Translate $text through the full HTML-aware pipeline.
	 *
	 * The pipeline is wrapped in a top-level try/catch: any exception at any
	 * step returns the original $text unchanged. Pages are never served with
	 * broken markup.
	 *
	 * Developer filter hooks are applied at defined intercept points:
	 *
	 *   ott_pre_translate  — may short-circuit the API call entirely.
	 *   ott_post_translate — may post-process the translated string.
	 *
	 * {@inheritDoc}
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string {
		if ( trim( $text ) === '' ) {
			return $text;
		}

		try {
			return $this->runPipeline( $text, $sourceLang, $targetLang );
		} catch ( \Throwable $e ) {
			error_log( sprintf(
				'[OpenTongue] HtmlAwareTranslator::translate() — pipeline exception: %s. Returning original text.',
				$e->getMessage()
			) );
			return $text;
		}
	}

	/**
	 * Delegate health checks to the inner raw client.
	 *
	 * {@inheritDoc}
	 */
	public function healthCheck(): bool {
		return $this->innerClient->healthCheck();
	}

	/**
	 * Delegate language listing to the inner raw client.
	 *
	 * {@inheritDoc}
	 */
	public function listLanguages(): array {
		return $this->innerClient->listLanguages();
	}

	// =========================================================================
	// Private: the 9-step pipeline
	// =========================================================================

	/**
	 * Execute the full translation pipeline.
	 *
	 * @param string $text       Original HTML or plain text.
	 * @param string $sourceLang BCP-47 source language code.
	 * @param string $targetLang BCP-47 target language code.
	 *
	 * @return string Translated text with all tokens restored.
	 */
	private function runPipeline( string $text, string $sourceLang, string $targetLang ): string {
		// -----------------------------------------------------------------------
		// STEP 1 — Exclusion short-circuit
		// -----------------------------------------------------------------------
		if ( $this->exclusionEngine->shouldExclude( $text ) ) {
			return $text;
		}

		// -----------------------------------------------------------------------
		// STEP 2 — Exclusion masking (tokenise excluded DOM regions)
		// -----------------------------------------------------------------------
		[ 'html' => $maskedText, 'map' => $exclusionMap ] = $this->exclusionEngine->maskExcluded( $text );

		// -----------------------------------------------------------------------
		// STEP 3 — Attribute preservation
		// -----------------------------------------------------------------------
		[ 'html' => $attrProtected, 'map' => $attrMap ] = $this->attrPreserver->protect( $maskedText );

		// -----------------------------------------------------------------------
		// STEP 4 — Tag tokenisation
		// -----------------------------------------------------------------------
		[ 'html' => $tokenised, 'map' => $tagMap ] = $this->tagProtector->protect( $attrProtected );

		// -----------------------------------------------------------------------
		// Developer filter: ott_pre_translate
		// Short-circuit the API call — but still apply glossary via caller.
		// -----------------------------------------------------------------------

		/**
		 * Allow developers to provide a translation without calling the API.
		 *
		 * If this filter returns a non-null string, the API call is skipped.
		 * IMPORTANT: the returned value must still be a tokenised string
		 * (i.e. with [[OTT_TAG_n]] placeholders still in place) because the
		 * restore steps below will run regardless of whether the API was called.
		 *
		 * @param string|null $preTranslated Null to proceed normally, or a translated string.
		 * @param string      $text          The tokenised text about to be sent to the API.
		 * @param string      $sourceLang    BCP-47 source language.
		 * @param string      $targetLang    BCP-47 target language.
		 */
		$preTranslated = apply_filters( 'ott_pre_translate', null, $tokenised, $sourceLang, $targetLang );

		// -----------------------------------------------------------------------
		// STEP 5 — API call (or use pre_translate filter result)
		// -----------------------------------------------------------------------
		$translatedTokenised = ( $preTranslated !== null )
			? (string) $preTranslated
			: $this->innerClient->translate( $tokenised, $sourceLang, $targetLang );

		// -----------------------------------------------------------------------
		// STEP 6 — Restore tags
		// -----------------------------------------------------------------------
		$tagsRestored = $this->tagProtector->restore( $translatedTokenised, $tagMap, $text );

		// If restore() detected a token mismatch it already returned $text.
		// We can detect this by checking if the output equals $text; if so,
		// skip further restoration and return immediately.
		if ( $tagsRestored === $text ) {
			return $text;
		}

		// -----------------------------------------------------------------------
		// STEP 7 — Restore attributes
		// -----------------------------------------------------------------------
		$attrsRestored = $this->attrPreserver->restore( $tagsRestored, $attrMap );

		// -----------------------------------------------------------------------
		// STEP 8 — Restore exclusion regions
		// -----------------------------------------------------------------------
		$fullyRestored = $this->exclusionEngine->unmaskExcluded( $attrsRestored, $exclusionMap );

		// -----------------------------------------------------------------------
		// STEP 9 — Final integrity check: HTML tag count parity
		// -----------------------------------------------------------------------
		$inputTagCount  = preg_match_all( '/<[^>]+>/', $text );
		$outputTagCount = preg_match_all( '/<[^>]+>/', $fullyRestored );

		if ( $inputTagCount !== $outputTagCount ) {
			error_log( sprintf(
				'[OpenTongue] HtmlAwareTranslator: integrity check failed — input had %d HTML tags, output has %d. Returning original text.',
				(int) $inputTagCount,
				(int) $outputTagCount
			) );
			return $text;
		}

		// -----------------------------------------------------------------------
		// Developer filter: ott_post_translate
		// -----------------------------------------------------------------------

		/**
		 * Allow post-processing of translated strings.
		 *
		 * @param string $fullyRestored The fully translated and restored HTML.
		 * @param string $text          The original input text.
		 * @param string $targetLang    BCP-47 target language.
		 */
		$fullyRestored = (string) apply_filters( 'ott_post_translate', $fullyRestored, $text, $targetLang );

		return $fullyRestored;
	}
}
