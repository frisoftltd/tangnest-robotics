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
 * parent an automatic send was addressed to, and a 14-day expiry plus
 * "every send mints a fresh one" covers the same ground without the
 * failure modes that cost a week of debugging in v0.5.0.
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
	 * Always mints a fresh token — regenerating on every automatic send is
	 * deliberate, not a bug: a parent acts on the most recent message, and
	 * an older one going stale is expected. Never cached anywhere; the raw
	 * value only exists for the lifetime of the request that builds the
	 * message it's embedded in, so it never sits in wp_options at all.
	 */
	public static function generate( int $family_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );

		$now     = current_time( 'mysql' );
		$expires = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + self::expiry_days() * DAY_IN_SECONDS );

		TR_Families::set_message_token( $family_id, $hash, $now, $expires );

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
	 * What every automatic send should call: mints a fresh token and
	 * returns the ready-to-use dashboard URL in one step. '' only when no
	 * dashboard page is configured at all — callers must handle that the
	 * same way they always have (no link rendered).
	 */
	public static function generate_url( int $family_id ): string {
		return self::build_url( self::generate( $family_id ) );
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
