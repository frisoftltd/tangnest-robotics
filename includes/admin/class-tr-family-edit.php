<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Family add/edit form: pick-or-create parent WP user, phone/meta, the
 * package selection, the billing-day anchor (read-only once set unless
 * the admin explicitly checks "override anchor"), and an inline
 * repeatable Children section so several siblings can be added in one
 * save instead of one trip through the standalone Add Student screen
 * per child.
 *
 * v0.10.0: monthly_amount is no longer a straight snapshot of the
 * package's own fee — it's TR_Families::price_for_child_count() (or a
 * negotiated override) resolved for this family's prospective active
 * child count (existing active children plus whatever's being added in
 * this very submit), computed and validated BEFORE anything is written.
 * A missing price or an unresolved stale negotiated rate blocks the
 * whole save with an inline error, the same errors[] + redisplay
 * pattern every other validation on this form already uses — nothing
 * is written until the admin provides a valid answer.
 */
class TR_Family_Edit {
	const NONCE = 'tr_family_save';

	private static function state_key(): string {
		return 'tr_family_form_state_' . get_current_user_id();
	}

	public static function maybe_handle_submit(): void {
		if ( ! isset( $_POST['tr_family_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'tr_family_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$errors    = [];
		$family_id = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
		$mode      = isset( $_POST['parent_mode'] ) && 'new' === $_POST['parent_mode'] ? 'new' : 'existing';

		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
			$errors[] = __( 'Phone must be in the format 07XXXXXXXX.', 'tangnest-robotics' );
		}

		$package_id = isset( $_POST['package_id'] ) ? absint( $_POST['package_id'] ) : 0;
		$package    = $package_id > 0 ? TR_Programs::get( $package_id ) : null;
		if ( null === $package ) {
			$errors[] = __( 'Please select a package.', 'tangnest-robotics' );
		}

		$billing_day_input = isset( $_POST['billing_day'] ) ? absint( $_POST['billing_day'] ) : 0;
		if ( $billing_day_input > 28 ) {
			$errors[] = __( 'Billing day must be between 1 and 28.', 'tangnest-robotics' );
		}
		$override_anchor = ! empty( $_POST['override_anchor'] );

		$program_start_input = isset( $_POST['program_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['program_start_date'] ) ) : '';
		$program_end_input   = isset( $_POST['program_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['program_end_date'] ) ) : '';

		$program_start_date = '';
		if ( '' !== $program_start_input ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $program_start_input );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $program_start_input ) {
				$errors[] = __( 'Programme start date is not a valid date.', 'tangnest-robotics' );
			} else {
				$program_start_date = $program_start_input;
			}
		}

		$program_end_date = '';
		if ( '' !== $program_end_input ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $program_end_input );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $program_end_input ) {
				$errors[] = __( 'Programme end date is not a valid date.', 'tangnest-robotics' );
			} else {
				$program_end_date = $program_end_input;
			}
		}

		if ( '' !== $program_start_date && '' !== $program_end_date && $program_start_date > $program_end_date ) {
			$errors[] = __( 'Programme start date must be on or before the programme end date.', 'tangnest-robotics' );
		}

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Families::STATUSES, true ) ? $_POST['status'] : 'active';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		[ $valid_children, $child_errors ] = self::parse_children_rows( $_POST['new_children'] ?? [] );
		$errors = array_merge( $errors, $child_errors );

		$existing_family = $family_id > 0 ? TR_Families::get( $family_id ) : null;

		// v0.10.0: existing active children plus whatever's being added in
		// this very submit (inserted as 'active' further down) — the count
		// every price lookup below is driven by.
		$existing_active_count = $existing_family ? TR_Students::count( [ 'family_id' => $family_id, 'status' => 'active' ] ) : 0;
		$prospective_count     = $existing_active_count + count( $valid_children );

		// Negotiated rate (Task 3). negotiated_for_child_count is never an
		// admin-entered field — it is always the count at the moment the
		// amount is actually saved, assigned here, not posted.
		$negotiated_checked      = ! empty( $_POST['negotiated_enabled'] );
		$negotiated_amount_input = isset( $_POST['negotiated_amount'] ) ? trim( wp_unslash( $_POST['negotiated_amount'] ) ) : '';
		$negotiated_note_input   = isset( $_POST['negotiated_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['negotiated_note'] ) ) : '';

		$existing_negotiated_amount    = $existing_family && null !== $existing_family->negotiated_amount ? (float) $existing_family->negotiated_amount : null;
		$existing_negotiated_for_count = $existing_family && null !== $existing_family->negotiated_for_child_count ? (int) $existing_family->negotiated_for_child_count : null;

		$negotiated_amount_final    = null;
		$negotiated_for_count_final = null;
		$negotiated_note_final      = '';
		$pricing_blocked            = false;

		if ( $negotiated_checked ) {
			if ( '' === $negotiated_amount_input || ! is_numeric( $negotiated_amount_input ) || (float) $negotiated_amount_input <= 0 ) {
				$errors[]        = __( 'Enter a valid negotiated amount, or uncheck the negotiated rate box to use the standard price.', 'tangnest-robotics' );
				$pricing_blocked = true;
			} else {
				$negotiated_amount_posted = (float) $negotiated_amount_input;

				// Already stale (agreed for a count that no longer matches)
				// AND nothing about the figure actually changed in this
				// submit — simply resaving the form must never silently
				// "fix" a stale rate just because the count moved while
				// this exact amount happened to still be sitting in the
				// field.
				$unresolved_stale = null !== $existing_negotiated_amount
					&& null !== $existing_negotiated_for_count
					&& $existing_negotiated_for_count !== $prospective_count
					&& abs( $existing_negotiated_amount - $negotiated_amount_posted ) < 0.005;

				if ( $unresolved_stale ) {
					$errors[]        = TR_Families::pricing_issue_message( [
						'type'                       => 'stale_negotiated',
						'child_count'                => $prospective_count,
						'negotiated_amount'          => $existing_negotiated_amount,
						'negotiated_for_child_count' => $existing_negotiated_for_count,
						'standard_price'             => null !== $package ? TR_Families::price_for_child_count( $package_id, $prospective_count ) : null,
					] );
					$pricing_blocked = true;
				} else {
					$negotiated_amount_final    = $negotiated_amount_posted;
					$negotiated_for_count_final = $prospective_count;
					$negotiated_note_final      = $negotiated_note_input;
				}
			}
		}

		// The amount this save would actually write — resolved and
		// validated BEFORE anything is written. A missing table price
		// blocks the whole save exactly like every other error on this
		// form; it never falls back to zero or to the previous amount.
		$monthly_amount = null;
		if ( null !== $package && ! $pricing_blocked ) {
			if ( null !== $negotiated_amount_final ) {
				$monthly_amount = $negotiated_amount_final;
			} else {
				$monthly_amount = TR_Families::price_for_child_count( $package_id, $prospective_count );
				if ( null === $monthly_amount ) {
					$errors[] = TR_Families::pricing_issue_message( [ 'type' => 'missing_price', 'child_count' => $prospective_count ] );
				}
			}
		}

		$user_id  = 0;
		$new_email = $new_first = $new_last = '';
		$existing_user = null;
		$existing_email = $existing_first = $existing_last = '';
		$email_changed = false;

		if ( 'new' === $mode ) {
			$new_email = isset( $_POST['new_email'] ) ? sanitize_email( wp_unslash( $_POST['new_email'] ) ) : '';
			$new_first = isset( $_POST['new_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_first_name'] ) ) : '';
			$new_last  = isset( $_POST['new_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_last_name'] ) ) : '';

			if ( ! is_email( $new_email ) ) {
				$errors[] = __( 'Please enter a valid email for the new parent.', 'tangnest-robotics' );
			} elseif ( email_exists( $new_email ) ) {
				$errors[] = __( 'A WordPress user with that email already exists — pick them from the existing-user list instead.', 'tangnest-robotics' );
			}

			if ( '' === $new_first || '' === $new_last ) {
				$errors[] = __( 'First and last name are required for a new parent.', 'tangnest-robotics' );
			}
		} else {
			$user_id       = isset( $_POST['existing_user_id'] ) ? absint( $_POST['existing_user_id'] ) : 0;
			$existing_user = $user_id > 0 ? get_userdata( $user_id ) : null;

			if ( null === $existing_user ) {
				$errors[] = __( 'Please select an existing parent user.', 'tangnest-robotics' );
			} else {
				$existing_email = isset( $_POST['existing_email'] ) ? sanitize_email( wp_unslash( $_POST['existing_email'] ) ) : '';
				$existing_first = isset( $_POST['existing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_first_name'] ) ) : '';
				$existing_last  = isset( $_POST['existing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_last_name'] ) ) : '';

				if ( ! is_email( $existing_email ) ) {
					$errors[] = __( 'Please enter a valid email for the parent.', 'tangnest-robotics' );
				} else {
					$email_owner = email_exists( $existing_email );
					if ( $email_owner && (int) $email_owner !== $user_id ) {
						$errors[] = __( 'That email address already belongs to a different WordPress user.', 'tangnest-robotics' );
					}
				}

				if ( '' === $existing_first || '' === $existing_last ) {
					$errors[] = __( 'First and last name are required.', 'tangnest-robotics' );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			set_transient( self::state_key(), [ 'errors' => $errors, 'values' => wp_unslash( $_POST ) ], MINUTE_IN_SECONDS );
			wp_safe_redirect( self::edit_url( $family_id ) );
			exit;
		}

		if ( 'new' === $mode ) {
			$user_id = wp_insert_user( [
				'user_login'   => self::generate_username( $new_email ),
				'user_email'   => $new_email,
				'user_pass'    => wp_generate_password( 20, true, true ),
				'first_name'   => $new_first,
				'last_name'    => $new_last,
				'display_name' => trim( $new_first . ' ' . $new_last ),
				'role'         => 'subscriber',
			] );

			if ( is_wp_error( $user_id ) ) {
				set_transient( self::state_key(), [ 'errors' => [ $user_id->get_error_message() ], 'values' => wp_unslash( $_POST ) ], MINUTE_IN_SECONDS );
				wp_safe_redirect( self::edit_url( $family_id ) );
				exit;
			}
		} else {
			// Never touches user_pass, user_login or role — this only ever
			// updates identity fields the plugin actually shows the admin.
			$email_changed = strtolower( $existing_user->user_email ) !== strtolower( $existing_email );

			$update_result = wp_update_user( [
				'ID'           => $user_id,
				'user_email'   => $existing_email,
				'first_name'   => $existing_first,
				'last_name'    => $existing_last,
				'display_name' => trim( $existing_first . ' ' . $existing_last ),
			] );

			if ( is_wp_error( $update_result ) ) {
				set_transient( self::state_key(), [ 'errors' => [ $update_result->get_error_message() ], 'values' => wp_unslash( $_POST ) ], MINUTE_IN_SECONDS );
				wp_safe_redirect( self::edit_url( $family_id ) );
				exit;
			}
		}

		update_user_meta( $user_id, 'phone_number', $phone );
		$user = get_userdata( $user_id );
		update_user_meta( $user_id, 'parent_name', $user->display_name );
		update_user_meta( $user_id, 'parent_email', $user->user_email );

		$data = [
			// Resolved and validated above, before any write happened —
			// never recalculated automatically later, so a subsequent
			// package price change never retroactively alters this
			// family's billing (v0.8.0 rule, still holds under v0.10.0's
			// per-child-count table).
			'monthly_amount'             => $monthly_amount,
			'package_id'                 => $package_id,
			'months_paid'                => $existing_family ? (int) $existing_family->months_paid : 0,
			'parent_user_id'             => $user_id,
			'currency'                   => 'RWF',
			'program_start_date'         => $program_start_date,
			'program_end_date'           => $program_end_date,
			'negotiated_amount'          => $negotiated_amount_final,
			'negotiated_for_child_count' => $negotiated_for_count_final,
			'negotiated_note'            => $negotiated_note_final,
			'status'                     => $status,
			'notes'                      => $notes,
		];

		if ( $family_id > 0 ) {
			$billing_day = $existing_family ? (int) $existing_family->billing_day : 0;
			if ( 0 === $billing_day || $override_anchor ) {
				$billing_day = $billing_day_input;
			}
			$data['billing_day'] = $billing_day;
			TR_Families::update( $family_id, $data );
		} else {
			$data['billing_day'] = $billing_day_input;
			$family_id            = TR_Families::insert( $data );
		}

		if ( $email_changed ) {
			// Info, not debug — this affects where invoices, receipts and
			// access links get sent from now on, worth keeping regardless
			// of the debug-logging toggle.
			TR_Logger::info( 'Parent email changed', [
				'family_id' => $family_id,
				'old_email' => $existing_user->user_email,
				'new_email' => $existing_email,
			] );
		}

		// v0.8.1: the add-child row no longer asks for an enrollment date —
		// the current date stands in for it wherever the anchor logic needs
		// one, so a family's billing day still gets set from the day the
		// first child is actually added.
		$today_for_anchor = current_time( 'Y-m-d' );
		$admin_id         = get_current_user_id();

		foreach ( $valid_children as $child ) {
			TR_Students::insert( [
				'family_id'  => $family_id,
				'first_name' => $child['first_name'],
				'last_name'  => $child['last_name'],
				'status'     => 'active',
			] );

			// Idempotent — only the first child ever actually moves this
			// from 0, regardless of how many rows are processed here.
			TR_Families::set_billing_anchor( $family_id, $today_for_anchor );

			TR_Family_Events::record(
				$family_id,
				'child_added',
				sprintf(
					/* translators: %s: child's full name */
					__( '%s added', 'tangnest-robotics' ),
					trim( $child['first_name'] . ' ' . $child['last_name'] )
				),
				null,
				null,
				$admin_id
			);
		}

		// Change events — only for an actual edit; a brand-new family has
		// no "before" state worth describing as a change.
		if ( $existing_family ) {
			self::log_edit_events( $existing_family, $family_id, [
				'package_id'                 => $package_id,
				'monthly_amount'             => $monthly_amount,
				'program_start_date'         => $program_start_date,
				'program_end_date'           => $program_end_date,
				'negotiated_amount'          => $negotiated_amount_final,
				'negotiated_for_child_count' => $negotiated_for_count_final,
			], $admin_id );
		}

		TR_Families::clear_composition_flag( $family_id );

		if ( 'new' === $mode ) {
			TR_Notifications::maybe_send_welcome_email( $user_id, $family_id );
		}

		// v0.9.3: a package change can leave the current month's already-
		// issued invoice priced for the old package. Never re-priced
		// automatically — stash what's on offer and let render() present it
		// as an explicit, dismissible choice on the next load. Stays
		// exactly as built — package-switch-triggered only, not extended
		// to every amount change under v0.10.0's child-count pricing; a
		// mid-period child-count or negotiated-rate change intentionally
		// waits for the next billing cycle (see spec).
		if ( $existing_family && (int) $existing_family->package_id !== $package_id ) {
			self::maybe_stash_invoice_offer( $family_id, $monthly_amount );
		}

		wp_safe_redirect( add_query_arg( [
			'page'    => TR_Admin_Menu::PAGE_FAMILIES,
			'action'  => 'edit',
			'id'      => $family_id,
			'updated' => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function invoice_offer_key(): string {
		return 'tr_invoice_offer_' . get_current_user_id();
	}

	/**
	 * Only the CURRENT period's invoice is ever considered — a past
	 * period's invoice is never looked up here, so it can never be touched
	 * by this flow. A paid/waived/cancelled current invoice is reported as
	 * "unchanged" rather than silently skipped, so the admin sees that
	 * nothing happened and why.
	 */
	private static function maybe_stash_invoice_offer( int $family_id, float $new_amount ): void {
		$current_period  = current_datetime()->format( 'Y-m' );
		$current_invoice = TR_Invoices::get_for_period( $family_id, $current_period );

		if ( null === $current_invoice ) {
			return;
		}

		if ( in_array( $current_invoice->status, [ 'pending', 'overdue' ], true ) ) {
			set_transient( self::invoice_offer_key(), [
				'invoice_id' => (int) $current_invoice->id,
				'old_amount' => (float) $current_invoice->amount,
				'new_amount' => $new_amount,
			], MINUTE_IN_SECONDS );
			return;
		}

		set_transient( self::invoice_offer_key(), [ 'unchanged' => true ], MINUTE_IN_SECONDS );
	}

	/**
	 * Writes a family event for each thing that actually changed in this
	 * save — package, amount, negotiated rate, programme dates. Diffs
	 * against $old (the family row as it was immediately before this
	 * save) rather than re-deriving "what changed" from request data, so
	 * this can never disagree with what was actually written.
	 */
	private static function log_edit_events( object $old, int $family_id, array $new, int $admin_id ): void {
		if ( (int) ( $old->package_id ?? 0 ) !== (int) $new['package_id'] ) {
			$old_package = ! empty( $old->package_id ) ? TR_Programs::get( (int) $old->package_id ) : null;
			$new_package = TR_Programs::get( (int) $new['package_id'] );

			TR_Family_Events::record(
				$family_id,
				'package_changed',
				sprintf(
					/* translators: 1: old package name, 2: new package name */
					__( 'Package changed from "%1$s" to "%2$s"', 'tangnest-robotics' ),
					$old_package->name ?? __( '(none)', 'tangnest-robotics' ),
					$new_package->name ?? ''
				),
				$old_package->name ?? '',
				$new_package->name ?? '',
				$admin_id
			);
		}

		if ( abs( (float) $old->monthly_amount - (float) $new['monthly_amount'] ) >= 0.005 ) {
			TR_Family_Events::record(
				$family_id,
				'amount_changed',
				sprintf(
					/* translators: 1: old amount, 2: new amount */
					__( 'Monthly amount changed from %1$s to %2$s RWF', 'tangnest-robotics' ),
					number_format( (float) $old->monthly_amount, 0 ),
					number_format( (float) $new['monthly_amount'], 0 )
				),
				number_format( (float) $old->monthly_amount, 2, '.', '' ),
				number_format( (float) $new['monthly_amount'], 2, '.', '' ),
				$admin_id
			);
		}

		$old_start = TR_Families::is_date_set( $old->program_start_date ) ? $old->program_start_date : '';
		$old_end   = TR_Families::is_date_set( $old->program_end_date ) ? $old->program_end_date : '';
		$new_start = $new['program_start_date'] ?: '';
		$new_end   = $new['program_end_date'] ?: '';

		if ( $old_start !== $new_start || $old_end !== $new_end ) {
			TR_Family_Events::record(
				$family_id,
				'programme_dates_changed',
				sprintf(
					/* translators: 1: new start date, 2: new end date */
					__( 'Programme dates changed to %1$s – %2$s', 'tangnest-robotics' ),
					'' !== $new_start ? $new_start : '—',
					'' !== $new_end ? $new_end : '—'
				),
				trim( $old_start . ' – ' . $old_end, ' –' ),
				trim( $new_start . ' – ' . $new_end, ' –' ),
				$admin_id
			);
		}

		$old_negotiated = null !== $old->negotiated_amount ? (float) $old->negotiated_amount : null;
		$new_negotiated = $new['negotiated_amount'];

		if ( null === $old_negotiated && null !== $new_negotiated ) {
			TR_Family_Events::record(
				$family_id,
				'negotiated_set',
				sprintf(
					/* translators: 1: negotiated amount, 2: child count */
					__( 'Negotiated rate set to %1$s RWF (%2$d children)', 'tangnest-robotics' ),
					number_format( $new_negotiated, 0 ),
					$new['negotiated_for_child_count']
				),
				null,
				number_format( $new_negotiated, 2, '.', '' ),
				$admin_id
			);
		} elseif ( null !== $old_negotiated && null === $new_negotiated ) {
			TR_Family_Events::record(
				$family_id,
				'negotiated_cleared',
				sprintf(
					/* translators: %s: the cleared negotiated amount */
					__( 'Negotiated rate cleared (was %s RWF)', 'tangnest-robotics' ),
					number_format( $old_negotiated, 0 )
				),
				number_format( $old_negotiated, 2, '.', '' ),
				null,
				$admin_id
			);
		} elseif ( null !== $old_negotiated && null !== $new_negotiated && abs( $old_negotiated - $new_negotiated ) >= 0.005 ) {
			TR_Family_Events::record(
				$family_id,
				'negotiated_changed',
				sprintf(
					/* translators: 1: old negotiated amount, 2: new negotiated amount */
					__( 'Negotiated rate changed from %1$s to %2$s RWF', 'tangnest-robotics' ),
					number_format( $old_negotiated, 0 ),
					number_format( $new_negotiated, 0 )
				),
				number_format( $old_negotiated, 2, '.', '' ),
				number_format( $new_negotiated, 2, '.', '' ),
				$admin_id
			);
		}
	}

	/**
	 * Handles the "Update invoice to new amount" link offered on the family
	 * edit screen after a package change (v0.9.3). Re-checks the invoice is
	 * still pending/overdue at the time this fires, in case it was paid in
	 * the meantime — never re-prices a paid invoice.
	 */
	public static function maybe_handle_invoice_update_action(): void {
		if ( ! isset( $_GET['tr_update_invoice'], $_GET['page'], $_GET['id'] ) || TR_Admin_Menu::PAGE_FAMILIES !== $_GET['page'] ) {
			return;
		}

		$invoice_id = absint( $_GET['tr_update_invoice'] );
		$family_id  = absint( $_GET['id'] );
		if ( $invoice_id <= 0 ) {
			return;
		}

		check_admin_referer( 'tr_update_invoice_' . $invoice_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$invoice = TR_Invoices::get( $invoice_id );
		$family  = TR_Families::get( $family_id );

		if ( null === $invoice || null === $family || ! in_array( $invoice->status, [ 'pending', 'overdue' ], true ) ) {
			wp_safe_redirect( self::edit_url( $family_id ) );
			exit;
		}

		$old_amount = (float) $invoice->amount;
		$new_amount = (float) $family->monthly_amount;

		TR_Invoices::update_amount_and_reset_irembopay( $invoice_id, $new_amount );

		TR_Logger::info( 'Invoice amount updated after package change', [
			'invoice_id' => $invoice_id,
			'family_id'  => $family_id,
			'old_amount' => $old_amount,
			'new_amount' => $new_amount,
			'changed_by' => wp_get_current_user()->user_login,
		] );

		wp_safe_redirect( add_query_arg( [
			'page'      => TR_Admin_Menu::PAGE_FAMILIES,
			'action'    => 'edit',
			'id'        => $family_id,
			'tr_notice' => 'invoice_updated',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A fully blank row is just an unused template row appended by the "Add
	 * child" button and never filled in — silently skipped, not an error.
	 * Any row with at least a name in it is validated fully. v0.8.1: a
	 * child is a name attached to the family — no date of birth, school or
	 * enrollment date collected here any more (the billing anchor, which
	 * used to read the row's own enrollment date, now just uses today —
	 * see the caller in maybe_handle_submit()).
	 */
	private static function parse_children_rows( $raw_rows ): array {
		$valid_children = [];
		$errors         = [];

		if ( ! is_array( $raw_rows ) ) {
			return [ $valid_children, $errors ];
		}

		foreach ( wp_unslash( $raw_rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$first = isset( $row['first_name'] ) ? sanitize_text_field( $row['first_name'] ) : '';
			$last  = isset( $row['last_name'] ) ? sanitize_text_field( $row['last_name'] ) : '';

			if ( '' === $first && '' === $last ) {
				continue;
			}

			$who = trim( $first . ' ' . $last ) ?: __( 'A new child', 'tangnest-robotics' );

			if ( '' === $first || '' === $last ) {
				$errors[] = sprintf(
					/* translators: %s: whichever name was entered for this row */
					__( '%s: first and last name are both required.', 'tangnest-robotics' ),
					$who
				);
				continue;
			}

			$valid_children[] = [
				'first_name' => $first,
				'last_name'  => $last,
			];
		}

		return [ $valid_children, $errors ];
	}

	private static function generate_username( string $email ): string {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) {
			$base = 'parent';
		}

		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			$i++;
		}

		return $username;
	}

	private static function edit_url( int $family_id ): string {
		$args = [ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => $family_id > 0 ? 'edit' : 'add' ];
		if ( $family_id > 0 ) {
			$args['id'] = $family_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$family_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$family    = $family_id > 0 ? TR_Families::get( $family_id ) : null;

		$state = get_transient( self::state_key() );
		if ( $state ) {
			delete_transient( self::state_key() );
		}
		$errors = $state['errors'] ?? [];
		$posted = $state['values'] ?? [];

		$invoice_offer = get_transient( self::invoice_offer_key() );
		if ( $invoice_offer ) {
			delete_transient( self::invoice_offer_key() );
		}

		$parent_user = $family ? get_userdata( (int) $family->parent_user_id ) : null;
		$phone       = $posted['phone'] ?? ( $parent_user ? get_user_meta( $parent_user->ID, 'phone_number', true ) : '' );
		$parent_mode = $posted['parent_mode'] ?? 'existing';

		$user_search = isset( $_GET['user_search'] ) ? sanitize_text_field( wp_unslash( $_GET['user_search'] ) ) : '';
		$selected_user_id = isset( $posted['existing_user_id'] ) ? absint( $posted['existing_user_id'] ) : ( $parent_user ? $parent_user->ID : 0 );

		// Prefilled from whichever existing user is currently selected —
		// posted values win on a validation-error redisplay so the admin's
		// edits aren't lost.
		$selected_existing_user = $selected_user_id > 0 ? get_userdata( $selected_user_id ) : null;
		$existing_parent_email  = $posted['existing_email'] ?? ( $selected_existing_user ? $selected_existing_user->user_email : '' );
		$existing_parent_first  = $posted['existing_first_name'] ?? ( $selected_existing_user ? $selected_existing_user->first_name : '' );
		$existing_parent_last   = $posted['existing_last_name'] ?? ( $selected_existing_user ? $selected_existing_user->last_name : '' );

		$active_packages = TR_Programs::get_list( [ 'status' => 'active', 'per_page' => 200 ] );

		// A family whose package has since been archived must still show
		// it selected, not silently fall back to "— Select —".
		$selected_package_id = isset( $posted['package_id'] ) ? absint( $posted['package_id'] ) : (int) ( $family->package_id ?? 0 );
		$selected_package    = $selected_package_id > 0 ? TR_Programs::get( $selected_package_id ) : null;

		$dropdown_packages = $active_packages;
		if ( $selected_package && 'active' !== $selected_package->status ) {
			$dropdown_packages[] = $selected_package;
		}

		// v0.10.0: the full price table per package, for the live price
		// line's JS — a package no longer means one fixed fee or one child
		// count (v0.9.3's package-mismatch notice and pre-select are gone
		// along with that assumption).
		$package_details = [];
		foreach ( $dropdown_packages as $package ) {
			$package_details[ (int) $package->id ] = [
				'duration' => TR_Programs::has_duration( $package ) ? (int) $package->duration_months : 0,
				'code'     => $package->irembopay_product_code ?? '',
				'price1'   => null !== $package->price_1_child ? (float) $package->price_1_child : null,
				'price2'   => null !== $package->price_2_children ? (float) $package->price_2_children : null,
				'price3'   => null !== $package->price_3_children ? (float) $package->price_3_children : null,
				'priceAdd' => null !== $package->price_additional_child ? (float) $package->price_additional_child : null,
			];
		}

		$active_child_count = $family ? TR_Students::count( [ 'family_id' => (int) $family->id, 'status' => 'active' ] ) : 0;
		$next_billing_date  = $family ? TR_Families::next_billing_date( (int) $family->id ) : null;

		$negotiated_enabled_now = isset( $posted['negotiated_enabled'] )
			? ! empty( $posted['negotiated_enabled'] )
			: ( $family && null !== $family->negotiated_amount );
		$negotiated_amount_now  = $posted['negotiated_amount'] ?? ( $family && null !== $family->negotiated_amount ? (string) (float) $family->negotiated_amount : '' );
		$negotiated_note_now    = $posted['negotiated_note'] ?? ( $family->negotiated_note ?? '' );
		$negotiated_for_count_saved = $family && null !== $family->negotiated_for_child_count ? (int) $family->negotiated_for_child_count : null;

		$pricing_issue = $family ? TR_Families::pricing_issue( $family ) : null;

		// v0.10.0 Task 5: a child added to a family whose programme has
		// already started joins it and finishes with their siblings —
		// children never carry their own dates (v0.8.1 removed those).
		$programme_has_started = $family && TR_Families::is_date_set( $family->program_start_date ) && $family->program_start_date <= current_time( 'Y-m-d' );
		$programme_end_label   = $family && TR_Families::is_date_set( $family->program_end_date )
			? date_i18n( 'j M Y', strtotime( $family->program_end_date ) )
			: '';

		$posted_children = isset( $posted['new_children'] ) && is_array( $posted['new_children'] ) ? array_values( $posted['new_children'] ) : [];
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php echo $family ? esc_html__( 'Edit Family', 'tangnest-robotics' ) : esc_html__( 'Add Family', 'tangnest-robotics' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Family saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $invoice_offer && empty( $invoice_offer['unchanged'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: 1: current amount, 2: new amount */
							esc_html__( 'The package changed. This month\'s invoice is currently %1$s RWF — update it to %2$s RWF?', 'tangnest-robotics' ),
							esc_html( number_format( $invoice_offer['old_amount'], 2 ) ),
							esc_html( number_format( $invoice_offer['new_amount'], 2 ) )
						);
						?>
						<a href="<?php echo esc_url( wp_nonce_url(
							add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'action' => 'edit', 'id' => $family_id, 'tr_update_invoice' => $invoice_offer['invoice_id'] ], admin_url( 'admin.php' ) ),
							'tr_update_invoice_' . $invoice_offer['invoice_id']
						) ); ?>"><?php esc_html_e( 'Update invoice to new amount', 'tangnest-robotics' ); ?></a>
					</p>
				</div>
			<?php elseif ( $invoice_offer && ! empty( $invoice_offer['unchanged'] ) ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'The paid invoice for this month is unchanged.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error">
					<ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul>
				</div>
			<?php endif; ?>

			<?php if ( $pricing_issue && empty( $errors ) ) : ?>
				<div class="notice notice-warning">
					<p><?php echo esc_html( TR_Families::pricing_issue_message( $pricing_issue ) ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get" class="tr-inline-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( TR_Admin_Menu::PAGE_FAMILIES ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $family ? 'edit' : 'add' ); ?>">
				<?php if ( $family ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $family->id ); ?>"><?php endif; ?>
				<label for="tr-user-search"><?php esc_html_e( 'Search existing users by name or email', 'tangnest-robotics' ); ?></label>
				<input type="text" id="tr-user-search" name="user_search" value="<?php echo esc_attr( $user_search ); ?>">
				<?php submit_button( __( 'Search', 'tangnest-robotics' ), 'secondary', '', false ); ?>
			</form>

			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tr_family_nonce' ); ?>
				<input type="hidden" name="family_id" value="<?php echo esc_attr( $family->id ?? 0 ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Parent', 'tangnest-robotics' ); ?></th>
						<td>
							<p>
								<label><input type="radio" name="parent_mode" value="existing" <?php checked( $parent_mode, 'existing' ); ?>> <?php esc_html_e( 'Use existing WordPress user', 'tangnest-robotics' ); ?></label>
								<?php wp_dropdown_users( [
									'name'             => 'existing_user_id',
									'show_option_none' => __( '— Select existing user —', 'tangnest-robotics' ),
									'selected'         => $selected_user_id,
									'search'           => '' !== $user_search ? '*' . $user_search . '*' : '',
								] ); ?>
							</p>
							<div id="tr-existing-parent-fields" style="<?php echo 'existing' === $parent_mode ? '' : 'display:none;'; ?>">
								<table class="form-table" role="presentation">
									<tr>
										<th><label for="tr-existing-email"><?php esc_html_e( 'Email', 'tangnest-robotics' ); ?></label></th>
										<td><input type="email" id="tr-existing-email" name="existing_email" class="regular-text" value="<?php echo esc_attr( $existing_parent_email ); ?>"></td>
									</tr>
									<tr>
										<th><label for="tr-existing-first"><?php esc_html_e( 'First name', 'tangnest-robotics' ); ?></label></th>
										<td><input type="text" id="tr-existing-first" name="existing_first_name" class="regular-text" value="<?php echo esc_attr( $existing_parent_first ); ?>"></td>
									</tr>
									<tr>
										<th><label for="tr-existing-last"><?php esc_html_e( 'Last name', 'tangnest-robotics' ); ?></label></th>
										<td><input type="text" id="tr-existing-last" name="existing_last_name" class="regular-text" value="<?php echo esc_attr( $existing_parent_last ); ?>"></td>
									</tr>
								</table>
								<p class="description"><?php esc_html_e( 'Editing these updates the WordPress user directly — invoices, receipts and access links all follow the email on file. Picking a different user above does not refresh these fields until the page reloads.', 'tangnest-robotics' ); ?></p>
							</div>
							<p>
								<label><input type="radio" name="parent_mode" value="new" <?php checked( $parent_mode, 'new' ); ?>> <?php esc_html_e( 'Create new parent', 'tangnest-robotics' ); ?></label>
							</p>
							<div id="tr-new-parent-fields" style="<?php echo 'new' === $parent_mode ? '' : 'display:none;'; ?>">
								<table class="form-table" role="presentation">
									<tr>
										<th><label for="tr-new-email"><?php esc_html_e( 'Email', 'tangnest-robotics' ); ?></label></th>
										<td><input type="email" id="tr-new-email" name="new_email" class="regular-text" value="<?php echo esc_attr( $posted['new_email'] ?? '' ); ?>"></td>
									</tr>
									<tr>
										<th><label for="tr-new-first"><?php esc_html_e( 'First name', 'tangnest-robotics' ); ?></label></th>
										<td><input type="text" id="tr-new-first" name="new_first_name" class="regular-text" value="<?php echo esc_attr( $posted['new_first_name'] ?? '' ); ?>"></td>
									</tr>
									<tr>
										<th><label for="tr-new-last"><?php esc_html_e( 'Last name', 'tangnest-robotics' ); ?></label></th>
										<td><input type="text" id="tr-new-last" name="new_last_name" class="regular-text" value="<?php echo esc_attr( $posted['new_last_name'] ?? '' ); ?>"></td>
									</tr>
								</table>
								<p class="description"><?php esc_html_e( 'New users get the Subscriber role and an auto-generated password. Credentials are not emailed in this release.', 'tangnest-robotics' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="tr-phone"><?php esc_html_e( 'Phone', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-phone" name="phone" placeholder="07XXXXXXXX" value="<?php echo esc_attr( $phone ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-package"><?php esc_html_e( 'Package', 'tangnest-robotics' ); ?></label></th>
						<td>
							<select id="tr-package" name="package_id">
								<option value="0"><?php esc_html_e( '— Select package —', 'tangnest-robotics' ); ?></option>
								<?php foreach ( $dropdown_packages as $package ) : ?>
									<option value="<?php echo esc_attr( $package->id ); ?>" <?php selected( $selected_package_id, (int) $package->id ); ?>>
										<?php
										echo esc_html( $package->name );
										if ( 'active' !== $package->status ) {
											echo ' ' . esc_html__( '(inactive)', 'tangnest-robotics' );
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="tr-package-details">
								<?php if ( $selected_package ) : ?>
									<?php
									printf(
										/* translators: 1: product code, 2: default duration or "no default" */
										esc_html__( 'Code %1$s — default duration %2$s', 'tangnest-robotics' ),
										esc_html( $selected_package->irembopay_product_code ?: '(none)' ),
										TR_Programs::has_duration( $selected_package )
											/* translators: %d: number of months */
											? esc_html( sprintf( __( '%d months', 'tangnest-robotics' ), (int) $selected_package->duration_months ) )
											: esc_html__( 'not set', 'tangnest-robotics' )
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Select a package to see its product code and default duration.', 'tangnest-robotics' ); ?>
								<?php endif; ?>
							</p>
							<?php if ( $family ) : ?>
								<p class="description"><strong><?php echo esc_html( TR_Families::progress_label( $family ) ); ?></strong></p>
							<?php endif; ?>
							<p class="description" id="tr-live-price"><strong></strong></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Negotiated rate', 'tangnest-robotics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="negotiated_enabled" value="1" id="tr-negotiated-enabled" <?php checked( $negotiated_enabled_now ); ?>>
								<?php esc_html_e( 'This family has an individually agreed amount, overriding the standard price', 'tangnest-robotics' ); ?>
							</label>
							<div id="tr-negotiated-fields" style="<?php echo $negotiated_enabled_now ? '' : 'display:none;'; ?>">
								<p>
									<label for="tr-negotiated-amount"><?php esc_html_e( 'Agreed amount (RWF)', 'tangnest-robotics' ); ?></label>
									<input type="number" id="tr-negotiated-amount" name="negotiated_amount" step="0.01" min="0" value="<?php echo esc_attr( $negotiated_amount_now ); ?>">
								</p>
								<p>
									<label for="tr-negotiated-note"><?php esc_html_e( 'Note (your own reference — why this rate)', 'tangnest-robotics' ); ?></label><br>
									<textarea id="tr-negotiated-note" name="negotiated_note" rows="2" class="large-text"><?php echo esc_textarea( $negotiated_note_now ); ?></textarea>
								</p>
								<?php if ( null !== $negotiated_for_count_saved ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %d: number of children the current rate was agreed for */
											esc_html__( 'Currently agreed for %d children.', 'tangnest-robotics' ),
											$negotiated_for_count_saved
										);
										?>
									</p>
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Unchecking this clears the negotiated rate — the family then bills the package\'s standard price for its active child count.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-billing-day"><?php esc_html_e( 'Billing day', 'tangnest-robotics' ); ?></label></th>
						<td>
							<?php $billing_day_value = $posted['billing_day'] ?? ( $family->billing_day ?? 0 ); ?>
							<input type="number" id="tr-billing-day" name="billing_day" min="1" max="28" value="<?php echo esc_attr( (int) $billing_day_value > 0 ? (int) $billing_day_value : '' ); ?>" <?php echo ( $family && (int) $family->billing_day > 0 ) ? 'readonly' : ''; ?>>
							<?php if ( $family && (int) $family->billing_day > 0 ) : ?>
								<label><input type="checkbox" name="override_anchor" value="1" id="tr-override-anchor"> <?php esc_html_e( 'Override anchor', 'tangnest-robotics' ); ?></label>
								<p class="description"><?php esc_html_e( 'Set automatically from the date the first child was added. Check the box to change it by hand.', 'tangnest-robotics' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Left blank, this is set automatically from the date the first child is added.', 'tangnest-robotics' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="tr-program-start"><?php esc_html_e( 'Programme start date', 'tangnest-robotics' ); ?></label></th>
						<td><input type="date" id="tr-program-start" name="program_start_date" value="<?php echo esc_attr( $posted['program_start_date'] ?? ( $family->program_start_date ?? '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-program-end"><?php esc_html_e( 'Programme end date', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="date" id="tr-program-end" name="program_end_date" value="<?php echo esc_attr( $posted['program_end_date'] ?? ( $family->program_end_date ?? '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'This family\'s own dates are authoritative (v0.10.0). When the selected package has a default duration, entering a start date defaults the end date to start + that length, editable as before. A package with no default duration leaves this for you to fill in.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-status"><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></label></th>
						<td>
							<select id="tr-status" name="status">
								<?php foreach ( TR_Families::STATUSES as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $posted['status'] ?? ( $family->status ?? 'active' ), $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="tr-notes"><?php esc_html_e( 'Notes', 'tangnest-robotics' ); ?></label></th>
						<td><textarea id="tr-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $posted['notes'] ?? ( $family->notes ?? '' ) ); ?></textarea></td>
					</tr>
				</table>

				<?php if ( $family ) : ?>
					<h2><?php esc_html_e( 'Children', 'tangnest-robotics' ); ?></h2>
					<?php self::render_students_sublist( (int) $family->id ); ?>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Add child', 'tangnest-robotics' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Add as many children as needed, then save once. They all share the family\'s package above.', 'tangnest-robotics' ); ?></p>
				<table class="wp-list-table widefat fixed striped" id="tr-new-children-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'First name', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Last name', 'tangnest-robotics' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="tr-new-children-body"></tbody>
				</table>
				<p><button type="button" class="button" id="tr-add-child"><?php esc_html_e( 'Add child', 'tangnest-robotics' ); ?></button></p>
				<?php if ( $programme_has_started ) : ?>
					<p class="description" id="tr-mid-programme-note" style="display:none;">
						<?php
						printf(
							/* translators: %s: the family's programme end date */
							esc_html__( 'This child joins the family\'s programme, ending %s with their siblings.', 'tangnest-robotics' ),
							esc_html( $programme_end_label ?: __( '(end date not set)', 'tangnest-robotics' ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php submit_button( $family ? __( 'Update Family', 'tangnest-robotics' ) : __( 'Add Family', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_FAMILIES ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>

			<?php if ( $family ) : ?>
				<h2><?php esc_html_e( 'History', 'tangnest-robotics' ); ?></h2>
				<?php self::render_history( (int) $family->id ); ?>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			var packageDetails = <?php echo wp_json_encode( $package_details ); ?>;
			var postedChildren = <?php echo wp_json_encode( $posted_children ); ?>;

			// v0.10.0 Task 5 state — everything the live price line and the
			// mid-programme note need that isn't already on the page.
			var existingActiveCount    = <?php echo wp_json_encode( $active_child_count ); ?>;
			var existingMonthlyAmount  = <?php echo wp_json_encode( $family ? (float) $family->monthly_amount : null ); ?>;
			var existingNegotiatedAmount   = <?php echo wp_json_encode( $family && null !== $family->negotiated_amount ? (float) $family->negotiated_amount : null ); ?>;
			var existingNegotiatedForCount = <?php echo wp_json_encode( $negotiated_for_count_saved ); ?>;
			var nextBillingLabel       = <?php echo wp_json_encode( $next_billing_date ? date_i18n( 'j M Y', strtotime( $next_billing_date ) ) : '' ); ?>;
			var programmeHasStarted    = <?php echo wp_json_encode( (bool) $programme_has_started ); ?>;

			var packageSelect  = document.getElementById( 'tr-package' );
			var detailsEl      = document.getElementById( 'tr-package-details' );
			var noPackageText  = <?php echo wp_json_encode( __( 'Select a package to see its product code and default duration.', 'tangnest-robotics' ) ); ?>;
			var detailsFormat  = <?php echo wp_json_encode( __( 'Code %1$s — default duration %2$s', 'tangnest-robotics' ) ); ?>;
			var noCodeText     = <?php echo wp_json_encode( __( '(none)', 'tangnest-robotics' ) ); ?>;
			var noDurationText = <?php echo wp_json_encode( __( 'not set', 'tangnest-robotics' ) ); ?>;
			var monthsSuffix   = <?php echo wp_json_encode( __( 'months', 'tangnest-robotics' ) ); ?>;

			function updatePackageDetails() {
				var detail = packageDetails[ packageSelect.value ];
				if ( ! detail ) {
					detailsEl.textContent = noPackageText;
					return;
				}
				var durationText = detail.duration > 0 ? ( detail.duration + ' ' + monthsSuffix ) : noDurationText;
				detailsEl.textContent = detailsFormat
					.replace( '%1$s', detail.code || noCodeText )
					.replace( '%2$s', durationText );
			}
			packageSelect.addEventListener( 'change', updatePackageDetails );

			var programStartInput = document.getElementById( 'tr-program-start' );
			var programEndInput   = document.getElementById( 'tr-program-end' );

			function pad2( n ) { return n < 10 ? '0' + n : '' + n; }

			function addMonthsToDateString( dateStr, months ) {
				var parts = dateStr.split( '-' );
				var year  = parseInt( parts[0], 10 );
				var month = parseInt( parts[1], 10 );
				var day   = parseInt( parts[2], 10 );
				if ( ! year || ! month || ! day ) { return ''; }
				var d = new Date( year, month - 1 + months, day );
				return d.getFullYear() + '-' + pad2( d.getMonth() + 1 ) + '-' + pad2( d.getDate() );
			}

			function updateProgramEndDefault() {
				if ( ! programStartInput.value ) { return; }
				var detail = packageDetails[ packageSelect.value ];
				// No default duration on this package — leave the end date
				// for the admin to fill in, don't overwrite it with a guess.
				if ( ! detail || ! detail.duration ) { return; }
				var next = addMonthsToDateString( programStartInput.value, detail.duration );
				if ( next ) { programEndInput.value = next; }
			}

			programStartInput.addEventListener( 'change', updateProgramEndDefault );
			packageSelect.addEventListener( 'change', updateProgramEndDefault );

			// --- v0.10.0 Task 5: live price line + mid-programme-join note ---

			var livePriceEl = document.querySelector( '#tr-live-price strong' );
			var midNoteEl   = document.getElementById( 'tr-mid-programme-note' );

			var fmt = <?php echo wp_json_encode( __( '%d children — %s RWF / month', 'tangnest-robotics' ) ); ?>;
			var fmtOne = <?php echo wp_json_encode( __( '1 child — %s RWF / month', 'tangnest-robotics' ) ); ?>;
			var currentlyFmt = <?php echo wp_json_encode( __( ' (currently %s)', 'tangnest-robotics' ) ); ?>;
			var negotiatedFmt = <?php echo wp_json_encode( __( ' (negotiated; standard %s)', 'tangnest-robotics' ) ); ?>;
			var effectiveFmt = <?php echo wp_json_encode( __( 'Takes effect from the next invoice, %s.', 'tangnest-robotics' ) ); ?>;
			var staleFmt = <?php echo wp_json_encode( __( 'This family has a negotiated rate of %1$s RWF agreed for %2$d children. They now have %3$d. Enter a new agreed amount, or clear the negotiated rate to use the standard price%4$s.', 'tangnest-robotics' ) ); ?>;
			var noPriceFmt = <?php echo wp_json_encode( __( 'This package has no price set for %d children yet — add it on the Packages screen.', 'tangnest-robotics' ) ); ?>;

			function numberFmt( n ) {
				return Math.round( n ).toString().replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
			}

			function tableLookup( detail, count ) {
				if ( ! detail || count < 1 ) { return null; }
				if ( 1 === count ) { return detail.price1; }
				if ( 2 === count ) { return detail.price2; }
				if ( 3 === count ) { return detail.price3; }
				if ( null === detail.price3 || null === detail.priceAdd ) { return null; }
				return detail.price3 + ( count - 3 ) * detail.priceAdd;
			}

			function filledChildRowCount() {
				var count = 0;
				body.querySelectorAll( 'tr' ).forEach( function( tr ) {
					var inputs = tr.querySelectorAll( 'input' );
					var first  = inputs[0] ? inputs[0].value.trim() : '';
					var last   = inputs[1] ? inputs[1].value.trim() : '';
					if ( '' !== first || '' !== last ) { count++; }
				} );
				return count;
			}

			function updateLivePrice() {
				if ( ! livePriceEl ) { return; }

				var detail = packageDetails[ packageSelect.value ];
				var count  = existingActiveCount + filledChildRowCount();

				if ( ! detail || count < 1 ) {
					livePriceEl.textContent = '';
					if ( midNoteEl ) { midNoteEl.style.display = 'none'; }
					return;
				}

				var negotiatedChecked = document.getElementById( 'tr-negotiated-enabled' ).checked;
				var standardPrice     = tableLookup( detail, count );
				var line              = '';

				if ( negotiatedChecked && null !== existingNegotiatedAmount && existingNegotiatedForCount === count ) {
					line = ( 1 === count ? fmtOne.replace( '%s', numberFmt( existingNegotiatedAmount ) ) : fmt.replace( '%d', count ).replace( '%s', numberFmt( existingNegotiatedAmount ) ) )
						+ ( null !== standardPrice ? negotiatedFmt.replace( '%s', numberFmt( standardPrice ) ) : '' );
				} else if ( negotiatedChecked && null !== existingNegotiatedAmount && existingNegotiatedForCount !== count ) {
					line = staleFmt
						.replace( '%1$s', numberFmt( existingNegotiatedAmount ) )
						.replace( '%2$d', existingNegotiatedForCount )
						.replace( '%3$d', count )
						.replace( '%4$s', null !== standardPrice ? ' of ' + numberFmt( standardPrice ) + ' RWF' : '' );
				} else if ( null === standardPrice ) {
					line = noPriceFmt.replace( '%d', count );
				} else {
					line = 1 === count ? fmtOne.replace( '%s', numberFmt( standardPrice ) ) : fmt.replace( '%d', count ).replace( '%s', numberFmt( standardPrice ) );
					if ( null !== existingMonthlyAmount && Math.round( existingMonthlyAmount ) !== Math.round( standardPrice ) ) {
						line += currentlyFmt.replace( '%s', numberFmt( existingMonthlyAmount ) );
					}
				}

				if ( nextBillingLabel && null !== standardPrice ) {
					line += ' ' + effectiveFmt.replace( '%s', nextBillingLabel );
				}

				livePriceEl.textContent = line;

				if ( midNoteEl ) {
					midNoteEl.style.display = ( programmeHasStarted && filledChildRowCount() > 0 ) ? '' : 'none';
				}
			}

			var body     = document.getElementById( 'tr-new-children-body' );
			var addBtn   = document.getElementById( 'tr-add-child' );
			var rowIndex = 0;

			function escHtml( s ) {
				return String( s === undefined || s === null ? '' : s )
					.replace( /&/g, '&amp;' )
					.replace( /</g, '&lt;' )
					.replace( />/g, '&gt;' )
					.replace( /"/g, '&quot;' );
			}

			function addChildRow( data ) {
				data = data || {};
				var idx = rowIndex++;
				var tr = document.createElement( 'tr' );

				function field( type, name, value ) {
					return '<input type="' + type + '" name="new_children[' + idx + '][' + name + ']" value="' + escHtml( value ) + '">';
				}

				tr.innerHTML =
					'<td>' + field( 'text', 'first_name', data.first_name ) + '</td>' +
					'<td>' + field( 'text', 'last_name', data.last_name ) + '</td>' +
					'<td><button type="button" class="button-link tr-remove-child"><?php echo esc_js( __( 'Remove', 'tangnest-robotics' ) ); ?></button></td>';

				body.appendChild( tr );

				tr.querySelectorAll( 'input' ).forEach( function( input ) {
					input.addEventListener( 'input', updateLivePrice );
				} );

				tr.querySelector( '.tr-remove-child' ).addEventListener( 'click', function() {
					tr.parentNode.removeChild( tr );
					updateLivePrice();
				} );

				updateLivePrice();
			}

			addBtn.addEventListener( 'click', function() { addChildRow(); } );

			postedChildren.forEach( function( row ) { addChildRow( row ); } );

			packageSelect.addEventListener( 'change', updateLivePrice );

			var negotiatedCb     = document.getElementById( 'tr-negotiated-enabled' );
			var negotiatedFields = document.getElementById( 'tr-negotiated-fields' );
			var negotiatedAmountInput = document.getElementById( 'tr-negotiated-amount' );
			if ( negotiatedCb ) {
				negotiatedCb.addEventListener( 'change', function() {
					negotiatedFields.style.display = negotiatedCb.checked ? '' : 'none';
					updateLivePrice();
				} );
			}
			if ( negotiatedAmountInput ) {
				negotiatedAmountInput.addEventListener( 'input', updateLivePrice );
			}

			updateLivePrice();

			var overrideCb = document.getElementById( 'tr-override-anchor' );
			var billingInput = document.getElementById( 'tr-billing-day' );
			if ( overrideCb && billingInput ) {
				overrideCb.addEventListener( 'change', function() {
					billingInput.readOnly = ! overrideCb.checked;
				} );
			}

			var parentModeRadios = document.querySelectorAll( 'input[name="parent_mode"]' );
			var newParentFields = document.getElementById( 'tr-new-parent-fields' );
			var existingParentFields = document.getElementById( 'tr-existing-parent-fields' );
			function toggleParentFields() {
				var checked = document.querySelector( 'input[name="parent_mode"]:checked' );
				var mode = checked ? checked.value : 'existing';
				newParentFields.style.display = ( 'new' === mode ) ? '' : 'none';
				existingParentFields.style.display = ( 'existing' === mode ) ? '' : 'none';
			}
			parentModeRadios.forEach( function( radio ) {
				radio.addEventListener( 'change', toggleParentFields );
			} );
			toggleParentFields();
		})();
		</script>
		<?php
	}

	/**
	 * Reverse-chronological change history (v0.10.0 Task 7) — the most
	 * recent 20 events shown directly, the rest behind a native <details>
	 * disclosure so the admin never has to leave this screen to see the
	 * full trail. Reads TR_Family_Events only; never writes.
	 */
	private static function render_history( int $family_id ): void {
		$events = TR_Family_Events::get_by_family( $family_id );

		if ( empty( $events ) ) {
			echo '<p class="description">' . esc_html__( 'No history recorded yet.', 'tangnest-robotics' ) . '</p>';
			return;
		}

		$visible = array_slice( $events, 0, 20 );
		$rest    = array_slice( $events, 20 );

		echo '<ul class="tr-family-history">';
		foreach ( $visible as $event ) {
			self::render_history_row( $event );
		}
		echo '</ul>';

		if ( ! empty( $rest ) ) {
			echo '<details><summary>' . esc_html( sprintf(
				/* translators: %d: number of older events */
				__( 'Show all (%d more)', 'tangnest-robotics' ),
				count( $rest )
			) ) . '</summary><ul class="tr-family-history">';
			foreach ( $rest as $event ) {
				self::render_history_row( $event );
			}
			echo '</ul></details>';
		}
	}

	private static function render_history_row( object $event ): void {
		printf(
			'<li><span class="tr-family-history__date">%s</span> — %s — <span class="tr-family-history__actor">%s</span></li>',
			esc_html( date_i18n( 'j M Y', strtotime( $event->created_at ) ) ),
			esc_html( $event->summary ),
			esc_html( TR_Family_Events::actor_label( $event ) )
		);
	}

	private static function render_students_sublist( int $family_id ): void {
		$students = TR_Students::get_list( [ 'family_id' => $family_id, 'per_page' => 200 ] );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'tangnest-robotics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $students ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No students yet.', 'tangnest-robotics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $students as $student ) : ?>
						<?php
						$action  = 'active' === $student->status ? 'deactivate' : 'reactivate';
						$label   = 'active' === $student->status ? __( 'Deactivate', 'tangnest-robotics' ) : __( 'Reactivate', 'tangnest-robotics' );
						$confirm = 'active' === $student->status ? __( 'Deactivate this student?', 'tangnest-robotics' ) : __( 'Reactivate this student?', 'tangnest-robotics' );
						$url     = wp_nonce_url(
							add_query_arg( [ 'page' => TR_Admin_Menu::PAGE_FAMILIES, 'tr_row_action' => $action, 'id' => $student->id ], admin_url( 'admin.php' ) ),
							'tr_student_row_action_' . $student->id
						);
						?>
						<tr>
							<td><?php echo esc_html( trim( $student->first_name . ' ' . $student->last_name ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $student->status ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $url ); ?>" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"><?php echo esc_html( $label ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
