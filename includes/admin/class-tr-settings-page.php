<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Robotics → Settings: the dashboard page picker, the reminder schedule,
 * and a manual trigger for invoice generation (needed for testing, and for
 * the month cron inevitably doesn't fire).
 */
class TR_Settings_Page {
	const PAGE            = 'tangnest-robotics-settings';
	const NONCE           = 'tr_settings_save';
	const GENERATE_NONCE  = 'tr_generate_invoices_now';
	const REMINDERS_NONCE = 'tr_reminders_save';

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
		}
	}

	private static function handle_settings_save(): void {
		check_admin_referer( self::NONCE, 'tr_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$page_id = isset( $_POST['dashboard_page_id'] ) ? absint( $_POST['dashboard_page_id'] ) : 0;
		update_option( TR_Parent_Dashboard::OPTION_PAGE_ID, $page_id );

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

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$current_page_id = (int) get_option( TR_Parent_Dashboard::OPTION_PAGE_ID, 0 );
		$enabled_stages   = TR_Reminder_Scheduler::enabled_stages();
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
