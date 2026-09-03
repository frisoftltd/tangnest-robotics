<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_enrollments, plus the progress helpers used
 * by the Students admin screen and the seam increment_months_paid() leaves
 * for the v0.4.0 payment webhook.
 */
class TR_Enrollments {
	const STATUSES = [ 'active', 'completed', 'withdrawn' ];

	private static function table(): string {
		return TR_DB::table_enrollments();
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (student_id, program_id, enrolled_on, months_total, months_paid, status, created_at, updated_at)
			 VALUES (%d, %d, %s, %d, %d, %s, %s, %s)",
			[
				absint( $data['student_id'] ),
				absint( $data['program_id'] ),
				$data['enrolled_on'],
				absint( $data['months_total'] ),
				absint( $data['months_paid'] ?? 0 ),
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
			"UPDATE " . self::table() . " SET program_id = %d, enrolled_on = %s, months_total = %d, months_paid = %d, status = %s, updated_at = %s WHERE id = %d",
			[
				absint( $data['program_id'] ),
				$data['enrolled_on'],
				absint( $data['months_total'] ),
				absint( $data['months_paid'] ),
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
			'student_id' => 0,
			'program_id' => 0,
			'status'     => '',
			'orderby'    => 'enrolled_on',
			'order'      => 'DESC',
			'per_page'   => 20,
			'page'       => 1,
		] );

		$allowed_orderby = [ 'enrolled_on', 'status', 'created_at', 'id' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'enrolled_on';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

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

		if ( ! empty( $args['student_id'] ) ) {
			$where[]  = 'student_id = %d';
			$params[] = absint( $args['student_id'] );
		}

		if ( ! empty( $args['program_id'] ) ) {
			$where[]  = 'program_id = %d';
			$params[] = absint( $args['program_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	public static function get_by_student( int $student_id ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE student_id = %d ORDER BY enrolled_on DESC, id DESC",
			[ $student_id ]
		);

		return $wpdb->get_results( $sql );
	}

	public static function get_active_by_family( int $family_id ): array {
		global $wpdb;
		$students_table = TR_DB::table_students();

		$sql = $wpdb->prepare(
			"SELECT e.* FROM " . self::table() . " e
			 INNER JOIN {$students_table} s ON s.id = e.student_id
			 WHERE s.family_id = %d AND e.status = %s
			 ORDER BY e.enrolled_on DESC",
			[ $family_id, 'active' ]
		);

		return $wpdb->get_results( $sql );
	}

	public static function progress_label( object $enrollment ): string {
		if ( 'completed' === $enrollment->status ) {
			return __( 'Completed', 'tangnest-robotics' );
		}

		if ( 'withdrawn' === $enrollment->status ) {
			return __( 'Withdrawn', 'tangnest-robotics' );
		}

		$months_total   = (int) $enrollment->months_total;
		$current_month  = min( (int) $enrollment->months_paid + 1, max( $months_total, 1 ) );

		return sprintf(
			/* translators: 1: current month number, 2: total months */
			__( 'Month %1$d of %2$d', 'tangnest-robotics' ),
			$current_month,
			$months_total
		);
	}

	/**
	 * Not called by anything yet — v0.4.0 wires this to the payment webhook.
	 */
	public static function increment_months_paid( int $enrollment_id ): void {
		$enrollment = self::get( $enrollment_id );
		if ( null === $enrollment ) {
			return;
		}

		$months_paid = (int) $enrollment->months_paid + 1;
		$status      = $enrollment->status;

		if ( $months_paid >= (int) $enrollment->months_total ) {
			$status = 'completed';
		}

		self::update( $enrollment_id, [
			'program_id'   => (int) $enrollment->program_id,
			'enrolled_on'  => $enrollment->enrolled_on,
			'months_total' => (int) $enrollment->months_total,
			'months_paid'  => $months_paid,
			'status'       => $status,
		] );

		if ( 'completed' === $status ) {
			$student = TR_Students::get( (int) $enrollment->student_id );
			if ( null !== $student ) {
				TR_Families::flag_composition_change( (int) $student->family_id );
			}
		}
	}
}
