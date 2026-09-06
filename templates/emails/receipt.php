<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Payment receipt email. Included by
 * TR_Notifications::render_receipt_template() with $user (WP_User),
 * $invoice (object: period, amount, currency, paid_at, payment_method,
 * payment_reference), $students and $access_url already in scope.
 * Same inline-CSS, table-safe, max-width 600px layout as welcome.php.
 *
 * $access_url carries a message token (spec v0.7.0), freshly minted for
 * this send — every parent-facing link must carry one, this email is no
 * exception.
 */
$first_name = $user->first_name ? $user->first_name : $user->display_name;
$paid_date  = $invoice->paid_at ? date_i18n( get_option( 'date_format' ), strtotime( $invoice->paid_at ) ) : '';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
	<tr>
		<td align="center">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial, Helvetica, sans-serif;color:#1a2535;">
				<tr>
					<td style="background:#1a2535;padding:24px 32px;">
						<span style="color:#ffffff;font-size:20px;font-weight:bold;"><?php esc_html_e( 'Tangnest Robotics', 'tangnest-robotics' ); ?></span>
					</td>
				</tr>
				<tr>
					<td style="padding:32px;">
						<p style="font-size:16px;margin:0 0 16px;">
							<?php
							printf(
								/* translators: %s: parent first name */
								esc_html__( 'Hi %s,', 'tangnest-robotics' ),
								esc_html( $first_name )
							);
							?>
						</p>

						<p style="font-size:15px;line-height:1.6;margin:0 0 20px;">
							<?php esc_html_e( 'Thank you — your payment has been received.', 'tangnest-robotics' ); ?>
						</p>

						<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;border:1px solid #b6ecec;border-radius:8px;background:#e6fbfb;">
							<tr>
								<td style="padding:16px 20px;">
									<p style="margin:0 0 8px;font-size:22px;font-weight:bold;color:#1a2535;">
										<?php echo esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency ); ?>
									</p>
									<p style="margin:0;font-size:13px;color:#555555;">
										<?php
										printf(
											/* translators: 1: billing period, 2: paid date */
											esc_html__( 'Period %1$s — paid %2$s', 'tangnest-robotics' ),
											esc_html( $invoice->period ),
											esc_html( $paid_date )
										);
										?>
									</p>
									<?php if ( ! empty( $invoice->payment_reference ) ) : ?>
										<p style="margin:8px 0 0;font-size:12px;color:#8a8f98;">
											<?php
											printf(
												/* translators: %s: IremboPay transaction ID */
												esc_html__( 'Transaction: %s', 'tangnest-robotics' ),
												esc_html( $invoice->payment_reference )
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<?php if ( ! empty( $students ) ) : ?>
							<p style="font-size:15px;line-height:1.6;margin:0 0 8px;font-weight:bold;"><?php esc_html_e( 'This covers:', 'tangnest-robotics' ); ?></p>
							<ul style="font-size:15px;line-height:1.6;margin:0 0 20px;padding-left:20px;">
								<?php foreach ( $students as $student ) : ?>
									<li><?php echo esc_html( $student['student_name'] ?? '' ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( ! empty( $access_url ) ) : ?>
							<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0;">
								<tr>
									<td style="border-radius:6px;background:#12c4c4;">
										<a href="<?php echo esc_url( $access_url ); ?>" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">
											<?php esc_html_e( 'View your payment schedule', 'tangnest-robotics' ); ?>
										</a>
									</td>
								</tr>
							</table>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="background:#f4f5f7;padding:16px 32px;font-size:12px;color:#8a8f98;">
						<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
