<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_programs — the packages table (v0.8.0).
 * Each row is a course and tier carrying a price table by child count
 * (v0.10.0: price_1_child / price_2_children / price_3_children /
 * price_additional_child), not a single fixed fee for one family size —
 * IremboPay was confirmed not to enforce the registered product amount
 * (`wp tangnest test-product-amount`, v0.9.4), so a package now covers
 * any number of children and pricing lives entirely here, in WordPress.
 * duration_months is only a default for a new family (v0.10.0 — a
 * family's own programme dates are authoritative) and irembopay_
 * product_code is the real, required code for an active package.
 * default_monthly_fee is legacy (pre-v0.10.0) — kept in the schema, no
 * longer read for billing. course and child_count are labels only
 * (v0.10.0 retires the v0.9.3 package-matching they used to drive); a
 * family selects one package and TR_Families::calculate_amount()
 * resolves the live price for its active child count, snapshotting the
 * result into monthly_amount — a later price change here never
 * retroactively alters an existing family's billing.
 */
class TR_Programs {
	const STATUSES = [ 'active', 'inactive' ];

	/**
	 * child_count 1-3 to its own price column — 4+ is price_3_children
	 * plus price_additional_child per extra child beyond 3, which has no
	 * column of its own (see TR_Families::price_for_child_count()).
	 */
	const PRICE_COLUMN_BY_COUNT = [
		1 => 'price_1_child',
		2 => 'price_2_children',
		3 => 'price_3_children',
	];

	private static function table(): string {
		return TR_DB::table_programs();
	}

	/**
	 * $wpdb->prepare() turns a null against a %s placeholder into an empty
	 * string, not SQL NULL, which a DECIMAL column then rejects or mangles
	 * — same issue TR_Families::date_fragment() exists to avoid for dates.
	 * A price column left blank on the form must land as a real NULL
	 * ("no price set for this count"), never as 0.00 ("free").
	 */
	private static function price_fragment( ?string $value, array &$args ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$args[] = $value;
		return '%s';
	}

	private static function sanitize_price( $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		return number_format( max( 0, (float) $value ), 2, '.', '' );
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$args = [
			sanitize_text_field( $data['name'] ),
			absint( $data['duration_months'] ?? 0 ),
			number_format( (float) ( $data['default_monthly_fee'] ?? 0 ), 2, '.', '' ),
			( $data['irembopay_product_code'] ?? '' ) !== '' ? sanitize_text_field( $data['irembopay_product_code'] ) : null,
			( $data['course'] ?? '' ) !== '' ? sanitize_text_field( $data['course'] ) : null,
			! empty( $data['child_count'] ) ? absint( $data['child_count'] ) : null,
		];

		$price_1_sql = self::price_fragment( self::sanitize_price( $data['price_1_child'] ?? null ), $args );
		$price_2_sql = self::price_fragment( self::sanitize_price( $data['price_2_children'] ?? null ), $args );
		$price_3_sql = self::price_fragment( self::sanitize_price( $data['price_3_children'] ?? null ), $args );
		$price_4_sql = self::price_fragment( self::sanitize_price( $data['price_additional_child'] ?? null ), $args );

		$args[] = ( $data['start_date'] ?? '' ) !== '' ? $data['start_date'] : null;
		$args[] = ( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null;
		$args[] = in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active';
		$args[] = $now;
		$args[] = $now;

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (name, duration_months, default_monthly_fee, irembopay_product_code, course, child_count, price_1_child, price_2_children, price_3_children, price_additional_child, start_date, notes, status, created_at, updated_at)
			 VALUES (%s, %d, %s, %s, %s, %d, {$price_1_sql}, {$price_2_sql}, {$price_3_sql}, {$price_4_sql}, %s, %s, %s, %s, %s)",
			$args
		);
		$wpdb->query( $sql );

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		$args = [
			sanitize_text_field( $data['name'] ),
			absint( $data['duration_months'] ?? 0 ),
			number_format( (float) ( $data['default_monthly_fee'] ?? 0 ), 2, '.', '' ),
			( $data['irembopay_product_code'] ?? '' ) !== '' ? sanitize_text_field( $data['irembopay_product_code'] ) : null,
			( $data['course'] ?? '' ) !== '' ? sanitize_text_field( $data['course'] ) : null,
			! empty( $data['child_count'] ) ? absint( $data['child_count'] ) : null,
		];

		$price_1_sql = self::price_fragment( self::sanitize_price( $data['price_1_child'] ?? null ), $args );
		$price_2_sql = self::price_fragment( self::sanitize_price( $data['price_2_children'] ?? null ), $args );
		$price_3_sql = self::price_fragment( self::sanitize_price( $data['price_3_children'] ?? null ), $args );
		$price_4_sql = self::price_fragment( self::sanitize_price( $data['price_additional_child'] ?? null ), $args );

		$args[] = ( $data['start_date'] ?? '' ) !== '' ? $data['start_date'] : null;
		$args[] = ( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null;
		$args[] = in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active';
		$args[] = $now;
		$args[] = $id;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET name = %s, duration_months = %d, default_monthly_fee = %s, irembopay_product_code = %s, course = %s, child_count = %d,
			 price_1_child = {$price_1_sql}, price_2_children = {$price_2_sql}, price_3_children = {$price_3_sql}, price_additional_child = {$price_4_sql},
			 start_date = %s, notes = %s, status = %s, updated_at = %s WHERE id = %d",
			$args
		);

		return false !== $wpdb->query( $sql );
	}

	public static function has_duration( object $package ): bool {
		return (int) $package->duration_months > 0;
	}

	/**
	 * The price for 1, 2 or 3 children, straight from the matching column —
	 * NULL when that slot was never filled in. 4+ is handled by the caller
	 * (TR_Families::price_for_child_count()), which also needs
	 * price_additional_child and the 3-child base together.
	 */
	public static function price_for_count( object $package, int $child_count ): ?float {
		if ( $child_count < 1 || $child_count > 3 ) {
			return null;
		}

		$column = self::PRICE_COLUMN_BY_COUNT[ $child_count ];
		return null !== $package->$column ? (float) $package->$column : null;
	}

	/**
	 * Which of the four price slots are empty, in a form fit to print —
	 * "1 child", "2 children", "3 children", "each additional child".
	 * Used by the Packages screen, `wp tangnest status`'s "incomplete
	 * pricing" section, and the v0.10.0 migration's own log line.
	 */
	public static function missing_price_labels( object $package ): array {
		$missing = [];

		if ( null === $package->price_1_child ) {
			$missing[] = __( '1 child', 'tangnest-robotics' );
		}
		if ( null === $package->price_2_children ) {
			$missing[] = __( '2 children', 'tangnest-robotics' );
		}
		if ( null === $package->price_3_children ) {
			$missing[] = __( '3 children', 'tangnest-robotics' );
		}
		if ( null === $package->price_additional_child ) {
			$missing[] = __( 'each additional child', 'tangnest-robotics' );
		}

		return $missing;
	}

	/**
	 * "65,000 / 120,000 / 155,000 / +30,000" — the compact price-table
	 * column on the Packages list. A missing slot shows as "—" rather than
	 * being skipped, so the position (which count it's for) is never
	 * ambiguous.
	 */
	public static function price_table_summary( object $package ): string {
		$fmt = static function ( $value ) {
			return null !== $value ? number_format( (float) $value, 0 ) : '—';
		};

		return sprintf(
			'%s / %s / %s / +%s',
			$fmt( $package->price_1_child ),
			$fmt( $package->price_2_children ),
			$fmt( $package->price_3_children ),
			$fmt( $package->price_additional_child )
		);
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
}
