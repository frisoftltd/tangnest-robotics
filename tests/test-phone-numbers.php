<?php
/**
 * Standalone regression test for international phone numbers (v0.10.5) —
 * run directly with `php tests/test-phone-numbers.php`, no WordPress or
 * WP-CLI bootstrap needed. Exits non-zero on any failure.
 *
 * Covers, against the real source:
 *   - TR_Notifications::msisdn() — the single place "+250788308807"
 *     becomes "250788308807" for WhatsApp and IremboPay, and the single
 *     place that refuses anything not valid E.164 (never a guess).
 *   - The save-time validation regex (E.164: +, country code not
 *     starting with 0, 8-15 digits total) against the exact accept/
 *     reject matrix from the acceptance criteria, plus the
 *     spaces/dashes/brackets/dots cleaning step that runs before it.
 *   - TR_DB's phone migration: 0XXXXXXXXX → +250XXXXXXXXX, a number
 *     already starting with + left untouched, anything matching
 *     neither shape left untouched AND reported by family ID, and the
 *     converted count logged.
 *   - No "250" literal remains anywhere outside the migration itself
 *     (which legitimately needs it once, to convert legacy Rwandan
 *     numbers) — a regression guard against the old hard-coded prefix
 *     creeping back in.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['OPTIONS']   = [];
$GLOBALS['USER_META'] = [];

function get_option( string $name, $default = false ) { return $GLOBALS['OPTIONS'][ $name ] ?? $default; }
function update_option( string $name, $value, $autoload = true ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function get_user_meta( int $user_id, string $key, bool $single = false ) { return $GLOBALS['USER_META'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( int $user_id, string $key, $value ) { $GLOBALS['USER_META'][ $user_id ][ $key ] = $value; return true; }

class TR_Logger {
	public static array $lines = [];
	public static function info( string $m, array $c = [] ): void { self::$lines[] = [ 'info', $m, $c ]; }
	public static function error( string $m, array $c = [] ): void { self::$lines[] = [ 'error', $m, $c ]; }
}

class Fake_WPDB {
	public string $prefix = 'wp_';

	public function get_results( string $sql ) {
		$rows = [];
		foreach ( $GLOBALS['TEST_FAMILIES'] as $f ) {
			$rows[] = (object) [ 'id' => $f['id'], 'parent_user_id' => $f['parent_user_id'] ];
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once __DIR__ . '/../includes/class-tr-notifications.php';
require_once __DIR__ . '/../includes/class-tr-db.php';

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

// Clean + validate exactly as TR_Family_Edit::maybe_handle_submit() does —
// copied here as a literal, not called, since it's inline in a method
// that has a dozen other side effects; this proves the two regexes used
// there (and verified by direct comparison against the real file below).
function clean_and_validate( string $raw ): bool {
	$phone = preg_replace( '/[\s\-().]/', '', $raw );
	return (bool) preg_match( '/^\+[1-9]\d{7,14}$/', $phone );
}

// 1. msisdn(): valid E.164 → bare digits; anything else → ''.

check( '250788308807' === TR_Notifications::msisdn( '+250788308807' ), 'msisdn(): +250788308807 -> 250788308807' );
check( '447700900123' === TR_Notifications::msisdn( '+447700900123' ), 'msisdn(): a non-Rwandan E.164 number converts the same way' );
check( '' === TR_Notifications::msisdn( '0788308807' ), 'msisdn(): a bare Rwandan-local number (no +) is refused, not guessed at' );
check( '' === TR_Notifications::msisdn( '' ), 'msisdn(): empty string is refused' );
check( '' === TR_Notifications::msisdn( '+0788308807' ), 'msisdn(): a country code starting with 0 is refused (not valid E.164)' );
check( '' === TR_Notifications::msisdn( '+2507883088077777777' ), 'msisdn(): more than 15 digits is refused' );
check( '' === TR_Notifications::msisdn( '+2507' ), 'msisdn(): fewer than 8 digits is refused' );

// 2. Save-time validation — the exact matrix from the acceptance criteria.

check( clean_and_validate( '+250788308807' ), 'validation: +250788308807 is accepted' );
check( ! clean_and_validate( '0788308807' ), 'validation: 0788308807 (the old format) is rejected' );
check( clean_and_validate( '+44 7700 900123' ), 'validation: "+44 7700 900123" is accepted after cleaning' );
check( '+447700900123' === preg_replace( '/[\s\-().]/', '', '+44 7700 900123' ), 'validation: "+44 7700 900123" cleans to exactly +447700900123' );
check( clean_and_validate( '+254712345678' ), 'validation: a Kenyan number is accepted — nothing Rwanda-specific' );
check( clean_and_validate( '+1-202-555-0143' ), 'validation: dashes are stripped before validating' );
check( clean_and_validate( '+1 (202) 555.0143' ), 'validation: spaces, brackets and dots are all stripped before validating' );
check( ! clean_and_validate( '250788308807' ), 'validation: digits only, no leading + at all, is rejected' );
check( ! clean_and_validate( '+0788308807' ), 'validation: a country code starting with 0 is rejected' );
check( ! clean_and_validate( '+1234567' ), 'validation: 7 digits after + (one short of the 8-digit floor) is rejected' );
check( clean_and_validate( '+12345678' ), 'validation: exactly 8 digits after + is accepted (the floor)' );
check( clean_and_validate( '+123456789012345' ), 'validation: exactly 15 digits after + is accepted (the ceiling)' );
check( ! clean_and_validate( '+1234567890123456' ), 'validation: 16 digits after + is rejected (one over the ceiling)' );
check( ! clean_and_validate( "+250788308807; DROP TABLE wp_users;" ), 'validation: trailing garbage after a valid-looking prefix is rejected' );

// Cross-check against the real source: the two files must actually ship
// these exact patterns, not a copy this test drifted away from.
$family_edit_source = file_get_contents( __DIR__ . '/../includes/admin/class-tr-family-edit.php' );
check( false !== strpos( $family_edit_source, "'/^\\+[1-9]\\d{7,14}\$/'" ), 'source: class-tr-family-edit.php ships the exact E.164 regex from spec' );
check( false !== strpos( $family_edit_source, "'/[\\s\\-().]/'" ), 'source: class-tr-family-edit.php ships the exact cleaning regex' );

$notifications_source = file_get_contents( __DIR__ . '/../includes/class-tr-notifications.php' );
check( false !== strpos( $notifications_source, "'/^\\+[1-9]\\d{7,14}\$/'" ), 'source: class-tr-notifications.php ships the exact E.164 regex from spec' );

// 3. No "250" literal anywhere outside the migration file (which
//    legitimately needs it once, to convert legacy numbers).

$offenders = [];
foreach ( glob( __DIR__ . '/../includes/**/*.php' ) + glob( __DIR__ . '/../includes/*.php' ) + glob( __DIR__ . '/../templates/*.php' ) as $file ) {
	if ( false !== strpos( $file, 'class-tr-db.php' ) ) {
		continue; // the migration itself — see below for its own check
	}
	$contents = file_get_contents( $file );
	if ( preg_match( "/['\"]250['\"]/", $contents ) ) {
		$offenders[] = basename( $file );
	}
}
check( empty( $offenders ), 'no "250" literal remains anywhere outside the migration: ' . ( empty( $offenders ) ? '(none found)' : implode( ', ', $offenders ) ) );

$db_source = file_get_contents( __DIR__ . '/../includes/class-tr-db.php' );
check( 1 === preg_match_all( "/'\\+250'/", $db_source ), 'the migration itself references "+250" exactly once (its one legitimate, one-time use)' );

// 4. The migration itself, against the real TR_DB class via reflection
//    (it's private — deliberately not part of this class's public API).

function run_migration(): void {
	$method = new ReflectionMethod( 'TR_DB', 'migrate_phone_numbers_to_international_format' );
	$method->setAccessible( true );
	$method->invoke( null );
}

$GLOBALS['TEST_FAMILIES'] = [
	[ 'id' => 1, 'parent_user_id' => 101 ],
	[ 'id' => 2, 'parent_user_id' => 102 ],
	[ 'id' => 3, 'parent_user_id' => 103 ],
	[ 'id' => 4, 'parent_user_id' => 104 ],
	[ 'id' => 5, 'parent_user_id' => 105 ],
];
$GLOBALS['USER_META'] = [
	101 => [ 'phone_number' => '0788308807' ],       // converts
	102 => [ 'phone_number' => '+447700900123' ],    // already international — untouched
	103 => [ 'phone_number' => '12345' ],            // matches neither shape — needs review
	104 => [ 'phone_number' => '' ],                 // blank — skipped silently
	105 => [ 'phone_number' => '0712345678' ],       // converts
];
TR_Logger::$lines = [];

run_migration();

check( '+250788308807' === $GLOBALS['USER_META'][101]['phone_number'], 'migration: 0788308807 -> +250788308807' );
check( '+447700900123' === $GLOBALS['USER_META'][102]['phone_number'], 'migration: an already-international number is left untouched' );
check( '12345' === $GLOBALS['USER_META'][103]['phone_number'], 'migration: a number matching neither shape is left untouched, not guessed at' );
check( '+250712345678' === $GLOBALS['USER_META'][105]['phone_number'], 'migration: a second 0XXXXXXXXX number also converts' );

$logged_summary = null;
foreach ( TR_Logger::$lines as $line ) {
	if ( 'info' === $line[0] && false !== strpos( $line[1], 'phone numbers converted' ) ) {
		$logged_summary = $line[2];
	}
}
check( null !== $logged_summary, 'migration: logs a summary line' );
check( null !== $logged_summary && 2 === $logged_summary['converted_count'], 'migration: logs the converted count as exactly 2' );
check( null !== $logged_summary && [ 3 ] === $logged_summary['needs_review_ids'], 'migration: logs family #3 specifically as needing manual review' );

// Re-running must be idempotent — an already-converted number must
// never be converted again or flagged.
TR_Logger::$lines = [];
run_migration();
check( '+250788308807' === $GLOBALS['USER_META'][101]['phone_number'], 'migration: re-running leaves an already-converted number unchanged' );

foreach ( TR_Logger::$lines as $line ) {
	if ( 'info' === $line[0] && false !== strpos( $line[1], 'phone numbers converted' ) ) {
		check( 0 === $line[2]['converted_count'], 'migration: re-running converts nothing the second time' );
	}
}

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s).\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
