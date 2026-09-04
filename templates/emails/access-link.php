<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Standalone access-link email. Included by
 * TR_Notifications::render_access_link_template() with $user (WP_User) and
 * $access_url already in scope. Same inline-CSS, table-safe, max-width
 * 600px layout as welcome.php.
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
							<?php esc_html_e( 'Here is your Tangnest Robotics parent page. It shows your children and their class progress — no password needed.', 'tangnest-robotics' ); ?>
						</p>

						<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
							<tr>
								<td style="border-radius:6px;background:#12c4c4;">
									<a href="<?php echo esc_url( $access_url ); ?>" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">
										<?php esc_html_e( 'Open your page', 'tangnest-robotics' ); ?>
									</a>
								</td>
							</tr>
						</table>

						<p style="font-size:13px;line-height:1.6;color:#555555;margin:0 0 16px;word-break:break-all;">
							<?php esc_html_e( 'Or copy this link into your browser:', 'tangnest-robotics' ); ?><br>
							<?php echo esc_html( $access_url ); ?>
						</p>

						<p style="font-size:13px;line-height:1.6;color:#555555;margin:0;">
							<?php esc_html_e( 'Open it on the phone you want to use. The link stops working after a short while — if you lose it, just ask us for a new one.', 'tangnest-robotics' ); ?>
						</p>
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
