<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Accessor for the IremboPay settings option group. One grouped option
 * rather than seven separate rows in wp_options.
 */
class TR_IremboPay_Settings {
	const OPTION = 'tangnest_robotics_irembopay';

	const DEFAULTS = [
		'enabled'                    => false,
		'secret_key'                 => '',
		'public_key'                 => 'pk_live_184c5a17d75c487d811d562ccf55c270',
		'payment_account_identifier' => 'TANGNEST_RWF',
		'default_product_code'       => '',
		'expiry_hours'               => 24,
		'webhook_secret'             => '',
	];

	public static function get(): array {
		$saved = get_option( self::OPTION, [] );

		return is_array( $saved ) ? wp_parse_args( $saved, self::DEFAULTS ) : self::DEFAULTS;
	}

	public static function save( array $data ): void {
		update_option( self::OPTION, wp_parse_args( $data, self::DEFAULTS ) );
	}

	/**
	 * Online payments are only "on" when the master toggle is checked AND
	 * a secret key is actually present — a bare toggle with no key would
	 * otherwise show Pay buttons that can never succeed.
	 */
	public static function is_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['enabled'] ) && '' !== $settings['secret_key'];
	}

	public static function secret_key(): string {
		return self::get()['secret_key'];
	}

	public static function public_key(): string {
		return self::get()['public_key'];
	}

	public static function payment_account_identifier(): string {
		return self::get()['payment_account_identifier'];
	}

	public static function default_product_code(): string {
		return self::get()['default_product_code'];
	}

	public static function expiry_hours(): int {
		return max( 1, (int) self::get()['expiry_hours'] );
	}

	public static function webhook_secret(): string {
		return self::get()['webhook_secret'];
	}

	public static function webhook_url(): string {
		return rest_url( 'tangnest-robotics/v1/webhook' );
	}

	/**
	 * For display only — the real key is never rendered back into a page,
	 * a log, or a form field.
	 */
	public static function masked_secret_key(): string {
		$key = self::secret_key();
		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	public static function masked_webhook_secret(): string {
		$key = self::webhook_secret();
		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * IremboPay's merchant account has exactly one registered callback URL,
	 * currently owned by the sibling woocommerce-irembopay plugin — this
	 * plugin's webhook only ever arrives there too, via TR_Webhook's
	 * rest_pre_dispatch interception (spec v0.7.1). IremboPay signs (or
	 * doesn't) based on whatever secret is configured against that one
	 * URL, i.e. the WooCommerce plugin's own setting — not this plugin's.
	 * If this plugin has a secret saved but that one doesn't, IremboPay is
	 * not sending a signature at all, and every real webhook would be
	 * rejected with a 401 the moment it's intercepted.
	 */
	public static function has_webhook_secret_mismatch(): bool {
		if ( '' === self::webhook_secret() ) {
			return false;
		}

		$wc_settings = get_option( 'woocommerce_irembopay_settings', [] );
		$wc_secret   = is_array( $wc_settings ) ? ( $wc_settings['webhook_secret'] ?? '' ) : '';

		return '' === $wc_secret;
	}
}
