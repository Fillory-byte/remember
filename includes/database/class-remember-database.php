<?php
/**
 * Database schema creation and management
 *
 * @package    reMember
 * @subpackage reMember/includes/database
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Database schema creation and management class.
 *
 * @package    reMember
 * @subpackage reMember/includes/database
 */
class Remember_Database {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb   = $wpdb;
		$this->prefix = $wpdb->prefix . 'remember_';
	}

	/**
	 * Create all database tables.
	 *
	 * @since    1.0.0
	 */
	public function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';

		$tables = array(
			'members'                        => 'create_members_table',
			'member_profiles'                => 'create_member_profiles_table',
			'social_media_platforms'         => 'create_social_media_platforms_table',
			'im_platforms'                   => 'create_im_platforms_table',
			'member_social_media'            => 'create_member_social_media_table',
			'dietary_restrictions'           => 'create_dietary_restrictions_table',
			'member_dietary_restrictions'    => 'create_member_dietary_restrictions_table',
			'allergies'                      => 'create_allergies_table',
			'member_allergies'               => 'create_member_allergies_table',
			'clothing_size_options'          => 'create_clothing_size_options_table',
			'clothing_stock'                 => 'create_clothing_stock_table',
			'medical_accommodations'         => 'create_medical_accommodations_table',
			'member_medical_accommodations'  => 'create_member_medical_accommodations_table',
			'roles'                          => 'create_roles_table',
			'member_roles'                   => 'create_member_roles_table',
			'role_capabilities'              => 'create_role_capabilities_table',
			'locations'                      => 'create_locations_table',
			'events'                         => 'create_events_table',
			'event_invites'                  => 'create_event_invites_table',
			'event_roles'                    => 'create_event_roles_table',
			'event_merchandise'              => 'create_event_merchandise_table',
			'event_merchandise_role_limits'  => 'create_event_merchandise_role_limits_table',
			'event_applications'             => 'create_event_applications_table',
			'application_merchandise'        => 'create_application_merchandise_table',
			'products'                       => 'create_products_table',
			'qb_item_mappings'               => 'create_qb_item_mappings_table',
			'xero_item_mappings'             => 'create_xero_item_mappings_table',
			'payment_processors'             => 'create_payment_processors_table',
			'payments'                       => 'create_payments_table',
			'vetting'                        => 'create_vetting_table',
			'vetting_collaborators'          => 'create_vetting_collaborators_table',
			'vetting_notes'                  => 'create_vetting_notes_table',
			'profile_notes'                  => 'create_profile_notes_table',
			'profile_duplicate_hits'         => 'create_profile_duplicate_hits_table',
			'notification_settings'          => 'create_notification_settings_table',
			'profile_questions'              => 'create_profile_questions_table',
			'profile_question_responses'     => 'create_profile_question_responses_table',
			'saved_reports'                  => 'create_saved_reports_table',
			'report_schedules'               => 'create_report_schedules_table',
			'agreements'                     => 'create_agreements_table',
			'agreement_revisions'            => 'create_agreement_revisions_table',
			'event_agreements'               => 'create_event_agreements_table',
			'agreement_acceptances'          => 'create_agreement_acceptances_table',
			'surveys'                        => 'create_surveys_table',
			'survey_roles'                   => 'create_survey_roles_table',
			'survey_questions'               => 'create_survey_questions_table',
			'survey_responses'               => 'create_survey_responses_table',
			'survey_answers'                 => 'create_survey_answers_table',
			'sensitive_access_log'           => 'create_sensitive_access_log_table',
			'plugin_version'                 => 'create_plugin_version_table',
		);

		foreach ( $tables as $label => $method ) {
			Remember_Logger::activation_debug( 'dbDelta: ' . $label );
			$this->{$method}();
		}
	}

	/**
	 * Create members table.
	 */
	private function create_members_table() {
		$table_name = $this->prefix . 'members';
		$charset_collate = $this->wpdb->get_charset_collate();

		$exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		Remember_Logger::activation_debug( 'create_members_table: SHOW TABLES done', array( 'table' => $table_name, 'exists' => (bool) $exists ) );

		$row_count = null;
		if ( $exists ) {
			Remember_Logger::activation_debug( 'create_members_table: COUNT(*) start' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled prefix + literal.
			$row_count = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );
			Remember_Logger::activation_debug( 'create_members_table: COUNT(*) end', array( 'row_count' => $row_count ) );
		}

		$sql = "CREATE TABLE $table_name (
			member_id BIGINT(20) UNSIGNED NOT NULL,
			status ENUM('pending_vetting', 'unvetted', 'in_vetting', 'vetted', 'rejected', 'inactive', 'merged') DEFAULT 'pending_vetting',
			photo_url VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (member_id),
			KEY status (status)
		) $charset_collate;";

		Remember_Logger::activation_debug( 'create_members_table: dbDelta( members ) start' );
		dbDelta( $sql );
		Remember_Logger::activation_debug( 'create_members_table: dbDelta( members ) end' );
	}

	/**
	 * Create member profiles table.
	 */
	private function create_member_profiles_table() {
		$table_name = $this->prefix . 'member_profiles';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			profile_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			legal_first_name VARCHAR(100) NOT NULL,
			legal_last_name VARCHAR(100) NOT NULL,
			address_street VARCHAR(255) DEFAULT NULL,
			address_city VARCHAR(100) DEFAULT NULL,
			address_state VARCHAR(50) DEFAULT NULL,
			address_postal VARCHAR(20) DEFAULT NULL,
			address_country VARCHAR(100) DEFAULT NULL,
			cell_phone VARCHAR(50) DEFAULT NULL,
			timezone VARCHAR(50) DEFAULT NULL,
			im_handle VARCHAR(100) DEFAULT NULL,
			im_type VARCHAR(50) DEFAULT 'telegram',
			interests TEXT DEFAULT NULL,
			allergy_reaction TEXT DEFAULT NULL,
			shirt_size VARCHAR(20) DEFAULT NULL,
			pants_size VARCHAR(20) DEFAULT NULL,
			shoe_size VARCHAR(20) DEFAULT NULL,
			emergency_contact_first VARCHAR(100) NOT NULL,
			emergency_contact_last VARCHAR(100) NOT NULL,
			emergency_contact_phone VARCHAR(50) NOT NULL,
			emergency_contact_relationship VARCHAR(50) NOT NULL,
			share_email_with_events TINYINT(1) DEFAULT 0,
			share_phone_with_events TINYINT(1) DEFAULT 0,
			share_location_with_events TINYINT(1) DEFAULT 0,
			share_im_with_events TINYINT(1) DEFAULT 0,
			share_interests_with_events TINYINT(1) DEFAULT 0,
			share_photo_with_events TINYINT(1) DEFAULT 0,
			member_number VARCHAR(50) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			updated_by BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY (profile_id),
			UNIQUE KEY member_id (member_id),
			UNIQUE KEY member_number (member_number),
			KEY updated_by (updated_by)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create social media platforms table.
	 */
	private function create_social_media_platforms_table() {
		$table_name = $this->prefix . 'social_media_platforms';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			platform_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			platform_name VARCHAR(100) NOT NULL,
			is_active BOOLEAN DEFAULT 1,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (platform_id),
			UNIQUE KEY platform_name (platform_name)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create instant messenger platforms catalog table.
	 */
	public function create_im_platforms_table() {
		$table_name      = $this->prefix . 'im_platforms';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			platform_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			platform_key VARCHAR(50) NOT NULL,
			platform_name VARCHAR(100) NOT NULL,
			is_active TINYINT(1) DEFAULT 1,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (platform_id),
			UNIQUE KEY platform_key (platform_key)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create member social media table.
	 */
	private function create_member_social_media_table() {
		$table_name = $this->prefix . 'member_social_media';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			social_media_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			platform_id BIGINT(20) UNSIGNED NOT NULL,
			handle VARCHAR(255) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (social_media_id),
			KEY member_id (member_id),
			KEY platform_id (platform_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create dietary restrictions table.
	 */
	private function create_dietary_restrictions_table() {
		$table_name = $this->prefix . 'dietary_restrictions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			restriction_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			restriction_name VARCHAR(100) NOT NULL,
			is_active BOOLEAN DEFAULT 1,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (restriction_id),
			UNIQUE KEY restriction_name (restriction_name)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create member dietary restrictions table.
	 */
	private function create_member_dietary_restrictions_table() {
		$table_name = $this->prefix . 'member_dietary_restrictions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			member_id BIGINT(20) UNSIGNED NOT NULL,
			restriction_id BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (member_id, restriction_id),
			KEY restriction_id (restriction_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create clothing size options table (shirt / pants / shoe catalogs).
	 */
	public function create_clothing_size_options_table() {
		$table_name      = $this->prefix . 'clothing_size_options';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			size_category ENUM('shirt','pants','shoe') NOT NULL,
			size_code VARCHAR(20) NOT NULL,
			is_active TINYINT(1) DEFAULT 1,
			is_stocked TINYINT(1) DEFAULT 1,
			issued_size_code VARCHAR(20) DEFAULT NULL,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (option_id),
			UNIQUE KEY category_code (size_category, size_code),
			KEY size_category (size_category)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Write-in stock sizes (what you actually have to issue). Qty is a later table.
	 */
	public function create_clothing_stock_table() {
		$table_name      = $this->prefix . 'clothing_stock';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			stock_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			size_category ENUM('shirt','pants','shoe') NOT NULL,
			size_code VARCHAR(20) NOT NULL,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (stock_id),
			UNIQUE KEY category_code (size_category, size_code),
			KEY size_category (size_category)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create allergies table.
	 */
	private function create_allergies_table() {
		$table_name = $this->prefix . 'allergies';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			allergy_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			allergy_name VARCHAR(100) NOT NULL,
			is_active BOOLEAN DEFAULT 1,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (allergy_id),
			UNIQUE KEY allergy_name (allergy_name)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create member allergies table.
	 */
	private function create_member_allergies_table() {
		$table_name = $this->prefix . 'member_allergies';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			member_id BIGINT(20) UNSIGNED NOT NULL,
			allergy_id BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (member_id, allergy_id),
			KEY allergy_id (allergy_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create medical accommodations table.
	 */
	private function create_medical_accommodations_table() {
		$table_name = $this->prefix . 'medical_accommodations';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			accommodation_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			accommodation_name VARCHAR(100) NOT NULL,
			description TEXT DEFAULT NULL,
			is_active BOOLEAN DEFAULT 1,
			sort_order INT(11) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (accommodation_id),
			UNIQUE KEY accommodation_name (accommodation_name)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create member medical accommodations table.
	 */
	private function create_member_medical_accommodations_table() {
		$table_name = $this->prefix . 'member_medical_accommodations';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			member_id BIGINT(20) UNSIGNED NOT NULL,
			accommodation_id BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (member_id, accommodation_id),
			KEY accommodation_id (accommodation_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create roles table.
	 */
	private function create_roles_table() {
		$table_name = $this->prefix . 'roles';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			role_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			role_name VARCHAR(100) NOT NULL,
			role_type ENUM('event', 'system') DEFAULT 'event',
			is_event_role BOOLEAN DEFAULT 1,
			show_in_frontend BOOLEAN DEFAULT 1,
			description TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (role_id),
			UNIQUE KEY role_name (role_name)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create member roles table.
	 */
	private function create_member_roles_table() {
		$table_name = $this->prefix . 'member_roles';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			member_role_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			role_id BIGINT(20) UNSIGNED NOT NULL,
			approved_at DATETIME DEFAULT NULL,
			approved_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (member_role_id),
			UNIQUE KEY member_role (member_id, role_id),
			KEY member_id (member_id),
			KEY role_id (role_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create role capabilities table.
	 */
	private function create_role_capabilities_table() {
		$table_name = $this->prefix . 'role_capabilities';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			role_capability_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			role_id BIGINT(20) UNSIGNED NOT NULL,
			capability VARCHAR(100) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (role_capability_id),
			UNIQUE KEY role_capability (role_id, capability),
			KEY role_id (role_id),
			KEY capability (capability)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create locations table.
	 */
	private function create_locations_table() {
		$table_name = $this->prefix . 'locations';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			location_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			location_name VARCHAR(255) NOT NULL,
			logo_url VARCHAR(255) DEFAULT NULL,
			address_street VARCHAR(255) DEFAULT NULL,
			address_city VARCHAR(100) DEFAULT NULL,
			address_state VARCHAR(50) DEFAULT NULL,
			address_postal VARCHAR(20) DEFAULT NULL,
			address_country VARCHAR(2) DEFAULT 'US',
			details TEXT DEFAULT NULL,
			is_active BOOLEAN DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (location_id),
			KEY address_city (address_city),
			KEY address_state (address_state),
			KEY address_country (address_country)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create events table.
	 */
	private function create_events_table() {
		$table_name = $this->prefix . 'events';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			event_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_name VARCHAR(255) NOT NULL,
			event_description TEXT DEFAULT NULL,
			attendee_details TEXT DEFAULT NULL,
			location_id BIGINT(20) UNSIGNED DEFAULT NULL,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			registration_opens_at DATETIME DEFAULT NULL,
			registration_closes_at DATETIME DEFAULT NULL,
			waitlist_mode VARCHAR(20) NOT NULL DEFAULT 'off',
			checkin_enabled TINYINT(1) NOT NULL DEFAULT 0,
			is_private BOOLEAN DEFAULT 0,
			status ENUM('draft', 'open', 'closed', 'completed', 'cancelled') DEFAULT 'draft',
			created_by BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (event_id),
			KEY location_id (location_id),
			KEY status (status),
			KEY start_date (start_date)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Private-event invitees.
	 */
	public function create_event_invites_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table_name      = $this->prefix . 'event_invites';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			invite_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			invited_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			invited_at DATETIME NOT NULL,
			invited_emailed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (invite_id),
			UNIQUE KEY event_member (event_id, member_id),
			KEY event_id (event_id),
			KEY member_id (member_id),
			KEY invited_emailed_at (invited_emailed_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create event roles table.
	 */
	private function create_event_roles_table() {
		$table_name = $this->prefix . 'event_roles';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			event_role_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			role_id BIGINT(20) UNSIGNED NOT NULL,
			cost DECIMAL(10,2) DEFAULT 0.00,
			max_participants INT(11) DEFAULT NULL,
			current_count INT(11) DEFAULT 0,
			is_active BOOLEAN DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (event_role_id),
			KEY event_id (event_id),
			KEY role_id (role_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create event merchandise table.
	 */
	private function create_event_merchandise_table() {
		$table_name = $this->prefix . 'event_merchandise';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			merchandise_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			merchandise_name VARCHAR(255) NOT NULL,
			description TEXT DEFAULT NULL,
			cost DECIMAL(10,2) NOT NULL,
			quickbooks_product_id VARCHAR(100) DEFAULT NULL,
			quickbooks_product_name VARCHAR(255) DEFAULT NULL,
			max_quantity INT(11) DEFAULT NULL,
			is_available BOOLEAN DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (merchandise_id),
			KEY event_id (event_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create per event-role max qty for event add-ons (0 = hide from that role).
	 */
	public function create_event_merchandise_role_limits_table() {
		$table_name      = $this->prefix . 'event_merchandise_role_limits';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			limit_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			merchandise_id BIGINT(20) UNSIGNED NOT NULL,
			event_role_id BIGINT(20) UNSIGNED NOT NULL,
			max_qty INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (limit_id),
			UNIQUE KEY merchandise_role (merchandise_id, event_role_id),
			KEY event_role_id (event_role_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create event applications table.
	 */
	private function create_event_applications_table() {
		$table_name = $this->prefix . 'event_applications';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			application_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			event_role_id BIGINT(20) UNSIGNED NOT NULL,
			status ENUM('pending', 'accepted', 'declined', 'cancelled', 'waitlisted') DEFAULT 'pending',
			applied_at DATETIME NOT NULL,
			waitlisted_at DATETIME DEFAULT NULL,
			processed_at DATETIME DEFAULT NULL,
			processed_by BIGINT(20) UNSIGNED DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			ticket_voided TINYINT(1) NOT NULL DEFAULT 0,
			ticket_ready_emailed_at DATETIME DEFAULT NULL,
			superseded_at DATETIME DEFAULT NULL,
			checked_in_at DATETIME DEFAULT NULL,
			checked_in_by BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY (application_id),
			KEY event_member_role_active (event_id, member_id, event_role_id, superseded_at),
			KEY event_id (event_id),
			KEY member_id (member_id),
			KEY event_role_id (event_role_id),
			KEY status (status)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create application merchandise table.
	 */
	private function create_application_merchandise_table() {
		$table_name = $this->prefix . 'application_merchandise';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			application_merchandise_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_application_id BIGINT(20) UNSIGNED NOT NULL,
			merchandise_id BIGINT(20) UNSIGNED NOT NULL,
			quantity INT(11) DEFAULT 1,
			unit_cost DECIMAL(10,2) NOT NULL,
			total_cost DECIMAL(10,2) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (application_merchandise_id),
			KEY event_application_id (event_application_id),
			KEY merchandise_id (merchandise_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create products table (add-on catalog only; QuickBooks mapping is remember_qb_item_mappings).
	 */
	private function create_products_table() {
		$table_name = $this->prefix . 'products';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			product_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_name VARCHAR(255) NOT NULL,
			description TEXT DEFAULT NULL,
			product_type VARCHAR(50) DEFAULT NULL,
			default_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			is_active BOOLEAN DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (product_id),
			UNIQUE KEY product_name (product_name)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create QuickBooks item mapping table (event roles + catalog products).
	 *
	 * Public so migrations can ensure the table exists before data moves.
	 */
	public function create_qb_item_mappings_table() {
		$table_name = $this->prefix . 'qb_item_mappings';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			mapping_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type ENUM('role','product') NOT NULL,
			entity_id BIGINT(20) UNSIGNED NOT NULL,
			quickbooks_product_id VARCHAR(100) DEFAULT NULL,
			quickbooks_product_name VARCHAR(255) DEFAULT NULL,
			last_sync_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (mapping_id),
			UNIQUE KEY entity (entity_type, entity_id),
			KEY idx_quickbooks_product_id (quickbooks_product_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create Xero item mappings table (roles + catalog products).
	 */
	public function create_xero_item_mappings_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->prefix . 'xero_item_mappings';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			mapping_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type ENUM('role','product') NOT NULL,
			entity_id BIGINT(20) UNSIGNED NOT NULL,
			xero_item_id VARCHAR(100) DEFAULT NULL,
			xero_item_name VARCHAR(255) DEFAULT NULL,
			last_sync_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (mapping_id),
			UNIQUE KEY entity (entity_type, entity_id),
			KEY idx_xero_item_id (xero_item_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create payment processors table.
	 */
	private function create_payment_processors_table() {
		$table_name = $this->prefix . 'payment_processors';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			processor_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			processor_type ENUM('manual', 'quickbooks', 'xero') NOT NULL,
			processor_name VARCHAR(100) NOT NULL,
			is_active BOOLEAN DEFAULT 0,
			settings TEXT DEFAULT NULL,
			last_sync_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (processor_id),
			UNIQUE KEY processor_type (processor_type)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create payments table.
	 */
	private function create_payments_table() {
		$table_name = $this->prefix . 'payments';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			payment_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_application_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			processor_id BIGINT(20) UNSIGNED DEFAULT NULL,
			role_cost DECIMAL(10,2) DEFAULT 0.00,
			merchandise_cost DECIMAL(10,2) DEFAULT 0.00,
			total_amount DECIMAL(10,2) NOT NULL,
			amount_paid DECIMAL(10,2) DEFAULT 0.00,
			amount_due DECIMAL(10,2) NOT NULL,
			payment_status ENUM('pending', 'partial', 'paid', 'refunded', 'cancelled') DEFAULT 'pending',
			payment_date DATETIME DEFAULT NULL,
			payment_method VARCHAR(50) DEFAULT NULL,
			transaction_id VARCHAR(255) DEFAULT NULL,
			quickbooks_invoice_id VARCHAR(100) DEFAULT NULL,
			quickbooks_invoice_number VARCHAR(50) DEFAULT NULL,
			quickbooks_invoice_sort_ts BIGINT(20) UNSIGNED DEFAULT NULL,
			quickbooks_payment_lines LONGTEXT DEFAULT NULL,
			quickbooks_refund_lines LONGTEXT DEFAULT NULL,
			xero_invoice_id VARCHAR(100) DEFAULT NULL,
			xero_invoice_number VARCHAR(50) DEFAULT NULL,
			xero_online_invoice_url VARCHAR(500) DEFAULT NULL,
			xero_invoice_sort_ts BIGINT(20) UNSIGNED DEFAULT NULL,
			xero_payment_lines LONGTEXT DEFAULT NULL,
			xero_refund_lines LONGTEXT DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			ticket_paid_emailed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (payment_id),
			UNIQUE KEY event_application_id (event_application_id),
			KEY member_id (member_id),
			KEY processor_id (processor_id),
			KEY payment_status (payment_status)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create vetting table.
	 */
	private function create_vetting_table() {
		$table_name = $this->prefix . 'vetting';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			vetting_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			primary_vetter_id BIGINT(20) UNSIGNED DEFAULT NULL,
			status ENUM('pending', 'scheduled', 'in_progress', 'completed') DEFAULT 'pending',
			scheduled_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			decision ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
			decision_date DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (vetting_id),
			KEY member_id (member_id),
			KEY primary_vetter_id (primary_vetter_id),
			KEY status (status)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create vetting collaborators table.
	 */
	private function create_vetting_collaborators_table() {
		$table_name = $this->prefix . 'vetting_collaborators';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			collaborator_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			vetting_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			invited_by BIGINT(20) UNSIGNED NOT NULL,
			invited_at DATETIME NOT NULL,
			status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
			PRIMARY KEY (collaborator_id),
			UNIQUE KEY vetting_collaborator (vetting_id, member_id),
			KEY vetting_id (vetting_id),
			KEY member_id (member_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create vetting notes table.
	 */
	private function create_vetting_notes_table() {
		$table_name = $this->prefix . 'vetting_notes';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			note_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			vetting_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			note_content TEXT NOT NULL,
			is_admin_only BOOLEAN DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (note_id),
			KEY vetting_id (vetting_id),
			KEY member_id (member_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Profile notes (member-visible or private to admin). Separate from vetting notes.
	 */
	public function create_profile_notes_table() {
		$table_name      = $this->prefix . 'profile_notes';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			note_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			author_id BIGINT(20) UNSIGNED NOT NULL,
			note_content TEXT NOT NULL,
			is_admin_only TINYINT(1) DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (note_id),
			KEY member_id (member_id),
			KEY author_id (author_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Duplicate profile hits awaiting review or already decided.
	 */
	public function create_profile_duplicate_hits_table() {
		$table_name      = $this->prefix . 'profile_duplicate_hits';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			hit_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			member_a_id BIGINT(20) UNSIGNED NOT NULL,
			member_b_id BIGINT(20) UNSIGNED NOT NULL,
			status ENUM('pending', 'dismissed', 'merged', 'closed') DEFAULT 'pending',
			match_reasons LONGTEXT DEFAULT NULL,
			notified_at DATETIME DEFAULT NULL,
			reviewed_by BIGINT(20) UNSIGNED DEFAULT NULL,
			reviewed_at DATETIME DEFAULT NULL,
			survivor_id BIGINT(20) UNSIGNED DEFAULT NULL,
			locked_id BIGINT(20) UNSIGNED DEFAULT NULL,
			undo_snapshot LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (hit_id),
			UNIQUE KEY member_pair (member_a_id, member_b_id),
			KEY status (status),
			KEY member_a_id (member_a_id),
			KEY member_b_id (member_b_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Add merged to the members status enum (existing installs).
	 *
	 * @return void
	 */
	public function add_merged_member_status() {
		$table_name = $this->prefix . 'members';
		$this->wpdb->query(
			"ALTER TABLE {$table_name} MODIFY COLUMN status ENUM('pending_vetting', 'unvetted', 'in_vetting', 'vetted', 'rejected', 'inactive', 'merged') DEFAULT 'pending_vetting'"
		);
	}

	/**
	 * Add closed to duplicate-hit status enum (existing installs).
	 *
	 * @return void
	 */
	public function add_closed_duplicate_hit_status() {
		$table_name = $this->prefix . 'profile_duplicate_hits';
		$exists     = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $exists !== $table_name ) {
			return;
		}
		$this->wpdb->query(
			"ALTER TABLE {$table_name} MODIFY COLUMN status ENUM('pending', 'dismissed', 'merged', 'closed') DEFAULT 'pending'"
		);
	}

	/**
	 * Store a pre-merge snapshot so a merge can be undone (existing installs).
	 *
	 * @return void
	 */
	public function add_duplicate_hit_undo_snapshot() {
		$table_name = $this->prefix . 'profile_duplicate_hits';
		$exists     = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $exists !== $table_name ) {
			return;
		}
		$col = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", 'undo_snapshot' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $col ) {
			return;
		}
		$this->wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN undo_snapshot LONGTEXT DEFAULT NULL AFTER locked_id" );
	}

	/**
	 * Create notification settings table.
	 */
	private function create_notification_settings_table() {
		$table_name = $this->prefix . 'notification_settings';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			setting_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			notification_type VARCHAR(100) NOT NULL,
			is_enabled BOOLEAN DEFAULT 1,
			subject_template VARCHAR(255) DEFAULT NULL,
			body_template TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (setting_id),
			UNIQUE KEY notification_type (notification_type)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Admin-defined profile custom questions (text, select, or multiselect).
	 */
	public function create_profile_questions_table() {
		$table_name      = $this->prefix . 'profile_questions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			field_key VARCHAR(64) NOT NULL,
			label VARCHAR(500) NOT NULL,
			field_type VARCHAR(20) NOT NULL DEFAULT 'text',
			options_json LONGTEXT DEFAULT NULL,
			is_required TINYINT(1) NOT NULL DEFAULT 0,
			required_when_json LONGTEXT DEFAULT NULL,
			sort_order INT(11) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			show_on_event_card TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (question_id),
			UNIQUE KEY field_key (field_key),
			KEY is_active_sort (is_active, sort_order)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Member answers to profile custom questions.
	 */
	public function create_profile_question_responses_table() {
		$table_name      = $this->prefix . 'profile_question_responses';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			response_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			question_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			value_text TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (response_id),
			UNIQUE KEY question_member (question_id, member_id),
			KEY member_id (member_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Who viewed or exported health and emergency-contact data.
	 *
	 * @return void
	 */
	public function create_sensitive_access_log_table() {
		$table_name      = $this->prefix . 'sensitive_access_log';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			access_what VARCHAR(20) NOT NULL,
			context VARCHAR(191) NOT NULL DEFAULT '',
			row_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (log_id),
			KEY member_created (member_id, created_at),
			KEY user_created (user_id, created_at),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Per-user saved report definitions.
	 *
	 * @return void
	 */
	public function create_saved_reports_table() {
		$table_name      = $this->prefix . 'saved_reports';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			report_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			owner_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			subject VARCHAR(50) NOT NULL,
			definition LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (report_id),
			KEY owner_id (owner_id),
			KEY owner_updated (owner_id, updated_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Optional email schedule for one saved report.
	 */
	public function create_report_schedules_table() {
		$table_name      = $this->prefix . 'report_schedules';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			schedule_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT(20) UNSIGNED NOT NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 0,
			frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
			weekday TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
			monthday TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
			send_time CHAR(5) NOT NULL DEFAULT '08:00',
			event_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			skip_empty TINYINT(1) NOT NULL DEFAULT 1,
			sensitive_opt_in TINYINT(1) NOT NULL DEFAULT 0,
			recipient_ids TEXT NOT NULL,
			last_sent_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (schedule_id),
			UNIQUE KEY report_id (report_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Agreement library entries (rules, waivers, etc.).
	 */
	public function create_agreements_table() {
		$table_name      = $this->prefix . 'agreements';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			agreement_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (agreement_id),
			KEY is_active_sort (is_active, sort_order)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Immutable agreement revisions (rich text body).
	 */
	public function create_agreement_revisions_table() {
		$table_name      = $this->prefix . 'agreement_revisions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			revision_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agreement_id BIGINT(20) UNSIGNED NOT NULL,
			revision_number INT(11) UNSIGNED NOT NULL DEFAULT 1,
			body LONGTEXT NOT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (revision_id),
			UNIQUE KEY agreement_revision (agreement_id, revision_number),
			KEY agreement_id (agreement_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Event pins specific agreement revisions.
	 */
	public function create_event_agreements_table() {
		$table_name      = $this->prefix . 'event_agreements';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			event_agreement_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			agreement_id BIGINT(20) UNSIGNED NOT NULL,
			revision_id BIGINT(20) UNSIGNED NOT NULL,
			sort_order INT(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (event_agreement_id),
			UNIQUE KEY event_agreement (event_id, agreement_id),
			KEY event_id (event_id),
			KEY revision_id (revision_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Per-application acknowledgments of pinned agreement revisions.
	 */
	public function create_agreement_acceptances_table() {
		$table_name      = $this->prefix . 'agreement_acceptances';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			acceptance_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT(20) UNSIGNED NOT NULL,
			revision_id BIGINT(20) UNSIGNED NOT NULL,
			typed_legal_name VARCHAR(255) NOT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(500) DEFAULT NULL,
			accepted_at DATETIME NOT NULL,
			PRIMARY KEY (acceptance_id),
			UNIQUE KEY application_revision (application_id, revision_id),
			KEY application_id (application_id),
			KEY revision_id (revision_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Surveys attached to an event. event_role_id null means every role on that event.
	 */
	public function create_surveys_table() {
		$table_name      = $this->prefix . 'surveys';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			survey_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			event_role_id BIGINT(20) UNSIGNED DEFAULT NULL,
			title VARCHAR(255) NOT NULL,
			instructions TEXT NULL,
			placement VARCHAR(20) NOT NULL DEFAULT 'followup',
			timing VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			issued_at DATETIME DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (survey_id),
			KEY event_id (event_id),
			KEY event_placement (event_id, placement),
			KEY event_role (event_id, event_role_id, placement)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Roles a survey is limited to. No rows means every role on the event.
	 */
	public function create_survey_roles_table() {
		$table_name      = $this->prefix . 'survey_roles';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			survey_role_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			survey_id BIGINT(20) UNSIGNED NOT NULL,
			event_role_id BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (survey_role_id),
			UNIQUE KEY survey_role (survey_id, event_role_id),
			KEY event_role_id (event_role_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Questions on a survey.
	 */
	public function create_survey_questions_table() {
		$table_name      = $this->prefix . 'survey_questions';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			survey_id BIGINT(20) UNSIGNED NOT NULL,
			sort_order INT(11) NOT NULL DEFAULT 0,
			label TEXT NOT NULL,
			field_key VARCHAR(64) NOT NULL DEFAULT '',
			field_type VARCHAR(20) NOT NULL DEFAULT 'text',
			options_text TEXT NULL,
			is_required TINYINT(1) NOT NULL DEFAULT 0,
			required_when_json TEXT NULL,
			show_if_question_id BIGINT(20) UNSIGNED DEFAULT NULL,
			show_if_value VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY (question_id),
			KEY survey_id (survey_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * One submitted survey per member.
	 */
	public function create_survey_responses_table() {
		$table_name      = $this->prefix . 'survey_responses';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			response_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			survey_id BIGINT(20) UNSIGNED NOT NULL,
			event_id BIGINT(20) UNSIGNED NOT NULL,
			member_id BIGINT(20) UNSIGNED NOT NULL,
			application_id BIGINT(20) UNSIGNED DEFAULT NULL,
			submitted_at DATETIME NOT NULL,
			PRIMARY KEY (response_id),
			UNIQUE KEY survey_member (survey_id, member_id),
			KEY event_id (event_id),
			KEY application_id (application_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * One answer per question on a response.
	 */
	public function create_survey_answers_table() {
		$table_name      = $this->prefix . 'survey_answers';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			answer_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			response_id BIGINT(20) UNSIGNED NOT NULL,
			question_id BIGINT(20) UNSIGNED NOT NULL,
			value_text TEXT NULL,
			PRIMARY KEY (answer_id),
			UNIQUE KEY response_question (response_id, question_id),
			KEY question_id (question_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Create plugin version table.
	 */
	private function create_plugin_version_table() {
		$table_name = $this->prefix . 'plugin_version';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			version VARCHAR(20) NOT NULL,
			applied_at DATETIME NOT NULL,
			PRIMARY KEY (version)
		) $charset_collate;";

		dbDelta( $sql );
	}
}
