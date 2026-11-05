<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Renders [tangnest_parent_dashboard], owns the dashboard-page option, and
 * handles device-bound passwordless access links. Access control lives in
 * templates/parent-dashboard.php and resolves the family strictly from the
 * logged-in user's session — never from a request parameter — so one
 * parent can never view another family by editing a URL.
 *
 * v0.10.4 shortens the link itself: /p/<22-char-token> instead of
 * ?tr_access=<64-char-hex-token> on the full dashboard URL (125 characters
 * down to roughly 48). The old ?tr_access= form still works — see
 * build_access_url()'s plain-permalink fallback and
 * maybe_handle_access_token()'s dual $_GET/query-var read — since links
 * already sitting in a parent's inbox must keep working indefinitely.
 */
class TR_Parent_Dashboard {
	const OPTION_PAGE_ID = 'tangnest_robotics_dashboard_page_id';
	const SHORTCODE      = 'tangnest_parent_dashboard';

	const TOKEN_LOGIN_FLAG_PREFIX = 'tr_access_notice_';
	const DEAD_TOKEN_FLAG_PREFIX  = 'tr_access_dead_';
	const CLEANUP_THROTTLE_KEY    = 'tr_access_cleanup_ran';

	const SHORT_LINK_BASE_OPTION    = 'tangnest_robotics_short_link_base';
	const REWRITE_VERSION_OPTION    = 'tangnest_robotics_rewrite_version';
	const SHORT_TOKEN_PATTERN       = '[A-Za-z0-9_-]{22}';

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
		add_action( 'template_redirect', [ $this, 'maybe_handle_access_token' ] );
		add_action( 'after_password_reset', [ $this, 'maybe_auto_login_after_reset' ], 10, 2 );
		add_action( 'login_init', [ $this, 'maybe_handle_reset_link_fallback' ] );

		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'init', [ $this, 'register_short_link_rewrite' ] );
	}

	public static function get_url(): string {
		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id <= 0 ) {
			return '';
		}

		$permalink = get_permalink( $page_id );

		return $permalink ?: '';
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'tr_access';
		return $vars;
	}

	/**
	 * The one place a raw access/message token is actually generated
	 * (v0.10.4) — 16 bytes of CSPRNG output, base64url-encoded, giving a
	 * fixed 22-character, URL-safe string with 128 bits of entropy. Never
	 * fewer than 16 bytes: this is a bearer credential, not a display ID.
	 * TR_Access_Tokens::generate() and TR_Message_Tokens::generate() both
	 * call this rather than each rolling their own, so the two can never
	 * quietly drift onto different byte counts or encodings.
	 */
	public static function generate_raw_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Registers `/<base>/<22-char-token>` → the dashboard page with
	 * tr_access set, where <base> is "p" unless something else already
	 * claims that (see short_link_base()). Flushes rewrite rules once per
	 * plugin-version transition, same mechanism TR_DB::maybe_upgrade() uses
	 * for schema changes — a file-copy deploy never fires the activation
	 * hook, so a version check on every load is what actually catches it.
	 * Flushing is deliberately NOT unconditional: it writes .htaccess and
	 * rebuilds the full rewrite_rules option, which is too expensive to do
	 * on every page load.
	 */
	public static function register_short_link_rewrite(): void {
		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		$slug    = $page_id > 0 ? get_post_field( 'post_name', $page_id ) : '';

		if ( '' === $slug ) {
			// No dashboard page configured yet — nothing to route to. The
			// version option is deliberately left untouched so the rule
			// gets registered (and rules flushed) the moment a page IS
			// configured, instead of being permanently skipped because
			// this ran once before that happened.
			return;
		}

		add_rewrite_rule(
			'^' . self::short_link_base() . '/(' . self::SHORT_TOKEN_PATTERN . ')/?$',
			'index.php?pagename=' . $slug . '&tr_access=$matches[1]',
			'top'
		);

		self::maybe_flush_rewrite_rules();
	}

	/**
	 * Decided exactly once, the first time this plugin ever registers its
	 * short-link rewrite rule, by inspecting the rewrite_rules option as it
	 * stands at that moment — i.e. before our own rule has ever been
	 * flushed into it, so what's already there genuinely reflects another
	 * plugin or the theme, never a leftover copy of our own rule from a
	 * previous flush. The result is cached in its own option permanently,
	 * so a later request can't "rediscover" a conflict that's actually
	 * just our own rule from last time.
	 */
	public static function short_link_base(): string {
		$base = get_option( self::SHORT_LINK_BASE_OPTION, '' );
		if ( '' !== $base ) {
			return $base;
		}

		$conflict = false;
		$existing = get_option( 'rewrite_rules' );
		if ( is_array( $existing ) ) {
			foreach ( $existing as $pattern => $query ) {
				if ( 1 === preg_match( '#^\^?p/#', $pattern ) ) {
					$conflict = true;
					break;
				}
			}
		}

		$base = $conflict ? 'go' : 'p';
		update_option( self::SHORT_LINK_BASE_OPTION, $base, true );

		if ( $conflict ) {
			TR_Logger::warning( 'Short access-link base "/p/" conflicts with an existing rewrite rule — using "/go/" instead.' );
		} else {
			TR_Logger::info( 'Short access-link base set to "/p/" — no conflicting rewrite rule found.' );
		}

		return $base;
	}

	/**
	 * Public + static so the activation hook (which never instantiates
	 * this class — see tangnest-robotics.php) can call it directly, as
	 * well as the instance-level init hook above. Both paths funnel
	 * through the exact same version check, so there is only one place
	 * that decides "has this transition already been flushed".
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::REWRITE_VERSION_OPTION ) === TANGNEST_ROBOTICS_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, TANGNEST_ROBOTICS_VERSION, false );
	}

	/**
	 * The single place every access/message link is built (v0.10.4) —
	 * TR_Access_Tokens::build_url(), TR_Message_Tokens::build_url() and
	 * the Pay-now-with-token link all delegate here, so there is exactly
	 * one chokepoint to get right rather than N copies that could drift.
	 * Falls back to the long ?tr_access= form when this site has no
	 * pretty-permalink structure at all — a plain-permalink site never
	 * writes mod_rewrite rules to .htaccess regardless of what this plugin
	 * registers, so the short path could never actually be reached there.
	 */
	public static function build_access_url( string $token ): string {
		$dashboard_url = self::get_url();
		if ( '' === $dashboard_url ) {
			return '';
		}

		if ( '' === get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'tr_access', $token, $dashboard_url );
		}

		return home_url( '/' . self::short_link_base() . '/' . $token );
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
	 * The token, from whichever source actually carries it (v0.10.4). A
	 * `/<base>/<token>` request never puts it in $_GET — a matched
	 * rewrite query var only ever reaches get_query_var(), never the
	 * superglobal — while the legacy/plain-permalink `?tr_access=` form
	 * only ever reaches $_GET. Checking both is what lets either link
	 * format keep working regardless of which one a given site's
	 * permalink structure can actually route.
	 */
	private static function read_tr_access_param(): string {
		if ( isset( $_GET['tr_access'] ) && is_string( $_GET['tr_access'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['tr_access'] ) );
		}

		$query_var = get_query_var( 'tr_access', '' );

		return is_string( $query_var ) ? sanitize_text_field( $query_var ) : '';
	}

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
		$raw_token = self::read_tr_access_param();

		// This hooks into template_redirect, which fires on every front-end
		// request site-wide — a visit with no token in the URL is not an
		// event worth a log line, or this would write thousands of them a
		// day once parents are actually using the dashboard.
		if ( '' === $raw_token ) {
			return;
		}

		TR_Logger::debug( 'maybe_handle_access_token entered', [ 'tr_access_present' => true ] );

		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id <= 0 || ! is_page( $page_id ) ) {
			return;
		}

		if ( self::is_link_previewer_request() ) {
			return;
		}

		$token = $raw_token;

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

		// A Pay now link carries both tr_pay and tr_access in the same URL —
		// the token itself must never survive the redirect, but tr_pay does,
		// so the very next request (now signed in) lands straight on the
		// payment flow instead of a second, separate login step.
		$tr_pay       = isset( $_GET['tr_pay'] ) ? absint( $_GET['tr_pay'] ) : 0;
		$redirect_url = $tr_pay > 0 ? add_query_arg( 'tr_pay', $tr_pay, self::get_url() ) : self::get_url();

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

	/**
	 * A parent who just set their password via the welcome email's reset
	 * link has already proven control of the account — WordPress's default
	 * post-reset screen then makes them log in again anyway. This signs
	 * them straight in and sends them to the dashboard instead.
	 *
	 * The capability guard is the same rule (and the same shared method) as
	 * the passwordless access-token login — an admin or instructor account
	 * must never be auto-signed-in this way, whatever password-reset flow
	 * they happen to be going through. A user with no family record is left
	 * to WordPress's normal behaviour entirely.
	 *
	 * Exits on success (a redirect back into wp-login.php's own flow would
	 * just show the ordinary "log in" screen this is meant to skip).
	 */
	public function maybe_auto_login_after_reset( WP_User $user, string $new_pass ): void {
		if ( null === TR_Families::get_by_user( $user->ID ) ) {
			return;
		}

		if ( TR_Access_Tokens::user_is_privileged( $user ) ) {
			return;
		}

		$dashboard_url = self::get_url();
		if ( '' === $dashboard_url ) {
			return;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		wp_safe_redirect( $dashboard_url );
		exit;
	}

	/**
	 * The WordPress password-reset key in a welcome email's "Set your
	 * password" link is single-use — resending that email (or a parent
	 * clicking an old copy after already setting a password) leaves a dead
	 * key with no way to change that without weakening resets for every
	 * other user on this shared LMS site. TR_Notifications::send_welcome_email()
	 * appends the message token to that same URL as a fallback; this
	 * validates it here, before wp-login.php gets anywhere near its own
	 * (possibly already-dead) key handling.
	 *
	 * Deliberately unconditional once the token validates — sign in and
	 * redirect happen regardless of whether the reset key on this same
	 * request would still have worked on its own, so every copy of the
	 * welcome email a parent has (old or new) lands them in the same
	 * working place.
	 *
	 * Reuses TR_Access_Tokens::validate_and_consume() rather than
	 * duplicating its checks — that method already tries the message-token
	 * hash (which is what this URL actually carries) and applies the exact
	 * same privileged-capability guard, rate limiting and family-status
	 * check as every other passwordless login path in the plugin. An
	 * admin or instructor account is never auto-signed-in this way.
	 */
	public function maybe_handle_reset_link_fallback(): void {
		if ( ! isset( $_GET['tr_access'] ) ) {
			return;
		}

		$token = is_string( $_GET['tr_access'] ) ? sanitize_text_field( wp_unslash( $_GET['tr_access'] ) ) : '';

		$user_id = TR_Access_Tokens::validate_and_consume( $token );
		if ( $user_id <= 0 ) {
			// Invalid, expired or privileged — fall through to WordPress's
			// own reset-key handling for the rest of this page load.
			return;
		}

		$dashboard_url = self::get_url();
		if ( '' === $dashboard_url ) {
			return;
		}

		wp_safe_redirect( $dashboard_url );
		exit;
	}
}
