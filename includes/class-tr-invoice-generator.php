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

			if ( empty( $family->package_id ) ) {
				TR_Logger::warning( 'Invoice generation skipped: family has no package', [ 'family_id' => $family_id ] );
				continue;
			}

			$active_students = TR_Students::get_list( [ 'family_id' => $family_id, 'status' => 'active', 'per_page' => 200 ] );
			if ( empty( $active_students ) ) {
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
				'student_snapshot' => self::build_student_snapshot( $family, $active_students ),
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

	/**
	 * Shared by the daily generator and the admin "Create invoice" action —
	 * one place that knows how to build the frozen-at-issue-time snapshot
	 * an invoice email reads later. Every row carries the same family-level
	 * package name and progress figures (v0.8.0: siblings finish together,
	 * so there is one figure to show, not one per child); $active_students
	 * is accepted rather than re-queried so a caller that already has the
	 * list (the daily loop) doesn't fetch it twice.
	 */
	public static function build_student_snapshot( object $family, ?array $active_students = null ): array {
		if ( null === $active_students ) {
			$active_students = TR_Students::get_list( [ 'family_id' => (int) $family->id, 'status' => 'active', 'per_page' => 200 ] );
		}

		$package      = ! empty( $family->package_id ) ? TR_Programs::get( (int) $family->package_id ) : null;
		$package_name = $package->name ?? '';
		$months_total = $package ? max( (int) $package->duration_months, 1 ) : 1;
		$month_number = min( (int) $family->months_paid + 1, $months_total );

		$snapshot = [];
		foreach ( $active_students as $student ) {
			$snapshot[] = [
				'student_name' => trim( $student->first_name . ' ' . $student->last_name ),
				'package_name' => $package_name,
				'month_number' => $month_number,
				'months_total' => $months_total,
			];
		}

		return $snapshot;
	}
}
