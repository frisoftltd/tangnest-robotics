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

			<?php if ( TR_Parent_Dashboard::just_paid() ) : ?>
				<p class="tr-dashboard__notice tr-dashboard__notice--token">
					<?php esc_html_e( 'Payment received — thank you! It may take a minute to appear below.', 'tangnest-robotics' ); ?>
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

				<?php
				// Every child on the family's package shows the same
				// package name and progress (v0.8.0) — siblings finish
				// together, so this is computed once, not per child.
				$family_package = ! empty( $family->package_id ) ? TR_Programs::get( (int) $family->package_id ) : null;
				$package_months_total = $family_package ? (int) $family_package->duration_months : 0;

				$family_percent = 0;
				if ( $package_months_total > 0 ) {
					$family_percent = min( 100, (int) round( ( (int) $family->months_paid / $package_months_total ) * 100 ) );
				}
				?>

				<div class="tr-dashboard__cards">
					<?php foreach ( $students as $student ) : ?>
						<div class="tr-dashboard__card">
							<h3><?php echo esc_html( trim( $student->first_name . ' ' . $student->last_name ) ); ?></h3>
							<p class="tr-dashboard__program"><?php echo esc_html( $family_package->name ?? __( 'No package yet', 'tangnest-robotics' ) ); ?></p>
							<?php if ( $family_package ) : ?>
								<p class="tr-dashboard__progress-label"><?php echo esc_html( TR_Families::progress_label( $family ) ); ?></p>
								<div class="tr-dashboard__progress-bar">
									<div class="tr-dashboard__progress-fill" style="width: <?php echo esc_attr( $family_percent ); ?>%;"></div>
								</div>
							<?php endif; ?>
							<p class="tr-dashboard__status tr-dashboard__status--<?php echo esc_attr( $student->status ); ?>"><?php echo esc_html( ucfirst( $student->status ) ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

			<?php
			$invoices = TR_Invoices::get_by_family( (int) $family->id );

			$due_invoices = array_values( array_filter( $invoices, static function ( $invoice ) {
				return in_array( $invoice->status, [ 'pending', 'overdue' ], true );
			} ) );
			usort( $due_invoices, static function ( $a, $b ) {
				return strcmp( $a->due_date, $b->due_date );
			} );

			$total_due = 0.0;
			foreach ( $due_invoices as $invoice ) {
				$total_due += (float) $invoice->amount;
			}

			$paid_invoices = array_values( array_filter( $invoices, static function ( $invoice ) {
				return 'paid' === $invoice->status;
			} ) );
			?>

			<h3 class="tr-dashboard__section-title"><?php esc_html_e( 'Amount Due', 'tangnest-robotics' ); ?></h3>

			<?php if ( empty( $due_invoices ) ) : ?>

				<div class="tr-dashboard__due-card tr-dashboard__due-card--ok">
					<p class="tr-dashboard__due-amount"><?php esc_html_e( 'You\'re up to date', 'tangnest-robotics' ); ?></p>
					<p class="tr-dashboard__due-sub"><?php esc_html_e( 'No payment is currently due.', 'tangnest-robotics' ); ?></p>
				</div>

			<?php else : ?>

				<?php
				$earliest_due = $due_invoices[0];
				$day_diff     = (int) floor( ( strtotime( $earliest_due->due_date ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
				$is_overdue   = 'overdue' === $earliest_due->status || $day_diff < 0;
				?>
				<div class="tr-dashboard__due-card <?php echo $is_overdue ? 'tr-dashboard__due-card--overdue' : 'tr-dashboard__due-card--due'; ?>">
					<p class="tr-dashboard__due-amount"><?php echo esc_html( number_format( $total_due, 2 ) . ' ' . $earliest_due->currency ); ?></p>
					<p class="tr-dashboard__due-sub">
						<?php
						if ( $is_overdue ) {
							printf(
								/* translators: 1: due date, 2: days overdue */
								esc_html__( 'Was due %1$s — %2$d day(s) overdue', 'tangnest-robotics' ),
								esc_html( $earliest_due->due_date ),
								absint( abs( $day_diff ) )
							);
						} else {
							printf(
								/* translators: 1: due date, 2: days remaining */
								esc_html__( 'Due %1$s — %2$d day(s) remaining', 'tangnest-robotics' ),
								esc_html( $earliest_due->due_date ),
								absint( $day_diff )
							);
						}
						?>
					</p>
					<?php if ( TR_IremboPay_Settings::is_enabled() ) : ?>
						<?php if ( TR_Payment::is_payable( $earliest_due ) ) : ?>
							<a class="tr-payment-button tr-payment-button--dashboard" href="<?php echo esc_url( TR_Payment::payment_page_url( (int) $earliest_due->id ) ); ?>"><?php esc_html_e( 'Pay now', 'tangnest-robotics' ); ?></a>
						<?php else : ?>
							<p class="tr-dashboard__due-sub"><?php esc_html_e( 'Contact Tangnest to pay this month.', 'tangnest-robotics' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>

			<?php endif; ?>

			<h3 class="tr-dashboard__section-title"><?php esc_html_e( 'Payment Schedule', 'tangnest-robotics' ); ?></h3>

			<?php if ( empty( $invoices ) ) : ?>

				<div class="tr-dashboard__empty"><p><?php esc_html_e( 'No invoices yet.', 'tangnest-robotics' ); ?></p></div>

			<?php else : ?>

				<div class="tr-dashboard__invoice-list">
					<?php foreach ( $invoices as $invoice ) : ?>
						<div class="tr-dashboard__invoice-row">
							<span class="tr-dashboard__invoice-period"><?php echo esc_html( $invoice->period ); ?></span>
							<span class="tr-dashboard__invoice-amount"><?php echo esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency ); ?></span>
							<span class="tr-badge tr-badge--<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( ucfirst( $invoice->status ) ); ?></span>
							<?php if ( TR_IremboPay_Settings::is_enabled() && in_array( $invoice->status, [ 'pending', 'overdue' ], true ) ) : ?>
								<?php if ( TR_Payment::is_payable( $invoice ) ) : ?>
									<a class="tr-payment-button tr-payment-button--small" href="<?php echo esc_url( TR_Payment::payment_page_url( (int) $invoice->id ) ); ?>"><?php esc_html_e( 'Pay now', 'tangnest-robotics' ); ?></a>
								<?php else : ?>
									<span class="tr-dashboard__invoice-meta"><?php esc_html_e( 'Contact Tangnest to pay this month.', 'tangnest-robotics' ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

			<?php if ( ! empty( $paid_invoices ) ) : ?>

				<h3 class="tr-dashboard__section-title"><?php esc_html_e( 'History', 'tangnest-robotics' ); ?></h3>

				<div class="tr-dashboard__invoice-list">
					<?php foreach ( $paid_invoices as $invoice ) : ?>
						<div class="tr-dashboard__invoice-row">
							<span class="tr-dashboard__invoice-period"><?php echo esc_html( $invoice->period ); ?></span>
							<span class="tr-dashboard__invoice-amount"><?php echo esc_html( number_format( (float) $invoice->amount, 2 ) . ' ' . $invoice->currency ); ?></span>
							<span class="tr-dashboard__invoice-meta">
								<?php
								echo esc_html( $invoice->paid_at ? substr( $invoice->paid_at, 0, 10 ) : '' );
								if ( ! empty( $invoice->payment_method ) ) {
									echo ' &middot; ' . esc_html( ucfirst( str_replace( '_', ' ', $invoice->payment_method ) ) );
								}
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

			<?php if ( ! TR_IremboPay_Settings::is_enabled() ) : ?>
				<p class="tr-dashboard__footer"><?php esc_html_e( 'Online payment is coming soon. For now, payments are recorded by Tangnest directly.', 'tangnest-robotics' ); ?></p>
			<?php endif; ?>

		<?php endif; ?>

	<?php endif; ?>
</div>
