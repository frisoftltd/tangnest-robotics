<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TR_DB {
	const DB_VERSION_OPTION = 'tangnest_robotics_db_version';

	public static function table_families(): string {
		global $wpdb;
		return $wpdb->prefix . 'tr_families';
	}

	public static function table_students(): string {
		global $wpdb;
		return $wpdb->prefix . 'tr_students';
	}

	public static function table_programs(): string {
		global $wpdb;
		return $wpdb->prefix . 'tr_programs';
	}

	public static function table_enrollments(): string {
		global $wpdb;
		return $wpdb->prefix . 'tr_enrollments';
	}

	public static function table_invoices(): string {
		global $wpdb;
		return $wpdb->prefix . 'tr_invoices';
	}

	public static function table_family_events(): string {
		global $wpdb;
		return $wpdb->prefix . 'tr_family_events';
	}

	public static function maybe_upgrade(): void {
		self::cleanup_legacy_access_token_cache();

		$previous_version = get_option( self::DB_VERSION_OPTION );
		if ( $previous_version === TANGNEST_ROBOTICS_DB_VERSION ) {
			return;
		}

		self::create_tables();

		// A brand new install has no rows to protect — only run this against
		// a site that already had families before amount_is_custom existed.
		if ( $previous_version && version_compare( $previous_version, '0.6.0', '<' ) ) {
			self::migrate_existing_family_amounts_to_custom();
		}

		// A brand new install has no families yet — nothing to match to a
		// package that doesn't exist until the admin enters one by hand.
		if ( $previous_version && version_compare( $previous_version, '0.8.0', '<' ) ) {
			self::migrate_families_to_packages();
		}

		// A brand new install has no invoices to backfill — period_start/
		// period_end are only ever left NULL by a row that predates this
		// column existing at all.
		if ( $previous_version && version_compare( $previous_version, '0.8.1', '<' ) ) {
			self::migrate_backfill_invoice_period_dates();
		}

		// A brand new install writes dates through the fixed insert()/
		// update() from the start (v0.8.6), so it can never have picked up
		// the 0000-00-00 artifact this backfills away.
		if ( $previous_version && version_compare( $previous_version, '0.8.6', '<' ) ) {
			self::migrate_zero_program_dates_to_null();
		}

		// A brand new install has no package-name data to migrate — course/
		// child_count are only ever backfilled on a site upgrading from
		// before this column existed.
		if ( $previous_version && version_compare( $previous_version, '0.9.3', '<' ) ) {
			self::migrate_backfill_course_and_child_count();
		}

		// A brand new install has no default_monthly_fee to copy anywhere —
		// every package starts with its price-by-child-count columns empty,
		// filled in by hand on the Packages screen.
		if ( $previous_version && version_compare( $previous_version, '0.10.0', '<' ) ) {
			self::migrate_package_prices_to_price_table();
		}

		// A brand new install has no 07XXXXXXXX numbers to convert — every
		// phone number is entered in full international format from the
		// start under the validation this version ships.
		if ( $previous_version && version_compare( $previous_version, '0.10.5', '<' ) ) {
			self::migrate_phone_numbers_to_international_format();
		}

		update_option( self::DB_VERSION_OPTION, TANGNEST_ROBOTICS_DB_VERSION );
	}

	/**
	 * v0.8.0 repurposes wp_tr_programs as the packages table and moves
	 * pricing/progress onto the family. The 18 real tiered packages don't
	 * exist yet at the moment this runs — the admin enters those by hand
	 * afterward — so this can only match each family to whichever program
	 * row its children were already enrolled in (that row simply becomes
	 * one of the packages going forward). A family whose children span more
	 * than one program, or has none, is left with package_id NULL and
	 * flagged via the existing composition-review mechanism rather than
	 * guessed at — the live site only has two families, so a partial
	 * migration with clear flags beats a wrong guess.
	 */
	private static function migrate_families_to_packages(): void {
		global $wpdb;
		$families_table = self::table_families();

		$families  = $wpdb->get_results( "SELECT id FROM {$families_table}" );
		$migrated  = 0;
		$to_review = 0;

		foreach ( $families as $row ) {
			$family_id   = (int) $row->id;
			$enrollments = TR_Enrollments::get_active_by_family( $family_id );

			$program_ids = array_values( array_unique( array_map( static function ( $e ) {
				return (int) $e->program_id;
			}, $enrollments ) ) );

			$months_paid = 0;
			foreach ( $enrollments as $enrollment ) {
				$months_paid = max( $months_paid, (int) $enrollment->months_paid );
			}

			$package_id = 1 === count( $program_ids ) ? $program_ids[0] : null;

			if ( null !== $package_id ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$families_table} SET package_id = %d, months_paid = %d, updated_at = %s WHERE id = %d",
					[ $package_id, $months_paid, current_time( 'mysql' ), $family_id ]
				) );
				$migrated++;
			} else {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$families_table} SET package_id = NULL, months_paid = %d, updated_at = %s WHERE id = %d",
					[ $months_paid, current_time( 'mysql' ), $family_id ]
				) );
				TR_Families::flag_composition_change( $family_id );
				$to_review++;
			}
		}

		TR_Logger::info( 'v0.8.0 migration: families matched to packages', [
			'migrated'  => $migrated,
			'to_review' => $to_review,
		] );
	}

	/**
	 * v0.8.1 gives an admin control over the dates each invoice covers.
	 * Every invoice created before this ran was implicitly "the whole
	 * period month" — this backfills that assumption into real dates so
	 * existing invoices display a sensible range instead of none at all.
	 * Runs once, as part of the version-gated upgrade above.
	 */
	private static function migrate_backfill_invoice_period_dates(): void {
		global $wpdb;
		$invoices_table = self::table_invoices();

		$rows = $wpdb->get_results( "SELECT id, period FROM {$invoices_table} WHERE period_start IS NULL OR period_end IS NULL" );

		$updated = 0;
		foreach ( $rows as $row ) {
			$timestamp = strtotime( $row->period . '-01' );
			if ( false === $timestamp ) {
				continue;
			}

			$wpdb->update(
				$invoices_table,
				[
					'period_start' => gmdate( 'Y-m-01', $timestamp ),
					'period_end'   => gmdate( 'Y-m-t', $timestamp ),
				],
				[ 'id' => $row->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			$updated++;
		}

		TR_Logger::info( 'v0.8.1 migration: invoice period dates backfilled', [
			'rows_updated' => $updated,
		] );
	}

	/**
	 * v0.8.6 fixes TR_Families::insert()/update() passing a null program
	 * date through a %s placeholder, which $wpdb->prepare() silently turns
	 * into an empty string — and which a DATE column then just as silently
	 * stores as '0000-00-00' instead of NULL. TR_Invoice_Generator read that
	 * zero date as a real, long-past end date and permanently skipped the
	 * family. This backfills every existing zero date to NULL so those
	 * families bill again; new writes can no longer produce one. Runs once,
	 * as part of the version-gated upgrade above.
	 */
	private static function migrate_zero_program_dates_to_null(): void {
		global $wpdb;
		$families_table = self::table_families();

		$start_updated = $wpdb->query(
			"UPDATE {$families_table} SET program_start_date = NULL WHERE program_start_date = '0000-00-00'"
		);
		$end_updated = $wpdb->query(
			"UPDATE {$families_table} SET program_end_date = NULL WHERE program_end_date = '0000-00-00'"
		);

		TR_Logger::info( 'v0.8.6 migration: zero program dates converted to NULL', [
			'program_start_date_rows' => false !== $start_updated ? (int) $start_updated : 0,
			'program_end_date_rows'   => false !== $end_updated ? (int) $end_updated : 0,
		] );
	}

	/**
	 * v0.9.3 adds course/child_count so a family's package can be matched
	 * automatically when its active child count changes. Every package
	 * created before this ran only has a free-text name like "Applied
	 * Robotics & Coding – 2 siblings" — this parses what it can out of that
	 * name and leaves the rest NULL for an admin to fill in by hand (see
	 * `wp tangnest status`). Runs once, as part of the version-gated
	 * upgrade above.
	 */
	private static function migrate_backfill_course_and_child_count(): void {
		global $wpdb;
		$programs_table = self::table_programs();

		$rows = $wpdb->get_results( "SELECT id, name, course, child_count FROM {$programs_table} WHERE course IS NULL OR course = '' OR child_count IS NULL" );

		$matched      = 0;
		$needs_review = [];

		foreach ( $rows as $row ) {
			$parsed = TR_Programs::parse_name_into_course_and_child_count( $row->name );

			$course      = ( null !== $row->course && '' !== $row->course ) ? $row->course : $parsed['course'];
			$child_count = null !== $row->child_count ? (int) $row->child_count : $parsed['child_count'];

			if ( null === $course || null === $child_count ) {
				$needs_review[] = (int) $row->id;
				continue;
			}

			$wpdb->update(
				$programs_table,
				[ 'course' => $course, 'child_count' => $child_count ],
				[ 'id' => $row->id ],
				[ '%s', '%d' ],
				[ '%d' ]
			);
			$matched++;
		}

		TR_Logger::info( 'v0.9.3 migration: course/child_count backfill', [
			'matched'          => $matched,
			'needs_review'     => count( $needs_review ),
			'needs_review_ids' => $needs_review,
		] );
	}

	/**
	 * v0.10.0 moves pricing off the single default_monthly_fee figure onto
	 * a price-by-child-count table (price_1_child / price_2_children /
	 * price_3_children / price_additional_child — see
	 * TR_Families::calculate_amount()). Every package created before this
	 * ran has exactly one real price, default_monthly_fee, and — for most —
	 * a child_count backfilled by the v0.9.3 migration above; this copies
	 * that one price into the column matching its own child_count and
	 * leaves the other three columns NULL for an admin to fill in by hand.
	 * It never guesses a price a package doesn't already hold, and it
	 * never writes a second time into a column that already has one (safe
	 * to re-run). A package whose child_count is still NULL or 0 — the
	 * v0.9.3 name parser failed on it — is inferred as 1 child (every one
	 * on the live site is, in fact, a single-child package, confirmed by
	 * hand against the real data, not guessed per row) and logged
	 * individually by ID and name so that can be checked by eye.
	 *
	 * Deliberately never touches wp_tr_families — monthly_amount on every
	 * existing family is a frozen snapshot (v0.8.0) and this migration
	 * must never move it, whether or not its package was itself migrated.
	 * Runs once, as part of the version-gated upgrade above.
	 */
	private static function migrate_package_prices_to_price_table(): void {
		global $wpdb;
		$programs_table = self::table_programs();

		$rows = $wpdb->get_results( "SELECT id, name, child_count, default_monthly_fee, price_1_child, price_2_children, price_3_children FROM {$programs_table}" );

		$price_column_by_count = [ 1 => 'price_1_child', 2 => 'price_2_children', 3 => 'price_3_children' ];

		$migrated = 0;
		$inferred = [];
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$child_count = (int) $row->child_count;

			if ( $child_count < 1 || $child_count > 3 ) {
				$child_count = 1;
				$inferred[]  = [ 'id' => (int) $row->id, 'name' => $row->name ];
			}

			$column = $price_column_by_count[ $child_count ];

			if ( null !== $row->$column ) {
				// Already has a price in that slot (safe to re-run) — never
				// overwrite a value that might by now differ from
				// default_monthly_fee.
				$skipped++;
				continue;
			}

			$wpdb->update(
				$programs_table,
				[ 'child_count' => $child_count, $column => $row->default_monthly_fee ],
				[ 'id' => $row->id ],
				[ '%d', '%s' ],
				[ '%d' ]
			);
			$migrated++;
		}

		foreach ( $inferred as $package ) {
			TR_Logger::info( 'v0.10.0 migration: child count inferred as 1', [
				'package_id' => $package['id'],
				'name'       => $package['name'],
			] );
		}

		$incomplete = [];
		foreach ( TR_Programs::get_list( [ 'status' => 'active', 'per_page' => 10000 ] ) as $package ) {
			$missing = TR_Programs::missing_price_labels( $package );
			if ( ! empty( $missing ) ) {
				$incomplete[] = [ 'id' => (int) $package->id, 'name' => $package->name, 'missing' => $missing ];
			}
		}

		TR_Logger::info( 'v0.10.0 migration: package prices copied to price table', [
			'migrated_count'   => $migrated,
			'inferred_count'   => count( $inferred ),
			'already_set_count' => $skipped,
			'incomplete_active_packages' => count( $incomplete ),
			'incomplete_detail' => $incomplete,
		] );
	}

	/**
	 * v0.10.5 replaces the Rwanda-only 07XXXXXXXX phone field with a
	 * plain international one — every number already on file was entered
	 * under the old format and must become the E.164 form the rest of the
	 * code now expects (TR_Notifications::msisdn(), the IremboPay
	 * customer payload, the WhatsApp builders). Converts `0XXXXXXXXX` to
	 * `+250XXXXXXXXX`; a number that already starts with `+` is left
	 * alone (already migrated, or already entered correctly since this
	 * version went live); anything matching neither shape is left alone
	 * and logged by family ID — guessing at a malformed number is worse
	 * than leaving it for the admin to fix by hand. Runs once, as part of
	 * the version-gated upgrade above.
	 */
	private static function migrate_phone_numbers_to_international_format(): void {
		global $wpdb;
		$families_table = self::table_families();

		$families = $wpdb->get_results( "SELECT id, parent_user_id FROM {$families_table}" );

		$converted    = 0;
		$needs_review = [];

		foreach ( $families as $row ) {
			$family_id = (int) $row->id;
			$user_id   = (int) $row->parent_user_id;

			$phone = get_user_meta( $user_id, 'phone_number', true );
			if ( ! is_string( $phone ) || '' === $phone ) {
				continue;
			}

			if ( 0 === strpos( $phone, '+' ) ) {
				continue;
			}

			if ( preg_match( '/^0\d{9}$/', $phone ) ) {
				update_user_meta( $user_id, 'phone_number', '+250' . substr( $phone, 1 ) );
				$converted++;
				continue;
			}

			$needs_review[] = $family_id;
		}

		TR_Logger::info( 'v0.10.5 migration: phone numbers converted to international format', [
			'converted_count'  => $converted,
			'needs_review'     => count( $needs_review ),
			'needs_review_ids' => $needs_review,
		] );
	}

	/**
	 * v0.6.0 makes monthly_amount a calculated figure by default. Every
	 * family that existed before this ran had its amount hand-typed under
	 * the old model — those figures must not be silently overwritten by a
	 * calculation the admin never asked for, so they're all marked custom.
	 * Runs once, as part of the version-gated upgrade above.
	 */
	private static function migrate_existing_family_amounts_to_custom(): void {
		global $wpdb;
		$families_table = self::table_families();

		$updated = $wpdb->query( "UPDATE {$families_table} SET amount_is_custom = 1 WHERE amount_is_custom = 0" );

		TR_Logger::info( 'v0.6.0 migration: existing family amounts marked as custom', [
			'rows_updated' => false !== $updated ? (int) $updated : 0,
		] );
	}

	/**
	 * One-time cleanup, independent of the DB_VERSION gate above since
	 * this isn't a schema change. v0.3.1 cached raw access tokens for 7
	 * days; a site already on 0.3.1+ before upgrading to the fix in 0.3.3
	 * can be carrying a stale tr_access_raw_* transient whose value no
	 * longer matches the family's current token hash. Runs once, guarded
	 * by its own option flag, then never again.
	 */
	private static function cleanup_legacy_access_token_cache(): void {
		$flag = 'tangnest_robotics_legacy_token_cache_cleaned';
		if ( get_option( $flag ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_tr_access_raw_' ) . '%'
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_timeout_tr_access_raw_' ) . '%'
		) );

		update_option( $flag, current_time( 'mysql' ), false );
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$families = self::table_families();
		dbDelta( "CREATE TABLE {$families} (
			id                       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_user_id           BIGINT(20) UNSIGNED NOT NULL,
			package_id               BIGINT(20) UNSIGNED DEFAULT NULL,
			monthly_amount           DECIMAL(12,2) NOT NULL DEFAULT '0.00',
			months_paid              TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
			currency                 VARCHAR(10)  NOT NULL DEFAULT 'RWF',
			billing_day              TINYINT(3)   UNSIGNED NOT NULL DEFAULT 1,
			program_start_date       DATE         DEFAULT NULL,
			program_end_date         DATE         DEFAULT NULL,
			negotiated_amount            DECIMAL(12,2) DEFAULT NULL,
			negotiated_for_child_count   TINYINT(3)   UNSIGNED DEFAULT NULL,
			negotiated_note               TEXT         DEFAULT NULL,
			status                   VARCHAR(20)  NOT NULL DEFAULT 'active',
			notes                    TEXT         DEFAULT NULL,
			access_token_hash        CHAR(64)     DEFAULT NULL,
			access_token_created     DATETIME     DEFAULT NULL,
			access_token_first_used  DATETIME     DEFAULT NULL,
			access_token_last_used   DATETIME     DEFAULT NULL,
			access_token_use_count   TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
			access_token_status      VARCHAR(20)  NOT NULL DEFAULT 'unused',
			access_token_expires     DATETIME     DEFAULT NULL,
			message_token_hash       CHAR(64)     DEFAULT NULL,
			message_token_created    DATETIME     DEFAULT NULL,
			message_token_expires    DATETIME     DEFAULT NULL,
			message_token_last_used  DATETIME     DEFAULT NULL,
			message_token_use_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at               DATETIME     NOT NULL,
			updated_at               DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY parent_user_id (parent_user_id),
			UNIQUE KEY access_token_hash (access_token_hash),
			UNIQUE KEY message_token_hash (message_token_hash),
			KEY status (status),
			KEY package_id (package_id)
		) {$charset};" );

		$students = self::table_students();
		dbDelta( "CREATE TABLE {$students} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			family_id     BIGINT(20) UNSIGNED NOT NULL,
			first_name    VARCHAR(100) NOT NULL,
			last_name     VARCHAR(100) NOT NULL,
			date_of_birth DATE         DEFAULT NULL,
			school        VARCHAR(150) DEFAULT NULL,
			status        VARCHAR(20)  NOT NULL DEFAULT 'active',
			notes         TEXT         DEFAULT NULL,
			created_at    DATETIME     NOT NULL,
			updated_at    DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			KEY family_id (family_id),
			KEY status (status)
		) {$charset};" );

		$programs = self::table_programs();
		dbDelta( "CREATE TABLE {$programs} (
			id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name                   VARCHAR(150) NOT NULL,
			duration_months        TINYINT(3)   UNSIGNED NOT NULL DEFAULT 8,
			default_monthly_fee    DECIMAL(12,2) NOT NULL DEFAULT '0.00',
			irembopay_product_code VARCHAR(60)  DEFAULT NULL,
			course                 VARCHAR(150) DEFAULT NULL,
			child_count            TINYINT(3)   UNSIGNED DEFAULT NULL,
			price_1_child          DECIMAL(12,2) DEFAULT NULL,
			price_2_children       DECIMAL(12,2) DEFAULT NULL,
			price_3_children       DECIMAL(12,2) DEFAULT NULL,
			price_additional_child DECIMAL(12,2) DEFAULT NULL,
			start_date             DATE         DEFAULT NULL,
			notes                  TEXT         DEFAULT NULL,
			status                 VARCHAR(20)  NOT NULL DEFAULT 'active',
			created_at             DATETIME     NOT NULL,
			updated_at             DATETIME     NOT NULL,
			PRIMARY KEY  (id)
		) {$charset};" );

		$enrollments = self::table_enrollments();
		dbDelta( "CREATE TABLE {$enrollments} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			student_id   BIGINT(20) UNSIGNED NOT NULL,
			program_id   BIGINT(20) UNSIGNED NOT NULL,
			enrolled_on  DATE         NOT NULL,
			months_total TINYINT(3)   UNSIGNED NOT NULL DEFAULT 8,
			months_paid  TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
			status       VARCHAR(20)  NOT NULL DEFAULT 'active',
			created_at   DATETIME     NOT NULL,
			updated_at   DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			KEY student_id (student_id),
			KEY program_id (program_id),
			KEY status (status)
		) {$charset};" );

		$invoices = self::table_invoices();
		dbDelta( "CREATE TABLE {$invoices} (
			id                        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			family_id                 BIGINT(20) UNSIGNED NOT NULL,
			period                    VARCHAR(7)   NOT NULL,
			period_start              DATE         DEFAULT NULL,
			period_end                DATE         DEFAULT NULL,
			amount                    DECIMAL(12,2) NOT NULL,
			currency                  VARCHAR(10)  NOT NULL DEFAULT 'RWF',
			status                    VARCHAR(20)  NOT NULL DEFAULT 'pending',
			due_date                  DATE         NOT NULL,
			issued_at                 DATETIME     NOT NULL,
			paid_at                   DATETIME     DEFAULT NULL,
			payment_method            VARCHAR(50)  DEFAULT NULL,
			payment_reference         VARCHAR(120) DEFAULT NULL,
			recorded_by               BIGINT(20) UNSIGNED DEFAULT NULL,
			waive_reason              TEXT         DEFAULT NULL,
			student_snapshot          TEXT         DEFAULT NULL,
			irembopay_invoice_number  VARCHAR(120) DEFAULT NULL,
			irembopay_transaction_id  VARCHAR(120) DEFAULT NULL,
			irembopay_expires_at      DATETIME     DEFAULT NULL,
			original_amount           DECIMAL(12,2) DEFAULT NULL,
			amount_edit_reason        TEXT         DEFAULT NULL,
			last_reminder_sent        DATETIME     DEFAULT NULL,
			reminder_count            TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
			reminder_stages_sent      VARCHAR(100) DEFAULT NULL,
			created_at                DATETIME     NOT NULL,
			updated_at                DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY family_period (family_id, period),
			KEY status (status),
			KEY due_date (due_date),
			KEY irembopay_invoice_number (irembopay_invoice_number)
		) {$charset};" );

		$family_events = self::table_family_events();
		dbDelta( "CREATE TABLE {$family_events} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			family_id    BIGINT(20) UNSIGNED NOT NULL,
			event_type   VARCHAR(40)  NOT NULL,
			summary      VARCHAR(255) NOT NULL,
			old_value    VARCHAR(100) DEFAULT NULL,
			new_value    VARCHAR(100) DEFAULT NULL,
			recorded_by  BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at   DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			KEY family_id (family_id),
			KEY created_at (created_at)
		) {$charset};" );
	}
}
