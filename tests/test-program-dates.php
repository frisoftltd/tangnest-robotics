<?php
/**
 * Standalone regression test for the v0.8.6 zero-date fix — run directly
 * with `php tests/test-program-dates.php`, no WordPress bootstrap needed.
 * Exits non-zero on any failure.
 *
 * Covers both sides of the bug: TR_Families::is_date_set() (what the
 * generator now uses to decide a programme date is unset) and the SQL
 * TR_Families::insert()/update() actually build (which must emit a
 * literal NULL for an unset date instead of routing it through a %s
 * placeholder, which is what produced '0000-00-00' in the first place).
 */

define( 'ABSPATH', __DIR__ . '/' );

// --- Minimal WP/$wpdb stand-ins, just enough for TR_Families to run. ---

function current_time( $type ) {
	return 'mysql' === $type ? '2026-09-10 12:00:00' : time();
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_textarea_field( $value ) {
	return trim( (string) $value );
}

class Fake_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 42;
	public $last_query = '';

	/**
	 * Not a faithful reimplementation of $wpdb->prepare() — just enough
	 * %s/%d substitution to let the assertions below inspect the SQL
	 * TR_Families actually builds.
	 */
	public function prepare( $query, $args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$value = $args[ $i++ ];
			if ( '%d' === $m[0] ) {
				return (string) (int) $value;
			}
			return "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}

	public function query( $sql ) {
		$this->last_query = $sql;
		return 1;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

require_once __DIR__ . '/../includes/class-tr-db.php';
require_once __DIR__ . '/../includes/class-tr-families.php';

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

// 1. TR_Families::is_date_set() — the read-side fix used by
//    TR_Invoice_Generator to decide whether a programme date is real.

check( false === TR_Families::is_date_set( null ), 'is_date_set(null) is false' );
check( false === TR_Families::is_date_set( '0000-00-00' ), "is_date_set('0000-00-00') is false" );
check( false === TR_Families::is_date_set( '' ), "is_date_set('') is false" );
check( true === TR_Families::is_date_set( '2020-01-01' ), 'is_date_set(past date) is true' );
check( true === TR_Families::is_date_set( '2099-01-01' ), 'is_date_set(future date) is true' );

// Mirrors TR_Invoice_Generator::run()'s "programme has ended" check —
// this is the exact condition that permanently skipped family_id 2.
function would_skip_as_ended( $program_end_date, string $today_str ): bool {
	return TR_Families::is_date_set( $program_end_date ) && $program_end_date < $today_str;
}

$today = '2026-09-10';
check( false === would_skip_as_ended( null, $today ), 'NULL end date never skips generation' );
check( false === would_skip_as_ended( '0000-00-00', $today ), "'0000-00-00' end date never skips generation" );
check( false === would_skip_as_ended( '', $today ), 'empty-string end date never skips generation' );
check( true === would_skip_as_ended( '2020-01-01', $today ), 'a genuinely past end date does skip generation' );
check( false === would_skip_as_ended( '2099-01-01', $today ), 'a future end date does not skip generation' );

// 2. TR_Families::insert()/update() — the write-side fix. An unset date
//    (NULL, '' or '0000-00-00' coming back in from a stale form repost)
//    must land in the query as a literal NULL, never as ''.

$base_data = [
	'parent_user_id' => 1,
	'monthly_amount' => 100,
	'package_id'      => 1,
	'months_paid'     => 0,
	'currency'        => 'RWF',
	'billing_day'     => 5,
	'status'          => 'active',
	'notes'           => '',
];

foreach ( [ null, '', '0000-00-00' ] as $unset_value ) {
	$wpdb = $GLOBALS['wpdb'];
	TR_Families::insert( array_merge( $base_data, [
		'program_start_date' => $unset_value,
		'program_end_date'   => $unset_value,
	] ) );

	check(
		1 === preg_match( '/VALUES \([^)]*NULL, NULL,/', $wpdb->last_query ),
		'insert(): unset value ' . var_export( $unset_value, true ) . ' becomes literal NULL, NULL in SQL'
	);
	check(
		false === strpos( $wpdb->last_query, "'0000-00-00'" ),
		'insert(): unset value ' . var_export( $unset_value, true ) . ' never becomes a quoted 0000-00-00'
	);
}

$wpdb = $GLOBALS['wpdb'];
TR_Families::insert( array_merge( $base_data, [
	'program_start_date' => '2026-01-01',
	'program_end_date'   => '2026-12-31',
] ) );
check(
	false !== strpos( $wpdb->last_query, "'2026-01-01', '2026-12-31'" ),
	'insert(): a real start/end date pair is written as quoted values, not NULL'
);

$wpdb = $GLOBALS['wpdb'];
TR_Families::update( 7, array_merge( $base_data, [
	'program_start_date' => '0000-00-00',
	'program_end_date'   => null,
] ) );
check(
	1 === preg_match( '/program_start_date = NULL, program_end_date = NULL/', $wpdb->last_query ),
	"update(): '0000-00-00' and null both become literal NULL in SQL"
);

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s).\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
