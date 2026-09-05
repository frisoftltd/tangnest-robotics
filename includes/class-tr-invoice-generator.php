<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Monthly invoice generation. Always evaluates against today only — there
 * is no parameter anywhere in this class that accepts an arbitrary date,
 * which is what makes it structurally impossible for a cron run (or a
 * plugin outage of several weeks) to silently backfill past periods.
 * Catching up on missed months is the admin's "Create invoice" action on
 * the Families screen, not something this class ever does on its own.
 */
class TR_Invoice_Generator {

	public static function run(): void {
		$today     = current_datetime();
		$today_str = $today->format( 'Y-m-d' );
		$period    = $today->format( 'Y-m' );

		$families = TR_Families::get_list( [ 'status' => 'active', 'per_page' => 10000 ] );
		$created  = 0;

		foreach ( $families as $family ) {
			$family_id = (int) $family->id;

			$active_enrollments = TR_Enrollments::get_active_by_family( $family_id );
			if ( empty( $active_enrollments ) ) {
				continue;
			}

			if ( ! self::is_billing_day( (int) $family->billing_day, $today ) ) {
				continue;
			}

			if ( null !== TR_Invoices::get_for_period( $family_id, $period ) ) {
				continue;
			}

			$amount = (float) $family->monthly_amount;
			if ( $amount <= 0 ) {
				TR_Logger::warning( 'Invoice generation skipped: monthly_amount is zero', [ 'family_id' => $family_id ] );
				continue;
			}

			$invoice_id = TR_Invoices::insert( [
				'family_id'        => $family_id,
				'period'           => $period,
				'amount'           => $amount,
				'currency'         => $family->currency ?: 'RWF',
				'status'           => 'pending',
				'due_date'         => $today_str,
				'issued_at'        => current_time( 'mysql' ),
				'student_snapshot' => self::build_student_snapshot( $active_enrollments ),
			] );

			if ( $invoice_id <= 0 ) {
				// The unique (family_id, period) key is the real guard — this
				// only fires if something else inserted the same period
				// between our get_for_period() check and this insert().
				TR_Logger::error( 'Invoice insert failed (likely a duplicate period)', [
					'family_id' => $family_id,
					'period'    => $period,
				] );
				continue;
			}

			$created++;

			TR_Logger::info( 'Invoice generated', [
				'family_id'  => $family_id,
				'invoice_id' => $invoice_id,
				'period'     => $period,
				'amount'     => number_format( $amount, 2, '.', '' ),
			] );

			TR_Notifications::send_invoice_issued_email( $family_id, $invoice_id );
		}

		$overdue_count = TR_Invoices::mark_overdue_due_before( $today_str );

		TR_Logger::info( 'Invoice generation run complete', [
			'created'        => $created,
			'marked_overdue' => $overdue_count,
		] );
	}

	/**
	 * True on the family's billing day, or on the last day of a month
	 * shorter than the billing day. Anchors are clamped to a maximum of 28
	 * at creation (TR_Families::set_billing_anchor()), and every month has
	 * at least 28 days, so the second branch is belt-and-braces — it
	 * cannot currently fire, but it's cheap insurance against that
	 * invariant changing later.
	 */
	private static function is_billing_day( int $billing_day, DateTimeInterface $today ): bool {
		if ( $billing_day < 1 ) {
			return false;
		}

		$day_of_month = (int) $today->format( 'j' );
		if ( $day_of_month === $billing_day ) {
			return true;
		}

		$last_day_of_month = (int) $today->format( 't' );

		return $day_of_month === $last_day_of_month && $last_day_of_month < $billing_day;
	}

	private static function build_student_snapshot( array $enrollments ): array {
		$snapshot = [];

		foreach ( $enrollments as $enrollment ) {
			$student = TR_Students::get( (int) $enrollment->student_id );
			$program = TR_Programs::get( (int) $enrollment->program_id );

			$months_total = max( (int) $enrollment->months_total, 1 );
			$month_number = min( (int) $enrollment->months_paid + 1, $months_total );

			$snapshot[] = [
				'student_name' => $student ? trim( $student->first_name . ' ' . $student->last_name ) : '',
				'program_name' => $program->name ?? '',
				'month_number' => $month_number,
				'months_total' => $months_total,
			];
		}

		return $snapshot;
	}
}
