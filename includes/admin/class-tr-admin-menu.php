<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the Robotics admin menu and routes each submenu between its
 * list view and its add/edit form. Form POST handling happens on
 * admin_init (before any output) so each handler can redirect after save.
 */
class TR_Admin_Menu {
	const CAP = 'manage_options';

	const PAGE_FAMILIES = 'tangnest-robotics-families';
	const PAGE_STUDENTS = 'tangnest-robotics-students';
	const PAGE_PACKAGES = 'tangnest-robotics-packages';
	const PAGE_INVOICES = 'tangnest-robotics-invoices';
	const PAGE_SETTINGS = 'tangnest-robotics-settings';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'admin_body_class', [ $this, 'filter_admin_body_class' ] );
		add_action( 'admin_notices', [ $this, 'render_composition_notices' ] );
		add_action( 'admin_notices', [ $this, 'render_dashboard_page_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_webhook_secret_mismatch_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_families_without_package_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_action_notices' ] );

		add_action( 'admin_init', [ 'TR_Family_Edit', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Student_Edit', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Packages_Page', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Packages_Page', 'maybe_handle_row_actions' ] );
		add_action( 'admin_init', [ 'TR_Settings_Page', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Invoice_Actions', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Invoice_Actions', 'maybe_handle_row_actions' ] );
		add_action( 'admin_init', [ 'TR_Invoice_Actions', 'maybe_handle_bulk_actions' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_row_actions' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_family_row_actions' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Robotics', 'tangnest-robotics' ),
			__( 'Robotics', 'tangnest-robotics' ),
			self::CAP,
			self::PAGE_FAMILIES,
			[ $this, 'render_families_page' ],
			'dashicons-groups',
			30
		);

		add_submenu_page( self::PAGE_FAMILIES, __( 'Families', 'tangnest-robotics' ), __( 'Families', 'tangnest-robotics' ), self::CAP, self::PAGE_FAMILIES, [ $this, 'render_families_page' ] );
		add_submenu_page( self::PAGE_FAMILIES, __( 'Students', 'tangnest-robotics' ), __( 'Students', 'tangnest-robotics' ), self::CAP, self::PAGE_STUDENTS, [ $this, 'render_students_page' ] );
		add_submenu_page( self::PAGE_FAMILIES, __( 'Packages', 'tangnest-robotics' ), __( 'Packages', 'tangnest-robotics' ), self::CAP, self::PAGE_PACKAGES, [ $this, 'render_packages_page' ] );
		add_submenu_page( self::PAGE_FAMILIES, __( 'Invoices', 'tangnest-robotics' ), __( 'Invoices', 'tangnest-robotics' ), self::CAP, self::PAGE_INVOICES, [ $this, 'render_invoices_page' ] );
		add_submenu_page( self::PAGE_FAMILIES, __( 'Settings', 'tangnest-robotics' ), __( 'Settings', 'tangnest-robotics' ), self::CAP, self::PAGE_SETTINGS, [ $this, 'render_settings_page' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'tangnest-robotics' ) ) {
			return;
		}

		wp_enqueue_style(
			'tangnest-robotics-admin',
			TANGNEST_ROBOTICS_PLUGIN_URL . 'assets/css/admin.css',
			[],
			TANGNEST_ROBOTICS_VERSION
		);
	}

	/**
	 * Scopes the always-visible-row-actions and pill-action CSS to only
	 * this plugin's own screens — WooCommerce and Tutor LMS share this
	 * install and must never see it.
	 */
	public function filter_admin_body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'tangnest-robotics' ) ) {
			$classes .= ' tangnest-robotics-page ';
		}

		return $classes;
	}

	public function render_families_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( in_array( $action, [ 'add', 'edit' ], true ) ) {
			TR_Family_Edit::render();
			return;
		}

		if ( 'create_invoice' === $action && $id > 0 ) {
			TR_Invoice_Actions::render_create_invoice_form( $id );
			return;
		}

		$this->render_families_list();
	}

	private function render_families_list(): void {
		$table = new TR_Families_Table();
		$table->prepare_items();

		$copy_link = null;
		if ( isset( $_GET['tr_show_link'] ) ) {
			$key       = 'tr_copy_link_' . get_current_user_id();
			$copy_link = get_transient( $key );
			if ( $copy_link ) {
				delete_transient( $key );
			}
		}
		?>
		<div class="wrap tr-admin-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Families', 'tangnest-robotics' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_FAMILIES, 'action' => 'add' ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'tangnest-robotics' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( $copy_link ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong>
							<?php
							echo $copy_link['regenerated']
								? esc_html__( 'New access link generated — the previous link has stopped working.', 'tangnest-robotics' )
								: esc_html__( 'Here is the family\'s current access link.', 'tangnest-robotics' );
							?>
						</strong>
					</p>
					<p>
						<input type="text" readonly value="<?php echo esc_attr( $copy_link['url'] ); ?>" id="tr-copy-link-field" class="large-text" onclick="this.select();" style="max-width:480px;">
						<button type="button" class="button" onclick="var f=document.getElementById('tr-copy-link-field'); f.select(); f.setSelectionRange(0,99999); document.execCommand('copy');"><?php esc_html_e( 'Copy', 'tangnest-robotics' ); ?></button>
					</p>
				</div>
			<?php elseif ( isset( $_GET['tr_show_link'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Could not generate a link — check that a dashboard page is configured under Robotics → Settings.', 'tangnest-robotics' ); ?></p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_FAMILIES ); ?>">
				<?php
				$table->views();
				$table->search_box( __( 'Search families', 'tangnest-robotics' ), 'tr-families' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_students_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		if ( in_array( $action, [ 'add', 'edit' ], true ) ) {
			TR_Student_Edit::render();
			return;
		}

		$this->render_students_list();
	}

	private function render_students_list(): void {
		$table = new TR_Students_Table();
		$table->prepare_items();
		?>
		<div class="wrap tr-admin-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Students', 'tangnest-robotics' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_STUDENTS, 'action' => 'add' ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'tangnest-robotics' ); ?></a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_STUDENTS ); ?>">
				<?php
				$table->views();
				$table->search_box( __( 'Search students', 'tangnest-robotics' ), 'tr-students' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_packages_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		TR_Packages_Page::render();
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		TR_Settings_Page::render();
	}

	public function render_invoices_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( 'record_payment' === $action && $id > 0 ) {
			TR_Invoice_Actions::render_record_payment_form( $id );
			return;
		}

		if ( 'waive' === $action && $id > 0 ) {
			TR_Invoice_Actions::render_waive_form( $id );
			return;
		}

		$this->render_invoices_list();
	}

	private function render_invoices_list(): void {
		$table = new TR_Invoices_Table();
		$table->prepare_items();
		?>
		<div class="wrap tr-admin-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Invoices', 'tangnest-robotics' ); ?></h1>
			<hr class="wp-header-end">

			<?php $table->render_summary_bar(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_INVOICES ); ?>">
				<?php
				$table->views();
				$table->search_box( __( 'Search by parent name', 'tangnest-robotics' ), 'tr-invoices' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles every row action from the Families table's parent-name column:
	 * resend the welcome email, send/copy/revoke a passwordless access
	 * link. WhatsApp send is the one branch that redirects off-site.
	 */
	public function maybe_handle_family_row_actions(): void {
		if ( ! isset( $_GET['tr_row_action'], $_GET['id'], $_GET['page'] ) ) {
			return;
		}

		if ( self::PAGE_FAMILIES !== $_GET['page'] ) {
			return;
		}

		$row_action    = sanitize_key( wp_unslash( $_GET['tr_row_action'] ) );
		$family_id     = absint( $_GET['id'] );
		$valid_actions = [ 'resend_welcome', 'send_link_whatsapp', 'send_link_email', 'copy_link', 'regenerate_link', 'revoke_link', 'delete' ];

		if ( ! in_array( $row_action, $valid_actions, true ) || $family_id <= 0 ) {
			return;
		}

		check_admin_referer( 'tr_family_row_action_' . $family_id );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$family = TR_Families::get( $family_id );

		if ( 'resend_welcome' === $row_action ) {
			$sent = null !== $family && TR_Notifications::resend_welcome_email( (int) $family->parent_user_id, $family_id );
			$this->redirect_with_notice( $sent ? 'welcome_sent' : 'welcome_failed' );
		}

		if ( 'send_link_email' === $row_action ) {
			$sent = false;
			if ( null !== $family ) {
				$user = get_userdata( (int) $family->parent_user_id );
				if ( $user ) {
					$sent = TR_Notifications::send_access_link_email( $user, $family_id );
				}
			}
			$this->redirect_with_notice( $sent ? 'access_email_sent' : 'access_email_failed' );
		}

		if ( 'send_link_whatsapp' === $row_action ) {
			$whatsapp_url = null !== $family ? $this->build_access_link_whatsapp_url( $family ) : '';
			if ( '' === $whatsapp_url ) {
				$this->redirect_with_notice( 'whatsapp_failed' );
			}
			// Deliberately a raw header() call — not wp_redirect() or
			// wp_safe_redirect(), and NOT esc_url()'d. Both WP redirect
			// functions run the Location value through wp_sanitize_redirect(),
			// which strips every "%0d"/"%0a" (either case) as a defense
			// against CRLF header-injection — that silently deletes the
			// WhatsApp message's line breaks. esc_url() would additionally
			// uppercase any surviving "%0a" to "%0A", which WhatsApp Web
			// also ignores. Safe to bypass both here: every byte of
			// $whatsapp_url is our own construction (fixed host, per-line
			// urlencode()'d text, a phone number already validated against
			// /^07\d{8}$/) — there is no untrusted input that could smuggle
			// a raw CR/LF into the header.
			header( 'Location: ' . $whatsapp_url );
			exit;
		}

		if ( 'copy_link' === $row_action ) {
			if ( null !== $family ) {
				$url = TR_Access_Tokens::get_or_generate_url( $family_id );
				if ( '' !== $url ) {
					$this->store_copy_link( $url, false );
				}
			}
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_FAMILIES, 'tr_show_link' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'regenerate_link' === $row_action ) {
			if ( null !== $family ) {
				$url = TR_Access_Tokens::regenerate_url( $family_id );
				if ( '' !== $url ) {
					$this->store_copy_link( $url, true );
				}
			}
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_FAMILIES, 'tr_show_link' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'revoke_link' === $row_action ) {
			if ( null !== $family ) {
				TR_Access_Tokens::revoke( $family_id );
			}
			$this->redirect_with_notice( 'link_revoked' );
		}

		if ( 'delete' === $row_action ) {
			if ( null === $family ) {
				$this->redirect_with_notice( 'family_delete_failed' );
			}

			// Re-checked here regardless of what the row action link was
			// rendered for — paid invoices are the financial record and
			// must never be orphaned by a deleted family.
			$paid_count = TR_Invoices::count( [ 'family_id' => $family_id, 'status' => 'paid' ] );
			if ( $paid_count > 0 ) {
				$this->redirect_with_notice( 'family_delete_has_paid_invoices' );
			}

			$user        = get_userdata( (int) $family->parent_user_id );
			$child_count = TR_Families::delete_with_dependents( $family_id );

			TR_Logger::info( 'Family permanently deleted', [
				'family_id'   => $family_id,
				'parent_name' => $user ? $user->display_name : '(unknown)',
				'child_count' => $child_count,
				'deleted_by'  => wp_get_current_user()->user_login,
			] );

			$this->redirect_with_notice( 'family_deleted' );
		}
	}

	private function store_copy_link( string $url, bool $regenerated ): void {
		set_transient(
			'tr_copy_link_' . get_current_user_id(),
			[ 'url' => $url, 'regenerated' => $regenerated ],
			MINUTE_IN_SECONDS
		);
	}

	private function redirect_with_notice( string $notice ): void {
		wp_safe_redirect( add_query_arg( [
			'page'      => self::PAGE_FAMILIES,
			'tr_notice' => $notice,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Builds the pre-filled WhatsApp message from spec Task 3, carrying a
	 * fresh access link (never a password-reset key), via the shared
	 * TR_Notifications::build_whatsapp_message_url() helper (also used by
	 * the invoice-reminder WhatsApp send in TR_Invoice_Actions). Logs both
	 * outcomes — matching the 'Email sent' / 'Email failed to send' pair in
	 * TR_Notifications::send() — since this channel has no other choke
	 * point to log from.
	 */
	private function build_access_link_whatsapp_url( object $family ): string {
		$phone = get_user_meta( (int) $family->parent_user_id, 'phone_number', true );
		if ( ! preg_match( '/^07\d{8}$/', $phone ) ) {
			TR_Logger::error( 'WhatsApp access link not sent: no valid phone number on file', [ 'family_id' => $family->id ] );
			return '';
		}

		$user = get_userdata( (int) $family->parent_user_id );
		if ( ! $user ) {
			TR_Logger::error( 'WhatsApp access link not sent: parent user not found', [ 'family_id' => $family->id ] );
			return '';
		}

		$access_url = TR_Access_Tokens::get_or_generate_url( (int) $family->id );
		if ( '' === $access_url ) {
			TR_Logger::error( 'WhatsApp access link not sent: no dashboard page configured', [ 'family_id' => $family->id ] );
			return '';
		}

		$lines = [
			sprintf( __( 'Hello %s,', 'tangnest-robotics' ), $user->display_name ),
			__( 'Here is your Tangnest Robotics parent page. It shows your children and their class progress.', 'tangnest-robotics' ),
			$access_url,
			__( 'Open it on the phone you want to use. The link stops working after a short while — if you lose it, just ask us for a new one.', 'tangnest-robotics' ),
		];

		$url = TR_Notifications::build_whatsapp_message_url( $phone, $lines );

		if ( '' !== $url ) {
			TR_Logger::info( 'WhatsApp access link sent', [ 'family_id' => $family->id ] );
		} else {
			TR_Logger::error( 'WhatsApp access link not sent: could not build message URL', [ 'family_id' => $family->id ] );
		}

		return $url;
	}

	public function render_action_notices(): void {
		if ( ! isset( $_GET['tr_notice'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || false === strpos( $screen->id, 'tangnest-robotics' ) ) {
			return;
		}

		$notice   = sanitize_key( wp_unslash( $_GET['tr_notice'] ) );
		$messages = [
			'welcome_sent'            => [ 'success', __( 'Welcome email sent.', 'tangnest-robotics' ) ],
			'welcome_failed'          => [ 'error', __( 'Welcome email failed to send. Check WP Mail SMTP → Email Log.', 'tangnest-robotics' ) ],
			'access_email_sent'       => [ 'success', __( 'Access link email sent.', 'tangnest-robotics' ) ],
			'access_email_failed'     => [ 'error', __( 'Access link email failed to send. Check WP Mail SMTP → Email Log.', 'tangnest-robotics' ) ],
			'whatsapp_failed'         => [ 'error', __( 'Could not build a WhatsApp link — check the parent has a valid phone number on file and that a dashboard page is configured.', 'tangnest-robotics' ) ],
			'link_revoked'            => [ 'success', __( 'Access link revoked.', 'tangnest-robotics' ) ],
			'payment_recorded'        => [ 'success', __( 'Payment recorded.', 'tangnest-robotics' ) ],
			'invoice_waived'          => [ 'success', __( 'Invoice waived.', 'tangnest-robotics' ) ],
			'invoice_cancelled'       => [ 'success', __( 'Invoice cancelled.', 'tangnest-robotics' ) ],
			'reminder_sent'           => [ 'success', __( 'Reminder email sent.', 'tangnest-robotics' ) ],
			'reminder_failed'         => [ 'error', __( 'Reminder email failed to send. Check WP Mail SMTP → Email Log.', 'tangnest-robotics' ) ],
			'whatsapp_reminder_failed' => [ 'error', __( 'Could not build a WhatsApp reminder — check the parent has a valid phone number on file.', 'tangnest-robotics' ) ],
			'marked_overdue'          => [ 'success', __( 'Selected invoices marked overdue.', 'tangnest-robotics' ) ],
			'no_invoices_selected'    => [ 'error', __( 'No invoices were selected.', 'tangnest-robotics' ) ],
			'invoice_created'         => [ 'success', __( 'Invoice created.', 'tangnest-robotics' ) ],
			'invoice_create_failed'   => [ 'error', __( 'Could not create invoice — an invoice for that period may already exist.', 'tangnest-robotics' ) ],
			'invoice_deleted'         => [ 'success', __( 'Invoice permanently deleted.', 'tangnest-robotics' ) ],
			'invoice_delete_failed'   => [ 'error', __( 'That invoice could not be deleted — only cancelled invoices can be deleted.', 'tangnest-robotics' ) ],
			'family_deleted'          => [ 'success', __( 'Family permanently deleted.', 'tangnest-robotics' ) ],
			'family_delete_failed'    => [ 'error', __( 'That family could not be found.', 'tangnest-robotics' ) ],
			'family_delete_has_paid_invoices' => [ 'error', __( 'That family could not be deleted — it has a paid invoice, which is the financial record and must never be removed.', 'tangnest-robotics' ) ],
		];

		if ( 'invoices_bulk_deleted' === $notice ) {
			$deleted = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0;
			$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;

			$text = $skipped > 0
				? sprintf(
					/* translators: 1: number deleted, 2: number skipped */
					_n( '%1$d invoice deleted, %2$d skipped (not cancelled).', '%1$d invoices deleted, %2$d skipped (not cancelled).', $deleted, 'tangnest-robotics' ),
					$deleted,
					$skipped
				)
				: sprintf(
					/* translators: %d: number deleted */
					_n( '%d invoice deleted.', '%d invoices deleted.', $deleted, 'tangnest-robotics' ),
					$deleted
				);

			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $skipped > 0 ? 'warning' : 'success' ), esc_html( $text ) );
			return;
		}

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $type, $text ] = $messages[ $notice ];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/**
	 * Warns rather than silently overriding anything — clearing this
	 * plugin's webhook secret (so the reused signature check is skipped
	 * entirely, matching what IremboPay is actually sending) is a
	 * deliberate admin action taken under Settings, not something the
	 * code should decide on its own.
	 */
	public function render_webhook_secret_mismatch_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || false === strpos( $screen->id, 'tangnest-robotics' ) ) {
			return;
		}

		if ( ! TR_IremboPay_Settings::has_webhook_secret_mismatch() ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to the settings page */
					esc_html__( 'IremboPay webhook secret mismatch: this plugin has a webhook secret saved, but the WooCommerce IremboPay plugin — which owns the only callback URL IremboPay is actually configured to call — has none. IremboPay is therefore not sending a signature, and every real webhook will be rejected with a 401 until you clear the secret under %s.', 'tangnest-robotics' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Robotics → Settings', 'tangnest-robotics' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * A family with no package can never be billed correctly and its Pay
	 * button is hidden everywhere — this is the admin-facing counterpart
	 * so it doesn't just go silently unnoticed.
	 */
	public function render_families_without_package_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || false === strpos( $screen->id, 'tangnest-robotics' ) ) {
			return;
		}

		global $wpdb;
		$families_table = TR_DB::table_families();
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$families_table} WHERE status = %s AND package_id IS NULL",
			[ 'active' ]
		) );

		if ( $count <= 0 ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: 1: number of families, 2: link to the Families screen */
					esc_html( _n(
						'%1$d active family has no package selected — it cannot be billed or shown a Pay button until you assign one. %2$s',
						'%1$d active families have no package selected — they cannot be billed or shown a Pay button until you assign one. %2$s',
						$count,
						'tangnest-robotics'
					) ),
					$count,
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_FAMILIES ) ) . '">' . esc_html__( 'Review families', 'tangnest-robotics' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	public function render_dashboard_page_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || false === strpos( $screen->id, 'tangnest-robotics' ) ) {
			return;
		}

		if ( '' !== TR_Parent_Dashboard::get_url() ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: link to the settings page */
					esc_html__( 'No parent dashboard page is set — the welcome email cannot link anywhere until you choose one under %s.', 'tangnest-robotics' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Robotics → Settings', 'tangnest-robotics' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handles the Withdraw / Reactivate row-action links from the Students
	 * table. Nonce + capability are re-verified here even though the link
	 * is only ever rendered for users who already have the capability.
	 */
	public function maybe_handle_row_actions(): void {
		if ( ! isset( $_GET['tr_row_action'], $_GET['id'], $_GET['page'] ) ) {
			return;
		}

		if ( self::PAGE_STUDENTS !== $_GET['page'] ) {
			return;
		}

		$row_action = sanitize_key( wp_unslash( $_GET['tr_row_action'] ) );
		$student_id = absint( $_GET['id'] );

		if ( ! in_array( $row_action, [ 'withdraw', 'reactivate' ], true ) || $student_id <= 0 ) {
			return;
		}

		check_admin_referer( 'tr_student_row_action_' . $student_id );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$student = TR_Students::get( $student_id );
		if ( null === $student ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_STUDENTS ) );
			exit;
		}

		$new_status = 'withdraw' === $row_action ? 'withdrawn' : 'active';

		TR_Students::update( $student_id, [
			'first_name'    => $student->first_name,
			'last_name'     => $student->last_name,
			'date_of_birth' => $student->date_of_birth,
			'school'        => $student->school,
			'status'        => $new_status,
			'notes'         => $student->notes,
		] );

		// Enrollments are historical only (v0.8.0) — a withdrawal no longer
		// touches one, since progress and billing both live on the family.
		TR_Families::flag_composition_change( (int) $student->family_id );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_STUDENTS ) );
		exit;
	}

	public function render_composition_notices(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || false === strpos( $screen->id, 'tangnest-robotics' ) ) {
			return;
		}

		$family_ids = get_option( TR_Families::REVIEW_OPTION, [] );
		if ( ! is_array( $family_ids ) || empty( $family_ids ) ) {
			return;
		}

		foreach ( $family_ids as $family_id ) {
			$family = TR_Families::get( (int) $family_id );
			if ( null === $family ) {
				TR_Families::clear_composition_flag( (int) $family_id );
				continue;
			}

			$user = get_userdata( (int) $family->parent_user_id );
			$name = $user ? $user->display_name : __( '(unknown parent)', 'tangnest-robotics' );

			$review_url = add_query_arg(
				[ 'page' => self::PAGE_FAMILIES, 'action' => 'edit', 'id' => $family->id ],
				admin_url( 'admin.php' )
			);
			?>
			<div class="notice notice-warning">
				<p>
					<?php
					printf(
						/* translators: 1: parent name, 2: monthly amount */
						esc_html__( 'Family composition changed for %1$s. Current monthly amount: %2$s RWF.', 'tangnest-robotics' ),
						esc_html( $name ),
						esc_html( $family->monthly_amount )
					);
					?>
					<a href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Review', 'tangnest-robotics' ); ?></a>
				</p>
			</div>
			<?php
		}
	}
}
