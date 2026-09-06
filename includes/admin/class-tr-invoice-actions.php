<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every invoice row action, the two small forms (record payment / waive),
 * and the bulk actions (mark overdue / export CSV) for the Invoices screen.
 */
class TR_Invoice_Actions {
	const PAYMENT_METHODS = [ 'cash', 'bank', 'mobile_money' ];

	public static function maybe_handle_submit(): void {
		if ( isset( $_POST['tr_invoice_payment_nonce'] ) ) {
			self::handle_record_payment_submit();
			return;
		}

		if ( isset( $_POST['tr_invoice_waive_nonce'] ) ) {
			self::handle_waive_submit();
			return;
		}

		if ( isset( $_POST['tr_invoice_create_nonce'] ) ) {
			self::handle_create_invoice_submit();
		}
	}

	/**
	 * The one place invoices can be created for a period other than
	 * today — deliberate, admin-initiated backdating or a one-off charge,
	 * never something the daily generator does on its own.
	 */
	private static function handle_create_invoice_submit(): void {
		$family_id = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;

		check_admin_referer( 'tr_invoice_create_' . $family_id, 'tr_invoice_create_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$family = TR_Families::get( $family_id );
		if ( null === $family ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_FAMILIES ) );
			exit;
		}

		$errors = [];

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			$errors[] = __( 'Period must be in YYYY-MM format.', 'tangnest-robotics' );
		}

		$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : -1;
		if ( $amount <= 0 ) {
			$errors[] = __( 'Amount must be greater than zero.', 'tangnest-robotics' );
		}

		$due_date = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		$dt       = DateTime::createFromFormat( 'Y-m-d', $due_date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $due_date ) {
			$errors[] = __( 'Due date is not a valid date.', 'tangnest-robotics' );
		}

		if ( ! empty( $errors ) ) {
			set_transient( self::error_key(), $errors, MINUTE_IN_SECONDS );
			wp_safe_redirect( self::family_form_url( $family_id ) );
			exit;
		}

		$invoice_id = TR_Invoices::insert( [
			'family_id'        => $family_id,
			'period'           => $period,
			'amount'           => $amount,
			'currency'         => $family->currency ?: 'RWF',
			'status'           => 'pending',
			'due_date'         => $due_date,
			'issued_at'        => current_time( 'mysql' ),
			'student_snapshot' => TR_Invoice_Generator::build_student_snapshot( $family ),
		] );

		if ( $invoice_id <= 0 ) {
			wp_safe_redirect( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'tr_notice' => 'invoice_create_failed' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		TR_Logger::info( 'Invoice created manually', [ 'family_id' => $family_id, 'invoice_id' => $invoice_id, 'period' => $period ] );

		// Every invoice-creation path emails the parent — no opt-in, no
		// toggle. The other two paths (cron, Generate Invoices Now) both
		// run through TR_Invoice_Generator::run(), which already sends
		// this; this row action is the one path that inserts directly.
		TR_Notifications::send_invoice_issued_email( $family_id, $invoice_id );

		wp_safe_redirect( add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'tr_notice' => 'invoice_created' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function family_form_url( int $family_id ): string {
		return add_query_arg(
			[ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => 'create_invoice', 'id' => $family_id ],
			admin_url( 'admin.php' )
		);
	}

	public static function render_create_invoice_form( int $family_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$family = TR_Families::get( $family_id );
		if ( null === $family ) {
			wp_die( esc_html__( 'Family not found.', 'tangnest-robotics' ) );
		}

		$user = get_userdata( (int) $family->parent_user_id );

		$errors = get_transient( self::error_key() );
		if ( $errors ) {
			delete_transient( self::error_key() );
		}
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Create Invoice', 'tangnest-robotics' ); ?></h1>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error"><ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<p><?php echo esc_html( $user ? $user->display_name : __( '(no user)', 'tangnest-robotics' ) ); ?></p>
			<p class="description"><?php esc_html_e( 'For backdating a missed period, or a one-off charge. The daily job only ever bills today — use this for anything else.', 'tangnest-robotics' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'tr_invoice_create_' . $family_id, 'tr_invoice_create_nonce' ); ?>
				<input type="hidden" name="family_id" value="<?php echo esc_attr( $family_id ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tr-period"><?php esc_html_e( 'Period', 'tangnest-robotics' ); ?></label></th>
						<td><input type="month" id="tr-period" name="period" required value="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-amount"><?php esc_html_e( 'Amount (RWF)', 'tangnest-robotics' ); ?></label></th>
						<td><input type="number" id="tr-amount" name="amount" step="0.01" min="0.01" required value="<?php echo esc_attr( $family->monthly_amount ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-due-date"><?php esc_html_e( 'Due date', 'tangnest-robotics' ); ?></label></th>
						<td><input type="date" id="tr-due-date" name="due_date" required value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create Invoice', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_FAMILIES ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>
		</div>
		<?php
	}

	private static function handle_record_payment_submit(): void {
		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;

		check_admin_referer( 'tr_invoice_payment_' . $invoice_id, 'tr_invoice_payment_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$invoice = TR_Invoices::get( $invoice_id );
		if ( null === $invoice ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_INVOICES ) );
			exit;
		}

		$errors = [];

		$method = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( ! in_array( $method, self::PAYMENT_METHODS, true ) ) {
			$errors[] = __( 'Please choose a payment method.', 'tangnest-robotics' );
		}

		$reference = isset( $_POST['payment_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_reference'] ) ) : '';

		$paid_at       = current_time( 'mysql' );
		$paid_at_input = isset( $_POST['paid_at'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_at'] ) ) : '';
		if ( '' !== $paid_at_input ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $paid_at_input );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $paid_at_input ) {
				$errors[] = __( 'Date paid is not a valid date.', 'tangnest-robotics' );
			} else {
				$paid_at = $paid_at_input . ' ' . current_time( 'H:i:s' );
			}
		}

		if ( ! empty( $errors ) ) {
			set_transient( self::error_key(), $errors, MINUTE_IN_SECONDS );
			wp_safe_redirect( self::form_url( 'record_payment', $invoice_id ) );
			exit;
		}

		// Captured BEFORE mark_paid() runs — this is what stops a
		// double-submitted form (or a retried webhook later) from
		// incrementing months_paid a second time on an invoice that was
		// already paid. mark_paid() itself is a no-op in that case, but the
		// months_paid increment below only ever runs on the transition.
		$already_paid = 'paid' === $invoice->status;

		TR_Invoices::mark_paid( $invoice_id, [
			'payment_method'    => $method,
			'payment_reference' => $reference,
			'paid_at'           => $paid_at,
			'recorded_by'       => get_current_user_id(),
		] );

		if ( ! $already_paid ) {
			// Exactly once per family per payment (v0.8.0) — every child on
			// a package finishes together, so there is one figure to
			// advance, not one per enrollment.
			TR_Families::increment_months_paid( (int) $invoice->family_id );
		}

		self::redirect_with_notice( 'payment_recorded' );
	}

	private static function handle_waive_submit(): void {
		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;

		check_admin_referer( 'tr_invoice_waive_' . $invoice_id, 'tr_invoice_waive_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$reason = isset( $_POST['waive_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['waive_reason'] ) ) : '';

		if ( '' === trim( $reason ) ) {
			set_transient( self::error_key(), [ __( 'A reason is required to waive an invoice.', 'tangnest-robotics' ) ], MINUTE_IN_SECONDS );
			wp_safe_redirect( self::form_url( 'waive', $invoice_id ) );
			exit;
		}

		TR_Invoices::set_status( $invoice_id, 'waived', $reason );

		self::redirect_with_notice( 'invoice_waived' );
	}

	/**
	 * Cancel, and both "send reminder" channels — none of these need a
	 * form, so they're plain nonce-protected GET links handled here.
	 */
	public static function maybe_handle_row_actions(): void {
		if ( ! isset( $_GET['tr_row_action'], $_GET['id'], $_GET['page'] ) ) {
			return;
		}

		if ( TR_Admin_Menu::PAGE_INVOICES !== $_GET['page'] ) {
			return;
		}

		$row_action    = sanitize_key( wp_unslash( $_GET['tr_row_action'] ) );
		$invoice_id    = absint( $_GET['id'] );
		$valid_actions = [ 'cancel', 'send_reminder_email', 'send_reminder_whatsapp', 'delete' ];

		if ( ! in_array( $row_action, $valid_actions, true ) || $invoice_id <= 0 ) {
			return;
		}

		check_admin_referer( 'tr_invoice_row_action_' . $invoice_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$invoice = TR_Invoices::get( $invoice_id );

		if ( 'cancel' === $row_action ) {
			if ( null !== $invoice ) {
				TR_Invoices::set_status( $invoice_id, 'cancelled' );
			}
			self::redirect_with_notice( 'invoice_cancelled' );
		}

		if ( 'send_reminder_email' === $row_action ) {
			$sent = null !== $invoice && TR_Notifications::send_reminder_email( $invoice_id );
			if ( $sent ) {
				// Manual send — never touches reminder_stages_sent, so this
				// can't suppress a later scheduled reminder for the same
				// invoice. See TR_Invoices::record_manual_reminder().
				TR_Invoices::record_manual_reminder( $invoice_id );
			}
			self::redirect_with_notice( $sent ? 'reminder_sent' : 'reminder_failed' );
		}

		if ( 'send_reminder_whatsapp' === $row_action ) {
			$whatsapp_url = null !== $invoice ? self::build_reminder_whatsapp_url( $invoice ) : '';
			if ( '' === $whatsapp_url ) {
				self::redirect_with_notice( 'whatsapp_reminder_failed' );
			}
			TR_Invoices::record_manual_reminder( $invoice_id );
			// See TR_Admin_Menu::build_access_link_whatsapp_url() for why
			// this is a raw header() call, never wp_redirect()/esc_url().
			header( 'Location: ' . $whatsapp_url );
			exit;
		}

		if ( 'delete' === $row_action ) {
			// Re-checked here regardless of what the row action link was
			// rendered for — a tampered ID/status combination must never
			// delete a pending, overdue, paid or waived invoice.
			if ( null === $invoice || 'cancelled' !== $invoice->status ) {
				self::redirect_with_notice( 'invoice_delete_failed' );
			}

			self::log_and_delete_invoice( $invoice );

			self::redirect_with_notice( 'invoice_deleted' );
		}
	}

	private static function log_and_delete_invoice( object $invoice ): void {
		$admin = wp_get_current_user();

		TR_Logger::info( 'Invoice permanently deleted', [
			'invoice_id'   => $invoice->id,
			'family_id'    => $invoice->family_id,
			'period'       => $invoice->period,
			'amount'       => $invoice->amount,
			'deleted_by'   => $admin->ID,
			'deleted_by_user' => $admin->user_login,
		] );

		TR_Invoices::delete( (int) $invoice->id );
	}

	/**
	 * Reuses TR_Notifications::build_whatsapp_message_url() — the same
	 * phone-conversion and %0a-safe encoding the access-link WhatsApp send
	 * already relies on — with a reminder-specific message instead of an
	 * access link.
	 *
	 * Carries a message token (spec v0.7.0), not the primary device-bound
	 * access token — this is a routine, high-frequency automatic send, and
	 * using the primary slot would invalidate whatever link the admin's
	 * own "Send access link (WhatsApp)" action deliberately sent. That is
	 * the exact collision the message-token slot exists to remove.
	 */
	private static function build_reminder_whatsapp_url( object $invoice ): string {
		$family = TR_Families::get( (int) $invoice->family_id );
		if ( null === $family ) {
			return '';
		}

		$user = get_userdata( (int) $family->parent_user_id );
		if ( ! $user ) {
			return '';
		}

		$phone      = get_user_meta( (int) $family->parent_user_id, 'phone_number', true );
		$access_url = TR_Message_Tokens::generate_url( (int) $family->id );

		$lines = [
			sprintf( __( 'Hello %s,', 'tangnest-robotics' ), $user->display_name ),
			sprintf(
				/* translators: 1: amount and currency, 2: billing period */
				__( 'This is a reminder that %1$s is due for your Tangnest Robotics payment (%2$s).', 'tangnest-robotics' ),
				number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency,
				$invoice->period
			),
		];

		if ( '' !== $access_url ) {
			$lines[] = __( 'You can view your payment schedule here:', 'tangnest-robotics' );
			$lines[] = $access_url;
		}

		$url = TR_Notifications::build_whatsapp_message_url( $phone, $lines );

		if ( '' !== $url ) {
			TR_Logger::info( 'WhatsApp payment reminder sent', [ 'invoice_id' => $invoice->id, 'family_id' => $family->id ] );
		} else {
			TR_Logger::error( 'WhatsApp payment reminder not sent: invalid phone or no dashboard page configured', [ 'invoice_id' => $invoice->id, 'family_id' => $family->id ] );
		}

		return $url;
	}

	public static function maybe_handle_bulk_actions(): void {
		if ( ! isset( $_REQUEST['page'] ) || TR_Admin_Menu::PAGE_INVOICES !== $_REQUEST['page'] ) {
			return;
		}

		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}

		if ( ! in_array( $action, [ 'mark_overdue', 'export_csv', 'delete' ], true ) ) {
			return;
		}

		check_admin_referer( 'bulk-invoices' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$ids = isset( $_REQUEST['invoice_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['invoice_ids'] ) ) ) : [];

		if ( empty( $ids ) ) {
			self::redirect_with_notice( 'no_invoices_selected' );
		}

		if ( 'mark_overdue' === $action ) {
			foreach ( $ids as $id ) {
				$invoice = TR_Invoices::get( $id );
				if ( $invoice && 'pending' === $invoice->status ) {
					TR_Invoices::set_status( $id, 'overdue' );
				}
			}
			self::redirect_with_notice( 'marked_overdue' );
		}

		if ( 'export_csv' === $action ) {
			self::export_csv( $ids );
		}

		if ( 'delete' === $action ) {
			self::bulk_delete( $ids );
		}
	}

	/**
	 * Any selected invoice that isn't cancelled is silently skipped rather
	 * than aborting the whole batch — a paid or pending invoice must never
	 * be deletable no matter what else was checked alongside it.
	 */
	private static function bulk_delete( array $ids ): void {
		$deleted = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			$invoice = TR_Invoices::get( $id );

			if ( null === $invoice || 'cancelled' !== $invoice->status ) {
				$skipped++;
				continue;
			}

			self::log_and_delete_invoice( $invoice );
			$deleted++;
		}

		wp_safe_redirect( add_query_arg( [
			'page'      => TR_Admin_Menu::PAGE_INVOICES,
			'tr_notice' => 'invoices_bulk_deleted',
			'deleted'   => $deleted,
			'skipped'   => $skipped,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function export_csv( array $ids ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=tangnest-invoices-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, [ 'ID', 'Family', 'Period', 'Amount', 'Currency', 'Status', 'Due Date', 'Paid Date', 'Method', 'Reference' ] );

		foreach ( $ids as $id ) {
			$invoice = TR_Invoices::get( $id );
			if ( null === $invoice ) {
				continue;
			}

			$family = TR_Families::get( (int) $invoice->family_id );
			$user   = $family ? get_userdata( (int) $family->parent_user_id ) : null;

			fputcsv( $output, [
				$invoice->id,
				$user ? $user->display_name : '',
				$invoice->period,
				$invoice->amount,
				$invoice->currency,
				$invoice->status,
				$invoice->due_date,
				$invoice->paid_at,
				$invoice->payment_method,
				$invoice->payment_reference,
			] );
		}

		fclose( $output );
		exit;
	}

	private static function error_key(): string {
		return 'tr_invoice_form_errors_' . get_current_user_id();
	}

	private static function form_url( string $action, int $invoice_id ): string {
		return add_query_arg(
			[ 'page' => TR_Admin_Menu::PAGE_INVOICES, 'action' => $action, 'id' => $invoice_id ],
			admin_url( 'admin.php' )
		);
	}

	private static function redirect_with_notice( string $notice ): void {
		wp_safe_redirect( add_query_arg( [
			'page'      => TR_Admin_Menu::PAGE_INVOICES,
			'tr_notice' => $notice,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_record_payment_form( int $invoice_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$invoice = TR_Invoices::get( $invoice_id );
		if ( null === $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'tangnest-robotics' ) );
		}

		$errors = get_transient( self::error_key() );
		if ( $errors ) {
			delete_transient( self::error_key() );
		}

		$family = TR_Families::get( (int) $invoice->family_id );
		$user   = $family ? get_userdata( (int) $family->parent_user_id ) : null;
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Record Payment', 'tangnest-robotics' ); ?></h1>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error"><ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: parent name, 2: billing period, 3: amount */
					esc_html__( '%1$s — period %2$s — %3$s', 'tangnest-robotics' ),
					esc_html( $user ? $user->display_name : __( '(no user)', 'tangnest-robotics' ) ),
					esc_html( $invoice->period ),
					esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency )
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'tr_invoice_payment_' . $invoice_id, 'tr_invoice_payment_nonce' ); ?>
				<input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tr-method"><?php esc_html_e( 'Method', 'tangnest-robotics' ); ?></label></th>
						<td>
							<select id="tr-method" name="payment_method" required>
								<option value=""><?php esc_html_e( '— Select —', 'tangnest-robotics' ); ?></option>
								<option value="cash"><?php esc_html_e( 'Cash', 'tangnest-robotics' ); ?></option>
								<option value="bank"><?php esc_html_e( 'Bank', 'tangnest-robotics' ); ?></option>
								<option value="mobile_money"><?php esc_html_e( 'Mobile Money', 'tangnest-robotics' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="tr-reference"><?php esc_html_e( 'Reference (optional)', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-reference" name="payment_reference" class="regular-text" placeholder="<?php esc_attr_e( 'Receipt number, transaction ID…', 'tangnest-robotics' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-paid-at"><?php esc_html_e( 'Date paid', 'tangnest-robotics' ); ?></label></th>
						<td><input type="date" id="tr-paid-at" name="paid_at" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Record Payment', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_INVOICES ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>
		</div>
		<?php
	}

	public static function render_waive_form( int $invoice_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$invoice = TR_Invoices::get( $invoice_id );
		if ( null === $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'tangnest-robotics' ) );
		}

		$errors = get_transient( self::error_key() );
		if ( $errors ) {
			delete_transient( self::error_key() );
		}

		$family = TR_Families::get( (int) $invoice->family_id );
		$user   = $family ? get_userdata( (int) $family->parent_user_id ) : null;
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Waive Invoice', 'tangnest-robotics' ); ?></h1>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error"><ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: parent name, 2: billing period, 3: amount */
					esc_html__( '%1$s — period %2$s — %3$s', 'tangnest-robotics' ),
					esc_html( $user ? $user->display_name : __( '(no user)', 'tangnest-robotics' ) ),
					esc_html( $invoice->period ),
					esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency )
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Waiving does not count as a payment — the child\'s progress will not advance.', 'tangnest-robotics' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'tr_invoice_waive_' . $invoice_id, 'tr_invoice_waive_nonce' ); ?>
				<input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tr-waive-reason"><?php esc_html_e( 'Reason (required)', 'tangnest-robotics' ); ?></label></th>
						<td><textarea id="tr-waive-reason" name="waive_reason" rows="3" class="large-text" required></textarea></td>
					</tr>
				</table>
				<?php submit_button( __( 'Waive Invoice', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_INVOICES ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>
		</div>
		<?php
	}
}
