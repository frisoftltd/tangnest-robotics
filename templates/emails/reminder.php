<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Payment reminder email body. Included by
 * TR_Notifications::render_reminder_template() with $user (WP_User),
 * $invoice (object: period, amount, currency, due_date, status),
 * $students (array, same shape as invoice-issued.php), $days_overdue (int,
 * 0 unless the invoice is actually overdue), $access_url and $pay_url
 * already in scope. Same inline-CSS, table-safe, max-width 600px layout
 * as welcome.php.
 *
 * Both carry a message token (spec v0.7.0) minted fresh for this one send
 * — independent of the device-bound access token, so this email can never
 * invalidate a link the admin sent by WhatsApp or vice versa. $pay_url is
 * '' whenever $access_url is (no dashboard page configured); the Pay now
 * block below is gated on it accordingly.
 */
$first_name = $user->first_name ? $user->first_name : $user->display_name;
$due_date   = date_i18n( get_option( 'date_format' ), strtotime( $invoice->due_date ) );
$is_overdue = $days_overdue > 0;
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
							<?php
							if ( $is_overdue ) {
								esc_html_e( 'This is a reminder that a payment is now overdue on your Tangnest Robotics account.', 'tangnest-robotics' );
							} else {
								esc_html_e( 'This is a reminder that a payment is due soon on your Tangnest Robotics account.', 'tangnest-robotics' );
							}
							?>
						</p>

						<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;border:1px solid <?php echo $is_overdue ? '#f6c9c4' : '#e2e5eb'; ?>;border-radius:8px;background:<?php echo $is_overdue ? '#fdeaea' : '#ffffff'; ?>;">
							<tr>
								<td style="padding:16px 20px;">
									<p style="margin:0 0 8px;font-size:22px;font-weight:bold;color:#1a2535;">
										<?php echo esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency ); ?>
									</p>
									<p style="margin:0;font-size:13px;color:#555555;">
										<?php
										if ( $is_overdue ) {
											printf(
												/* translators: 1: billing period, 2: due date, 3: days overdue */
												esc_html__( 'Period %1$s — was due %2$s (%3$d days overdue)', 'tangnest-robotics' ),
												esc_html( $invoice->period ),
												esc_html( $due_date ),
												(int) $days_overdue
											);
										} else {
											printf(
												/* translators: 1: billing period, 2: due date */
												esc_html__( 'Period %1$s — due %2$s', 'tangnest-robotics' ),
												esc_html( $invoice->period ),
												esc_html( $due_date )
											);
										}
										?>
									</p>
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

						<p style="font-size:15px;line-height:1.6;margin:0 0 16px;">
							<?php esc_html_e( 'If you have already paid, please let Tangnest know so we can update your record — sorry for the reminder in that case.', 'tangnest-robotics' ); ?>
						</p>

						<?php if ( '' !== $pay_url && TR_IremboPay_Settings::is_enabled() && TR_Payment::is_payable( $invoice ) ) : ?>
							<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0;">
								<tr>
									<td style="border-radius:6px;background:#12c4c4;">
										<a href="<?php echo esc_url( $pay_url ); ?>" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">
											<?php esc_html_e( 'Pay now', 'tangnest-robotics' ); ?>
										</a>
									</td>
								</tr>
							</table>
						<?php endif; ?>

						<?php if ( ! empty( $access_url ) ) : ?>
							<p style="margin:16px 0 0;">
								<a href="<?php echo esc_url( $access_url ); ?>" style="font-size:14px;color:#12c4c4;text-decoration:underline;">
									<?php esc_html_e( 'View your payment schedule', 'tangnest-robotics' ); ?>
								</a>
							</p>
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
