<?php
/**
 * WP_List_Table implementation for manually-edited translation strings.
 *
 * Displays all rows in {prefix}libre_translations where is_manual = 1.
 * Administrators can inline-edit the translated_text or clear the is_manual
 * flag to allow the machine translation engine to take over again.
 *
 * @package OpenToungeTranslations\Admin
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class OTT_Manual_Edits_Table
 */
final class OTT_Manual_Edits_Table extends \WP_List_Table {

	/** @var \wpdb */
	private readonly \wpdb $db;

	/** Fully-qualified table name. */
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'libre_translations';

		parent::__construct( [
			'singular' => 'manual_edit',
			'plural'   => 'manual_edits',
			'ajax'     => false,
		] );
	}

	// =========================================================================
	// WP_List_Table contract
	// =========================================================================

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return [
			'cb'              => '<input type="checkbox" />',
			'source_hash'     => __( 'Source Hash', 'open-tongue-translations' ),
			'source_text'     => __( 'Original String', 'open-tongue-translations' ),
			'translated_text' => __( 'Translated Text', 'open-tongue-translations' ),
			'target_lang'     => __( 'Locale', 'open-tongue-translations' ),
			'last_used'    => __( 'Last Used', 'open-tongue-translations' ),
		];
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function get_sortable_columns(): array {
		return [
			'target_lang'  => [ 'target_lang',  false ],
			'last_used' => [ 'last_used', true  ],
		];
	}

	/**
	 * Bulk action definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions(): array {
		return [
			'revert_to_machine' => __( 'Revert to Machine Translation', 'open-tongue-translations' ),
			'delete'            => __( 'Delete', 'open-tongue-translations' ),
		];
	}

	/**
	 * Fetch rows, apply search, sort, and paginate.
	 */
	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];

		$this->process_bulk_action();

		$per_page    = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = sanitize_sql_orderby( isset( $_REQUEST['orderby'] ) ? wp_unslash( (string) $_REQUEST['orderby'] ) : 'last_used' ) ?: 'last_used'; // phpcs:ignore WordPress.Security.NonceVerification
		$order   = isset( $_REQUEST['order'] ) && strtoupper( (string) $_REQUEST['order'] ) === 'ASC' ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

		// Whitelist sortable columns to prevent SQL injection.
		$allowed_cols = array_keys( $this->get_sortable_columns() );
		if ( ! in_array( $orderby, $allowed_cols, true ) ) {
			$orderby = 'last_used';
		}

		if ( $search !== '' ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE is_manual = 1 AND (source_text LIKE %s OR translated_text LIKE %s)",
					'%' . $this->db->esc_like( $search ) . '%',
					'%' . $this->db->esc_like( $search ) . '%'
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table} WHERE is_manual = 1 AND (source_text LIKE %s OR translated_text LIKE %s) ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
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
				"SELECT COUNT(*) FROM {$this->table} WHERE is_manual = 1"
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table} WHERE is_manual = 1 ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
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

	/**
	 * Checkbox column for bulk actions.
	 *
	 * @param array<string,mixed> $item Row data.
	 */
	public function column_cb( mixed $item ): string {
		return sprintf(
			'<input type="checkbox" name="manual_edit[]" value="%s" />',
			esc_attr( (string) $item['id'] )
		);
	}

	/**
	 * source_hash column — monospaced short-form.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_source_hash( array $item ): string {
		return '<code>' . esc_html( substr( (string) ( $item['source_hash'] ?? '' ), 0, 8 ) ) . '…</code>';
	}

	/**
	 * source_text column — truncated to 80 characters with a tooltip.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_source_text( array $item ): string {
		$full    = (string) ( $item['source_text'] ?? '' );
		$preview = wp_html_excerpt( $full, 80, '…' );

		$edit_url   = $this->row_action_url( (int) $item['id'], 'edit' );
		$revert_url = $this->row_action_url( (int) $item['id'], 'revert_to_machine' );
		$delete_url = $this->row_action_url( (int) $item['id'], 'delete' );

		$row_actions = $this->row_actions( [
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'open-tongue-translations' )
			),
			'revert' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $revert_url ),
				esc_html__( 'Revert to Machine', 'open-tongue-translations' )
			),
			'delete' => sprintf(
				'<a href="%s" style="color:#d63638;" onclick="return confirm(%s)">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this translation? This cannot be undone.', 'open-tongue-translations' ) ),
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

	/**
	 * translated_text column — inline-edit form.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_translated_text( array $item ): string {
		$id     = (int) $item['id'];
		$value  = (string) ( $item['translated_text'] ?? '' );
		$nonce  = wp_create_nonce( 'ott_edit_manual_' . $id );

		return sprintf(
			'<form method="post" style="margin:0;">
				<input type="hidden" name="action" value="ott_update_manual_edit" />
				<input type="hidden" name="row_id" value="%1$d" />
				<input type="hidden" name="_wpnonce" value="%2$s" />
				<textarea name="translated_text" rows="2" style="width:100%%;min-width:180px;">%3$s</textarea>
				<button type="submit" class="button button-small" style="margin-top:4px;">%4$s</button>
			</form>',
			$id,
			esc_attr( $nonce ),
			esc_textarea( $value ),
			esc_html__( 'Save', 'open-tongue-translations' )
		);
	}

	/**
	 * target_lang column.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_target_lang( array $item ): string {
		return '<code>' . esc_html( (string) ( $item['target_lang'] ?? '' ) ) . '</code>';
	}

	/**
	 * last_used column — human-readable diff.
	 *
	 * @param array<string,mixed> $item
	 */
	public function column_last_used( array $item ): string {
		$raw = (string) ( $item['last_used'] ?? '' );
		if ( $raw === '' || $raw === '0000-00-00 00:00:00' ) {
			return esc_html__( 'Never', 'open-tongue-translations' );
		}

		$timestamp = (int) strtotime( $raw );
		return sprintf(
			'<abbr title="%s">%s %s</abbr>',
			esc_attr( $raw ),
			esc_html( human_time_diff( $timestamp, time() ) ),
			esc_html__( 'ago', 'open-tongue-translations' )
		);
	}

	/**
	 * Default column fallback.
	 *
	 * @param array<string,mixed> $item
	 * @param string              $column_name
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	// =========================================================================
	// Bulk action handling
	// =========================================================================

	/**
	 * Process bulk actions and single-row action links.
	 */
	public function process_bulk_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = $this->current_action();
		if ( $action === false || $action === '' ) {
			return;
		}

		// Single-row actions (nonce in URL).
		if ( ! empty( $_GET['row_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$row_id = absint( $_GET['row_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'ott_row_action_' . $row_id );

			match ( $action ) {
				'revert_to_machine' => $this->revert_row( $row_id ),
				'delete'            => $this->delete_row( $row_id ),
				default             => null,
			};

			wp_safe_redirect( remove_query_arg( [ 'action', 'row_id', '_wpnonce' ] ) );
			exit;
		}

		// Inline-edit POST handler.
		if ( $action === 'ott_update_manual_edit' && ! empty( $_POST['row_id'] ) ) {
			$row_id = absint( $_POST['row_id'] );
			check_admin_referer( 'ott_edit_manual_' . $row_id );
			$new_text = sanitize_textarea_field( wp_unslash( (string) ( $_POST['translated_text'] ?? '' ) ) );

			$this->db->update(
				$this->table,
				[ 'translated_text' => $new_text ],
				[ 'id' => $row_id ],
				[ '%s' ],
				[ '%d' ]
			);

			wp_safe_redirect( remove_query_arg( [ 'action', 'row_id', '_wpnonce' ] ) );
			exit;
		}

		// Bulk actions — nonce checked via settings_fields / wp_nonce_field pattern.
		if ( ! empty( $_POST['manual_edit'] ) ) {
			check_admin_referer( 'bulk-manual_edits' );
			$ids = array_map( 'absint', (array) $_POST['manual_edit'] );

			foreach ( $ids as $id ) {
				match ( $action ) {
					'revert_to_machine' => $this->revert_row( $id ),
					'delete'            => $this->delete_row( $id ),
					default             => null,
				};
			}

			wp_safe_redirect( remove_query_arg( [ 'action', 'action2', '_wpnonce', 'manual_edit' ] ) );
			exit;
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Clear the is_manual flag so the machine translation engine takes over.
	 */
	private function revert_row( int $id ): void {
		$this->db->update(
			$this->table,
			[ 'is_manual' => 0 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Hard-delete a translation row.
	 */
	private function delete_row( int $id ): void {
		$this->db->delete(
			$this->table,
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	/**
	 * Build a nonce-signed URL for a row action.
	 */
	private function row_action_url( int $id, string $action ): string {
		return wp_nonce_url(
			add_query_arg( [
				'action' => $action,
				'row_id' => $id,
			] ),
			'ott_row_action_' . $id
		);
	}
}
