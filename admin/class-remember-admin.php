<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    reMember
 * @subpackage reMember/admin
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    reMember
 * @subpackage reMember/admin
 */
class Remember_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string $plugin_name       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Main plugin file relative to wp-content/plugins.
	 *
	 * @return string
	 */
	public static function plugin_file() {
		return plugin_basename( REMEMBER_PLUGIN_DIR . 'remember.php' );
	}

	/**
	 * Point Plugins → Deactivate at a backup reminder. Data is not removed here.
	 *
	 * @param array $actions Row actions.
	 * @return array
	 */
	public function filter_plugin_action_links( $actions ) {
		if ( isset( $actions['deactivate'] ) ) {
			$actions['deactivate'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=remember-deactivate' ) ),
				esc_attr__( 'Deactivate reMember', 'remember' ),
				esc_html__( 'Deactivate', 'remember' )
			);
		}
		return $actions;
	}

	/**
	 * Catch a direct plugins.php deactivate URL so it still offers a backup.
	 *
	 * @return void
	 */
	public function intercept_plugin_deactivate() {
		if ( ! is_admin() || is_network_admin() ) {
			return;
		}
		if ( ! empty( $_GET['remember_confirmed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 'deactivate' !== $action ) {
			return;
		}
		$plugin = isset( $_REQUEST['plugin'] ) ? wp_unslash( $_REQUEST['plugin'] ) : '';
		if ( self::plugin_file() !== $plugin ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		check_admin_referer( 'deactivate-plugin_' . $plugin );
		wp_safe_redirect( admin_url( 'admin.php?page=remember-deactivate' ) );
		exit;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		if ( strpos( $screen->id, 'remember' ) !== false ) {
			$admin_css = plugin_dir_path( __FILE__ ) . '../assets/css/admin.css';
			$admin_ver = is_readable( $admin_css ) ? (string) filemtime( $admin_css ) : $this->version;
			wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . '../assets/css/admin.css', array(), $admin_ver, 'all' );
			// Photo cropper styles (shared with front-end) on Members edit.
			if ( false !== strpos( $screen->id, 'remember-members' ) ) {
				$public_css = plugin_dir_path( __FILE__ ) . '../assets/css/public.css';
				$css_ver    = is_readable( $public_css ) ? (string) filemtime( $public_css ) : $this->version;
				wp_enqueue_style(
					$this->plugin_name . '-photo-cropper',
					plugin_dir_url( __FILE__ ) . '../assets/css/public.css',
					array(),
					$css_ver,
					'all'
				);
			}
		}
		// Timezone combobox on WP user profile screens.
		if ( in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
			wp_enqueue_style( $this->plugin_name . '-timezone', plugin_dir_url( __FILE__ ) . '../assets/css/admin.css', array(), $this->version, 'all' );
		}

		// Settings flyout in the left admin menu (any wp-admin screen).
		if ( $this->current_user_has_remember_capability() ) {
			wp_enqueue_style(
				$this->plugin_name . '-admin-menu',
				plugin_dir_url( __FILE__ ) . '../assets/css/admin-menu.css',
				array(),
				$this->version,
				'all'
			);
		}
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		if ( strpos( $screen->id, 'remember' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-timezone',
				plugin_dir_url( __FILE__ ) . '../assets/js/timezone-picker.js',
				array( 'jquery' ),
				$this->version,
				true
			);
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . '../assets/js/admin.js', array( 'jquery', $this->plugin_name . '-timezone' ), $this->version, true );
			// Localize script for AJAX
			wp_localize_script( $this->plugin_name, 'rememberAjax', array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'remember_ajax_nonce' ),
			) );
			// Shared photo cropper (zoom + drag) on Members edit.
			if ( false !== strpos( $screen->id, 'remember-members' ) ) {
				$public_js  = plugin_dir_path( __FILE__ ) . '../assets/js/public.js';
				$public_ver = is_readable( $public_js ) ? (string) filemtime( $public_js ) : $this->version;
				wp_enqueue_script(
					$this->plugin_name . '-photo-cropper',
					plugin_dir_url( __FILE__ ) . '../assets/js/public.js',
					array( 'jquery' ),
					$public_ver,
					true
				);
			}
			if ( false !== strpos( $screen->id, 'remember-settings' ) ) {
				wp_enqueue_editor();
			}
			if ( false !== strpos( $screen->id, 'remember-settings' ) || false !== strpos( $screen->id, 'remember-locations' ) ) {
				wp_enqueue_media();
			}
			if ( false !== strpos( $screen->id, 'remember-reports' ) ) {
				$reports_js  = plugin_dir_path( __FILE__ ) . '../assets/js/reports.js';
				$reports_ver = is_readable( $reports_js ) ? (string) filemtime( $reports_js ) : $this->version;
				wp_enqueue_script(
					$this->plugin_name . '-reports',
					plugin_dir_url( __FILE__ ) . '../assets/js/reports.js',
					array( 'jquery' ),
					$reports_ver,
					true
				);
				wp_localize_script(
					$this->plugin_name . '-reports',
					'rememberReports',
					array(
						'ajaxurl'    => admin_url( 'admin-ajax.php' ),
						'nonce'      => wp_create_nonce( 'remember_reports' ),
						'exportUrl'  => admin_url( 'admin-post.php' ),
						'i18n'       => array(
							'run'          => __( 'Run report', 'remember' ),
							'running'      => __( 'Running…', 'remember' ),
							'save'         => __( 'Save', 'remember' ),
							'saveAs'       => __( 'Save as', 'remember' ),
							'del'          => __( 'Delete', 'remember' ),
							'exportCsv'    => __( 'Export CSV', 'remember' ),
							'newReport'    => __( 'New report', 'remember' ),
							'unnamed'      => __( 'Untitled report', 'remember' ),
							'confirmDel'   => __( 'Delete this saved report?', 'remember' ),
							'needName'     => __( 'Name this report.', 'remember' ),
							'noRows'       => __( 'No rows.', 'remember' ),
							'error'        => __( 'Could not run that report.', 'remember' ),
							'addFilter'    => __( 'Add filter', 'remember' ),
							'addGroup'     => __( 'Add grouping', 'remember' ),
							'addAgg'       => __( 'Add calculation', 'remember' ),
							'detail'       => __( 'Rows', 'remember' ),
							'summary'      => __( 'Summary', 'remember' ),
							'page'         => __( 'Page', 'remember' ),
							'of'           => __( 'of', 'remember' ),
							'saved'        => __( 'Saved.', 'remember' ),
							'deleted'      => __( 'Deleted.', 'remember' ),
							'copyTo'       => __( 'Copy to…', 'remember' ),
							'copy'         => __( 'Copy', 'remember' ),
							'copyCancel'   => __( 'Cancel', 'remember' ),
							'copyNeedSave' => __( 'Save this report before copying it.', 'remember' ),
							'scheduleNeedSave' => __( 'Save this report before scheduling it.', 'remember' ),
							'scheduleSaved' => __( 'Schedule saved.', 'remember' ),
							'scheduleNote' => __( 'Times use the site timezone (%s). The CSV uses your access. Each person is checked again when it sends.', 'remember' ),
							'lastSent'     => __( 'Last sent %s.', 'remember' ),
							'notSentYet'   => __( 'Not sent yet.', 'remember' ),
							'copyNeedUser' => __( 'Choose someone to copy this report to.', 'remember' ),
							'noRecipients' => __( 'No one else can receive this report. They need View Reports plus the read access this subject uses.', 'remember' ),
							'loadingRecipients' => __( 'Loading…', 'remember' ),
							'noSubjects'   => __( 'No report subjects are available for your role.', 'remember' ),
							'prev'         => __( 'Previous', 'remember' ),
							'next'         => __( 'Next', 'remember' ),
							'remove'       => __( 'Remove', 'remember' ),
							'up'           => __( 'Move up', 'remember' ),
							'down'         => __( 'Move down', 'remember' ),
							'none'         => __( 'None', 'remember' ),
							'countStar'    => __( 'Rows', 'remember' ),
							'rowsLabel'    => __( 'rows', 'remember' ),
							'noneSaved'    => __( 'No saved reports yet.', 'remember' ),
							'fieldLabel'   => __( 'Field', 'remember' ),
							'operatorLabel'=> __( 'Operator', 'remember' ),
							'valueLabel'   => __( 'Value', 'remember' ),
							'selectValue'  => __( 'Select value', 'remember' ),
							'joinLabel'    => __( 'Combine with the filter above', 'remember' ),
							'joinAnd'      => __( 'AND', 'remember' ),
							'joinOr'       => __( 'OR', 'remember' ),
							'openGroup'    => __( 'Group (', 'remember' ),
							'closeGroup'   => __( 'Group )', 'remember' ),
							/* translators: %s: the filters written out, such as (Status is vetted OR Status is in_vetting) AND City is Portland */
							'logicPreview' => __( 'Rows must match: %s', 'remember' ),
							/* translators: %d: filter position, counting from 1 at the top */
							'logicMixed'   => __( 'AND and OR meet at filter %d without parentheses. Add ( and ) to show which filters belong together.', 'remember' ),
							/* translators: %d: filter position, counting from 1 at the top */
							'logicOpen'    => __( 'Filter %d opens a group that is never closed. Add a ) to a later filter or remove the (.', 'remember' ),
							/* translators: %d: filter position, counting from 1 at the top */
							'logicClose'   => __( 'Filter %d closes a group that was never opened. Remove a ) or add a ( to an earlier filter.', 'remember' ),
							'calcLabel'    => __( 'Calculation', 'remember' ),
							'allEvents'    => __( 'All events', 'remember' ),
							'eventHintAll' => __( 'Saved reports stay global. Choose an event to limit this run; it is not saved with the report.', 'remember' ),
							'eventHintMembers' => __( 'This run is limited to accepted participants of the selected event. The saved report stays global.', 'remember' ),
							'eventHintApps' => __( 'This run is limited to applications for the selected event. The saved report stays global.', 'remember' ),
							'eventHintPay' => __( 'This run is limited to payments for the selected event. The saved report stays global.', 'remember' ),
							'eventHintEvents' => __( 'This run is limited to the selected event. The saved report stays global.', 'remember' ),
						),
					)
				);
			}
		}
		if ( in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
			wp_enqueue_script(
				$this->plugin_name . '-timezone',
				plugin_dir_url( __FILE__ ) . '../assets/js/timezone-picker.js',
				array( 'jquery' ),
				$this->version,
				true
			);
		}

		if ( $this->current_user_has_remember_capability() ) {
			wp_enqueue_script(
				$this->plugin_name . '-admin-menu',
				plugin_dir_url( __FILE__ ) . '../assets/js/admin-menu.js',
				array(),
				$this->version,
				true
			);
			wp_localize_script(
				$this->plugin_name . '-admin-menu',
				'rememberSettingsFlyout',
				array(
					'label' => __( 'Settings', 'remember' ),
					'items' => $this->get_settings_flyout_items_for_user(),
				)
			);
		}
	}

	/**
	 * Whether the current user has any reMember capability (sees the admin menu).
	 *
	 * @return bool
	 */
	private function current_user_has_remember_capability() {
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-capabilities.php';
		foreach ( array_keys( Remember_Capabilities::get_all_capabilities() ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Settings flyout links the current user can open.
	 *
	 * @return array<int, array{slug:string,label:string,url:string}>
	 */
	private function get_settings_flyout_items_for_user() {
		$candidates = array(
			array(
				'slug'  => 'remember-settings',
				'label' => __( 'Settings', 'remember' ),
				'caps'  => array( 'remember_access_settings' ),
			),
			array(
				'slug'  => 'remember-getting-started',
				'label' => __( 'Getting Started', 'remember' ),
				'caps'  => array( 'remember_read_events', 'remember_access_settings' ),
			),
			array(
				'slug'  => 'remember-locations',
				'label' => __( 'Locations', 'remember' ),
				'caps'  => array( 'remember_read_locations' ),
			),
			array(
				'slug'  => 'remember-roles',
				'label' => __( 'Roles', 'remember' ),
				'caps'  => array( 'remember_read_roles' ),
			),
			array(
				'slug'  => 'remember-products',
				'label' => __( 'Products', 'remember' ),
				'caps'  => array( 'remember_access_settings' ),
			),
			array(
				'slug'  => 'remember-profile-questions',
				'label' => __( 'Custom Fields', 'remember' ),
				'caps'  => array( 'remember_access_settings' ),
			),
			array(
				'slug'  => 'remember-agreements',
				'label' => __( 'Agreements', 'remember' ),
				'caps'  => array( 'remember_access_settings' ),
			),
			array(
				'slug'  => 'remember-import-export',
				'label' => __( 'Import/Export', 'remember' ),
				'caps'  => array( 'remember_import_export' ),
			),
		);

		$items = array();
		foreach ( $candidates as $item ) {
			foreach ( $item['caps'] as $cap ) {
				if ( current_user_can( $cap ) ) {
					$items[] = array(
						'slug'  => $item['slug'],
						'label' => $item['label'],
						'url'   => admin_url( 'admin.php?page=' . $item['slug'] ),
					);
					break;
				}
			}
		}
		return $items;
	}

	/**
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 *
	 * @since    1.0.0
	 */
	public function add_admin_menu() {
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-logger.php';
		Remember_Logger::debug( 'Building admin menu' );

		// Check if user has any reMember capability
		$has_remember_cap = false;
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-capabilities.php';
		$all_caps = Remember_Capabilities::get_all_capabilities();
		foreach ( array_keys( $all_caps ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				$has_remember_cap = true;
				break;
			}
		}
		
		// If user has no reMember capabilities, don't show menu
		if ( ! $has_remember_cap ) {
			return;
		}

		// Main menu item (Dashboard) - use the first capability the user has
		$main_cap = 'remember_read_events';
		// Try to find a capability the user actually has
		foreach ( array( 'remember_read_vetting', 'remember_read_events', 'remember_read_members', 'remember_read_applications' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				$main_cap = $cap;
				break;
			}
		}
		
		add_menu_page(
			__( 'reMember Dashboard', 'remember' ),
			__( 'reMember', 'remember' ),
			$main_cap,
			'remember',
			array( $this, 'display_dashboard_page' ),
			'dashicons-groups',
			30
		);

		// Dashboard submenu (duplicate of main menu)
		add_submenu_page(
			'remember',
			__( 'Dashboard', 'remember' ),
			__( 'Dashboard', 'remember' ),
			$main_cap,
			'remember',
			array( $this, 'display_dashboard_page' )
		);

		// Members (accessible with either members or attendees capability)
		// Check at menu registration time which capability the user has
		// The members page will handle filtering based on actual capabilities
		$members_cap = 'remember_read_members'; // Default to full access
		if ( ! current_user_can( 'remember_read_members' ) && current_user_can( 'remember_read_attendees' ) ) {
			$members_cap = 'remember_read_attendees';
		}
		add_submenu_page(
			'remember',
			__( 'Members', 'remember' ),
			__( 'Members', 'remember' ),
			$members_cap,
			'remember-members',
			array( $this, 'display_members_page' )
		);

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-duplicates.php';
		$dup_label = __( 'Duplicate Profiles', 'remember' );
		if ( current_user_can( Remember_Profile_Duplicates::MERGE_CAP ) ) {
			$pending = Remember_Profile_Duplicates::pending_count();
			if ( $pending > 0 ) {
				$dup_label .= ' <span class="awaiting-mod">' . esc_html( (string) $pending ) . '</span>';
			}
		}
		add_submenu_page(
			'remember',
			__( 'Duplicate Profiles', 'remember' ),
			$dup_label,
			Remember_Profile_Duplicates::MERGE_CAP,
			'remember-duplicates',
			array( $this, 'display_duplicates_page' )
		);

		add_submenu_page(
			'remember',
			__( 'Reports', 'remember' ),
			__( 'Reports', 'remember' ),
			'remember_view_reports',
			'remember-reports',
			array( $this, 'display_reports_page' )
		);

		add_submenu_page(
			'remember',
			__( 'Sensitive access log', 'remember' ),
			__( 'Access log', 'remember' ),
			'manage_options',
			'remember-access-log',
			array( $this, 'display_access_log_page' )
		);

		// Events
		add_submenu_page(
			'remember',
			__( 'Events', 'remember' ),
			__( 'Events', 'remember' ),
			'remember_read_events',
			'remember-events',
			array( $this, 'display_events_page' )
		);

		add_submenu_page(
			'remember',
			__( 'Surveys', 'remember' ),
			__( 'Surveys', 'remember' ),
			'remember_read_events',
			'remember-surveys',
			array( $this, 'display_surveys_page' )
		);

		// Applications
		add_submenu_page(
			'remember',
			__( 'Applications', 'remember' ),
			__( 'Applications', 'remember' ),
			'remember_read_applications',
			'remember-applications',
			array( $this, 'display_applications_page' )
		);

		// Waitlist
		add_submenu_page(
			'remember',
			__( 'Waitlist', 'remember' ),
			__( 'Waitlist', 'remember' ),
			'remember_read_applications',
			'remember-waitlist',
			array( $this, 'display_waitlist_page' )
		);

		if ( current_user_can( 'manage_options' ) && ! current_user_can( 'remember_checkin_attendees' ) ) {
			wp_get_current_user()->add_cap( 'remember_checkin_attendees' );
		}
		add_submenu_page(
			'remember',
			__( 'Check-in', 'remember' ),
			__( 'Check-in', 'remember' ),
			'remember_checkin_attendees',
			'remember-checkin',
			array( $this, 'display_checkin_page' )
		);

		// Vetting
		add_submenu_page(
			'remember',
			__( 'Vetting', 'remember' ),
			__( 'Vetting', 'remember' ),
			'remember_read_vetting',
			'remember-vetting',
			array( $this, 'display_vetting_page' )
		);

		// Billing
		add_submenu_page(
			'remember',
			__( 'Billing', 'remember' ),
			__( 'Billing', 'remember' ),
			'remember_read_billing',
			'remember-billing',
			array( $this, 'display_billing_page' )
		);

		// Settings — nested setup pages open from a left-menu flyout (not listed vertically).
		add_submenu_page(
			'remember',
			__( 'Settings', 'remember' ),
			__( 'Settings', 'remember' ),
			$this->get_settings_menu_capability(),
			'remember-settings',
			array( $this, 'display_settings_page' )
		);

		// Hidden pages nested under Settings flyout (empty menu title).
		$getting_started_cap = 'remember_read_events';
		if ( ! current_user_can( 'remember_read_events' ) && current_user_can( 'remember_access_settings' ) ) {
			$getting_started_cap = 'remember_access_settings';
		}
		add_submenu_page(
			'remember',
			__( 'Getting Started', 'remember' ),
			'',
			$getting_started_cap,
			'remember-getting-started',
			array( $this, 'display_getting_started_page' )
		);
		add_submenu_page(
			'remember',
			__( 'Locations', 'remember' ),
			'',
			'remember_read_locations',
			'remember-locations',
			array( $this, 'display_locations_page' )
		);
		add_submenu_page(
			'remember',
			__( 'Roles', 'remember' ),
			'',
			'remember_read_roles',
			'remember-roles',
			array( $this, 'display_roles_page' )
		);
		add_submenu_page(
			'remember',
			__( 'Products', 'remember' ),
			'',
			'remember_access_settings',
			'remember-products',
			array( $this, 'display_products_page' )
		);
		add_submenu_page(
			'remember',
			__( 'Custom Fields', 'remember' ),
			'',
			'remember_access_settings',
			'remember-profile-questions',
			array( $this, 'display_profile_questions_page' )
		);
		add_submenu_page(
			'remember',
			__( 'Agreements', 'remember' ),
			'',
			'remember_access_settings',
			'remember-agreements',
			array( $this, 'display_agreements_page' )
		);
		add_submenu_page(
			'remember',
			__( 'Import/Export', 'remember' ),
			'',
			'remember_import_export',
			'remember-import-export',
			array( $this, 'display_import_export_page' )
		);

		// Setup Wizard (hidden page, only shown when needed)
		add_submenu_page(
			'remember',
			__( 'reMember Setup', 'remember' ),
			'',
			'remember_access_settings',
			'remember-setup',
			array( $this, 'display_setup_wizard' )
		);

		add_submenu_page(
			'options.php',
			__( 'Deactivate reMember', 'remember' ),
			'',
			'activate_plugins',
			'remember-deactivate',
			array( $this, 'display_deactivate_page' )
		);

		Remember_Logger::debug( 'Admin menu registered' );
	}

	/**
	 * Page slugs nested under the Settings hub (hidden from left menu).
	 *
	 * @return string[]
	 */
	public static function get_settings_hub_page_slugs() {
		return array(
			'remember-settings',
			'remember-getting-started',
			'remember-locations',
			'remember-roles',
			'remember-products',
			'remember-profile-questions',
			'remember-agreements',
			'remember-import-export',
		);
	}

	/**
	 * Capability for the visible Settings menu item (any hub page the user can open).
	 *
	 * @return string
	 */
	private function get_settings_menu_capability() {
		foreach ( array(
			'remember_access_settings',
			'remember_import_export',
			'remember_read_locations',
			'remember_read_roles',
			'remember_read_events',
		) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}
		return 'remember_access_settings';
	}

	/**
	 * First Settings-hub URL the current user can open.
	 *
	 * @param bool $exclude_settings Skip the main Settings page (used when redirecting away from it).
	 * @return string Empty if none.
	 */
	private function get_first_accessible_settings_hub_url( $exclude_settings = false ) {
		$candidates = array(
			array( 'remember-settings', array( 'remember_access_settings' ) ),
			array( 'remember-getting-started', array( 'remember_read_events', 'remember_access_settings' ) ),
			array( 'remember-locations', array( 'remember_read_locations' ) ),
			array( 'remember-roles', array( 'remember_read_roles' ) ),
			array( 'remember-products', array( 'remember_access_settings' ) ),
			array( 'remember-profile-questions', array( 'remember_access_settings' ) ),
			array( 'remember-agreements', array( 'remember_access_settings' ) ),
			array( 'remember-import-export', array( 'remember_import_export' ) ),
		);
		foreach ( $candidates as $pair ) {
			if ( $exclude_settings && 'remember-settings' === $pair[0] ) {
				continue;
			}
			foreach ( $pair[1] as $cap ) {
				if ( current_user_can( $cap ) ) {
					return admin_url( 'admin.php?page=' . $pair[0] );
				}
			}
		}
		return '';
	}

	/**
	 * Keep Settings highlighted in the left menu on hub pages.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public function highlight_settings_hub_submenu( $submenu_file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $page, self::get_settings_hub_page_slugs(), true ) ) {
			return 'remember-settings';
		}
		return $submenu_file;
	}

	/**
	 * Render the dashboard page.
	 *
	 * @since    1.0.0
	 */
	public function display_dashboard_page() {
		// Check capability — must stay aligned with menu $main_cap and user_can_access_remember_admin_main().
		if (
			! current_user_can( 'remember_read_events' )
			&& ! current_user_can( 'remember_read_vetting' )
			&& ! current_user_can( 'remember_read_members' )
			&& ! current_user_can( 'remember_read_applications' )
		) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-application.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-vetting.php';
		include_once 'views/dashboard.php';
	}

	/**
	 * Render the members page.
	 *
	 * @since    1.0.0
	 */
	public function display_members_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_members' ) && ! current_user_can( 'remember_read_attendees' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		include_once 'views/members.php';
	}

	/**
	 * Duplicate profile review and merge.
	 *
	 * @return void
	 */
	public function display_duplicates_page() {
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-duplicates.php';
		if ( ! current_user_can( Remember_Profile_Duplicates::MERGE_CAP ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		include_once 'views/duplicates.php';
	}

	/**
	 * Who viewed or exported health and emergency-contact data.
	 *
	 * @return void
	 */
	public function display_access_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-access-log.php';
		if ( isset( $_POST['remember_access_log_retention'] ) && check_admin_referer( 'remember_access_log_retention', 'remember_access_log_nonce' ) ) {
			Remember_Access_Log::set_retention_months( isset( $_POST['retention_months'] ) ? absint( wp_unslash( $_POST['retention_months'] ) ) : 12 );
			wp_safe_redirect( admin_url( 'admin.php?page=remember-access-log&updated=1' ) );
			exit;
		}
		include_once 'views/access-log.php';
	}

	/**
	 * Staff reporting builder.
	 *
	 * @return void
	 */
	public function display_reports_page() {
		if ( ! current_user_can( 'remember_view_reports' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		include_once 'views/reports.php';
	}

	/**
	 * Event surveys.
	 *
	 * @return void
	 */
	public function display_surveys_page() {
		if ( ! current_user_can( 'remember_read_events' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		include_once 'views/surveys.php';
	}

	/**
	 * Render the events page.
	 *
	 * @since    1.0.0
	 */
	public function display_events_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_events' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-location.php';
		include_once 'views/events.php';
	}

	/**
	 * Render the applications page.
	 *
	 * @since    1.0.0
	 */
	public function display_applications_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_applications' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-application.php';
		include_once 'views/applications.php';
	}

	/**
	 * Render the door check-in page.
	 *
	 * @since    2.2.0
	 */
	public function display_checkin_page() {
		if ( ! current_user_can( 'remember_checkin_attendees' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		include_once 'views/checkin.php';
	}

	/**
	 * Render the waitlist page.
	 *
	 * @since    1.0.0
	 */
	public function display_waitlist_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_applications' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-application.php';
		include_once 'views/waitlist.php';
	}

	/**
	 * Render the vetting page.
	 *
	 * @since    1.0.0
	 */
	public function display_vetting_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_vetting' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-vetting.php';
		include_once 'views/vetting.php';
	}

	/**
	 * Render Getting Started (static data setup + links to wizard and docs).
	 *
	 * @since 1.0.0
	 */
	public function display_getting_started_page() {
		if ( ! current_user_can( 'remember_read_events' ) && ! current_user_can( 'remember_access_settings' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		include_once 'views/getting-started.php';
	}

	/**
	 * Render the billing page.
	 *
	 * @since    1.0.0
	 */
	public function display_billing_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_billing' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		// Refresh payment rows from the active accounting provider.
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-billing-provider.php';
		if ( Remember_Billing_Provider::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-xero-oauth.php';
			if ( Remember_Xero_OAuth::is_connected() ) {
				require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-xero-sync.php';
				Remember_Xero_Sync::sync_all_payments();
			}
		} elseif ( Remember_Billing_Provider::is_quickbooks() ) {
			require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-quickbooks-oauth.php';
			$qb_settings = Remember_QuickBooks_OAuth::get_settings();
			if ( $qb_settings && ! empty( $qb_settings['access_token'] ) && ! empty( $qb_settings['realm_id'] ) ) {
				require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-quickbooks-sync.php';
				Remember_QuickBooks_Sync::sync_all_payments();
			}
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-payment.php';
		include_once 'views/billing.php';
	}

	/**
	 * Render the locations page.
	 *
	 * @since    1.0.0
	 */
	public function display_locations_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_locations' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-location.php';
		include_once 'views/locations.php';
	}

	/**
	 * Render the roles page.
	 *
	 * @since    1.0.0
	 */
	public function display_roles_page() {
		// Check capability
		if ( ! current_user_can( 'remember_read_roles' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-role.php';
		include_once 'views/roles.php';
	}

	/**
	 * Render the settings page.
	 *
	 * @since    1.0.0
	 */
	public function display_settings_page() {
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			$fallback = $this->get_first_accessible_settings_hub_url( true );
			if ( $fallback ) {
				wp_safe_redirect( $fallback );
				exit;
			}
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		include_once 'views/settings.php';
	}

	/**
	 * Render the products page.
	 *
	 * @since    1.0.0
	 */
	public function display_products_page() {
		// Check capability
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-product.php';
		include_once 'views/products.php';
	}

	/**
	 * Render custom profile fields (questions) admin page.
	 *
	 * @return void
	 */
	public function display_profile_questions_page() {
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		include_once 'views/profile-questions.php';
	}

	/**
	 * Render Agreements library admin page.
	 *
	 * @return void
	 */
	public function display_agreements_page() {
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		include_once 'views/agreements.php';
	}

	/**
	 * Render the import/export page.
	 *
	 * @since    1.0.0
	 */
	public function display_import_export_page() {
		if ( ! current_user_can( 'remember_import_export' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-import-export.php';
		include_once 'views/import-export.php';
	}

	/**
	 * Backup reminder before Plugins → Deactivate. Does not wipe data.
	 *
	 * @return void
	 */
	public function display_deactivate_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to deactivate plugins.', 'remember' ), esc_html__( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-backup.php';
		include_once 'views/deactivate.php';
	}

	/**
	 * Serve per-event accepted-participant CSV before admin HTML output.
	 *
	 * @since 1.3.0
	 */
	public function handle_event_participant_export() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) || 'remember-events' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( empty( $_GET['remember_export_event_participants'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'remember_event_data_export' ) ) {
			wp_die( esc_html__( 'You do not have permission to export event participant data.', 'remember' ) );
		}

		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $event_id <= 0 ) {
			return;
		}

		check_admin_referer( 'remember_export_event_participants_' . $event_id );

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-import-export.php';
		Remember_Import_Export::export_event_participants( $event_id );

		wp_die( esc_html__( 'Event not found or export failed.', 'remember' ) );
	}

	/**
	 * Serve CSV template downloads and exports before admin HTML output (avoids "headers already sent").
	 *
	 * @since    1.0.0
	 */
	public function handle_import_export_requests() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		if ( 'remember-deactivate' === $page ) {
			$this->handle_deactivate_backup_download();
			return;
		}

		if ( 'remember-import-export' !== $page ) {
			return;
		}

		if ( ! current_user_can( 'remember_import_export' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-import-export.php';

		// GET: template downloads.
		if ( isset( $_GET['remember_import_export_action'], $_GET['remember_import_export_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['remember_import_export_nonce'] ) ), 'remember_import_export_action' ) ) {
				return;
			}

			$action = sanitize_text_field( wp_unslash( $_GET['remember_import_export_action'] ) );

			if ( 'download_members_template' === $action ) {
				Remember_Import_Export::download_members_template();
			} elseif ( 'download_events_template' === $action ) {
				Remember_Import_Export::download_events_template();
			} elseif ( 'download_locations_template' === $action ) {
				Remember_Import_Export::download_locations_template();
			} elseif ( 'download_profile_questions_template' === $action ) {
				Remember_Import_Export::download_profile_questions_template();
			}
			// Valid downloads exit inside the handler; unknown actions fall through to the page.
		}

		// POST: CSV exports only (imports render notices in the view).
		if ( ! isset( $_POST['remember_import_export_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['remember_import_export_action'] ) );
		if ( ! in_array(
			$action,
			array( 'export_members', 'export_events', 'export_locations', 'export_profile_questions', 'export_backup' ),
			true
		) ) {
			return;
		}

		check_admin_referer( 'remember_import_export_action', 'remember_import_export_nonce' );

		if ( 'export_members' === $action ) {
			Remember_Import_Export::export_members();
		} elseif ( 'export_events' === $action ) {
			Remember_Import_Export::export_events();
		} elseif ( 'export_locations' === $action ) {
			Remember_Import_Export::export_locations();
		} elseif ( 'export_backup' === $action ) {
			require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-backup.php';
			Remember_Backup::download();
		} else {
			Remember_Import_Export::export_profile_questions();
		}
	}

	/**
	 * Full backup from the deactivate reminder screen.
	 *
	 * @return void
	 */
	private function handle_deactivate_backup_download() {
		if ( ! isset( $_POST['remember_import_export_action'] ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_POST['remember_import_export_action'] ) );
		if ( 'export_backup' !== $action ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		check_admin_referer( 'remember_import_export_action', 'remember_import_export_nonce' );
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-backup.php';
		Remember_Backup::download();
	}

	/**
	 * QuickBooks OAuth: redirect to Intuit and handle callback before admin HTML output.
	 * Running this in the settings view is too late — wp_redirect() fails after headers are sent (white screen).
	 *
	 * @since    1.0.0
	 */
	public function handle_quickbooks_oauth() {
		if ( ! is_admin() ) {
			return;
		}

		// Start OAuth (POST).
		if ( isset( $_POST['remember_settings_action'] ) && 'start_qb_oauth' === $_POST['remember_settings_action'] ) {
			if ( ! current_user_can( 'remember_access_settings' ) ) {
				return;
			}
			if ( ! check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
				return;
			}

			require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-quickbooks-oauth.php';

			$qb = Remember_QuickBooks_OAuth::get_settings();
			if ( empty( $qb['client_id'] ) || empty( $qb['client_secret'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=remember-settings&qb_oauth_error=nocreds#quickbooks' ) );
				exit;
			}

			$env     = isset( $_POST['qb_environment_oauth'] ) ? sanitize_text_field( wp_unslash( $_POST['qb_environment_oauth'] ) ) : 'sandbox';
			$pending = Remember_QuickBooks_OAuth::create_pending_oauth( $env );

			$auth_url = Remember_QuickBooks_OAuth::get_authorization_url(
				$qb['client_id'],
				Remember_QuickBooks_OAuth::get_redirect_uri(),
				$pending['state']
			);
			wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External Intuit URL.
			exit;
		}

		// OAuth callback (GET) — must be reMember settings screen.
		if ( ! isset( $_GET['page'] ) || 'remember-settings' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['qb_oauth_callback'] ) || ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-quickbooks-oauth.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-logger.php';

		$uid      = get_current_user_id();
		$notice   = 'remember_qb_oauth_notice_' . $uid;
		$redirect = admin_url( 'admin.php?page=remember-settings#quickbooks' );

		if ( ! empty( $_GET['error'] ) ) {
			$error       = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			$this->finish_qb_oauth_error(
				'access_denied' === $error
					? __( 'QuickBooks sign-in was cancelled or access was not granted. Nothing was changed.', 'remember' )
					: sprintf(
						/* translators: %s: error reported by Intuit */
						__( 'QuickBooks sign-in failed: %s. Nothing was changed.', 'remember' ),
						'' !== $description ? $description : $error
					),
				$redirect,
				'QuickBooks sign-in returned an error',
				array(
					'error'   => $error,
					'user_id' => $uid,
				)
			);
		}

		$code    = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$pending = Remember_QuickBooks_OAuth::get_pending_oauth();

		if ( ! $pending || '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
			$this->finish_qb_oauth_error(
				__( 'This QuickBooks sign-in could not be verified. It may have expired, been started in another tab, or been reloaded. Nothing was changed; click Connect or Reconnect QuickBooks again.', 'remember' ),
				$redirect,
				'QuickBooks sign-in state did not match',
				array(
					'has_pending' => (bool) $pending,
					'user_id'     => $uid,
				)
			);
		}

		// The state is single-use: consume it before any further work.
		Remember_QuickBooks_OAuth::clear_pending_oauth();

		if ( '' === $code ) {
			$this->finish_qb_oauth_error(
				__( 'QuickBooks did not return an authorization code. Nothing was changed; please try again.', 'remember' ),
				$redirect,
				'QuickBooks sign-in returned no authorization code',
				array( 'user_id' => $uid )
			);
		}

		$settings = Remember_QuickBooks_OAuth::get_settings();
		if ( ! $settings || empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			$this->finish_qb_oauth_error(
				__( 'QuickBooks credentials not configured. Please enter Client ID and Client Secret first.', 'remember' ),
				$redirect
			);
		}

		$returned_realm = '';
		if ( ! empty( $_GET['realmId'] ) ) {
			$returned_realm = sanitize_text_field( wp_unslash( $_GET['realmId'] ) );
		} elseif ( ! empty( $_GET['realmid'] ) ) {
			$returned_realm = sanitize_text_field( wp_unslash( $_GET['realmid'] ) );
		}

		if ( '' === $returned_realm ) {
			$this->finish_qb_oauth_error(
				__( 'QuickBooks did not say which company was authorized, so nothing was changed. Please try again.', 'remember' ),
				$redirect,
				'QuickBooks sign-in returned no company ID',
				array( 'user_id' => $uid )
			);
		}

		// Stored invoice and customer IDs are only meaningful in the company they were created in.
		$linked_realm = isset( $settings['realm_id'] ) ? (string) $settings['realm_id'] : '';
		if ( '' !== $linked_realm && $linked_realm !== $returned_realm ) {
			$this->finish_qb_oauth_error(
				sprintf(
					/* translators: 1: QuickBooks company ID that was just authorized, 2: QuickBooks company ID reMember is linked to */
					__( 'You signed in to a different QuickBooks company (ID %1$s) from the one reMember is linked to (ID %2$s), so nothing was changed and the existing connection is still in use. Reconnect and choose the same company, or check that the Environment setting matches it. To move reMember to a different company on purpose, click Disconnect QuickBooks first, then Connect. Invoices and customers already linked will still point to the old company.', 'remember' ),
					$returned_realm,
					$linked_realm
				),
				$redirect,
				'QuickBooks reconnect refused: a different company was authorized',
				array(
					'linked_realm_id'   => $linked_realm,
					'returned_realm_id' => $returned_realm,
					'user_id'           => $uid,
				)
			);
		}

		$token_data = Remember_QuickBooks_OAuth::exchange_code_for_token(
			$code,
			$settings['client_id'],
			$settings['client_secret'],
			Remember_QuickBooks_OAuth::get_redirect_uri()
		);

		if ( is_wp_error( $token_data ) ) {
			$this->finish_qb_oauth_error( wp_strip_all_tags( $token_data->get_error_message() ), $redirect );
		}

		$settings['access_token']  = $token_data['access_token'];
		$settings['refresh_token'] = $token_data['refresh_token'];
		$expires_in                = isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 3600;
		if ( $expires_in < 60 ) {
			$expires_in = 3600;
		}
		$settings['expires_at']  = time() + $expires_in;
		$settings['realm_id']    = $returned_realm;
		$settings['environment'] = $pending['environment'];

		if ( ! Remember_QuickBooks_OAuth::save_settings( $settings ) ) {
			Remember_Logger::error( 'QuickBooks connection could not be saved', array( 'realm_id' => $returned_realm ) );
			$this->finish_qb_oauth_error( __( 'Failed to save QuickBooks connection.', 'remember' ), $redirect );
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'remember_payment_processors',
			array( 'is_active' => 1 ),
			array( 'processor_type' => 'quickbooks' ),
			array( '%d' ),
			array( '%s' )
		);

		Remember_Logger::info(
			'QuickBooks connected',
			array(
				'realm_id'    => $settings['realm_id'],
				'environment' => isset( $settings['environment'] ) ? $settings['environment'] : 'sandbox',
				'reconnect'   => '' !== $linked_realm,
				'user_id'     => $uid,
			)
		);

		set_transient( $notice, array( 'type' => 'success', 'message' => __( 'QuickBooks connected successfully!', 'remember' ) ), 60 );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Xero OAuth: redirect to Xero and handle callback before admin HTML output.
	 *
	 * @since 1.1.0
	 */
	public function handle_xero_oauth() {
		if ( ! is_admin() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-xero-oauth.php';

		// Start OAuth (POST).
		if ( isset( $_POST['remember_settings_action'] ) && 'start_xero_oauth' === $_POST['remember_settings_action'] ) {
			if ( ! current_user_can( 'remember_access_settings' ) ) {
				return;
			}
			if ( ! check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
				return;
			}

			$xero = Remember_Xero_OAuth::get_settings();
			if ( empty( $xero['client_id'] ) || empty( $xero['client_secret'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=remember-settings&xero_oauth_error=nocreds#xero' ) );
				exit;
			}

			$pending = Remember_Xero_OAuth::create_pending_oauth();
			$auth_url = Remember_Xero_OAuth::get_authorization_url(
				$xero['client_id'],
				$pending['redirect_uri'],
				'',
				array(
					'state'          => $pending['state'],
					'code_challenge' => Remember_Xero_OAuth::pkce_challenge( $pending['code_verifier'] ),
				)
			);
			wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External Xero URL.
			exit;
		}

		// OAuth callback (GET). Accept the dedicated flag, or a code/state return if Xero dropped extra query args.
		if ( ! isset( $_GET['page'] ) || 'remember-settings' !== $_GET['page'] ) {
			return;
		}

		$pending     = Remember_Xero_OAuth::get_pending_oauth();
		$is_callback = isset( $_GET['xero_oauth_callback'] )
			|| ( isset( $_GET['code'] ) && isset( $_GET['state'] ) )
			|| ( isset( $_GET['error'] ) && is_array( $pending ) );
		if ( ! $is_callback ) {
			return;
		}
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			return;
		}

		$redirect = admin_url( 'admin.php?page=remember-settings#xero' );

		if ( ! empty( $_GET['error'] ) ) {
			$error_desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : sanitize_text_field( wp_unslash( $_GET['error'] ) );
			$this->finish_xero_oauth_error(
				sprintf(
					/* translators: %s: Xero error message */
					__( 'Xero authorization failed: %s', 'remember' ),
					$error_desc
				),
				$redirect
			);
		}

		if ( empty( $_GET['code'] ) ) {
			$this->finish_xero_oauth_error(
				__( 'Xero did not return an authorization code. In Xero, open the organisation → Settings → Connected apps, disconnect reMember, then click Reconnect Xero again.', 'remember' ),
				$redirect
			);
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( ! is_array( $pending ) || empty( $pending['state'] ) || ! hash_equals( (string) $pending['state'], $state ) ) {
			$this->finish_xero_oauth_error(
				__( 'Invalid OAuth state. Click Reconnect Xero again and finish the Xero prompt without using the browser Back button.', 'remember' ),
				$redirect
			);
		}

		$settings = Remember_Xero_OAuth::get_settings();
		if ( ! $settings || empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			$this->finish_xero_oauth_error(
				__( 'Xero credentials not configured. Please enter Client ID and Client Secret first.', 'remember' ),
				$redirect
			);
		}

		$redirect_uri  = ! empty( $pending['redirect_uri'] ) ? $pending['redirect_uri'] : Remember_Xero_OAuth::get_redirect_uri();
		$code_verifier = ! empty( $pending['code_verifier'] ) ? $pending['code_verifier'] : '';
		$token_data    = Remember_Xero_OAuth::exchange_code_for_token(
			$code,
			$settings['client_id'],
			$settings['client_secret'],
			$redirect_uri,
			$code_verifier
		);

		if ( is_wp_error( $token_data ) ) {
			$this->finish_xero_oauth_error( wp_strip_all_tags( $token_data->get_error_message() ), $redirect );
		}

		$settings['access_token']  = isset( $token_data['access_token'] ) ? $token_data['access_token'] : '';
		$settings['refresh_token'] = isset( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : '';
		$expires_in                = isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 1800;
		if ( $expires_in < 60 ) {
			$expires_in = 1800;
		}
		$settings['expires_at'] = time() + $expires_in;

		// Persist tokens before connections call so a connections failure is recoverable.
		Remember_Xero_OAuth::save_settings( $settings );

		$connections = Remember_Xero_OAuth::get_connections( $settings['access_token'] );
		if ( is_wp_error( $connections ) ) {
			$this->finish_xero_oauth_error( wp_strip_all_tags( $connections->get_error_message() ), $redirect );
		}

		if ( empty( $connections[0]['tenantId'] ) ) {
			$this->finish_xero_oauth_error(
				__( 'No Xero organisation was available to connect. Authorize at least one organisation.', 'remember' ),
				$redirect
			);
		}

		// Phase 1: use the first authorised tenant (multi-tenant picker can come later).
		$settings['tenant_id']     = sanitize_text_field( $connections[0]['tenantId'] );
		$settings['tenant_name']   = ! empty( $connections[0]['tenantName'] ) ? sanitize_text_field( $connections[0]['tenantName'] ) : '';
		$settings['connection_id'] = ! empty( $connections[0]['id'] ) ? sanitize_text_field( $connections[0]['id'] ) : '';

		if ( ! Remember_Xero_OAuth::save_settings( $settings ) ) {
			$this->finish_xero_oauth_error( __( 'Failed to save Xero connection.', 'remember' ), $redirect );
		}

		Remember_Xero_OAuth::clear_pending_oauth();

		// Cache org ShortCode for invoice deep links (best-effort).
		require_once plugin_dir_path( __FILE__ ) . '../includes/integrations/class-remember-xero-api.php';
		$org = Remember_Xero_API::get_organisation();
		if ( ! is_wp_error( $org ) && ! empty( $org['ShortCode'] ) ) {
			$settings                     = Remember_Xero_OAuth::get_settings();
			$settings['org_shortcode']    = sanitize_text_field( (string) $org['ShortCode'] );
			Remember_Xero_OAuth::save_settings( $settings );
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'remember_payment_processors',
			array( 'is_active' => 1 ),
			array( 'processor_type' => 'xero' ),
			array( '%d' ),
			array( '%s' )
		);

		$org_label = $settings['tenant_name'] ? $settings['tenant_name'] : $settings['tenant_id'];
		Remember_Xero_OAuth::set_last_oauth_result(
			'success',
			sprintf(
				/* translators: %s: Xero organisation name */
				__( 'Xero connected successfully (%s).', 'remember' ),
				$org_label
			)
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Store a Xero OAuth failure and return to Settings → Xero.
	 *
	 * @param string $message  Error message.
	 * @param string $redirect Admin URL.
	 */
	private function finish_xero_oauth_error( $message, $redirect ) {
		Remember_Xero_OAuth::set_last_oauth_result( 'error', $message );
		Remember_Xero_OAuth::clear_pending_oauth();
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * End a QuickBooks sign-in attempt with an error notice for the settings tab.
	 *
	 * @param string $message     Message shown to the admin.
	 * @param string $redirect    Settings URL to return to.
	 * @param string $log_message Warning to log, or '' when the caller already logged the failure.
	 * @param array  $context     Log context.
	 */
	private function finish_qb_oauth_error( $message, $redirect, $log_message = '', $context = array() ) {
		if ( '' !== $log_message ) {
			Remember_Logger::warning( $log_message, $context );
		}
		Remember_QuickBooks_OAuth::clear_pending_oauth();
		set_transient(
			'remember_qb_oauth_notice_' . get_current_user_id(),
			array(
				'type'    => 'error',
				'message' => $message,
			),
			2 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Process setup wizard form submission.
	 * Called on admin_init to process before any output.
	 *
	 * @since    1.0.0
	 */
	public function process_setup_wizard() {
		// Only process if we're on the setup wizard page
		if ( ! isset( $_GET['page'] ) || 'remember-setup' !== $_GET['page'] ) {
			return;
		}

		// Only process POST requests
		if ( ! isset( $_POST['remember_setup_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'remember_access_settings' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to perform this action.', 'remember' ),
				esc_html__( 'Access Denied', 'remember' ),
				array( 'response' => 403 )
			);
		}

		if ( ! check_admin_referer( 'remember_setup_wizard', 'remember_setup_nonce' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-page-creator.php';
		
		$action = sanitize_text_field( $_POST['remember_setup_action'] );
		
		if ( 'setup_pages' === $action ) {
			$default_pages = Remember_Page_Creator::get_default_pages();
			$pages_created = 0;
			$pages_linked = 0;
			
			foreach ( $default_pages as $key => $page_data ) {
				$selection = isset( $_POST[ 'page_' . $key ] ) ? sanitize_text_field( $_POST[ 'page_' . $key ] ) : 'skip';
				
				if ( 'create' === $selection ) {
					// Create new page
					$created = Remember_Page_Creator::create_pages( array( $key ) );
					if ( ! empty( $created ) ) {
						$pages_created++;
					}
				} elseif ( 'skip' !== $selection && is_numeric( $selection ) ) {
					// Link to existing page - add shortcode if not already present
					$page_id = absint( $selection );
					$page = get_post( $page_id );
					
					if ( $page && 'page' === $page->post_type ) {
						// Store the mapping
						$created_pages = Remember_Page_Creator::get_created_pages();
						$created_pages[ $key ] = $page_id;
						update_option( 'remember_created_pages', $created_pages );
						
						// Add shortcode to page if not already present
						if ( strpos( $page->post_content, $page_data['shortcode'] ) === false ) {
							$new_content = $page->post_content . "\n\n" . $page_data['shortcode'];
							wp_update_post( array(
								'ID' => $page_id,
								'post_content' => $new_content,
							) );
						}
						$pages_linked++;
					}
				}
			}
			
			// Clear the setup wizard transient
			delete_transient( 'remember_show_setup_wizard' );
			
			// Redirect to settings page with success message
			$message = '';
			if ( $pages_created > 0 && $pages_linked > 0 ) {
				$message = sprintf(
					__( 'Successfully created %d page(s) and linked %d page(s).', 'remember' ),
					$pages_created,
					$pages_linked
				);
			} elseif ( $pages_created > 0 ) {
				$message = sprintf(
					_n( 'Successfully created %d page.', 'Successfully created %d pages.', $pages_created, 'remember' ),
					$pages_created
				);
			} elseif ( $pages_linked > 0 ) {
				$message = sprintf(
					_n( 'Successfully linked %d page.', 'Successfully linked %d pages.', $pages_linked, 'remember' ),
					$pages_linked
				);
			}
			
			if ( $message ) {
				wp_safe_redirect( add_query_arg( 'pages_setup', urlencode( $message ), admin_url( 'admin.php?page=remember-settings' ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=remember-settings' ) );
			}
			exit;
		} elseif ( 'skip' === $action ) {
			// Clear the setup wizard transient
			delete_transient( 'remember_show_setup_wizard' );
			wp_safe_redirect( admin_url( 'admin.php?page=remember-settings' ) );
			exit;
		}
	}

	/**
	 * Render the setup wizard page.
	 *
	 * @since    1.0.0
	 */
	public function display_setup_wizard() {
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access this page.', 'remember' ),
				esc_html__( 'Access Denied', 'remember' ),
				array( 'response' => 403 )
			);
		}

		include_once 'views/setup-wizard.php';
	}

	/**
	 * Check if setup wizard should be shown and redirect.
	 *
	 * @since    1.0.0
	 */
	public function maybe_show_setup_wizard() {
		// Only show to admins
		if ( ! current_user_can( 'remember_access_settings' ) ) {
			return;
		}

		// Check if we should show the setup wizard
		if ( get_transient( 'remember_show_setup_wizard' ) ) {
			// Don't redirect if already on setup page
			if ( isset( $_GET['page'] ) && 'remember-setup' === $_GET['page'] ) {
				return;
			}

			// Redirect to setup wizard
			wp_safe_redirect( admin_url( 'admin.php?page=remember-setup' ) );
			exit;
		}
	}

	/**
	 * AJAX handler to get event roles for an event.
	 *
	 * @since    1.0.0
	 */
	public function ajax_get_event_roles() {
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-logger.php';
		
		Remember_Logger::debug( 'ajax_get_event_roles: Starting', array( 'POST' => $_POST ) );

		// Admin vs front use different nonces so a front-end nonce cannot unlock the full role list.
		$context       = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'front';
		$is_admin_path = false;
		if ( 'admin' === $context ) {
			check_ajax_referer( 'remember_get_event_roles_admin', 'nonce' );
			if ( ! current_user_can( 'remember_create_applications' ) && ! current_user_can( 'remember_update_applications' ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'remember' ) ) );
			}
			$is_admin_path = true;
		} else {
			check_ajax_referer( 'remember_get_event_roles', 'nonce' );
		}
		
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		Remember_Logger::debug( 'ajax_get_event_roles: Event ID', array( 'event_id' => $event_id ) );
		
		if ( $event_id <= 0 ) {
			Remember_Logger::error( 'ajax_get_event_roles: Invalid event ID' );
			wp_send_json_error( array( 'message' => __( 'Invalid event ID.', 'remember' ) ) );
		}
		
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		
		$event_model = new Remember_Event();
		$member_model = new Remember_Member();
		
		global $wpdb;
		
		// Check if show_in_frontend column exists
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}remember_roles" );
		$has_show_in_frontend = in_array( 'show_in_frontend', $columns, true );
		Remember_Logger::debug( 'ajax_get_event_roles: Column check', array( 
			'has_show_in_frontend' => $has_show_in_frontend,
			'columns' => $columns 
		) );

		Remember_Logger::debug( 'ajax_get_event_roles: Path check', array(
			'context'       => $context,
			'is_admin_path' => $is_admin_path,
			'user_id'       => get_current_user_id(),
			'is_logged_in'  => is_user_logged_in(),
		) );
		
		if ( $is_admin_path ) {
			// Staff creating/editing applications in wp-admin: all event roles.
			Remember_Logger::debug( 'ajax_get_event_roles: Admin path - getting all roles' );
			$event_roles = $event_model->get_event_roles( $event_id );
			Remember_Logger::debug( 'ajax_get_event_roles: Admin results', array( 
				'count' => count( $event_roles ),
				'roles' => array_map( function( $r ) { 
					return array( 
						'event_role_id' => $r->event_role_id, 
						'role_id' => $r->role_id,
						'role_name' => $r->role_name,
						'show_in_frontend' => isset( $r->show_in_frontend ) ? $r->show_in_frontend : 'not set'
					); 
				}, $event_roles )
			) );
		} else {
			// For non-admin users, filter at the SQL level:
			// 1. Only roles the member has assigned (join with member_roles)
			// 2. Only roles with show_in_frontend != 0
			// 3. Only event roles (role_type = 'event')
			if ( is_user_logged_in() ) {
				$member_id = get_current_user_id();
				Remember_Logger::debug( 'ajax_get_event_roles: Non-admin logged in user', array( 'member_id' => $member_id ) );
				
				// Get member's assigned event role IDs for logging
				$member_event_role_ids = $member_model->get_member_event_role_ids( $member_id );
				Remember_Logger::debug( 'ajax_get_event_roles: Member event role IDs', array( 
					'member_id' => $member_id,
					'role_ids' => $member_event_role_ids 
				) );
				
				if ( $has_show_in_frontend ) {
					// Filter by member roles AND show_in_frontend
					$query = $wpdb->prepare(
						"SELECT DISTINCT er.*, r.role_name, r.show_in_frontend 
						FROM {$wpdb->prefix}remember_event_roles er 
						JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
						JOIN {$wpdb->prefix}remember_member_roles mr ON r.role_id = mr.role_id 
						WHERE er.event_id = %d 
						AND mr.member_id = %d 
						AND r.role_type = 'event'
						AND COALESCE(r.show_in_frontend, 1) != 0
						ORDER BY r.role_name ASC",
						$event_id,
						$member_id
					);
					Remember_Logger::debug( 'ajax_get_event_roles: Executing SQL with show_in_frontend filter', array( 
						'query' => $query,
						'event_id' => $event_id,
						'member_id' => $member_id
					) );
					$event_roles = $wpdb->get_results( $query );
					Remember_Logger::debug( 'ajax_get_event_roles: Query results', array( 
						'count' => count( $event_roles ),
						'roles' => array_map( function( $r ) { 
							return array( 
								'event_role_id' => $r->event_role_id, 
								'role_id' => $r->role_id,
								'role_name' => $r->role_name,
								'show_in_frontend' => isset( $r->show_in_frontend ) ? $r->show_in_frontend : 'not set'
							); 
						}, $event_roles ),
						'wpdb_last_error' => $wpdb->last_error
					) );
				} else {
					// Column doesn't exist yet - filter by member roles only
					$query = $wpdb->prepare(
						"SELECT DISTINCT er.*, r.role_name 
						FROM {$wpdb->prefix}remember_event_roles er 
						JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
						JOIN {$wpdb->prefix}remember_member_roles mr ON r.role_id = mr.role_id 
						WHERE er.event_id = %d 
						AND mr.member_id = %d 
						AND r.role_type = 'event'
						ORDER BY r.role_name ASC",
						$event_id,
						$member_id
					);
					Remember_Logger::debug( 'ajax_get_event_roles: Executing SQL without show_in_frontend filter', array( 
						'query' => $query,
						'event_id' => $event_id,
						'member_id' => $member_id
					) );
					$event_roles = $wpdb->get_results( $query );
					Remember_Logger::debug( 'ajax_get_event_roles: Query results', array( 
						'count' => count( $event_roles ),
						'roles' => array_map( function( $r ) { 
							return array( 
								'event_role_id' => $r->event_role_id, 
								'role_id' => $r->role_id,
								'role_name' => $r->role_name
							); 
						}, $event_roles ),
						'wpdb_last_error' => $wpdb->last_error
					) );
				}
			} else {
				// Not logged in - return empty (can't apply without being logged in)
				Remember_Logger::debug( 'ajax_get_event_roles: User not logged in, returning empty' );
				$event_roles = array();
			}
		}
		
		// Format for response - return event_role_id and role_name
		$formatted_roles = array();
		foreach ( $event_roles as $event_role ) {
			$formatted_roles[] = array(
				'event_role_id' => $event_role->event_role_id,
				'role_name'     => $event_role->role_name,
			);
		}
		
		Remember_Logger::debug( 'ajax_get_event_roles: Final formatted response', array( 
			'count' => count( $formatted_roles ),
			'roles' => $formatted_roles
		) );
		
		wp_send_json_success( $formatted_roles );
	}

	/**
	 * AJAX handler to get event add-ons for an event.
	 *
	 * @since    1.0.0
	 */
	public function ajax_get_event_addons() {
		check_ajax_referer( 'remember_get_event_addons', 'nonce' );

		$event_id      = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$event_role_id = isset( $_POST['event_role_id'] ) ? absint( $_POST['event_role_id'] ) : 0;
		if ( $event_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event ID.', 'remember' ) ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-addon-role-limits.php';

		if ( $event_role_id > 0 ) {
			$addons = Remember_Addon_Role_Limits::get_available_addons_for_role( $event_id, $event_role_id );
		} else {
			// No role yet — return empty so the UI waits for role selection.
			$addons = array();
		}

		$formatted_addons = array();
		foreach ( $addons as $addon ) {
			$formatted_addons[] = array(
				'merchandise_id'   => absint( $addon->merchandise_id ),
				'merchandise_name' => $addon->merchandise_name,
				'description'      => $addon->description,
				'cost'             => floatval( $addon->cost ),
				'max_quantity'     => isset( $addon->max_quantity ) ? absint( $addon->max_quantity ) : 1,
			);
		}

		wp_send_json_success( $formatted_addons );
	}

	/**
	 * AJAX: HTML for agreements required on apply for an event.
	 *
	 * @return void
	 */
	public function ajax_get_event_agreements() {
		check_ajax_referer( 'remember_get_event_agreements', 'nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		if ( $event_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event ID.', 'remember' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'remember' ) ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-agreements.php';
		wp_send_json_success(
			array(
				'html' => Remember_Agreements::render_apply_html( $event_id ),
			)
		);
	}

	/**
	 * AJAX: application survey HTML, empty when the event has none.
	 *
	 * @return void
	 */
	public function ajax_get_event_survey() {
		check_ajax_referer( 'remember_get_event_survey', 'nonce' );

		$event_id      = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$event_role_id = isset( $_POST['event_role_id'] ) ? absint( $_POST['event_role_id'] ) : 0;
		if ( $event_id <= 0 || ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'remember' ) ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-surveys.php';
		$html = Remember_Surveys::render_apply_html( $event_id, $event_role_id );
		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	/**
	 * Register dashboard widget.
	 *
	 * @since    1.0.0
	 */
	public function register_dashboard_widget() {
		// Only show to users with at least read_events capability
		if ( ! current_user_can( 'remember_read_events' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'remember_dashboard_widget',
			__( 'reMember Overview', 'remember' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render dashboard widget content.
	 *
	 * @since    1.0.0
	 */
	public function render_dashboard_widget() {
		// Load models
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-application.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-vetting.php';

		$member_model      = new Remember_Member();
		$event_model       = new Remember_Event();
		$application_model = new Remember_Application();
		$vetting_model     = new Remember_Vetting();

		// Get statistics (only count valid members with existing WordPress users)
		$all_members = $member_model->get_all_valid();
		$total_members = count( $all_members );

		$pending_vetting = $member_model->get_by_status( 'pending_vetting' );
		$in_vetting = $member_model->get_by_status( 'in_vetting' );
		$vetted = $member_model->get_by_status( 'vetted' );

		$pending_vetting_count = count( $pending_vetting );
		$in_vetting_count = count( $in_vetting );
		$vetted_count = count( $vetted );

		$open_events = $event_model->get_open();
		$upcoming_events = $event_model->get_upcoming();

		$pending_applications = $application_model->get_by_status( 'pending' );
		$pending_applications_count = count( $pending_applications );

		$open_vetting_records = $vetting_model->get_open();
		$open_vetting_records_count = count( $open_vetting_records );

		// Include widget view
		include plugin_dir_path( __FILE__ ) . 'views/dashboard-widget.php';
	}

	/**
	 * Add timezone field to WordPress user profile.
	 *
	 * @param WP_User $user User object.
	 */
	public function add_timezone_field_to_profile( $user ) {
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-timezone.php';
		
		$selected_timezone = get_user_meta( $user->ID, 'timezone_string', true );
		if ( empty( $selected_timezone ) ) {
			$selected_timezone = 'America/Los_Angeles'; // Default
			// Auto-assign default timezone
			update_user_meta( $user->ID, 'timezone_string', $selected_timezone );
		}
		?>
		<h2><?php esc_html_e( 'reMember Settings', 'remember' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="timezone_string"><?php esc_html_e( 'Time Zone', 'remember' ); ?> <span class="description"><?php esc_html_e( '(required)', 'remember' ); ?></span></label></th>
				<td>
					<?php echo Remember_Timezone::dropdown( $selected_timezone, 'timezone_string', 'timezone_string', true ); ?>
					<p class="description"><?php echo esc_html( Remember_Timezone::help_text() ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save timezone field from WordPress user profile.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_timezone_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		
		if ( isset( $_POST['timezone_string'] ) ) {
			$timezone_string = sanitize_text_field( $_POST['timezone_string'] );
			if ( ! empty( $timezone_string ) ) {
				update_user_meta( $user_id, 'timezone_string', $timezone_string );
			} else {
				// Default if empty
				update_user_meta( $user_id, 'timezone_string', 'America/Los_Angeles' );
			}
		}
	}
}
