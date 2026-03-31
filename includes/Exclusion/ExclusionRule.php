<?php
/**
 * Immutable value object representing a single exclusion rule.
 *
 * Rules tell the ExclusionEngine which regions of HTML to skip when
 * translating — identified by CSS selector, XPath expression, or regex.
 *
 * @package OpenToungeTranslations\Exclusion
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Exclusion;

/**
 * Class ExclusionRule
 *
 * Readonly value object. All properties are set once at construction via
 * the fromRow() named constructor. No setters.
 */
final class ExclusionRule {

	/**
	 * @param int         $id          Primary key from the DB table.
	 * @param string      $ruleType    One of: css_selector, regex, xpath.
	 * @param string      $ruleValue   The selector pattern, regex, or XPath expression.
	 * @param string      $scope       One of: global, post_type, post_id.
	 * @param string|null $scopeValue  Post type slug or post ID string (null for global scope).
	 * @param bool        $isActive    Whether the rule is currently active.
	 * @param int         $createdBy   WP user ID who created the rule (audit trail).
	 * @param string      $createdAt   MySQL DATETIME string.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $ruleType,
		public readonly string $ruleValue,
		public readonly string $scope,
		public readonly ?string $scopeValue,
		public readonly bool $isActive,
		public readonly int $createdBy,
		public readonly string $createdAt,
	) {}

	/**
	 * Build an ExclusionRule from a $wpdb ARRAY_A result row.
	 *
	 * @param array<string, mixed> $row Database row.
	 *
	 * @return self
	 */
	public static function fromRow( array $row ): self {
		return new self(
			id: (int) $row['id'],
			ruleType: (string) $row['rule_type'],
			ruleValue: (string) $row['rule_value'],
			scope: (string) ( $row['scope'] ?? 'global' ),
			scopeValue: isset( $row['scope_value'] ) ? (string) $row['scope_value'] : null,
			isActive: (bool) $row['is_active'],
			createdBy: (int) $row['created_by'],
			createdAt: (string) $row['created_at'],
		);
	}

	/**
	 * Determine whether this rule applies to the given post context.
	 *
	 * Scope semantics:
	 *   global    — applies everywhere; $postId / $postType are irrelevant.
	 *   post_type — applies when $postType matches $scopeValue.
	 *   post_id   — applies when (string)$postId matches $scopeValue.
	 *
	 * @param int    $postId   WordPress post ID of the page being translated.
	 * @param string $postType Post type slug (e.g. 'post', 'page').
	 *
	 * @return bool True when the rule should be evaluated for this context.
	 */
	public function appliesTo( int $postId, string $postType ): bool {
		return match ( $this->scope ) {
			'global'    => true,
			'post_type' => $this->scopeValue === $postType,
			'post_id'   => $this->scopeValue === (string) $postId,
			default     => false,
		};
	}
}
