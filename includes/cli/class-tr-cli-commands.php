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

		$active_families = TR_Families::get_list( [ 'status' => 'active', 'per_page' => 10000 ] );
		$families_without_package = array_values( array_filter( $active_families, static function ( $f ) {
			return empty( $f->package_id );
		} ) );

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

		[ $next_billing_date, $next_billing_count ] = self::next_billing_summary( $active_families );

		$cron_next_ts = wp_next_scheduled( TR_Cron::HOOK );

		$irembopay          = TR_IremboPay_Settings::get();
		$online_pay_enabled = ! empty( $irembopay['enabled'] );
		$online_pay_ready   = TR_IremboPay_Settings::is_enabled();

		$debug_on = TR_Logger::debug_enabled();

		$issues = [];
		foreach ( $families_without_package as $f ) {
			$issues[] = sprintf( 'family %d has no package assigned', (int) $f->id );
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
		];

		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			WP_CLI::print_value( $data, $assoc_args );
			return;
		}

		if ( in_array( $format, [ 'table', 'csv' ], true ) ) {
			$rows = [];
			foreach ( $data as $key => $value ) {
				if ( 'issues' === $key ) {
					continue;
				}
				$rows[] = [ 'metric' => $key, 'value' => self::scalar_for_display( $value ) ];
			}
			foreach ( $data['issues'] as $issue ) {
				$rows[] = [ 'metric' => 'issue', 'value' => $issue ];
			}
			\WP_CLI\Utils\format_items( $format, $rows, [ 'metric', 'value' ] );
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
		];

		$format = $assoc_args['format'] ?? '';

		if ( in_array( $format, [ 'json', 'yaml' ], true ) ) {
			WP_CLI::print_value( $data, $assoc_args );
			return;
		}

		if ( in_array( $format, [ 'table', 'csv' ], true ) ) {
			$summary = $data;
			unset( $summary['children'], $summary['invoices'] );
			$row = array_map( [ __CLASS__, 'scalar_for_display' ], $summary );
			\WP_CLI\Utils\format_items( $format, [ $row ], array_keys( $row ) );

			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( $format, $data['children'], [ 'id', 'name', 'status' ] );

			WP_CLI::line( '' );
			\WP_CLI\Utils\format_items( $format, $data['invoices'], [ 'id', 'period', 'status', 'amount', 'currency', 'due_date', 'paid_at' ] );
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
	private static function next_billing_summary( array $active_families ): array {
		$today_str = current_time( 'Y-m-d' );
		$by_date   = [];

		foreach ( $active_families as $family ) {
			if ( (int) $family->billing_day < 1 ) {
				continue;
			}

			if ( ! TR_Invoice_Generator::is_billable( $family, $today_str )['billable'] ) {
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
			return;
		}

		WP_CLI::line( sprintf( '%d issue(s):', count( $data['issues'] ) ) );
		foreach ( $data['issues'] as $issue ) {
			WP_CLI::line( '  - ' . $issue );
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
			return;
		}

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
