<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @package    reMember
 * @subpackage reMember/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    reMember
 * @subpackage reMember/includes
 */
class Remember {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @var      Remember_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'REMEMBER_VERSION' ) ) {
			$this->version = REMEMBER_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'remember';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Remember_Loader. Orchestrates the hooks of the plugin.
	 * - Remember_i18n. Defines internationalization functionality.
	 * - Remember_Admin. Defines all hooks for the admin area.
	 * - Remember_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-remember-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-remember-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-remember-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-remember-public.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-xmlrpc.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-rest-privacy.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-security-headers.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-frontend.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-login-screen.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-profile-duplicates.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-reports.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-access-log.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-sensitive-profile.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-privacy.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-registration-guard.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-waitlist.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-event-invites.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-checkin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/utilities/class-remember-logger.php';

		$this->loader = new Remember_Loader();
		Remember_Logger::bootstrap();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Remember_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new Remember_i18n();
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Remember_Admin( $this->get_plugin_name(), $this->get_version() );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'wp_ajax_remember_get_event_roles', $plugin_admin, 'ajax_get_event_roles' );
		$this->loader->add_action( 'wp_ajax_remember_get_event_addons', $plugin_admin, 'ajax_get_event_addons' );
		$this->loader->add_action( 'wp_ajax_remember_get_event_agreements', $plugin_admin, 'ajax_get_event_agreements' );
		$this->loader->add_action( 'wp_ajax_remember_get_event_survey', $plugin_admin, 'ajax_get_event_survey' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_admin_menu' );
		$this->loader->add_filter( 'submenu_file', $plugin_admin, 'highlight_settings_hub_submenu' );
		$this->loader->add_action( 'wp_dashboard_setup', $plugin_admin, 'register_dashboard_widget' );
		
		// QuickBooks sync hooks
		$this->loader->add_action( 'remember_member_vetted', $this, 'sync_vetted_member_to_qb' );
		$this->loader->add_action( 'remember_qb_sync', $this, 'sync_qb_payments' );

		// Import/export CSV downloads must run before admin headers are sent.
		$this->loader->add_action( 'admin_init', $plugin_admin, 'handle_import_export_requests', 1 );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'handle_event_participant_export', 1 );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'intercept_plugin_deactivate', 0 );
		$this->loader->add_filter( 'plugin_action_links_' . plugin_basename( REMEMBER_PLUGIN_DIR . 'remember.php' ), $plugin_admin, 'filter_plugin_action_links' );

		// QuickBooks OAuth (redirect to Intuit + callback) must run before any admin HTML output.
		$this->loader->add_action( 'admin_init', $plugin_admin, 'handle_quickbooks_oauth', 1 );

		// Xero OAuth (redirect to Xero + callback) must run before any admin HTML output.
		$this->loader->add_action( 'admin_init', $plugin_admin, 'handle_xero_oauth', 1 );

		// Setup wizard redirect and form processing
		$this->loader->add_action( 'admin_init', $plugin_admin, 'maybe_show_setup_wizard' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'process_setup_wizard' );

		// Clean up member records when WordPress user is deleted
		$this->loader->add_action( 'delete_user', $this, 'cleanup_member_on_user_delete' );
		
		// Add timezone field to WordPress user profile
		$this->loader->add_action( 'show_user_profile', $plugin_admin, 'add_timezone_field_to_profile' );
		$this->loader->add_action( 'edit_user_profile', $plugin_admin, 'add_timezone_field_to_profile' );
		$this->loader->add_action( 'personal_options_update', $plugin_admin, 'save_timezone_field' );
		$this->loader->add_action( 'edit_user_profile_update', $plugin_admin, 'save_timezone_field' );
		
		// Redirect non-admin users to member dashboard after login
		$this->loader->add_filter( 'login_redirect', $this, 'redirect_non_admin_to_dashboard', 10, 3 );
		
		// Sync role capabilities on user login (run early)
		$this->loader->add_action( 'wp_login', $this, 'sync_user_capabilities_on_login', 5, 2 );
		
		// Also redirect on admin_init as fallback (after capabilities are synced)
		$this->loader->add_action( 'admin_init', $this, 'maybe_redirect_to_remember_dashboard' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
		$plugin_public = new Remember_Public( $this->get_plugin_name(), $this->get_version() );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
		$this->loader->add_action( 'init', $plugin_public, 'register_shortcodes' );
		$this->loader->add_action( 'template_redirect', $plugin_public, 'maybe_process_member_registration', 1 );
		$this->loader->add_action( 'template_redirect', $plugin_public, 'maybe_output_admission_ticket', 0 );
		$this->loader->add_action( 'wp_ajax_remember_profile_currency_status', $plugin_public, 'ajax_profile_currency_status' );
		$this->loader->add_action( 'remember_member_profile_saved', $this, 'maybe_sync_member_profile_to_qb', 10, 1 );
		$this->loader->add_action( 'remember_payment_status_changed', $this, 'on_payment_status_changed', 10, 4 );

		// Register FSE block patterns
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-fse.php';
		$this->loader->add_action( 'init', 'Remember_FSE', 'register_block_pattern_category' );
		$this->loader->add_action( 'init', 'Remember_FSE', 'register_block_patterns' );

		Remember_Xmlrpc::init();
		Remember_Rest_Privacy::init();
		Remember_Security_Headers::init();
		Remember_Frontend::init();
		Remember_Login_Screen::init();
		Remember_Profile_Duplicates::init();
		Remember_Reports::init();
		Remember_Access_Log::init();
		Remember_Sensitive_Profile::init();
		Remember_Privacy::init();
		Remember_Waitlist::init();
		Remember_Event_Invites::init();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		// Run after activation (avoids timing out during the activation HTTP request).
		$this->loader->add_action( 'init', $this, 'maybe_flush_rewrite_rules_after_activation', 0 );

		// Run database updates on admin init
		$this->loader->add_action( 'admin_init', $this, 'maybe_update_database' );
		
		// Schedule QuickBooks sync cron job
		$this->loader->add_action( 'admin_init', $this, 'maybe_schedule_qb_sync' );
		
		$this->loader->run();
	}

	/**
	 * When payment status changes to paid, email the paid ticket.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param object $payment    Payment row.
	 * @return void
	 */
	public function on_payment_status_changed( $payment_id, $old_status, $new_status, $payment ) {
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-notifications.php';
		Remember_Notifications::maybe_send_on_payment_status_change( $payment_id, $old_status, $new_status, $payment );
	}

	/**
	 * Flush permalinks after plugin activation (deferred from Remember_Activator::activate).
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules_after_activation() {
		if ( ! get_option( 'remember_activation_needs_rewrite_flush' ) ) {
			return;
		}
		flush_rewrite_rules();
		delete_option( 'remember_activation_needs_rewrite_flush' );
	}

	/**
	 * Check and update database schema if needed.
	 */
	public function maybe_update_database() {
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'database/class-remember-database-updater.php';
			Remember_Database_Updater::update_schema();
			$this->maybe_sync_plugin_version_option();
		}
	}

	/**
	 * Keep the remember_version option aligned with the installed plugin files.
	 *
	 * Upload → Replace reactivates silently and skips the activation hook that
	 * normally writes this option, so Settings (and anything else reading it)
	 * can report a stale version after a zip overwrite.
	 *
	 * @return void
	 */
	private function maybe_sync_plugin_version_option() {
		if ( ! defined( 'REMEMBER_VERSION' ) ) {
			return;
		}
		$stored = (string) get_option( 'remember_version', '' );
		if ( REMEMBER_VERSION === $stored ) {
			return;
		}
		update_option( 'remember_version', REMEMBER_VERSION );
	}

	/**
	 * Sync vetted member to the active accounting provider (QuickBooks or Xero).
	 *
	 * @param int $member_id Member ID.
	 */
	public function sync_vetted_member_to_qb( $member_id ) {
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-billing-provider.php';

		if ( Remember_Billing_Provider::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-xero-oauth.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return;
			}
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-xero-sync.php';
			Remember_Xero_Sync::sync_member_to_contact( $member_id );
			return;
		}

		if ( ! Remember_Billing_Provider::is_quickbooks() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-quickbooks-oauth.php';
		$qb_settings = Remember_QuickBooks_OAuth::get_settings();

		if ( $qb_settings && ! empty( $qb_settings['access_token'] ) && ! empty( $qb_settings['realm_id'] ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-quickbooks-sync.php';
			Remember_QuickBooks_Sync::sync_member_to_customer( $member_id );
		}
	}

	/**
	 * After member profile is saved (admin or front), push latest data to the active provider.
	 *
	 * @param int $member_id Member user ID.
	 */
	public function maybe_sync_member_profile_to_qb( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-billing-provider.php';

		if ( Remember_Billing_Provider::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-xero-oauth.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return;
			}
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-xero-sync.php';
			Remember_Xero_Sync::sync_member_to_contact( $member_id );
			return;
		}

		if ( ! Remember_Billing_Provider::is_quickbooks() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-quickbooks-oauth.php';
		$qb_settings = Remember_QuickBooks_OAuth::get_settings();
		if ( empty( $qb_settings['access_token'] ) || empty( $qb_settings['realm_id'] ) ) {
			return;
		}
		require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-quickbooks-sync.php';
		Remember_QuickBooks_Sync::sync_member_to_customer( $member_id );
	}

	/**
	 * Schedule QuickBooks sync cron job if needed.
	 */
	public function maybe_schedule_qb_sync() {
		if ( ! wp_next_scheduled( 'remember_qb_sync' ) ) {
			$options = get_option( 'remember_options', array() );
			$interval = isset( $options['qb_sync_interval'] ) ? absint( $options['qb_sync_interval'] ) : 3600; // Default 1 hour
			
			// Schedule recurring event
			wp_schedule_event( time(), 'hourly', 'remember_qb_sync' );
			
			// Note: WordPress doesn't support custom intervals easily, so we'll use hourly
			// and check the interval in the sync function itself
		}
	}

	/**
	 * Sync accounting payments via cron (active provider).
	 */
	public function sync_qb_payments() {
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-billing-provider.php';

		$options  = get_option( 'remember_options', array() );
		$interval = isset( $options['qb_sync_interval'] ) ? absint( $options['qb_sync_interval'] ) : 3600;

		if ( Remember_Billing_Provider::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-xero-oauth.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return;
			}
			global $wpdb;
			$last_sync = $wpdb->get_var( "SELECT last_sync_at FROM {$wpdb->prefix}remember_payment_processors WHERE processor_type = 'xero' AND is_active = 1 LIMIT 1" );
			if ( $last_sync ) {
				$time_since_sync = time() - strtotime( $last_sync );
				if ( $time_since_sync < $interval ) {
					return;
				}
			}
			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-xero-sync.php';
			Remember_Xero_Sync::sync_all_payments();
			return;
		}

		if ( ! Remember_Billing_Provider::is_quickbooks() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-quickbooks-oauth.php';
		$qb_settings = Remember_QuickBooks_OAuth::get_settings();

		if ( $qb_settings && ! empty( $qb_settings['access_token'] ) && ! empty( $qb_settings['realm_id'] ) ) {
			global $wpdb;
			$last_sync = $wpdb->get_var( "SELECT last_sync_at FROM {$wpdb->prefix}remember_payment_processors WHERE processor_type = 'quickbooks' AND is_active = 1 LIMIT 1" );

			if ( $last_sync ) {
				$time_since_sync = time() - strtotime( $last_sync );
				if ( $time_since_sync < $interval ) {
					return; // Not enough time has passed
				}
			}

			require_once plugin_dir_path( __FILE__ ) . 'integrations/class-remember-quickbooks-sync.php';
			Remember_QuickBooks_Sync::sync_all_payments();
		}
	}

	/**
	 * Clean up member record when WordPress user is deleted.
	 *
	 * @param int $user_id The ID of the user being deleted.
	 * @param int|null $reassign The ID of the user to reassign posts to, or null.
	 * @param WP_User $user The user object being deleted.
	 */
	public function cleanup_member_on_user_delete( $user_id, $reassign = null, $user = null ) {
		require_once plugin_dir_path( __FILE__ ) . 'models/class-member.php';
		$member_model = new Remember_Member();
		
		// Delete the member record if it exists
		$member = $member_model->get( $user_id );
		if ( $member ) {
			$member_model->delete( $user_id );
			
			require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-logger.php';
			Remember_Logger::debug( 'Deleted member record on user deletion', array( 'user_id' => $user_id ) );
		}
	}

	/**
	 * Redirect non-admin users to member dashboard after login.
	 *
	 * @param string  $redirect_to URL to redirect to.
	 * @param string  $request     The requested redirect destination.
	 * @param WP_User $user        WP_User object.
	 * @return string Redirect URL.
	 */
	public function redirect_non_admin_to_dashboard( $redirect_to, $request, $user ) {
		// Don't redirect if there's an error or if user is admin
		if ( is_wp_error( $user ) || ! $user ) {
			return $redirect_to;
		}
		
		// Sync capabilities first (in case they weren't synced yet)
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-capabilities.php';
		Remember_Capabilities::sync_user_capabilities_from_roles( $user->ID );
		
		// Refresh user object to get updated capabilities
		$user = get_userdata( $user->ID );
		if ( ! $user ) {
			return $redirect_to;
		}

		// Full site admins: respect the default redirect (usually wp-admin).
		if ( user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}

		require_once plugin_dir_path( __FILE__ ) . 'models/class-member.php';
		$member_model = new Remember_Member();
		$member       = $member_model->get( $user->ID );

		$created_pages     = get_option( 'remember_created_pages', array() );
		$dashboard_page_id = isset( $created_pages['member_dashboard'] ) ? absint( $created_pages['member_dashboard'] ) : ( isset( $created_pages['dashboard'] ) ? absint( $created_pages['dashboard'] ) : 0 );

		// Members: always prefer the front-end member dashboard when that page exists.
		// Avoids sending subscribers to wp-admin with partial reMember caps they cannot use on the main plugin screen.
		if ( $member && $dashboard_page_id ) {
			return get_permalink( $dashboard_page_id );
		}

		// Staff-only users who can actually open admin.php?page=remember (same caps as display_dashboard_page).
		if ( $this->user_can_access_remember_admin_main( $user ) ) {
			return admin_url( 'admin.php?page=remember' );
		}

		return $redirect_to;
	}

	/**
	 * Whether the user may load the main reMember wp-admin screen (admin.php?page=remember).
	 *
	 * Must match Remember_Admin::display_dashboard_page() capability checks.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	private function user_can_access_remember_admin_main( $user ) {
		if ( ! $user || ! $user->ID ) {
			return false;
		}
		return user_can( $user, 'remember_read_events' )
			|| user_can( $user, 'remember_read_vetting' )
			|| user_can( $user, 'remember_read_members' )
			|| user_can( $user, 'remember_read_applications' );
	}
	
	/**
	 * Redirect users with reMember capabilities to reMember dashboard on admin_init.
	 * This is a fallback in case the login_redirect filter didn't catch it.
	 */
	public function maybe_redirect_to_remember_dashboard() {
		// Only redirect if we're in admin and not already on the reMember dashboard
		if ( ! is_admin() ) {
			return;
		}
		
		// Don't redirect if already on reMember pages or if doing AJAX
		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'remember' ) === 0 ) {
			return;
		}
		
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}
		
		// Sync capabilities first
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-capabilities.php';
		Remember_Capabilities::sync_user_capabilities_from_roles( $user->ID );
		
		// Refresh user object
		$user = get_userdata( $user->ID );
		if ( ! $user ) {
			return;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'models/class-member.php';
		$member_model = new Remember_Member();
		$member       = $member_model->get( $user->ID );
		$created_pages = get_option( 'remember_created_pages', array() );
		$dashboard_page_id = isset( $created_pages['member_dashboard'] ) ? absint( $created_pages['member_dashboard'] ) : ( isset( $created_pages['dashboard'] ) ? absint( $created_pages['dashboard'] ) : 0 );

		// Do not push members toward wp-admin when they have a public dashboard URL.
		if ( $member && $dashboard_page_id ) {
			return;
		}

		// Only redirect if they can open the main reMember admin screen (not merely any remember_* cap).
		if ( ! $this->user_can_access_remember_admin_main( $user ) ) {
			return;
		}
		
		// Staff: redirect from generic admin screens to reMember dashboard
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $current_screen && $current_screen->id !== 'toplevel_page_remember' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=remember' ) );
			exit;
		}
	}

	/**
	 * Sync user capabilities from assigned roles on login.
	 *
	 * @param string  $user_login User login name.
	 * @param WP_User $user       WP_User object.
	 */
	public function sync_user_capabilities_on_login( $user_login, $user ) {
		if ( ! $user || ! $user->ID ) {
			return;
		}
		
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-capabilities.php';
		Remember_Capabilities::sync_user_capabilities_from_roles( $user->ID );
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Remember_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
