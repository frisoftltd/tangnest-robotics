<?php
/**
 * Standalone regression test for `wp tangnest consolidate-packages`
 * (v0.10.1) — run directly with `php tests/test-consolidate-packages.php`,
 * no WordPress or WP-CLI bootstrap needed. Exits non-zero on any failure.
 *
 * This command moves real billing data, so this test exists to prove,
 * against the real TR_CLI_Command class (not a double of it), that:
 *   - a dry run never calls a single write method, on any collaborator
 *   - a precondition mismatch (wrong package, wrong child count, wrong
 *     amount) on any of the six reassigned families aborts with that
 *     family named, not just the first one found
 *   - an amount that would move for ANY active family — not only the
 *     six being reassigned — aborts the whole run
 *   - "already consolidated" is detected and short-circuits cleanly
 *   - --execute writes exactly the rows the plan said it would, writes
 *     a family event for each, and a second run afterward reports
 *     "already consolidated"
 *
 * Every collaborator below is a minimal fake covering only what
 * TR_CLI_Command::consolidate_packages() and its private helpers
 * actually call — see test-invoice-generator-plan.php for why that's
 * the pattern across this test suite.
 */

namespace WP_CLI\Utils {
	function get_flag_value( array $assoc_args, string $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}
}

namespace {

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = 'default' ) { return $text; }
function get_current_user_id() { return 0; }
function wp_get_current_user() {
	return new class {
		public string $user_login = 'cli';
		public function exists(): bool { return false; }
	};
}
function get_userdata( $id ) { return $GLOBALS['TEST_USERS'][ $id ] ?? false; }

class WP_CLI_Test_Exit extends \Exception {}

class WP_CLI {
	public static array $lines = [];

	public static function line( $msg = '' ): void { self::$lines[] = (string) $msg; }
	public static function success( $msg ): void { self::$lines[] = 'SUCCESS: ' . $msg; }
	public static function warning( $msg ): void { self::$lines[] = 'WARNING: ' . $msg; }

	public static function error( $msg ): void {
		self::$lines[] = 'ERROR: ' . $msg;
		throw new WP_CLI_Test_Exit( (string) $msg );
	}
}

class TR_DB {
	public static function table_families(): string { return 'wp_tr_families'; }
	public static function table_programs(): string { return 'wp_tr_programs'; }
}

class Fake_WPDB {
	public array $queries = [];

	public function prepare( $sql, $args = [] ) { return $sql; }

	public function query( $sql ) {
		$this->queries[] = $sql;
		return true;
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SUM(monthly_amount)' ) ) {
			$total = 0.0;
			foreach ( $GLOBALS['TEST_FAMILIES'] as $f ) {
				if ( 'active' === $f->status ) {
					$total += (float) $f->monthly_amount;
				}
			}
			return $total;
		}

		if ( false !== strpos( $sql, 'package_id IN' ) ) {
			$count = 0;
			foreach ( $GLOBALS['TEST_FAMILIES'] as $f ) {
				if ( in_array( (int) $f->package_id, [ 16, 20, 21 ], true ) ) {
					$count++;
				}
			}
			return $count;
		}

		return 0;
	}
}

class TR_Logger {
	public static array $lines = [];
	public static function info( string $m, array $c = [] ): void { self::$lines[] = [ 'info', $m ]; }
	public static function error( string $m, array $c = [] ): void { self::$lines[] = [ 'error', $m ]; }
}

class TR_Family_Events {
	public static array $events = [];
	public static function record( int $family_id, string $event_type, string $summary, ?string $old_value = null, ?string $new_value = null, ?int $recorded_by = null ): int {
		self::$events[] = [ $family_id, $event_type, $summary ];
		return 1;
	}
}

class TR_Programs {
	public static function get( int $id ): ?object { return $GLOBALS['TEST_PACKAGES'][ $id ] ?? null; }

	public static function update( int $id, array $data ): bool {
		$GLOBALS['CALLS']['package_updates'][] = [ 'id' => $id, 'data' => $data ];

		$p = $GLOBALS['TEST_PACKAGES'][ $id ];
		$p->name                   = $data['name'];
		$p->duration_months        = $data['duration_months'];
		$p->course                 = $data['course'];
		$p->price_1_child          = $data['price_1_child'];
		$p->price_2_children       = $data['price_2_children'];
		$p->price_3_children       = $data['price_3_children'];
		$p->price_additional_child = $data['price_additional_child'];
		$p->status                 = $data['status'];

		return true;
	}
}

class TR_Families {
	public static function get( int $id ): ?object { return $GLOBALS['TEST_FAMILIES'][ $id ] ?? null; }

	public static function get_list( array $args = [] ): array {
		$out = array_values( $GLOBALS['TEST_FAMILIES'] );
		if ( ! empty( $args['status'] ) ) {
			$out = array_values( array_filter( $out, static fn( $f ) => $f->status === $args['status'] ) );
		}
		return $out;
	}

	public static function count( array $args = [] ): int { return count( self::get_list( $args ) ); }

	public static function active_child_count( int $family_id ): int {
		return $GLOBALS['TEST_CHILD_COUNTS'][ $family_id ] ?? 0;
	}

	public static function price_for_child_count( int $package_id, int $child_count ): ?float {
		$package = TR_Programs::get( $package_id );
		if ( null === $package || $child_count < 1 ) {
			return null;
		}
		if ( $child_count <= 3 ) {
			return [ 1 => $package->price_1_child, 2 => $package->price_2_children, 3 => $package->price_3_children ][ $child_count ];
		}
		if ( null === $package->price_3_children || null === $package->price_additional_child ) {
			return null;
		}
		return $package->price_3_children + ( $child_count - 3 ) * $package->price_additional_child;
	}

	public static function update( int $id, array $data ): bool {
		$GLOBALS['CALLS']['family_updates'][] = [ 'id' => $id, 'data' => $data ];

		$f                             = $GLOBALS['TEST_FAMILIES'][ $id ];
		$f->package_id                 = $data['package_id'];
		$f->monthly_amount              = $data['monthly_amount'];
		$f->negotiated_amount           = $data['negotiated_amount'];
		$f->negotiated_for_child_count = $data['negotiated_for_child_count'];
		$f->negotiated_note            = $data['negotiated_note'];

		return true;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

require_once __DIR__ . '/../includes/cli/class-tr-cli-commands.php';

// --- Fixtures: 14 active families, self-consistent under the CURRENT
// (pre-consolidation) package data, mirroring the real roster's shape
// without needing to reproduce the live totals exactly. ---

function make_package( int $id, array $overrides = [] ): object {
	return (object) array_merge( [
		'id'                     => $id,
		'name'                   => "Package {$id}",
		'course'                 => null,
		'child_count'            => null,
		'default_monthly_fee'    => 0.0,
		'duration_months'        => 8,
		'irembopay_product_code' => "PC-{$id}",
		'price_1_child'          => null,
		'price_2_children'       => null,
		'price_3_children'       => null,
		'price_additional_child' => null,
		'start_date'             => null,
		'notes'                  => null,
		'status'                 => 'active',
	], $overrides );
}

function make_family( int $id, int $package_id, float $amount, array $overrides = [] ): object {
	return (object) array_merge( [
		'id'                         => $id,
		'parent_user_id'             => 1000 + $id,
		'package_id'                 => $package_id,
		'monthly_amount'             => $amount,
		'months_paid'                => 0,
		'currency'                   => 'RWF',
		'billing_day'                => 10,
		'program_start_date'         => null,
		'program_end_date'           => null,
		'negotiated_amount'          => null,
		'negotiated_for_child_count' => null,
		'negotiated_note'            => null,
		'status'                     => 'active',
		'notes'                      => null,
	], $overrides );
}

function fresh_fixtures(): void {
	$packages = [
		1  => make_package( 1, [ 'price_1_child' => 50.0 ] ),
		3  => make_package( 3, [ 'price_1_child' => 75000.0 ] ),
		4  => make_package( 4, [ 'price_1_child' => 65000.0 ] ),
		8  => make_package( 8 ),
		9  => make_package( 9 ),
		14 => make_package( 14, [ 'price_3_children' => 155000.0 ] ), // incomplete — not yet consolidated
		15 => make_package( 15 ),
		22 => make_package( 22 ),
		25 => make_package( 25 ),
	];
	foreach ( [ 2, 5, 6, 7, 10, 11, 12, 13, 16, 17, 18, 19, 20, 21, 23, 24, 26, 27 ] as $archive_id ) {
		$packages[ $archive_id ] = make_package( $archive_id );
	}
	$GLOBALS['TEST_PACKAGES'] = $packages;

	$GLOBALS['TEST_FAMILIES'] = [
		2  => make_family( 2, 1, 50.0 ),
		5  => make_family( 5, 16, 120000.0 ),
		7  => make_family( 7, 16, 120000.0 ),
		10 => make_family( 10, 16, 120000.0 ),
		15 => make_family( 15, 16, 120000.0 ),
		11 => make_family( 11, 14, 155000.0 ),
		12 => make_family( 12, 14, 155000.0 ),
		13 => make_family( 13, 14, 155000.0 ),
		16 => make_family( 16, 14, 155000.0 ),
		17 => make_family( 17, 14, 155000.0 ),
		23 => make_family( 23, 14, 155000.0 ),
		14 => make_family( 14, 20, 130000.0 ),
		20 => make_family( 20, 21, 60000.0 ),
		22 => make_family( 22, 4, 65000.0 ),
	];

	$GLOBALS['TEST_CHILD_COUNTS'] = [
		2 => 1, 5 => 2, 7 => 2, 10 => 2, 15 => 2,
		11 => 3, 12 => 3, 13 => 3, 16 => 3, 17 => 3, 23 => 3,
		14 => 3, 20 => 1, 22 => 1,
	];

	$GLOBALS['TEST_USERS'] = [];

	$GLOBALS['CALLS'] = [ 'package_updates' => [], 'family_updates' => [] ];
	TR_Logger::$lines        = [];
	TR_Family_Events::$events = [];
}

// --- Harness ---

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

function run( callable $fn, array $assoc_args = [] ): array {
	WP_CLI::$lines = [];
	$cmd = new TR_CLI_Command();
	try {
		$fn( $cmd, $assoc_args );
	} catch ( WP_CLI_Test_Exit $e ) {
		// expected for abort-path tests; caller inspects $GLOBALS['CALLS']
	}
	return WP_CLI::$lines;
}

function has_substring( array $lines, string $needle ): bool {
	foreach ( $lines as $l ) {
		if ( false !== strpos( $l, $needle ) ) {
			return true;
		}
	}
	return false;
}

// 1. Clean dry run: no writes anywhere, prints the plan, says "no changes".

fresh_fixtures();
$lines = run( static fn( $cmd ) => $cmd->consolidate_packages( [], [] ) );

check( empty( $GLOBALS['CALLS']['package_updates'] ), 'dry run: never calls TR_Programs::update()' );
check( empty( $GLOBALS['CALLS']['family_updates'] ), 'dry run: never calls TR_Families::update()' );
check( empty( TR_Family_Events::$events ), 'dry run: never writes a family event' );
check( has_substring( $lines, 'Would update 9 packages' ), 'dry run: reports updating 9 packages' );
check( has_substring( $lines, 'Would reassign 6 families' ), 'dry run: reports reassigning 6 families' );
check( has_substring( $lines, 'Would archive 18 packages' ), 'dry run: reports archiving 18 packages' );
check( has_substring( $lines, 'No changes made' ), 'dry run: says no changes were made' );
check( ! has_substring( $lines, 'aborted' ), 'dry run on good data: does not abort' );

// 2. Precondition mismatch: family 5 has drifted onto a different package.

fresh_fixtures();
$GLOBALS['TEST_FAMILIES'][5]->package_id = 999;
$lines = run( static fn( $cmd ) => $cmd->consolidate_packages( [], [] ) );

check( has_substring( $lines, 'family #5' ), 'precondition mismatch: names family 5 specifically' );
check( has_substring( $lines, 'aborted' ), 'precondition mismatch: aborts' );
check( empty( $GLOBALS['CALLS']['family_updates'] ), 'precondition mismatch: writes nothing' );
check( empty( $GLOBALS['CALLS']['package_updates'] ), 'precondition mismatch: writes nothing (packages either)' );

// 2b. Two independent precondition problems at once — both reported, not
//     just the first one encountered.

fresh_fixtures();
$GLOBALS['TEST_FAMILIES'][5]->monthly_amount = 999999.0;
$GLOBALS['TEST_CHILD_COUNTS'][7] = 1; // was 2
$lines = run( static fn( $cmd ) => $cmd->consolidate_packages( [], [] ) );

check( has_substring( $lines, 'family #5' ) && has_substring( $lines, 'family #7' ), 'multiple precondition failures: both families named in one report' );

// 3. An amount-change guard failure for a family NOT in the reassignment
//    table at all — family 11 drifts to 2 children while staying on
//    package 14, so the new price table would actually change its bill.

fresh_fixtures();
$GLOBALS['TEST_CHILD_COUNTS'][11] = 2;
$lines = run( static fn( $cmd ) => $cmd->consolidate_packages( [], [] ) );

check( has_substring( $lines, 'family #11' ), 'amount-change guard: catches a family outside the reassignment table too' );
check( has_substring( $lines, 'aborted' ), 'amount-change guard: aborts' );
check( empty( $GLOBALS['CALLS']['family_updates'] ), 'amount-change guard: writes nothing' );

// 4. Already consolidated — package 14 fully priced, nobody left on 16/20/21.

fresh_fixtures();
$GLOBALS['TEST_PACKAGES'][14]->price_1_child    = 65000.0;
$GLOBALS['TEST_PACKAGES'][14]->price_2_children = 120000.0;
foreach ( $GLOBALS['TEST_FAMILIES'] as $f ) {
	if ( in_array( (int) $f->package_id, [ 16, 20, 21 ], true ) ) {
		$f->package_id = 14;
	}
}
$lines = run( static fn( $cmd ) => $cmd->consolidate_packages( [], [] ) );

check( has_substring( $lines, 'already appears to have run' ), 'already consolidated: detected and reported' );
check( empty( $GLOBALS['CALLS']['package_updates'] ) && empty( $GLOBALS['CALLS']['family_updates'] ), 'already consolidated: touches nothing' );

// 5. --execute: writes exactly what the plan said, including the two
//    negotiated rates, logs a family event per touched family, and a
//    second run afterward reports "already consolidated".

fresh_fixtures();
$before_total = 0.0;
foreach ( $GLOBALS['TEST_FAMILIES'] as $f ) { $before_total += (float) $f->monthly_amount; }

$lines = run( static fn( $cmd, $a ) => $cmd->consolidate_packages( [], $a ), [ 'execute' => true ] );

// 9 kept packages re-priced, plus 18 archived packages (also written via
// TR_Programs::update(), just with status flipped to inactive) — 27 total.
check( 27 === count( $GLOBALS['CALLS']['package_updates'] ), '--execute: writes exactly 27 packages (9 re-priced + 18 archived)' );
check( 6 === count( $GLOBALS['CALLS']['family_updates'] ), '--execute: updates exactly 6 families' );

$family_14_update = null;
foreach ( $GLOBALS['CALLS']['family_updates'] as $u ) {
	if ( 14 === $u['id'] ) { $family_14_update = $u['data']; }
}
check( null !== $family_14_update && 130000.0 === (float) $family_14_update['negotiated_amount'], '--execute: family 14 gets negotiated_amount 130,000' );
check( null !== $family_14_update && 3 === (int) $family_14_update['negotiated_for_child_count'], '--execute: family 14\'s negotiated_for_child_count is its actual child count (3)' );
check( null !== $family_14_update && 14 === (int) $family_14_update['package_id'], '--execute: family 14 reassigned to package 14' );

$package_14_update = null;
foreach ( $GLOBALS['CALLS']['package_updates'] as $u ) {
	if ( 14 === $u['id'] ) { $package_14_update = $u['data']; }
}
check( null !== $package_14_update && null === $package_14_update['price_additional_child'], '--execute: package 14 price_additional_child stays NULL, never guessed' );
check( null !== $package_14_update && 65000.0 === (float) $package_14_update['price_1_child'], '--execute: package 14 gets price_1_child 65,000' );

check( count( TR_Family_Events::$events ) >= 6, '--execute: writes at least one family event per touched family (6 package_changed + 2 negotiated_set)' );
check( ! empty( TR_Logger::$lines ), '--execute: logs the run' );
check( has_substring( $lines, 'Verified total' ), '--execute: prints a verified total after writing' );

// v0.10.2: --execute reports in past tense, never "Would", and the
// success line is the very last thing printed — never ahead of the
// plan it's confirming.
check( has_substring( $lines, 'Updated 9 packages' ), '--execute: reports "Updated", not "Would update"' );
check( has_substring( $lines, 'Reassigned 6 families' ), '--execute: reports "Reassigned", not "Would reassign"' );
check( has_substring( $lines, 'Archived 18 packages' ), '--execute: reports "Archived", not "Would archive"' );
check( ! has_substring( $lines, 'Would' ), '--execute: never says "Would" anywhere in its output' );
$success_index = null;
foreach ( $lines as $i => $l ) {
	if ( false !== strpos( $l, 'SUCCESS:' ) ) { $success_index = $i; }
}
check( null !== $success_index && $success_index === array_key_last( $lines ), '--execute: the success line is the last line printed, after the plan' );
check( has_substring( $lines, 'Package 14' ) && has_substring( $lines, 'Applied Robotics & Coding — Standard' ), '--execute: shows the pre-consolidation name alongside the new one, not the new name on both sides of the arrow' );

$after_total = 0.0;
foreach ( $GLOBALS['TEST_FAMILIES'] as $f ) { $after_total += (float) $f->monthly_amount; }
check( abs( $before_total - $after_total ) < 0.005, '--execute: total active monthly_amount is identical before and after' );

// Re-run against the now-mutated fixtures (the fakes applied the writes
// in place) — must detect "already consolidated" and touch nothing more.
$GLOBALS['CALLS'] = [ 'package_updates' => [], 'family_updates' => [] ];
$lines2 = run( static fn( $cmd ) => $cmd->consolidate_packages( [], [] ) );
check( has_substring( $lines2, 'already appears to have run' ), 're-running after --execute: reports already consolidated' );
check( empty( $GLOBALS['CALLS']['package_updates'] ) && empty( $GLOBALS['CALLS']['family_updates'] ), 're-running after --execute: writes nothing' );

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s).\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );

}
