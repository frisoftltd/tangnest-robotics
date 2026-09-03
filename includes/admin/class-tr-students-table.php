<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Students list, joined to each student's most recent enrollment (a student
 * is expected to carry one live enrollment at a time in this pass).
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
			'program'      => __( 'Program', 'tangnest-robotics' ),
			'progress'     => __( 'Progress', 'tangnest-robotics' ),
			'enrolled_on'  => __( 'Enrolled', 'tangnest-robotics' ),
			'status'       => __( 'Status', 'tangnest-robotics' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'student_name' => [ 'first_name', false ],
			'enrolled_on'  => [ 'enrolled_on', false ],
			'status'       => [ 'status', false ],
		];
	}

	private function base_from(): string {
		$students    = TR_DB::table_students();
		$enrollments = TR_DB::table_enrollments();
		$programs    = TR_DB::table_programs();
		$families    = TR_DB::table_families();
		global $wpdb;

		return "FROM {$students} s
				LEFT JOIN (
					SELECT e1.* FROM {$enrollments} e1
					INNER JOIN ( SELECT student_id, MAX(id) AS max_id FROM {$enrollments} GROUP BY student_id ) le ON le.max_id = e1.id
				) e ON e.student_id = s.id
				LEFT JOIN {$programs} p ON p.id = e.program_id
				LEFT JOIN {$families} f ON f.id = s.family_id
				LEFT JOIN {$wpdb->users} u ON u.ID = f.parent_user_id";
	}

	protected function get_views(): array {
		global $wpdb;

		$current  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$base_url = admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_STUDENTS );
		$from     = $this->base_from();

		$counts = [
			''          => (int) $wpdb->get_var( "SELECT COUNT(*) {$from}" ),
			'active'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE e.status = %s", [ 'active' ] ) ),
			'completed' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE e.status = %s", [ 'completed' ] ) ),
			'withdrawn' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} WHERE e.status = %s", [ 'withdrawn' ] ) ),
		];

		$labels = [
			''          => __( 'All', 'tangnest-robotics' ),
			'active'    => __( 'Active', 'tangnest-robotics' ),
			'completed' => __( 'Completed', 'tangnest-robotics' ),
			'withdrawn' => __( 'Withdrawn', 'tangnest-robotics' ),
		];

		$out = [];
		foreach ( $labels as $status_value => $label ) {
			$url   = '' === $status_value ? $base_url : add_query_arg( 'status', $status_value, $base_url );
			$class = $current === $status_value ? ' class="current"' : '';
			$key   = '' === $status_value ? 'all' : $status_value;
			$out[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $counts[ $status_value ] );
		}

		return $out;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$programs        = TR_Programs::get_list( [ 'per_page' => 200 ] );
		$selected_program = isset( $_REQUEST['program_id'] ) ? absint( $_REQUEST['program_id'] ) : 0;
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="tr-filter-program"><?php esc_html_e( 'Filter by program', 'tangnest-robotics' ); ?></label>
			<select name="program_id" id="tr-filter-program">
				<option value="0"><?php esc_html_e( 'All programs', 'tangnest-robotics' ); ?></option>
				<?php foreach ( $programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program->id ); ?>" <?php selected( $selected_program, (int) $program->id ); ?>><?php echo esc_html( $program->name ); ?></option>
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
		$program_id   = isset( $_REQUEST['program_id'] ) ? absint( $_REQUEST['program_id'] ) : 0;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$orderby_map = [
			'first_name'  => 's.first_name',
			'enrolled_on' => 'e.enrolled_on',
			'status'      => 'e.status',
		];
		$orderby_key = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$orderby     = $orderby_map[ $orderby_key ] ?? 's.first_name';
		$order       = isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ? 'DESC' : 'ASC';

		$where  = [ '1=1' ];
		$params = [];

		if ( in_array( $status, [ 'active', 'completed', 'withdrawn' ], true ) ) {
			$where[]  = 'e.status = %s';
			$params[] = $status;
		}

		if ( $program_id > 0 ) {
			$where[]  = 'e.program_id = %d';
			$params[] = $program_id;
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

		$offset         = ( max( 1, $current_page ) - 1 ) * $per_page;
		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$sql = "SELECT s.*, e.id AS enrollment_id, e.program_id, e.months_total, e.months_paid, e.enrolled_on, e.status AS enrollment_status,
					p.name AS program_name, f.id AS family_id, u.display_name AS parent_name
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

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'program':
				return esc_html( $item->program_name ?? '—' );
			case 'progress':
				if ( null === $item->enrollment_id ) {
					return '—';
				}
				return esc_html( TR_Enrollments::progress_label( (object) [
					'status'       => $item->enrollment_status,
					'months_paid'  => $item->months_paid,
					'months_total' => $item->months_total,
				] ) );
			case 'enrolled_on':
				return esc_html( $item->enrolled_on ?? '—' );
			case 'status':
				return esc_html( $item->enrollment_status ? ucfirst( $item->enrollment_status ) : ucfirst( $item->status ) );
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

		if ( 'active' === $item->enrollment_status ) {
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
		} elseif ( 'withdrawn' === $item->enrollment_status ) {
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
