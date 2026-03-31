<?php
/**
 * Protects HTML attribute values from being translated by LibreTranslate.
 *
 * Provides a defence-in-depth layer complementing TagProtector. After
 * TagProtector has replaced full HTML tags with [[OTT_TAG_n]] placeholders,
 * no raw tags should remain in the translated payload. However, if regex
 * tokenisation is used (when `dom` is unavailable) and attribute values
 * contain `>`, some attribute content may leak through as visible text.
 *
 * This class scans for common attribute patterns and tokenises their VALUES
 * separately, ensuring URLs, `src`, `action`, and `data-*` attributes are
 * never passed to the translation API regardless of which strategy is used.
 *
 * @package OpenToungeTranslations\Html
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Html;

/**
 * Class AttributePreserver
 *
 * Tokenises href, src, action, and data-* attribute values into numbered
 * [[OTT_ATTR_n]] placeholders. Provides the same protect/restore contract
 * as TagProtector.
 */
final class AttributePreserver {

	/**
	 * Placeholder format for attribute tokens.
	 *
	 * Must NOT collide with [[OTT_TAG_n]], [[OTT_EXCL_n]], or [[OTT_TERM_n]].
	 */
	public const PLACEHOLDER_FORMAT = '[[OTT_ATTR_%d]]';

	/**
	 * Pattern used to detect any remaining [[OTT_ATTR_n]] token.
	 */
	public const PLACEHOLDER_PATTERN = '/\[\[OTT_ATTR_\d+\]\]/';

	/**
	 * Attribute names whose VALUES should always be protected.
	 *
	 * Matched case-insensitively. data-* is handled separately via a wildcard.
	 */
	private const PROTECTED_ATTRS = [ 'href', 'src', 'action', 'srcset', 'poster', 'formaction', 'cite' ];

	/**
	 * Tokenise translatable-unsafe attribute values in $html.
	 *
	 * Scans for:
	 *   href="...", src="...", action="...", data-*="..."
	 *   (and their single-quoted variants)
	 *
	 * Only the VALUE is replaced — the attribute name and quotes are preserved
	 * in-place so the surrounding markup remains valid.
	 *
	 * @param string $html The (possibly already tag-tokenised) HTML string.
	 *
	 * @return array{html: string, map: array<string, string>}
	 *   'html' — the string with attribute values replaced by placeholders.
	 *   'map'  — ['[[OTT_ATTR_0]]' => 'https://example.com/fr/', …]
	 */
	public function protect( string $html ): array {
		$map     = [];
		$counter = 0;

		// Build the alternation of protected attribute names.
		$attrAlt = implode( '|', array_map( 'preg_quote', self::PROTECTED_ATTRS, array_fill( 0, count( self::PROTECTED_ATTRS ), '/' ) ) );

		// Pattern: named attribute or data-* attribute, followed by = and a quoted value.
		// Captures: (1) attr name including quotes, (2) value, (3) closing quote char.
		// Covers double-quoted and single-quoted variants.
		$pattern = '/\b(?:' . $attrAlt . '|data-[a-z0-9_-]+)\s*=\s*(["\'])(.*?)\1/si';

		$html = (string) preg_replace_callback(
			$pattern,
			function ( array $match ) use ( &$map, &$counter ): string {
				$attrName  = $match[0]; // Full matched attr string, e.g. href="https://…"
				$quote     = $match[1]; // " or '
				$value     = $match[2]; // The raw attribute value

				if ( trim( $value ) === '' ) {
					return $attrName; // Never tokenise empty attribute values.
				}

				// Reconstruct the attribute, replacing only the value.
				$token         = sprintf( self::PLACEHOLDER_FORMAT, $counter );
				$map[ $token ] = $value;
				++$counter;

				// Replace only the value portion; keep attr name and quotes intact.
				$replaced = str_replace(
					$quote . $value . $quote,
					$quote . $token . $quote,
					$attrName
				);

				return $replaced;
			},
			$html
		);

		return [ 'html' => $html, 'map' => $map ];
	}

	/**
	 * Restore [[OTT_ATTR_n]] placeholders to their original attribute values.
	 *
	 * Unlike TagProtector::restore(), this method does not fail-safe on mismatch
	 * because attribute tokenisation is a defence-in-depth layer — a mismatch
	 * here (e.g. if LibreTranslate alters a data-* placeholder) is logged as a
	 * warning and the text is partially restored. Full-document integrity is
	 * enforced by HtmlAwareTranslator::translate()'s final tag-count check.
	 *
	 * @param string               $text The translated text containing [[OTT_ATTR_n]] tokens.
	 * @param array<string, string> $map The map produced by protect().
	 *
	 * @return string The text with attribute values restored.
	 */
	public function restore( string $text, array $map ): string {
		if ( empty( $map ) ) {
			return $text;
		}

		$remaining = preg_match_all( self::PLACEHOLDER_PATTERN, $text );

		if ( $remaining !== count( $map ) ) {
			error_log( sprintf(
				'[OpenTongue] AttributePreserver::restore() — token mismatch: expected %d, found %d. Restoring what is possible.',
				count( $map ),
				(int) $remaining
			) );
		}

		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}
}
