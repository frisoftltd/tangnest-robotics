<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * [tangnest_parent_dashboard] output. The family shown here is resolved
 * ONLY from get_current_user_id() — this file must never read $_GET or
 * $_POST. That is what stops one parent editing a URL to see another
 * family's children.
 */
?>
<div class="tr-dashboard">
	<?php if ( ! is_user_logged_in() ) : ?>

		<?php if ( TR_Parent_Dashboard::consume_dead_token_notice() ) : ?>
			<p class="tr-dashboard__notice tr-dashboard__notice--dead">
				<?php esc_html_e( 'This link is no longer active. Contact Tangnest and we\'ll send you a new one.', 'tangnest-robotics' ); ?>
			</p>
		<?php endif; ?>

		<div class="tr-dashboard__login">
			<h2><?php esc_html_e( 'Parent Login', 'tangnest-robotics' ); ?></h2>
			<?php
			wp_login_form( [
				'redirect' => get_permalink(),
			] );
			?>
		</div>

	<?php else : ?>

		<?php
		$current_user = wp_get_current_user();
		$family       = TR_Families::get_by_user( $current_user->ID );
		?>

		<?php if ( null === $family ) : ?>

			<div class="tr-dashboard__empty">
				<p><?php esc_html_e( 'We could not find a Tangnest Robotics family linked to your account. Please contact Tangnest for help.', 'tangnest-robotics' ); ?></p>
			</div>

		<?php else : ?>

			<?php $students = TR_Students::get_list( [ 'family_id' => (int) $family->id, 'per_page' => 200 ] ); ?>

			<?php if ( TR_Parent_Dashboard::consume_token_login_notice( $current_user->ID ) ) : ?>
				<p class="tr-dashboard__notice tr-dashboard__notice--token" id="tr-token-notice">
					<?php esc_html_e( 'You\'re signed in with your private link. For extra security you can set a password.', 'tangnest-robotics' ); ?>
					<button type="button" class="tr-dashboard__notice-dismiss" onclick="document.getElementById('tr-token-notice').style.display='none';" aria-label="<?php esc_attr_e( 'Dismiss', 'tangnest-robotics' ); ?>">&times;</button>
				</p>
			<?php endif; ?>

			<div class="tr-dashboard__header">
				<h2>
					<?php
					printf(
						/* translators: %s: parent first name or display name */
						esc_html__( 'Welcome, %s', 'tangnest-robotics' ),
						esc_html( $current_user->first_name ? $current_user->first_name : $current_user->display_name )
					);
					?>
				</h2>
			</div>

			<?php if ( empty( $students ) ) : ?>

				<div class="tr-dashboard__empty">
					<p><?php esc_html_e( 'No children are enrolled yet.', 'tangnest-robotics' ); ?></p>
				</div>

			<?php else : ?>

				<div class="tr-dashboard__cards">
					<?php foreach ( $students as $student ) : ?>
						<?php
						$enrollments = TR_Enrollments::get_by_student( (int) $student->id );
						$enrollment  = $enrollments[0] ?? null;
						$program     = $enrollment ? TR_Programs::get( (int) $enrollment->program_id ) : null;

						$percent = 0;
						if ( $enrollment && (int) $enrollment->months_total > 0 ) {
							$percent = min( 100, (int) round( ( (int) $enrollment->months_paid / (int) $enrollment->months_total ) * 100 ) );
						}
						?>
						<div class="tr-dashboard__card">
							<h3><?php echo esc_html( trim( $student->first_name . ' ' . $student->last_name ) ); ?></h3>
							<p class="tr-dashboard__program"><?php echo esc_html( $program->name ?? __( 'No program yet', 'tangnest-robotics' ) ); ?></p>
							<?php if ( $enrollment ) : ?>
								<p class="tr-dashboard__progress-label"><?php echo esc_html( TR_Enrollments::progress_label( $enrollment ) ); ?></p>
								<div class="tr-dashboard__progress-bar">
									<div class="tr-dashboard__progress-fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
								</div>
							<?php endif; ?>
							<p class="tr-dashboard__status tr-dashboard__status--<?php echo esc_attr( $student->status ); ?>"><?php echo esc_html( ucfirst( $student->status ) ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

			<p class="tr-dashboard__footer"><?php esc_html_e( 'Payment details are coming soon.', 'tangnest-robotics' ); ?></p>

		<?php endif; ?>

	<?php endif; ?>
</div>
