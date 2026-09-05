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

	public static function maybe_upgrade(): void {
		self::cleanup_legacy_access_token_cache();

		if ( get_option( self::DB_VERSION_OPTION ) === TANGNEST_ROBOTICS_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, TANGNEST_ROBOTICS_DB_VERSION );
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
			monthly_amount           DECIMAL(12,2) NOT NULL DEFAULT '0.00',
			currency                 VARCHAR(10)  NOT NULL DEFAULT 'RWF',
			billing_day              TINYINT(3)   UNSIGNED NOT NULL DEFAULT 1,
			status                   VARCHAR(20)  NOT NULL DEFAULT 'active',
			notes                    TEXT         DEFAULT NULL,
			access_token_hash        CHAR(64)     DEFAULT NULL,
			access_token_created     DATETIME     DEFAULT NULL,
			access_token_first_used  DATETIME     DEFAULT NULL,
			access_token_last_used   DATETIME     DEFAULT NULL,
			access_token_use_count   TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
			access_token_status      VARCHAR(20)  NOT NULL DEFAULT 'unused',
			access_token_expires     DATETIME     DEFAULT NULL,
			created_at               DATETIME     NOT NULL,
			updated_at               DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY parent_user_id (parent_user_id),
			UNIQUE KEY access_token_hash (access_token_hash),
			KEY status (status)
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
			start_date             DATE         DEFAULT NULL,
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
			created_at                DATETIME     NOT NULL,
			updated_at                DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY family_period (family_id, period),
			KEY status (status),
			KEY due_date (due_date),
			KEY irembopay_invoice_number (irembopay_invoice_number)
		) {$charset};" );
	}
}
