<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single-screen Programs admin page: a plain table plus an add/edit form.
 */
class TR_Programs_Page {
	const PAGE  = 'tangnest-robotics-programs';
	const NONCE = 'tr_program_save';

	public static function maybe_handle_submit(): void {
		if ( ! isset( $_POST['tr_program_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'tr_program_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$errors = [];

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			$errors[] = __( 'Program name is required.', 'tangnest-robotics' );
		}

		$duration_months = isset( $_POST['duration_months'] ) ? absint( $_POST['duration_months'] ) : 0;
		if ( $duration_months < 1 || $duration_months > 36 ) {
			$errors[] = __( 'Duration must be between 1 and 36 months.', 'tangnest-robotics' );
		}

		$default_fee = isset( $_POST['default_monthly_fee'] ) ? (float) wp_unslash( $_POST['default_monthly_fee'] ) : 0.0;
		if ( $default_fee < 0 ) {
			$errors[] = __( 'Default monthly fee cannot be negative.', 'tangnest-robotics' );
		}

		$product_code = isset( $_POST['irembopay_product_code'] ) ? sanitize_text_field( wp_unslash( $_POST['irembopay_product_code'] ) ) : '';
		if ( '' !== $product_code && ! preg_match( '/^PC-[A-Za-z0-9]+$/', $product_code ) ) {
			$errors[] = __( 'IremboPay product code must look like PC- followed by letters/numbers.', 'tangnest-robotics' );
		}

		$start_date = '';
		if ( ! empty( $_POST['start_date'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['start_date'] ) );
			$dt  = DateTime::createFromFormat( 'Y-m-d', $raw );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $raw ) {
				$errors[] = __( 'Start date is not a valid date.', 'tangnest-robotics' );
			} else {
				$start_date = $raw;
			}
		}

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Programs::STATUSES, true ) ? $_POST['status'] : 'active';
		$id     = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;

		if ( ! empty( $errors ) ) {
			set_transient( 'tr_program_form_errors_' . get_current_user_id(), $errors, MINUTE_IN_SECONDS );
			wp_safe_redirect( self::edit_url( $id ) );
			exit;
		}

		$data = [
			'name'                   => $name,
			'duration_months'        => $duration_months,
			'default_monthly_fee'    => $default_fee,
			'irembopay_product_code' => $product_code,
			'start_date'             => $start_date,
			'status'                 => $status,
		];

		if ( $id > 0 ) {
			TR_Programs::update( $id, $data );
		} else {
			$id = TR_Programs::insert( $data );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function edit_url( int $id ): string {
		$args = [ 'page' => self::PAGE ];
		if ( $id > 0 ) {
			$args['edit'] = $id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$program = $edit_id > 0 ? TR_Programs::get( $edit_id ) : null;

		$errors = get_transient( 'tr_program_form_errors_' . get_current_user_id() );
		if ( $errors ) {
			delete_transient( 'tr_program_form_errors_' . get_current_user_id() );
		}
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Programs', 'tangnest-robotics' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Program saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) && is_array( $errors ) ) : ?>
				<div class="notice notice-error">
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<h2><?php echo $program ? esc_html__( 'Edit Program', 'tangnest-robotics' ) : esc_html__( 'Add Program', 'tangnest-robotics' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tr_program_nonce' ); ?>
				<input type="hidden" name="program_id" value="<?php echo esc_attr( $program->id ?? 0 ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tr-name"><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-name" name="name" class="regular-text" required value="<?php echo esc_attr( $program->name ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-duration"><?php esc_html_e( 'Duration (months)', 'tangnest-robotics' ); ?></label></th>
						<td><input type="number" id="tr-duration" name="duration_months" min="1" max="36" required value="<?php echo esc_attr( $program->duration_months ?? 8 ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-fee"><?php esc_html_e( 'Default monthly fee', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="number" id="tr-fee" name="default_monthly_fee" step="0.01" min="0" value="<?php echo esc_attr( $program->default_monthly_fee ?? '0.00' ); ?>">
							<p class="description"><?php esc_html_e( 'Convenience figure only. Families are billed their own monthly amount, never this fee.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-product-code"><?php esc_html_e( 'IremboPay product code', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-product-code" name="irembopay_product_code" class="regular-text" placeholder="PC-XXXXXXXX" value="<?php echo esc_attr( $program->irembopay_product_code ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-start-date"><?php esc_html_e( 'Start date', 'tangnest-robotics' ); ?></label></th>
						<td><input type="date" id="tr-start-date" name="start_date" value="<?php echo esc_attr( $program->start_date ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-status"><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></label></th>
						<td>
							<select id="tr-status" name="status">
								<?php foreach ( TR_Programs::STATUSES as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $program->status ?? 'active', $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( $program ? __( 'Update Program', 'tangnest-robotics' ) : __( 'Add Program', 'tangnest-robotics' ) ); ?>
				<?php if ( $program ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
				<?php endif; ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'All Programs', 'tangnest-robotics' ); ?></h2>
			<?php self::render_table(); ?>
		</div>
		<?php
	}

	private static function render_table(): void {
		$programs = TR_Programs::get_list( [ 'per_page' => 200 ] );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Default Fee', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Product Code', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Start Date', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $programs ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No programs yet.', 'tangnest-robotics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $programs as $program ) : ?>
						<tr>
							<td><?php echo esc_html( $program->name ); ?></td>
							<td><?php echo esc_html( $program->duration_months ); ?></td>
							<td><?php echo esc_html( $program->default_monthly_fee ); ?></td>
							<td><?php echo esc_html( $program->irembopay_product_code ?? '' ); ?></td>
							<td><?php echo esc_html( $program->start_date ?? '' ); ?></td>
							<td><?php echo esc_html( ucfirst( $program->status ) ); ?></td>
							<td><a href="<?php echo esc_url( self::edit_url( (int) $program->id ) ); ?>"><?php esc_html_e( 'Edit', 'tangnest-robotics' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
