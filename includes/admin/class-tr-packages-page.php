<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Packages admin screen (renamed from Programs, v0.8.0). A package is one
 * of Tangnest's IremboPay-priced tiers — a course at a specific
 * family-size price point, e.g. "Introduction Robotics — 2 siblings". A
 * family selects exactly one; product code and monthly amount are
 * required for an active package, since a family billed against one
 * without a code can never pay online.
 */
class TR_Packages_Page {
	const PAGE  = 'tangnest-robotics-packages';
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
			$errors[] = __( 'Package name is required.', 'tangnest-robotics' );
		}

		$duration_months = isset( $_POST['duration_months'] ) ? absint( $_POST['duration_months'] ) : 0;
		if ( $duration_months < 1 || $duration_months > 36 ) {
			$errors[] = __( 'Duration must be between 1 and 36 months.', 'tangnest-robotics' );
		}

		$default_fee = isset( $_POST['default_monthly_fee'] ) ? (float) wp_unslash( $_POST['default_monthly_fee'] ) : 0.0;
		if ( $default_fee < 0 ) {
			$errors[] = __( 'Monthly amount cannot be negative.', 'tangnest-robotics' );
		}

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Programs::STATUSES, true ) ? $_POST['status'] : 'active';

		$product_code = isset( $_POST['irembopay_product_code'] ) ? sanitize_text_field( wp_unslash( $_POST['irembopay_product_code'] ) ) : '';
		if ( '' !== $product_code && ! preg_match( '/^PC-[A-Za-z0-9]+$/', $product_code ) ) {
			$errors[] = __( 'IremboPay product code must look like PC- followed by letters/numbers.', 'tangnest-robotics' );
		}

		if ( 'active' === $status ) {
			if ( $default_fee <= 0 ) {
				$errors[] = __( 'Monthly amount is required for an active package — this is what a family on it is billed.', 'tangnest-robotics' );
			}
			if ( '' === $product_code ) {
				$errors[] = __( 'A product code is required for an active package — without one, families on it cannot pay online.', 'tangnest-robotics' );
			}
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

		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;

		if ( ! empty( $errors ) ) {
			set_transient( self::error_key(), $errors, MINUTE_IN_SECONDS );
			wp_safe_redirect( self::edit_url( $id ) );
			exit;
		}

		$data = [
			'name'                   => $name,
			'duration_months'        => $duration_months,
			'default_monthly_fee'    => $default_fee,
			'irembopay_product_code' => $product_code,
			'start_date'             => $start_date,
			'notes'                  => $notes,
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

	/**
	 * Archive and Delete, both nonce-protected GET row actions — Delete is
	 * re-checked here regardless of what the row action link was rendered
	 * for, same pattern as invoice/family deletion.
	 */
	public static function maybe_handle_row_actions(): void {
		if ( ! isset( $_GET['tr_row_action'], $_GET['id'], $_GET['page'] ) ) {
			return;
		}

		if ( self::PAGE !== $_GET['page'] ) {
			return;
		}

		$row_action    = sanitize_key( wp_unslash( $_GET['tr_row_action'] ) );
		$package_id    = absint( $_GET['id'] );
		$valid_actions = [ 'archive', 'delete' ];

		if ( ! in_array( $row_action, $valid_actions, true ) || $package_id <= 0 ) {
			return;
		}

		check_admin_referer( 'tr_package_row_action_' . $package_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$package = TR_Programs::get( $package_id );

		if ( 'archive' === $row_action ) {
			if ( null !== $package ) {
				TR_Programs::update( $package_id, [
					'name'                   => $package->name,
					'duration_months'        => $package->duration_months,
					'default_monthly_fee'    => $package->default_monthly_fee,
					'irembopay_product_code' => $package->irembopay_product_code,
					'start_date'             => $package->start_date,
					'notes'                  => $package->notes,
					'status'                 => 'inactive',
				] );
			}
			self::redirect_with_notice( 'package_archived' );
		}

		if ( 'delete' === $row_action ) {
			if ( null === $package ) {
				self::redirect_with_notice( 'package_delete_failed' );
			}

			$family_count = TR_Programs::family_count( $package_id );
			if ( $family_count > 0 ) {
				self::redirect_with_notice_count( 'package_delete_has_families', $family_count );
			}

			$admin = wp_get_current_user();
			TR_Logger::info( 'Package permanently deleted', [
				'package_id'   => $package->id,
				'name'         => $package->name,
				'product_code' => $package->irembopay_product_code,
				'deleted_by'   => $admin->user_login,
			] );

			TR_Programs::delete( $package_id );
			self::redirect_with_notice( 'package_deleted' );
		}
	}

	private static function redirect_with_notice( string $notice ): void {
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tr_notice' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function redirect_with_notice_count( string $notice, int $count ): void {
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tr_notice' => $notice, 'count' => $count ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function edit_url( int $id ): string {
		$args = [ 'page' => self::PAGE ];
		if ( $id > 0 ) {
			$args['edit'] = $id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function row_action_url( int $id, string $action ): string {
		return wp_nonce_url(
			add_query_arg( [ 'page' => self::PAGE, 'tr_row_action' => $action, 'id' => $id ], admin_url( 'admin.php' ) ),
			'tr_package_row_action_' . $id
		);
	}

	private static function error_key(): string {
		return 'tr_program_form_errors_' . get_current_user_id();
	}

	public static function render(): void {
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$program = $edit_id > 0 ? TR_Programs::get( $edit_id ) : null;

		$errors = get_transient( self::error_key() );
		if ( $errors ) {
			delete_transient( self::error_key() );
		}
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Packages', 'tangnest-robotics' ); ?></h1>

			<?php self::render_notices(); ?>

			<?php if ( ! empty( $errors ) && is_array( $errors ) ) : ?>
				<div class="notice notice-error">
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<h2><?php echo $program ? esc_html__( 'Edit Package', 'tangnest-robotics' ) : esc_html__( 'Add Package', 'tangnest-robotics' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tr_program_nonce' ); ?>
				<input type="hidden" name="program_id" value="<?php echo esc_attr( $program->id ?? 0 ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tr-name"><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-name" name="name" class="regular-text" required value="<?php echo esc_attr( $program->name ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-product-code"><?php esc_html_e( 'IremboPay product code', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="text" id="tr-product-code" name="irembopay_product_code" class="regular-text" placeholder="PC-XXXXXXXX" required value="<?php echo esc_attr( $program->irembopay_product_code ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'From the IremboPay merchant dashboard. Required for an active package.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-fee"><?php esc_html_e( 'Monthly amount (RWF)', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="number" id="tr-fee" name="default_monthly_fee" step="0.01" min="0" required value="<?php echo esc_attr( $program->default_monthly_fee ?? '0.00' ); ?>">
							<p class="description"><?php esc_html_e( 'The real price — this is what a family on this package is billed. Required for an active package.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-duration"><?php esc_html_e( 'Duration (months)', 'tangnest-robotics' ); ?></label></th>
						<td><input type="number" id="tr-duration" name="duration_months" min="1" max="36" required value="<?php echo esc_attr( $program->duration_months ?? 8 ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-notes"><?php esc_html_e( 'Notes', 'tangnest-robotics' ); ?></label></th>
						<td>
							<textarea id="tr-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $program->notes ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'For your own reference only — never shown to parents. E.g. what distinguishes this tier from another at the same price.', 'tangnest-robotics' ); ?></p>
						</td>
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
				<?php submit_button( $program ? __( 'Update Package', 'tangnest-robotics' ) : __( 'Add Package', 'tangnest-robotics' ) ); ?>
				<?php if ( $program ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'tangnest-robotics' ); ?></a>
				<?php endif; ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'All Packages', 'tangnest-robotics' ); ?></h2>
			<?php self::render_table(); ?>
		</div>
		<?php
	}

	private static function render_notices(): void {
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Package saved.', 'tangnest-robotics' ) . '</p></div>';
		}

		if ( ! isset( $_GET['tr_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['tr_notice'] ) );

		if ( 'package_delete_has_families' === $notice ) {
			$count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of families using this package */
					_n(
						'That package could not be deleted — %d family is currently on it. Archive it instead to keep it out of new selections.',
						'That package could not be deleted — %d families are currently on it. Archive it instead to keep it out of new selections.',
						$count,
						'tangnest-robotics'
					),
					$count
				) )
			);
			return;
		}

		$messages = [
			'package_archived'      => [ 'success', __( 'Package archived.', 'tangnest-robotics' ) ],
			'package_deleted'       => [ 'success', __( 'Package permanently deleted.', 'tangnest-robotics' ) ],
			'package_delete_failed' => [ 'error', __( 'That package could not be found.', 'tangnest-robotics' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $type, $text ] = $messages[ $notice ];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	private static function render_table(): void {
		$programs = TR_Programs::get_list( [ 'per_page' => 200 ] );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Product Code', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Monthly Amount', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Families', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $programs ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No packages yet.', 'tangnest-robotics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $programs as $program ) : ?>
						<?php
						$family_count  = TR_Programs::family_count( (int) $program->id );
						$missing_code  = 'active' === $program->status && empty( $program->irembopay_product_code );
						$missing_fee   = 'active' === $program->status && (float) $program->default_monthly_fee <= 0;
						?>
						<tr>
							<td><?php echo esc_html( $program->name ); ?></td>
							<td>
								<?php echo esc_html( $program->irembopay_product_code ?? '' ); ?>
								<?php if ( $missing_code ) : ?>
									<br><span class="tr-warning-flag"><?php esc_html_e( '⚠ No product code — online payment will not work.', 'tangnest-robotics' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $program->default_monthly_fee ); ?>
								<?php if ( $missing_fee ) : ?>
									<br><span class="tr-warning-flag"><?php esc_html_e( '⚠ No amount set.', 'tangnest-robotics' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $program->duration_months ); ?></td>
							<td><?php echo esc_html( wp_trim_words( (string) ( $program->notes ?? '' ), 8 ) ); ?></td>
							<td><?php echo esc_html( (string) $family_count ); ?></td>
							<td><?php echo esc_html( ucfirst( $program->status ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( self::edit_url( (int) $program->id ) ); ?>"><?php esc_html_e( 'Edit', 'tangnest-robotics' ); ?></a>
								<?php if ( 'active' === $program->status ) : ?>
									| <a href="<?php echo esc_url( self::row_action_url( (int) $program->id, 'archive' ) ); ?>"><?php esc_html_e( 'Archive', 'tangnest-robotics' ); ?></a>
								<?php endif; ?>
								|
								<a href="<?php echo esc_url( self::row_action_url( (int) $program->id, 'delete' ) ); ?>" onclick="return confirm('<?php echo esc_js( sprintf(
									/* translators: %s: package name */
									__( 'Permanently delete package "%s"? This cannot be undone.', 'tangnest-robotics' ),
									$program->name
								) ); ?>');"><?php esc_html_e( 'Delete', 'tangnest-robotics' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
