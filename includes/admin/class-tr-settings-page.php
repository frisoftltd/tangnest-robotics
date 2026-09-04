<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Robotics → Settings: currently just the dashboard page picker.
 */
class TR_Settings_Page {
	const PAGE  = 'tangnest-robotics-settings';
	const NONCE = 'tr_settings_save';

	public static function maybe_handle_submit(): void {
		if ( ! isset( $_POST['tr_settings_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'tr_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$page_id = isset( $_POST['dashboard_page_id'] ) ? absint( $_POST['dashboard_page_id'] ) : 0;
		update_option( TR_Parent_Dashboard::OPTION_PAGE_ID, $page_id );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$current_page_id = (int) get_option( TR_Parent_Dashboard::OPTION_PAGE_ID, 0 );
		?>
		<div class="wrap tr-admin-wrap">
			<h1><?php esc_html_e( 'Robotics Settings', 'tangnest-robotics' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'tangnest-robotics' ); ?></p></div>
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
		</div>
		<?php
	}
}
