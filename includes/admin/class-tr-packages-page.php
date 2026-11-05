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

		// v0.10.0: duration moves to the family and is now only a default
		// for a new one — an active package no longer requires it. Blank
		// means "no default", not zero months.
		$duration_raw    = isset( $_POST['duration_months'] ) ? trim( wp_unslash( $_POST['duration_months'] ) ) : '';
		$duration_months = '' !== $duration_raw ? absint( $duration_raw ) : 0;
		if ( $duration_months > 36 ) {
			$errors[] = __( 'Duration must be 36 months or fewer.', 'tangnest-robotics' );
		}

		// Legacy (v0.10.0) — no longer read for billing, kept only so
		// existing data isn't lost. Never required.
		$default_fee = isset( $_POST['default_monthly_fee'] ) ? (float) wp_unslash( $_POST['default_monthly_fee'] ) : 0.0;
		if ( $default_fee < 0 ) {
			$errors[] = __( 'Monthly amount cannot be negative.', 'tangnest-robotics' );
		}

		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], TR_Programs::STATUSES, true ) ? $_POST['status'] : 'active';

		$product_code = isset( $_POST['irembopay_product_code'] ) ? sanitize_text_field( wp_unslash( $_POST['irembopay_product_code'] ) ) : '';
		if ( '' !== $product_code && ! preg_match( '/^PC-[A-Za-z0-9]+$/', $product_code ) ) {
			$errors[] = __( 'IremboPay product code must look like PC- followed by letters/numbers.', 'tangnest-robotics' );
		}

		// course/child_count are v0.10.0 labels only (no longer used to
		// match a package automatically) — neither is required any more.
		$course      = isset( $_POST['course'] ) ? sanitize_text_field( wp_unslash( $_POST['course'] ) ) : '';
		$child_count = isset( $_POST['child_count'] ) ? absint( $_POST['child_count'] ) : 0;

		$price_fields = [];
		foreach ( [ 'price_1_child', 'price_2_children', 'price_3_children', 'price_additional_child' ] as $field ) {
			$raw = isset( $_POST[ $field ] ) ? trim( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( '' === $raw ) {
				$price_fields[ $field ] = null;
				continue;
			}
			if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
				$errors[] = __( 'Prices must be blank or a non-negative number.', 'tangnest-robotics' );
				$price_fields[ $field ] = null;
				continue;
			}
			$price_fields[ $field ] = (float) $raw;
		}

		if ( 'active' === $status && '' === $product_code ) {
			$errors[] = __( 'A product code is required for an active package — without one, families on it cannot pay online.', 'tangnest-robotics' );
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

		$data = array_merge( [
			'name'                   => $name,
			'duration_months'        => $duration_months,
			'default_monthly_fee'    => $default_fee,
			'irembopay_product_code' => $product_code,
			'course'                 => $course,
			'child_count'            => $child_count,
			'start_date'             => $start_date,
			'notes'                  => $notes,
			'status'                 => $status,
		], $price_fields );

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
					'course'                 => $package->course,
					'child_count'            => $package->child_count,
					'price_1_child'          => $package->price_1_child,
					'price_2_children'       => $package->price_2_children,
					'price_3_children'       => $package->price_3_children,
					'price_additional_child' => $package->price_additional_child,
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

		// A brand new package suggests the common 8-month length; an
		// existing one with no duration set shows blank, not a false 0.
		$duration_display = $program ? ( (int) $program->duration_months > 0 ? (int) $program->duration_months : '' ) : 8;

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
						<th><label for="tr-course"><?php esc_html_e( 'Course', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="text" id="tr-course" name="course" class="regular-text" value="<?php echo esc_attr( $program->course ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'A label only — grouping for your own reference. No longer used to match a family to a package automatically.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-child-count"><?php esc_html_e( 'Typical number of children', 'tangnest-robotics' ); ?></label></th>
						<td>
							<select id="tr-child-count" name="child_count">
								<option value=""><?php esc_html_e( '— Select —', 'tangnest-robotics' ); ?></option>
								<?php foreach ( [ 1, 2, 3 ] as $n ) : ?>
									<option value="<?php echo esc_attr( $n ); ?>" <?php selected( (int) ( $program->child_count ?? 0 ), $n ); ?>><?php echo esc_html( $n ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'A label only — this package now carries a full price table below and covers any number of children.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-product-code"><?php esc_html_e( 'IremboPay product code', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="text" id="tr-product-code" name="irembopay_product_code" class="regular-text" placeholder="PC-XXXXXXXX" required value="<?php echo esc_attr( $program->irembopay_product_code ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'From the IremboPay merchant dashboard. Required for an active package — IremboPay accepts any amount against a valid code (confirmed v0.9.4), but still needs the code itself.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Monthly price by number of children', 'tangnest-robotics' ); ?></th>
						<td>
							<table class="form-table" role="presentation" style="margin:0;">
								<tr>
									<th style="padding-left:0;"><label for="tr-price-1"><?php esc_html_e( '1 child (RWF)', 'tangnest-robotics' ); ?></label></th>
									<td><input type="number" id="tr-price-1" name="price_1_child" step="0.01" min="0" value="<?php echo esc_attr( $program->price_1_child ?? '' ); ?>"></td>
								</tr>
								<tr>
									<th style="padding-left:0;"><label for="tr-price-2"><?php esc_html_e( '2 children (RWF)', 'tangnest-robotics' ); ?></label></th>
									<td><input type="number" id="tr-price-2" name="price_2_children" step="0.01" min="0" value="<?php echo esc_attr( $program->price_2_children ?? '' ); ?>"></td>
								</tr>
								<tr>
									<th style="padding-left:0;"><label for="tr-price-3"><?php esc_html_e( '3 children (RWF)', 'tangnest-robotics' ); ?></label></th>
									<td><input type="number" id="tr-price-3" name="price_3_children" step="0.01" min="0" value="<?php echo esc_attr( $program->price_3_children ?? '' ); ?>"></td>
								</tr>
								<tr>
									<th style="padding-left:0;"><label for="tr-price-4"><?php esc_html_e( 'Each additional child (RWF)', 'tangnest-robotics' ); ?></label></th>
									<td><input type="number" id="tr-price-4" name="price_additional_child" step="0.01" min="0" value="<?php echo esc_attr( $program->price_additional_child ?? '' ); ?>"></td>
								</tr>
							</table>
							<p class="description"><?php esc_html_e( 'Leave any of these blank if that count doesn\'t apply yet — a family needing an unset count cannot be saved until it\'s filled in here. "Each additional child" is added per child beyond 3, not a flat cap.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-fee"><?php esc_html_e( 'Legacy monthly amount (RWF)', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="number" id="tr-fee" name="default_monthly_fee" step="0.01" min="0" value="<?php echo esc_attr( $program->default_monthly_fee ?? '0.00' ); ?>">
							<p class="description"><?php esc_html_e( 'No longer read for billing (v0.10.0) — kept only so old data isn\'t lost. Use the price table above.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-duration"><?php esc_html_e( 'Default duration (months)', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="number" id="tr-duration" name="duration_months" min="0" max="36" value="<?php echo esc_attr( $duration_display ); ?>">
							<p class="description"><?php esc_html_e( 'Optional (v0.10.0) — only used to default a new family\'s end date when they start. A family\'s own programme dates are authoritative and can run for a different length. Leave blank if this course has no fixed length.', 'tangnest-robotics' ); ?></p>
						</td>
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
					<th><?php esc_html_e( 'Course', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Product Code', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Price (1 / 2 / 3 / +each)', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Default Duration', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Families', 'tangnest-robotics' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tangnest-robotics' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $programs ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No packages yet.', 'tangnest-robotics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $programs as $program ) : ?>
						<?php
						$family_count  = TR_Programs::family_count( (int) $program->id );
						$missing_code  = 'active' === $program->status && empty( $program->irembopay_product_code );
						$missing_prices = 'active' === $program->status ? TR_Programs::missing_price_labels( $program ) : [];
						?>
						<tr>
							<td><?php echo esc_html( $program->name ); ?></td>
							<td><?php echo esc_html( $program->course ?? '' ); ?></td>
							<td>
								<?php echo esc_html( $program->irembopay_product_code ?? '' ); ?>
								<?php if ( $missing_code ) : ?>
									<br><span class="tr-warning-flag"><?php esc_html_e( '⚠ No product code — online payment will not work.', 'tangnest-robotics' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( TR_Programs::price_table_summary( $program ) ); ?>
								<?php if ( ! empty( $missing_prices ) ) : ?>
									<br><span class="tr-warning-flag">
										<?php
										printf(
											/* translators: %s: comma-separated list of missing counts */
											esc_html__( '⚠ Incomplete pricing — missing %s.', 'tangnest-robotics' ),
											esc_html( implode( ', ', $missing_prices ) )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( TR_Programs::has_duration( $program ) ? (string) (int) $program->duration_months : '—' ); ?></td>
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
