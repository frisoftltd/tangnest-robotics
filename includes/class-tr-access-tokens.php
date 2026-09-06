<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Device-bound passwordless dashboard links (spec v0.3.1).
 *
 * A link is not a permanent key: the first use opens a grace window (2
 * hours or 3 devices, whichever comes first — see TR_ACCESS_GRACE_WINDOW_MINUTES
 * / TR_ACCESS_MAX_DEVICES) during which the same link keeps working, so a
 * parent tapping it from inside WhatsApp's in-app browser and later
 * re-opening it in Chrome isn't locked out. Once the window closes the
 * token is dead regardless of remaining device slots.
 */
class TR_Access_Tokens {
	const STATUS_UNUSED   = 'unused';
	const STATUS_ACTIVE   = 'active';
	const STATUS_CONSUMED = 'consumed';
	const STATUS_REVOKED  = 'revoked';

	const RATE_LIMIT_MAX_FAILURES   = 10;
	const RATE_LIMIT_WINDOW_MINUTES = 15;
	const RATE_LIMIT_BLOCK_MINUTES  = 60;

	const PRIVILEGED_CAPS = [ 'manage_options', 'edit_posts', 'edit_courses', 'list_users' ];

	public static function grace_window_minutes(): int {
		return (int) apply_filters( 'tr_access_grace_window_minutes', TR_ACCESS_GRACE_WINDOW_MINUTES );
	}

	public static function max_devices(): int {
		return (int) apply_filters( 'tr_access_max_devices', TR_ACCESS_MAX_DEVICES );
	}

	public static function unused_expiry_days(): int {
		return (int) apply_filters( 'tr_access_link_unused_expiry_days', TR_ACCESS_LINK_UNUSED_EXPIRY_DAYS );
	}

	/**
	 * Generates a fresh token, overwriting (and thereby invalidating) any
	 * existing one for this family. The raw token is returned ONCE — only
	 * its SHA-256 hash is ever persisted in the families table, logged, or
	 * displayed again.
	 *
	 * It is also cached briefly in a transient (see raw_token_cache_key())
	 * so that get_or_generate_url() can hand the SAME link to a second
	 * send channel shortly after the first, instead of every "send"
	 * silently killing the link a different channel just delivered. This
	 * is a convenience cache, not the source of truth — is_reusable()
	 * always checks the real DB-tracked status first, so a token that has
	 * genuinely expired its window is never handed out just because the
	 * cache entry is still warm.
	 *
	 * The cache TTL is capped to the grace window, never the (much longer)
	 * unused-link expiry — the raw token must not sit in wp_options any
	 * longer than the token itself is meaningfully reusable for. If the
	 * cache has expired by the time a second send happens, get_or_generate_url()
	 * mints a fresh token rather than fail; see its docblock.
	 */
	public static function generate( int $family_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );

		$now     = current_time( 'mysql' );
		$expires = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::unused_expiry_days() * DAY_IN_SECONDS );

		TR_Families::set_access_token( $family_id, $hash, $now, $expires );
		set_transient( self::raw_token_cache_key( $family_id ), $token, self::grace_window_minutes() * MINUTE_IN_SECONDS );

		return $token;
	}

	public static function build_url( string $token ): string {
		$dashboard_url = TR_Parent_Dashboard::get_url();
		if ( '' === $dashboard_url ) {
			return '';
		}

		return add_query_arg( 'tr_access', $token, $dashboard_url );
	}

	/**
	 * True when the family's current token can still be handed out to a
	 * new send channel without generating a replacement: unused-and-not-
	 * expired, or active-and-still-inside-its-grace-window-with-a-device-
	 * slot-free. Consumed, revoked, and expired tokens are never reusable.
	 */
	public static function is_reusable( object $family ): bool {
		if ( empty( $family->access_token_hash ) ) {
			return false;
		}

		$status = $family->access_token_status ?? self::STATUS_UNUSED;

		if ( self::STATUS_UNUSED === $status ) {
			$expires_ts = $family->access_token_expires ? strtotime( $family->access_token_expires ) : 0;
			return $expires_ts > current_time( 'timestamp' );
		}

		if ( self::STATUS_ACTIVE === $status ) {
			$deadline = strtotime( $family->access_token_first_used ) + self::grace_window_minutes() * MINUTE_IN_SECONDS;
			return current_time( 'timestamp' ) <= $deadline
				&& (int) $family->access_token_use_count < self::max_devices();
		}

		return false;
	}

	/**
	 * The URL every "send" action should use: reuses the existing token
	 * when it is still usable and its raw value is still cached, otherwise
	 * mints a fresh one. This is what stops "send by email" from silently
	 * invalidating a link just sent by WhatsApp minutes earlier.
	 *
	 * The cached raw value is untrusted until checked against the DB hash.
	 * A stale cache entry (e.g. left over from an older version that used
	 * a longer cache TTL, surviving until this code runs on an upgraded
	 * site) would otherwise hand out a link for a token that no longer
	 * matches what's stored — a real, previously-shipped bug, not a
	 * hypothetical one. A mismatch here always means something is wrong,
	 * so it's logged at error level.
	 */
	public static function get_or_generate_url( int $family_id ): string {
		$family = TR_Families::get( $family_id );

		if ( $family && self::is_reusable( $family ) ) {
			$cached_token = get_transient( self::raw_token_cache_key( $family_id ) );
			if ( is_string( $cached_token ) && '' !== $cached_token ) {
				if ( hash( 'sha256', $cached_token ) === $family->access_token_hash ) {
					$url = self::build_url( $cached_token );
					if ( '' !== $url ) {
						return $url;
					}
				} else {
					TR_Logger::error( 'Cached access token does not match stored hash — discarding stale cache', [
						'family_id' => $family_id,
					] );
					delete_transient( self::raw_token_cache_key( $family_id ) );
				}
			}
		}

		return self::build_url( self::generate( $family_id ) );
	}

	/**
	 * Always mints a fresh token regardless of reusability — the explicit
	 * "Regenerate link" admin action, and the escape hatch for a lost
	 * phone or cleared browser.
	 */
	public static function regenerate_url( int $family_id ): string {
		return self::build_url( self::generate( $family_id ) );
	}

	public static function revoke( int $family_id ): void {
		TR_Families::revoke_access_token( $family_id );
		delete_transient( self::raw_token_cache_key( $family_id ) );
	}

	private static function raw_token_cache_key( int $family_id ): string {
		return 'tr_access_raw_' . $family_id;
	}

	/**
	 * Runs the full validation sequence and, on success, signs the parent
	 * in. Returns the signed-in user ID on success, 0 on any rejection.
	 * Every rejection is deliberately indistinguishable to the caller —
	 * unknown, expired, revoked, window-closed and privileged-account all
	 * just fail, so the dashboard can show one generic "link is no longer
	 * active" message without leaking which check failed.
	 *
	 * Tries the access-token hash first, then the message-token hash
	 * (spec v0.7.0) — the two slots share the same URL parameter and the
	 * same rate limiting, so a guesser probing either one trips the same
	 * IP block.
	 */
	public static function validate_and_consume( string $token ): int {
		TR_Logger::debug( 'Access-link validation attempt', [
			'method'     => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'ip'         => self::truncated_ip(),
		] );

		// Diagnostic only — reads the same two transients is_rate_limited()
		// and record_failure() already touch elsewhere; adds no new state
		// and no additional cost class (still no DB hit).
		$is_blocked    = self::is_rate_limited();
		$failure_count = (int) get_transient( self::rate_limit_key() );
		TR_Logger::debug( 'Access-link rate-limit state', [
			'ip_blocked'    => $is_blocked,
			'failure_count' => $failure_count,
		] );

		if ( $is_blocked ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'rate_limited' ] );
			return 0;
		}

		$format_ok = (bool) preg_match( '/^[a-f0-9]{64}$/', $token );
		TR_Logger::debug( 'Access-link format check', [ 'format_ok' => $format_ok ] );

		if ( ! $format_ok ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'bad_format' ] );
			return 0;
		}

		$hash = hash( 'sha256', $token );

		$family = TR_Families::get_by_access_token_hash( $hash );
		if ( null !== $family ) {
			return self::validate_access_slot( $family );
		}

		$family = TR_Families::get_by_message_token_hash( $hash );
		if ( null !== $family ) {
			return self::validate_message_slot( $family );
		}

		TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'hash_not_found' ] );
		self::record_failure();
		return 0;
	}

	/**
	 * Device-bound / grace-window / use-cap validation for the primary
	 * access-token slot — unchanged in substance from pre-0.7.0, only the
	 * log lines now carry token_type so a rejection can be told apart from
	 * a message-token one.
	 */
	private static function validate_access_slot( object $family ): int {
		TR_Logger::debug( 'Access-link family lookup', [
			'token_type'              => 'access',
			'family_id'               => $family->id,
			'access_token_status'     => $family->access_token_status,
			'access_token_use_count'  => $family->access_token_use_count,
			'access_token_first_used' => $family->access_token_first_used,
			'access_token_expires'    => $family->access_token_expires,
		] );

		if ( self::STATUS_REVOKED === $family->access_token_status ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'status_revoked', 'family_id' => $family->id, 'token_type' => 'access' ] );
			self::record_failure();
			return 0;
		}

		if ( self::STATUS_CONSUMED === $family->access_token_status ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'status_consumed', 'family_id' => $family->id, 'token_type' => 'access' ] );
			self::record_failure();
			return 0;
		}

		if ( empty( $family->access_token_expires ) || strtotime( $family->access_token_expires ) < current_time( 'timestamp' ) ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'expired', 'family_id' => $family->id, 'token_type' => 'access' ] );
			self::record_failure();
			return 0;
		}

		if ( self::STATUS_ACTIVE === $family->access_token_status ) {
			$grace_deadline = strtotime( $family->access_token_first_used ) + self::grace_window_minutes() * MINUTE_IN_SECONDS;
			$window_passed  = current_time( 'timestamp' ) > $grace_deadline;
			$devices_full   = (int) $family->access_token_use_count >= self::max_devices();

			if ( $window_passed ) {
				TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'grace_window_passed', 'family_id' => $family->id, 'token_type' => 'access' ] );
				TR_Families::set_token_status( (int) $family->id, self::STATUS_CONSUMED );
				delete_transient( self::raw_token_cache_key( (int) $family->id ) );
				self::record_failure();
				return 0;
			}

			if ( $devices_full ) {
				TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'device_cap_reached', 'family_id' => $family->id, 'token_type' => 'access' ] );
				TR_Families::set_token_status( (int) $family->id, self::STATUS_CONSUMED );
				delete_transient( self::raw_token_cache_key( (int) $family->id ) );
				self::record_failure();
				return 0;
			}
		}

		$user = self::eligible_user( $family, 'access' );
		if ( null === $user ) {
			return 0;
		}

		// The device slot is only spent once the login actually happens —
		// sign-in runs first, and the increment follows it, never the
		// other way round.
		self::sign_in( $user );

		$now           = current_time( 'mysql' );
		$first_used    = $family->access_token_first_used ?: $now;
		$new_use_count = (int) $family->access_token_use_count + 1;
		$new_status    = $new_use_count >= self::max_devices() ? self::STATUS_CONSUMED : self::STATUS_ACTIVE;

		TR_Families::record_token_use( (int) $family->id, $first_used, $now, $new_use_count, $new_status );

		if ( self::STATUS_CONSUMED === $new_status ) {
			delete_transient( self::raw_token_cache_key( (int) $family->id ) );
		}

		TR_Logger::info( 'Access-link login', [
			'family_id'  => $family->id,
			'token_type' => 'access',
			'use_count'  => $new_use_count,
			'ip'         => self::truncated_ip(),
		] );

		return $user->ID;
	}

	/**
	 * The message-token slot: expiry only, no device binding, no grace
	 * window, no use cap — see the TR_Message_Tokens class docblock for
	 * why that is an acceptable trade for a link that only ever reaches
	 * one automatic-send recipient and is replaced on every later send.
	 */
	private static function validate_message_slot( object $family ): int {
		TR_Logger::debug( 'Access-link family lookup', [
			'token_type'              => 'message',
			'family_id'               => $family->id,
			'message_token_expires'   => $family->message_token_expires,
			'message_token_use_count' => $family->message_token_use_count,
		] );

		if ( empty( $family->message_token_expires ) || strtotime( $family->message_token_expires ) < current_time( 'timestamp' ) ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'expired', 'family_id' => $family->id, 'token_type' => 'message' ] );
			self::record_failure();
			return 0;
		}

		$user = self::eligible_user( $family, 'message' );
		if ( null === $user ) {
			return 0;
		}

		self::sign_in( $user );

		$now = current_time( 'mysql' );
		TR_Families::record_message_token_use( (int) $family->id, $now, (int) $family->message_token_use_count + 1 );

		TR_Logger::info( 'Access-link login', [
			'family_id'  => $family->id,
			'token_type' => 'message',
			'ip'         => self::truncated_ip(),
		] );

		return $user->ID;
	}

	/**
	 * Shared by both slots: the parent user must still exist, must not
	 * hold any privileged capability (an admin's own account must never be
	 * signable-in via a family link, whichever slot matched), and the
	 * family itself must be active. Returns null (and records the
	 * rejection + failure) on the first check that fails.
	 */
	private static function eligible_user( object $family, string $token_type ): ?WP_User {
		$user = get_userdata( (int) $family->parent_user_id );

		if ( ! $user ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'no_parent_user', 'family_id' => $family->id, 'token_type' => $token_type ] );
			self::record_failure();
			return null;
		}

		if ( self::user_is_privileged( $user ) ) {
			TR_Logger::warning( 'Access-link rejected', [
				'reason'     => 'privileged_user',
				'family_id'  => $family->id,
				'user_id'    => $family->parent_user_id,
				'token_type' => $token_type,
			] );
			self::record_failure();
			return null;
		}

		if ( 'active' !== $family->status ) {
			TR_Logger::warning( 'Access-link rejected', [ 'reason' => 'family_inactive', 'family_id' => $family->id, 'token_type' => $token_type ] );
			self::record_failure();
			return null;
		}

		return $user;
	}

	private static function sign_in( WP_User $user ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
	}

	private static function user_is_privileged( WP_User $user ): bool {
		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Opportunistic cleanup — called from dashboard load, not a cron job
	 * (cron is a later milestone). Request-time validation above is what
	 * actually enforces the window; this just keeps the admin status
	 * column from showing a stale "Active" after the window has passed.
	 */
	public static function close_stale_windows(): void {
		TR_Families::expire_stale_active_tokens( self::grace_window_minutes() );
	}

	public static function status_label( object $family ): string {
		if ( empty( $family->access_token_hash ) ) {
			return __( 'No link sent', 'tangnest-robotics' );
		}

		$status = $family->access_token_status ?? self::STATUS_UNUSED;

		if ( self::STATUS_REVOKED === $status ) {
			return __( 'Revoked', 'tangnest-robotics' );
		}

		if ( self::STATUS_CONSUMED === $status ) {
			return __( 'Consumed', 'tangnest-robotics' );
		}

		$expires_ts = $family->access_token_expires ? strtotime( $family->access_token_expires ) : 0;
		if ( $expires_ts && $expires_ts < current_time( 'timestamp' ) ) {
			return __( 'Expired', 'tangnest-robotics' );
		}

		if ( self::STATUS_UNUSED === $status ) {
			return __( 'Never opened', 'tangnest-robotics' );
		}

		$deadline     = strtotime( $family->access_token_first_used ) + self::grace_window_minutes() * MINUTE_IN_SECONDS;
		$minutes_left = (int) floor( ( $deadline - current_time( 'timestamp' ) ) / MINUTE_IN_SECONDS );

		if ( $minutes_left <= 0 ) {
			return __( 'Consumed', 'tangnest-robotics' );
		}

		return sprintf(
			/* translators: 1: devices used, 2: max devices, 3: minutes left in the grace window */
			__( 'Active — %1$d of %2$d devices, %3$d min left', 'tangnest-robotics' ),
			(int) $family->access_token_use_count,
			self::max_devices(),
			$minutes_left
		);
	}

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Last octet (IPv4) or last groups (IPv6) zeroed out — used only for
	 * logging, never for the rate-limit bucket key, which needs the real
	 * address to be effective.
	 */
	private static function truncated_ip(): string {
		$ip = self::client_ip();

		if ( '' === $ip ) {
			return '';
		}

		if ( false !== strpos( $ip, '.' ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		if ( false !== strpos( $ip, ':' ) ) {
			$parts = explode( ':', $ip );
			return implode( ':', array_slice( $parts, 0, 4 ) ) . '::';
		}

		return '';
	}

	private static function rate_limit_key(): string {
		return 'tr_arl_' . md5( self::client_ip() );
	}

	private static function rate_limit_block_key(): string {
		return 'tr_arb_' . md5( self::client_ip() );
	}

	public static function is_rate_limited(): bool {
		return (bool) get_transient( self::rate_limit_block_key() );
	}

	private static function record_failure(): void {
		if ( '' === self::client_ip() ) {
			return;
		}

		$key   = self::rate_limit_key();
		$count = (int) get_transient( $key );
		$count++;

		set_transient( $key, $count, self::RATE_LIMIT_WINDOW_MINUTES * MINUTE_IN_SECONDS );

		if ( $count >= self::RATE_LIMIT_MAX_FAILURES ) {
			set_transient( self::rate_limit_block_key(), 1, self::RATE_LIMIT_BLOCK_MINUTES * MINUTE_IN_SECONDS );
			delete_transient( $key );
			TR_Logger::error( 'Access-link rate limit tripped', [ 'ip' => self::truncated_ip() ] );
		}
	}
}
