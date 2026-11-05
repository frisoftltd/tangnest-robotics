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

	/**
	 * NULL, '' and the '0000-00-00' zero-date artifact (what a bare %s
	 * placeholder silently turns a PHP null into — see normalize_date())
	 * all mean "no date set". Used both to sanitize what gets written and,
	 * by TR_Invoice_Generator, to decide what gets read as unset.
	 */
	public static function is_date_set( $value ): bool {
		return null !== $value && '' !== $value && '0000-00-00' !== $value;
	}

	private static function normalize_date( $value ): ?string {
		return self::is_date_set( $value ) ? $value : null;
	}

	/**
	 * $wpdb->prepare() turns a null passed against a %s placeholder into an
	 * empty string, not SQL NULL — which a DATE column then silently stores
	 * as '0000-00-00'. Emitting the literal NULL keyword for an unset date,
	 * instead of routing it through a %s placeholder at all, is what
	 * actually gets a real NULL into the column.
	 */
	private static function date_fragment( ?string $value, array &$args ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$args[] = $value;
		return '%s';
	}

	/**
	 * Same NULL-vs-empty-string problem as dates (see date_fragment()) —
	 * a blank negotiated_amount must land as a real NULL ("no negotiated
	 * rate"), never 0.00 ("negotiated to be free").
	 */
	private static function amount_fragment( $value, array &$args ): string {
		if ( null === $value || '' === $value ) {
			return 'NULL';
		}
		$args[] = number_format( (float) $value, 2, '.', '' );
		return '%s';
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$program_start_date = self::normalize_date( $data['program_start_date'] ?? null );
		$program_end_date   = self::normalize_date( $data['program_end_date'] ?? null );

		$args = [
			absint( $data['parent_user_id'] ),
			number_format( (float) ( $data['monthly_amount'] ?? 0 ), 2, '.', '' ),
			! empty( $data['package_id'] ) ? absint( $data['package_id'] ) : null,
			absint( $data['months_paid'] ?? 0 ),
			$data['currency'] ?? 'RWF',
			absint( $data['billing_day'] ?? 0 ),
		];

		$start_sql = self::date_fragment( $program_start_date, $args );
		$end_sql   = self::date_fragment( $program_end_date, $args );

		$negotiated_sql = self::amount_fragment( $data['negotiated_amount'] ?? null, $args );
		$args[] = ! empty( $data['negotiated_for_child_count'] ) ? absint( $data['negotiated_for_child_count'] ) : null;
		$args[] = ( $data['negotiated_note'] ?? '' ) !== '' ? sanitize_textarea_field( $data['negotiated_note'] ) : null;

		$args[] = in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active';
		$args[] = ( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null;
		$args[] = $now;
		$args[] = $now;

		$sql = $wpdb->prepare(
			"INSERT INTO " . self::table() . " (parent_user_id, monthly_amount, package_id, months_paid, currency, billing_day, program_start_date, program_end_date, negotiated_amount, negotiated_for_child_count, negotiated_note, status, notes, created_at, updated_at)
			 VALUES (%d, %s, %d, %d, %s, %d, {$start_sql}, {$end_sql}, {$negotiated_sql}, %d, %s, %s, %s, %s, %s)",
			$args
		);
		$wpdb->query( $sql );

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );

		$program_start_date = self::normalize_date( $data['program_start_date'] ?? null );
		$program_end_date   = self::normalize_date( $data['program_end_date'] ?? null );

		$args = [
			number_format( (float) ( $data['monthly_amount'] ?? 0 ), 2, '.', '' ),
			! empty( $data['package_id'] ) ? absint( $data['package_id'] ) : null,
			absint( $data['months_paid'] ?? 0 ),
			$data['currency'] ?? 'RWF',
			absint( $data['billing_day'] ?? 0 ),
		];

		$start_sql = self::date_fragment( $program_start_date, $args );
		$end_sql   = self::date_fragment( $program_end_date, $args );

		$negotiated_sql = self::amount_fragment( $data['negotiated_amount'] ?? null, $args );
		$args[] = ! empty( $data['negotiated_for_child_count'] ) ? absint( $data['negotiated_for_child_count'] ) : null;
		$args[] = ( $data['negotiated_note'] ?? '' ) !== '' ? sanitize_textarea_field( $data['negotiated_note'] ) : null;

		$args[] = in_array( $data['status'] ?? 'active', self::STATUSES, true ) ? $data['status'] : 'active';
		$args[] = ( $data['notes'] ?? '' ) !== '' ? sanitize_textarea_field( $data['notes'] ) : null;
		$args[] = $now;
		$args[] = $id;

		$sql = $wpdb->prepare(
			"UPDATE " . self::table() . " SET monthly_amount = %s, package_id = %d, months_paid = %d, currency = %s, billing_day = %d, program_start_date = {$start_sql}, program_end_date = {$end_sql},
			 negotiated_amount = {$negotiated_sql}, negotiated_for_child_count = %d, negotiated_note = %s, status = %s, notes = %s, updated_at = %s WHERE id = %d",
			$args
		);

		return false !== $wpdb->query( $sql );
	}

	/**
	 * Writes only monthly_amount — the targeted update used by
	 * recompute_and_save_amount() so a recalculation never has to
	 * re-specify every other column the way update() does.
	 */
	private static function set_monthly_amount( int $id, float $amount ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table() . " SET monthly_amount = %s, updated_at = %s WHERE id = %d",
			[ number_format( $amount, 2, '.', '' ), current_time( 'mysql' ), $id ]
		) );
	}

	/**
	 * How many children a family has right now — the single figure every
	 * price lookup (calculate_amount(), price_for_child_count()) and every
	 * staleness check (is_negotiated_stale()) is driven by.
	 */
	public static function active_child_count( int $family_id ): int {
		return TR_Students::count( [ 'family_id' => $family_id, 'status' => 'active' ] );
	}

	/**
	 * The package's price table, resolved for one child count (v0.10.0).
	 * 1–3 children read straight off their own column; 4+ is
	 * price_3_children plus price_additional_child per child beyond 3.
	 * NULL whenever the slot actually needed is empty — this never caps
	 * silently at the highest price that happens to exist, and it never
	 * guesses at a count the admin hasn't priced yet.
	 */
	public static function price_for_child_count( int $package_id, int $child_count ): ?float {
		$package = TR_Programs::get( $package_id );
		if ( null === $package || $child_count < 1 ) {
			return null;
		}

		if ( $child_count <= 3 ) {
			return TR_Programs::price_for_count( $package, $child_count );
		}

		$base       = TR_Programs::price_for_count( $package, 3 );
		$additional = null !== $package->price_additional_child ? (float) $package->price_additional_child : null;

		if ( null === $base || null === $additional ) {
			return null;
		}

		return $base + ( $child_count - 3 ) * $additional;
	}

	/**
	 * True once a negotiated amount no longer matches the family's current
	 * active child count — the moment it was agreed for has passed, and
	 * calculate_amount() must refuse to apply it (never silently falling
	 * back to the table either) until an admin explicitly resolves it.
	 */
	public static function is_negotiated_stale( object $family ): bool {
		if ( null === $family->negotiated_amount ) {
			return false;
		}

		return (int) $family->negotiated_for_child_count !== self::active_child_count( (int) $family->id );
	}

	/**
	 * The amount a family should be billed right now: the negotiated rate
	 * while it still matches the active child count, otherwise the
	 * package's price table for that count. Returns NULL — never a guess,
	 * never zero — when a negotiated rate has gone stale or the table has
	 * no price for the count needed; see pricing_issue() for which one and
	 * why, and recompute_and_save_amount() for the caller that actually
	 * decides what to do about it.
	 */
	public static function calculate_amount( int $family_id ): ?float {
		$family = self::get( $family_id );
		if ( null === $family || empty( $family->package_id ) ) {
			return null;
		}

		if ( null !== $family->negotiated_amount ) {
			if ( self::is_negotiated_stale( $family ) ) {
				return null;
			}
			return (float) $family->negotiated_amount;
		}

		return self::price_for_child_count( (int) $family->package_id, self::active_child_count( $family_id ) );
	}

	/**
	 * Why calculate_amount() can't resolve a figure for this family right
	 * now, structured for both the hard-block on save (TR_Family_Edit) and
	 * the review lists (`wp tangnest status`, the Families screen badge).
	 * NULL means there is nothing to resolve — the family prices cleanly.
	 */
	public static function pricing_issue( object $family ): ?array {
		if ( empty( $family->package_id ) ) {
			return null;
		}

		$child_count = self::active_child_count( (int) $family->id );

		if ( null !== $family->negotiated_amount && (int) $family->negotiated_for_child_count !== $child_count ) {
			return [
				'type'                       => 'stale_negotiated',
				'child_count'                => $child_count,
				'negotiated_amount'          => (float) $family->negotiated_amount,
				'negotiated_for_child_count' => (int) $family->negotiated_for_child_count,
				'standard_price'             => self::price_for_child_count( (int) $family->package_id, $child_count ),
			];
		}

		if ( null === $family->negotiated_amount && null === self::price_for_child_count( (int) $family->package_id, $child_count ) ) {
			return [ 'type' => 'missing_price', 'child_count' => $child_count ];
		}

		return null;
	}

	/**
	 * Renders pricing_issue()'s structured result as the exact admin-facing
	 * copy from spec — one call site for both the family-form hard-block
	 * error and the review-list wording, so the two can never drift apart.
	 */
	public static function pricing_issue_message( array $issue ): string {
		$children_word = static function ( int $n ): string {
			return 1 === $n ? __( 'child', 'tangnest-robotics' ) : __( 'children', 'tangnest-robotics' );
		};

		if ( 'missing_price' === $issue['type'] ) {
			return sprintf(
				/* translators: 1: child count, 2: "child" or "children" */
				__( 'This package has no price set for %1$d %2$s. Add it on the Packages screen first.', 'tangnest-robotics' ),
				$issue['child_count'],
				$children_word( $issue['child_count'] )
			);
		}

		if ( null !== $issue['standard_price'] ) {
			return sprintf(
				/* translators: 1: negotiated amount, 2: child count it was agreed for, 3/5: "child"/"children", 4: current child count, 6: standard price */
				__( 'This family has a negotiated rate of %1$s RWF agreed for %2$d %3$s. They now have %4$d %5$s. Enter a new agreed amount, or clear the negotiated rate to use the standard price of %6$s RWF.', 'tangnest-robotics' ),
				number_format( $issue['negotiated_amount'], 0 ),
				$issue['negotiated_for_child_count'],
				$children_word( $issue['negotiated_for_child_count'] ),
				$issue['child_count'],
				$children_word( $issue['child_count'] ),
				number_format( $issue['standard_price'], 0 )
			);
		}

		return sprintf(
			/* translators: 1: negotiated amount, 2: child count it was agreed for, 3: current child count */
			__( 'This family has a negotiated rate of %1$s RWF agreed for %2$d children. They now have %3$d, and this package has no standard price set for that count either. Enter a new agreed amount, or set a price on the Packages screen.', 'tangnest-robotics' ),
			number_format( $issue['negotiated_amount'], 0 ),
			$issue['negotiated_for_child_count'],
			$issue['child_count']
		);
	}

	/**
	 * Recomputes monthly_amount from scratch and writes it when it
	 * actually changes — the one place a child being added, deactivated
	 * or reactivated (or any other active-count change outside the family
	 * form's own save) is allowed to move the figure. Writes nothing and
	 * reports 'blocked' when pricing_issue() finds a reason it can't —
	 * neither the old nor any guessed new amount is ever persisted in that
	 * case; an admin has to resolve it explicitly on the family form.
	 */
	public static function recompute_and_save_amount( int $family_id, ?int $recorded_by = null ): array {
		$family = self::get( $family_id );
		if ( null === $family ) {
			return [ 'status' => 'unchanged', 'old_amount' => 0.0, 'new_amount' => null, 'child_count' => 0, 'issue' => null ];
		}

		$old_amount  = (float) $family->monthly_amount;
		$child_count = self::active_child_count( $family_id );

		if ( empty( $family->package_id ) ) {
			return [ 'status' => 'unchanged', 'old_amount' => $old_amount, 'new_amount' => null, 'child_count' => $child_count, 'issue' => null ];
		}

		$issue = self::pricing_issue( $family );
		if ( null !== $issue ) {
			return [ 'status' => 'blocked', 'old_amount' => $old_amount, 'new_amount' => null, 'child_count' => $child_count, 'issue' => $issue ];
		}

		$new_amount = self::calculate_amount( $family_id );
		if ( null === $new_amount ) {
			// pricing_issue() already found nothing wrong — should not
			// happen, but never persist a guess if it somehow does.
			return [ 'status' => 'blocked', 'old_amount' => $old_amount, 'new_amount' => null, 'child_count' => $child_count, 'issue' => [ 'type' => 'missing_price', 'child_count' => $child_count ] ];
		}

		if ( abs( $new_amount - $old_amount ) < 0.005 ) {
			return [ 'status' => 'unchanged', 'old_amount' => $old_amount, 'new_amount' => $new_amount, 'child_count' => $child_count, 'issue' => null ];
		}

		self::set_monthly_amount( $family_id, $new_amount );

		TR_Logger::info( 'Family monthly amount recalculated', [
			'family_id'   => $family_id,
			'old_amount'  => $old_amount,
			'new_amount'  => $new_amount,
			'child_count' => $child_count,
		] );

		TR_Family_Events::record(
			$family_id,
			'amount_changed',
			sprintf(
				/* translators: 1: old amount, 2: new amount, 3: child count, 4: "child" or "children" */
				__( 'Monthly amount changed from %1$s to %2$s RWF (%3$d %4$s)', 'tangnest-robotics' ),
				number_format( $old_amount, 0 ),
				number_format( $new_amount, 0 ),
				$child_count,
				1 === $child_count ? __( 'child', 'tangnest-robotics' ) : __( 'children', 'tangnest-robotics' )
			),
			number_format( $old_amount, 2, '.', '' ),
			number_format( $new_amount, 2, '.', '' ),
			$recorded_by
		);

		return [ 'status' => 'updated', 'old_amount' => $old_amount, 'new_amount' => $new_amount, 'child_count' => $child_count, 'issue' => null ];
	}

	/**
	 * Whole months between the family's own programme start and end dates
	 * (v0.10.0 — duration moves off the package). Falls back to the
	 * package's duration_months only when the family hasn't got both dates
	 * set itself, and only when the package actually has one (duration is
	 * now optional there too). NULL — never a guess — when neither is
	 * available; callers show "Month 3" with no denominator in that case.
	 */
	public static function total_months( int $family_id ): ?int {
		$family = self::get( $family_id );
		if ( null === $family ) {
			return null;
		}

		if ( self::is_date_set( $family->program_start_date ) && self::is_date_set( $family->program_end_date ) ) {
			$start = new DateTime( $family->program_start_date );
			$end   = new DateTime( $family->program_end_date );

			if ( $end < $start ) {
				return null;
			}

			$diff   = $start->diff( $end );
			$months = $diff->y * 12 + $diff->m;
			if ( $diff->d > 0 ) {
				$months++;
			}

			return max( 1, $months );
		}

		$package = ! empty( $family->package_id ) ? TR_Programs::get( (int) $family->package_id ) : null;
		if ( $package && TR_Programs::has_duration( $package ) ) {
			return (int) $package->duration_months;
		}

		return null;
	}

	/**
	 * The one place a payment (webhook or admin "Record payment") advances
	 * a family — exactly once per payment, regardless of how many children
	 * are on the package, since they all finish together. Marks the family
	 * 'completed' and raises the existing composition-review notice once
	 * months_paid reaches the family's own total (v0.10.0 — duration is
	 * per-family now, not read off the package). A family with no known
	 * total (no package duration to fall back on, and no programme dates
	 * of its own) never auto-completes — there's nothing to compare
	 * months_paid against, so it's left for an admin to mark directly.
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

		$total_months = self::total_months( $family_id );

		if ( null !== $total_months && $months_paid >= $total_months && 'completed' !== $family->status ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE " . self::table() . " SET status = %s, updated_at = %s WHERE id = %d",
				[ 'completed', current_time( 'mysql' ), $family_id ]
			) );
			self::flag_composition_change( $family_id );
		}
	}

	/**
	 * Reads the family's own total_months() (v0.10.0 — per-family
	 * duration) rather than the package's — every child on the family
	 * shows this same figure. "Month 3" with no denominator when the
	 * total isn't known at all.
	 */
	public static function progress_label( object $family ): string {
		if ( 'completed' === $family->status ) {
			return __( 'Completed', 'tangnest-robotics' );
		}

		if ( empty( $family->package_id ) ) {
			return __( 'No package', 'tangnest-robotics' );
		}

		$months_total = self::total_months( (int) $family->id );
		$current      = null !== $months_total
			? min( (int) $family->months_paid + 1, max( $months_total, 1 ) )
			: (int) $family->months_paid + 1;

		if ( null === $months_total ) {
			return sprintf(
				/* translators: %d: current month number */
				__( 'Month %d', 'tangnest-robotics' ),
				$current
			);
		}

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
