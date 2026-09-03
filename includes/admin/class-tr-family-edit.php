<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Family add/edit form: pick-or-create parent WP user, phone/meta, monthly
 * amount, and the billing-day anchor (read-only once set unless the admin
 * explicitly checks "override anchor").
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

		$monthly_amount = isset( $_POST['monthly_amount'] ) ? (float) wp_unslash( $_POST['monthly_amount'] ) : -1;
		if ( $monthly_amount < 0 ) {
			$errors[] = __( 'Monthly amount must be zero or greater.', 'tangnest-robotics' );
		}

		$billing_day_input = isset( $_POST['billing_day'] ) ? absint( $_POST['billing_day'] ) : 0;
		if ( $billing_day_input > 28 ) {
			$errors[] = __( 'Billing day must be between 1 and 28.', 'tangnest-robotics' );
		}
		$override_anchor = ! empty( $_POST['override_anchor'] );

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Families::STATUSES, true ) ? $_POST['status'] : 'active';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

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
			'parent_user_id' => $user_id,
			'monthly_amount' => $monthly_amount,
			'currency'       => 'RWF',
			'status'         => $status,
			'notes'          => $notes,
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

		TR_Families::clear_composition_flag( $family_id );

		wp_safe_redirect( add_query_arg( [
			'page'    => TR_Admin_Menu::PAGE_FAMILIES,
			'action'  => 'edit',
			'id'      => $family_id,
			'updated' => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
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
						<th><label for="tr-monthly-amount"><?php esc_html_e( 'Monthly amount (RWF)', 'tangnest-robotics' ); ?></label></th>
						<td><input type="number" id="tr-monthly-amount" name="monthly_amount" step="0.01" min="0" required value="<?php echo esc_attr( $posted['monthly_amount'] ?? ( $family->monthly_amount ?? '0.00' ) ); ?>"></td>
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

				<?php submit_button( $family ? __( 'Update Family', 'tangnest-robotics' ) : __( 'Add Family', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_FAMILIES ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>

			<?php if ( $family ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Students', 'tangnest-robotics' ); ?></h2>
				<?php self::render_students_sublist( (int) $family->id ); ?>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			var cb = document.getElementById( 'tr-override-anchor' );
			var input = document.getElementById( 'tr-billing-day' );
			if ( cb && input ) {
				cb.addEventListener( 'change', function() {
					input.readOnly = ! cb.checked;
				} );
			}
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
