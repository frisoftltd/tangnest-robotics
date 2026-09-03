<?php
/**
 * Plugin Name:       Tangnest Robotics — Class & Payment Manager
 * Plugin URI:        https://github.com/frisoftltd/tangnest-robotics
 * Description:       Manages robotics class enrollment, family billing, and IremboPay payments for Tangnest. Standalone — does not require WooCommerce or Tutor LMS.
 * Version:           0.1.0
 * Author:            Fri Soft Ltd
 * Author URI:        https://frisoft.rw
 * License:           GPL-2.0-or-later
 * Text Domain:       tangnest-robotics
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TANGNEST_ROBOTICS_VERSION',     '0.1.0' );
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
	}

	private function hooks(): void {
		// Deployment copies files directly and does not fire activation hooks,
		// so the schema must also be kept current via a version check on every load.
		add_action( 'plugins_loaded', [ $this, 'maybe_upgrade' ], 5 );
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	public function maybe_upgrade(): void {
		TR_DB::maybe_upgrade();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'tangnest-robotics', false, dirname( plugin_basename( TANGNEST_ROBOTICS_PLUGIN_FILE ) ) . '/languages' );
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
