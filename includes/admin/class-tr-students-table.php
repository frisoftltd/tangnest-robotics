<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Students list. A child no longer carries its own program or progress
 * (v0.8.0) — both live on the family's package now, since siblings finish
 * together, so this table reads them from the family, not an enrollment.
 */
class TR_Students_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'student',
			'plural'   => 'students',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'student_name' => __( 'Student', 'tangnest-robotics' ),
			'family'       => __( 'Family', 'tangnest-robotics' ),
			'package'      => __( 'Package', 'tangnest-robotics' ),
			'progress'     => __( 'Progress', 'tangnest-robotics' ),
			'status'       => __( 'Status', 'tangnest-robotics' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'student_name' => [ 'first_name', false ],
			'status'       => [ 'status', false ],
		];
	}

	private function base_from(): string {
		$students = TR_DB::table_students();
		$families = TR_DB::table_families();
		global $wpdb;

		return "FROM {$students} s
				LEFT JOIN {$families} f ON f.id = s.family_id
				LEFT JOIN {$wpdb->users} u ON u.ID = f.parent_user_id";
	}

	protected function get_views(): array {
		global $wpdb;

		$current  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$base_url = admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_STUDENTS );
		$from     = $this->base_from();

		$labels = [ '' => __( 'All', 'tangnest-robotics' ) ];
		foreach ( TR_Students::STATUSES as $status_value ) {
			$labels[ $status_value ] = ucfirst( $status_value );
		}

		$out = [];
		foreach ( $labels as $status_value => $label ) {
			$count = '' === $status_value
				? (int) $wpdb->get_var( "SELECT COUNT(*) {$from}" )
				: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE s.status = %s", [ $status_value ] ) );

			$url   = '' === $status_value ? $base_url : add_query_arg( 'status', $status_value, $base_url );
			$class = $current === $status_value ? ' class="current"' : '';
			$key   = '' === $status_value ? 'all' : $status_value;
			$out[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $count );
		}

		return $out;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$packages         = TR_Programs::get_list( [ 'per_page' => 200 ] );
		$selected_package = isset( $_REQUEST['package_id'] ) ? absint( $_REQUEST['package_id'] ) : 0;
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="tr-filter-package"><?php esc_html_e( 'Filter by package', 'tangnest-robotics' ); ?></label>
			<select name="package_id" id="tr-filter-package">
				<option value="0"><?php esc_html_e( 'All packages', 'tangnest-robotics' ); ?></option>
				<?php foreach ( $packages as $package ) : ?>
					<option value="<?php echo esc_attr( $package->id ); ?>" <?php selected( $selected_package, (int) $package->id ); ?>><?php echo esc_html( $package->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'tangnest-robotics' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function prepare_items(): void {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$status       = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$package_id   = isset( $_REQUEST['package_id'] ) ? absint( $_REQUEST['package_id'] ) : 0;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$orderby_map = [
			'first_name' => 's.first_name',
			'status'     => 's.status',
		];
		$orderby_key = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$orderby     = $orderby_map[ $orderby_key ] ?? 's.first_name';
		$order       = isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ? 'DESC' : 'ASC';

		$where  = [ '1=1' ];
		$params = [];

		if ( in_array( $status, TR_Students::STATUSES, true ) ) {
			$where[]  = 's.status = %s';
			$params[] = $status;
		}

		if ( $package_id > 0 ) {
			$where[]  = 'f.package_id = %d';
			$params[] = $package_id;
		}

		if ( '' !== $search ) {
			$where[]  = '(s.first_name LIKE %s OR s.last_name LIKE %s OR u.display_name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$from      = $this->base_from();

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE {$where_sql}", $params ) );

		$offset          = ( max( 1, $current_page ) - 1 ) * $per_page;
		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$sql = "SELECT s.*, f.id AS family_id, f.package_id, f.months_paid, f.status AS family_status, u.display_name AS parent_name
				{$from}
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

	/**
	 * Reconstructs the bits of a family row TR_Families::progress_label()
	 * needs, from the columns already selected in prepare_items() — avoids
	 * a second query per row just to call a method that only reads three
	 * fields.
	 */
	private function family_progress_stub( object $item ): object {
		return (object) [
			'status'      => $item->family_status,
			'package_id'  => $item->package_id,
			'months_paid' => $item->months_paid,
		];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'package':
				if ( empty( $item->package_id ) ) {
					return '—';
				}
				$package = TR_Programs::get( (int) $item->package_id );
				return esc_html( $package->name ?? __( '(deleted)', 'tangnest-robotics' ) );
			case 'progress':
				if ( empty( $item->family_id ) ) {
					return '—';
				}
				return esc_html( TR_Families::progress_label( $this->family_progress_stub( $item ) ) );
			case 'status':
				return esc_html( ucfirst( $item->status ) );
			default:
				return '';
		}
	}

	public function column_student_name( $item ): string {
		$edit_url = add_query_arg(
			[ 'page' => TR_Admin_Menu::PAGE_STUDENTS, 'action' => 'edit', 'id' => $item->id ],
			admin_url( 'admin.php' )
		);

		$actions = [
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'tangnest-robotics' ) ),
		];

		if ( 'active' === $item->status ) {
			$withdraw_url = wp_nonce_url(
				add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_STUDENTS, 'tr_row_action' => 'withdraw', 'id' => $item->id ], admin_url( 'admin.php' ) ),
				'tr_student_row_action_' . $item->id
			);
			$actions['withdraw'] = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $withdraw_url ),
				esc_js( __( 'Withdraw this student?', 'tangnest-robotics' ) ),
				esc_html__( 'Withdraw', 'tangnest-robotics' )
			);
		} elseif ( 'withdrawn' === $item->status ) {
			$reactivate_url = wp_nonce_url(
				add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_STUDENTS, 'tr_row_action' => 'reactivate', 'id' => $item->id ], admin_url( 'admin.php' ) ),
				'tr_student_row_action_' . $item->id
			);
			$actions['reactivate'] = sprintf( '<a href="%s">%s</a>', esc_url( $reactivate_url ), esc_html__( 'Reactivate', 'tangnest-robotics' ) );
		}

		$name = trim( $item->first_name . ' ' . $item->last_name );

		return sprintf( '<a href="%s"><strong>%s</strong></a>%s', esc_url( $edit_url ), esc_html( $name ), $this->row_actions( $actions ) );
	}

	public function column_family( $item ): string {
		if ( empty( $item->family_id ) ) {
			return '—';
		}

		$url = add_query_arg(
			[ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => 'edit', 'id' => $item->family_id ],
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $item->parent_name ?? __( '(no user)', 'tangnest-robotics' ) ) );
	}

	public function no_items(): void {
		esc_html_e( 'No students yet.', 'tangnest-robotics' );
	}
}
