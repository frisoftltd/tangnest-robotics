<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Welcome email body. Included by TR_Notifications::render_welcome_template()
 * with $user (WP_User), $reset_url, $dashboard_url and $students (array of
 * objects with ->name / ->program) already in scope.
 *
 * Table-based layout, inline CSS, max-width 600px — written to survive
 * being mangled by webmail clients that strip <style> blocks.
 */
$first_name = $user->first_name ? $user->first_name : $user->display_name;
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

						<p style="font-size:15px;line-height:1.6;margin:0 0 16px;">
							<?php esc_html_e( 'This is your Tangnest Robotics parent account. From here you’ll be able to see your child’s classes and progress.', 'tangnest-robotics' ); ?>
						</p>

						<?php if ( ! empty( $students ) ) : ?>
							<p style="font-size:15px;line-height:1.6;margin:0 0 8px;font-weight:bold;"><?php esc_html_e( 'Your children:', 'tangnest-robotics' ); ?></p>
							<ul style="font-size:15px;line-height:1.6;margin:0 0 16px;padding-left:20px;">
								<?php foreach ( $students as $student ) : ?>
									<li>
										<?php
										echo esc_html( $student->name );
										if ( ! empty( $student->program ) ) {
											echo ' &mdash; ' . esc_html( $student->program );
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
							<tr>
								<td style="border-radius:6px;background:#12c4c4;">
									<a href="<?php echo esc_url( $reset_url ); ?>" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">
										<?php esc_html_e( 'Set your password', 'tangnest-robotics' ); ?>
									</a>
								</td>
							</tr>
						</table>

						<p style="font-size:13px;line-height:1.6;color:#555555;margin:0 0 16px;word-break:break-all;">
							<?php esc_html_e( 'Or copy this link into your browser:', 'tangnest-robotics' ); ?><br>
							<?php echo esc_html( $reset_url ); ?>
						</p>

						<p style="font-size:13px;line-height:1.6;color:#555555;margin:0 0 16px;">
							<?php esc_html_e( 'This link is valid for 24 hours. If it has expired, contact Tangnest and we will send you a new one.', 'tangnest-robotics' ); ?>
						</p>

						<?php if ( ! empty( $dashboard_url ) ) : ?>
							<p style="font-size:13px;line-height:1.6;color:#555555;margin:0;">
								<?php esc_html_e( 'Once your password is set, visit your dashboard any time at:', 'tangnest-robotics' ); ?><br>
								<a href="<?php echo esc_url( $dashboard_url ); ?>" style="color:#12c4c4;"><?php echo esc_html( $dashboard_url ); ?></a>
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
