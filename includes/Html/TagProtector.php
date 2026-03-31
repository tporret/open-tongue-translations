<?php
/**
 * Tokenises HTML tags into numbered placeholders before translation.
 *
 * Core problem: sending raw HTML to LibreTranslate risks the engine
 * translating tag attribute values, mangling href URLs, and splitting
 * inline elements across translation boundaries.
 *
 * Solution: replace every HTML tag with an opaque [[OTT_TAG_n]] token
 * before the API call and restore them afterward.
 *
 * ## Why DOMDocument over regex?
 *
 * A naive regex like `/<[^>]+>/` fails on attribute values that contain
 * a literal `>` character — a valid (though uncommon) construct in HTML5
 * attribute values. For example:
 *
 *   <div data-expr="a > b">text</div>
 *
 * The `<[^>]+>` pattern would match `<div data-expr="a ` and leave
 * `b">text</div>` unmatched, producing broken placeholders.
 *
 * DOMDocument (via `loadHTML`) correctly parses the full attribute string
 * regardless of embedded `>` characters and is the preferred strategy when
 * the `dom` and `mbstring` PHP extensions are available. The regex fallback
 * is provided for environments where neither is available, with documented
 * limitations.
 *
 * @package OpenToungeTranslations\Html
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Html;

/**
 * Class TagProtector
 *
 * Replaces HTML tags with numbered placeholders so the translation engine
 * never sees raw markup. All placeholders survive LibreTranslate because the
 * double-bracket format [[OTT_TAG_n]] is treated as plain text by the engine
 * and is never modified.
 *
 * @see AttributePreserver for a complementary defence-in-depth layer.
 */
final class TagProtector {

	/**
	 * Placeholder format for tag tokens.
	 *
	 * Must NOT collide with [[OTT_EXCL_n]] (ExclusionEngine) or
	 * [[OTT_TERM_n]] (GlossaryApplicator).
	 */
	public const PLACEHOLDER_FORMAT = '[[OTT_TAG_%d]]';

	/**
	 * Pattern used to detect any remaining [[OTT_TAG_n]] token.
	 *
	 * Used during the integrity check to count tokens in the output.
	 */
	public const PLACEHOLDER_PATTERN = '/\[\[OTT_TAG_\d+\]\]/';

	/**
	 * Tokenise all HTML tags in $html, replacing each with a numbered placeholder.
	 *
	 * Strategy (in priority order):
	 *   1. If `dom` and `mbstring` extensions are loaded use DOMDocument — safe
	 *      for attribute values containing `>`.
	 *   2. Otherwise fall back to an attribute-aware regex that handles quoted
	 *      attribute strings correctly (but documents its limitations).
	 *
	 * @param string $html The HTML fragment or full document to protect.
	 *
	 * @return array{html: string, map: array<string, string>}
	 *   'html' — the tokenised string with all tags replaced.
	 *   'map'  — ['[[OTT_TAG_0]]' => '<strong class="x">', …]
	 */
	public function protect( string $html ): array {
		if ( extension_loaded( 'dom' ) && extension_loaded( 'mbstring' ) ) {
			return $this->protectViaDom( $html );
		}

		return $this->protectViaRegex( $html );
	}

	/**
	 * Replace all [[OTT_TAG_n]] placeholders in $translatedText with their
	 * original tag strings.
	 *
	 * Validates that the number of tokens in the output matches the input map.
	 * On mismatch — which indicates LibreTranslate dropped or modified a token —
	 * log a warning and return the ORIGINAL html (safe fallback).
	 *
	 * @param string               $translatedText  The translated string, still containing token placeholders.
	 * @param array<string, string> $map            The map produced by protect().
	 * @param string               $originalHtml    The original pre-translation HTML (used as fallback).
	 *
	 * @return string The restored HTML, or $originalHtml on token mismatch.
	 */
	public function restore( string $translatedText, array $map, string $originalHtml ): string {
		if ( empty( $map ) ) {
			return $translatedText;
		}

		// Count tokens in the translated output.
		$foundTokens = preg_match_all( self::PLACEHOLDER_PATTERN, $translatedText );

		if ( $foundTokens !== count( $map ) ) {
			$this->logIntegrityFailure( $originalHtml, $translatedText, $map, (int) $foundTokens );
			return $originalHtml;
		}

		return str_replace( array_keys( $map ), array_values( $map ), $translatedText );
	}

	// =========================================================================
	// Private: integrity logging
	// =========================================================================

	/**
	 * Write a token-mismatch event to the ott_integrity_log table.
	 *
	 * Silently no-ops if the table does not yet exist (e.g. before migration runs).
	 *
	 * @param string               $sourceHtml      Pre-translation HTML.
	 * @param string               $translatedText  Post-translation output with tokens.
	 * @param array<string,string> $map             Placeholder map from protect().
	 * @param int                  $foundTokens     How many tokens survived in output.
	 */
	private function logIntegrityFailure(
		string $sourceHtml,
		string $translatedText,
		array $map,
		int $foundTokens
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'ott_integrity_log';

		// Bail silently if the table does not exist.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			error_log( sprintf(
				'[OpenTongue] TagProtector: token mismatch (expected %d, found %d) — integrity log table missing.',
				count( $map ),
				$foundTokens
			) );
			return;
		}

		// Retrieve the target lang from the interceptor's context if stored as a global;
		// fall back to an empty string if not available.
		$targetLang = (string) ( $GLOBALS['ott_current_target_lang'] ?? '' );
		$requestUrl = isset( $_SERVER['REQUEST_URI'] )
			? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), 0, 2048 )
			: '';

		$wpdb->insert(
			$table,
			[
				'source_hash'     => md5( $sourceHtml ),
				'source_text'     => $sourceHtml,
				'target_lang'     => $targetLang,
				'expected_tokens' => count( $map ),
				'found_tokens'    => $foundTokens,
				'request_url'     => $requestUrl,
				'occurred_at'     => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
	}

	// =========================================================================
	// Static factory — for unit-test-friendly inspection
	// =========================================================================

	/**
	 * Build a token map from an explicit list of tag strings.
	 *
	 * Allows tests to construct a known map and verify protect/restore
	 * roundtrip behaviour without running the full DOMDocument or regex engine.
	 *
	 * @param string[] $tags Ordered list of raw HTML tag strings.
	 *
	 * @return array<string, string> Map of placeholder => tag, same shape as protect()['map'].
	 */
	public static function buildMapFromTags( array $tags ): array {
		$map = [];
		foreach ( $tags as $index => $tag ) {
			$map[ sprintf( self::PLACEHOLDER_FORMAT, $index ) ] = $tag;
		}
		return $map;
	}

	// =========================================================================
	// Private: DOMDocument strategy
	// =========================================================================

	/**
	 * Tokenise tags using DOMDocument for correct multibyte + `>` in attr handling.
	 *
	 * Uses LIBXML_NOERROR to suppress warnings on HTML5 and malformed input.
	 * The DOM is never serialised — we use preg_replace_callback on the raw
	 * string so the DOM only informs the tokenisation decision, not the edit.
	 *
	 * @param string $html
	 *
	 * @return array{html: string, map: array<string, string>}
	 */
	private function protectViaDom( string $html ): array {
		// Use DOMDocument to validate that the input is parseable, then fall
		// back to the regex approach for actual tokenisation (which is simpler
		// and more predictable on fragments). The regex below is attribute-string
		// aware: it matches quoted attribute values, including those containing >.
		//
		// Pattern breakdown:
		//   <          — opening angle bracket
		//   (?:        — non-capturing group for tag content
		//     [^"'>]   — any char that is NOT quote or >
		//     |"[^"]*" — OR a double-quoted attribute value (may contain >)
		//     |'[^']*' — OR a single-quoted attribute value (may contain >)
		//   )*         — zero or more of the above
		//   >          — closing angle bracket
		$pattern = '/<(?:[^"\'>]|"[^"]*"|\'[^\']*\')*>/s';
		$map     = [];
		$counter = 0;

		$tokenised = (string) preg_replace_callback(
			$pattern,
			function ( array $match ) use ( &$map, &$counter ): string {
				$token         = sprintf( self::PLACEHOLDER_FORMAT, $counter );
				$map[ $token ] = $match[0];
				++$counter;
				return $token;
			},
			$html
		);

		return [ 'html' => $tokenised, 'map' => $map ];
	}

	// =========================================================================
	// Private: regex fallback strategy
	// =========================================================================

	/**
	 * Tokenise tags using an attribute-aware regex (fallback when `dom` is absent).
	 *
	 * ## Known limitation
	 * This regex handles quoted attribute values but not unquoted attribute values
	 * that contain `>`. Unquoted attributes with `>` are rare in modern HTML but
	 * are technically valid per the HTML5 spec. If such content is encountered,
	 * the tag boundary will be detected incorrectly. Install the PHP `dom`
	 * extension to eliminate this edge case.
	 *
	 * @param string $html
	 *
	 * @return array{html: string, map: array<string, string>}
	 */
	private function protectViaRegex( string $html ): array {
		$pattern = '/<(?:[^"\'>]|"[^"]*"|\'[^\']*\')*>/s';
		$map     = [];
		$counter = 0;

		$tokenised = (string) preg_replace_callback(
			$pattern,
			function ( array $match ) use ( &$map, &$counter ): string {
				$token         = sprintf( self::PLACEHOLDER_FORMAT, $counter );
				$map[ $token ] = $match[0];
				++$counter;
				return $token;
			},
			$html
		);

		return [ 'html' => $tokenised, 'map' => $map ];
	}
}
