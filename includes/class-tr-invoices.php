<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_invoices. The unique (family_id, period)
 * key on the table is the real safety net against double-billing — this
 * class never works around it with a "check first" query, it just lets a
 * duplicate insert() fail.
 */
class TR_Invoices {
	const STATUSES = [ 'pending', 'paid', 'overdue', 'cancelled', 'waived' ];

	private static function table(): string {
		return TR_DB::table_invoices();
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$snapshot = $data['student_snapshot'] ?? null;
		if ( is_array( $snapshot ) ) {
			$snapshot = wp_json_encode( $snapshot );
		}

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (family_id, period, amount, currency, status, due_date, issued_at, student_snapshot, created_at, updated_at)
			 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
			[
				absint( $data['family_id'] ),
				$data['period'],
				number_format( (float) ( $data['amount'] ?? 0 ), 2, '.', '' ),
				$data['currency'] ?? 'RWF',
				in_array( $data['status'] ?? 'pending', self::STATUSES, true ) ? $data['status'] : 'pending',
				$data['due_date'],
				$data['issued_at'] ?? $now,
				( $snapshot ?? '' ) !== '' ? $snapshot : null,
				$now,
				$now,
			]
		);

		$result = $wpdb->query( $sql );

		// A duplicate (family_id, period) fails the unique key and $wpdb->query()
		// returns false — insert_id is not reliable in that case, so report 0.
		return $result ? (int) $wpdb->insert_id : 0;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = %s, due_date = %s, paid_at = %s, payment_method = %s, payment_reference = %s, recorded_by = %d, waive_reason = %s, updated_at = %s WHERE id = %d",
			[
				in_array( $data['status'] ?? 'pending', self::STATUSES, true ) ? $data['status'] : 'pending',
				$data['due_date'],
				( $data['paid_at'] ?? '' ) !== '' ? $data['paid_at'] : null,
				( $data['payment_method'] ?? '' ) !== '' ? sanitize_text_field( $data['payment_method'] ) : null,
				( $data['payment_reference'] ?? '' ) !== '' ? sanitize_text_field( $data['payment_reference'] ) : null,
				! empty( $data['recorded_by'] ) ? absint( $data['recorded_by'] ) : 0,
				( $data['waive_reason'] ?? '' ) !== '' ? sanitize_textarea_field( $data['waive_reason'] ) : null,
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
			'period'    => '',
			'orderby'   => 'due_date',
			'order'     => 'DESC',
			'per_page'  => 20,
			'page'      => 1,
		] );

		$allowed_orderby = [ 'due_date', 'period', 'amount', 'status', 'issued_at', 'paid_at', 'id' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'due_date';
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

		if ( ! empty( $args['family_id'] ) ) {
			$where[]  = 'family_id = %d';
			$params[] = absint( $args['family_id'] );
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['period'] ) ) {
			$where[]  = 'period = %s';
			$params[] = $args['period'];
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	public static function get_by_family( int $family_id, string $status = 'any' ): array {
		global $wpdb;

		$where  = [ 'family_id = %d' ];
		$params = [ $family_id ];

		if ( 'any' !== $status && in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$sql = "SELECT * FROM " . self::table() . " WHERE " . implode( ' AND ', $where ) . " ORDER BY period DESC";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function get_for_period( int $family_id, string $period ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE family_id = %d AND period = %s",
			[ $family_id, $period ]
		) );

		return $row ?: null;
	}

	public static function get_outstanding(): array {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE status IN (%s, %s) ORDER BY due_date ASC",
			[ 'pending', 'overdue' ]
		);

		return $wpdb->get_results( $sql );
	}

	public static function last_payment_for_family( int $family_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE family_id = %d AND status = %s ORDER BY paid_at DESC LIMIT 1",
			[ $family_id, 'paid' ]
		) );

		return $row ?: null;
	}

	/**
	 * Idempotent: calling this on an invoice that is already 'paid' changes
	 * nothing and still returns true. Matters for admin double-clicks now,
	 * and for a webhook that fires twice later.
	 */
	public static function mark_paid( int $id, array $payment ): bool {
		global $wpdb;

		$invoice = self::get( $id );
		if ( null === $invoice ) {
			return false;
		}

		if ( 'paid' === $invoice->status ) {
			return true;
		}

		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = %s, paid_at = %s, payment_method = %s, payment_reference = %s, recorded_by = %d, updated_at = %s WHERE id = %d",
			[
				'paid',
				( $payment['paid_at'] ?? '' ) !== '' ? $payment['paid_at'] : $now,
				( $payment['payment_method'] ?? '' ) !== '' ? sanitize_text_field( $payment['payment_method'] ) : null,
				( $payment['payment_reference'] ?? '' ) !== '' ? sanitize_text_field( $payment['payment_reference'] ) : null,
				absint( $payment['recorded_by'] ?? 0 ),
				$now,
				$id,
			]
		);

		return false !== $wpdb->query( $sql );
	}

	/**
	 * Waive or cancel — both are status flips only, never a row delete, and
	 * never touch months_paid (that only happens on an actual payment).
	 */
	public static function set_status( int $id, string $status, ?string $waive_reason = null ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = %s, waive_reason = %s, updated_at = %s WHERE id = %d",
			[ $status, ( $waive_reason ?? '' ) !== '' ? sanitize_textarea_field( $waive_reason ) : null, current_time( 'mysql' ), $id ]
		);

		return false !== $wpdb->query( $sql );
	}

	public static function mark_overdue_due_before( string $date ): int {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = %s, updated_at = %s WHERE status = %s AND due_date < %s",
			[ 'overdue', current_time( 'mysql' ), 'pending', $date ]
		);
		$wpdb->query( $sql );

		return (int) $wpdb->rows_affected;
	}

	public static function family_balance( int $family_id ): float {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM " . self::table() . " WHERE family_id = %d AND status IN (%s, %s)",
			[ $family_id, 'pending', 'overdue' ]
		);

		return (float) $wpdb->get_var( $sql );
	}
}
