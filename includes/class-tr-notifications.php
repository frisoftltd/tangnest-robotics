<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * All plugin email goes through send(). The welcome-email flow never puts a
 * password in the message — it hands out a one-time WP password-reset key
 * instead, and never extends the 24-hour expiry (that filter is global and
 * would weaken resets for every user on the LMS site).
 */
class TR_Notifications {
	const FROM_EMAIL       = 'info@tangnest.rw';
	const WELCOME_SENT_META = '_tr_welcome_sent';

	public static function send( string $to, string $subject, string $body_html ): bool {
		add_filter( 'wp_mail_from', [ __CLASS__, 'filter_from_email' ] );
		add_filter( 'wp_mail_from_name', [ __CLASS__, 'filter_from_name' ] );

		$sent = wp_mail( $to, $subject, $body_html, [ 'Content-Type: text/html; charset=UTF-8' ] );

		remove_filter( 'wp_mail_from', [ __CLASS__, 'filter_from_email' ] );
		remove_filter( 'wp_mail_from_name', [ __CLASS__, 'filter_from_name' ] );

		if ( $sent ) {
			TR_Logger::info( 'Email sent', [ 'to' => $to, 'subject' => $subject ] );
		} else {
			TR_Logger::error( 'Email failed to send', [ 'to' => $to, 'subject' => $subject ] );
		}

		return $sent;
	}

	public static function filter_from_email(): string {
		return self::FROM_EMAIL;
	}

	public static function filter_from_name(): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * Sends (or re-sends) the welcome email unconditionally and records the
	 * timestamp on success. Used directly by the admin Resend row action.
	 */
	public static function send_welcome_email( WP_User $user, int $family_id ): bool {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			TR_Logger::error( 'Could not generate password reset key', [
				'user_id' => $user->ID,
				'error'   => $key->get_error_message(),
			] );
			return false;
		}

		$reset_url     = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$dashboard_url = TR_Parent_Dashboard::get_url();
		$students      = self::get_family_students_for_email( $family_id );
		$access_url    = TR_Access_Tokens::get_or_generate_url( $family_id );

		$body = self::render_welcome_template( $user, $reset_url, $dashboard_url, $students, $access_url );
		$sent = self::send(
			$user->user_email,
			__( 'Welcome to Tangnest Robotics — set up your account', 'tangnest-robotics' ),
			$body
		);

		if ( $sent ) {
			update_user_meta( $user->ID, self::WELCOME_SENT_META, time() );
		}

		return $sent;
	}

	/**
	 * Guarded version used by the automatic trigger on parent creation —
	 * never re-sends on a later save of the same family.
	 */
	public static function maybe_send_welcome_email( int $user_id, int $family_id ): bool {
		if ( get_user_meta( $user_id, self::WELCOME_SENT_META, true ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return self::send_welcome_email( $user, $family_id );
	}

	/**
	 * Used by the admin Resend row action — bypasses the _tr_welcome_sent
	 * guard on purpose, since this is an explicit admin request.
	 */
	public static function resend_welcome_email( int $user_id, int $family_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return self::send_welcome_email( $user, $family_id );
	}

	private static function get_family_students_for_email( int $family_id ): array {
		$students = TR_Students::get_list( [ 'family_id' => $family_id, 'per_page' => 200 ] );
		$rows     = [];

		foreach ( $students as $student ) {
			$enrollments = TR_Enrollments::get_by_student( (int) $student->id );
			$enrollment  = $enrollments[0] ?? null;
			$program     = $enrollment ? TR_Programs::get( (int) $enrollment->program_id ) : null;

			$rows[] = (object) [
				'name'    => trim( $student->first_name . ' ' . $student->last_name ),
				'program' => $program->name ?? '',
			];
		}

		return $rows;
	}

	private static function render_welcome_template( WP_User $user, string $reset_url, string $dashboard_url, array $students, string $access_url ): string {
		ob_start();
		include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/emails/welcome.php';
		return ob_get_clean();
	}

	/**
	 * Standalone "here's your link" email used by the admin Send access-link
	 * row action. Reuses the family's current token when it is still usable
	 * (see TR_Access_Tokens::get_or_generate_url()) so sending by email
	 * after already sending by WhatsApp doesn't kill the first link.
	 */
	public static function send_access_link_email( WP_User $user, int $family_id ): bool {
		if ( '' === TR_Parent_Dashboard::get_url() ) {
			TR_Logger::error( 'Could not send access-link email: no dashboard page configured', [ 'family_id' => $family_id ] );
			return false;
		}

		$access_url = TR_Access_Tokens::get_or_generate_url( $family_id );
		$body       = self::render_access_link_template( $user, $access_url );

		return self::send(
			$user->user_email,
			__( 'Your Tangnest Robotics parent page link', 'tangnest-robotics' ),
			$body
		);
	}

	private static function render_access_link_template( WP_User $user, string $access_url ): string {
		ob_start();
		include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/emails/access-link.php';
		return ob_get_clean();
	}

	/**
	 * Sent by TR_Invoice_Generator immediately after an invoice is created.
	 */
	public static function send_invoice_issued_email( int $family_id, int $invoice_id ): bool {
		$family = TR_Families::get( $family_id );
		if ( null === $family ) {
			return false;
		}

		$user = get_userdata( (int) $family->parent_user_id );
		if ( ! $user ) {
			return false;
		}

		if ( ! is_email( $user->user_email ) ) {
			TR_Logger::warning( 'Invoice created but no valid email on file — parent was not notified', [
				'family_id'  => $family_id,
				'invoice_id' => $invoice_id,
			] );
			return false;
		}

		$invoice = TR_Invoices::get( $invoice_id );
		if ( null === $invoice ) {
			return false;
		}

		$dashboard_url = TR_Parent_Dashboard::get_url();
		$students      = self::decode_invoice_snapshot( $invoice );

		$body = self::render_invoice_issued_template( $user, $invoice, $students, $dashboard_url );

		return self::send(
			$user->user_email,
			sprintf(
				/* translators: %s: billing period, e.g. 2026-09 */
				__( 'Tangnest Robotics — invoice for %s', 'tangnest-robotics' ),
				$invoice->period
			),
			$body
		);
	}

	/**
	 * Used by the admin "Send reminder" row action on the Invoices screen.
	 * Works for a pending or an overdue invoice — $days_overdue is 0 unless
	 * the invoice is actually overdue.
	 */
	public static function send_reminder_email( int $invoice_id ): bool {
		$invoice = TR_Invoices::get( $invoice_id );
		if ( null === $invoice ) {
			return false;
		}

		$family = TR_Families::get( (int) $invoice->family_id );
		if ( null === $family ) {
			return false;
		}

		$user = get_userdata( (int) $family->parent_user_id );
		if ( ! $user ) {
			return false;
		}

		$dashboard_url = TR_Parent_Dashboard::get_url();
		$students      = self::decode_invoice_snapshot( $invoice );

		$days_overdue = 0;
		if ( 'overdue' === $invoice->status ) {
			$days_overdue = max( 0, (int) floor( ( current_time( 'timestamp' ) - strtotime( $invoice->due_date ) ) / DAY_IN_SECONDS ) );
		}

		$body = self::render_reminder_template( $user, $invoice, $students, $dashboard_url, $days_overdue );

		return self::send(
			$user->user_email,
			sprintf(
				/* translators: %s: billing period, e.g. 2026-09 */
				__( 'Reminder: Tangnest Robotics payment for %s', 'tangnest-robotics' ),
				$invoice->period
			),
			$body
		);
	}

	private static function decode_invoice_snapshot( object $invoice ): array {
		if ( empty( $invoice->student_snapshot ) ) {
			return [];
		}

		$decoded = json_decode( $invoice->student_snapshot, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	private static function render_invoice_issued_template( WP_User $user, object $invoice, array $students, string $dashboard_url ): string {
		ob_start();
		include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/emails/invoice-issued.php';
		return ob_get_clean();
	}

	private static function render_reminder_template( WP_User $user, object $invoice, array $students, string $dashboard_url, int $days_overdue ): string {
		ob_start();
		include TANGNEST_ROBOTICS_PLUGIN_DIR . 'templates/emails/reminder.php';
		return ob_get_clean();
	}

	/**
	 * Shared by every WhatsApp send in the plugin (access links, payment
	 * reminders). Each line is esc_attr() + urlencode()'d individually,
	 * then joined with a lowercase "%0a" — urlencode()'ing the already-joined
	 * string would encode the "%0a" markers themselves and collapse every
	 * line break. Returns '' if the phone isn't in 07XXXXXXXX format.
	 *
	 * The caller must emit the result via a raw header() call, never
	 * wp_redirect()/wp_safe_redirect() (both run wp_sanitize_redirect(),
	 * which strips "%0d"/"%0a" as a CRLF-injection defense) or esc_url()
	 * (which uppercases any surviving "%0a" to "%0A") — either would delete
	 * the line breaks WhatsApp Web needs to render the message.
	 */
	public static function build_whatsapp_message_url( string $phone, array $lines ): string {
		if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
			return '';
		}

		$whatsapp_number = '250' . preg_replace( '/^0/', '', $phone );

		$encoded_lines = array_map( static function ( $line ) {
			return urlencode( esc_attr( $line ) );
		}, $lines );

		$text = implode( '%0a', $encoded_lines );

		return 'https://web.whatsapp.com/send?phone=' . $whatsapp_number . '&text=' . $text;
	}
}
