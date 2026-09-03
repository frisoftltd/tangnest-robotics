<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_families, plus the billing-anchor and
 * composition-change-notice logic from spec §4.
 *
 * billing_day of 0 means "not yet anchored" — the column is NOT NULL so we
 * use 0 as the sentinel rather than allowing a real 1–28 value to be
 * ambiguous with "unset".
 */
class TR_Families {
	const STATUSES        = [ 'active', 'inactive' ];
	const REVIEW_OPTION    = 'tangnest_robotics_review_families';

	private static function table(): string {
		return TR_DB::table_families();
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (parent_user_id, monthly_amount, currency, billing_day, status, notes, created_at, updated_at)
			 VALUES (%d, %s, %s, %d, %s, %s, %s, %s)",
			[
				absint( $data['parent_user_id'] ),
				number_format( (float) ( $data['monthly_amount'] ?? 0 ), 2, '.', '' ),
				$data['currency'] ?? 'RWF',
				absint( $data['billing_day'] ?? 0 ),
				in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active',
				( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null,
				$now,
				$now,
			]
		);
		$wpdb->query( $sql );

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET monthly_amount = %s, currency = %s, billing_day = %d, status = %s, notes = %s, updated_at = %s WHERE id = %d",
			[
				number_format( (float) ( $data['monthly_amount'] ?? 0 ), 2, '.', '' ),
				$data['currency'] ?? 'RWF',
				absint( $data['billing_day'] ?? 0 ),
				in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active',
				( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null,
				$now,
				$id,
			]
		);

		return false !== $wpdb->query( $sql );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", [ $id ] ) );

		return $row ?: null;
	}

	public static function get_list( array $args = [] ): array {
		global $wpdb;

		$args = wp_parse_args( $args, [
			'status'   => '',
			'orderby'  => 'id',
			'order'    => 'ASC',
			'per_page' => 20,
			'page'     => 1,
		] );

		$allowed_orderby = [ 'id', 'monthly_amount', 'billing_day', 'status', 'created_at' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		[ $where_sql, $params ] = self::build_where( $args );

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;
		$params[] = $per_page;
		$params[] = $offset;

		$sql = "SELECT * FROM " . self::table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count( array $args = [] ): int {
		global $wpdb;

		[ $where_sql, $params ] = self::build_where( $args );
		$sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE {$where_sql}";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	private static function build_where( array $args ): array {
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	public static function get_by_user( int $user_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE parent_user_id = %d", [ $user_id ] ) );

		return $row ?: null;
	}

	public static function get_or_create_for_user( int $user_id ): int {
		$family = self::get_by_user( $user_id );
		if ( null !== $family ) {
			return (int) $family->id;
		}

		return self::insert( [
			'parent_user_id' => $user_id,
			'monthly_amount' => 0,
			'currency'       => 'RWF',
			'billing_day'    => 0,
			'status'         => 'active',
		] );
	}

	/**
	 * Sets the family's billing_day from the first enrollment's date. Later
	 * siblings must never move an anchor that already exists (spec §4).
	 */
	public static function set_billing_anchor( int $family_id, string $enrolled_on ): void {
		global $wpdb;

		$family = self::get( $family_id );
		if ( null === $family || (int) $family->billing_day > 0 ) {
			return;
		}

		$timestamp = strtotime( $enrolled_on );
		if ( false === $timestamp ) {
			return;
		}

		$billing_day = min( (int) gmdate( 'j', $timestamp ), 28 );
		$now         = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET billing_day = %d, updated_at = %s WHERE id = %d",
			[ $billing_day, $now, $family_id ]
		);
		$wpdb->query( $sql );
	}

	public static function next_billing_date( int $family_id ): ?string {
		$family = self::get( $family_id );
		if ( null === $family || (int) $family->billing_day < 1 ) {
			return null;
		}

		$day   = (int) $family->billing_day;
		$today = new DateTime( 'today' );
		$month = $today->format( 'Y-m' );

		$candidate = DateTime::createFromFormat( 'Y-m-d', $month . '-' . str_pad( (string) $day, 2, '0', STR_PAD_LEFT ) );
		if ( false === $candidate ) {
			return null;
		}

		if ( $candidate <= $today ) {
			$candidate->modify( '+1 month' );
		}

		return $candidate->format( 'Y-m-d' );
	}

	public static function flag_composition_change( int $family_id ): void {
		$families = get_option( self::REVIEW_OPTION, [] );
		if ( ! is_array( $families ) ) {
			$families = [];
		}

		if ( ! in_array( $family_id, $families, true ) ) {
			$families[] = $family_id;
			update_option( self::REVIEW_OPTION, $families, false );
		}
	}

	public static function clear_composition_flag( int $family_id ): void {
		$families = get_option( self::REVIEW_OPTION, [] );
		if ( ! is_array( $families ) || empty( $families ) ) {
			return;
		}

		$families = array_values( array_diff( $families, [ $family_id ] ) );
		update_option( self::REVIEW_OPTION, $families, false );
	}
}
