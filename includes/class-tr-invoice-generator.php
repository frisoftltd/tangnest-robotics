<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Monthly invoice generation. Always evaluates against today only — there
 * is no parameter anywhere in this class that accepts an arbitrary date,
 * which is what makes it structurally impossible for a cron run (or a
 * plugin outage of several weeks) to silently backfill past periods.
 * Catching up on missed months is the admin's "Create invoice" action on
 * the Families screen, not something this class ever does on its own.
 *
 * v0.9.0 splits the old single run() loop into a pure decision step
 * (build_plan()/evaluate_family(), which touches no data and logs
 * nothing) and an execution step (execute_plan(), which does). run() is
 * just those two glued together, plus the overdue sweep. This is what
 * lets `wp tangnest generate --dry-run` show exactly what a real run
 * would do — it calls build_plan() and stops, instead of maintaining a
 * second copy of the eligibility rules that could quietly drift from
 * the real ones.
 */
class TR_Invoice_Generator {

	/**
	 * Skip reasons worth a log line on a real run, and at what level —
	 * matches the levels the pre-v0.9.0 inline version used. Reasons not
	 * listed here (e.g. "not this family's billing day today") are too
	 * routine to log on every single day they don't apply.
	 */
	const SKIP_LOG_LEVELS = [
		'no_package'  => 'warning',
		'ended'       => 'info',
		'zero_amount' => 'warning',
	];

	/**
	 * Runs (or re-runs) generation. $only_family_id restricts both the
	 * decision and the execution to one family — used by
	 * `wp tangnest generate --family=<id>`; the overdue sweep, which is
	 * unrelated to any one family, is skipped in that case so a targeted
	 * run never has a wider side effect than the admin asked for.
	 */
	public static function run( ?int $only_family_id = null ): array {
		$plan = self::build_plan( $only_family_id );

		self::log_skips( $plan['skipped'] );

		$result = self::execute_plan( $plan );

		$overdue_count = null;
		if ( null === $only_family_id ) {
			$overdue_count = TR_Invoices::mark_overdue_due_before( $plan['today'] );
		}

		TR_Logger::info( 'Invoice generation run complete', [
			'created'        => count( $result['created'] ),
			'marked_overdue' => $overdue_count ?? 0,
		] );

		return [
			'plan'           => $plan,
			'created'        => $result['created'],
			'failed'         => $result['failed'],
			'marked_overdue' => $overdue_count,
		];
	}

	/**
	 * The decision step. Reads family/student/invoice data and returns
	 * what generation would do today — never writes anything, sends
	 * nothing, and calls no external API, which is what makes it safe for
	 * `wp tangnest generate --dry-run` to call directly.
	 */
	public static function build_plan( ?int $only_family_id = null ): array {
		$today      = current_datetime();
		$today_str  = $today->format( 'Y-m-d' );
		$period     = $today->format( 'Y-m' );
		$period_end = $today->modify( '+1 month' )->modify( '-1 day' )->format( 'Y-m-d' );

		$families = TR_Families::get_list( [ 'status' => 'active', 'per_page' => 10000 ] );

		$to_bill = [];
		$skipped = [];

		foreach ( $families as $family ) {
			$family_id = (int) $family->id;

			if ( null !== $only_family_id && $family_id !== $only_family_id ) {
				continue;
			}

			$decision = self::evaluate_family( $family, $today, $today_str, $period );

			if ( null !== $decision['skip'] ) {
				$skipped[] = array_merge(
					[ 'family_id' => $family_id ],
					$decision['skip']
				);
				continue;
			}

			$to_bill[] = [
				'family_id'       => $family_id,
				'family'          => $family,
				'period'          => $period,
				'period_start'    => $today_str,
				'period_end'      => $period_end,
				'due_date'        => $today_str,
				'amount'          => $decision['amount'],
				'currency'        => $family->currency ?: 'RWF',
				'active_students' => $decision['active_students'],
			];
		}

		return [
			'today'        => $today_str,
			'period'       => $period,
			'period_start' => $today_str,
			'period_end'   => $period_end,
			'to_bill'      => $to_bill,
			'skipped'      => $skipped,
		];
	}

	/**
	 * One family's eligibility, in isolation — every rule real generation
	 * applies, and nothing else. Returns ['skip' => null, 'amount' => ...,
	 * 'active_students' => [...]] when the family would be billed, or
	 * ['skip' => ['code' => ..., 'message' => ..., 'context' => [...]]]
	 * naming the first rule that excluded it.
	 */
	private static function evaluate_family( object $family, DateTimeInterface $today, string $today_str, string $period ): array {
		$family_id = (int) $family->id;

		if ( empty( $family->package_id ) ) {
			return [ 'skip' => [
				'code'    => 'no_package',
				'message' => 'family has no package',
				'context' => [],
			] ];
		}

		if ( TR_Families::is_date_set( $family->program_start_date ) && $family->program_start_date > $today_str ) {
			return [ 'skip' => [
				'code'    => 'not_started',
				'message' => sprintf( 'programme has not started yet (starts %s)', $family->program_start_date ),
				'context' => [ 'program_start_date' => $family->program_start_date ],
			] ];
		}

		if ( TR_Families::is_date_set( $family->program_end_date ) && $family->program_end_date < $today_str ) {
			return [ 'skip' => [
				'code'    => 'ended',
				'message' => sprintf( 'programme has ended (ended %s)', $family->program_end_date ),
				'context' => [ 'program_end_date' => $family->program_end_date ],
			] ];
		}

		$active_students = TR_Students::get_list( [ 'family_id' => $family_id, 'status' => 'active', 'per_page' => 200 ] );
		if ( empty( $active_students ) ) {
			return [ 'skip' => [
				'code'    => 'no_children',
				'message' => 'no active children',
				'context' => [],
			] ];
		}

		if ( ! self::is_billing_day( (int) $family->billing_day, $today ) ) {
			return [ 'skip' => [
				'code'    => 'not_billing_day',
				'message' => sprintf( 'today is not billing day %d', (int) $family->billing_day ),
				'context' => [ 'billing_day' => (int) $family->billing_day ],
			] ];
		}

		if ( null !== TR_Invoices::get_for_period( $family_id, $period ) ) {
			return [ 'skip' => [
				'code'    => 'already_invoiced',
				'message' => sprintf( 'already invoiced for %s', $period ),
				'context' => [ 'period' => $period ],
			] ];
		}

		$amount = (float) $family->monthly_amount;
		if ( $amount <= 0 ) {
			return [ 'skip' => [
				'code'    => 'zero_amount',
				'message' => 'monthly_amount is zero',
				'context' => [],
			] ];
		}

		return [ 'skip' => null, 'amount' => $amount, 'active_students' => $active_students ];
	}

	/**
	 * The execution step. Creates the invoice and sends the issued-invoice
	 * email for every entry in $plan['to_bill'] — the one place either of
	 * those actually happens. Never called for a dry run.
	 */
	public static function execute_plan( array $plan ): array {
		$created = [];
		$failed  = [];

		foreach ( $plan['to_bill'] as $entry ) {
			$family    = $entry['family'];
			$family_id = $entry['family_id'];

			$invoice_id = TR_Invoices::insert( [
				'family_id'        => $family_id,
				'period'           => $entry['period'],
				'period_start'     => $entry['period_start'],
				'period_end'       => $entry['period_end'],
				'amount'           => $entry['amount'],
				'currency'         => $entry['currency'],
				'status'           => 'pending',
				'due_date'         => $entry['due_date'],
				'issued_at'        => current_time( 'mysql' ),
				'student_snapshot' => self::build_student_snapshot( $family, $entry['active_students'] ),
			] );

			if ( $invoice_id <= 0 ) {
				// The unique (family_id, period) key is the real guard — this
				// only fires if something else inserted the same period
				// between build_plan()'s get_for_period() check and this
				// insert().
				TR_Logger::error( 'Invoice insert failed (likely a duplicate period)', [
					'family_id' => $family_id,
					'period'    => $entry['period'],
				] );
				$failed[] = $entry;
				continue;
			}

			TR_Logger::info( 'Invoice generated', [
				'family_id'  => $family_id,
				'invoice_id' => $invoice_id,
				'period'     => $entry['period'],
				'amount'     => number_format( $entry['amount'], 2, '.', '' ),
			] );

			TR_Notifications::send_invoice_issued_email( $family_id, $invoice_id );

			$created[] = array_merge( $entry, [ 'invoice_id' => $invoice_id ] );
		}

		return [ 'created' => $created, 'failed' => $failed ];
	}

	private static function log_skips( array $skipped ): void {
		foreach ( $skipped as $skip ) {
			$level = self::SKIP_LOG_LEVELS[ $skip['code'] ] ?? null;
			if ( null === $level ) {
				continue;
			}

			$context = array_merge( [ 'family_id' => $skip['family_id'] ], $skip['context'] ?? [] );
			$message = 'Invoice generation skipped: ' . $skip['message'];

			if ( 'warning' === $level ) {
				TR_Logger::warning( $message, $context );
			} else {
				TR_Logger::info( $message, $context );
			}
		}
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
