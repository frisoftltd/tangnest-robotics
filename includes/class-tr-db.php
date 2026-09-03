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

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === TANGNEST_ROBOTICS_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, TANGNEST_ROBOTICS_DB_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$families = self::table_families();
		dbDelta( "CREATE TABLE {$families} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_user_id BIGINT(20) UNSIGNED NOT NULL,
			monthly_amount DECIMAL(12,2) NOT NULL DEFAULT '0.00',
			currency       VARCHAR(10)  NOT NULL DEFAULT 'RWF',
			billing_day    TINYINT(3)   UNSIGNED NOT NULL DEFAULT 1,
			status         VARCHAR(20)  NOT NULL DEFAULT 'active',
			notes          TEXT         DEFAULT NULL,
			created_at     DATETIME     NOT NULL,
			updated_at     DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY parent_user_id (parent_user_id),
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
	}
}
