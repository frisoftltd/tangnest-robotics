<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Schedules the daily invoice-generation job. Registered from two places —
 * the activation hook AND a plugins_loaded check — because this plugin's
 * usual deploy path is a file copy that never fires activation hooks, so
 * activation alone would leave the site with no cron event at all.
 */
class TR_Cron {
	const HOOK = 'tangnest_robotics_daily';

	public function __construct() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_schedule' ], 20 );
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function run(): void {
		TR_Invoice_Generator::run();
	}
}
