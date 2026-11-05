<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * `wp tangnest ...` — inspection and a genuine dry run for invoice
 * generation, for the admin to check what the daily cron is about to do
 * to nine paying families before it does it. This file is only required
 * when WP-CLI is the thing running (see tangnest-robotics.php), and
 * every command here is read-only unless explicitly told otherwise
 * (`generate` without --dry-run, `remind` without --dry-run) — nothing
 * in this class runs from, or affects, a normal web request.
 *
 * `generate` never reimplements TR_Invoice_Generator's eligibility
 * rules — it calls TR_Invoice_Generator::build_plan() (the decision
 * step) and, only when not a dry run, TR_Invoice_Generator::run()
 * (which calls build_plan() itself, then acts on it). A dry run that
 * could silently diverge from the real thing would be worse than no
 * dry run at all.
 */
class TR_CLI_Command {

	/**
	 * Fixed data for `wp tangnest consolidate-packages` (v0.10.1) —
	 * deliberately NOT derived from package names, which are inconsistent
	 * ("Applied Robotics & Coding – 2 siblings" no longer means anything
	 * under the price-table model). Every number here was verified by
	 * hand against the live data before this command was written.
	 *
	 * price_add is NULL on every robotics package on purpose — the real
	 * increments (+55,000 then +35,000) don't extend to a fourth child,
	 * and a family that size would be negotiated anyway. NULL means that
	 * family is refused, never billed an invented figure.
	 */
	const CONSOLIDATION_KEEP_PACKAGES = [
		4  => [ 'name' => 'Introduction Robotics, Engineering & Coding — Standard', 'course' => 'Introduction Robotics, Engineering & Coding', 'price1' => 65000.0, 'price2' => 120000.0, 'price3' => 155000.0, 'price_add' => null, 'duration' => 8 ],
		3  => [ 'name' => 'Introduction Robotics, Engineering & Coding — Premium', 'course' => 'Introduction Robotics, Engineering & Coding', 'price1' => 75000.0, 'price2' => 140000.0, 'price3' => 195000.0, 'price_add' => null, 'duration' => 8 ],
		8  => [ 'name' => 'Robotics Engineering — Standard', 'course' => 'Robotics Engineering', 'price1' => 65000.0, 'price2' => 120000.0, 'price3' => 155000.0, 'price_add' => null, 'duration' => 8 ],
		9  => [ 'name' => 'Robotics Engineering — Premium', 'course' => 'Robotics Engineering', 'price1' => 75000.0, 'price2' => 140000.0, 'price3' => 195000.0, 'price_add' => null, 'duration' => 8 ],
		14 => [ 'name' => 'Applied Robotics & Coding — Standard', 'course' => 'Applied Robotics & Coding', 'price1' => 65000.0, 'price2' => 120000.0, 'price3' => 155000.0, 'price_add' => null, 'duration' => 8 ],
		15 => [ 'name' => 'Applied Robotics & Coding — Premium', 'course' => 'Applied Robotics & Coding', 'price1' => 75000.0, 'price2' => 140000.0, 'price3' => 195000.0, 'price_add' => null, 'duration' => 8 ],
		25 => [ 'name' => 'Computational Thinking with Scratch', 'course' => 'Computational Thinking with Scratch', 'price1' => 19500.0, 'price2' => 39000.0, 'price3' => 58500.0, 'price_add' => 19500.0, 'duration' => 8 ],
		22 => [ 'name' => 'Computer Science & Virtual Robotics', 'course' => 'Computer Science & Virtual Robotics', 'price1' => 47500.0, 'price2' => 95000.0, 'price3' => 142500.0, 'price_add' => 47500.0, 'duration' => 8 ],
		1  => [ 'name' => 'Introduction Robotics — Test (50 RWF)', 'course' => 'Introduction Robotics, Engineering & Coding', 'price1' => 50.0, 'price2' => 50.0, 'price3' => 50.0, 'price_add' => 50.0, 'duration' => 8 ],
	];

	/**
	 * Keyed by family ID. 'negotiated' + 'negotiated_note' are only
	 * present for families 14 and 20, whose rate cannot be derived from
	 * any rule — recorded, not computed.
	 */
	const CONSOLIDATION_REASSIGNMENTS = [
		5  => [ 'from' => 16, 'to' => 14, 'child_count' => 2, 'amount' => 120000.0 ],
		7  => [ 'from' => 16, 'to' => 14, 'child_count' => 2, 'amount' => 120000.0 ],
		10 => [ 'from' => 16, 'to' => 14, 'child_count' => 2, 'amount' => 120000.0 ],
		15 => [ 'from' => 16, 'to' => 14, 'child_count' => 2, 'amount' => 120000.0 ],
		14 => [ 'from' => 20, 'to' => 14, 'child_count' => 3, 'amount' => 130000.0, 'negotiated' => 130000.0, 'negotiated_note' => 'Consolidated from package 20' ],
		20 => [ 'from' => 21, 'to' => 14, 'child_count' => 1, 'amount' => 60000.0, 'negotiated' => 60000.0, 'negotiated_note' => 'Consolidated from package 21' ],
	];

	const CONSOLIDATION_ARCHIVE_PACKAGES = [ 2, 5, 6, 7, 10, 11, 12, 13, 16, 17, 18, 19, 20, 21, 23, 24, 26, 27 ];

	/**
	 * One-screen overview of the whole system: families, children,
	 * packages, invoices, money, the next billing date, cron, online
	 * payments and the debug-log toggle — plus anything worth flagging.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render structured metrics instead of the default human summary.
	 * ---
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest status
	 *     wp tangnest status --format=json
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ): void {
		$format = $assoc_args['format'] ?? '';

		$families_active     = TR_Families::count( [ 'status' => 'active' ] );
		$families_inactive   = TR_Families::count( [ 'status' => 'inactive' ] );
		$families_completed  = TR_Families::count( [ 'status' => 'completed' ] );

		$active_families  = TR_Families::get_list( [ 'status' => 'active', 'per_page' => 10000 ] );
		$today_str        = current_time( 'Y-m-d' );
		$billability      = self::billability_by_family( $active_families, $today_str );
		$cannot_be_billed = self::cannot_be_billed_rows( $active_families, $billability );
		$incomplete_packages    = self::incomplete_package_rows();
		$negotiated_needs_review = self::negotiated_review_rows( $active_families );

		$children_active = TR_Students::count( [ 'status' => 'active' ] );

		$packages_active = TR_Programs::count( [ 'status' => 'active' ] );
		$active_packages = TR_Programs::get_list( [ 'status' => 'active', 'per_page' => 10000 ] );
		$default_product_code = TR_IremboPay_Settings::default_product_code();
		$packages_without_code = '' === $default_product_code
			? array_values( array_filter( $active_packages, static function ( $p ) {
				return empty( $p->irembopay_product_code );
			} ) )
			: [];

		$invoices_pending = TR_Invoices::count( [ 'status' => 'pending' ] );
		$invoices_paid    = TR_Invoices::count( [ 'status' => 'paid' ] );
		$invoices_overdue = TR_Invoices::count( [ 'status' => 'overdue' ] );

		$totals      = TR_Invoices::totals_by_status();
		$outstanding = ( $totals['pending'] ?? 0.0 ) + ( $totals['overdue'] ?? 0.0 );

		$month_start          = current_time( 'Y-m-01' );
		$month_end            = current_time( 'Y-m-t' );
		$collected_this_month = TR_Invoices::collected_in_period( $month_start, $month_end );

		[ $next_billing_date, $next_billing_count ] = self::next_billing_summary( $active_families, $billability );

		$cron_next_ts = wp_next_scheduled( TR_Cron::HOOK );

		$irembopay          = TR_IremboPay_Settings::get();
		$online_pay_enabled = ! empty( $irembopay['enabled'] );
		$online_pay_ready   = TR_IremboPay_Settings::is_enabled();

		$debug_on = TR_Logger::debug_enabled();

		$issues = [];
		if ( ! empty( $cannot_be_billed ) ) {
			$issues[] = sprintf(
				'%d active famil%s cannot be billed — see "Cannot be billed" below',
				count( $cannot_be_billed ),
				1 === count( $cannot_be_billed ) ? 'y' : 'ies'
			);
		}
		if ( ! empty( $incomplete_packages ) ) {
			$is_one = 1 === count( $incomplete_packages );
			$issues[] = sprintf(
				'%d active package%s missing part of %s price table%s — see "Packages with incomplete pricing" below',
				count( $incomplete_packages ),
				$is_one ? ' is' : 's are',
				$is_one ? 'its' : 'their',
				$is_one ? '' : 's'
			);
		}
		if ( ! empty( $negotiated_needs_review ) ) {
			$issues[] = sprintf(
				'%d negotiated rate%s stale — see "Negotiated rates needing review" below',
				count( $negotiated_needs_review ),
				1 === count( $negotiated_needs_review ) ? ' is' : 's are'
			);
		}
		foreach ( $packages_without_code as $p ) {
			$issues[] = sprintf( 'package "%s" (id %d) has no product code and no site-wide default is set', $p->name, (int) $p->id );
		}
		if ( false === $cron_next_ts ) {
			$issues[] = sprintf( 'cron event "%s" is not scheduled', TR_Cron::HOOK );
		}
		if ( $online_pay_enabled && '' === $irembopay['secret_key'] ) {
			$issues[] = 'online payments are enabled but no IremboPay secret key is configured';
		}

		$data = [
			'version'                     => TANGNEST_ROBOTICS_VERSION,
			'db_version'                  => TANGNEST_ROBOTICS_DB_VERSION,
			'families_active'             => $families_active,
			'families_inactive'           => $families_inactive,
			'families_completed'         => $families_completed,
			'children_active'             => $children_active,
			'packages_active'             => $packages_active,
			'invoices_pending'            => $invoices_pending,
			'invoices_paid'               => $invoices_paid,
			'invoices_overdue'            => $invoices_overdue,
			'outstanding_amount'          => round( $outstanding, 2 ),
			'collected_this_month'        => round( $collected_this_month, 2 ),
			'currency'                    => 'RWF',
			'next_billing_date'           => $next_billing_date,
			'next_billing_family_count'   => $next_billing_count,
			'cron_hook'                   => TR_Cron::HOOK,
			'cron_scheduled'              => false !== $cron_next_ts,
			'cron_next_run_ts'            => $cron_next_ts ?: null,
			'online_pay_enabled'          => $online_pay_enabled,
			'online_pay_ready'            => $online_pay_ready,
			'online_pay_default_code_set' => '' !== $default_product_code,
			'debug_log'                   => $debug_on,
			'issues'                      => $issues,
			'cannot_be_billed'            => $cannot_be_billed,
			'incomplete_packages'         => $incomplete_packages,
			'negotiated_needs_review'     => $negotiated_needs_review,
		];

		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			WP_CLI::print_value( $data, $assoc_args );
			return;
		}

		if ( in_array( $format, [ 'table', 'csv' ], true ) ) {
			$rows = [];
			foreach ( $data as $key => $value ) {
				if ( in_array( $key, [ 'issues', 'cannot_be_billed', 'incomplete_packages', 'negotiated_needs_review' ], true ) ) {
					continue;
				}
				$rows[] = [ 'metric' => $key, 'value' => self::scalar_for_display( $value ) ];
			}
			foreach ( $data['issues'] as $issue ) {
				$rows[] = [ 'metric' => 'issue', 'value' => $issue ];
			}
			\WP_CLI\Utils\format_items( $format, $rows, [ 'metric', 'value' ] );

			if ( ! empty( $data['cannot_be_billed'] ) ) {
				WP_CLI::line( '' );
				\WP_CLI\Utils\format_items( $format, $data['cannot_be_billed'], [ 'family_id', 'parent_name', 'code', 'reason' ] );
			}

			if ( ! empty( $data['incomplete_packages'] ) ) {
				WP_CLI::line( '' );
				\WP_CLI\Utils\format_items( $format, $data['incomplete_packages'], [ 'package_id', 'name', 'missing' ] );
			}

			if ( ! empty( $data['negotiated_needs_review'] ) ) {
				WP_CLI::line( '' );
				\WP_CLI\Utils\format_items( $format, $data['negotiated_needs_review'], [ 'family_id', 'parent_name', 'reason' ] );
			}
			return;
		}

		self::print_status_text( $data );
	}

	/**
	 * Preview or run monthly invoice generation. Shares its decision code
	 * with the daily cron job — this never runs a second copy of the
	 * eligibility rules, it calls the exact same one.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what generation would do without writing or sending anything.
	 *
	 * [--family=<id>]
	 * : Restrict to a single family.
	 *
	 * [--format=<format>]
	 * : Render structured data instead of the default summary + table.
	 * ---
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest generate --dry-run
	 *     wp tangnest generate --family=5 --dry-run
	 *     wp tangnest generate
	 *
	 * @when after_wp_load
	 */
	public function generate( $args, $assoc_args ): void {
		$dry_run   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$family_id = isset( $assoc_args['family'] ) ? absint( $assoc_args['family'] ) : null;
		$format    = $assoc_args['format'] ?? '';

		if ( null !== $family_id ) {
			$family = TR_Families::get( $family_id );
			if ( null === $family ) {
				WP_CLI::error( "Family {$family_id} not found." );
			}
			if ( 'active' !== $family->status ) {
				WP_CLI::warning( "Family {$family_id} status is \"{$family->status}\", not \"active\" — it is never eligible for generation." );
			}
		}

		if ( $dry_run ) {
			$plan    = TR_Invoice_Generator::build_plan( $family_id );
			$created = [];
			$failed  = [];
		} else {
			$result  = TR_Invoice_Generator::run( $family_id );
			$plan    = $result['plan'];
			$created = $result['created'];
			$failed  = $result['failed'];
		}

		self::output_generate_result( $plan, $created, $failed, $dry_run, $format, $assoc_args );
	}

	/**
	 * Everything about one family: parent, package, billing, programme
	 * dates, progress, children, access/message token status, and every
	 * invoice on file.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The family ID.
	 *
	 * [--format=<format>]
	 * ---
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest family 5
	 *
	 * @when after_wp_load
	 */
	public function family( $args, $assoc_args ): void {
		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Usage: wp tangnest family <id>' );
		}

		$family = TR_Families::get( $id );
		if ( null === $family ) {
			WP_CLI::error( "Family {$id} not found." );
		}

		$user     = get_userdata( (int) $family->parent_user_id );
		$package  = ! empty( $family->package_id ) ? TR_Programs::get( (int) $family->package_id ) : null;
		$children = TR_Students::get_list( [ 'family_id' => $id, 'per_page' => 200 ] );
		$invoices = TR_Invoices::get_by_family( $id );

		$data = [
			'id'                   => $id,
			'status'               => $family->status,
			'parent_name'          => $user ? $user->display_name : '(no WordPress user)',
			'parent_email'         => $user ? $user->user_email : '',
			'parent_phone'         => $user ? get_user_meta( $user->ID, 'phone_number', true ) : '',
			'package'              => $package ? $package->name : '(none)',
			'monthly_amount'       => number_format( (float) $family->monthly_amount, 0 ),
			'currency'             => $family->currency,
			'billing_day'          => (int) $family->billing_day,
			'program_start_date'   => TR_Families::is_date_set( $family->program_start_date ) ? $family->program_start_date : null,
			'program_end_date'     => TR_Families::is_date_set( $family->program_end_date ) ? $family->program_end_date : null,
			'progress'             => TR_Families::progress_label( $family ),
			'access_token_status'  => TR_Access_Tokens::status_label( $family ),
			'message_token_status' => TR_Message_Tokens::status_label( $family ),
			'children'             => array_map( static function ( $c ) {
				return [
					'id'     => (int) $c->id,
					'name'   => trim( $c->first_name . ' ' . $c->last_name ),
					'status' => $c->status,
				];
			}, $children ),
			'invoices' => array_map( static function ( $inv ) {
				return [
					'id'       => (int) $inv->id,
					'period'   => $inv->period,
					'status'   => $inv->status,
					'amount'   => number_format( (float) $inv->amount, 0 ),
					'currency' => $inv->currency,
					'due_date' => $inv->due_date,
					'paid_at'  => $inv->paid_at ? substr( $inv->paid_at, 0, 10 ) : null,
				];
			}, $invoices ),
			'history' => array_map( static function ( $event ) {
				return [
					'date'    => substr( $event->created_at, 0, 10 ),
					'summary' => $event->summary,
					'by'      => TR_Family_Events::actor_label( $event ),
				];
			}, TR_Family_Events::get_by_family( $id ) ),
		];

		$format = $assoc_args['format'] ?? '';

		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			WP_CLI::print_value( $data, $assoc_args );
			return;
		}

		if ( in_array( $format, [ 'table', 'csv' ], true ) ) {
			$summary = $data;
			unset( $summary['children'], $summary['invoices'], $summary['history'] );
			$row = array_map( [ __CLASS__, 'scalar_for_display' ], $summary );
			\WP_CLI\Utils\format_items( $format, [ $row ], array_keys( $row ) );

			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( $format, $data['children'], [ 'id', 'name', 'status' ] );

			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( $format, $data['invoices'], [ 'id', 'period', 'status', 'amount', 'currency', 'due_date', 'paid_at' ] );

			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( $format, $data['history'], [ 'date', 'summary', 'by' ] );
			return;
		}

		self::print_family_text( $data );
	}

	/**
	 * List invoices.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status (pending, paid, overdue, cancelled, waived).
	 *
	 * [--family=<id>]
	 * : Filter by family ID.
	 *
	 * [--period=<period>]
	 * : Filter by billing period, e.g. 2026-09. Defaults to the current
	 * period; pass "all" to list every period.
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest invoices
	 *     wp tangnest invoices --status=overdue
	 *     wp tangnest invoices --period=all --format=json
	 *
	 * @when after_wp_load
	 */
	public function invoices( $args, $assoc_args ): void {
		$status    = $assoc_args['status'] ?? '';
		$family_id = isset( $assoc_args['family'] ) ? absint( $assoc_args['family'] ) : 0;
		$period    = $assoc_args['period'] ?? current_time( 'Y-m' );
		$format    = $assoc_args['format'] ?? 'table';

		if ( '' !== $status && ! in_array( $status, TR_Invoices::STATUSES, true ) ) {
			WP_CLI::error( sprintf( 'Unknown status "%s". Valid: %s', $status, implode( ', ', TR_Invoices::STATUSES ) ) );
		}

		$query_args = [ 'per_page' => 10000 ];
		if ( '' !== $status ) {
			$query_args['status'] = $status;
		}
		if ( $family_id > 0 ) {
			$query_args['family_id'] = $family_id;
		}
		if ( 'all' !== $period ) {
			$query_args['period'] = $period;
		}

		$invoices = TR_Invoices::get_list( $query_args );

		$rows = array_map( static function ( $inv ) {
			$family = TR_Families::get( (int) $inv->family_id );
			$user   = $family ? get_userdata( (int) $family->parent_user_id ) : null;

			return [
				'id'       => (int) $inv->id,
				'family'   => (int) $inv->family_id,
				'parent'   => $user ? $user->display_name : '',
				'period'   => $inv->period,
				'status'   => $inv->status,
				'amount'   => number_format( (float) $inv->amount, 0 ),
				'currency' => $inv->currency,
				'due_date' => $inv->due_date,
				'paid_at'  => $inv->paid_at ? substr( $inv->paid_at, 0, 10 ) : '',
			];
		}, $invoices );

		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			WP_CLI::print_value( $rows, $assoc_args );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'family', 'parent', 'period', 'status', 'amount', 'currency', 'due_date', 'paid_at' ] );
	}

	/**
	 * Send, or preview, a reminder for one invoice — useful for checking
	 * the wording (including the resolved day-count line) without
	 * pestering a parent.
	 *
	 * ## OPTIONS
	 *
	 * <invoice_id>
	 * : The invoice ID.
	 *
	 * [--dry-run]
	 * : Show what would be sent without sending it, generating a token, or
	 * writing to the log.
	 *
	 * [--format=<format>]
	 * ---
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest remind 9 --dry-run
	 *     wp tangnest remind 9
	 *
	 * @when after_wp_load
	 */
	public function remind( $args, $assoc_args ): void {
		$invoice_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $invoice_id <= 0 ) {
			WP_CLI::error( 'Usage: wp tangnest remind <invoice_id>' );
		}

		$invoice = TR_Invoices::get( $invoice_id );
		if ( null === $invoice ) {
			WP_CLI::error( "Invoice {$invoice_id} not found." );
		}

		$family = TR_Families::get( (int) $invoice->family_id );
		if ( null === $family ) {
			WP_CLI::error( "Invoice {$invoice_id} has no matching family record." );
		}

		$user = get_userdata( (int) $family->parent_user_id );
		if ( ! $user ) {
			WP_CLI::error( "Family {$family->id} has no matching WordPress user." );
		}

		if ( ! in_array( $invoice->status, [ 'pending', 'overdue' ], true ) ) {
			WP_CLI::warning( "Invoice {$invoice_id} is \"{$invoice->status}\", not pending/overdue — sending a reminder for it is unusual." );
		}

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$format  = $assoc_args['format'] ?? '';

		// Read-only: due-line math and the pay-link eligibility checks below
		// touch no data and mint nothing, so they're identical whether or
		// not this ends up being a dry run.
		$due_line = TR_Notifications::reminder_due_line( $invoice->due_date );

		$pay_link_available = '' !== TR_Parent_Dashboard::get_url()
			&& TR_IremboPay_Settings::is_enabled()
			&& TR_Payment::is_payable( $invoice );

		$data = [
			'invoice_id'           => $invoice_id,
			'family_id'            => (int) $family->id,
			'parent_name'          => $user->display_name,
			'parent_email'         => $user->user_email,
			'period'               => $invoice->period,
			'status'               => $invoice->status,
			'amount'               => number_format( (float) $invoice->amount, 0 ),
			'currency'             => $invoice->currency,
			'due_date'             => $invoice->due_date,
			'due_line'             => $due_line,
			'pay_link_available'   => $pay_link_available,
			'message_token_status' => TR_Message_Tokens::status_label( $family ),
			'dry_run'              => $dry_run,
			'sent'                 => false,
		];

		if ( $dry_run ) {
			self::output_remind( $data, $format, $assoc_args );
			return;
		}

		$sent = TR_Notifications::send_reminder_email( $invoice_id );
		if ( $sent ) {
			TR_Invoices::record_manual_reminder( $invoice_id );
		}

		// The send above may just have minted a message token — re-read the
		// family row so the status line reflects what actually happened.
		$family_after = TR_Families::get( (int) $family->id ) ?? $family;

		$data['message_token_status'] = TR_Message_Tokens::status_label( $family_after );
		$data['sent']                 = $sent;

		self::output_remind( $data, $format, $assoc_args );

		if ( ! $sent ) {
			WP_CLI::error( 'Reminder failed to send — check the parent has a valid email on file and see the debug log.' );
		}
	}

	/**
	 * Diagnostic: send one real IremboPay invoice for a given product code
	 * and amount, through the exact same TR_IremboPay_API client and
	 * payload shape TR_Payment::create_new() uses for a real payment — to
	 * find out whether IremboPay enforces unitAmount against the product's
	 * registered amount, or accepts any amount against a valid code. That
	 * question decides the whole shape of per-child pricing, and it has
	 * never been tested because every real payment so far happened to send
	 * an amount that already matched the product.
	 *
	 * Writes nothing to wp_tr_invoices — this creates no family, invoice,
	 * or student record of any kind; it only talks to IremboPay.
	 *
	 * ## OPTIONS
	 *
	 * --code=<code>
	 * : The IremboPay product code to test, e.g. PC-02f0b15ac4.
	 *
	 * --amount=<amount>
	 * : Integer RWF amount to send as unitAmount. RWF has no minor units.
	 *
	 * [--dry-run]
	 * : Print the payload that would be sent and make no API call.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest test-product-amount --code=PC-02f0b15ac4 --amount=50
	 *     wp tangnest test-product-amount --code=PC-02f0b15ac4 --amount=60 --dry-run
	 *
	 * @subcommand test-product-amount
	 * @when after_wp_load
	 */
	public function test_product_amount( $args, $assoc_args ): void {
		if ( ! TR_IremboPay_Settings::is_enabled() ) {
			WP_CLI::error( 'Online payments are not enabled, or no IremboPay secret key is configured — set both in Robotics → Settings first.' );
		}

		if ( ! isset( $assoc_args['code'] ) || '' === trim( (string) $assoc_args['code'] ) ) {
			WP_CLI::error( 'Usage: wp tangnest test-product-amount --code=<PC-xxxxxxxxxx> --amount=<integer> [--dry-run]' );
		}
		if ( ! isset( $assoc_args['amount'] ) ) {
			WP_CLI::error( 'Usage: wp tangnest test-product-amount --code=<PC-xxxxxxxxxx> --amount=<integer> [--dry-run]' );
		}

		$code       = trim( (string) $assoc_args['code'] );
		$amount_raw = trim( (string) $assoc_args['amount'] );

		if ( ! preg_match( '/^\d+$/', $amount_raw ) ) {
			WP_CLI::error( 'Amount must be a positive whole number — RWF has no minor units.' );
		}

		$amount = (int) $amount_raw;
		if ( $amount <= 0 ) {
			WP_CLI::error( 'Amount must be a positive whole number.' );
		}

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$transaction_id = 'TEST-' . strtoupper( substr( wp_generate_password( 12, false, false ), 0, 8 ) );
		$expiry_atom    = ( new DateTime( 'now', wp_timezone() ) )->modify( '+1 hour' )->format( DateTime::ATOM );

		$payload = [
			'transactionId'            => $transaction_id,
			'paymentAccountIdentifier' => TR_IremboPay_Settings::payment_account_identifier(),
			'customer'                 => [
				'email'       => get_option( 'admin_email' ),
				'phoneNumber' => '250700000000',
				'name'        => 'Tangnest Diagnostic',
			],
			'paymentItems'             => [
				[
					'code'       => $code,
					'unitAmount' => $amount,
					'quantity'   => 1,
				],
			],
			'description'              => 'IremboPay amount validation test',
			'language'                 => 'EN',
			'expiryAt'                 => $expiry_atom,
		];

		if ( $dry_run ) {
			WP_CLI::line( 'DRY RUN — payload that would be sent (no API call made):' );
			WP_CLI::line( '' );
			WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::warning( 'This creates a real invoice at IremboPay. Unpaid test invoices expire and can be ignored.' );

		// Same client, same proxy, same headers as the real payment flow
		// (TR_Payment::create_new()) — a test that built its own request
		// would prove nothing about what the live path actually sends.
		$api    = new TR_IremboPay_API( TR_IremboPay_Settings::secret_key() );
		$result = $api->create_invoice( $payload );

		$returned_amount = $result['data']['amount'] ?? $result['amount'] ?? null;

		// Never the secret key — only the code, amount sent, and outcome.
		TR_Logger::info( 'IremboPay amount validation test', [
			'product_code'    => $code,
			'amount_sent'     => $amount,
			'success'         => ! empty( $result['success'] ),
			'http_code'       => $result['http_code'] ?? null,
			'returned_amount' => $returned_amount,
		] );

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'http_code: %s', $result['http_code'] ?? '?' ) );
		WP_CLI::line( sprintf( 'success:   %s', ! empty( $result['success'] ) ? 'true' : 'false' ) );
		if ( null !== $returned_amount ) {
			WP_CLI::line( sprintf( 'amount returned by IremboPay: %s', $returned_amount ) );
		}
		if ( ! empty( $result['errors'] ) ) {
			WP_CLI::line( 'errors:' );
			WP_CLI::line( wp_json_encode( $result['errors'], JSON_PRETTY_PRINT ) );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Full response:' );
		WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	/**
	 * One-time migration: 27 legacy packages (one per child-count tier,
	 * the old model) down to 9 real price-table packages (v0.10.1) — see
	 * CONSOLIDATION_KEEP_PACKAGES above for why the mapping is fixed data
	 * rather than parsed from names. Dry run is the default, the opposite
	 * of `generate` — forgetting --execute here must produce nothing,
	 * never a migration against live billing data. Every active family's
	 * resulting amount is simulated and compared before anything is
	 * written; any mismatch, anywhere, aborts the whole run.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Actually write the changes. Without this, prints what would
	 * happen and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tangnest consolidate-packages
	 *     wp tangnest consolidate-packages --execute
	 *
	 * @subcommand consolidate-packages
	 * @when after_wp_load
	 */
	public function consolidate_packages( $args, $assoc_args ): void {
		$execute = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'execute', false );

		if ( self::consolidation_already_done() ) {
			WP_CLI::line( 'Consolidation already appears to have run: package #14 has a complete price table and no family remains on package #16, #20 or #21. Nothing to do.' );
			return;
		}

		$errors = array_merge(
			self::consolidation_check_packages_exist(),
			self::consolidation_check_family_preconditions()
		);

		if ( ! empty( $errors ) ) {
			WP_CLI::line( 'Consolidation aborted — the live data does not match what this command expects:' );
			foreach ( $errors as $error ) {
				WP_CLI::line( '  - ' . $error );
			}
			WP_CLI::error( 'Nothing was changed.' );
		}

		$before_total = self::consolidation_active_families_total();
		[ $amount_mismatches, $simulated ] = self::consolidation_simulate();

		if ( ! empty( $amount_mismatches ) ) {
			WP_CLI::line( 'Consolidation aborted — the following famil(y/ies) would have their amount changed, which must never happen:' );
			foreach ( $amount_mismatches as $m ) {
				WP_CLI::line( sprintf(
					'  - family #%d: %s → %s',
					$m['family_id'],
					self::consolidation_fmt( $m['old'] ),
					null !== $m['new'] ? self::consolidation_fmt( $m['new'] ) : '(no price for this count)'
				) );
			}
			WP_CLI::error( 'Nothing was changed.' );
		}

		$after_total = round( array_sum( array_column( $simulated, 'new_amount' ) ), 2 );
		if ( abs( $after_total - $before_total ) > 0.005 ) {
			WP_CLI::error( sprintf(
				'Consolidation aborted — total active monthly_amount would move from %s to %s RWF.',
				self::consolidation_fmt( $before_total ),
				self::consolidation_fmt( $after_total )
			) );
		}

		self::print_consolidation_plan( $simulated, $before_total );

		if ( ! $execute ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'No changes made. Re-run with --execute to apply.' );
			return;
		}

		self::consolidation_execute();

		$verified_total = self::consolidation_active_families_total();
		WP_CLI::line( '' );
		WP_CLI::success( sprintf(
			'Consolidation applied. Verified total: %s RWF across %d active families.',
			self::consolidation_fmt( $verified_total ),
			TR_Families::count( [ 'status' => 'active' ] )
		) );
	}

	/**
	 * Guard #5 — if package #14 is already fully priced and nobody is
	 * left on the three packages being retired, this has already run;
	 * exit cleanly rather than re-deriving a plan that would just report
	 * every reassignment as "already there".
	 */
	private static function consolidation_already_done(): bool {
		$package_14 = TR_Programs::get( 14 );
		if ( null === $package_14 ) {
			return false;
		}

		$complete = null !== $package_14->price_1_child
			&& null !== $package_14->price_2_children
			&& null !== $package_14->price_3_children;

		if ( ! $complete ) {
			return false;
		}

		global $wpdb;
		$families_table = TR_DB::table_families();
		$stuck = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$families_table} WHERE package_id IN (%d, %d, %d)",
			[ 16, 20, 21 ]
		) );

		return 0 === $stuck;
	}

	/**
	 * Guard #2 — every package ID this command touches (the 9 kept, the
	 * 18 archived — the 3 "from" packages are already inside that
	 * archive list) must exist and currently be active. Collects every
	 * failure rather than stopping at the first.
	 */
	private static function consolidation_check_packages_exist(): array {
		$errors = [];
		$ids    = array_unique( array_merge(
			array_keys( self::CONSOLIDATION_KEEP_PACKAGES ),
			self::CONSOLIDATION_ARCHIVE_PACKAGES
		) );

		foreach ( $ids as $id ) {
			$package = TR_Programs::get( $id );
			if ( null === $package ) {
				$errors[] = sprintf( 'package #%d does not exist', $id );
				continue;
			}
			if ( 'active' !== $package->status ) {
				$errors[] = sprintf( 'package #%d ("%s") is not active (status: %s)', $id, $package->name, $package->status );
			}
		}

		return $errors;
	}

	/**
	 * Guard #1 — every family named in CONSOLIDATION_REASSIGNMENTS must
	 * currently be exactly where this mapping assumes: the stated
	 * package, the stated active child count, the stated monthly_amount.
	 * This command is a one-time migration against known data, and must
	 * assert that data rather than trust it. Collects every mismatch.
	 */
	private static function consolidation_check_family_preconditions(): array {
		$errors = [];

		foreach ( self::CONSOLIDATION_REASSIGNMENTS as $family_id => $plan ) {
			$family = TR_Families::get( $family_id );
			if ( null === $family ) {
				$errors[] = sprintf( 'family #%d does not exist', $family_id );
				continue;
			}

			if ( (int) $family->package_id !== $plan['from'] ) {
				$errors[] = sprintf( 'family #%d: expected to be on package #%d, is actually on #%d', $family_id, $plan['from'], (int) $family->package_id );
			}

			$child_count = TR_Families::active_child_count( $family_id );
			if ( $child_count !== $plan['child_count'] ) {
				$errors[] = sprintf( 'family #%d: expected %d active children, has %d', $family_id, $plan['child_count'], $child_count );
			}

			if ( abs( (float) $family->monthly_amount - $plan['amount'] ) > 0.005 ) {
				$errors[] = sprintf(
					'family #%d: expected monthly_amount %s, has %s',
					$family_id,
					self::consolidation_fmt( $plan['amount'] ),
					self::consolidation_fmt( (float) $family->monthly_amount )
				);
			}
		}

		return $errors;
	}

	private static function consolidation_active_families_total(): float {
		global $wpdb;
		$families_table = TR_DB::table_families();

		return (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(monthly_amount), 0) FROM {$families_table} WHERE status = %s",
			[ 'active' ]
		) );
	}

	/**
	 * The new price table's figure for a child count, read from the fixed
	 * mapping — never from the database, since in a dry run (and even at
	 * the top of an execute run, before anything is written) the real
	 * rows don't have these values yet.
	 */
	private static function consolidation_new_table_price( array $package_def, int $child_count ): ?float {
		if ( $child_count < 1 ) {
			return null;
		}
		if ( $child_count <= 3 ) {
			return [ 1 => $package_def['price1'], 2 => $package_def['price2'], 3 => $package_def['price3'] ][ $child_count ];
		}
		if ( null === $package_def['price3'] || null === $package_def['price_add'] ) {
			return null;
		}

		return $package_def['price3'] + ( $child_count - 3 ) * $package_def['price_add'];
	}

	/**
	 * Guards #3 and #4 — the resulting monthly_amount for every currently
	 * active family (not just the six being reassigned), computed from
	 * the fixed mapping rather than read back from the database. A family
	 * untouched by this consolidation still gets simulated: its package
	 * either isn't changing (so today's real price-table lookup applies
	 * unchanged) or it's one of the kept packages whose table is about to
	 * change, in which case the new table must still land on today's
	 * figure.
	 *
	 * @return array{0: array, 1: array} [ mismatches, every family's simulated row ]
	 */
	private static function consolidation_simulate(): array {
		$mismatches = [];
		$rows       = [];

		foreach ( TR_Families::get_list( [ 'status' => 'active', 'per_page' => 10000 ] ) as $family ) {
			$family_id   = (int) $family->id;
			$old_amount  = (float) $family->monthly_amount;
			$old_package = (int) $family->package_id;
			$child_count = TR_Families::active_child_count( $family_id );

			$plan           = self::CONSOLIDATION_REASSIGNMENTS[ $family_id ] ?? null;
			$new_package_id = $plan['to'] ?? $old_package;
			$negotiated     = null !== $plan ? ( $plan['negotiated'] ?? null ) : null;

			if ( null !== $negotiated ) {
				$new_amount = (float) $negotiated;
			} elseif ( isset( self::CONSOLIDATION_KEEP_PACKAGES[ $new_package_id ] ) ) {
				$new_amount = self::consolidation_new_table_price( self::CONSOLIDATION_KEEP_PACKAGES[ $new_package_id ], $child_count );
			} else {
				// Untouched by this consolidation — including a package
				// about to be archived, which only ever changes status,
				// never its price columns — so today's real lookup is
				// still the right answer.
				$new_amount = TR_Families::price_for_child_count( $new_package_id, $child_count );
			}

			if ( null === $new_amount || abs( $new_amount - $old_amount ) > 0.005 ) {
				$mismatches[] = [ 'family_id' => $family_id, 'old' => $old_amount, 'new' => $new_amount ];
			}

			$user = get_userdata( (int) $family->parent_user_id );

			$rows[] = [
				'family_id'   => $family_id,
				'parent_name' => $user ? $user->display_name : '(no WordPress user)',
				'child_count' => $child_count,
				'old_package' => $old_package,
				'new_package' => $new_package_id,
				'old_amount'  => $old_amount,
				'new_amount'  => $new_amount ?? $old_amount,
				'negotiated'  => null !== $negotiated,
				'reassigned'  => null !== $plan,
			];
		}

		return [ $mismatches, $rows ];
	}

	private static function print_consolidation_plan( array $simulated_rows, float $before_total ): void {
		WP_CLI::line( sprintf( 'Would update %d packages', count( self::CONSOLIDATION_KEEP_PACKAGES ) ) );
		foreach ( self::CONSOLIDATION_KEEP_PACKAGES as $id => $def ) {
			$current = TR_Programs::get( $id );
			WP_CLI::line( sprintf( '  #%d  %s', $id, $current ? $current->name : '(missing)' ) );
			WP_CLI::line( sprintf( '       → %s', $def['name'] ) );
			WP_CLI::line( sprintf(
				'       prices %s / %s / %s / %s',
				number_format( $def['price1'], 0 ),
				number_format( $def['price2'], 0 ),
				number_format( $def['price3'], 0 ),
				null !== $def['price_add'] ? number_format( $def['price_add'], 0 ) : '—'
			) );
		}

		WP_CLI::line( '' );

		$reassign_rows = array_values( array_filter( $simulated_rows, static fn( $r ) => $r['reassigned'] ) );
		WP_CLI::line( sprintf( 'Would reassign %d families', count( $reassign_rows ) ) );
		foreach ( $reassign_rows as $row ) {
			WP_CLI::line( sprintf(
				'  family %-3d  %-18s  %d child%s  #%d → #%d  %s RWF  %s',
				$row['family_id'],
				$row['parent_name'],
				$row['child_count'],
				1 === $row['child_count'] ? '' : 'ren',
				$row['old_package'],
				$row['new_package'],
				number_format( $row['new_amount'], 0 ),
				$row['negotiated'] ? 'unchanged (negotiated)' : 'unchanged'
			) );
		}

		WP_CLI::line( '' );

		WP_CLI::line( sprintf( 'Would archive %d packages', count( self::CONSOLIDATION_ARCHIVE_PACKAGES ) ) );
		WP_CLI::line( '  ' . implode( ', ', array_map( static fn( $id ) => '#' . $id, self::CONSOLIDATION_ARCHIVE_PACKAGES ) ) );

		WP_CLI::line( '' );
		WP_CLI::line( sprintf(
			'Totals  %d families, %s RWF before and after — no change',
			count( $simulated_rows ),
			self::consolidation_fmt( $before_total )
		) );
	}

	/**
	 * The write phase — only ever reached after every guard above has
	 * passed. Transactional: any failure anywhere rolls back everything,
	 * leaving the database exactly as it was before this ran.
	 */
	private static function consolidation_execute(): void {
		global $wpdb;

		$admin_id = get_current_user_id();
		$wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( self::CONSOLIDATION_KEEP_PACKAGES as $id => $def ) {
				$package = TR_Programs::get( $id );
				if ( null === $package ) {
					throw new RuntimeException( "package #{$id} vanished mid-run" );
				}

				$ok = TR_Programs::update( $id, [
					'name'                   => $def['name'],
					'duration_months'        => $def['duration'],
					'default_monthly_fee'    => $package->default_monthly_fee,
					'irembopay_product_code' => $package->irembopay_product_code,
					'course'                 => $def['course'],
					'child_count'            => $package->child_count,
					'price_1_child'          => $def['price1'],
					'price_2_children'       => $def['price2'],
					'price_3_children'       => $def['price3'],
					'price_additional_child' => $def['price_add'],
					'start_date'             => $package->start_date,
					'notes'                  => $package->notes,
					'status'                 => 'active',
				] );

				if ( ! $ok ) {
					throw new RuntimeException( "failed to update package #{$id}" );
				}
			}

			foreach ( self::CONSOLIDATION_REASSIGNMENTS as $family_id => $plan ) {
				$family = TR_Families::get( $family_id );
				if ( null === $family ) {
					throw new RuntimeException( "family #{$family_id} vanished mid-run" );
				}

				$child_count          = TR_Families::active_child_count( $family_id );
				$negotiated_amount    = $plan['negotiated'] ?? null;
				$negotiated_for_count = null !== $negotiated_amount ? $child_count : null;
				$negotiated_note      = $plan['negotiated_note'] ?? '';

				$new_amount = null !== $negotiated_amount
					? (float) $negotiated_amount
					: self::consolidation_new_table_price( self::CONSOLIDATION_KEEP_PACKAGES[ $plan['to'] ], $child_count );

				$ok = TR_Families::update( $family_id, [
					'monthly_amount'             => $new_amount,
					'package_id'                 => $plan['to'],
					'months_paid'                => (int) $family->months_paid,
					'currency'                   => $family->currency,
					'billing_day'                => (int) $family->billing_day,
					'program_start_date'         => $family->program_start_date,
					'program_end_date'           => $family->program_end_date,
					'negotiated_amount'          => $negotiated_amount,
					'negotiated_for_child_count' => $negotiated_for_count,
					'negotiated_note'            => $negotiated_note,
					'status'                     => $family->status,
					'notes'                      => $family->notes,
				] );

				if ( ! $ok ) {
					throw new RuntimeException( "failed to update family #{$family_id}" );
				}

				$old_package_row = TR_Programs::get( $plan['from'] );
				$new_package_row = TR_Programs::get( $plan['to'] );

				TR_Family_Events::record(
					$family_id,
					'package_changed',
					sprintf(
						/* translators: 1: old package name, 2: new package name */
						__( 'Package changed from "%1$s" to "%2$s" (package consolidation)', 'tangnest-robotics' ),
						$old_package_row->name ?? (string) $plan['from'],
						$new_package_row->name ?? (string) $plan['to']
					),
					$old_package_row->name ?? '',
					$new_package_row->name ?? '',
					$admin_id
				);

				if ( null !== $negotiated_amount ) {
					TR_Family_Events::record(
						$family_id,
						'negotiated_set',
						sprintf(
							/* translators: 1: negotiated amount, 2: child count, 3: note */
							__( 'Negotiated rate set to %1$s RWF (%2$d children) — %3$s', 'tangnest-robotics' ),
							number_format( $negotiated_amount, 0 ),
							$negotiated_for_count,
							$negotiated_note
						),
						null,
						number_format( $negotiated_amount, 2, '.', '' ),
						$admin_id
					);
				}
			}

			foreach ( self::CONSOLIDATION_ARCHIVE_PACKAGES as $id ) {
				$package = TR_Programs::get( $id );
				if ( null === $package ) {
					throw new RuntimeException( "package #{$id} vanished mid-run" );
				}

				$ok = TR_Programs::update( $id, [
					'name'                   => $package->name,
					'duration_months'        => $package->duration_months,
					'default_monthly_fee'    => $package->default_monthly_fee,
					'irembopay_product_code' => $package->irembopay_product_code,
					'course'                 => $package->course,
					'child_count'            => $package->child_count,
					'price_1_child'          => $package->price_1_child,
					'price_2_children'       => $package->price_2_children,
					'price_3_children'       => $package->price_3_children,
					'price_additional_child' => $package->price_additional_child,
					'start_date'             => $package->start_date,
					'notes'                  => $package->notes,
					'status'                 => 'inactive',
				] );

				if ( ! $ok ) {
					throw new RuntimeException( "failed to archive package #{$id}" );
				}
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			TR_Logger::error( 'Package consolidation failed, rolled back', [ 'error' => $e->getMessage() ] );
			WP_CLI::error( 'Consolidation failed mid-run and was rolled back: ' . $e->getMessage() );
		}

		$wpdb->query( 'COMMIT' );

		$admin_user = wp_get_current_user();
		TR_Logger::info( 'Package consolidation applied', [
			'packages_updated'    => count( self::CONSOLIDATION_KEEP_PACKAGES ),
			'families_reassigned' => count( self::CONSOLIDATION_REASSIGNMENTS ),
			'packages_archived'   => count( self::CONSOLIDATION_ARCHIVE_PACKAGES ),
			'applied_by'          => $admin_user && $admin_user->exists() ? $admin_user->user_login : 'cli',
		] );
	}

	private static function consolidation_fmt( ?float $amount ): string {
		return null === $amount ? '—' : number_format( $amount, 2 );
	}

	private static function output_remind( array $data, string $format, array $assoc_args ): void {
		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			WP_CLI::print_value( $data, $assoc_args );
			return;
		}

		if ( in_array( $format, [ 'table', 'csv' ], true ) ) {
			$row = array_map( [ __CLASS__, 'scalar_for_display' ], $data );
			\WP_CLI\Utils\format_items( $format, [ $row ], array_keys( $row ) );
			return;
		}

		WP_CLI::line( sprintf( 'Invoice #%d — %s — %s', $data['invoice_id'], $data['period'], $data['status'] ) );
		WP_CLI::line( sprintf( 'To            %s <%s>', $data['parent_name'], $data['parent_email'] ) );
		WP_CLI::line( sprintf( 'Amount        %s %s, due %s', $data['amount'], $data['currency'], $data['due_date'] ) );
		WP_CLI::line( sprintf( 'Message       %s', $data['due_line'] ) );
		WP_CLI::line( sprintf( 'Pay link      %s', $data['pay_link_available'] ? 'would be included' : 'not available' ) );
		WP_CLI::line( sprintf( 'Message token %s', $data['message_token_status'] ) );
		WP_CLI::line( '' );

		if ( $data['dry_run'] ) {
			WP_CLI::line( 'DRY RUN — no email was sent.' );
		} elseif ( $data['sent'] ) {
			WP_CLI::success( 'Reminder sent.' );
		} else {
			WP_CLI::line( 'Reminder was NOT sent.' );
		}
	}

	private static function output_generate_result( array $plan, array $created, array $failed, bool $dry_run, string $format, array $assoc_args ): void {
		$rows = array_map( [ __CLASS__, 'bill_row' ], $dry_run ? $plan['to_bill'] : $created );

		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			$data = [
				'dry_run' => $dry_run,
				'period'  => $plan['period'],
				'today'   => $plan['today'],
				'to_bill' => array_map( [ __CLASS__, 'bill_row' ], $plan['to_bill'] ),
				'created' => array_map( [ __CLASS__, 'bill_row' ], $created ),
				'failed'  => array_values( array_map( static function ( $e ) {
					return $e['family_id'];
				}, $failed ) ),
				'skipped' => array_map( [ __CLASS__, 'skip_row' ], $plan['skipped'] ),
			];
			WP_CLI::print_value( $data, $assoc_args );
			return;
		}

		$table_format = in_array( $format, [ 'table', 'csv' ], true ) ? $format : 'table';
		$total        = array_sum( array_map( static function ( $r ) {
			return (float) str_replace( ',', '', $r['amount'] );
		}, $rows ) );
		$currency = ! empty( $rows ) ? $rows[0]['currency'] : 'RWF';

		if ( empty( $rows ) ) {
			WP_CLI::line( sprintf( '%s 0 invoices for %s.', $dry_run ? 'Would create' : 'Created', $plan['period'] ) );
		} else {
			WP_CLI::line( sprintf(
				'%s %d invoice%s for %s (%s), total %s %s',
				$dry_run ? 'Would create' : 'Created',
				count( $rows ),
				1 === count( $rows ) ? '' : 's',
				$plan['period'],
				self::period_range_label( $plan ),
				number_format( $total, 0 ),
				$currency
			) );
			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( $table_format, $rows, [ 'family', 'parent', 'package', 'amount', 'due' ] );
		}

		if ( ! empty( $failed ) ) {
			WP_CLI::line( '' );
			WP_CLI::warning( sprintf( '%d invoice(s) failed to insert — see the log.', count( $failed ) ) );
		}

		if ( ! empty( $plan['skipped'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Skipped %d famil%s', count( $plan['skipped'] ), 1 === count( $plan['skipped'] ) ? 'y' : 'ies' ) );
			$skip_rows = array_map( [ __CLASS__, 'skip_row' ], $plan['skipped'] );
			\WP_CLI\Utils\format_items( $table_format, $skip_rows, [ 'family', 'reason' ] );
		}

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? 'No changes made. Run without --dry-run to create these.' : 'Done.' );
	}

	private static function bill_row( array $entry ): array {
		$family  = $entry['family'];
		$user    = get_userdata( (int) $family->parent_user_id );
		$package = ! empty( $family->package_id ) ? TR_Programs::get( (int) $family->package_id ) : null;

		return [
			'family'     => $entry['family_id'],
			'parent'     => $user ? $user->display_name : '',
			'package'    => $package ? $package->name : '',
			'children'   => count( $entry['active_students'] ),
			'amount'     => number_format( $entry['amount'], 0 ),
			'currency'   => $entry['currency'],
			'due'        => date_i18n( 'j M', strtotime( $entry['due_date'] ) ),
			'due_date'   => $entry['due_date'],
			'period'     => $entry['period'],
			'invoice_id' => $entry['invoice_id'] ?? null,
		];
	}

	private static function skip_row( array $skip ): array {
		return [
			'family' => $skip['family_id'],
			'code'   => $skip['code'],
			'reason' => $skip['message'],
		];
	}

	private static function period_range_label( array $plan ): string {
		if ( empty( $plan['period_start'] ) || empty( $plan['period_end'] ) ) {
			return $plan['period'];
		}

		return date_i18n( 'j M', strtotime( $plan['period_start'] ) ) . ' – ' . date_i18n( 'j M', strtotime( $plan['period_end'] ) );
	}

	/**
	 * TR_Invoice_Generator::is_billable() for every active family, keyed
	 * by family ID — computed once and shared by next_billing_summary()
	 * (which needs to know what to exclude from the projection) and
	 * cannot_be_billed_rows() (which needs to know what to list, and
	 * why), so the two can never disagree about which families are
	 * stranded.
	 */
	private static function billability_by_family( array $active_families, string $today_str ): array {
		$billability = [];
		foreach ( $active_families as $family ) {
			$billability[ (int) $family->id ] = TR_Invoice_Generator::is_billable( $family, $today_str );
		}
		return $billability;
	}

	/**
	 * An active family that fails is_billable() is a silent revenue
	 * leak — it looks active on the Families screen but will never
	 * actually be invoiced until someone fixes it. Listed explicitly
	 * (family ID, parent name, and the specific reason) rather than
	 * folded into the generic $issues list, since an admin needs to know
	 * exactly which families and why, not just that "some" are affected.
	 */
	private static function cannot_be_billed_rows( array $active_families, array $billability ): array {
		$rows = [];

		foreach ( $active_families as $family ) {
			$result = $billability[ (int) $family->id ];
			if ( $result['billable'] ) {
				continue;
			}

			$user = get_userdata( (int) $family->parent_user_id );

			$rows[] = [
				'family_id'   => (int) $family->id,
				'parent_name' => $user ? $user->display_name : '(no WordPress user)',
				'code'        => $result['code'],
				'reason'      => $result['message'],
			];
		}

		return $rows;
	}

	/**
	 * Every active package missing part of its price-by-child-count table
	 * (v0.10.0) — a family needing the missing count can't be saved until
	 * an admin fills it in on the Packages screen. Superseded v0.9.3's
	 * package-mismatch check, which assumed a package meant one fixed
	 * child count; under the price-table model a package legitimately
	 * covers any count, so that check no longer applies.
	 */
	private static function incomplete_package_rows(): array {
		$rows = [];

		foreach ( TR_Programs::get_list( [ 'status' => 'active', 'per_page' => 10000 ] ) as $package ) {
			$missing = TR_Programs::missing_price_labels( $package );
			if ( empty( $missing ) ) {
				continue;
			}

			$rows[] = [
				'package_id' => (int) $package->id,
				'name'       => $package->name,
				'missing'    => implode( ', ', $missing ),
			];
		}

		return $rows;
	}

	/**
	 * Every active family whose negotiated rate (v0.10.0) no longer
	 * matches its active child count — TR_Families::calculate_amount()
	 * refuses to apply it and refuses to silently fall back to the table,
	 * so the figure currently on the family's monthly_amount is whatever
	 * was last actually written, not necessarily what it would bill next.
	 */
	private static function negotiated_review_rows( array $active_families ): array {
		$rows = [];

		foreach ( $active_families as $family ) {
			if ( ! TR_Families::is_negotiated_stale( $family ) ) {
				continue;
			}

			$user  = get_userdata( (int) $family->parent_user_id );
			$issue = TR_Families::pricing_issue( $family );

			$rows[] = [
				'family_id'   => (int) $family->id,
				'parent_name' => $user ? $user->display_name : '(no WordPress user)',
				'reason'      => null !== $issue ? TR_Families::pricing_issue_message( $issue ) : '',
			];
		}

		return $rows;
	}

	/**
	 * The nearest upcoming billing date among active, currently-billable
	 * families with a set billing anchor, and how many share it —
	 * TR_Families::next_billing_date() already knows how to project one
	 * family's anchor forward, this just finds the earliest result and
	 * counts ties.
	 *
	 * "Currently billable" is TR_Invoice_Generator::is_billable() — the
	 * same has-package/has-children/positive-amount/programme-not-ended
	 * gate generation itself uses, so a family stranded with a stale
	 * billing anchor but no package (or no active children, or a zero
	 * amount) doesn't surface here as a phantom "next billing" date it
	 * will never actually reach.
	 */
	private static function next_billing_summary( array $active_families, array $billability ): array {
		$by_date = [];

		foreach ( $active_families as $family ) {
			if ( (int) $family->billing_day < 1 ) {
				continue;
			}

			if ( ! $billability[ (int) $family->id ]['billable'] ) {
				continue;
			}

			$date = TR_Families::next_billing_date( (int) $family->id );
			if ( null === $date ) {
				continue;
			}

			$by_date[ $date ] = ( $by_date[ $date ] ?? 0 ) + 1;
		}

		if ( empty( $by_date ) ) {
			return [ null, 0 ];
		}

		ksort( $by_date );
		$next_date = array_key_first( $by_date );

		return [ $next_date, $by_date[ $next_date ] ];
	}

	private static function print_status_text( array $data ): void {
		WP_CLI::line( sprintf( 'Tangnest Robotics %s  (DB %s)', $data['version'], $data['db_version'] ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Families      %d active, %d inactive', $data['families_active'], $data['families_inactive'] ) );
		WP_CLI::line( sprintf( 'Children      %d', $data['children_active'] ) );
		WP_CLI::line( sprintf( 'Packages      %d active', $data['packages_active'] ) );
		WP_CLI::line( sprintf( 'Invoices      %d pending, %d paid, %d overdue', $data['invoices_pending'], $data['invoices_paid'], $data['invoices_overdue'] ) );
		WP_CLI::line( sprintf( 'Outstanding   %s %s', number_format( $data['outstanding_amount'], 0 ), $data['currency'] ) );
		WP_CLI::line( sprintf( 'Collected     %s %s this month', number_format( $data['collected_this_month'], 0 ), $data['currency'] ) );
		WP_CLI::line( '' );

		if ( $data['next_billing_date'] ) {
			WP_CLI::line( sprintf(
				'Next billing  %s — %d famil%s due',
				date_i18n( 'j M Y', strtotime( $data['next_billing_date'] ) ),
				$data['next_billing_family_count'],
				1 === $data['next_billing_family_count'] ? 'y' : 'ies'
			) );
		} else {
			WP_CLI::line( 'Next billing  no active family has a billing day set yet' );
		}

		WP_CLI::line( sprintf(
			'Cron          %s, %s',
			$data['cron_hook'],
			$data['cron_scheduled'] ? 'next run ' . wp_date( 'j M H:i', $data['cron_next_run_ts'] ) : 'NOT SCHEDULED'
		) );

		$pay_state = $data['online_pay_ready']
			? 'enabled'
			: ( $data['online_pay_enabled'] ? 'enabled (no secret key)' : 'disabled' );
		WP_CLI::line( sprintf(
			'Online pay    %s, %s',
			$pay_state,
			$data['online_pay_default_code_set'] ? 'product code set' : 'no default product code'
		) );

		WP_CLI::line( sprintf( 'Debug log     %s', $data['debug_log'] ? 'on' : 'off' ) );
		WP_CLI::line( '' );

		if ( empty( $data['issues'] ) ) {
			WP_CLI::line( 'No issues found.' );
		} else {
			WP_CLI::line( sprintf( '%d issue(s):', count( $data['issues'] ) ) );
			foreach ( $data['issues'] as $issue ) {
				WP_CLI::line( '  - ' . $issue );
			}
		}

		WP_CLI::line( '' );

		// An active family failing is_billable() looks fine on the Families
		// screen and is otherwise invisible — this is deliberately always
		// printed, not folded into "No issues found.", so it can never be
		// silently skipped past the way it was before is_billable() was
		// shared with the "next billing" projection.
		if ( empty( $data['cannot_be_billed'] ) ) {
			WP_CLI::line( 'Cannot be billed: none.' );
		} else {
			WP_CLI::line( sprintf( 'Cannot be billed (%d)', count( $data['cannot_be_billed'] ) ) );
			foreach ( $data['cannot_be_billed'] as $row ) {
				WP_CLI::line( sprintf( '  #%d  %s  %s', $row['family_id'], $row['parent_name'], $row['reason'] ) );
			}
		}

		WP_CLI::line( '' );

		if ( empty( $data['incomplete_packages'] ) ) {
			WP_CLI::line( 'Packages with incomplete pricing: none.' );
		} else {
			WP_CLI::line( sprintf( 'Packages with incomplete pricing (%d)', count( $data['incomplete_packages'] ) ) );
			foreach ( $data['incomplete_packages'] as $row ) {
				WP_CLI::line( sprintf( '  #%d  %s  missing %s', $row['package_id'], $row['name'], $row['missing'] ) );
			}
		}

		WP_CLI::line( '' );

		if ( empty( $data['negotiated_needs_review'] ) ) {
			WP_CLI::line( 'Negotiated rates needing review: none.' );
			return;
		}

		WP_CLI::line( sprintf( 'Negotiated rates needing review (%d)', count( $data['negotiated_needs_review'] ) ) );
		foreach ( $data['negotiated_needs_review'] as $row ) {
			WP_CLI::line( sprintf( '  #%d  %s  %s', $row['family_id'], $row['parent_name'], $row['reason'] ) );
		}
	}

	private static function print_family_text( array $data ): void {
		WP_CLI::line( sprintf( 'Family #%d (%s)', $data['id'], $data['status'] ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Parent        %s <%s>', $data['parent_name'], $data['parent_email'] ) );
		WP_CLI::line( sprintf( 'Phone         %s', $data['parent_phone'] ?: '(none)' ) );
		WP_CLI::line( sprintf( 'Package       %s', $data['package'] ) );
		WP_CLI::line( sprintf( 'Amount        %s %s / month', $data['monthly_amount'], $data['currency'] ) );
		WP_CLI::line( sprintf( 'Billing day   %s', $data['billing_day'] > 0 ? $data['billing_day'] : '(not yet anchored)' ) );
		WP_CLI::line( sprintf( 'Programme     %s to %s', $data['program_start_date'] ?? '(not set)', $data['program_end_date'] ?? '(not set)' ) );
		WP_CLI::line( sprintf( 'Progress      %s', $data['progress'] ) );
		WP_CLI::line( sprintf( 'Access link   %s', $data['access_token_status'] ) );
		WP_CLI::line( sprintf( 'Message link  %s', $data['message_token_status'] ) );
		WP_CLI::line( '' );

		WP_CLI::line( sprintf( 'Children (%d)', count( $data['children'] ) ) );
		if ( empty( $data['children'] ) ) {
			WP_CLI::line( '  (none)' );
		} else {
			foreach ( $data['children'] as $child ) {
				WP_CLI::line( sprintf( '  #%d  %s  (%s)', $child['id'], $child['name'], $child['status'] ) );
			}
		}
		WP_CLI::line( '' );

		WP_CLI::line( sprintf( 'Invoices (%d)', count( $data['invoices'] ) ) );
		if ( empty( $data['invoices'] ) ) {
			WP_CLI::line( '  (none)' );
		} else {
			foreach ( $data['invoices'] as $invoice ) {
				WP_CLI::line( sprintf(
					'  #%d  %s  %-10s %s %s  due %s%s',
					$invoice['id'],
					$invoice['period'],
					$invoice['status'],
					$invoice['amount'],
					$invoice['currency'],
					$invoice['due_date'],
					$invoice['paid_at'] ? '  paid ' . $invoice['paid_at'] : ''
				) );
			}
		}
		WP_CLI::line( '' );

		WP_CLI::line( sprintf( 'History (%d)', count( $data['history'] ) ) );
		if ( empty( $data['history'] ) ) {
			WP_CLI::line( '  (none)' );
			return;
		}

		foreach ( $data['history'] as $event ) {
			WP_CLI::line( sprintf( '  %s  %s — %s', $event['date'], $event['summary'], $event['by'] ) );
		}
	}

	private static function scalar_for_display( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( null === $value ) {
			return '';
		}
		if ( is_array( $value ) ) {
			return (string) count( $value );
		}
		return (string) $value;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'tangnest', 'TR_CLI_Command' );
}
