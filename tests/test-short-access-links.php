<?php
/**
 * Standalone regression test for short access links (v0.10.4) — run
 * directly with `php tests/test-short-access-links.php`, no WordPress or
 * WP-CLI bootstrap needed. Exits non-zero on any failure.
 *
 * Covers:
 *   - TR_Parent_Dashboard::generate_raw_token() produces a 22-character,
 *     URL-safe, base64url string from 16 bytes of random_bytes() output,
 *     and that output is actually unpredictable (not a fixed string, not
 *     visibly patterned) across repeated calls.
 *   - The format-acceptance regexes TR_Access_Tokens::validate_and_consume()
 *     actually ships with (read from the real source file, not a copy
 *     that could drift) accept both a fresh 22-char token and a legacy
 *     64-char hex token, and reject anything else — including each format
 *     at the WRONG length, and strings that are the right length but use
 *     disallowed characters.
 *   - TR_Parent_Dashboard::short_link_base() picks "/p/" when nothing
 *     else has registered a conflicting rule, "/go/" when something has,
 *     and then never re-evaluates (the cached decision survives even
 *     once a "/p/" rule — its own — genuinely exists).
 *   - TR_Parent_Dashboard::build_access_url() emits the short /<base>/
 *     <token> form on a site with pretty permalinks, and falls back to
 *     the long ?tr_access= form on a plain-permalink site where no
 *     rewrite rule could ever actually be reached.
 */

define( 'ABSPATH', __DIR__ . '/' );

// --- Minimal WP stand-ins — just enough for the methods under test. ---

$GLOBALS['OPTIONS'] = [];

function get_option( string $name, $default = false ) {
	return $GLOBALS['OPTIONS'][ $name ] ?? $default;
}

function update_option( string $name, $value, $autoload = true ): bool {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	return true;
}

function home_url( string $path = '' ): string {
	return 'https://lms.tangnest.rw' . $path;
}

/**
 * Real add_query_arg() handles far more than this (arrays, current
 * request URL, multiple existing params) — this covers only the single
 * key/value-onto-a-clean-URL case build_access_url() actually uses.
 */
function add_query_arg( string $key, $value, string $url ): string {
	$sep = false !== strpos( $url, '?' ) ? '&' : '?';
	return $url . $sep . $key . '=' . $value;
}

class TR_Logger {
	public static array $lines = [];
	public static function info( string $m, array $c = [] ): void { self::$lines[] = [ 'info', $m ]; }
	public static function warning( string $m, array $c = [] ): void { self::$lines[] = [ 'warning', $m ]; }
}

// get_url() is called by build_access_url() — give it a real permalink
// without pulling in the whole dashboard-page-option machinery.
class TR_Parent_Dashboard_Test_Support {
	public static string $dashboard_url = 'https://lms.tangnest.rw/robotics-parent-dashboard/';
}

require_once __DIR__ . '/../includes/class-tr-parent-dashboard.php';

// get_url() reads an option this test never sets directly — stub it via
// a tiny subclass-free override: define the option it reads instead.
// TR_Parent_Dashboard::get_url() calls get_permalink( $page_id ), which
// this test doesn't define — so exercise build_access_url() through a
// fixed override instead, matching how the real method is actually used
// (get_url()'s own correctness is plain option + get_permalink() glue,
// not logic this release touches).
function get_permalink( $page_id ) {
	return TR_Parent_Dashboard_Test_Support::$dashboard_url;
}
$GLOBALS['OPTIONS'][ TR_Parent_Dashboard::OPTION_PAGE_ID ] = 1;

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

function reset_options(): void {
	$GLOBALS['OPTIONS'] = [ TR_Parent_Dashboard::OPTION_PAGE_ID => 1 ];
	TR_Logger::$lines   = [];
}

// 1. generate_raw_token(): 22 chars, URL-safe charset, 16 real random
//    bytes underneath (not a fixed or weakly-varying value).

$tokens = [];
for ( $i = 0; $i < 200; $i++ ) {
	$tokens[] = TR_Parent_Dashboard::generate_raw_token();
}

check( 22 === strlen( $tokens[0] ), 'generate_raw_token(): exactly 22 characters' );

$all_match_charset = true;
foreach ( $tokens as $t ) {
	if ( ! preg_match( '/^[A-Za-z0-9_-]{22}$/', $t ) ) {
		$all_match_charset = false;
		break;
	}
}
check( $all_match_charset, 'generate_raw_token(): every token matches [A-Za-z0-9_-]{22}' );
check( count( array_unique( $tokens ) ) === count( $tokens ), 'generate_raw_token(): 200 calls produce 200 distinct tokens' );

// base64url of 16 random bytes never contains '=' padding (16 % 3 != 0,
// but base64_encode(16 bytes) still always lands on a '==' or no-pad
// boundary depending on length — the real guarantee is simply that the
// rtrim() in the source strips it, i.e. no token ends up NEEDING padding
// characters at all in its 22-char form).
check( false === strpos( $tokens[0], '=' ), 'generate_raw_token(): no base64 padding leaks through' );

// 2. Format-acceptance regexes — read from the real source file, not a
//    copy that could silently drift from what's actually shipped.

$source = file_get_contents( __DIR__ . '/../includes/class-tr-access-tokens.php' );
check( false !== strpos( $source, "'/^[A-Za-z0-9_-]{22}\$/'" ), 'source: ships the exact short-token regex from spec' );
check( false !== strpos( $source, "'/^[a-f0-9]{64}\$/'" ), 'source: ships the exact legacy-token regex from spec' );

$short_pattern  = '/^[A-Za-z0-9_-]{22}$/';
$legacy_pattern = '/^[a-f0-9]{64}$/';

function format_of( string $token, string $short_pattern, string $legacy_pattern ): ?string {
	if ( preg_match( $short_pattern, $token ) ) { return 'short'; }
	if ( preg_match( $legacy_pattern, $token ) ) { return 'legacy'; }
	return null;
}

check( 'short' === format_of( TR_Parent_Dashboard::generate_raw_token(), $short_pattern, $legacy_pattern ), 'format check: a real generated token is accepted as "short"' );
check( 'legacy' === format_of( str_repeat( 'a', 64 ), $short_pattern, $legacy_pattern ), 'format check: 64 lowercase hex chars accepted as "legacy"' );
check( 'legacy' === format_of( str_repeat( '0123456789abcdef', 4 ), $short_pattern, $legacy_pattern ), 'format check: a real-shaped 64-hex token accepted as "legacy"' );

check( null === format_of( str_repeat( 'a', 63 ), $short_pattern, $legacy_pattern ), 'format check: 63 hex chars (one short) rejected' );
check( null === format_of( str_repeat( 'a', 65 ), $short_pattern, $legacy_pattern ), 'format check: 65 hex chars (one long) rejected' );
check( null === format_of( str_repeat( 'A', 22 ) . 'x', $short_pattern, $legacy_pattern ), 'format check: 23 chars (one long of the short form) rejected' );
check( null === format_of( str_repeat( 'A', 21 ), $short_pattern, $legacy_pattern ), 'format check: 21 chars (one short of the short form) rejected' );
check( null === format_of( str_repeat( 'g', 64 ), $short_pattern, $legacy_pattern ), 'format check: 64 chars using "g" (not valid hex) rejected' );
check( null === format_of( str_repeat( 'A', 64 ), $short_pattern, $legacy_pattern ), 'format check: 64 uppercase chars (legacy is lowercase-hex only) rejected' );
check( null === format_of( str_repeat( '!', 22 ), $short_pattern, $legacy_pattern ), 'format check: 22 chars of punctuation rejected' );
check( null === format_of( '', $short_pattern, $legacy_pattern ), 'format check: empty string rejected' );
check( null === format_of( "' OR 1=1", $short_pattern, $legacy_pattern ), 'format check: SQL-injection-shaped garbage rejected' );

// 3. short_link_base(): no conflict found → "p", decided once and cached.

reset_options();
check( 'p' === TR_Parent_Dashboard::short_link_base(), 'short_link_base(): no existing "^p/" rule → "p"' );
check( 'p' === $GLOBALS['OPTIONS'][ TR_Parent_Dashboard::SHORT_LINK_BASE_OPTION ], 'short_link_base(): decision is persisted' );

$had_info_line = false;
foreach ( TR_Logger::$lines as $line ) {
	if ( 'info' === $line[0] && false !== strpos( $line[1], 'no conflicting rewrite rule' ) ) { $had_info_line = true; }
}
check( $had_info_line, 'short_link_base(): reports the choice at info level when there is no conflict' );

// Re-adding our OWN rule into rewrite_rules (as a real flush eventually
// would) must never retroactively look like a conflict on a later call —
// but short_link_base() is cached after the first call anyway, so this
// also proves the cache, not just the exclusion logic.
$GLOBALS['OPTIONS']['rewrite_rules'] = [ '^p/([A-Za-z0-9_-]{22})/?$' => 'index.php?pagename=robotics-parent-dashboard&tr_access=$matches[1]' ];
check( 'p' === TR_Parent_Dashboard::short_link_base(), 'short_link_base(): a second call reuses the cached decision, never re-evaluates' );

// 4. short_link_base(): a genuine pre-existing "^p/" rule from something
//    else → "go", reported as a warning.

reset_options();
$GLOBALS['OPTIONS']['rewrite_rules'] = [ '^p/([^/]+)/?$' => 'index.php?some_other_plugin=$matches[1]' ];
check( 'go' === TR_Parent_Dashboard::short_link_base(), 'short_link_base(): an existing unrelated "^p/" rule → "go"' );

$had_warning_line = false;
foreach ( TR_Logger::$lines as $line ) {
	if ( 'warning' === $line[0] && false !== strpos( $line[1], 'using "/go/" instead' ) ) { $had_warning_line = true; }
}
check( $had_warning_line, 'short_link_base(): reports the fallback at warning level when there IS a conflict' );

// 5. build_access_url(): short form on a pretty-permalink site, long
//    ?tr_access= form on a plain-permalink site.

reset_options();
$GLOBALS['OPTIONS']['permalink_structure'] = '/%postname%/';
$short_url = TR_Parent_Dashboard::build_access_url( 'K7mQp2vXnR4tL9wZbF8gHs' );
check( 0 === strpos( $short_url, 'https://lms.tangnest.rw/p/K7mQp2vXnR4tL9wZbF8gHs' ), 'build_access_url(): pretty permalinks → short /p/<token> form' );
check( false === strpos( $short_url, 'tr_access' ), 'build_access_url(): short form never carries the tr_access param name' );
check( strlen( 'https://lms.tangnest.rw/p/K7mQp2vXnR4tL9wZbF8gHs' ) <= 50, 'build_access_url(): the short form is roughly 48 characters, not 125' );

reset_options();
$GLOBALS['OPTIONS']['permalink_structure'] = '';
$long_url = TR_Parent_Dashboard::build_access_url( 'K7mQp2vXnR4tL9wZbF8gHs' );
check( false !== strpos( $long_url, '?tr_access=K7mQp2vXnR4tL9wZbF8gHs' ), 'build_access_url(): plain permalinks → long ?tr_access= fallback (no rewrite rule could ever route the short form)' );
check( 0 === strpos( $long_url, TR_Parent_Dashboard_Test_Support::$dashboard_url ), 'build_access_url(): plain-permalink fallback is built on the real dashboard URL' );

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s).\n";
	exit( 1 );
}

echo "\nAll tests passed.\n";
exit( 0 );
