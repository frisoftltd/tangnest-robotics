<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * "Invoice issued" email body. Included by
 * TR_Notifications::render_invoice_issued_template() with $user (WP_User),
 * $invoice (object: period, amount, currency, due_date), $students (array
 * of ['student_name'=>, 'program_name'=>, 'month_number'=>, 'months_total'=>]),
 * $dashboard_url and $access_url already in scope.
 *
 * $access_url is '' unless the family's current passwordless link is still
 * reusable — it is never minted here (see TR_Access_Tokens::get_reusable_url_only()).
 * The schedule link below falls back to the plain $dashboard_url when empty.
 *
 * Same inline-CSS, table-safe, max-width 600px layout as welcome.php.
 */
$first_name    = $user->first_name ? $user->first_name : $user->display_name;
$due_date      = date_i18n( get_option( 'date_format' ), strtotime( $invoice->due_date ) );
$schedule_link = '' !== $access_url ? $access_url : $dashboard_url;
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
							<?php esc_html_e( 'A new invoice is ready for your Tangnest Robotics account.', 'tangnest-robotics' ); ?>
						</p>

						<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;border:1px solid #e2e5eb;border-radius:8px;">
							<tr>
								<td style="padding:16px 20px;">
									<p style="margin:0 0 8px;font-size:22px;font-weight:bold;color:#1a2535;">
										<?php echo esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency ); ?>
									</p>
									<p style="margin:0;font-size:13px;color:#555555;">
										<?php
										printf(
											/* translators: 1: billing period, 2: due date */
											esc_html__( 'Period %1$s — due %2$s', 'tangnest-robotics' ),
											esc_html( $invoice->period ),
											esc_html( $due_date )
										);
										?>
									</p>
								</td>
							</tr>
						</table>

						<?php if ( ! empty( $students ) ) : ?>
							<p style="font-size:15px;line-height:1.6;margin:0 0 8px;font-weight:bold;"><?php esc_html_e( 'This covers:', 'tangnest-robotics' ); ?></p>
							<ul style="font-size:15px;line-height:1.6;margin:0 0 20px;padding-left:20px;">
								<?php foreach ( $students as $student ) : ?>
									<li>
										<?php
										echo esc_html( $student['student_name'] ?? '' );
										if ( ! empty( $student['program_name'] ) ) {
											echo ' &mdash; ' . esc_html( $student['program_name'] );
										}
										if ( ! empty( $student['month_number'] ) && ! empty( $student['months_total'] ) ) {
											echo ' ' . esc_html(
												sprintf(
													/* translators: 1: current month, 2: total months */
													__( '(month %1$d of %2$d)', 'tangnest-robotics' ),
													(int) $student['month_number'],
													(int) $student['months_total']
												)
											);
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( TR_IremboPay_Settings::is_enabled() ) : ?>
							<p style="font-size:15px;line-height:1.6;margin:0 0 16px;">
								<?php esc_html_e( 'You can pay online right now with IremboPay, or keep paying cash, bank transfer or mobile money as usual — just let us know once you\'ve paid so we can mark it.', 'tangnest-robotics' ); ?>
							</p>

							<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0;">
								<tr>
									<td style="border-radius:6px;background:#12c4c4;">
										<a href="<?php echo esc_url( TR_Payment::payment_page_url( (int) $invoice->id ) ); ?>" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">
											<?php esc_html_e( 'Pay now', 'tangnest-robotics' ); ?>
										</a>
									</td>
								</tr>
							</table>
						<?php else : ?>
							<p style="font-size:15px;line-height:1.6;margin:0 0 16px;">
								<?php esc_html_e( 'Payment is currently recorded by Tangnest directly — cash, bank transfer or mobile money. Just let us know once you\'ve paid so we can mark it.', 'tangnest-robotics' ); ?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $schedule_link ) ) : ?>
							<p style="margin:16px 0 0;">
								<a href="<?php echo esc_url( $schedule_link ); ?>" style="font-size:14px;color:#12c4c4;text-decoration:underline;">
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
