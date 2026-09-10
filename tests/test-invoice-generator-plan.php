<?php
/**
 * Standalone regression test for the v0.9.0 build_plan()/execute_plan()
 * split behind `wp tangnest generate --dry-run` — run directly with
 * `php tests/test-invoice-generator-plan.php`, no WordPress or WP-CLI
 * bootstrap needed. Exits non-zero on any failure.
 *
 * Loads the real includes/class-tr-invoice-generator.php and exercises
 * it against fake TR_Families/TR_Students/TR_Invoices/TR_Programs/
 * TR_Notifications/TR_Logger collaborators (defined below, not the real
 * classes) so the decision logic runs for real while every collaborator
 * call is recorded. That's what lets this test assert the properties
 * that actually matter for the CLI dry run:
 *
 *   - build_plan() alone never calls TR_Invoices::insert(), never calls
 *     TR_Notifications::send_invoice_issued_email(), and never calls
 *     TR_Logger at all — a dry run genuinely writes and sends nothing.
 *   - execute_plan() only acts on exactly what build_plan() decided —
 *     the plan and what actually gets created line up family for
 *     family, because both paths run through the same evaluate_family()
 *     rather than two copies of the eligibility rules.
 *   - each skip reason names the specific rule that excluded that family.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// --- Fixed "today" so billing-day math is deterministic. ---
const TEST_TODAY = '2026-09-26';

function current_datetime() {
	return new DateTimeImmutable( TEST_TODAY, new DateTimeZone( 'UTC' ) );
}

function current_time( $type ) {
	return 'mysql' === $type ? TEST_TODAY . ' 10:00:00' : strtotime( TEST_TODAY );
}

// --- Fixture data: 9 families, mirroring the real "nine families all
// billing on the 26th" situation this feature exists for. ---

function make_family( int $id, array $overrides = [] ): object {
	return (object) array_merge( [
		'id'                 => $id,
		'package_id'         => 1,
		'program_start_date' => null,
		'program_end_date'   => null,
		'billing_day'        => 26,
		'monthly_amount'     => 65000,
		'currency'           => 'RWF',
		'status'             => 'active',
		'parent_user_id'     => 100 + $id,
		'months_paid'        => 0,
	], $overrides );
}

$GLOBALS['TEST_FAMILIES'] = [
	make_family( 1 ),                                                          // bills
	make_family( 2, [ 'package_id' => null ] ),                                // no_package
	make_family( 3, [ 'program_end_date' => '2020-01-01' ] ),                  // ended
	make_family( 4, [ 'program_start_date' => '2099-01-01' ] ),                // not_started
	make_family( 5 ),                                                          // no_children
	make_family( 6, [ 'billing_day' => 15 ] ),                                 // not_billing_day
	make_family( 7 ),                                                          // already_invoiced
	make_family( 8, [ 'monthly_amount' => 0 ] ),                               // zero_amount
	make_family( 9, [ 'monthly_amount' => 120000 ] ),                          // bills
];

$GLOBALS['TEST_STUDENTS'] = [
	1 => [ (object) [ 'first_name' => 'Aline', 'last_name' => 'K' ] ],
	3 => [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ],
	4 => [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ],
	5 => [], // no active children
	6 => [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ],
	7 => [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ],
	8 => [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ],
	9 => [ (object) [ 'first_name' => 'Bosco', 'last_name' => 'N' ], (object) [ 'first_name' => 'Divine', 'last_name' => 'N' ] ],
];

// --- Call recorders, inspected by the assertions below. ---

$GLOBALS['CALLS'] = [
	'insert'                    => [],
	'send_invoice_issued_email' => [],
	'log'                       => [],
	'mark_overdue_calls'        => 0,
];

// --- Fake collaborators — NOT the real classes, deliberately minimal
// doubles covering only what TR_Invoice_Generator actually calls. ---

class TR_Families {
	public static function get_list( array $args = [] ): array {
		return $GLOBALS['TEST_FAMILIES'];
	}

	public static function is_date_set( $value ): bool {
		return null !== $value && '' !== $value && '0000-00-00' !== $value;
	}
}

class TR_Students {
	public static function get_list( array $args = [] ): array {
		$family_id = (int) ( $args['family_id'] ?? 0 );
		return $GLOBALS['TEST_STUDENTS'][ $family_id ] ?? [];
	}
}

class TR_Invoices {
	private static array $store  = [ 7 => [ '2026-09' => true ] ]; // family 7 pre-invoiced
	private static int   $next_id = 1000;

	public static function get_for_period( int $family_id, string $period ): ?object {
		if ( ! empty( self::$store[ $family_id ][ $period ] ) ) {
			return (object) [ 'id' => 1, 'family_id' => $family_id, 'period' => $period ];
		}
		return null;
	}

	public static function insert( array $data ): int {
		$GLOBALS['CALLS']['insert'][] = $data;
		$id = ++self::$next_id;
		self::$store[ (int) $data['family_id'] ][ $data['period'] ] = true;
		return $id;
	}

	public static function mark_overdue_due_before( string $date ): int {
		$GLOBALS['CALLS']['mark_overdue_calls']++;
		return 0;
	}
}

class TR_Programs {
	public static function get( int $id ): ?object {
		return (object) [ 'id' => $id, 'name' => 'Intro Robotics', 'duration_months' => 8 ];
	}
}

class TR_Notifications {
	public static function send_invoice_issued_email( int $family_id, int $invoice_id ): bool {
		$GLOBALS['CALLS']['send_invoice_issued_email'][] = [ $family_id, $invoice_id ];
		return true;
	}
}

class TR_Logger {
	public static function info( string $message, array $context = [] ): void { self::record( 'info', $message, $context ); }
	public static function warning( string $message, array $context = [] ): void { self::record( 'warning', $message, $context ); }
	public static function error( string $message, array $context = [] ): void { self::record( 'error', $message, $context ); }

	private static function record( string $level, string $message, array $context ): void {
		$GLOBALS['CALLS']['log'][] = [ $level, $message, $context ];
	}
}

require_once __DIR__ . '/../includes/class-tr-invoice-generator.php';

// --- Test harness ---

$failures = 0;

function check( bool $condition, string $label ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
	} else {
		$failures++;
		echo "FAIL: {$label}\n";
	}
}

function reset_calls(): void {
	$GLOBALS['CALLS'] = [
		'insert'                    => [],
		'send_invoice_issued_email' => [],
		'log'                       => [],
		'mark_overdue_calls'        => 0,
	];
}

// 1. build_plan() alone: zero side effects, correct to_bill/skip sets.

reset_calls();
$plan = TR_Invoice_Generator::build_plan();

check( empty( $GLOBALS['CALLS']['insert'] ), 'build_plan(): never calls TR_Invoices::insert()' );
check( empty( $GLOBALS['CALLS']['send_invoice_issued_email'] ), 'build_plan(): never calls TR_Notifications::send_invoice_issued_email()' );
check( empty( $GLOBALS['CALLS']['log'] ), 'build_plan(): never calls TR_Logger — no log writes on a dry run' );
check( 0 === $GLOBALS['CALLS']['mark_overdue_calls'], 'build_plan(): never calls TR_Invoices::mark_overdue_due_before()' );

check( '2026-09' === $plan['period'], 'build_plan(): period is 2026-09' );
check( 2 === count( $plan['to_bill'] ), 'build_plan(): exactly 2 families would be billed' );
check( 7 === count( $plan['skipped'] ), 'build_plan(): exactly 7 families are skipped' );

$to_bill_ids = array_column( $plan['to_bill'], 'family_id' );
sort( $to_bill_ids );
check( [ 1, 9 ] === $to_bill_ids, 'build_plan(): families 1 and 9 are the ones that would be billed' );

$skip_by_family = [];
foreach ( $plan['skipped'] as $s ) {
	$skip_by_family[ $s['family_id'] ] = $s['code'];
}
check( 'no_package' === ( $skip_by_family[2] ?? null ), 'family 2 skipped: no_package' );
check( 'ended' === ( $skip_by_family[3] ?? null ), 'family 3 skipped: ended' );
check( 'not_started' === ( $skip_by_family[4] ?? null ), 'family 4 skipped: not_started' );
check( 'no_children' === ( $skip_by_family[5] ?? null ), 'family 5 skipped: no_children' );
check( 'not_billing_day' === ( $skip_by_family[6] ?? null ), 'family 6 skipped: not_billing_day' );
check( 'already_invoiced' === ( $skip_by_family[7] ?? null ), 'family 7 skipped: already_invoiced' );
check( 'zero_amount' === ( $skip_by_family[8] ?? null ), 'family 8 skipped: zero_amount' );

// 1b. TR_Invoice_Generator::is_billable() directly — the shared gate
//     extracted so `wp tangnest status`'s "next billing" figure and
//     evaluate_family() can't drift into two different answers for
//     "could this family ever produce an invoice right now".

$billable_family = make_family( 1 );
check( true === TR_Invoice_Generator::is_billable( $billable_family, TEST_TODAY )['billable'], 'is_billable(): a normal family is billable' );

$no_package = make_family( 2, [ 'package_id' => null ] );
check( false === TR_Invoice_Generator::is_billable( $no_package, TEST_TODAY )['billable']
	&& 'no_package' === TR_Invoice_Generator::is_billable( $no_package, TEST_TODAY )['code'], 'is_billable(): no package is not billable' );

$ended = make_family( 3, [ 'program_end_date' => '2020-01-01' ] );
check( false === TR_Invoice_Generator::is_billable( $ended, TEST_TODAY )['billable']
	&& 'ended' === TR_Invoice_Generator::is_billable( $ended, TEST_TODAY )['code'], 'is_billable(): an ended programme is not billable' );

// Family 5 has no fixture entry in TEST_STUDENTS, i.e. no active children.
$no_children = make_family( 5 );
check( false === TR_Invoice_Generator::is_billable( $no_children, TEST_TODAY )['billable']
	&& 'no_children' === TR_Invoice_Generator::is_billable( $no_children, TEST_TODAY )['code'], 'is_billable(): no active children is not billable' );

$zero_amount = make_family( 8, [ 'monthly_amount' => 0 ] );
check( false === TR_Invoice_Generator::is_billable( $zero_amount, TEST_TODAY )['billable']
	&& 'zero_amount' === TR_Invoice_Generator::is_billable( $zero_amount, TEST_TODAY )['code'], 'is_billable(): a zero amount is not billable' );

// A programme that has not started YET is still billable by this check —
// only evaluate_family()'s separate not_started check excludes it from
// today's run; is_billable() answers "will this family ever bill again",
// not "should it bill today".
$not_started_yet = make_family( 4, [ 'program_start_date' => '2099-01-01' ] );
check( true === TR_Invoice_Generator::is_billable( $not_started_yet, TEST_TODAY )['billable'], 'is_billable(): a not-yet-started programme is still billable (start date is not one of the four checks)' );

// 2. --family=<id> restricts both to_bill and skipped to that one family.

reset_calls();
$single_plan = TR_Invoice_Generator::build_plan( 9 );
check( 1 === count( $single_plan['to_bill'] ), '--family=9: to_bill has exactly one entry' );
check( 9 === ( $single_plan['to_bill'][0]['family_id'] ?? null ), '--family=9: that entry is family 9' );
check( empty( $single_plan['skipped'] ), '--family=9: no other family appears in skipped' );

reset_calls();
$single_skip_plan = TR_Invoice_Generator::build_plan( 2 );
check( empty( $single_skip_plan['to_bill'] ), '--family=2: to_bill is empty (family 2 has no package)' );
check( 1 === count( $single_skip_plan['skipped'] )
	&& 'no_package' === $single_skip_plan['skipped'][0]['code'], '--family=2: skipped with no_package, nothing else' );

// 3. Repeated dry runs are identical and still make no changes.

reset_calls();
$plan_again = TR_Invoice_Generator::build_plan();
check(
	array_column( $plan['to_bill'], 'family_id' ) === array_column( $plan_again['to_bill'], 'family_id' ),
	're-running build_plan() twice produces the same to_bill set'
);
check( empty( $GLOBALS['CALLS']['insert'] ), 're-running the dry run still creates nothing' );

// 4. execute_plan() acts on exactly what build_plan() decided — plan and
//    execution line up family for family.

reset_calls();
$plan_for_exec = TR_Invoice_Generator::build_plan();
$exec_result   = TR_Invoice_Generator::execute_plan( $plan_for_exec );

check( 2 === count( $exec_result['created'] ), 'execute_plan(): creates exactly the 2 planned invoices' );
check( empty( $exec_result['failed'] ), 'execute_plan(): none fail' );

$created_ids = array_column( $exec_result['created'], 'family_id' );
sort( $created_ids );
check( [ 1, 9 ] === $created_ids, 'execute_plan(): created invoices are for families 1 and 9 — matches the plan exactly' );
check( 2 === count( $GLOBALS['CALLS']['insert'] ), 'execute_plan(): TR_Invoices::insert() called exactly twice' );
check( 2 === count( $GLOBALS['CALLS']['send_invoice_issued_email'] ), 'execute_plan(): invoice-issued email sent exactly twice' );

$sent_family_ids = array_column( $GLOBALS['CALLS']['send_invoice_issued_email'], 0 );
sort( $sent_family_ids );
check( [ 1, 9 ] === $sent_family_ids, 'execute_plan(): emails went to families 1 and 9, no one else' );

// A follow-up plan (family 1/9 now already invoiced) must show them
// skipped instead of billed — proves the whole loop is idempotent.
$plan_after_exec = TR_Invoice_Generator::build_plan();
check( empty( $plan_after_exec['to_bill'] ), 'after execute_plan(): nothing left to bill for this period' );

// 4b. Equality boundaries. Nine of the real families' programme start
//     date and billing day are both the 26th, so the 26th is an exact
//     boundary in production, not just a hypothetical edge case: a
//     programme starting exactly today must count as started (skip only
//     when start_date is strictly AFTER today), and a programme ending
//     exactly today must still bill (skip only when end_date is
//     strictly BEFORE today — the programme runs through that day).
//     Appended to the fixtures here, after every earlier unfiltered
//     build_plan()/execute_plan() assertion above, so those counts don't
//     need to account for two more always-billable families.

$GLOBALS['TEST_FAMILIES'][]   = make_family( 10, [ 'program_start_date' => TEST_TODAY ] );
$GLOBALS['TEST_FAMILIES'][]   = make_family( 11, [ 'program_end_date' => TEST_TODAY ] );
$GLOBALS['TEST_STUDENTS'][10] = [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ];
$GLOBALS['TEST_STUDENTS'][11] = [ (object) [ 'first_name' => 'X', 'last_name' => 'Y' ] ];

reset_calls();
$plan_start_today = TR_Invoice_Generator::build_plan( 10 );
check( empty( $plan_start_today['skipped'] ), 'family 10 (program_start_date == today): not skipped' );
check(
	1 === count( $plan_start_today['to_bill'] ) && 10 === ( $plan_start_today['to_bill'][0]['family_id'] ?? null ),
	'family 10 (program_start_date == today): bills today — a programme starting today has started, not "not yet"'
);

reset_calls();
$plan_end_today = TR_Invoice_Generator::build_plan( 11 );
check( empty( $plan_end_today['skipped'] ), 'family 11 (program_end_date == today): not skipped' );
check(
	1 === count( $plan_end_today['to_bill'] ) && 11 === ( $plan_end_today['to_bill'][0]['family_id'] ?? null ),
	'family 11 (program_end_date == today): still bills — the programme runs through its last day, not up to it'
);

// 5. run() glues build_plan()+execute_plan()+the overdue sweep together,
//    and logs skip reasons at the levels the old single-loop version did.
// Families 1 and 9 are already invoiced for 2026-09 from step 4, so this
// section verifies run() against families already past that point —
// that's what run(--family=9) below is exercising.

reset_calls();
$run_result = TR_Invoice_Generator::run( 9 ); // family 9 already invoiced by step 4 — expect zero created, one skip.

check( 0 === count( $run_result['created'] ), 'run(--family=9) after already invoiced: creates nothing' );
check( 1 === count( $run_result['plan']['skipped'] )
	&& 'already_invoiced' === $run_result['plan']['skipped'][0]['code'], 'run(--family=9): skip reason is already_invoiced' );
check( null === $run_result['marked_overdue'], 'run(--family=<id>): overdue sweep is skipped for a filtered run' );

// run() always ends with an "Invoice generation run complete" info line
// (unrelated to any skip), so the expected log count is "the skip's own
// line, if any" plus that one.

reset_calls();
$run_result_2 = TR_Invoice_Generator::run( 8 ); // family 8: zero_amount, logged at warning level.
check( 2 === count( $GLOBALS['CALLS']['log'] ), 'run(--family=8): the zero_amount warning plus the run-complete line' );
check( 'warning' === $GLOBALS['CALLS']['log'][0][0], 'run(--family=8): skip itself is logged at warning level' );

reset_calls();
$run_result_3 = TR_Invoice_Generator::run( 3 ); // family 3: ended, logged at info level.
check( 2 === count( $GLOBALS['CALLS']['log'] ), 'run(--family=3): the ended notice plus the run-complete line' );
check( 'info' === $GLOBALS['CALLS']['log'][0][0], 'run(--family=3): skip itself is logged at info level' );

reset_calls();
$run_result_4 = TR_Invoice_Generator::run( 6 ); // family 6: not_billing_day, not worth a log line of its own.
check( 1 === count( $GLOBALS['CALLS']['log'] ), 'run(--family=6): not_billing_day is routine — only the run-complete line' );

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s).\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
