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
	const PAGE_PROGRAMS = 'tangnest-robotics-programs';
	const PAGE_SETTINGS = 'tangnest-robotics-settings';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_composition_notices' ] );
		add_action( 'admin_notices', [ $this, 'render_dashboard_page_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_action_notices' ] );

		add_action( 'admin_init', [ 'TR_Family_Edit', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Student_Edit', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Programs_Page', 'maybe_handle_submit' ] );
		add_action( 'admin_init', [ 'TR_Settings_Page', 'maybe_handle_submit' ] );
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
		add_submenu_page( self::PAGE_FAMILIES, __( 'Programs', 'tangnest-robotics' ), __( 'Programs', 'tangnest-robotics' ), self::CAP, self::PAGE_PROGRAMS, [ $this, 'render_programs_page' ] );
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

	public function render_families_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		if ( in_array( $action, [ 'add', 'edit' ], true ) ) {
			TR_Family_Edit::render();
			return;
		}

		$this->render_families_list();
	}

	private function render_families_list(): void {
		$table = new TR_Families_Table();
		$table->prepare_items();
		?>
		<div class="wrap tr-admin-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Families', 'tangnest-robotics' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_FAMILIES, 'action' => 'add' ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'tangnest-robotics' ); ?></a>
			<hr class="wp-header-end">
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

	public function render_programs_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		TR_Programs_Page::render();
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tangnest-robotics' ) );
		}

		TR_Settings_Page::render();
	}

	/**
	 * Handles the Resend-welcome-email row action from the Families table.
	 * The WhatsApp row action needs no handler — it's a plain outbound link.
	 */
	public function maybe_handle_family_row_actions(): void {
		if ( ! isset( $_GET['tr_row_action'], $_GET['id'], $_GET['page'] ) ) {
			return;
		}

		if ( self::PAGE_FAMILIES !== $_GET['page'] ) {
			return;
		}

		$row_action = sanitize_key( wp_unslash( $_GET['tr_row_action'] ) );
		$family_id  = absint( $_GET['id'] );

		if ( 'resend_welcome' !== $row_action || $family_id <= 0 ) {
			return;
		}

		check_admin_referer( 'tr_family_row_action_' . $family_id );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tangnest-robotics' ) );
		}

		$family = TR_Families::get( $family_id );
		$sent   = false;

		if ( null !== $family ) {
			$sent = TR_Notifications::resend_welcome_email( (int) $family->parent_user_id, $family_id );
		}

		wp_safe_redirect( add_query_arg( [
			'page'      => self::PAGE_FAMILIES,
			'tr_notice' => $sent ? 'welcome_sent' : 'welcome_failed',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_action_notices(): void {
		if ( ! isset( $_GET['tr_notice'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || false === strpos( $screen->id, 'tangnest-robotics' ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['tr_notice'] ) );

		if ( 'welcome_sent' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Welcome email sent.', 'tangnest-robotics' ) . '</p></div>';
		} elseif ( 'welcome_failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Welcome email failed to send. Check WP Mail SMTP → Email Log.', 'tangnest-robotics' ) . '</p></div>';
		}
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

		$enrollments = TR_Enrollments::get_by_student( $student_id );
		if ( ! empty( $enrollments ) ) {
			$current = $enrollments[0];
			if ( in_array( $current->status, [ 'active', 'withdrawn' ], true ) ) {
				TR_Enrollments::update( (int) $current->id, [
					'program_id'   => (int) $current->program_id,
					'enrolled_on'  => $current->enrolled_on,
					'months_total' => (int) $current->months_total,
					'months_paid'  => (int) $current->months_paid,
					'status'       => $new_status,
				] );
			}
		}

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
