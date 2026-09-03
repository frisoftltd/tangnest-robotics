<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_programs. default_monthly_fee is a
 * convenience figure only — families are billed their own monthly_amount,
 * never the program fee.
 */
class TR_Programs {
	const STATUSES = [ 'active', 'inactive' ];

	private static function table(): string {
		return TR_DB::table_programs();
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (name, duration_months, default_monthly_fee, irembopay_product_code, start_date, status, created_at, updated_at)
			 VALUES (%s, %d, %s, %s, %s, %s, %s, %s)",
			[
				sanitize_text_field( $data['name'] ),
				absint( $data['duration_months'] ),
				number_format( (float) ( $data['default_monthly_fee'] ?? 0 ), 2, '.', '' ),
				( $data['irembopay_product_code'] ?? '' ) !== '' ? sanitize_text_field( $data['irembopay_product_code'] ) : null,
				( $data['start_date'] ?? '' ) !== '' ? $data['start_date'] : null,
				in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active',
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
			"UPDATE " . self::table() . " SET name = %s, duration_months = %d, default_monthly_fee = %s, irembopay_product_code = %s, start_date = %s, status = %s, updated_at = %s WHERE id = %d",
			[
				sanitize_text_field( $data['name'] ),
				absint( $data['duration_months'] ),
				number_format( (float) ( $data['default_monthly_fee'] ?? 0 ), 2, '.', '' ),
				( $data['irembopay_product_code'] ?? '' ) !== '' ? sanitize_text_field( $data['irembopay_product_code'] ) : null,
				( $data['start_date'] ?? '' ) !== '' ? $data['start_date'] : null,
				in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active',
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
			'search'   => '',
			'orderby'  => 'name',
			'order'    => 'ASC',
			'per_page' => 20,
			'page'     => 1,
		] );

		$allowed_orderby = [ 'name', 'duration_months', 'default_monthly_fee', 'start_date', 'status', 'created_at', 'id' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
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
		global $wpdb;

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		return [ implode( ' AND ', $where ), $params ];
	}
}
