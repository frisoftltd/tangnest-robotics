<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Standalone file logger. No WooCommerce dependency — writes into a
 * protected subdirectory of the uploads folder instead of using wc_get_logger().
 */
class TR_Logger {
	const DIR_NAME = 'tangnest-robotics-logs';

	private static function log_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::DIR_NAME;
	}

	private static function ensure_log_dir(): string {
		$dir = self::log_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		return $dir;
	}

	public static function log( string $message, string $level = 'info', array $context = [] ): void {
		$dir = self::ensure_log_dir();

		// Site (Kigali) time, not UTC — so log lines can be compared
		// directly against created_at/updated_at columns, which are also
		// written with current_time( 'mysql' ).
		$now  = current_time( 'mysql' );
		$file = $dir . '/' . substr( $now, 0, 10 ) . '.log';

		$line = sprintf(
			'[%s] %s: %s %s' . PHP_EOL,
			$now,
			strtoupper( $level ),
			$message,
			empty( $context ) ? '' : wp_json_encode( $context )
		);

		error_log( $line, 3, $file );
	}

	public static function debug( string $message, array $context = [] ): void   { self::log( $message, 'debug', $context ); }
	public static function info( string $message, array $context = [] ): void    { self::log( $message, 'info', $context ); }
	public static function warning( string $message, array $context = [] ): void { self::log( $message, 'warning', $context ); }
	public static function error( string $message, array $context = [] ): void   { self::log( $message, 'error', $context ); }
}
