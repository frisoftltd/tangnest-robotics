<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Independent second token slot (spec v0.7.0) for anything the plugin sends
 * automatically to a parent — invoice, reminder, welcome and receipt
 * emails, plus the routine WhatsApp payment reminder sent from the
 * Invoices screen. Exists because those automatic sends were fighting the
 * device-bound access token used by the admin's explicit "Send access
 * link" actions: regenerating one on every send invalidated whichever
 * link a different channel had just delivered minutes earlier.
 *
 * Deliberately much simpler than TR_Access_Tokens: no device binding, no
 * grace window, no use cap. Those exist to stop a forwarded WhatsApp link
 * granting permanent access; a message token only ever reaches the one
 * parent an automatic send was addressed to, and a 14-day expiry covers
 * the same ground without those failure modes.
 *
 * v0.8.4: every automatic send used to mint a brand new token, which
 * meant only the MOST RECENT message's links still worked — every earlier
 * email or WhatsApp message a parent hadn't gotten to yet went dead the
 * moment a newer one was sent. get_or_generate()/get_or_generate_url()
 * fix that: a still-valid token is reused as-is, so every message sent
 * within the 14-day window keeps working, not just the latest one.
 * generate() itself is unchanged (always mints fresh) and is now reserved
 * for get_or_generate()'s own use and any caller that genuinely wants a
 * forced-fresh token.
 *
 * Reuse requires recovering the RAW token from a later request, which the
 * stored SHA-256 hash alone can never do — so the raw value is now cached
 * in a transient for exactly as long as the token itself is valid
 * (expiry_days()), the same "cache the raw value, verify it against the
 * stored hash before trusting it" pattern TR_Access_Tokens already uses
 * for its own (much shorter) grace-window reuse cache. If that transient
 * is ever missing when a still-valid hash exists (e.g. an evicted object
 * cache), get_or_generate() mints a fresh token rather than fail — the
 * same fallback TR_Access_Tokens takes in the equivalent situation.
 *
 * The admin's explicit "Send access link" actions (Email and WhatsApp)
 * are untouched by any of this — they keep using TR_Access_Tokens, on
 * purpose, since that is the deliberate device-bound path a parent is
 * meant to keep reusing across visits.
 */
class TR_Message_Tokens {

	public static function expiry_days(): int {
		return (int) apply_filters( 'tr_message_token_expiry_days', TR_MESSAGE_TOKEN_EXPIRY_DAYS );
	}

	/**
	 * Always mints a fresh token, overwriting (and thereby invalidating)
	 * any existing one for this family — see get_or_generate() for the
	 * reuse-aware version every automatic send should actually call.
	 */
	public static function generate( int $family_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );

		$now     = current_time( 'mysql' );
		$expires = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::expiry_days() * DAY_IN_SECONDS );

		TR_Families::set_message_token( $family_id, $hash, $now, $expires );
		set_transient( self::raw_token_cache_key( $family_id ), $token, self::expiry_days() * DAY_IN_SECONDS );

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
	 * True when the family's current message token is still usable as-is —
	 * unset or expired tokens are never reusable; there is no status column
	 * to check beyond that (see the class docblock for why this slot has no
	 * device/grace/use-cap concept the way TR_Access_Tokens does).
	 */
	public static function is_reusable( object $family ): bool {
		if ( empty( $family->message_token_hash ) ) {
			return false;
		}

		$expires_ts = $family->message_token_expires ? strtotime( $family->message_token_expires ) : 0;

		return $expires_ts > current_time( 'timestamp' );
	}

	/**
	 * What every automatic send should call: reuses the family's current
	 * token when it's still valid, otherwise mints a fresh one. This is
	 * what stops a second (or third, or tenth) automatic send from
	 * invalidating the links already delivered by earlier ones.
	 *
	 * The cached raw value is untrusted until checked against the DB hash —
	 * a stale cache entry would otherwise hand out a link for a token that
	 * no longer matches what's stored. A mismatch here always means
	 * something is wrong, so it's logged at error level, same as the
	 * equivalent check in TR_Access_Tokens::get_or_generate_url().
	 */
	public static function get_or_generate( int $family_id ): string {
		$family = TR_Families::get( $family_id );

		if ( $family && self::is_reusable( $family ) ) {
			$cached_token = get_transient( self::raw_token_cache_key( $family_id ) );
			if ( is_string( $cached_token ) && '' !== $cached_token ) {
				if ( hash( 'sha256', $cached_token ) === $family->message_token_hash ) {
					return $cached_token;
				}

				TR_Logger::error( 'Cached message token does not match stored hash — discarding stale cache', [
					'family_id' => $family_id,
				] );
				delete_transient( self::raw_token_cache_key( $family_id ) );
			}
		}

		return self::generate( $family_id );
	}

	/**
	 * Reuse-aware convenience wrapper, returning the ready-to-use dashboard
	 * URL in one step. '' only when no dashboard page is configured at
	 * all — callers must handle that the same way they always have (no
	 * link rendered).
	 */
	public static function get_or_generate_url( int $family_id ): string {
		return self::build_url( self::get_or_generate( $family_id ) );
	}

	/**
	 * Always mints a fresh token and returns the ready-to-use dashboard
	 * URL in one step. Kept for any caller that genuinely wants a
	 * forced-fresh token rather than reuse — not currently called by any
	 * automatic send (see get_or_generate_url() for that).
	 */
	public static function generate_url( int $family_id ): string {
		return self::build_url( self::generate( $family_id ) );
	}

	private static function raw_token_cache_key( int $family_id ): string {
		return 'tr_msg_raw_' . $family_id;
	}

	/**
	 * Admin-facing summary for the Families screen — deliberately just
	 * "None" / "Expired" / "Valid until <date>". There is no device or
	 * use-count detail to show, unlike TR_Access_Tokens::status_label();
	 * that is the whole point of this slot being simpler.
	 */
	public static function status_label( object $family ): string {
		if ( empty( $family->message_token_hash ) ) {
			return __( 'None', 'tangnest-robotics' );
		}

		$expires_ts = $family->message_token_expires ? strtotime( $family->message_token_expires ) : 0;
		if ( ! $expires_ts || $expires_ts < current_time( 'timestamp' ) ) {
			return __( 'Expired', 'tangnest-robotics' );
		}

		return sprintf(
			/* translators: %s: expiry date */
			__( 'Valid until %s', 'tangnest-robotics' ),
			date_i18n( get_option( 'date_format' ), $expires_ts )
		);
	}
}
