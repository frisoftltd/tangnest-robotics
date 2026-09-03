<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Families list. Joins wp_users/wp_usermeta directly (with prepare()) since
 * TR_Families itself stays a thin wrapper over its own table only.
 */
class TR_Families_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'family',
			'plural'   => 'families',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'parent_name'      => __( 'Parent', 'tangnest-robotics' ),
			'email'            => __( 'Email', 'tangnest-robotics' ),
			'phone'            => __( 'Phone', 'tangnest-robotics' ),
			'active_students'  => __( 'Active Students', 'tangnest-robotics' ),
			'monthly_amount'   => __( 'Monthly Amount', 'tangnest-robotics' ),
			'billing_day'      => __( 'Billing Day', 'tangnest-robotics' ),
			'next_billing'     => __( 'Next Billing Date', 'tangnest-robotics' ),
			'status'           => __( 'Status', 'tangnest-robotics' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'monthly_amount' => [ 'monthly_amount', false ],
			'billing_day'    => [ 'billing_day', false ],
			'status'         => [ 'status', false ],
		];
	}

	protected function get_views(): array {
		$current  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$base_url = admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_FAMILIES );

		$views = [
			'all'      => [ '', __( 'All', 'tangnest-robotics' ), TR_Families::count() ],
			'active'   => [ 'active', __( 'Active', 'tangnest-robotics' ), TR_Families::count( [ 'status' => 'active' ] ) ],
			'inactive' => [ 'inactive', __( 'Inactive', 'tangnest-robotics' ), TR_Families::count( [ 'status' => 'inactive' ] ) ],
		];

		$out = [];
		foreach ( $views as $key => [ $status_value, $label, $count ] ) {
			$url   = '' === $status_value ? $base_url : add_query_arg( 'status', $status_value, $base_url );
			$class = $current === $status_value ? ' class="current"' : '';
			$out[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $count );
		}

		return $out;
	}

	public function prepare_items(): void {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$status       = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$families_table = TR_DB::table_families();
		$students_table = TR_DB::table_students();

		$orderby_map = [
			'monthly_amount' => 'f.monthly_amount',
			'billing_day'    => 'f.billing_day',
			'status'         => 'f.status',
		];
		$orderby_key = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$orderby     = $orderby_map[ $orderby_key ] ?? 'f.id';
		$order       = isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ? 'DESC' : 'ASC';

		$where  = [ '1=1' ];
		$params = [];

		if ( in_array( $status, [ 'active', 'inactive' ], true ) ) {
			$where[]  = 'f.status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$families_table} f LEFT JOIN {$wpdb->users} u ON u.ID = f.parent_user_id WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$offset       = ( max( 1, $current_page ) - 1 ) * $per_page;
		$select_params = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$sql = "SELECT f.*, u.display_name, u.user_email,
					(SELECT um.meta_value FROM {$wpdb->usermeta} um WHERE um.user_id = f.parent_user_id AND um.meta_key = 'phone_number' LIMIT 1) AS phone,
					(SELECT COUNT(*) FROM {$students_table} s WHERE s.family_id = f.id AND s.status = 'active') AS active_students
				FROM {$families_table} f
				LEFT JOIN {$wpdb->users} u ON u.ID = f.parent_user_id
				WHERE {$where_sql}
				ORDER BY {$orderby} {$order}
				LIMIT %d OFFSET %d";

		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $select_params ) );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'email':
				return esc_html( $item->user_email ?? '' );
			case 'phone':
				return esc_html( $item->phone ?? '' );
			case 'active_students':
				return esc_html( (string) $item->active_students );
			case 'monthly_amount':
				return esc_html( $item->monthly_amount ) . ' RWF';
			case 'billing_day':
				return (int) $item->billing_day > 0 ? esc_html( (string) (int) $item->billing_day ) : esc_html__( 'Not set', 'tangnest-robotics' );
			case 'next_billing':
				$next = TR_Families::next_billing_date( (int) $item->id );
				return $next ? esc_html( $next ) : '&#8212;';
			case 'status':
				return esc_html( ucfirst( $item->status ) );
			default:
				return '';
		}
	}

	public function column_parent_name( $item ): string {
		$edit_url = add_query_arg(
			[ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => 'edit', 'id' => $item->id ],
			admin_url( 'admin.php' )
		);

		$name = $item->display_name ?? __( '(no user)', 'tangnest-robotics' );

		$actions = [
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'tangnest-robotics' ) ),
		];

		return sprintf( '<a href="%s"><strong>%s</strong></a>%s', esc_url( $edit_url ), esc_html( $name ), $this->row_actions( $actions ) );
	}

	public function no_items(): void {
		esc_html_e( 'No families yet.', 'tangnest-robotics' );
	}
}
