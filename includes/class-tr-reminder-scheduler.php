<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Automatic payment reminders, run as part of the daily cron job (see
 * TR_Cron::run()) after invoice generation. Idempotent per invoice per
 * stage via TR_Invoices::reminder_stages_sent — running this twice in one
 * day sends each stage at most once. A manual "Send reminder" row action
 * never touches that column, so it can never suppress a stage this
 * scheduler would otherwise still send.
 */
class TR_Reminder_Scheduler {
	const OPTION_ENABLED_STAGES = 'tangnest_robotics_reminder_stages';

	/**
	 * offset_days is "days after the due date" — negative means before.
	 * status is the invoice status this stage only ever applies to.
	 */
	const STAGES = [
		'before_3' => [ 'offset_days' => -3, 'status' => 'pending' ],
		'on_due'   => [ 'offset_days' => 0, 'status' => 'pending' ],
		'after_3'  => [ 'offset_days' => 3, 'status' => 'overdue' ],
		'after_7'  => [ 'offset_days' => 7, 'status' => 'overdue' ],
	];

	const STAGE_LABELS = [
		'before_3' => 'Three days before the due date (while still pending)',
		'on_due'   => 'On the due date (while still pending)',
		'after_3'  => 'Three days after the due date (once overdue)',
		'after_7'  => 'Seven days after the due date (once overdue)',
	];

	public static function enabled_stages(): array {
		$defaults = array_fill_keys( array_keys( self::STAGES ), true );
		$saved    = get_option( self::OPTION_ENABLED_STAGES, $defaults );

		return is_array( $saved ) ? wp_parse_args( $saved, $defaults ) : $defaults;
	}

	public static function run(): void {
		$enabled = self::enabled_stages();
		$today   = current_datetime();
		$sent    = 0;

		foreach ( self::STAGES as $stage_key => $config ) {
			if ( empty( $enabled[ $stage_key ] ) ) {
				continue;
			}

			// due_date = today - offset_days.
			$target_date = $today->modify( sprintf( '%+d days', -$config['offset_days'] ) )->format( 'Y-m-d' );

			$invoices = TR_Invoices::get_list( [
				'status'   => $config['status'],
				'per_page' => 10000,
			] );

			foreach ( $invoices as $invoice ) {
				if ( $invoice->due_date !== $target_date ) {
					continue;
				}

				if ( TR_Invoices::has_sent_stage( (int) $invoice->id, $stage_key ) ) {
					continue;
				}

				$mail_sent = TR_Notifications::send_reminder_email( (int) $invoice->id );

				// Recorded whether or not the send succeeded — this stage is
				// tied to this invoice's fixed due_date, so a missed send
				// here has no future day where the target_date would match
				// again. A failure is logged loudly instead; the admin can
				// always trigger a manual reminder from the row action.
				TR_Invoices::record_automatic_reminder( (int) $invoice->id, $stage_key );

				if ( $mail_sent ) {
					$sent++;
					TR_Logger::info( 'Automatic reminder sent', [ 'invoice_id' => $invoice->id, 'stage' => $stage_key ] );
				} else {
					TR_Logger::error( 'Automatic reminder failed to send', [ 'invoice_id' => $invoice->id, 'stage' => $stage_key ] );
				}
			}
		}

		TR_Logger::info( 'Reminder scheduler run complete', [ 'sent' => $sent ] );
	}
}
