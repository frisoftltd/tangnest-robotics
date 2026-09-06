<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Payment initiation. The family paying is always resolved from
 * get_current_user_id() — the invoice ID arriving in a URL is only ever
 * used to look up a candidate row, never trusted for authorization. A
 * mismatch between that row's family_id and the session's family is
 * treated identically to "invoice not found", so a tampered ID can never
 * distinguish "exists but isn't yours" from "doesn't exist".
 *
 * There is no IremboPay endpoint to ask an existing invoice's current
 * status (the sibling woocommerce-irembopay plugin never does this either
 * — it relies entirely on the webhook), so "should we reuse the invoice
 * already on this row" is decided entirely from our own data: the
 * irembopay_expires_at we stored when we created it, plus our own
 * invoice status. If that reuse window has passed, or the invoice was
 * never actually created remotely, a fresh IremboPay invoice is created.
 */
class TR_Payment {

	/**
	 * @return array{success:bool, invoice:?object, irembopay_invoice_number:string, error:string}
	 */
	public static function initiate( int $invoice_id ): array {
		if ( ! TR_IremboPay_Settings::is_enabled() ) {
			return self::error( __( 'Online payments are not available right now.', 'tangnest-robotics' ) );
		}

		if ( ! is_user_logged_in() ) {
			return self::error( __( 'Please log in to pay.', 'tangnest-robotics' ) );
		}

		$current_user = wp_get_current_user();
		$family       = TR_Families::get_by_user( $current_user->ID );

		if ( null === $family ) {
			return self::error( __( 'No family record was found for your account.', 'tangnest-robotics' ) );
		}

		$invoice = TR_Invoices::get( $invoice_id );

		// Same generic message whether the invoice doesn't exist at all or
		// simply belongs to a different family — never confirm or deny
		// that a given ID exists to someone who doesn't own it.
		if ( null === $invoice || (int) $invoice->family_id !== (int) $family->id ) {
			TR_Logger::warning( 'Payment initiation refused: invoice does not belong to this family', [
				'invoice_id' => $invoice_id,
				'user_id'    => $current_user->ID,
				'family_id'  => $family->id,
			] );
			return self::error( __( 'Invoice not found.', 'tangnest-robotics' ) );
		}

		if ( ! in_array( $invoice->status, [ 'pending', 'overdue' ], true ) ) {
			return self::error( __( 'This invoice is not payable.', 'tangnest-robotics' ) );
		}

		if ( (float) $invoice->amount <= 0 ) {
			return self::error( __( 'This invoice has no amount due.', 'tangnest-robotics' ) );
		}

		if ( ! empty( $invoice->irembopay_invoice_number ) && self::is_reusable( $invoice ) ) {
			TR_Logger::info( 'Reusing existing IremboPay invoice', [
				'invoice_id'               => $invoice->id,
				'irembopay_invoice_number' => $invoice->irembopay_invoice_number,
			] );
			return [ 'success' => true, 'invoice' => $invoice, 'irembopay_invoice_number' => $invoice->irembopay_invoice_number, 'error' => '' ];
		}

		$api = new TR_IremboPay_API( TR_IremboPay_Settings::secret_key() );
		return self::create_new( $api, $invoice, $family, $current_user );
	}

	private static function is_reusable( object $invoice ): bool {
		if ( empty( $invoice->irembopay_expires_at ) ) {
			return false;
		}

		return strtotime( $invoice->irembopay_expires_at ) > current_time( 'timestamp' );
	}

	private static function error( string $message ): array {
		return [ 'success' => false, 'invoice' => null, 'irembopay_invoice_number' => '', 'error' => $message ];
	}

	/**
	 * The same sequence the webhook and the admin "Record payment" action
	 * both use — one code path for "an invoice just got paid", not
	 * multiple copies of it. Advances the family exactly once (v0.8.0) —
	 * every child on a package finishes together, so progress lives on the
	 * family now, not once per enrollment.
	 */
	public static function mark_paid_and_advance( int $invoice_id, int $family_id, string $transaction_id ): void {
		TR_Invoices::mark_paid( $invoice_id, [
			'payment_method'    => 'irembopay',
			'payment_reference' => $transaction_id,
			'recorded_by'       => 0,
		] );

		TR_Families::increment_months_paid( $family_id );
	}

	private static function create_new( TR_IremboPay_API $api, object $invoice, object $family, WP_User $user ): array {
		$phone      = get_user_meta( $user->ID, 'phone_number', true );
		$phone_e164 = preg_match( '/^07\d{8}$/', $phone ) ? '250' . preg_replace( '/^0/', '', $phone ) : '';

		$transaction_id = 'TR-' . $invoice->id . '-' . strtoupper( substr( wp_generate_password( 12, false, false ), 0, 8 ) );

		$expiry_dt   = ( new DateTime( 'now', wp_timezone() ) )->modify( '+' . TR_IremboPay_Settings::expiry_hours() . ' hours' );
		$expiry_atom = $expiry_dt->format( DateTime::ATOM );

		$product_code = self::product_code_for_invoice( $invoice );

		// Caught here, not by IremboPay's BAD_PRODUCT rejection — the
		// dashboard and emails are also supposed to hide the Pay button in
		// this exact situation (see has_resolvable_product_code()), so
		// reaching this point at all means that guard was bypassed or a
		// package was edited after the button was rendered.
		if ( '' === $product_code ) {
			TR_Logger::error( 'Cannot start IremboPay payment: no product code resolves for this invoice', [
				'invoice_id' => $invoice->id,
				'family_id'  => $family->id,
				'issue'      => self::package_issue_for_invoice( $invoice ),
			] );
			return self::error( __( 'Online payment is not set up for this package yet. Please contact Tangnest.', 'tangnest-robotics' ) );
		}

		$payment_item = [
			'unitAmount' => (int) round( (float) $invoice->amount ), // RWF has no minor units — must be an integer, never a float.
			'quantity'   => 1,
			'code'       => $product_code,
		];

		$payload = [
			'transactionId'            => $transaction_id,
			'paymentAccountIdentifier' => TR_IremboPay_Settings::payment_account_identifier(),
			'customer'                 => [
				'email'       => $user->user_email,
				'phoneNumber' => $phone_e164,
				'name'        => $user->display_name,
			],
			'paymentItems'             => [ $payment_item ],
			'description'              => sprintf(
				/* translators: 1: billing period, 2: comma-separated child names */
				__( 'Robotics fees %1$s — %2$s', 'tangnest-robotics' ),
				$invoice->period,
				implode( ', ', self::student_names_for_invoice( $invoice ) )
			),
			'language'                 => 'EN',
			'expiryAt'                 => $expiry_atom,
		];

		$result = $api->create_invoice( $payload );

		if ( empty( $result['success'] ) || empty( $result['data']['invoiceNumber'] ) ) {
			TR_Logger::error( 'Failed to create IremboPay invoice', [
				'invoice_id' => $invoice->id,
				'family_id'  => $family->id,
				'message'    => $result['message'] ?? '',
			] );
			return self::error( __( 'Could not start the payment. Please try again shortly.', 'tangnest-robotics' ) );
		}

		$irembopay_invoice_number = $result['data']['invoiceNumber'];

		TR_Invoices::set_irembopay_reference(
			(int) $invoice->id,
			$irembopay_invoice_number,
			$transaction_id,
			$expiry_dt->format( 'Y-m-d H:i:s' )
		);

		TR_Logger::info( 'IremboPay invoice created', [
			'invoice_id'               => $invoice->id,
			'family_id'                => $family->id,
			'irembopay_invoice_number' => $irembopay_invoice_number,
			'amount'                   => $invoice->amount,
		] );

		return [ 'success' => true, 'invoice' => $invoice, 'irembopay_invoice_number' => $irembopay_invoice_number, 'error' => '' ];
	}

	/**
	 * True when a Pay attempt on this invoice would actually have a product
	 * code to send IremboPay — the dashboard and email templates use this
	 * to hide the Pay button and show a plain "contact us" line instead,
	 * rather than let a parent hit "Could not start the payment".
	 */
	public static function has_resolvable_product_code( object $invoice ): bool {
		return '' !== self::product_code_for_invoice( $invoice );
	}

	/**
	 * The single check every Pay button should use (v0.8.0). A family with
	 * no package at all must never show one, even if a global default
	 * product code happens to be configured — that default exists to cover
	 * a package with no code of its own, not a missing package entirely.
	 */
	public static function is_payable( object $invoice ): bool {
		$family = TR_Families::get( (int) $invoice->family_id );
		if ( null === $family || empty( $family->package_id ) ) {
			return false;
		}

		return self::has_resolvable_product_code( $invoice );
	}

	/**
	 * The family's package code, falling back to the settings default —
	 * there is exactly one package per family now (v0.8.0), not one
	 * program per child, so there is no "first active enrollment with a
	 * code" search to do any more.
	 */
	public static function product_code_for_invoice( object $invoice ): string {
		$family = TR_Families::get( (int) $invoice->family_id );

		if ( $family && ! empty( $family->package_id ) ) {
			$package = TR_Programs::get( (int) $family->package_id );
			if ( $package && ! empty( $package->irembopay_product_code ) ) {
				return $package->irembopay_product_code;
			}
		}

		return TR_IremboPay_Settings::default_product_code();
	}

	/**
	 * For the log line only, when create_new() finds no resolvable code at
	 * all — names the specific package missing one, or notes the family
	 * has no package, so the log says what an admin needs to go fix.
	 */
	private static function package_issue_for_invoice( object $invoice ): string {
		$family = TR_Families::get( (int) $invoice->family_id );

		if ( null === $family || empty( $family->package_id ) ) {
			return 'family has no package';
		}

		$package = TR_Programs::get( (int) $family->package_id );
		if ( null === $package ) {
			return 'family package no longer exists';
		}

		return empty( $package->irembopay_product_code ) ? 'package "' . $package->name . '" has no product code' : '';
	}

	private static function student_names_for_invoice( object $invoice ): array {
		if ( empty( $invoice->student_snapshot ) ) {
			return [];
		}

		$decoded = json_decode( $invoice->student_snapshot, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		return array_values( array_filter( array_map( static function ( $row ) {
			return $row['student_name'] ?? '';
		}, $decoded ) ) );
	}

	public static function payment_page_url( int $invoice_id ): string {
		$dashboard_url = TR_Parent_Dashboard::get_url();
		if ( '' === $dashboard_url ) {
			return '';
		}

		return add_query_arg( 'tr_pay', $invoice_id, $dashboard_url );
	}
}
