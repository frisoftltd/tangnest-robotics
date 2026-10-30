<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_programs — the packages table (v0.8.0).
 * Each row is one of Tangnest's IremboPay-priced packages: a course at a
 * specific family-size tier. default_monthly_fee, irembopay_product_code
 * and duration_months are the real price/code/length; a family selects
 * one package and TR_Family_Edit snapshots that fee into the family's own
 * monthly_amount at save time, so a later price change here never
 * retroactively alters an existing family's billing.
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
			"INSERT INTO " . self::table() . " (name, duration_months, default_monthly_fee, irembopay_product_code, course, child_count, start_date, notes, status, created_at, updated_at)
			 VALUES (%s, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s)",
			[
				sanitize_text_field( $data['name'] ),
				absint( $data['duration_months'] ),
				number_format( (float) ( $data['default_monthly_fee'] ?? 0 ), 2, '.', '' ),
				( $data['irembopay_product_code'] ?? '' ) !== '' ? sanitize_text_field( $data['irembopay_product_code'] ) : null,
				( $data['course'] ?? '' ) !== '' ? sanitize_text_field( $data['course'] ) : null,
				! empty( $data['child_count'] ) ? absint( $data['child_count'] ) : null,
				( $data['start_date'] ?? '' ) !== '' ? $data['start_date'] : null,
				( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null,
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
			"UPDATE " . self::table() . " SET name = %s, duration_months = %d, default_monthly_fee = %s, irembopay_product_code = %s, course = %s, child_count = %d, start_date = %s, notes = %s, status = %s, updated_at = %s WHERE id = %d",
			[
				sanitize_text_field( $data['name'] ),
				absint( $data['duration_months'] ),
				number_format( (float) ( $data['default_monthly_fee'] ?? 0 ), 2, '.', '' ),
				( $data['irembopay_product_code'] ?? '' ) !== '' ? sanitize_text_field( $data['irembopay_product_code'] ) : null,
				( $data['course'] ?? '' ) !== '' ? sanitize_text_field( $data['course'] ) : null,
				! empty( $data['child_count'] ) ? absint( $data['child_count'] ) : null,
				( $data['start_date'] ?? '' ) !== '' ? $data['start_date'] : null,
				( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null,
				in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active',
				$now,
				$id,
			]
		);

		return false !== $wpdb->query( $sql );
	}

	/**
	 * Refused by the caller (TR_Packages_Page) whenever family_count() is
	 * non-zero — this method itself does not re-check, same as every other
	 * delete primitive in this codebase leaves its precondition to the
	 * caller. Archiving (status = inactive) is the recommended action for
	 * a package that already has families on it.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table() . " WHERE id = %d", [ $id ] ) );
	}

	public static function family_count( int $id ): int {
		return TR_Families::count( [ 'package_id' => $id ] );
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

	/**
	 * Used to warn on both the Programs screen (per row) and the Settings
	 * screen (a single summary notice) — an active program with no code of
	 * its own can only take online payments if the global default is set.
	 */
	public static function has_active_without_product_code(): bool {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table() . " WHERE status = %s AND (irembopay_product_code IS NULL OR irembopay_product_code = '')",
			[ 'active' ]
		) );

		return (int) $count > 0;
	}

	/**
	 * Used to warn on the Packages screen and flag packages for review in
	 * `wp tangnest status` (v0.9.3) — an active package without both of
	 * these can never be matched automatically when a family's child count
	 * changes. "0" is treated the same as NULL for child_count since
	 * $wpdb->prepare()'s %d placeholder coerces a null argument to 0 (the
	 * same quirk TR_Families::package_id already lives with) — 0 is never a
	 * legitimate child count, so the two are interchangeable here.
	 */
	public static function has_active_without_course_or_child_count(): bool {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table() . " WHERE status = %s AND (course IS NULL OR course = '' OR child_count IS NULL OR child_count = 0)",
			[ 'active' ]
		) );

		return (int) $count > 0;
	}

	/**
	 * Every active package at a given course + family-size tier, cheapest
	 * first (id as a tiebreak so two packages at the same price still sort
	 * deterministically) — used by find_matching_package() below to rank a
	 * package within its own group and within the group it's being matched
	 * into.
	 */
	public static function get_active_by_course_and_child_count( string $course, int $child_count ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE status = %s AND course = %s AND child_count = %d ORDER BY default_monthly_fee ASC, id ASC",
			[ 'active', $course, $child_count ]
		) );
	}

	/**
	 * Parses a free-text package name like "Applied Robotics & Coding – 2
	 * siblings" into its course and child count (v0.9.3). Course is
	 * whatever precedes the first dash (em, en, or plain hyphen); child
	 * count comes from "one child" (→ 1) or a leading digit before
	 * "sibling(s)". Either half that doesn't match is left null so the
	 * caller can flag the package for manual review rather than guess.
	 */
	public static function parse_name_into_course_and_child_count( string $name ): array {
		$course    = null;
		$remainder = $name;

		if ( preg_match( '/^(.*?)\s*[\x{2013}\x{2014}-]\s*(.*)$/u', $name, $matches ) ) {
			$course    = '' !== trim( $matches[1] ) ? trim( $matches[1] ) : null;
			$remainder = $matches[2];
		}

		$child_count = null;
		if ( preg_match( '/\bone\s+child\b/i', $remainder ) ) {
			$child_count = 1;
		} elseif ( preg_match( '/(\d+)\s*sibling/i', $remainder, $count_matches ) ) {
			$child_count = (int) $count_matches[1];
		}

		return [ 'course' => $course, 'child_count' => $child_count ];
	}

	/**
	 * Finds the package to pre-select when a family's active child count no
	 * longer matches $current_package's child_count (v0.9.3 — see
	 * TR_Family_Edit::render()). Where more than one active package shares
	 * the target course + child count (two price tiers), the one at the
	 * same rank-by-amount within its group as $current_package holds within
	 * its own group is preferred — a family on the higher of two 3-sibling
	 * tiers lands on the higher of two 2-sibling tiers. Returns null when no
	 * package exists for that course at that child count at all.
	 */
	public static function find_matching_package( object $current_package, int $target_child_count ): ?object {
		$current_group = self::get_active_by_course_and_child_count( (string) $current_package->course, (int) $current_package->child_count );

		$current_rank = 0;
		foreach ( $current_group as $index => $candidate ) {
			if ( (int) $candidate->id === (int) $current_package->id ) {
				$current_rank = $index + 1;
				break;
			}
		}
		if ( 0 === $current_rank ) {
			$current_rank = 1;
		}

		$target_group = self::get_active_by_course_and_child_count( (string) $current_package->course, $target_child_count );
		if ( empty( $target_group ) ) {
			return null;
		}
		if ( 1 === count( $target_group ) ) {
			return $target_group[0];
		}

		$index = min( $current_rank, count( $target_group ) ) - 1;
		return $target_group[ $index ];
	}
}
