<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Financial audit trail for a family (v0.10.0) — every child added or
 * deactivated, every amount change, every negotiated-rate decision,
 * every programme-date edit, every invoice amount edit. Log files
 * rotate after 30 days (TR_Logger::RETENTION_DAYS) and nobody reads one
 * during a phone call; this table is what an admin actually looks at on
 * the family edit screen and in `wp tangnest family <id>`.
 *
 * Append-only by design: no update(), no delete(), no retention sweep.
 * A row here is never touched once written.
 */
class TR_Family_Events {
	const TYPES = [
		'child_added',
		'child_deactivated',
		'child_reactivated',
		'amount_changed',
		'package_changed',
		'negotiated_set',
		'negotiated_changed',
		'negotiated_cleared',
		'programme_dates_changed',
		'invoice_amount_edited',
		'invoice_created',
		'invoice_paid',
	];

	private static function table(): string {
		return TR_DB::table_family_events();
	}

	public static function record( int $family_id, string $event_type, string $summary, ?string $old_value = null, ?string $new_value = null, ?int $recorded_by = null ): int {
		global $wpdb;

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (family_id, event_type, summary, old_value, new_value, recorded_by, created_at)
			 VALUES (%d, %s, %s, %s, %s, %d, %s)",
			[
				$family_id,
				in_array( $event_type, self::TYPES, true ) ? $event_type : 'amount_changed',
				$summary,
				$old_value,
				$new_value,
				$recorded_by ?: 0,
				current_time( 'mysql' ),
			]
		);
		$wpdb->query( $sql );

		return (int) $wpdb->insert_id;
	}

	public static function get_by_family( int $family_id, int $limit = 0 ): array {
		global $wpdb;

		$sql = "SELECT * FROM " . self::table() . " WHERE family_id = %d ORDER BY created_at DESC, id DESC";
		$args = [ $family_id ];

		if ( $limit > 0 ) {
			$sql   .= " LIMIT %d";
			$args[] = $limit;
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	public static function count_by_family( int $family_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table() . " WHERE family_id = %d",
			[ $family_id ]
		) );
	}

	/**
	 * recorded_by of 0 means the cron/system did it, not an admin — label
	 * used wherever an event is displayed (family screen, CLI).
	 */
	public static function actor_label( object $event ): string {
		if ( empty( $event->recorded_by ) ) {
			return __( 'System', 'tangnest-robotics' );
		}

		$user = get_userdata( (int) $event->recorded_by );
		return $user ? $user->display_name : __( '(unknown)', 'tangnest-robotics' );
	}
}
