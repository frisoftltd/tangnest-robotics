<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Family add/edit form: pick-or-create parent WP user, phone/meta, the
 * package selection that drives price/duration/product code (v0.8.0 —
 * replaces the old per-child program + bundle-override model), the
 * billing-day anchor (read-only once set unless the admin explicitly
 * checks "override anchor"), and an inline repeatable Children section so
 * several siblings can be added in one save instead of one trip through
 * the standalone Add Student screen per child.
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

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Families::STATUSES, true ) ? $_POST['status'] : 'active';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		[ $valid_children, $child_errors ] = self::parse_children_rows( $_POST['new_children'] ?? [] );
		$errors = array_merge( $errors, $child_errors );

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

		$existing_family = $family_id > 0 ? TR_Families::get( $family_id ) : null;

		$data = [
			// A snapshot of the package's price right now — never
			// recalculated automatically later, so a subsequent price
			// change on the package never retroactively alters this
			// family's billing. Re-saving the family (e.g. to switch
			// package) always refreshes the snapshot to the new package's
			// current price.
			'monthly_amount' => $package->default_monthly_fee,
			'package_id'     => $package_id,
			'months_paid'    => $existing_family ? (int) $existing_family->months_paid : 0,
			'parent_user_id' => $user_id,
			'currency'       => 'RWF',
			'status'         => $status,
			'notes'          => $notes,
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

		foreach ( $valid_children as $child ) {
			TR_Students::insert( [
				'family_id'     => $family_id,
				'first_name'    => $child['first_name'],
				'last_name'     => $child['last_name'],
				'date_of_birth' => $child['date_of_birth'],
				'school'        => $child['school'],
				'status'        => 'active',
			] );

			// Idempotent — only the first child ever actually moves this
			// from 0, regardless of how many rows are processed here.
			TR_Families::set_billing_anchor( $family_id, $child['enrolled_on'] );
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
	 * Any row with at least a name in it is validated fully. No program
	 * field here (v0.8.0) — the family's package covers every child on it;
	 * "enrolled_on" stays because it's still what sets the billing anchor
	 * for a family that doesn't have one yet.
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

		$package_details = [];
		foreach ( $dropdown_packages as $package ) {
			$package_details[ (int) $package->id ] = [
				'amount'   => number_format( (float) $package->default_monthly_fee, 2 ),
				'duration' => (int) $package->duration_months,
				'code'     => $package->irembopay_product_code ?? '',
			];
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
										echo esc_html( $package->name . ' — ' . number_format( (float) $package->default_monthly_fee, 2 ) . ' RWF' );
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
										/* translators: 1: amount, 2: duration in months, 3: product code */
										esc_html__( '%1$s RWF/month — %2$d months — code %3$s', 'tangnest-robotics' ),
										esc_html( number_format( (float) $selected_package->default_monthly_fee, 2 ) ),
										(int) $selected_package->duration_months,
										esc_html( $selected_package->irembopay_product_code ?: '(none)' )
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Select a package to see its price, duration and product code.', 'tangnest-robotics' ); ?>
								<?php endif; ?>
							</p>
							<?php if ( $family ) : ?>
								<p class="description"><strong><?php echo esc_html( TR_Families::progress_label( $family ) ); ?></strong></p>
							<?php endif; ?>
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
				<p class="description"><?php esc_html_e( 'Add as many children as needed, then save once. They all share the family\'s package above.', 'tangnest-robotics' ); ?></p>
				<table class="wp-list-table widefat fixed striped" id="tr-new-children-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'First name', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Last name', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'Date of birth', 'tangnest-robotics' ); ?></th>
							<th><?php esc_html_e( 'School', 'tangnest-robotics' ); ?></th>
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
			var packageDetails = <?php echo wp_json_encode( $package_details ); ?>;
			var postedChildren = <?php echo wp_json_encode( $posted_children ); ?>;

			var packageSelect  = document.getElementById( 'tr-package' );
			var detailsEl      = document.getElementById( 'tr-package-details' );
			var noPackageText  = <?php echo wp_json_encode( __( 'Select a package to see its price, duration and product code.', 'tangnest-robotics' ) ); ?>;
			var detailsFormat  = <?php echo wp_json_encode( __( '%1$s RWF/month — %2$d months — code %3$s', 'tangnest-robotics' ) ); ?>;
			var noCodeText     = <?php echo wp_json_encode( __( '(none)', 'tangnest-robotics' ) ); ?>;

			function updatePackageDetails() {
				var detail = packageDetails[ packageSelect.value ];
				if ( ! detail ) {
					detailsEl.textContent = noPackageText;
					return;
				}
				detailsEl.textContent = detailsFormat
					.replace( '%1$s', detail.amount )
					.replace( '%2$d', detail.duration )
					.replace( '%3$s', detail.code || noCodeText );
			}
			packageSelect.addEventListener( 'change', updatePackageDetails );

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
					'<td>' + field( 'date', 'date_of_birth', data.date_of_birth ) + '</td>' +
					'<td>' + field( 'text', 'school', data.school ) + '</td>' +
					'<td>' + field( 'date', 'enrolled_on', data.enrolled_on || <?php echo wp_json_encode( gmdate( 'Y-m-d' ) ); ?> ) + '</td>' +
					'<td><button type="button" class="button-link tr-remove-child"><?php echo esc_js( __( 'Remove', 'tangnest-robotics' ) ); ?></button></td>';

				body.appendChild( tr );

				tr.querySelector( '.tr-remove-child' ).addEventListener( 'click', function() {
					tr.parentNode.removeChild( tr );
				} );
			}

			addBtn.addEventListener( 'click', function() { addChildRow(); } );

			postedChildren.forEach( function( row ) { addChildRow( row ); } );

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

	private static function render_students_sublist( int $family_id ): void {
		$students = TR_Students::get_list( [ 'family_id' => $family_id, 'per_page' => 200 ] );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Date of birth', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'School', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $students ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No students yet.', 'tangnest-robotics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $students as $student ) : ?>
						<tr>
							<td><?php echo esc_html( trim( $student->first_name . ' ' . $student->last_name ) ); ?></td>
							<td><?php echo esc_html( $student->date_of_birth ?? '—' ); ?></td>
							<td><?php echo esc_html( $student->school ?? '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $student->status ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
