<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Robotics → Settings: the dashboard page picker, the reminder schedule,
 * and a manual trigger for invoice generation (needed for testing, and for
 * the month cron inevitably doesn't fire).
 */
class TR_Settings_Page {
	const PAGE             = 'tangnest-robotics-settings';
	const NONCE            = 'tr_settings_save';
	const GENERATE_NONCE   = 'tr_generate_invoices_now';
	const REMINDERS_NONCE  = 'tr_reminders_save';
	const IREMBOPAY_NONCE  = 'tr_irembopay_save';

	public static function maybe_handle_submit(): void {
		if ( isset( $_POST['tr_settings_nonce'] ) ) {
			self::handle_settings_save();
			return;
		}

		if ( isset( $_POST['tr_generate_invoices_nonce'] ) ) {
			self::handle_generate_now();
			return;
		}

		if ( isset( $_POST['tr_reminders_nonce'] ) ) {
			self::handle_reminders_save();
			return;
		}

		if ( isset( $_POST['tr_irembopay_nonce'] ) ) {
			self::handle_irembopay_save();
		}
	}

	private static function handle_settings_save(): void {
		check_admin_referer( self::NONCE, 'tr_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$page_id = isset( $_POST['dashboard_page_id'] ) ? absint( $_POST['dashboard_page_id'] ) : 0;
		update_option( TR_Parent_Dashboard::OPTION_PAGE_ID, $page_id );
		update_option( TR_Logger::DEBUG_OPTION, ! empty( $_POST['debug_logging'] ) );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function handle_generate_now(): void {
		check_admin_referer( self::GENERATE_NONCE, 'tr_generate_invoices_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		TR_Invoice_Generator::run();

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'generated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function handle_reminders_save(): void {
		check_admin_referer( self::REMINDERS_NONCE, 'tr_reminders_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$selected = isset( $_POST['reminder_stages'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['reminder_stages'] ) ) : [];

		$stages = [];
		foreach ( array_keys( TR_Reminder_Scheduler::STAGES ) as $stage_key ) {
			$stages[ $stage_key ] = in_array( $stage_key, $selected, true );
		}

		update_option( TR_Reminder_Scheduler::OPTION_ENABLED_STAGES, $stages );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'reminders_updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Secret-key fields are rendered empty (never pre-filled with the real
	 * value — only a placeholder showing the last 4 characters), so a blank
	 * submission means "keep the existing key", not "clear it".
	 */
	private static function handle_irembopay_save(): void {
		check_admin_referer( self::IREMBOPAY_NONCE, 'tr_irembopay_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$existing = TR_IremboPay_Settings::get();

		$new_secret         = isset( $_POST['irembopay_secret_key'] ) ? trim( wp_unslash( $_POST['irembopay_secret_key'] ) ) : '';
		$new_webhook_secret = isset( $_POST['irembopay_webhook_secret'] ) ? trim( wp_unslash( $_POST['irembopay_webhook_secret'] ) ) : '';

		$product_code = isset( $_POST['irembopay_default_product_code'] ) ? sanitize_text_field( wp_unslash( $_POST['irembopay_default_product_code'] ) ) : '';
		if ( '' !== $product_code && ! preg_match( '/^PC-[A-Za-z0-9]+$/', $product_code ) ) {
			set_transient( self::error_key(), [ __( 'Default product code must look like PC- followed by letters/numbers.', 'tangnest-robotics' ) ], MINUTE_IN_SECONDS );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}

		$expiry_hours = isset( $_POST['irembopay_expiry_hours'] ) ? absint( $_POST['irembopay_expiry_hours'] ) : $existing['expiry_hours'];

		TR_IremboPay_Settings::save( [
			'enabled'                    => ! empty( $_POST['irembopay_enabled'] ),
			'secret_key'                 => '' !== $new_secret ? $new_secret : $existing['secret_key'],
			'public_key'                 => isset( $_POST['irembopay_public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['irembopay_public_key'] ) ) : $existing['public_key'],
			'payment_account_identifier' => isset( $_POST['irembopay_account_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['irembopay_account_identifier'] ) ) : $existing['payment_account_identifier'],
			'default_product_code'       => $product_code,
			'expiry_hours'               => $expiry_hours > 0 ? $expiry_hours : 24,
			'webhook_secret'             => '' !== $new_webhook_secret ? $new_webhook_secret : $existing['webhook_secret'],
		] );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'irembopay_updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function error_key(): string {
		return 'tr_irembopay_errors_' . get_current_user_id();
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$current_page_id = (int) get_option( TR_Parent_Dashboard::OPTION_PAGE_ID, 0 );
		$enabled_stages   = TR_Reminder_Scheduler::enabled_stages();
		$irembopay        = TR_IremboPay_Settings::get();

		$irembopay_errors = get_transient( self::error_key() );
		if ( $irembopay_errors ) {
			delete_transient( self::error_key() );
		}
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Robotics Settings', 'tangnest-robotics' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Invoice generation run complete. Check the Invoices screen and the plugin log for details.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['reminders_updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Reminder schedule saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['irembopay_updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'IremboPay settings saved.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $irembopay_errors ) ) : ?>
				<div class="notice notice-error"><ul><?php foreach ( $irembopay_errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<?php if ( ! empty( $irembopay['enabled'] ) && '' === $irembopay['default_product_code'] && TR_Programs::has_active_without_product_code() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Online payments are enabled, there is no default product code set, and at least one active program has no product code of its own. Parents on those programs will see "Could not start the payment" when they try to pay. Set a default below, or a code on each program.', 'tangnest-robotics' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tr_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="tr-dashboard-page"><?php esc_html_e( 'Parent dashboard page', 'tangnest-robotics' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages( [
								'name'              => 'dashboard_page_id',
								'id'                => 'tr-dashboard-page',
								'selected'          => $current_page_id,
								'show_option_none'  => __( '— Select page —', 'tangnest-robotics' ),
								'option_none_value' => 0,
								'post_status'       => 'publish',
							] );
							?>
							<p class="description"><?php esc_html_e( 'The page containing the [tangnest_parent_dashboard] shortcode.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Debug logging', 'tangnest-robotics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="debug_logging" value="1" <?php checked( TR_Logger::debug_enabled() ); ?>>
								<?php esc_html_e( 'Write verbose debug lines to the plugin log', 'tangnest-robotics' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. Info, warning and error lines are always written regardless of this setting — this only controls the high-volume debug ones. Turn on temporarily while diagnosing an issue, then off again.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'tangnest-robotics' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Payment Reminders', 'tangnest-robotics' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::REMINDERS_NONCE, 'tr_reminders_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Automatic reminders', 'tangnest-robotics' ); ?></th>
						<td>
							<?php foreach ( TR_Reminder_Scheduler::STAGE_LABELS as $stage_key => $label ) : ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="reminder_stages[]" value="<?php echo esc_attr( $stage_key ); ?>" <?php checked( ! empty( $enabled_stages[ $stage_key ] ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Each stage fires at most once per invoice, whether or not it is checked here at the time the invoice was created. Turning a stage off stops it going forward — it does not un-send anything already sent.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Reminder Settings', 'tangnest-robotics' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'IremboPay', 'tangnest-robotics' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::IREMBOPAY_NONCE, 'tr_irembopay_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Enable online payments', 'tangnest-robotics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="irembopay_enabled" value="1" <?php checked( ! empty( $irembopay['enabled'] ) ); ?>>
								<?php esc_html_e( 'Show Pay now buttons on the dashboard and in invoice/reminder emails', 'tangnest-robotics' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When off, no Pay button appears anywhere — manual recording (cash, bank, mobile money) is unaffected.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-secret"><?php esc_html_e( 'Secret key', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="password" id="tr-irembopay-secret" name="irembopay_secret_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $irembopay['secret_key'] ? TR_IremboPay_Settings::masked_secret_key() : __( 'Not set', 'tangnest-robotics' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Never displayed again in full once saved. Leave blank to keep the current key.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-public"><?php esc_html_e( 'Public key', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-irembopay-public" name="irembopay_public_key" class="regular-text" value="<?php echo esc_attr( $irembopay['public_key'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-account"><?php esc_html_e( 'Payment account identifier', 'tangnest-robotics' ); ?></label></th>
						<td><input type="text" id="tr-irembopay-account" name="irembopay_account_identifier" class="regular-text" value="<?php echo esc_attr( $irembopay['payment_account_identifier'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-product"><?php esc_html_e( 'Default product code', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="text" id="tr-irembopay-product" name="irembopay_default_product_code" class="regular-text" placeholder="PC-XXXXXXXX" value="<?php echo esc_attr( $irembopay['default_product_code'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used when a program has no IremboPay product code of its own.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-expiry"><?php esc_html_e( 'Invoice expiry (hours)', 'tangnest-robotics' ); ?></label></th>
						<td><input type="number" id="tr-irembopay-expiry" name="irembopay_expiry_hours" min="1" value="<?php echo esc_attr( $irembopay['expiry_hours'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-webhook-url"><?php esc_html_e( 'Webhook URL', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="text" id="tr-irembopay-webhook-url" class="regular-text" readonly value="<?php echo esc_attr( TR_IremboPay_Settings::webhook_url() ); ?>" onclick="this.select();">
							<p class="description"><?php esc_html_e( 'Paste this into the IremboPay merchant dashboard.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="tr-irembopay-webhook-secret"><?php esc_html_e( 'Webhook secret (optional)', 'tangnest-robotics' ); ?></label></th>
						<td>
							<input type="password" id="tr-irembopay-webhook-secret" name="irembopay_webhook_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $irembopay['webhook_secret'] ? TR_IremboPay_Settings::masked_webhook_secret() : __( 'Not set', 'tangnest-robotics' ) ); ?>">
							<p class="description"><?php esc_html_e( 'If set, incoming webhooks must carry a matching signature or they are rejected.', 'tangnest-robotics' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save IremboPay Settings', 'tangnest-robotics' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Invoices', 'tangnest-robotics' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::GENERATE_NONCE, 'tr_generate_invoices_nonce' ); ?>
				<p><?php esc_html_e( 'Runs the same daily job that checks every active family\'s billing day and creates any invoices that are due today. Safe to run more than once — an invoice already created for the current period is never duplicated.', 'tangnest-robotics' ); ?></p>
				<?php submit_button( __( 'Generate Invoices Now', 'tangnest-robotics' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
