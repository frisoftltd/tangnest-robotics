<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * IremboPay payment page. Included by
 * TR_Parent_Dashboard::render_shortcode() with $payment_result already in
 * scope (see TR_Payment::initiate()). Ownership of the invoice by the
 * CURRENT session's family was already verified there — this file must
 * never read $_GET or $_POST, and never trust an ID from the request for
 * authorization, same rule as parent-dashboard.php.
 *
 * The inline.js widget call, its callback signature, and the
 * spinner/error/reopen flow below match the sibling woocommerce-irembopay
 * plugin's templates/payment-page.php, which runs this exact modal against
 * live IremboPay traffic. In particular: callback is a single error-first
 * function, not an {onSuccess,onFail,onClose} object — and a failed or
 * cancelled attempt re-opens the SAME invoice's modal client-side rather
 * than bouncing back to the server, since mobile networks make payment
 * modals fail often enough that this needs to be a one-tap retry.
 */
$dashboard_url = TR_Parent_Dashboard::get_url();
?>
<div class="tr-dashboard tr-payment-page">

	<?php if ( empty( $payment_result['success'] ) ) : ?>

		<div class="tr-dashboard__empty">
			<p><?php echo esc_html( $payment_result['error'] ?: __( 'This payment could not be started.', 'tangnest-robotics' ) ); ?></p>
			<p><a href="<?php echo esc_url( $dashboard_url ); ?>">&larr; <?php esc_html_e( 'Back to your dashboard', 'tangnest-robotics' ); ?></a></p>
		</div>

	<?php else : ?>

		<?php
		$invoice                  = $payment_result['invoice'];
		$irembopay_invoice_number = $payment_result['irembopay_invoice_number'];
		$success_url              = $dashboard_url ? add_query_arg( 'tr_paid', 1, $dashboard_url ) : $dashboard_url;
		?>

		<div class="tr-payment-card">
			<h2><?php esc_html_e( 'Complete Your Payment', 'tangnest-robotics' ); ?></h2>
			<p class="tr-payment-amount"><?php echo esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency ); ?></p>
			<p class="tr-payment-period">
				<?php
				printf(
					/* translators: %s: billing period, e.g. 2026-09 */
					esc_html__( 'Period %s', 'tangnest-robotics' ),
					esc_html( $invoice->period )
				);
				?>
			</p>

			<div class="tr-payment-spinner" id="tr-payment-spinner"></div>
			<p id="tr-payment-status"><?php esc_html_e( 'A secure payment window is opening. Please do not close this page.', 'tangnest-robotics' ); ?></p>

			<button type="button" id="tr-payment-reopen" class="tr-payment-button" style="display:none;"><?php esc_html_e( 'Open Payment Window', 'tangnest-robotics' ); ?></button>

			<div id="tr-payment-error" class="tr-payment-error" style="display:none;"></div>

			<p class="tr-payment-back"><a href="<?php echo esc_url( $dashboard_url ); ?>">&larr; <?php esc_html_e( 'Back to your dashboard', 'tangnest-robotics' ); ?></a></p>
		</div>

		<script src="https://dashboard.irembopay.com/assets/payment/inline.js"></script>
		<script>
		( function() {
			var publicKey     = <?php echo wp_json_encode( TR_IremboPay_Settings::public_key() ); ?>;
			var invoiceNumber = <?php echo wp_json_encode( $irembopay_invoice_number ); ?>;
			var successUrl    = <?php echo wp_json_encode( $success_url ); ?>;
			var defaultError  = <?php echo wp_json_encode( __( 'Payment failed or was cancelled.', 'tangnest-robotics' ) ); ?>;
			var noLibraryError = <?php echo wp_json_encode( __( 'Could not load the payment window. Please check your connection and try again.', 'tangnest-robotics' ) ); ?>;

			var spinner = document.getElementById( 'tr-payment-spinner' );
			var status  = document.getElementById( 'tr-payment-status' );
			var reopen  = document.getElementById( 'tr-payment-reopen' );
			var errBox  = document.getElementById( 'tr-payment-error' );

			function showError( message ) {
				spinner.style.display = 'none';
				status.style.display  = 'none';
				reopen.style.display  = 'inline-block';
				errBox.style.display  = 'block';
				errBox.textContent    = message || defaultError;
			}

			function initPayment() {
				errBox.style.display  = 'none';
				reopen.style.display  = 'none';
				status.style.display  = 'block';
				spinner.style.display = 'block';

				IremboPay.initiate( {
					publicKey: publicKey,
					invoiceNumber: invoiceNumber,
					locale: IremboPay.locale.EN,
					callback: function( err ) {
						if ( ! err ) {
							window.location.href = successUrl;
						} else {
							showError( err && err.message );
						}
					}
				} );
			}

			reopen.addEventListener( 'click', initPayment );

			document.addEventListener( 'DOMContentLoaded', function() {
				if ( typeof IremboPay === 'undefined' ) {
					showError( noLibraryError );
					return;
				}
				initPayment();
			} );
		} )();
		</script>

	<?php endif; ?>

</div>
