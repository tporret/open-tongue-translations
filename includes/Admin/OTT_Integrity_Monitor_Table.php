<?php
/**
 * WP_List_Table implementation for the Token Integrity Monitor.
 *
 * Displays rows from {prefix}ott_integrity_log — each row records a
 * TagProtector token-mismatch event: a string where LibreTranslate dropped
 * or modified one or more [[OTT_TAG_n]] placeholders, indicating HTML was
 * mangled during translation.
 *
 * Developers use this view to identify strings that need an Exclusion Rule
 * so the HTML regions are skipped entirely.
 *
 * @package OpenToungeTranslations\Admin
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class OTT_Integrity_Monitor_Table
 */
final class OTT_Integrity_Monitor_Table extends \WP_List_Table {

	/** @var \wpdb */
	private readonly \wpdb $db;

	/** Fully-qualified log table name. */
	private string $log_table;

	public function __construct() {
		global $wpdb;
		$this->db        = $wpdb;
		$this->log_table = $wpdb->prefix . 'ott_integrity_log';

		parent::__construct( [
			'singular' => 'integrity_event',
			'plural'   => 'integrity_events',
			'ajax'     => false,
		] );
	}

	// =========================================================================
	// WP_List_Table contract
	// =========================================================================

	/**
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return [
			'cb'              => '<input type="checkbox" />',
			'source_hash'     => __( 'Source Hash', 'open-tongue-translations' ),
			'source_text'     => __( 'Affected String', 'open-tongue-translations' ),
			'target_lang'     => __( 'Locale', 'open-tongue-translations' ),
			'token_diff'      => __( 'Token Delta', 'open-tongue-translations' ),
			'request_url'     => __( 'Page URL', 'open-tongue-translations' ),
			'occurred_at'     => __( 'When', 'open-tongue-translations' ),
		];
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public function get_sortable_columns(): array {
		return [
			'target_lang'  => [ 'target_lang', false ],
			'occurred_at'  => [ 'occurred_at', true  ],
		];
	}

	/**
	 * @return array<string,string>
	 */
	public function get_bulk_actions(): array {
		return [
			'delete' => __( 'Delete', 'open-tongue-translations' ),
		];
	}

	/**
	 * Fetch, filter, sort, and paginate log rows.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];

		$this->process_bulk_action();

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = sanitize_sql_orderby( isset( $_REQUEST['orderby'] ) ? wp_unslash( (string) $_REQUEST['orderby'] ) : 'occurred_at' ) ?: 'occurred_at'; // phpcs:ignore WordPress.Security.NonceVerification
		$order   = isset( $_REQUEST['order'] ) && strtoupper( (string) $_REQUEST['order'] ) === 'ASC' ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

		$allowed_cols = array_keys( $this->get_sortable_columns() );
		if ( ! in_array( $orderby, $allowed_cols, true ) ) {
			$orderby = 'occurred_at';
		}

		// Bail gracefully if the table doesn't exist yet.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $this->db->get_var( "SHOW TABLES LIKE '{$this->log_table}'" ) !== $this->log_table ) {
			$this->items = [];
			$this->set_pagination_args( [ 'total_items' => 0, 'per_page' => $per_page, 'total_pages' => 0 ] );
			return;
		}

		if ( $search !== '' ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->log_table} WHERE source_text LIKE %s OR request_url LIKE %s",
					'%' . $this->db->esc_like( $search ) . '%',
					'%' . $this->db->esc_like( $search ) . '%'
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->log_table} WHERE source_text LIKE %s OR request_url LIKE %s ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
					'%' . $this->db->esc_like( $search ) . '%',
					'%' . $this->db->esc_like( $search ) . '%',
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->log_table}"
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->log_table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		$this->items = $items ?? [];

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	// =========================================================================
	// Column renderers
	// =========================================================================

	/** @param array<string,mixed> $item */
	public function column_cb( mixed $item ): string {
		return sprintf(
			'<input type="checkbox" name="integrity_event[]" value="%s" />',
			esc_attr( (string) $item['id'] )
		);
	}

	/** @param array<string,mixed> $item */
	public function column_source_hash( array $item ): string {
		return '<code>' . esc_html( substr( (string) ( $item['source_hash'] ?? '' ), 0, 8 ) ) . '…</code>';
	}

	/** @param array<string,mixed> $item */
	public function column_source_text( array $item ): string {
		$full    = (string) ( $item['source_text'] ?? '' );
		$preview = wp_html_excerpt( wp_strip_all_tags( $full ), 100, '…' );

		$delete_url = wp_nonce_url(
			add_query_arg( [
				'action' => 'delete',
				'row_id' => (int) $item['id'],
			] ),
			'ott_integrity_row_' . (int) $item['id']
		);

		$row_actions = $this->row_actions( [
			'delete' => sprintf(
				'<a href="%s" style="color:#d63638;" onclick="return confirm(%s)">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this log entry?', 'open-tongue-translations' ) ),
				esc_html__( 'Delete', 'open-tongue-translations' )
			),
		] );

		return sprintf(
			'<span title="%s">%s</span>%s',
			esc_attr( $full ),
			esc_html( $preview ),
			$row_actions
		);
	}

	/** @param array<string,mixed> $item */
	public function column_target_lang( array $item ): string {
		return '<code>' . esc_html( (string) ( $item['target_lang'] ?? '' ) ) . '</code>';
	}

	/**
	 * token_diff column — shows expected vs found with a colour-coded delta.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_token_diff( array $item ): string {
		$expected = (int) ( $item['expected_tokens'] ?? 0 );
		$found    = (int) ( $item['found_tokens']    ?? 0 );
		$delta    = $found - $expected;
		$color    = $delta < 0 ? '#d63638' : '#dba617';

		return sprintf(
			'<span title="%s">%s → %s <strong style="color:%s">(%s%d)</strong></span>',
			esc_attr( sprintf(
				/* translators: 1: expected tokens 2: found tokens */
				__( 'Expected %1$d token(s), found %2$d after translation', 'open-tongue-translations' ),
				$expected,
				$found
			) ),
			esc_html( (string) $expected ),
			esc_html( (string) $found ),
			esc_attr( $color ),
			$delta > 0 ? '+' : '',
			$delta
		);
	}

	/** @param array<string,mixed> $item */
	public function column_request_url( array $item ): string {
		$url = (string) ( $item['request_url'] ?? '' );
		if ( $url === '' ) {
			return '—';
		}

		$display = strlen( $url ) > 60 ? substr( $url, 0, 57 ) . '…' : $url;
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
			esc_url( home_url( $url ) ),
			esc_attr( $url ),
			esc_html( $display )
		);
	}

	/** @param array<string,mixed> $item */
	public function column_occurred_at( array $item ): string {
		$raw = (string) ( $item['occurred_at'] ?? '' );
		if ( $raw === '' ) {
			return '—';
		}
		$timestamp = (int) strtotime( $raw );
		return sprintf(
			'<abbr title="%s">%s %s</abbr>',
			esc_attr( $raw ),
			esc_html( human_time_diff( $timestamp, time() ) ),
			esc_html__( 'ago', 'open-tongue-translations' )
		);
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	// =========================================================================
	// Bulk + row action handling
	// =========================================================================

	public function process_bulk_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = $this->current_action();
		if ( $action === false || $action === '' ) {
			return;
		}

		// Single-row delete.
		if ( ! empty( $_GET['row_id'] ) && $action === 'delete' ) { // phpcs:ignore WordPress.Security.NonceVerification
			$row_id = absint( $_GET['row_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'ott_integrity_row_' . $row_id );
			$this->db->delete(
				$this->log_table,
				[ 'id' => $row_id ],
				[ '%d' ]
			);
			wp_safe_redirect( remove_query_arg( [ 'action', 'row_id', '_wpnonce' ] ) );
			exit;
		}

		// Bulk delete.
		if ( $action === 'delete' && ! empty( $_POST['integrity_event'] ) ) {
			check_admin_referer( 'bulk-integrity_events' );
			$ids = array_map( 'absint', (array) $_POST['integrity_event'] );
			foreach ( $ids as $id ) {
				$this->db->delete( $this->log_table, [ 'id' => $id ], [ '%d' ] );
			}
			wp_safe_redirect( remove_query_arg( [ 'action', 'action2', '_wpnonce', 'integrity_event' ] ) );
			exit;
		}
	}

	// =========================================================================
	// Display helper — empty-state message
	// =========================================================================

	public function no_items(): void {
		esc_html_e( 'No token-mismatch events recorded. Your translation pipeline is clean.', 'open-tongue-translations' );
	}
}
