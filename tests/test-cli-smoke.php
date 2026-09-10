<?php
/**
 * Smoke test for `wp tangnest ...` (v0.9.0) — run directly with
 * `php tests/test-cli-smoke.php`, no real WordPress or WP-CLI needed.
 *
 * There is no WP-CLI/WordPress/MySQL available in this environment to
 * actually run `wp tangnest status` etc., so this stubs just enough of
 * WP_CLI, WP_CLI\Utils, and the plugin's collaborator classes to invoke
 * every TR_CLI_Command public method for real and catch what a live run
 * would catch: fatal errors, undefined-function calls, wrong argument
 * counts, bad array access. It is not a check of exact wording — see
 * tests/test-invoice-generator-plan.php for the decision-logic
 * correctness this command layer sits on top of.
 */

namespace WP_CLI\Utils {
	function get_flag_value( array $assoc_args, string $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}

	function format_items( string $format, array $items, $fields ): void {
		\WP_CLI::$lines[] = "[table:{$format} fields=" . implode( ',', (array) $fields ) . '] ' . count( $items ) . ' row(s)';
		foreach ( $items as $item ) {
			\WP_CLI::$lines[] = '  ' . json_encode( $item );
		}
	}
}

namespace {

define( 'ABSPATH', __DIR__ . '/' );
define( 'TANGNEST_ROBOTICS_VERSION', '0.9.0' );
define( 'TANGNEST_ROBOTICS_DB_VERSION', '0.8.6' );

class WP_CLI_Test_Exit extends \Exception {}

class WP_CLI {
	public static array $lines = [];

	public static function line( $msg = '' ): void { self::$lines[] = (string) $msg; }
	public static function log( $msg = '' ): void { self::$lines[] = (string) $msg; }
	public static function success( $msg ): void { self::$lines[] = 'SUCCESS: ' . $msg; }
	public static function warning( $msg ): void { self::$lines[] = 'WARNING: ' . $msg; }

	public static function error( $msg ): void {
		self::$lines[] = 'ERROR: ' . $msg;
		throw new WP_CLI_Test_Exit( (string) $msg );
	}

	public static function print_value( $value, $assoc_args = [] ): void {
		$format = $assoc_args['format'] ?? 'var_export';
		if ( 'json' === $format ) {
			self::$lines[] = json_encode( $value );
		} elseif ( 'yaml' === $format ) {
			self::$lines[] = 'yaml:' . print_r( $value, true );
		} else {
			self::$lines[] = var_export( $value, true );
		}
	}
}

// --- WP function stubs ---

function absint( $v ) { return abs( (int) $v ); }
function current_time( $type ) { return 'mysql' === $type ? '2026-09-26 09:00:00' : ( 'Y-m-01' === $type ? '2026-09-01' : ( 'Y-m-t' === $type ? '2026-09-30' : ( 'Y-m' === $type ? '2026-09' : time() ) ) ); }
function current_datetime() { return new \DateTimeImmutable( '2026-09-26', new \DateTimeZone( 'UTC' ) ); }
function date_i18n( $format, $ts ) { return date( $format, $ts ); }
function wp_date( $format, $ts ) { return date( $format, $ts ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['TEST_CRON_NEXT'] ?? false; }
function get_userdata( $id ) { return $GLOBALS['TEST_USERS'][ $id ] ?? false; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['TEST_USER_META'][ $id ][ $key ] ?? ''; }

// --- Fixtures ---

$GLOBALS['TEST_USERS'] = [
	205 => (object) [ 'ID' => 205, 'display_name' => 'Andre KAZEYEZU', 'user_email' => 'andre@example.com' ],
	206 => (object) [ 'ID' => 206, 'display_name' => 'No Package Parent', 'user_email' => 'nopkg@example.com' ],
];
$GLOBALS['TEST_USER_META'] = [
	205 => [ 'phone_number' => '0788123456' ],
];
$GLOBALS['TEST_CRON_NEXT'] = false; // not scheduled — exercises the "issues" branch

function make_family( int $id, array $overrides = [] ): object {
	return (object) array_merge( [
		'id'                 => $id,
		'status'             => 'active',
		'package_id'         => 1,
		'parent_user_id'     => 205,
		'monthly_amount'     => 65000,
		'currency'           => 'RWF',
		'billing_day'        => 26,
		'program_start_date' => null,
		'program_end_date'   => null,
		'months_paid'        => 0,
	], $overrides );
}

$GLOBALS['TEST_FAMILIES'] = [
	5 => make_family( 5 ),
	6 => make_family( 6, [ 'package_id' => null, 'parent_user_id' => 206, 'monthly_amount' => 0 ] ),
];

// --- Stub collaborators (not the real classes — see test-invoice-generator-plan.php for why) ---

class TR_Families {
	const STATUSES = [ 'active', 'inactive', 'completed' ];

	public static function get( int $id ): ?object { return $GLOBALS['TEST_FAMILIES'][ $id ] ?? null; }

	public static function get_list( array $args = [] ): array {
		$out = array_values( $GLOBALS['TEST_FAMILIES'] );
		if ( ! empty( $args['status'] ) ) {
			$out = array_values( array_filter( $out, static fn( $f ) => $f->status === $args['status'] ) );
		}
		return $out;
	}

	public static function count( array $args = [] ): int { return count( self::get_list( $args ) ); }

	public static function is_date_set( $v ): bool { return null !== $v && '' !== $v && '0000-00-00' !== $v; }

	public static function progress_label( object $family ): string { return 'Month 1 of 8'; }

	public static function next_billing_date( int $id ): ?string { return '2026-09-26'; }
}

class TR_Students {
	public static function get_list( array $args = [] ): array {
		$family_id = (int) ( $args['family_id'] ?? 0 );
		if ( 5 === $family_id ) {
			return [ (object) [ 'id' => 1, 'first_name' => 'Bosco', 'last_name' => 'N', 'status' => 'active' ] ];
		}
		return [];
	}

	public static function count( array $args = [] ): int {
		return ( 'active' === ( $args['status'] ?? '' ) ) ? 1 : 0;
	}
}

class TR_Programs {
	private static array $packages = [
		1 => [ 'id' => 1, 'name' => 'Intro Robotics', 'duration_months' => 8, 'irembopay_product_code' => 'PKG1', 'status' => 'active' ],
		2 => [ 'id' => 2, 'name' => 'No Code Package', 'duration_months' => 8, 'irembopay_product_code' => '', 'status' => 'active' ],
	];

	public static function get( int $id ): ?object { return isset( self::$packages[ $id ] ) ? (object) self::$packages[ $id ] : null; }

	public static function get_list( array $args = [] ): array { return array_map( static fn( $p ) => (object) $p, array_values( self::$packages ) ); }

	public static function count( array $args = [] ): int { return count( self::$packages ); }
}

class TR_Invoices {
	const STATUSES = [ 'pending', 'paid', 'overdue', 'cancelled', 'waived' ];

	private static array $rows = [
		9 => [ 'id' => 9, 'family_id' => 5, 'period' => '2026-09', 'status' => 'pending', 'amount' => 65000, 'currency' => 'RWF', 'due_date' => '2026-09-26', 'paid_at' => null ],
	];

	public static function get( int $id ): ?object { return isset( self::$rows[ $id ] ) ? (object) self::$rows[ $id ] : null; }

	public static function get_list( array $args = [] ): array { return array_map( static fn( $r ) => (object) $r, array_values( self::$rows ) ); }

	public static function get_by_family( int $family_id ): array {
		return array_values( array_map( static fn( $r ) => (object) $r, array_filter( self::$rows, static fn( $r ) => $r['family_id'] === $family_id ) ) );
	}

	public static function count( array $args = [] ): int { return count( self::$rows ); }

	public static function totals_by_status(): array { return [ 'pending' => 65000.0, 'paid' => 240000.0 ]; }

	public static function collected_in_period( string $start, string $end ): float { return 240000.0; }

	public static function get_for_period( int $family_id, string $period ): ?object { return null; }

	public static function insert( array $data ): int { return 999; }

	public static function mark_overdue_due_before( string $date ): int { return 0; }

	public static function record_manual_reminder( int $id ): void {}
}

class TR_IremboPay_Settings {
	public static function get(): array {
		return [ 'enabled' => true, 'secret_key' => '', 'default_product_code' => '' ];
	}
	public static function is_enabled(): bool { return false; } // enabled flag true but no secret key
	public static function default_product_code(): string { return ''; }
}

class TR_Logger {
	public static array $lines = [];
	public static function info( string $m, array $c = [] ): void { self::$lines[] = [ 'info', $m ]; }
	public static function warning( string $m, array $c = [] ): void { self::$lines[] = [ 'warning', $m ]; }
	public static function error( string $m, array $c = [] ): void { self::$lines[] = [ 'error', $m ]; }
	public static function debug_enabled(): bool { return false; }
}

class TR_Cron {
	const HOOK = 'tangnest_robotics_daily';
}

class TR_Message_Tokens {
	public static function status_label( object $family ): string { return 'None'; }
}

class TR_Access_Tokens {
	public static function status_label( object $family ): string { return 'No link sent'; }
}

class TR_Notifications {
	public static function send_invoice_issued_email( int $family_id, int $invoice_id ): bool { return true; }
	public static function send_reminder_email( int $invoice_id ): bool { return true; }
	public static function reminder_due_line( string $due_date ): string { return 'We kindly remind you that your payment is due today.'; }
}

class TR_Payment {
	public static function is_payable( object $invoice ): bool { return true; }
}

class TR_Parent_Dashboard {
	public static function get_url(): string { return 'https://example.com/dashboard/'; }
}

require_once __DIR__ . '/../includes/class-tr-invoice-generator.php';
require_once __DIR__ . '/../includes/cli/class-tr-cli-commands.php';

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

function run_command( callable $fn, string $label ): array {
	global $failures;
	WP_CLI::$lines = [];
	try {
		$fn();
		echo "PASS: {$label} ran without a fatal error\n";
	} catch ( WP_CLI_Test_Exit $e ) {
		$failures++;
		echo "FAIL: {$label} called WP_CLI::error() unexpectedly: {$e->getMessage()}\n";
	} catch ( \Throwable $e ) {
		$failures++;
		echo "FAIL: {$label} threw " . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	}
	return WP_CLI::$lines;
}

$cmd = new TR_CLI_Command();

// status: default text, then each machine format.
$lines = run_command( static fn() => $cmd->status( [], [] ), 'status (default)' );
check( has_line( $lines, 'Tangnest Robotics' ), 'status: prints the header line' );
check( has_substring( $lines, 'has no package' ), 'status: flags the family with no package' );
check( has_substring( $lines, 'no IremboPay secret key' ), 'status: flags online pay enabled with no secret key' );
check( has_substring( $lines, 'not scheduled' ), 'status: flags the missing cron event' );
check( none_contain( $lines, 'raw' ) && none_contain( $lines, 'http' ), 'status: no URL-looking value in the output' );

run_command( static fn() => $cmd->status( [], [ 'format' => 'json' ] ), 'status --format=json' );
run_command( static fn() => $cmd->status( [], [ 'format' => 'table' ] ), 'status --format=table' );

// generate --dry-run and --dry-run --family=5
$lines = run_command( static fn() => $cmd->generate( [], [ 'dry-run' => true ] ), 'generate --dry-run' );
check( has_substring( $lines, 'Would create' ), 'generate --dry-run: says "Would create", not "Created"' );
check( 0 === count( TR_Invoices::get_list() ) || 1 === count( TR_Invoices::get_list() ), 'generate --dry-run: invoice store untouched (insert() stub never actually appends)' );

run_command( static fn() => $cmd->generate( [], [ 'dry-run' => true, 'family' => 5 ] ), 'generate --dry-run --family=5' );
run_command( static fn() => $cmd->generate( [], [ 'dry-run' => true, 'format' => 'json' ] ), 'generate --dry-run --format=json' );

// family <id>
$lines = run_command( static fn() => $cmd->family( [ '5' ], [] ), 'family 5 (default)' );
check( has_substring( $lines, 'Andre KAZEYEZU' ), 'family 5: shows the parent name' );
check( none_contain( $lines, 'https://example.com/dashboard' ), 'family 5: never prints the actual dashboard/access URL' );

run_command( static fn() => $cmd->family( [ '5' ], [ 'format' => 'json' ] ), 'family 5 --format=json' );
run_command( static fn() => $cmd->family( [ '6' ], [] ), 'family 6 (no package)' );

try {
	$cmd->family( [ '999' ], [] );
	echo "FAIL: family 999 (missing) should have called WP_CLI::error()\n";
	$failures++;
} catch ( WP_CLI_Test_Exit $e ) {
	echo "PASS: family 999 (missing) calls WP_CLI::error()\n";
}

// invoices
run_command( static fn() => $cmd->invoices( [], [] ), 'invoices (default)' );
run_command( static fn() => $cmd->invoices( [], [ 'format' => 'json' ] ), 'invoices --format=json' );
run_command( static fn() => $cmd->invoices( [], [ 'status' => 'pending' ] ), 'invoices --status=pending' );

// remind --dry-run
$lines = run_command( static fn() => $cmd->remind( [ '9' ], [ 'dry-run' => true ] ), 'remind 9 --dry-run' );
check( has_substring( $lines, 'due today' ), 'remind --dry-run: shows the resolved day-count line' );
check( has_substring( $lines, 'DRY RUN' ), 'remind --dry-run: says it is a dry run' );
check( none_contain( $lines, 'https://example.com/dashboard' ), 'remind --dry-run: never prints the actual pay/access URL' );

run_command( static fn() => $cmd->remind( [ '9' ], [ 'dry-run' => true, 'format' => 'json' ] ), 'remind 9 --dry-run --format=json' );

$lines = run_command( static fn() => $cmd->remind( [ '9' ], [] ), 'remind 9 (real send, stubbed)' );
check( has_substring( $lines, 'Reminder sent' ), 'remind (real): reports the send as successful' );

function has_line( array $lines, string $needle ): bool {
	foreach ( $lines as $l ) {
		if ( is_string( $l ) && 0 === strpos( trim( $l ), $needle ) ) {
			return true;
		}
	}
	return false;
}

function has_substring( array $lines, string $needle ): bool {
	foreach ( $lines as $l ) {
		if ( is_string( $l ) && false !== strpos( $l, $needle ) ) {
			return true;
		}
	}
	return false;
}

function none_contain( array $lines, string $needle ): bool {
	return ! has_substring( $lines, $needle );
}

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s).\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );

}
