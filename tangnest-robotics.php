<?php
/**
 * Plugin Name:       Tangnest Robotics — Class & Payment Manager
 * Plugin URI:        https://github.com/frisoftltd/tangnest-robotics
 * Description:       Manages robotics class enrollment, family billing, and IremboPay payments for Tangnest. Standalone — does not require WooCommerce or Tutor LMS.
 * Version:           0.3.0
 * Author:            Fri Soft Ltd
 * Author URI:        https://frisoft.rw
 * License:           GPL-2.0-or-later
 * Text Domain:       tangnest-robotics
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TANGNEST_ROBOTICS_VERSION',     '0.3.0' );
define( 'TANGNEST_ROBOTICS_DB_VERSION',  '0.1.0' );
define( 'TANGNEST_ROBOTICS_PLUGIN_FILE', __FILE__ );
define( 'TANGNEST_ROBOTICS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TANGNEST_ROBOTICS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

final class Tangnest_Robotics {
	private static ?Tangnest_Robotics $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->hooks();
	}

	private function includes(): void {
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-logger.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-db.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-github-updater.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-programs.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-families.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-students.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-enrollments.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-notifications.php';
		require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-parent-dashboard.php';

		if ( is_admin() ) {
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-admin-menu.php';
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-programs-page.php';
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-families-table.php';
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-family-edit.php';
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-students-table.php';
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-student-edit.php';
			require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/admin/class-tr-settings-page.php';
		}
	}

	private function hooks(): void {
		// Deployment copies files directly and does not fire activation hooks,
		// so the schema must also be kept current via a version check on every load.
		add_action( 'plugins_loaded', [ $this, 'maybe_upgrade' ], 5 );
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'admin_init', [ $this, 'admin_init' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( TANGNEST_ROBOTICS_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );

		new TR_Parent_Dashboard();

		if ( is_admin() ) {
			new TR_Admin_Menu();
		}
	}

	public function maybe_upgrade(): void {
		TR_DB::maybe_upgrade();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'tangnest-robotics', false, dirname( plugin_basename( TANGNEST_ROBOTICS_PLUGIN_FILE ) ) . '/languages' );
	}

	public function admin_init(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->maybe_handle_check_for_updates();

		$token = defined( 'TANGNEST_ROBOTICS_GITHUB_TOKEN' ) ? TANGNEST_ROBOTICS_GITHUB_TOKEN : '';
		new TR_GitHub_Updater(
			TANGNEST_ROBOTICS_PLUGIN_FILE,
			'frisoftltd',
			'tangnest-robotics',
			$token
		);
	}

	private function maybe_handle_check_for_updates(): void {
		if ( ! isset( $_GET['tr-check-for-updates'] ) ) {
			return;
		}

		check_admin_referer( 'tr_check_for_updates' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		delete_site_transient( 'update_plugins' );
		delete_site_transient( TR_GitHub_Updater::RELEASE_TRANSIENT );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public function plugin_action_links( array $links ): array {
		$url = wp_nonce_url(
			add_query_arg( 'tr-check-for-updates', '1', admin_url( 'plugins.php' ) ),
			'tr_check_for_updates'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for Updates', 'tangnest-robotics' ) . '</a>';

		return $links;
	}
}

function tangnest_robotics(): Tangnest_Robotics {
	return Tangnest_Robotics::instance();
}
tangnest_robotics();

register_activation_hook( TANGNEST_ROBOTICS_PLUGIN_FILE, function() {
	require_once TANGNEST_ROBOTICS_PLUGIN_DIR . 'includes/class-tr-db.php';
	TR_DB::create_tables();
	update_option( TR_DB::DB_VERSION_OPTION, TANGNEST_ROBOTICS_DB_VERSION );
} );
