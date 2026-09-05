<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin client over the IremboPay REST API, routed through a Cloudflare
 * Worker proxy — the Namecheap server firewall blocks direct outbound
 * calls to api.irembopay.com, so every request goes to the proxy with the
 * real endpoint path carried in X-Target-Path instead of in the URL.
 *
 * Uses cURL directly rather than wp_remote_post(), and matches the sibling
 * woocommerce-irembopay plugin's IremboPay_API class field-for-field — that
 * class is proven against this exact proxy on this exact server. In
 * particular the response shape below is IremboPay's own decoded JSON
 * merged flat with 'http_code' — there is no wrapper key, and 'success' is
 * whatever IremboPay's payload says it is, never derived from the HTTP
 * status. Only create_invoice() exists; there is no invoice-status lookup
 * anywhere in the sibling plugin, so none is implemented here either —
 * payment confirmation comes from the webhook only.
 */
class TR_IremboPay_API {
	const PROXY_URL = 'https://irembopay-proxy.info-tangnest.workers.dev';

	private string $secret_key;

	public function __construct( string $secret_key ) {
		$this->secret_key = trim( $secret_key );
	}

	/**
	 * @param array $invoice_data Full IremboPay create-invoice payload.
	 * @return array IremboPay's decoded response merged with 'http_code'.
	 */
	public function create_invoice( array $invoice_data ): array {
		return $this->post( '/payments/invoices', $invoice_data );
	}

	private function post( string $endpoint, array $body ): array {
		$headers = [
			'irembopay-secretkey: ' . $this->secret_key,
			'Content-Type: application/json',
			'X-API-Version: 2',
			'X-Target-Path: ' . $endpoint,
		];

		// Never log $this->secret_key or the raw $headers array (it
		// contains the secret key) — only the endpoint and body, which
		// carries customer/payment details but never the credential itself.
		TR_Logger::debug( 'IremboPay API request', [
			'endpoint' => $endpoint,
			'body'     => $body,
		] );

		$curl = curl_init();
		curl_setopt_array( $curl, [
			CURLOPT_URL            => self::PROXY_URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => $headers,
		] );

		$response   = curl_exec( $curl );
		$http_code  = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$curl_error = curl_error( $curl );
		curl_close( $curl );

		if ( $curl_error ) {
			TR_Logger::error( 'IremboPay API cURL error', [
				'endpoint' => $endpoint,
				'error'    => $curl_error,
			] );
			return [ 'success' => false, 'message' => $curl_error, 'http_code' => 0 ];
		}

		$decoded = json_decode( $response, true );

		TR_Logger::debug( 'IremboPay API response', [
			'endpoint'  => $endpoint,
			'http_code' => $http_code,
			'response'  => $decoded,
		] );

		return is_array( $decoded )
			? array_merge( $decoded, [ 'http_code' => $http_code ] )
			: [ 'success' => false, 'message' => 'Unexpected response', 'http_code' => $http_code ];
	}
}
