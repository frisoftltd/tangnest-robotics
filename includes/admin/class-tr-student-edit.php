<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Student add/edit form. Combines the student record and its single live
 * enrollment on one screen. months_total is copied from the chosen
 * program at save time and never retroactively updated by later program
 * edits.
 */
class TR_Student_Edit {
	const NONCE = 'tr_student_save';

	private static function state_key(): string {
		return 'tr_student_form_state_' . get_current_user_id();
	}

	public static function maybe_handle_submit(): void {
		if ( ! isset( $_POST['tr_student_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'tr_student_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$errors      = [];
		$student_id  = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
		$family_mode = isset( $_POST['family_mode'] ) && 'new' === $_POST['family_mode'] ? 'new' : 'existing';

		$family_id = 0;
		$new_email = $new_first = $new_last = $new_phone = '';
		$new_monthly_amount = 0.0;

		if ( 'new' === $family_mode ) {
			$new_email = isset( $_POST['new_email'] ) ? sanitize_email( wp_unslash( $_POST['new_email'] ) ) : '';
			$new_first = isset( $_POST['new_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_first_name'] ) ) : '';
			$new_last  = isset( $_POST['new_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_last_name'] ) ) : '';
			$new_phone = isset( $_POST['new_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['new_phone'] ) ) : '';
			$new_monthly_amount = isset( $_POST['new_monthly_amount'] ) ? (float) wp_unslash( $_POST['new_monthly_amount'] ) : -1;

			if ( ! is_email( $new_email ) ) {
				$errors[] = __( 'Please enter a valid email for the new parent.', 'tangnest-robotics' );
			} elseif ( email_exists( $new_email ) ) {
				$errors[] = __( 'A WordPress user with that email already exists — pick their family from the existing list instead.', 'tangnest-robotics' );
			}

			if ( '' === $new_first || '' === $new_last ) {
				$errors[] = __( 'First and last name are required for a new parent.', 'tangnest-robotics' );
			}

			if ( ! preg_match( '/^07\d{8}$/', $new_phone ) ) {
				$errors[] = __( 'Phone must be in the format 07XXXXXXXX.', 'tangnest-robotics' );
			}

			if ( $new_monthly_amount < 0 ) {
				$errors[] = __( 'Monthly amount must be zero or greater.', 'tangnest-robotics' );
			}
		} else {
			$family_id = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
			if ( $family_id <= 0 || ! TR_Families::get( $family_id ) ) {
				$errors[] = __( 'Please select a family.', 'tangnest-robotics' );
			}
		}

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		if ( '' === $first_name || '' === $last_name ) {
			$errors[] = __( 'First and last name are required.', 'tangnest-robotics' );
		}

		$dob = '';
		if ( ! empty( $_POST['date_of_birth'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ) );
			$dt  = DateTime::createFromFormat( 'Y-m-d', $raw );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $raw ) {
				$errors[] = __( 'Date of birth is not a valid date.', 'tangnest-robotics' );
			} else {
				$dob = $raw;
			}
		}

		$school = isset( $_POST['school'] ) ? sanitize_text_field( wp_unslash( $_POST['school'] ) ) : '';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$program    = $program_id > 0 ? TR_Programs::get( $program_id ) : null;
		if ( null === $program ) {
			$errors[] = __( 'Please select a program.', 'tangnest-robotics' );
		}

		$enrolled_on = isset( $_POST['enrolled_on'] ) ? sanitize_text_field( wp_unslash( $_POST['enrolled_on'] ) ) : '';
		$dt          = DateTime::createFromFormat( 'Y-m-d', $enrolled_on );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $enrolled_on ) {
			$errors[] = __( 'Enrollment date is not a valid date.', 'tangnest-robotics' );
		}

		if ( ! empty( $errors ) ) {
			set_transient( self::state_key(), [ 'errors' => $errors, 'values' => wp_unslash( $_POST ) ], MINUTE_IN_SECONDS );
			wp_safe_redirect( self::edit_url( $student_id ) );
			exit;
		}

		if ( 'new' === $family_mode ) {
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
				wp_safe_redirect( self::edit_url( $student_id ) );
				exit;
			}

			update_user_meta( $user_id, 'phone_number', $new_phone );
			$user = get_userdata( $user_id );
			update_user_meta( $user_id, 'parent_name', $user->display_name );
			update_user_meta( $user_id, 'parent_email', $user->user_email );

			$family_id = TR_Families::insert( [
				'parent_user_id' => $user_id,
				'monthly_amount' => $new_monthly_amount,
				'currency'       => 'RWF',
				'billing_day'    => 0,
				'status'         => 'active',
			] );
		}

		$existing_student = $student_id > 0 ? TR_Students::get( $student_id ) : null;

		$student_data = [
			'family_id'     => $family_id,
			'first_name'    => $first_name,
			'last_name'     => $last_name,
			'date_of_birth' => $dob,
			'school'        => $school,
			'status'        => $existing_student->status ?? 'active',
			'notes'         => $notes,
		];

		if ( $existing_student ) {
			TR_Students::update( $student_id, $student_data );
		} else {
			$student_id = TR_Students::insert( $student_data );
		}

		$enrollment_id       = isset( $_POST['enrollment_id'] ) ? absint( $_POST['enrollment_id'] ) : 0;
		$existing_enrollment = $enrollment_id > 0 ? TR_Enrollments::get( $enrollment_id ) : null;
		$months_paid          = $existing_enrollment ? (int) $existing_enrollment->months_paid : 0;
		$enrollment_status    = $existing_enrollment ? $existing_enrollment->status : 'active';

		$enrollment_data = [
			'program_id'   => $program_id,
			'enrolled_on'  => $enrolled_on,
			'months_total' => (int) $program->duration_months,
			'months_paid'  => $months_paid,
			'status'       => $enrollment_status,
		];

		if ( $existing_enrollment ) {
			TR_Enrollments::update( $enrollment_id, $enrollment_data );
		} else {
			TR_Enrollments::insert( $enrollment_data + [ 'student_id' => $student_id ] );
		}

		TR_Families::set_billing_anchor( $family_id, $enrolled_on );
		TR_Families::flag_composition_change( $family_id );

		wp_safe_redirect( add_query_arg( [
			'page'    => TR_Admin_Menu::PAGE_STUDENTS,
			'action'  => 'edit',
			'id'      => $student_id,
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

	private static function edit_url( int $student_id ): string {
		$args = [ 'page' => TR_Admin_Menu::PAGE_STUDENTS, 'action' => $student_id > 0 ? 'edit' : 'add' ];
		if ( $student_id > 0 ) {
			$args['id'] = $student_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$student_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$student    = $student_id > 0 ? TR_Students::get( $student_id ) : null;
		$enrollment = null;
		if ( $student ) {
			$enrollments = TR_Enrollments::get_by_student( $student_id );
			$enrollment  = $enrollments[0] ?? null;
		}

		$state = get_transient( self::state_key() );
		if ( $state ) {
			delete_transient( self::state_key() );
		}
		$errors = $state['errors'] ?? [];
		$posted = $state['values'] ?? [];

		$family_mode = $posted['family_mode'] ?? 'existing';
		$families    = TR_Families::get_list( [ 'per_page' => 200 ] );
		$programs    = TR_Programs::get_list( [ 'status' => 'active', 'per_page' => 200 ] );

		$selected_family_id = isset( $posted['family_id'] ) ? absint( $posted['family_id'] ) : ( $student->family_id ?? 0 );
		$selected_program_id = isset( $posted['program_id'] ) ? absint( $posted['program_id'] ) : ( $enrollment->program_id ?? 0 );
		$enrolled_on          = $posted['enrolled_on'] ?? ( $enrollment->enrolled_on ?? gmdate( 'Y-m-d' ) );
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php echo $student ? esc_html__( 'Edit Student', 'tangnest-robotics' ) : esc_html__( 'Add Student', 'tangnest-robotics' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Student saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error">
					<ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tr_student_nonce' ); ?>
				<input type="hidden" name="student_id" value="<?php echo esc_attr( $student->id ?? 0 ); ?>">
				<input type="hidden" name="enrollment_id" value="<?php echo esc_attr( $enrollment->id ?? 0 ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Family', 'tangnest-robotics' ); ?></th>
						<td>
							<p>
								<label><input type="radio" name="family_mode" value="existing" <?php checked( $family_mode, 'existing' ); ?>> <?php esc_html_e( 'Existing family', 'tangnest-robotics' ); ?></label>
								<select name="family_id">
									<option value="0"><?php esc_html_e( '— Select family —', 'tangnest-robotics' ); ?></option>
									<?php foreach ( $families as $family ) : ?>
										<?php $parent = get_userdata( (int) $family->parent_user_id ); ?>
										<option value="<?php echo esc_attr( $family->id ); ?>" <?php selected( $selected_family_id, (int) $family->id ); ?>>
											<?php echo esc_html( ( $parent ? $parent->display_name : __( '(no user)', 'tangnest-robotics' ) ) . ' — ' . $family->monthly_amount . ' RWF/mo' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label><input type="radio" name="family_mode" value="new" <?php checked( $family_mode, 'new' ); ?>> <?php esc_html_e( 'Create new family', 'tangnest-robotics' ); ?></label>
							</p>
							<table class="form-table" role="presentation">
								<tr>
									<th><label for="tr-new-email"><?php esc_html_e( 'Parent email', 'tangnest-robotics' ); ?></label></th>
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
								<tr>
									<th><label for="tr-new-phone"><?php esc_html_e( 'Phone', 'tangnest-robotics' ); ?></label></th>
									<td><input type="text" id="tr-new-phone" name="new_phone" placeholder="07XXXXXXXX" value="<?php echo esc_attr( $posted['new_phone'] ?? '' ); ?>"></td>
								</tr>
								<tr>
									<th><label for="tr-new-amount"><?php esc_html_e( 'Monthly amount (RWF)', 'tangnest-robotics' ); ?></label></th>
									<td><input type="number" id="tr-new-amount" name="new_monthly_amount" step="0.01" min="0" value="<?php echo esc_attr( $posted['new_monthly_amount'] ?? '0.00' ); ?>"></td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<th><label for="tr-first-name"><?php esc_html_e( 'First name', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-first-name" name="first_name" class="regular-text" required value="<?php echo esc_attr( $posted['first_name'] ?? ( $student->first_name ?? '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-last-name"><?php esc_html_e( 'Last name', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-last-name" name="last_name" class="regular-text" required value="<?php echo esc_attr( $posted['last_name'] ?? ( $student->last_name ?? '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-dob"><?php esc_html_e( 'Date of birth', 'tangnest-robotics' ); ?></label></th>
						<td><input type="date" id="tr-dob" name="date_of_birth" value="<?php echo esc_attr( $posted['date_of_birth'] ?? ( $student->date_of_birth ?? '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-school"><?php esc_html_e( 'School', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-school" name="school" class="regular-text" value="<?php echo esc_attr( $posted['school'] ?? ( $student->school ?? '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-program"><?php esc_html_e( 'Program', 'tangnest-robotics' ); ?></label></th>
						<td>
							<select id="tr-program" name="program_id" required>
								<option value="0"><?php esc_html_e( '— Select program —', 'tangnest-robotics' ); ?></option>
								<?php foreach ( $programs as $program ) : ?>
									<option value="<?php echo esc_attr( $program->id ); ?>" <?php selected( $selected_program_id, (int) $program->id ); ?>><?php echo esc_html( $program->name . ' (' . $program->duration_months . ' mo)' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="tr-enrolled-on"><?php esc_html_e( 'Enrollment date', 'tangnest-robotics' ); ?></label></th>
						<td><input type="date" id="tr-enrolled-on" name="enrolled_on" required value="<?php echo esc_attr( $enrolled_on ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-notes"><?php esc_html_e( 'Notes', 'tangnest-robotics' ); ?></label></th>
						<td><textarea id="tr-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $posted['notes'] ?? ( $student->notes ?? '' ) ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( $student ? __( 'Update Student', 'tangnest-robotics' ) : __( 'Add Student', 'tangnest-robotics' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . TR_Admin_Menu::PAGE_STUDENTS ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
			</form>
		</div>
		<?php
	}
}
