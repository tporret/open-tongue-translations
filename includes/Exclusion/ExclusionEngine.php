<?php
/**
 * Masks/unmasks excluded DOM regions before and after translation.
 *
 * The engine has two main responsibilities:
 *
 *   shouldExclude($text)   — fast regex scan of the raw text node value to
 *                            decide whether the whole string is excluded.
 *
 *   maskExcluded($html)    — walk the DOM, find nodes matching the active
 *                            rule set, replace their outer HTML with opaque
 *                            [[OTT_EXCL_n]] tokens so the translator never
 *                            sees the protected content.
 *
 *   unmaskExcluded($html)  — simple str_replace to restore tokens after
 *                            the translation API call has returned.
 *
 * The engine pre-loads active rules from the repository at construction time
 * and caches derived regex/XPath representations to avoid re-compiling them
 * on every page view.
 *
 * @package OpenToungeTranslations\Exclusion
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Exclusion;

/**
 * Class ExclusionEngine
 */
final class ExclusionEngine {

	/** Placeholder format — must not collide with OTT_TAG_*, OTT_ATTR_*, OTT_TERM_*. */
	private const PLACEHOLDER_FORMAT  = '[[OTT_EXCL_%d]]';
	private const PLACEHOLDER_PATTERN = '/\[\[OTT_EXCL_\d+\]\]/';

	/** @var ExclusionRule[] */
	private array $rules;

	/** @var string[] Pre-compiled regex patterns for shouldExclude(). */
	private array $regexPatterns = [];

	/** @var string[] Pre-compiled XPath expressions (from css_selector + xpath rules). */
	private array $xpathExpressions = [];

	/** @var int Current post ID (0 = unknown). */
	private int $postId;

	/** @var string Current post type ('' = unknown). */
	private string $postType;

	/**
	 * @param ExclusionRuleRepository $ruleRepo
	 * @param int                     $postId   Optional: set to filter scope-aware rules.
	 * @param string                  $postType Optional: set to filter scope-aware rules.
	 */
	public function __construct(
		private readonly ExclusionRuleRepository $ruleRepo,
		int $postId = 0,
		string $postType = ''
	) {
		$this->postId   = $postId;
		$this->postType = $postType;
		$this->loadRules();
	}

	// =========================================================================
	// Context setters (called by OutputBufferInterceptor before each request)
	// =========================================================================

	/**
	 * Update the post context and reload rules.
	 *
	 * Called once per request by OutputBufferInterceptor after WP
	 * has determined the current post.
	 *
	 * @param int    $postId
	 * @param string $postType
	 */
	public function setContext( int $postId, string $postType ): void {
		if ( $this->postId === $postId && $this->postType === $postType ) {
			return;
		}

		$this->postId   = $postId;
		$this->postType = $postType;
		$this->loadRules();
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Fast-path check: should the entire text node be excluded?
	 *
	 * Only regex rules are checked here; DOM-based rules require full HTML and
	 * are handled by maskExcluded().
	 *
	 * @param string $text Plain text (not HTML).
	 *
	 * @return bool
	 */
	public function shouldExclude( string $text ): bool {
		foreach ( $this->regexPatterns as $pattern ) {
			if ( preg_match( $pattern, $text ) === 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace excluded DOM regions with opaque tokens.
	 *
	 * Steps:
	 *   1. Load into DOMDocument (libxml internal errors suppressed).
	 *   2. For each XPath expression, evaluate and replace matching nodes.
	 *   3. Save the modified HTML fragment.
	 *
	 * If no XPath expressions are configured the HTML is returned unchanged.
	 *
	 * @param string $html
	 *
	 * @return array{html: string, map: array<string, string>}
	 *                html — HTML with excluded regions replaced by tokens.
	 *                map  — maps token (key) to original outer HTML (value).
	 */
	public function maskExcluded( string $html ): array {
		$map = [];

		if ( empty( $this->xpathExpressions ) ) {
			return [ 'html' => $html, 'map' => $map ];
		}

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();

		// Wrap in a root element so loadHTML doesn't inject <html><body> wrappers.
		$wrapped = '<ott-root>' . $html . '</ott-root>';

		if ( ! $dom->loadHTML( '<?xml encoding="UTF-8"?>' . $wrapped, LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			libxml_clear_errors();
			return [ 'html' => $html, 'map' => $map ];
		}

		$domXpath = new \DOMXPath( $dom );
		$counter  = 0;

		foreach ( $this->xpathExpressions as $expr ) {
			$nodes = @$domXpath->query( $expr );
			if ( ! $nodes || $nodes->length === 0 ) {
				continue;
			}

			// Collect nodes before modifying the tree (live NodeList).
			$matched = iterator_to_array( $nodes );

			foreach ( $matched as $node ) {
				if ( ! ( $node instanceof \DOMElement ) ) {
					continue;
				}

				$placeholder = sprintf( self::PLACEHOLDER_FORMAT, $counter++ );
				$outerHtml   = $this->outerHtml( $dom, $node );

				$map[ $placeholder ] = $outerHtml;

				// Replace node with a text node containing the placeholder.
				$textNode = $dom->createTextNode( $placeholder );
				$node->parentNode?->replaceChild( $textNode, $node );
			}
		}

		// Extract only the inner HTML of <ott-root> to discard DOMDocument wrappers.
		$root = $dom->getElementsByTagName( 'ott-root' )->item( 0 );

		if ( $root === null ) {
			libxml_clear_errors();
			return [ 'html' => $html, 'map' => $map ];
		}

		$maskedHtml = $this->innerHtml( $dom, $root );

		libxml_clear_errors();

		return [ 'html' => $maskedHtml, 'map' => $map ];
	}

	/**
	 * Restore excluded regions from their token placeholders.
	 *
	 * @param string               $html
	 * @param array<string,string> $map  Token → original outer HTML.
	 *
	 * @return string
	 */
	public function unmaskExcluded( string $html, array $map ): string {
		if ( empty( $map ) ) {
			return $html;
		}

		return str_replace( array_keys( $map ), array_values( $map ), $html );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Load active rules and pre-compile patterns / XPath expressions.
	 */
	private function loadRules(): void {
		$this->rules            = $this->ruleRepo->findAll( $this->postId, $this->postType );
		$this->regexPatterns    = [];
		$this->xpathExpressions = [];

		foreach ( $this->rules as $rule ) {
			match ( $rule->ruleType ) {
				'regex' => $this->regexPatterns[]     = $rule->ruleValue,
				'css_selector' => $this->xpathExpressions[] = CssToXPath::convert( $rule->ruleValue ) ?? '',
				'xpath'       => $this->xpathExpressions[] = $rule->ruleValue,
			};
		}

		// Remove empty XPath expressions (unsupported CSS selectors).
		$this->xpathExpressions = array_filter( $this->xpathExpressions );
	}

	/**
	 * Return the outer HTML of a DOMElement without DOMDocument wrappers.
	 *
	 * DOMDocument::saveHTML($node) is used (not saveHTML() on the document)
	 * to avoid injecting <!DOCTYPE>, <html>, <head>, and <body> wrappers.
	 *
	 * @param \DOMDocument $dom
	 * @param \DOMElement  $node
	 *
	 * @return string
	 */
	private function outerHtml( \DOMDocument $dom, \DOMElement $node ): string {
		return $dom->saveHTML( $node ) ?: '';
	}

	/**
	 * Return the inner HTML of a DOMElement (all children serialised).
	 *
	 * @param \DOMDocument $dom
	 * @param \DOMElement  $root
	 *
	 * @return string
	 */
	private function innerHtml( \DOMDocument $dom, \DOMElement $root ): string {
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}
}
