<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST route tangnest-robotics/v1/webhook, PLUS interception of the
 * sibling woocommerce-irembopay plugin's irembopay/v1/webhook route
 * (spec v0.7.1).
 *
 * IremboPay's merchant account allows exactly one registered callback
 * URL. It is currently the WooCommerce plugin's route, and it cannot be
 * changed without breaking live LMS course payments. So instead of ever
 * receiving traffic on our own route, our webhooks arrive at the
 * WooCommerce plugin's URL — this class hooks rest_pre_dispatch to look
 * at the payload before that route's own callback runs, claims it if the
 * invoiceNumber is one of ours, and otherwise returns $result completely
 * untouched so WooCommerce's own handler processes it exactly as it
 * always has. Our own route stays registered and working too, in case
 * the merchant account ever allows a second callback URL.
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

	// The only URL IremboPay is actually configured to call. Compared
	// against WP_REST_Request::get_route(), which always has a leading
	// slash, e.g. "/irembopay/v1/webhook".
	const INTERCEPT_ROUTE_PATH = '/irembopay/v1/webhook';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'maybe_intercept' ], 10, 3 );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		return self::process( $request->get_body(), $request->get_header( 'x-irembopay-signature' ) );
	}

	/**
	 * Never touches a request that isn't a POST to the exact WooCommerce
	 * webhook route, and never touches a $result some earlier filter has
	 * already decided — cheap checks first, no logging on the (overwhelming
	 * majority) non-matching case, same lesson as the v0.5.1 debug-logging
	 * cleanup: rest_pre_dispatch fires for every REST request site-wide.
	 *
	 * Anything past that point is wrapped in try/catch/finally: malformed
	 * JSON, a missing field, or any unexpected exception all fall through
	 * to $result unchanged rather than risk ever being the reason an LMS
	 * course payment breaks. The finally block logs exactly once per
	 * request that actually reaches this route, regardless of which path
	 * out of the method was taken.
	 */
	public function maybe_intercept( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result ) {
			return $result;
		}

		if ( self::INTERCEPT_ROUTE_PATH !== $request->get_route() || 'POST' !== $request->get_method() ) {
			return $result;
		}

		$invoice_number = '';
		$claimed        = false;
		$outcome        = 'not_ours';

		try {
			$raw_body = $request->get_body();
			$payload  = json_decode( $raw_body, true );

			if ( ! is_array( $payload ) ) {
				$outcome = 'invalid_payload';
				return $result;
			}

			$data = $payload['data'] ?? $payload;

			if ( ! is_array( $data ) || ! is_string( $data['invoiceNumber'] ?? null ) || '' === $data['invoiceNumber'] ) {
				$outcome = 'missing_invoice_number';
				return $result;
			}

			$invoice_number = $data['invoiceNumber'];

			$invoice = TR_Invoices::get_by_irembopay_invoice_number( $invoice_number );
			if ( null === $invoice ) {
				$outcome = 'not_ours';
				return $result;
			}

			$claimed  = true;
			$response = self::process( $raw_body, $request->get_header( 'x-irembopay-signature' ) );
			$outcome  = 'http_' . $response->get_status();

			return $response;
		} catch ( \Throwable $e ) {
			$outcome = 'exception: ' . $e->getMessage();
			return $result;
		} finally {
			TR_Logger::info( 'IremboPay webhook interception', [
				'irembopay_invoice_number' => $invoice_number,
				'claimed'                  => $claimed,
				'outcome'                  => $outcome,
			] );
		}
	}

	/**
	 * The actual webhook logic, shared by both our own route (handle())
	 * and the interception path above — one place that knows how to turn
	 * a raw IremboPay payload into an invoice update, so neither call site
	 * duplicates the idempotency check or the mark_paid_and_advance() call.
	 */
	public static function process( string $raw_body, ?string $signature ): WP_REST_Response {
		// Logged first, before any parsing — this is the line you want at
		// 2am when this misbehaves.
		TR_Logger::debug( 'IremboPay webhook received', [ 'raw_body' => $raw_body ] );

		$webhook_secret = TR_IremboPay_Settings::webhook_secret();
		if ( '' !== $webhook_secret ) {
			$expected = hash_hmac( 'sha256', $raw_body, $webhook_secret );

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
