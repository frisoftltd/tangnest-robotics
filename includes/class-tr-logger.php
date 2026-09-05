<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Standalone file logger. No WooCommerce dependency — writes into a
 * protected subdirectory of the uploads folder instead of using wc_get_logger().
 */
class TR_Logger {
	const DIR_NAME           = 'tangnest-robotics-logs';
	const DEBUG_OPTION       = 'tangnest_robotics_debug_logging';
	const RETENTION_DAYS     = 30;

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

	public static function debug_enabled(): bool {
		return (bool) get_option( self::DEBUG_OPTION, false );
	}

	/**
	 * Gated behind the "Debug logging" toggle in Robotics → Settings,
	 * default off — a single dashboard visit can touch several classes
	 * that each write a debug line, and this hook fires on every front-end
	 * request site-wide, so left always-on this is thousands of lines a
	 * day once parents are actually using it. INFO/WARNING/ERROR always
	 * write regardless — those only fire when something is actually
	 * noteworthy or wrong.
	 */
	public static function debug( string $message, array $context = [] ): void {
		if ( ! self::debug_enabled() ) {
			return;
		}
		self::log( $message, 'debug', $context );
	}

	public static function info( string $message, array $context = [] ): void    { self::log( $message, 'info', $context ); }
	public static function warning( string $message, array $context = [] ): void { self::log( $message, 'warning', $context ); }
	public static function error( string $message, array $context = [] ): void   { self::log( $message, 'error', $context ); }

	/**
	 * Called from the daily cron job. Log files are named {YYYY-MM-DD}.log
	 * (see log()), so the cutoff date is read straight from the filename
	 * rather than filesystem mtime — accurate regardless of how the files
	 * were copied or synced onto the server.
	 */
	public static function rotate(): void {
		$dir = self::log_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$cutoff = strtotime( '-' . self::RETENTION_DAYS . ' days', current_time( 'timestamp' ) );

		foreach ( glob( $dir . '/*.log' ) ?: [] as $file ) {
			$file_date = substr( basename( $file ), 0, 10 );
			$file_ts   = strtotime( $file_date );

			if ( false !== $file_ts && $file_ts < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}
}
