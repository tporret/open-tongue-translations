<?php
/**
 * Validates exclusion rules before storage to prevent broken patterns from
 * reaching content pages.
 *
 * @package OpenToungeTranslations\Exclusion
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Exclusion;

/**
 * Class ExclusionRuleValidator
 *
 * Each rule type requires a different validation strategy:
 *
 *   css_selector — convert to XPath with the internal CssToXPath helper,
 *                  then evaluate on an empty DOMDocument. Returns WP_Error
 *                  if the selector is unsupported or the XPath is invalid.
 *
 *   regex        — test with @preg_match() inside a sandboxed error handler
 *                  (set_error_handler / restore_error_handler in a finally
 *                  block) to catch E_WARNING without triggering a PHP fatal.
 *                  This is the ONLY safe way to test arbitrary regex patterns.
 *
 *   xpath        — evaluate on an empty DOMDocument; catch any thrown
 *                  DOMException or libxml error.
 */
final class ExclusionRuleValidator {

	/**
	 * Validate a rule before it is stored in the database.
	 *
	 * @param string $ruleType  One of: css_selector, regex, xpath.
	 * @param string $ruleValue The selector, pattern, or XPath expression.
	 *
	 * @return true|\WP_Error true on valid, WP_Error describing the problem on invalid.
	 */
	public function validate( string $ruleType, string $ruleValue ): true|\WP_Error {
		if ( trim( $ruleValue ) === '' ) {
			return new \WP_Error( 'ott_empty_rule', 'Rule value must not be empty.' );
		}

		return match ( $ruleType ) {
			'css_selector' => $this->validateCssSelector( $ruleValue ),
			'regex'        => $this->validateRegex( $ruleValue ),
			'xpath'        => $this->validateXpath( $ruleValue ),
			default        => new \WP_Error(
				'ott_unknown_rule_type',
				sprintf( 'Unknown rule type "%s". Allowed: css_selector, regex, xpath.', $ruleType )
			),
		};
	}

	// =========================================================================
	// Private validators
	// =========================================================================

	/**
	 * Validate a CSS selector by converting it to XPath and evaluating it.
	 *
	 * @param string $selector
	 *
	 * @return true|\WP_Error
	 */
	private function validateCssSelector( string $selector ): true|\WP_Error {
		$xpath = CssToXPath::convert( $selector );

		if ( $xpath === null ) {
			return new \WP_Error(
				'ott_unsupported_selector',
				sprintf(
					'CSS selector "%s" could not be converted to XPath. ' .
					'Supported patterns: element, .class, #id, element.class, [attr], [attr=value], parent > child.',
					$selector
				)
			);
		}

		return $this->validateXpath( $xpath );
	}

	/**
	 * Validate a regex pattern using a sandboxed error handler.
	 *
	 * CRITICAL: the sandboxed handler MUST be restored in a finally block.
	 * If the handler is not restored, subsequent PHP errors on content pages
	 * will be swallowed silently, making debugging impossible.
	 *
	 * The @-suppressed preg_match() alone is insufficient — it suppresses the
	 * E_WARNING echo but does not prevent preg_last_error() from being set
	 * and, on some PCRE versions, can produce a recoverable fatal rather than
	 * a warning. The custom handler converts any E_WARNING into captured state.
	 *
	 * @param string $pattern
	 *
	 * @return true|\WP_Error
	 */
	private function validateRegex( string $pattern ): true|\WP_Error {
		$capturedError = null;

		// Install a sandboxed error handler that captures E_WARNING messages
		// from preg_match() instead of displaying them or triggering a fatal.
		set_error_handler( static function ( int $errno, string $errstr ) use ( &$capturedError ): bool {
			$capturedError = $errstr;
			return true; // Returning true suppresses the default PHP error handler.
		}, E_WARNING );

		try {
			// A false return from preg_match() indicates a PCRE error (invalid pattern).
			// @-suppression is a secondary guard; the set_error_handler above is primary.
			$result = @preg_match( $pattern, '' );
		} finally {
			// Always restore the original error handler — even if preg_match() throws.
			restore_error_handler();
		}

		if ( $result === false || $capturedError !== null ) {
			return new \WP_Error(
				'ott_invalid_regex',
				sprintf(
					'Invalid regex pattern: %s',
					$capturedError ?? preg_last_error_msg()
				)
			);
		}

		return true;
	}

	/**
	 * Validate an XPath expression by evaluating it on an empty DOMDocument.
	 *
	 * @param string $xpath
	 *
	 * @return true|\WP_Error
	 */
	private function validateXpath( string $xpath ): true|\WP_Error {
		libxml_use_internal_errors( true );

		try {
			$dom = new \DOMDocument();
			$dom->loadHTML( '<html><body></body></html>', LIBXML_NOERROR );
			$domXpath = new \DOMXPath( $dom );
			$result   = @$domXpath->evaluate( $xpath );

			if ( $result === false ) {
				return new \WP_Error(
					'ott_invalid_xpath',
					sprintf( 'Invalid XPath expression: "%s".', $xpath )
				);
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'ott_invalid_xpath',
				sprintf( 'XPath evaluation error: %s', $e->getMessage() )
			);
		} finally {
			libxml_clear_errors();
		}

		return true;
	}
}

/**
 * Minimal CSS-to-XPath converter.
 *
 * Supports the selector patterns required by the spec:
 *   element, .class, #id, element.class, [attr], [attr=value], parent > child
 *
 * Returns null for unsupported syntax so callers can surface a clear error.
 *
 * @internal Used only by ExclusionRuleValidator and ExclusionEngine.
 * @package OpenToungeTranslations\Exclusion
 */
final class CssToXPath {

	/**
	 * Convert a CSS selector to an XPath expression.
	 *
	 * ## Supported selectors
	 *
	 * | CSS                  | XPath equivalent                              |
	 * |----------------------|-----------------------------------------------|
	 * | `element`            | `//element`                                   |
	 * | `.class`             | `//*[contains(@class, 'class')]`              |
	 * | `#id`                | `//*[@id='id']`                               |
	 * | `element.class`      | `//element[contains(@class, 'class')]`        |
	 * | `[attr]`             | `//*[@attr]`                                  |
	 * | `[attr=value]`       | `//*[@attr='value']`                          |
	 * | `parent > child`     | `//parent/child`                              |
	 *
	 * ## Unsupported selectors (documented)
	 * - Descendant combinators (space): `div p` — ambiguous without full parser.
	 * - Sibling combinators: `~`, `+`.
	 * - Pseudo-classes: `:first-child`, `:not()`, `:nth-child()`.
	 * - Attribute substring matchers: `[class^=foo]`, `[class*=foo]`.
	 * - Universal selector: `*` (already valid XPath; pass through directly).
	 *
	 * @param string $selector CSS selector string.
	 *
	 * @return string|null XPath expression, or null if the selector is unsupported.
	 */
	public static function convert( string $selector ): ?string {
		$selector = trim( $selector );

		if ( $selector === '' ) {
			return null;
		}

		// Universal selector pass-through.
		if ( $selector === '*' ) {
			return '//*';
		}

		// Parent > child combinator: convert each segment and chain with /.
		if ( str_contains( $selector, '>' ) ) {
			$parts  = array_map( 'trim', explode( '>', $selector ) );
			$xparts = [];
			foreach ( $parts as $index => $part ) {
				$converted = self::convertSimple( $part );
				if ( $converted === null ) {
					return null;
				}
				// First part gets // prefix; subsequent parts get / (direct child).
				$xparts[] = ( $index === 0 )
					? $converted
					: ltrim( $converted, '/' );
			}
			return implode( '/', $xparts );
		}

		return self::convertSimple( $selector );
	}

	/**
	 * Convert a simple (no combinator) CSS selector to XPath.
	 *
	 * @param string $selector
	 *
	 * @return string|null
	 */
	private static function convertSimple( string $selector ): ?string {
		// #id
		if ( preg_match( '/^#([\w-]+)$/', $selector, $m ) ) {
			return sprintf( "//*[@id='%s']", $m[1] );
		}

		// .class
		if ( preg_match( '/^\.([\w-]+)$/', $selector, $m ) ) {
			return sprintf( "//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $m[1] );
		}

		// element.class
		if ( preg_match( '/^([\w-]+)\.([\w-]+)$/', $selector, $m ) ) {
			return sprintf(
				"//%s[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
				$m[1],
				$m[2]
			);
		}

		// [attr=value] or [attr]
		if ( preg_match( '/^\[([a-z][a-z0-9_-]*)(?:=[\'"]?([^\]\'\"]*)[\'"]?)?\]$/i', $selector, $m ) ) {
			return isset( $m[2] ) && $m[2] !== ''
				? sprintf( "//*[@%s='%s']", $m[1], $m[2] )
				: sprintf( '//*[@%s]', $m[1] );
		}

		// element (plain tag name)
		if ( preg_match( '/^[a-z][a-z0-9-]*$/i', $selector ) ) {
			return '//' . $selector;
		}

		// Unsupported.
		return null;
	}
}
