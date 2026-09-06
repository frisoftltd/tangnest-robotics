<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Family add/edit form: pick-or-create parent WP user, phone/meta, the
 * calculated (or bundle-override) monthly amount, the billing-day anchor
 * (read-only once set unless the admin explicitly checks "override
 * anchor"), and an inline repeatable Children section so several siblings
 * can be added in one save instead of one trip through the standalone
 * Add Student screen per child.
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

		$amount_is_custom = ! empty( $_POST['amount_is_custom'] );
		$custom_amount    = isset( $_POST['custom_amount'] ) ? (float) wp_unslash( $_POST['custom_amount'] ) : -1;
		if ( $amount_is_custom && $custom_amount < 0 ) {
			$errors[] = __( 'Custom amount must be zero or greater.', 'tangnest-robotics' );
		}

		$billing_day_input = isset( $_POST['billing_day'] ) ? absint( $_POST['billing_day'] ) : 0;
		if ( $billing_day_input > 28 ) {
			$errors[] = __( 'Billing day must be between 1 and 28.', 'tangnest-robotics' );
		}
		$override_anchor = ! empty( $_POST['override_anchor'] );

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Families::STATUSES, true ) ? $_POST['status'] : 'active';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		[ $valid_children, $child_errors ] = self::parse_children_rows( $_POST['new_children'] ?? [] );
		$errors = array_merge( $errors, $child_errors );

		$user_id  = 0;
		$new_email = $new_first = $new_last = '';

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
			$user_id = isset( $_POST['existing_user_id'] ) ? absint( $_POST['existing_user_id'] ) : 0;
			if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
				$errors[] = __( 'Please select an existing parent user.', 'tangnest-robotics' );
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
		}

		update_user_meta( $user_id, 'phone_number', $phone );
		$user = get_userdata( $user_id );
		update_user_meta( $user_id, 'parent_name', $user->display_name );
		update_user_meta( $user_id, 'parent_email', $user->user_email );

		$data = [
			// Placeholder when calculated — recalculate_amount() below sets
			// the real figure once this family's children are in place.
			'monthly_amount'   => $amount_is_custom ? $custom_amount : 0,
			'amount_is_custom' => $amount_is_custom,
			'parent_user_id'   => $user_id,
			'currency'         => 'RWF',
			'status'           => $status,
			'notes'            => $notes,
		];

		if ( $family_id > 0 ) {
			$existing     = TR_Families::get( $family_id );
			$billing_day  = $existing ? (int) $existing->billing_day : 0;
			if ( 0 === $billing_day || $override_anchor ) {
				$billing_day = $billing_day_input;
			}
			$data['billing_day'] = $billing_day;
			TR_Families::update( $family_id, $data );
		} else {
			$data['billing_day'] = $billing_day_input;
			$family_id            = TR_Families::insert( $data );
		}

		foreach ( $valid_children as $child ) {
			$new_student_id = TR_Students::insert( [
				'family_id'     => $family_id,
				'first_name'    => $child['first_name'],
				'last_name'     => $child['last_name'],
				'date_of_birth' => $child['date_of_birth'],
				'school'        => $child['school'],
				'status'        => 'active',
			] );

			TR_Enrollments::insert( [
				'student_id'   => $new_student_id,
				'program_id'   => $child['program_id'],
				'enrolled_on'  => $child['enrolled_on'],
				'months_total' => (int) $child['program']->duration_months,
				'months_paid'  => 0,
				'status'       => 'active',
			] );

			// Idempotent — only the first child ever actually moves this
			// from 0, regardless of how many rows are processed here.
			TR_Families::set_billing_anchor( $family_id, $child['enrolled_on'] );
		}

		if ( ! $amount_is_custom ) {
			TR_Families::recalculate_amount( $family_id );
		}

		TR_Families::clear_composition_flag( $family_id );

		if ( 'new' === $mode ) {
			TR_Notifications::maybe_send_welcome_email( $user_id, $family_id );
		}

		wp_safe_redirect( add_query_arg( [
			'page'    => TR_Admin_Menu::PAGE_FAMILIES,
			'action'  => 'edit',
			'id'      => $family_id,
			'updated' => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A fully blank row is just an unused template row appended by the "Add
	 * child" button and never filled in — silently skipped, not an error.
	 * Any row with at least a name in it is validated fully.
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

			$dob = '';
			if ( ! empty( $row['date_of_birth'] ) ) {
				$raw_dob = sanitize_text_field( $row['date_of_birth'] );
				$dt      = DateTime::createFromFormat( 'Y-m-d', $raw_dob );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $raw_dob ) {
					$errors[] = sprintf(
						/* translators: %s: child's name */
						__( '%s: date of birth is not a valid date.', 'tangnest-robotics' ),
						$who
					);
					continue;
				}
				$dob = $raw_dob;
			}

			$school = isset( $row['school'] ) ? sanitize_text_field( $row['school'] ) : '';

			$program_id = isset( $row['program_id'] ) ? absint( $row['program_id'] ) : 0;
			$program    = $program_id > 0 ? TR_Programs::get( $program_id ) : null;
			if ( null === $program ) {
				$errors[] = sprintf(
					/* translators: %s: child's name */
					__( '%s: please select a program.', 'tangnest-robotics' ),
					$who
				);
				continue;
			}

			$enrolled_on = isset( $row['enrolled_on'] ) ? sanitize_text_field( $row['enrolled_on'] ) : '';
			$dt          = DateTime::createFromFormat( 'Y-m-d', $enrolled_on );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $enrolled_on ) {
				$errors[] = sprintf(
					/* translators: %s: child's name */
					__( '%s: enrollment date is not a valid date.', 'tangnest-robotics' ),
					$who
				);
				continue;
			}

			$valid_children[] = [
				'first_name'    => $first,
				'last_name'     => $last,
				'date_of_birth' => $dob,
				'school'        => $school,
				'program_id'    => $program_id,
				'program'       => $program,
				'enrolled_on'   => $enrolled_on,
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

		$parent_user = $family ? get_userdata( (int) $family->parent_user_id ) : null;
		$phone       = $posted['phone'] ?? ( $parent_user ? get_user_meta( $parent_user->ID, 'phone_number', true ) : '' );
		$parent_mode = $posted['parent_mode'] ?? 'existing';

		$user_search = isset( $_GET['user_search'] ) ? sanitize_text_field( wp_unslash( $_GET['user_search'] ) ) : '';
		$selected_user_id = isset( $posted['existing_user_id'] ) ? absint( $posted['existing_user_id'] ) : ( $parent_user ? $parent_user->ID : 0 );

		$is_custom       = isset( $posted['amount_is_custom'] ) ? ! empty( $posted['amount_is_custom'] ) : ( $family ? (bool) $family->amount_is_custom : false );
		$calculated_now  = $family ? TR_Families::calculate_amount( (int) $family->id ) : 0.0;
		$custom_value    = $posted['custom_amount'] ?? ( $family->monthly_amount ?? '0.00' );
		$active_children = $family ? count( TR_Enrollments::get_active_by_family( (int) $family->id ) ) : 0;

		$active_programs = TR_Programs::get_list( [ 'status' => 'active', 'per_page' => 200 ] );
		$program_fees    = [];
		foreach ( $active_programs as $program ) {
			$program_fees[ (int) $program->id ] = (float) $program->default_monthly_fee;
		}

		$posted_children = isset( $posted['new_children'] ) && is_array( $posted['new_children'] ) ? array_values( $posted['new_children'] ) : [];
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php echo $family ? esc_html__( 'Edit Family', 'tangnest-robotics' ) : esc_html__( 'Add Family', 'tangnest-robotics' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Family saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error">
					<ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul>
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
							<p>
								<label><input type="radio" name="parent_mode" value="new" <?php checked( $parent_mode, 'new' ); ?>> <?php esc_html_e( 'Create new parent', 'tangnest-robotics' ); ?></label>
							</p>
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
						</td>
					</tr>
					<tr>
						<th><label for="tr-phone"><?php esc_html_e( 'Phone', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-phone" name="phone" placeholder="07XXXXXXXX" value="<?php echo esc_attr( $phone ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Monthly amount (RWF)', 'tangnest-robotics' ); ?></th>
						<td>
							<p>
								<strong id="tr-calculated-display"><?php echo esc_html( number_format( $is_custom ? (float) $custom_value : $calculated_now, 2 ) ); ?> RWF</strong>
								<span id="tr-calculated-working" class="description" style="<?php echo $is_custom ? 'display:none;' : ''; ?>">
									<?php echo esc_html( self::working_text( $active_children, $calculated_now ) ); ?>
								</span>
							</p>
							<label>
								<input type="checkbox" id="tr-amount-custom" name="amount_is_custom" value="1" <?php checked( $is_custom ); ?>>
								<?php esc_html_e( 'Custom family amount (bundle)', 'tangnest-robotics' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Tick this when siblings get a negotiated total instead of the sum of their program fees.', 'tangnest-robotics' ); ?></p>
							<p id="tr-custom-amount-row" style="<?php echo $is_custom ? '' : 'display:none;'; ?>">
								<label for="tr-custom-amount"><?php esc_html_e( 'Custom total (RWF)', 'tangnest-robotics' ); ?></label>
								<input type="number" id="tr-custom-amount" name="custom_amount" step="0.01" min="0" value="<?php echo esc_attr( $custom_value ); ?>">
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-billing-day"><?php esc_html_e( 'Billing day', 'tangnest-robotics' ); ?></label></th>
						<td>
							<?php $billing_day_value = $posted['billing_day'] ?? ( $family->billing_day ?? 0 ); ?>
							<input type="number" id="tr-billing-day" name="billing_day" min="1" max="28" value="<?php echo esc_attr( (int) $billing_day_value > 0 ? (int) $billing_day_value : '' ); ?>" <?php echo ( $family && (int) $family->billing_day > 0 ) ? 'readonly' : ''; ?>>
							<?php if ( $family && (int) $family->billing_day > 0 ) : ?>
								<label><input type="checkbox" name="override_anchor" value="1" id="tr-override-anchor"> <?php esc_html_e( 'Override anchor', 'tangnest-robotics' ); ?></label>
								<p class="description"><?php esc_html_e( 'Set automatically from the first child\'s enrollment date. Check the box to change it by hand.', 'tangnest-robotics' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Left blank, this is set automatically when the first child is enrolled.', 'tangnest-robotics' ); ?></p>
							<?php endif; ?>
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
				<p class="description"><?php esc_html_e( 'Add as many children as needed, then save once.', 'tangnest-robotics' ); ?></p>
				<table class="wp-list-table widefat fixed striped" id="tr-new-children-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'First name', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Last name', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Date of birth', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'School', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Program', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Enrolled on', 'tangnest-robotics' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="tr-new-children-body"></tbody>
				</table>
				<p><button type="button" class="button" id="tr-add-child"><?php esc_html_e( 'Add child', 'tangnest-robotics' ); ?></button></p>

				<?php submit_button( $family ? __( 'Update Family', 'tangnest-robotics' ) : __( 'Add Family', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_FAMILIES ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>
		</div>
		<script>
		(function() {
			var programFees = <?php echo wp_json_encode( $program_fees ); ?>;
			var programOptions = <?php echo wp_json_encode( array_map( static function ( $p ) {
				return [ 'id' => (int) $p->id, 'label' => $p->name . ' (' . number_format( (float) $p->default_monthly_fee, 2 ) . ' RWF)' ];
			}, $active_programs ) ); ?>;
			var postedChildren = <?php echo wp_json_encode( $posted_children ); ?>;
			var baselineTotal = <?php echo wp_json_encode( (float) $calculated_now ); ?>;
			var baselineCount = <?php echo wp_json_encode( (int) $active_children ); ?>;

			var body       = document.getElementById( 'tr-new-children-body' );
			var addBtn     = document.getElementById( 'tr-add-child' );
			var customCb   = document.getElementById( 'tr-amount-custom' );
			var customRow  = document.getElementById( 'tr-custom-amount-row' );
			var workingEl  = document.getElementById( 'tr-calculated-working' );
			var displayEl  = document.getElementById( 'tr-calculated-display' );
			var customInput = document.getElementById( 'tr-custom-amount' );
			var rowIndex   = 0;

			function fmt( n ) {
				return n.toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
			}

			function recompute() {
				var total = baselineTotal;
				var count = baselineCount;

				body.querySelectorAll( 'select.tr-child-program' ).forEach( function( sel ) {
					var fee = programFees[ sel.value ];
					if ( fee !== undefined ) {
						total += fee;
						count += 1;
					}
				} );

				if ( customCb.checked ) {
					displayEl.textContent = fmt( parseFloat( customInput.value || '0' ) ) + ' RWF';
					workingEl.style.display = 'none';
				} else {
					displayEl.textContent = fmt( total ) + ' RWF';
					workingEl.style.display = '';
					workingEl.textContent = count === 1
						? ( '1 ' + <?php echo wp_json_encode( __( 'child', 'tangnest-robotics' ) ); ?> + ' — ' + fmt( total ) + ' RWF ' + <?php echo wp_json_encode( __( 'calculated', 'tangnest-robotics' ) ); ?> )
						: ( count + ' ' + <?php echo wp_json_encode( __( 'children', 'tangnest-robotics' ) ); ?> + ' — ' + fmt( total ) + ' RWF ' + <?php echo wp_json_encode( __( 'calculated', 'tangnest-robotics' ) ); ?> );
				}
			}

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

				var optionsHtml = '<option value="0"><?php echo esc_js( __( '— Select program —', 'tangnest-robotics' ) ); ?></option>';
				programOptions.forEach( function( p ) {
					var selected = String( p.id ) === String( data.program_id || '' ) ? ' selected' : '';
					optionsHtml += '<option value="' + p.id + '"' + selected + '>' + escHtml( p.label ) + '</option>';
				} );

				tr.innerHTML =
					'<td>' + field( 'text', 'first_name', data.first_name ) + '</td>' +
					'<td>' + field( 'text', 'last_name', data.last_name ) + '</td>' +
					'<td>' + field( 'date', 'date_of_birth', data.date_of_birth ) + '</td>' +
					'<td>' + field( 'text', 'school', data.school ) + '</td>' +
					'<td><select class="tr-child-program" name="new_children[' + idx + '][program_id]">' + optionsHtml + '</select></td>' +
					'<td>' + field( 'date', 'enrolled_on', data.enrolled_on || <?php echo wp_json_encode( gmdate( 'Y-m-d' ) ); ?> ) + '</td>' +
					'<td><button type="button" class="button-link tr-remove-child"><?php echo esc_js( __( 'Remove', 'tangnest-robotics' ) ); ?></button></td>';

				body.appendChild( tr );

				tr.querySelector( '.tr-child-program' ).addEventListener( 'change', recompute );
				tr.querySelector( '.tr-remove-child' ).addEventListener( 'click', function() {
					tr.parentNode.removeChild( tr );
					recompute();
				} );

				recompute();
			}

			addBtn.addEventListener( 'click', function() { addChildRow(); } );

			customCb.addEventListener( 'change', function() {
				customRow.style.display = customCb.checked ? '' : 'none';
				recompute();
			} );
			customInput.addEventListener( 'input', recompute );

			postedChildren.forEach( function( row ) { addChildRow( row ); } );

			var overrideCb = document.getElementById( 'tr-override-anchor' );
			var billingInput = document.getElementById( 'tr-billing-day' );
			if ( overrideCb && billingInput ) {
				overrideCb.addEventListener( 'change', function() {
					billingInput.readOnly = ! overrideCb.checked;
				} );
			}

			recompute();
		})();
		</script>
		<?php
	}

	private static function working_text( int $count, float $total ): string {
		if ( 0 === $count ) {
			return __( 'No active children yet.', 'tangnest-robotics' );
		}

		return sprintf(
			/* translators: 1: number of active children, 2: calculated total */
			_n( '%1$d child — %2$s RWF calculated', '%1$d children — %2$s RWF calculated', $count, 'tangnest-robotics' ),
			$count,
			number_format( $total, 2 )
		);
	}

	private static function render_students_sublist( int $family_id ): void {
		$students = TR_Students::get_list( [ 'family_id' => $family_id, 'per_page' => 200 ] );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Program', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $students ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No students yet.', 'tangnest-robotics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $students as $student ) : ?>
						<?php
						$enrollments = TR_Enrollments::get_by_student( (int) $student->id );
						$enrollment  = $enrollments[0] ?? null;
						$program     = $enrollment ? TR_Programs::get( (int) $enrollment->program_id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( trim( $student->first_name . ' ' . $student->last_name ) ); ?></td>
							<td><?php echo esc_html( $program->name ?? '—' ); ?></td>
							<td><?php echo esc_html( $enrollment ? TR_Enrollments::progress_label( $enrollment ) : '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $student->status ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
