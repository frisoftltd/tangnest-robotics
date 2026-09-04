<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Renders [tangnest_parent_dashboard] and owns the dashboard-page option.
 * Access control lives in templates/parent-dashboard.php and resolves the
 * family strictly from the logged-in user's session — never from a request
 * parameter — so one parent can never view another family by editing a URL.
 */
class TR_Parent_Dashboard {
	const OPTION_PAGE_ID = 'tangnest_robotics_dashboard_page_id';
	const SHORTCODE      = 'tangnest_parent_dashboard';

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
	}

	public static function get_url(): string {
		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id <= 0 ) {
			return '';
		}

		$permalink = get_permalink( $page_id );

		return $permalink ?: '';
	}

	public function maybe_enqueue_assets(): void {
		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id <= 0 || get_queried_object_id() !== $page_id ) {
			return;
		}

		wp_enqueue_style(
			'tangnest-robotics-dashboard',
			TANGNEST_ROBOTICS_PLUGIN_URL . 'assets/css/dashboard.css',
			[],
			TANGNEST_ROBOTICS_VERSION
		);
	}

	public function render_shortcode(): string {
		ob_start();
		include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/parent-dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Only redirects parents with a family record to their dashboard.
	 * Administrators and every other login on this (shared LMS) site are
	 * left completely untouched.
	 */
	public function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}

		if ( null === TR_Families::get_by_user( $user->ID ) ) {
			return $redirect_to;
		}

		$dashboard_url = self::get_url();

		return $dashboard_url ?: $redirect_to;
	}
}
