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
		// Fires on the request that follows a token redirect (the bare
		// dashboard URL) as well as every other dashboard view — this is
		// what distinguishes "cookie was never set" from "cookie was set
		// but not honoured on the next request".
		TR_Logger::debug( 'Dashboard shortcode rendering', [
			'is_user_logged_in' => is_user_logged_in(),
			'current_user_id'   => get_current_user_id(),
		] );

		$this->maybe_cleanup_stale_windows();

		// ?tr_pay={id} is only ever used to look up a candidate invoice —
		// TR_Payment::initiate() independently verifies it belongs to the
		// CURRENT session's family before anything renders. Logged-out
		// visitors fall through to the normal dashboard (its own login
		// form), never to the payment template.
		$payment_invoice_id = isset( $_GET['tr_pay'] ) ? absint( $_GET['tr_pay'] ) : 0;

		ob_start();

		if ( $payment_invoice_id > 0 && is_user_logged_in() ) {
			$payment_result = TR_Payment::initiate( $payment_invoice_id );
			include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/payment-page.php';
		} else {
			include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/parent-dashboard.php';
		}

		return ob_get_clean();
	}

	/**
	 * Purely cosmetic — carries no ID or data, grants no access. The
	 * webhook (or the reuse-check's defensive reconciliation) is the only
	 * source of truth for whether an invoice is actually paid; this just
	 * lets the dashboard show a friendly banner after IremboPay's
	 * onSuccess callback redirects back.
	 */
	public static function just_paid(): bool {
		return isset( $_GET['tr_paid'] );
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
	 * Link previewer/crawler User-Agent substrings. Messaging apps fetch a
	 * link's Open Graph tags server-side the moment a URL is pasted into a
	 * chat — before any human taps it — from their own infrastructure, on
	 * a different IP than the eventual real visit. Left unchecked, that
	 * fetch alone burns a device slot and can start the grace-window clock
	 * before the parent ever sees the message.
	 *
	 * These substrings alone are NOT sufficient to identify a previewer —
	 * WhatsApp's in-app browser (used by real parents tapping the link)
	 * also carries "WhatsApp/x.y.z" in its UA. What actually distinguishes
	 * a bare server-side fetch from a real browser is the absence of
	 * "Mozilla": every real browser, including in-app browsers, sends
	 * "Mozilla/5.0 (...)"; a preview fetcher sends a bare product token
	 * like "WhatsApp/2.23.20.0" with nothing else. See
	 * is_link_previewer_request().
	 */
	const PREVIEWER_USER_AGENTS = [ 'WhatsApp/', 'facebookexternalhit', 'Twitterbot', 'Slackbot', 'TelegramBot' ];

	/**
	 * Validates ?tr_access={token} before the shortcode renders and always
	 * redirects to the bare dashboard URL afterward — success or failure —
	 * so the token never lingers in the browser history or referrer
	 * headers, and a failed attempt looks identical to a first-time visit.
	 *
	 * Requests that look like link-preview fetchers are filtered out first
	 * and never reach the token at all — no lookup, no consumption, no
	 * redirect. They just fall through to the normal shortcode render,
	 * which shows the login form since a bot is never logged in.
	 */
	public function maybe_handle_access_token(): void {
		TR_Logger::debug( 'maybe_handle_access_token entered', [
			'tr_access_present' => isset( $_GET['tr_access'] ),
		] );

		if ( ! isset( $_GET['tr_access'] ) ) {
			return;
		}

		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id <= 0 || ! is_page( $page_id ) ) {
			return;
		}

		if ( self::is_link_previewer_request() ) {
			return;
		}

		$token = is_string( $_GET['tr_access'] ) ? sanitize_text_field( wp_unslash( $_GET['tr_access'] ) ) : '';

		// Shared family phones are common — a token link must sign in as
		// the family it belongs to, not whoever happened to be logged in.
		if ( is_user_logged_in() ) {
			wp_logout();
		}

		$user_id = TR_Access_Tokens::validate_and_consume( $token );

		TR_Logger::debug( 'validate_and_consume returned', [ 'user_id' => $user_id ] );

		if ( $user_id > 0 ) {
			set_transient( self::TOKEN_LOGIN_FLAG_PREFIX . $user_id, 1, HOUR_IN_SECONDS );
		} else {
			self::flag_dead_token_notice();
		}

		$redirect_url = self::get_url();
		TR_Logger::debug( 'maybe_handle_access_token redirecting', [ 'redirect_url' => $redirect_url ] );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * A request is a previewer only when it is HEAD, has no User-Agent at
	 * all, or has a User-Agent that both (a) lacks "Mozilla" and (b)
	 * matches one of the known crawler product tokens. Requiring the
	 * absence of "Mozilla" is what stops this from misfiring on WhatsApp's
	 * own in-app browser — a real human tap — which carries both "Mozilla/5.0"
	 * and "WhatsApp/x.y.z" in the same UA string; a bare server-side
	 * preview fetch never sends "Mozilla" at all.
	 */
	private static function is_link_previewer_request(): bool {
		$method     = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$is_previewer = false;

		if ( 'HEAD' === $method ) {
			$is_previewer = true;
		} elseif ( '' === $user_agent ) {
			$is_previewer = true;
		} elseif ( false === stripos( $user_agent, 'Mozilla' ) ) {
			foreach ( self::PREVIEWER_USER_AGENTS as $needle ) {
				if ( false !== stripos( $user_agent, $needle ) ) {
					$is_previewer = true;
					break;
				}
			}
		}

		if ( $is_previewer ) {
			TR_Logger::debug( 'Skipped link-previewer request', [ 'method' => $method, 'user_agent' => $user_agent ] );
		}

		return $is_previewer;
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

		// 60 seconds is a backstop only — the real guarantee is that
		// consume_dead_token_notice() deletes this the instant it's read,
		// so it can never outlive the page view that displays it. The TTL
		// just bounds how long an unread flag (nobody reloaded the page)
		// can sit in wp_options.
		set_transient( self::DEAD_TOKEN_FLAG_PREFIX . md5( $ip ), 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Shown-once flag for "this link is no longer active", tracked
	 * server-side by IP — never a URL parameter. Strictly one-shot: this
	 * deletes the flag the moment it's read, before the template renders
	 * anything with the result, so a single failure can never paint the
	 * banner on a later, unrelated page view (including a successful one,
	 * or one with no token at all).
	 */
	public static function consume_dead_token_notice(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return false;
		}

		$key     = self::DEAD_TOKEN_FLAG_PREFIX . md5( $ip );
		$flagged = (bool) get_transient( $key );

		delete_transient( $key );

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
