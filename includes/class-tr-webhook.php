<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST route tangnest-robotics/v1/webhook — deliberately distinct from the
 * LMS plugin's existing irembopay/v1/webhook route, so the two never
 * collide on this shared site.
 *
 * permission_callback is '__return_true' on purpose: authenticity comes
 * from the HMAC signature (when a webhook secret is configured), not from
 * WordPress auth — IremboPay's servers have no WP session to present.
 *
 * Payload field names (paymentStatus, the "data" wrapper, invoiceNumber,
 * transactionId) match the sibling woocommerce-irembopay plugin's
 * IremboPay_Webhook class, which processes live payments on this same
 * server. That class also handles FAILED and CANCELLED by moving its
 * WooCommerce order to a matching status — this plugin deliberately does
 * not: an invoice here represents an ongoing family obligation, not a
 * one-shot order, so a failed or cancelled IremboPay attempt changes
 * nothing except what's logged. Only PAID ever moves an invoice.
 */
class TR_Webhook {
	const NAMESPACE = 'tangnest-robotics/v1';
	const ROUTE     = '/webhook';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = $request->get_body();

		// Logged first, before any parsing — this is the line you want at
		// 2am when this misbehaves.
		TR_Logger::debug( 'IremboPay webhook received', [ 'raw_body' => $raw_body ] );

		$webhook_secret = TR_IremboPay_Settings::webhook_secret();
		if ( '' !== $webhook_secret ) {
			$signature = $request->get_header( 'x-irembopay-signature' );
			$expected  = hash_hmac( 'sha256', $raw_body, $webhook_secret );

			if ( ! $signature || ! hash_equals( $expected, $signature ) ) {
				TR_Logger::error( 'IremboPay webhook rejected: signature mismatch', [] );
				return new WP_REST_Response( [ 'error' => 'invalid_signature' ], 401 );
			}
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			TR_Logger::error( 'IremboPay webhook rejected: body is not valid JSON', [] );
			return new WP_REST_Response( [ 'error' => 'invalid_payload' ], 400 );
		}

		$data           = $payload['data'] ?? $payload;
		$invoice_number = $data['invoiceNumber'] ?? '';
		$status         = $data['paymentStatus'] ?? '';
		$transaction_id = $data['transactionId'] ?? '';

		if ( '' === $invoice_number ) {
			TR_Logger::error( 'IremboPay webhook rejected: no invoiceNumber in payload', [] );
			return new WP_REST_Response( [ 'error' => 'missing_invoice_number' ], 400 );
		}

		$invoice = TR_Invoices::get_by_irembopay_invoice_number( $invoice_number );
		if ( null === $invoice ) {
			TR_Logger::error( 'IremboPay webhook: invoice not found', [ 'irembopay_invoice_number' => $invoice_number ] );
			return new WP_REST_Response( [ 'error' => 'invoice_not_found' ], 404 );
		}

		// Idempotency: IremboPay retries webhook delivery. If this invoice
		// is already paid, change nothing and still return 200 — a
		// double-increment of months_paid would corrupt every parent's
		// progress, and there is no undo for that.
		if ( 'paid' === $invoice->status ) {
			TR_Logger::info( 'IremboPay webhook: invoice already paid, no action taken', [
				'invoice_id'               => $invoice->id,
				'irembopay_invoice_number' => $invoice_number,
			] );
			return new WP_REST_Response( [ 'status' => 'already_processed' ], 200 );
		}

		if ( 'PAID' !== $status ) {
			// An expired or failed IremboPay invoice does not mean the
			// family stopped owing money — never mark anything failed here.
			TR_Logger::info( 'IremboPay webhook: non-PAID status, acknowledged only', [
				'invoice_id' => $invoice->id,
				'status'     => $status,
			] );
			return new WP_REST_Response( [ 'status' => 'acknowledged' ], 200 );
		}

		// Same code path the admin "Record payment" action and the
		// defensive reuse-check reconciliation both use — one place that
		// knows how to mark an invoice paid and advance progress.
		TR_Payment::mark_paid_and_advance( (int) $invoice->id, (int) $invoice->family_id, $transaction_id );

		TR_Notifications::send_receipt_email( (int) $invoice->id );

		TR_Logger::info( 'IremboPay webhook: invoice marked paid', [
			'invoice_id' => $invoice->id,
			'family_id'  => $invoice->family_id,
			'amount'     => $invoice->amount,
		] );

		return new WP_REST_Response( [ 'status' => 'processed' ], 200 );
	}
}
