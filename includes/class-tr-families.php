<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin $wpdb wrapper over wp_tr_families, plus the billing-anchor and
 * composition-change-notice logic from spec §4.
 *
 * billing_day of 0 means "not yet anchored" — the column is NOT NULL so we
 * use 0 as the sentinel rather than allowing a real 1–28 value to be
 * ambiguous with "unset".
 *
 * v0.8.0: a family selects one package (wp_tr_programs, repurposed) and
 * monthly_amount becomes a snapshot of that package's price at save
 * time — callers (TR_Family_Edit) resolve the package and pass the
 * resulting figure in explicitly; this class never recalculates it on its
 * own, which is what stops a later package price change retroactively
 * altering an existing family's billing. Progress (months_paid) now lives
 * here too, one figure per family since every child in it finishes
 * together — see increment_months_paid() and progress_label().
 */
class TR_Families {
	const STATUSES        = [ 'active', 'inactive', 'completed' ];
	const REVIEW_OPTION    = 'tangnest_robotics_review_families';

	private static function table(): string {
		return TR_DB::table_families();
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (parent_user_id, monthly_amount, package_id, months_paid, currency, billing_day, status, notes, created_at, updated_at)
			 VALUES (%d, %s, %d, %d, %s, %d, %s, %s, %s, %s)",
			[
				absint( $data['parent_user_id'] ),
				number_format( (float) ( $data['monthly_amount'] ?? 0 ), 2, '.', '' ),
				! empty( $data['package_id'] ) ? absint( $data['package_id'] ) : null,
				absint( $data['months_paid'] ?? 0 ),
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
			"UPDATE " . self::table() . " SET monthly_amount = %s, package_id = %d, months_paid = %d, currency = %s, billing_day = %d, status = %s, notes = %s, updated_at = %s WHERE id = %d",
			[
				number_format( (float) ( $data['monthly_amount'] ?? 0 ), 2, '.', '' ),
				! empty( $data['package_id'] ) ? absint( $data['package_id'] ) : null,
				absint( $data['months_paid'] ?? 0 ),
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

	/**
	 * The one place a payment (webhook or admin "Record payment") advances
	 * a family — exactly once per payment, regardless of how many children
	 * are on the package, since they all finish together. Marks the family
	 * 'completed' and raises the existing composition-review notice once
	 * months_paid reaches the package's duration.
	 */
	public static function increment_months_paid( int $family_id ): void {
		global $wpdb;

		$family = self::get( $family_id );
		if ( null === $family ) {
			return;
		}

		$months_paid = (int) $family->months_paid + 1;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET months_paid = %d, updated_at = %s WHERE id = %d",
			[ $months_paid, current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );

		$package = ! empty( $family->package_id ) ? TR_Programs::get( (int) $family->package_id ) : null;

		if ( $package && $months_paid >= (int) $package->duration_months && 'completed' !== $family->status ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE " . self::table() . " SET status = %s, updated_at = %s WHERE id = %d",
				[ 'completed', current_time( 'mysql' ), $family_id ]
			) );
			self::flag_composition_change( $family_id );
		}
	}

	/**
	 * Mirrors the old per-enrollment version, but reads the family's own
	 * months_paid against its package's duration instead of an enrollment
	 * row — every child on the family shows this same figure.
	 */
	public static function progress_label( object $family ): string {
		if ( 'completed' === $family->status ) {
			return __( 'Completed', 'tangnest-robotics' );
		}

		if ( empty( $family->package_id ) ) {
			return __( 'No package', 'tangnest-robotics' );
		}

		$package      = TR_Programs::get( (int) $family->package_id );
		$months_total = $package ? (int) $package->duration_months : 0;
		$current      = min( (int) $family->months_paid + 1, max( $months_total, 1 ) );

		return sprintf(
			/* translators: 1: current month number, 2: total months */
			__( 'Month %1$d of %2$d', 'tangnest-robotics' ),
			$current,
			$months_total
		);
	}

	/**
	 * Deletes the family and every record that only makes sense attached
	 * to it — children, their (historical) enrollments, and any invoice
	 * that isn't 'paid'. The caller (TR_Admin_Menu) has already refused
	 * this when a paid invoice exists; this method does not re-check, same
	 * as the invoice/program delete primitives leave their own
	 * preconditions to the caller. Never touches the parent's WordPress
	 * user account — that belongs to the LMS too.
	 *
	 * @return int The number of children that were deleted, for the log line.
	 */
	public static function delete_with_dependents( int $family_id ): int {
		global $wpdb;

		$child_count = TR_Students::count( [ 'family_id' => $family_id ] );

		$wpdb->query( 'START TRANSACTION' );
		TR_Enrollments::delete_by_family( $family_id );
		TR_Students::delete_by_family( $family_id );
		TR_Invoices::delete_non_paid_by_family( $family_id );
		self::delete( $family_id );
		$wpdb->query( 'COMMIT' );

		return $child_count;
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table() . " WHERE id = %d", [ $id ] ) );
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

		if ( ! empty( $args['package_id'] ) ) {
			$where[]  = 'package_id = %d';
			$params[] = absint( $args['package_id'] );
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

	/**
	 * Overwrites this family's access token, which invalidates any
	 * previously issued link for it immediately (the old hash no longer
	 * matches anything once replaced).
	 */
	public static function set_access_token( int $family_id, string $hash, string $created, string $expires ): void {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET access_token_hash = %s, access_token_created = %s, access_token_first_used = NULL, access_token_last_used = NULL, access_token_use_count = 0, access_token_status = %s, access_token_expires = %s, updated_at = %s WHERE id = %d",
			[ $hash, $created, 'unused', $expires, current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );
	}

	public static function get_by_access_token_hash( string $hash ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE access_token_hash = %s", [ $hash ] ) );

		return $row ?: null;
	}

	public static function record_token_use( int $family_id, string $first_used, string $last_used, int $use_count, string $status ): void {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET access_token_first_used = %s, access_token_last_used = %s, access_token_use_count = %d, access_token_status = %s, updated_at = %s WHERE id = %d",
			[ $first_used, $last_used, $use_count, $status, current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );
	}

	public static function set_token_status( int $family_id, string $status ): void {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET access_token_status = %s, updated_at = %s WHERE id = %d",
			[ $status, current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );
	}

	/**
	 * Clears both token slots — the access token (marked 'revoked', same as
	 * before) and the message token (fully nulled, since it has no status
	 * column of its own to flag). A parent stuck after a revoke must have
	 * neither link keep working.
	 */
	public static function revoke_access_token( int $family_id ): void {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET access_token_hash = NULL, access_token_status = %s,
			 message_token_hash = NULL, message_token_created = NULL, message_token_expires = NULL,
			 message_token_last_used = NULL, message_token_use_count = 0, updated_at = %s WHERE id = %d",
			[ 'revoked', current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );
	}

	/**
	 * Overwrites this family's message token, invalidating any previous one
	 * immediately — deliberate on every automatic send, see TR_Message_Tokens.
	 */
	public static function set_message_token( int $family_id, string $hash, string $created, string $expires ): void {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET message_token_hash = %s, message_token_created = %s, message_token_expires = %s, message_token_last_used = NULL, message_token_use_count = 0, updated_at = %s WHERE id = %d",
			[ $hash, $created, $expires, current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );
	}

	public static function get_by_message_token_hash( string $hash ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE message_token_hash = %s", [ $hash ] ) );

		return $row ?: null;
	}

	public static function record_message_token_use( int $family_id, string $last_used, int $use_count ): void {
		global $wpdb;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET message_token_last_used = %s, message_token_use_count = %d, updated_at = %s WHERE id = %d",
			[ $last_used, $use_count, current_time( 'mysql' ), $family_id ]
		);
		$wpdb->query( $sql );
	}

	/**
	 * Sweeps any 'active' token whose grace window has passed to
	 * 'consumed'. Called opportunistically from dashboard load — request-time
	 * validation in TR_Access_Tokens enforces the window regardless, this
	 * just keeps stale rows from lingering in the admin status column.
	 */
	public static function expire_stale_active_tokens( int $grace_minutes ): int {
		global $wpdb;

		$cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $grace_minutes * MINUTE_IN_SECONDS );

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET access_token_status = %s, updated_at = %s WHERE access_token_status = %s AND access_token_first_used IS NOT NULL AND access_token_first_used < %s",
			[ 'consumed', current_time( 'mysql' ), 'active', $cutoff ]
		);
		$wpdb->query( $sql );

		return (int) $wpdb->rows_affected;
	}
}
