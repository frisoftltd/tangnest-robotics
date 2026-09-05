<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Invoices list. Joins wp_users/wp_tr_families directly (with prepare())
 * for the same reason the Families/Students tables do — TR_Invoices stays
 * a thin wrapper over its own table only.
 */
class TR_Invoices_Table extends WP_List_Table {

	private array $summary = [];

	public function __construct() {
		parent::__construct( [
			'singular' => 'invoice',
			'plural'   => 'invoices',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'cb'             => '<input type="checkbox" />',
			'id'             => __( 'ID', 'tangnest-robotics' ),
			'family'         => __( 'Family', 'tangnest-robotics' ),
			'period'         => __( 'Period', 'tangnest-robotics' ),
			'amount'         => __( 'Amount', 'tangnest-robotics' ),
			'status'         => __( 'Status', 'tangnest-robotics' ),
			'due_date'       => __( 'Due Date', 'tangnest-robotics' ),
			'paid_at'        => __( 'Paid Date', 'tangnest-robotics' ),
			'payment_method' => __( 'Method', 'tangnest-robotics' ),
			'reminder'       => __( 'Reminders', 'tangnest-robotics' ),
		];
	}

	protected function get_primary_column_name(): string {
		return 'family';
	}

	protected function get_sortable_columns(): array {
		return [
			'id'       => [ 'id', false ],
			'period'   => [ 'period', false ],
			'amount'   => [ 'amount', false ],
			'due_date' => [ 'due_date', true ],
			'status'   => [ 'status', false ],
		];
	}

	protected function get_bulk_actions(): array {
		return [
			'mark_overdue' => __( 'Mark overdue', 'tangnest-robotics' ),
			'export_csv'   => __( 'Export to CSV', 'tangnest-robotics' ),
		];
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="invoice_ids[]" value="%d" />', (int) $item->id );
	}

	protected function get_views(): array {
		$current  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$base_url = admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_INVOICES );

		$labels = [
			''        => __( 'All', 'tangnest-robotics' ),
			'pending' => __( 'Pending', 'tangnest-robotics' ),
			'overdue' => __( 'Overdue', 'tangnest-robotics' ),
			'paid'    => __( 'Paid', 'tangnest-robotics' ),
			'waived'  => __( 'Waived', 'tangnest-robotics' ),
		];

		$out = [];
		foreach ( $labels as $status_value => $label ) {
			$count = TR_Invoices::count( '' === $status_value ? [] : [ 'status' => $status_value ] );
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

		global $wpdb;
		$periods  = $wpdb->get_col( 'SELECT DISTINCT period FROM ' . TR_DB::table_invoices() . ' ORDER BY period DESC' );
		$programs = TR_Programs::get_list( [ 'per_page' => 200 ] );

		$selected_period  = isset( $_REQUEST['period'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['period'] ) ) : '';
		$selected_program = isset( $_REQUEST['program_id'] ) ? absint( $_REQUEST['program_id'] ) : 0;
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="tr-filter-period"><?php esc_html_e( 'Filter by period', 'tangnest-robotics' ); ?></label>
			<select name="period" id="tr-filter-period">
				<option value=""><?php esc_html_e( 'All periods', 'tangnest-robotics' ); ?></option>
				<?php foreach ( $periods as $period ) : ?>
					<option value="<?php echo esc_attr( $period ); ?>" <?php selected( $selected_period, $period ); ?>><?php echo esc_html( $period ); ?></option>
				<?php endforeach; ?>
			</select>

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

		$status  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$period  = isset( $_REQUEST['period'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['period'] ) ) : '';
		$program = isset( $_REQUEST['program_id'] ) ? absint( $_REQUEST['program_id'] ) : 0;
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$students_table    = TR_DB::table_students();
		$enrollments_table = TR_DB::table_enrollments();

		$orderby_map = [
			'id'       => 'i.id',
			'period'   => 'i.period',
			'amount'   => 'i.amount',
			'due_date' => 'i.due_date',
			'status'   => 'i.status',
		];
		$orderby_key = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$orderby     = $orderby_map[ $orderby_key ] ?? 'i.due_date';
		$order       = isset( $_REQUEST['order'] ) && 'asc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';

		$where  = [ '1=1' ];
		$params = [];

		if ( in_array( $status, TR_Invoices::STATUSES, true ) ) {
			$where[]  = 'i.status = %s';
			$params[] = $status;
		}

		if ( '' !== $period ) {
			$where[]  = 'i.period = %s';
			$params[] = $period;
		}

		if ( $program > 0 ) {
			$where[]  = "i.family_id IN ( SELECT s.family_id FROM {$students_table} s INNER JOIN {$enrollments_table} e ON e.student_id = s.id WHERE e.program_id = %d )";
			$params[] = $program;
		}

		if ( '' !== $search ) {
			$where[]  = 'u.display_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$from_sql  = self::from_sql();

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}", $params ) );

		$offset          = ( max( 1, $current_page ) - 1 ) * $per_page;
		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$sql = "SELECT i.*, u.display_name AS parent_name
				{$from_sql}
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

		$this->summary = self::compute_summary( $where_sql, $params );
	}

	private static function from_sql(): string {
		global $wpdb;
		$invoices_table = TR_DB::table_invoices();
		$families_table = TR_DB::table_families();

		return "FROM {$invoices_table} i
				LEFT JOIN {$families_table} f ON f.id = i.family_id
				LEFT JOIN {$wpdb->users} u ON u.ID = f.parent_user_id";
	}

	/**
	 * Aggregates over the exact same filtered WHERE clause as the list
	 * itself (minus pagination), so the summary bar always describes what
	 * the admin is currently looking at, not the whole table.
	 */
	public static function compute_summary( string $where_sql, array $params ): array {
		global $wpdb;

		// Must be i.status/i.amount, not bare column names — wp_tr_families
		// also has a "status" column, and a bare "status" in SELECT/GROUP BY
		// against this three-table join is an ambiguous column reference
		// that MySQL rejects outright. The query then silently returns no
		// rows, which is why every summary figure was showing zero.
		$sql  = 'SELECT i.status, COALESCE(SUM(i.amount), 0) AS total ' . self::from_sql() . " WHERE {$where_sql} GROUP BY i.status";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$totals_by_status = [];
		foreach ( $rows as $row ) {
			$totals_by_status[ $row->status ] = (float) $row->total;
		}

		return self::totals_from_status_map( $totals_by_status );
	}

	/**
	 * Pure function, split out from compute_summary() so the maths can be
	 * unit-tested without a database. "Invoiced" is defined as exactly
	 * collected + outstanding — a strict identity, not a separate sum —
	 * so the two figures always reconcile regardless of filter. Cancelled
	 * and waived invoices are both excluded: neither is money the family
	 * still owes or that came in, so neither belongs in any of these four
	 * figures. (v0.4.0 included waived in "invoiced", which broke this
	 * identity whenever a waived invoice existed in the filtered set.)
	 */
	public static function totals_from_status_map( array $totals_by_status ): array {
		$collected   = $totals_by_status['paid'] ?? 0.0;
		$outstanding = ( $totals_by_status['pending'] ?? 0.0 ) + ( $totals_by_status['overdue'] ?? 0.0 );
		$invoiced    = $collected + $outstanding;

		$collection_rate = $invoiced > 0 ? round( ( $collected / $invoiced ) * 100, 1 ) : 0.0;

		return [
			'invoiced'        => $invoiced,
			'collected'       => $collected,
			'outstanding'     => $outstanding,
			'collection_rate' => $collection_rate,
		];
	}

	public function render_summary_bar(): void {
		$summary = $this->summary ?? self::totals_from_status_map( [] );
		?>
		<div class="tr-invoice-summary">
			<div class="tr-invoice-summary__item">
				<span class="tr-invoice-summary__label"><?php esc_html_e( 'Invoiced', 'tangnest-robotics' ); ?></span>
				<span class="tr-invoice-summary__value"><?php echo esc_html( number_format( $summary['invoiced'], 2 ) ); ?> RWF</span>
			</div>
			<div class="tr-invoice-summary__item">
				<span class="tr-invoice-summary__label"><?php esc_html_e( 'Collected', 'tangnest-robotics' ); ?></span>
				<span class="tr-invoice-summary__value tr-invoice-summary__value--good"><?php echo esc_html( number_format( $summary['collected'], 2 ) ); ?> RWF</span>
			</div>
			<div class="tr-invoice-summary__item">
				<span class="tr-invoice-summary__label"><?php esc_html_e( 'Outstanding', 'tangnest-robotics' ); ?></span>
				<span class="tr-invoice-summary__value tr-invoice-summary__value--bad"><?php echo esc_html( number_format( $summary['outstanding'], 2 ) ); ?> RWF</span>
			</div>
			<div class="tr-invoice-summary__item">
				<span class="tr-invoice-summary__label"><?php esc_html_e( 'Collection Rate', 'tangnest-robotics' ); ?></span>
				<span class="tr-invoice-summary__value"><?php echo esc_html( number_format( $summary['collection_rate'], 1 ) ); ?>%</span>
			</div>
		</div>
		<?php
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return '#' . esc_html( $item->id );
			case 'period':
				return esc_html( $item->period );
			case 'amount':
				return esc_html( number_format( (float) $item->amount, 2 ) . ' ' . $item->currency );
			case 'due_date':
				return esc_html( $item->due_date );
			case 'paid_at':
				return $item->paid_at ? esc_html( substr( $item->paid_at, 0, 10 ) ) : '&#8212;';
			case 'payment_method':
				return $this->format_payment_method( $item );
			default:
				return '';
		}
	}

	/**
	 * "irembopay" is stored as the payment_method value (see
	 * TR_Payment::mark_paid_and_advance()) but must render with its proper
	 * brand capitalisation, not the generic ucfirst(str_replace()) used for
	 * cash/bank/mobile_money — "Irembopay" reads as a typo, not a brand.
	 */
	private function format_payment_method( object $item ): string {
		if ( 'irembopay' === $item->payment_method ) {
			$label = esc_html__( 'IremboPay', 'tangnest-robotics' );
			if ( ! empty( $item->payment_reference ) ) {
				$label .= '<br><span class="tr-invoice-meta">' . esc_html( $item->payment_reference ) . '</span>';
			}
			return $label;
		}

		if ( ! empty( $item->irembopay_invoice_number ) ) {
			return '&#8212;<br><span class="tr-invoice-meta">' . esc_html( $item->irembopay_invoice_number ) . '</span>';
		}

		return $item->payment_method ? esc_html( ucfirst( str_replace( '_', ' ', $item->payment_method ) ) ) : '&#8212;';
	}

	public function column_status( $item ): string {
		return sprintf(
			'<span class="tr-badge tr-badge--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( ucfirst( $item->status ) )
		);
	}

	public function column_family( $item ): string {
		$name = $item->parent_name ?? __( '(no user)', 'tangnest-robotics' );
		$url  = add_query_arg(
			[ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => 'edit', 'id' => $item->family_id ],
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%s">%s</a>%s', esc_url( $url ), esc_html( $name ), $this->render_actions_html( $this->row_actions_for( $item ) ) );
	}

	public function column_reminder( $item ): string {
		if ( empty( $item->reminder_count ) ) {
			return esc_html__( 'None sent', 'tangnest-robotics' );
		}

		$days_ago = $item->last_reminder_sent ? (int) floor( ( current_time( 'timestamp' ) - strtotime( $item->last_reminder_sent ) ) / DAY_IN_SECONDS ) : 0;

		if ( $days_ago <= 0 ) {
			return esc_html( sprintf(
				/* translators: %d: number of reminders sent */
				__( '%d sent, last today', 'tangnest-robotics' ),
				(int) $item->reminder_count
			) );
		}

		return esc_html( sprintf(
			/* translators: 1: number of reminders sent, 2: days since the last one */
			_n( '%1$d sent, last %2$d day ago', '%1$d sent, last %2$d days ago', $days_ago, 'tangnest-robotics' ),
			(int) $item->reminder_count,
			$days_ago
		) );
	}

	/**
	 * Builds the row-actions markup by hand instead of WP core's
	 * row_actions() helper — that helper always joins actions with a
	 * literal " | ", and there is no clean way to strip that via CSS alone
	 * without also hiding the links. This gives every action its own
	 * .tr-row-action--{key} class for the pill styling and per-action
	 * (e.g. destructive) targeting in admin.css.
	 */
	private function render_actions_html( array $actions ): string {
		$items = '';
		foreach ( $actions as $key => $html ) {
			$items .= '<span class="tr-row-action tr-row-action--' . esc_attr( $key ) . '">' . $html . '</span>';
		}

		return '<div class="row-actions tr-row-actions">' . $items . '</div>';
	}

	private function row_actions_for( object $item ): array {
		$actions      = [];
		$nonce_action = 'tr_invoice_row_action_' . $item->id;

		if ( in_array( $item->status, [ 'pending', 'overdue' ], true ) ) {
			$actions['record_payment'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_INVOICES, 'action' => 'record_payment', 'id' => $item->id ], admin_url( 'admin.php' ) ) ),
				esc_html__( 'Record payment', 'tangnest-robotics' )
			);

			$actions['send_reminder_email'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_INVOICES, 'tr_row_action' => 'send_reminder_email', 'id' => $item->id ], admin_url( 'admin.php' ) ), $nonce_action ) ),
				esc_html__( 'Send reminder (Email)', 'tangnest-robotics' )
			);

			$whatsapp_url = wp_nonce_url(
				add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_INVOICES, 'tr_row_action' => 'send_reminder_whatsapp', 'id' => $item->id ], admin_url( 'admin.php' ) ),
				$nonce_action
			);
			// New tab: this link hits our own handler first (so the send is
			// logged and reminder state recorded), which then issues the raw
			// header() redirect to web.whatsapp.com — target="_blank" just
			// means that final landing happens in a new tab instead of
			// carrying the admin away from the list.
			$actions['send_reminder_whatsapp'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $whatsapp_url ),
				esc_html__( 'Send reminder (WhatsApp)', 'tangnest-robotics' )
			);

			$actions['waive'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_INVOICES, 'action' => 'waive', 'id' => $item->id ], admin_url( 'admin.php' ) ) ),
				esc_html__( 'Waive', 'tangnest-robotics' )
			);

			$actions['cancel'] = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_INVOICES, 'tr_row_action' => 'cancel', 'id' => $item->id ], admin_url( 'admin.php' ) ), $nonce_action ) ),
				esc_js( __( 'Cancel this invoice?', 'tangnest-robotics' ) ),
				esc_html__( 'Cancel', 'tangnest-robotics' )
			);
		}

		$actions['view_family'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => 'edit', 'id' => $item->family_id ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'View family', 'tangnest-robotics' )
		);

		return $actions;
	}

	public function no_items(): void {
		esc_html_e( 'No invoices yet.', 'tangnest-robotics' );
	}
}
