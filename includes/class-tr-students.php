<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_students.
 */
class TR_Students {
	const STATUSES = [ 'active', 'withdrawn', 'archived' ];

	private static function table(): string {
		return TR_DB::table_students();
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (family_id, first_name, last_name, date_of_birth, school, status, notes, created_at, updated_at)
			 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)",
			[
				absint( $data['family_id'] ),
				sanitize_text_field( $data['first_name'] ),
				sanitize_text_field( $data['last_name'] ),
				( $data['date_of_birth'] ?? '' ) !== '' ? $data['date_of_birth'] : null,
				( $data['school'] ?? '' ) !== '' ? sanitize_text_field( $data['school'] ) : null,
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
			"UPDATE " . self::table() . " SET first_name = %s, last_name = %s, date_of_birth = %s, school = %s, status = %s, notes = %s, updated_at = %s WHERE id = %d",
			[
				sanitize_text_field( $data['first_name'] ),
				sanitize_text_field( $data['last_name'] ),
				( $data['date_of_birth'] ?? '' ) !== '' ? $data['date_of_birth'] : null,
				( $data['school'] ?? '' ) !== '' ? sanitize_text_field( $data['school'] ) : null,
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
			'family_id' => 0,
			'status'    => '',
			'search'    => '',
			'orderby'   => 'first_name',
			'order'     => 'ASC',
			'per_page'  => 20,
			'page'      => 1,
		] );

		$allowed_orderby = [ 'first_name', 'last_name', 'family_id', 'status', 'created_at', 'id' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'first_name';
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

		if ( ! empty( $args['family_id'] ) ) {
			$where[]  = 'family_id = %d';
			$params[] = absint( $args['family_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(first_name LIKE %s OR last_name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		return [ implode( ' AND ', $where ), $params ];
	}
}
