<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Renders [tangnest_parent_dashboard], owns the dashboard-page option, and
 * handles device-bound passwordless access links (?tr_access=...). Access
 * control lives in templates/parent-dashboard.php and resolves the family
 * strictly from the logged-in user's session — never from a request
 * parameter — so one parent can never view another family by editing a URL.
 */
class TR_Parent_Dashboard {
	const OPTION_PAGE_ID = 'tangnest_robotics_dashboard_page_id';
	const SHORTCODE      = 'tangnest_parent_dashboard';

	const TOKEN_LOGIN_FLAG_PREFIX = 'tr_access_notice_';
	const DEAD_TOKEN_FLAG_PREFIX  = 'tr_access_dead_';
	const CLEANUP_THROTTLE_KEY    = 'tr_access_cleanup_ran';

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
		add_action( 'template_redirect', [ $this, 'maybe_handle_access_token' ] );
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
		$this->maybe_cleanup_stale_windows();

		ob_start();
		include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/parent-dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Belt-and-suspenders cleanup only — request-time validation in
	 * TR_Access_Tokens::validate_and_consume() is what actually enforces
	 * the grace window. This just keeps the admin status column from
	 * showing a stale "Active" after the window has passed, without
	 * requiring wp-cron (a later milestone). Throttled to once an hour so
	 * a busy dashboard page doesn't run the sweep on every view.
	 */
	private function maybe_cleanup_stale_windows(): void {
		if ( get_transient( self::CLEANUP_THROTTLE_KEY ) ) {
			return;
		}

		set_transient( self::CLEANUP_THROTTLE_KEY, 1, HOUR_IN_SECONDS );
		TR_Access_Tokens::close_stale_windows();
	}

	/**
	 * Validates ?tr_access={token} before the shortcode renders and always
	 * redirects to the bare dashboard URL afterward — success or failure —
	 * so the token never lingers in the browser history or referrer
	 * headers, and a failed attempt looks identical to a first-time visit.
	 */
	public function maybe_handle_access_token(): void {
		if ( ! isset( $_GET['tr_access'] ) ) {
			return;
		}

		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id <= 0 || ! is_page( $page_id ) ) {
			return;
		}

		$token = is_string( $_GET['tr_access'] ) ? sanitize_text_field( wp_unslash( $_GET['tr_access'] ) ) : '';

		// Shared family phones are common — a token link must sign in as
		// the family it belongs to, not whoever happened to be logged in.
		if ( is_user_logged_in() ) {
			wp_logout();
		}

		$user_id = TR_Access_Tokens::validate_and_consume( $token );

		if ( $user_id > 0 ) {
			set_transient( self::TOKEN_LOGIN_FLAG_PREFIX . $user_id, 1, HOUR_IN_SECONDS );
		} else {
			self::flag_dead_token_notice();
		}

		wp_safe_redirect( self::get_url() );
		exit;
	}

	/**
	 * Shown-once flag for "you're signed in via your private link", tracked
	 * server-side by user ID — never a URL parameter.
	 */
	public static function consume_token_login_notice( int $user_id ): bool {
		$key     = self::TOKEN_LOGIN_FLAG_PREFIX . $user_id;
		$flagged = (bool) get_transient( $key );

		if ( $flagged ) {
			delete_transient( $key );
		}

		return $flagged;
	}

	private static function flag_dead_token_notice(): void {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return;
		}

		set_transient( self::DEAD_TOKEN_FLAG_PREFIX . md5( $ip ), 1, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Shown-once flag for "this link is no longer active", tracked
	 * server-side by IP for the two minutes after the failed redirect —
	 * never a URL parameter.
	 */
	public static function consume_dead_token_notice(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return false;
		}

		$key     = self::DEAD_TOKEN_FLAG_PREFIX . md5( $ip );
		$flagged = (bool) get_transient( $key );

		if ( $flagged ) {
			delete_transient( $key );
		}

		return $flagged;
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
