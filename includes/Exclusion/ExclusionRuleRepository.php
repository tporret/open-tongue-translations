<?php
/**
 * Repository for exclusion rules stored in {prefix}ott_exclusion_rules.
 *
 * All public methods perform a single well-typed wpdb query and return
 * strongly-typed PHP objects.  Raw data is always sanitised with prepare()
 * before execution; columns that store arrays are never used here.
 *
 * @package OpenToungeTranslations\Exclusion
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Exclusion;

/**
 * Class ExclusionRuleRepository
 */
final class ExclusionRuleRepository {

	/** @var \wpdb */
	private readonly \wpdb $db;

	/** @var string Fully-qualified table name including prefix. */
	private readonly string $table;

	public function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'ott_exclusion_rules';
	}

	// =========================================================================
	// Read
	// =========================================================================

	/**
	 * Return all active rules, optionally scoped to a post.
	 *
	 * Applies the `ott_exclusion_rules` filter after DB fetch so that
	 * developers can inject programmatic rules without a database entry.
	 *
	 * @param int    $postId   0 = do not filter by post_id scope.
	 * @param string $postType Empty string = do not filter by post_type scope.
	 *
	 * @return ExclusionRule[]
	 */
	public function findAll( int $postId = 0, string $postType = '' ): array {
		$conditions = [ 'is_active = 1' ];
		$values     = [];

		// Scope filtering: include global rules + any matching post_type / post_id.
		if ( $postId > 0 || $postType !== '' ) {
			$scopeConditions = [ "scope = 'global'" ];

			if ( $postType !== '' ) {
				$scopeConditions[] = $this->db->prepare( "(scope = 'post_type' AND scope_value = %s)", $postType );
			}

			if ( $postId > 0 ) {
				$scopeConditions[] = $this->db->prepare( "(scope = 'post_id' AND scope_value = %s)", (string) $postId );
			}

			$conditions[] = '(' . implode( ' OR ', $scopeConditions ) . ')';
		}

		$sql  = 'SELECT * FROM ' . $this->table . ' WHERE ' . implode( ' AND ', $conditions );
		$rows = $this->db->get_results( $sql, ARRAY_A ) ?? [];

		$rules = array_map( [ ExclusionRule::class, 'fromRow' ], $rows );

		/**
		 * Filter the list of active exclusion rules.
		 *
		 * Developers can append programmatic rules (e.g. from plugin options)
		 * without adding a database row.
		 *
		 * @param ExclusionRule[] $rules    Rules fetched from the database.
		 * @param int             $postId   The post being translated (0 if unknown).
		 * @param string          $postType The post type (empty if unknown).
		 */
		return (array) apply_filters( 'ott_exclusion_rules', $rules, $postId, $postType );
	}

	/**
	 * Return ALL active rules (no scope filtering).
	 *
	 * Used by ExclusionEngine to pre-load the rule set at boot time.
	 *
	 * @return ExclusionRule[]
	 */
	public function findActive(): array {
		$rows = $this->db->get_results(
			'SELECT * FROM ' . $this->table . ' WHERE is_active = 1 ORDER BY id ASC',
			ARRAY_A
		) ?? [];

		return array_map( [ ExclusionRule::class, 'fromRow' ], $rows );
	}

	/**
	 * Find a single rule by its primary key.
	 *
	 * @param int $id
	 *
	 * @return ExclusionRule|null
	 */
	public function findById( int $id ): ?ExclusionRule {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? ExclusionRule::fromRow( $row ) : null;
	}

	// =========================================================================
	// Write
	// =========================================================================

	/**
	 * Insert a new exclusion rule.
	 *
	 * @param array{
	 *     rule_type:   string,
	 *     rule_value:  string,
	 *     scope?:      string,
	 *     scope_value?:string,
	 *     is_active?:  int,
	 *     created_by:  int,
	 * } $data Column data. `created_at` is set by the DB DEFAULT.
	 *
	 * @return int|false Inserted row ID on success, false on error.
	 */
	public function insert( array $data ): int|false {
		$row = array_merge(
			[
				'scope'       => 'global',
				'scope_value' => null,
				'is_active'   => 1,
			],
			$data
		);

		$inserted = $this->db->insert(
			$this->table,
			$row,
			$this->columnFormats( $row )
		);

		if ( $inserted === false ) {
			return false;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Update an existing rule by ID.
	 *
	 * @param int   $id   Primary key.
	 * @param array $data Columns to update (partial update supported).
	 *
	 * @return bool True on success (including no-op with 0 rows updated), false on DB error.
	 */
	public function update( int $id, array $data ): bool {
		if ( empty( $data ) ) {
			return true;
		}

		$result = $this->db->update(
			$this->table,
			$data,
			[ 'id' => $id ],
			$this->columnFormats( $data ),
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Hard-delete a rule by ID.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return (bool) $this->db->delete(
			$this->table,
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return a wpdb format array for the given column data.
	 *
	 * @param array $data Associative column array.
	 *
	 * @return string[]
	 */
	private function columnFormats( array $data ): array {
		$intColumns = [ 'is_active', 'created_by', 'id' ];
		$formats    = [];

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $intColumns, true ) ? '%d' : '%s';
		}

		return $formats;
	}
}
