<?php
/**
 * Database seeder for initial data
 *
 * @package    reMember
 * @subpackage reMember/includes/database
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Database seeder class.
 *
 * @package    reMember
 * @subpackage reMember/includes/database
 */
class Remember_Seeder {

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
	 * Seed initial data.
	 *
	 * @since    1.0.0
	 */
	public function seed() {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';
		$steps = array(
			'seed_default_location',
			'seed_default_roles',
			'seed_social_media_platforms',
			'seed_im_platforms',
			'seed_dietary_restrictions',
			'seed_allergies',
			'seed_clothing_size_options',
			'seed_medical_accommodations',
			'seed_notification_settings',
			'seed_payment_processors',
		);
		foreach ( $steps as $method ) {
			Remember_Logger::activation_debug( 'seeder: ' . $method );
			$this->{$method}();
		}
	}

	/**
	 * Ensure notification setting rows exist (safe for upgrades).
	 *
	 * @return void
	 */
	public function ensure_notification_settings() {
		$this->seed_notification_settings();
	}

	/**
	 * Ensure clothing size option rows exist (safe for upgrades).
	 *
	 * @return void
	 */
	public function ensure_clothing_size_options() {
		$this->seed_clothing_size_options();
	}

	/**
	 * Ensure dietary / allergy / medical catalog rows exist (safe for upgrades).
	 *
	 * @return void
	 */
	public function ensure_health_catalog_options() {
		$this->seed_dietary_restrictions();
		$this->seed_allergies();
		$this->seed_medical_accommodations();
		$this->ensure_none_options_sort_first();
	}

	/**
	 * Keep the "None" catalog row first in dietary / allergy / medical lists.
	 *
	 * Older installs seeded accommodations before "None" existed, so "None"
	 * landed with the same sort_order as CPAP and sorted second by name.
	 *
	 * @return void
	 */
	public function ensure_none_options_sort_first() {
		$catalogs = array(
			array( 'dietary_restrictions', 'restriction_name' ),
			array( 'allergies', 'allergy_name' ),
			array( 'medical_accommodations', 'accommodation_name' ),
		);

		foreach ( $catalogs as $catalog ) {
			$table    = $this->prefix . $catalog[0];
			$name_col = $catalog[1];

			// Free sort_order 0 for None when another row already occupies it.
			$this->wpdb->query(
				"UPDATE {$table}
				SET sort_order = sort_order + 1
				WHERE {$name_col} <> 'None' AND sort_order <= 0"
			);

			$this->wpdb->update(
				$table,
				array( 'sort_order' => 0 ),
				array( $name_col => 'None' ),
				array( '%d' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Seed default location.
	 */
	private function seed_default_location() {
		$table_name = $this->prefix . 'locations';
		
		// Check if any locations exist
		$existing = $this->wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		if ( $existing > 0 ) {
			return;
		}

		$this->wpdb->insert(
			$table_name,
			array(
				'location_name'  => __( 'Default Location', 'remember' ),
				'logo_url'       => null,
				'address_street' => '',
				'address_city'   => '',
				'address_state'  => '',
				'address_postal' => '',
				'address_country' => 'US',
				'details'        => __( 'Default location created during plugin activation.', 'remember' ),
				'is_active'      => 1,
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Seed default roles.
	 */
	private function seed_default_roles() {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';
		
		$table_name = $this->prefix . 'roles';
		$role_model = new Remember_Role();
		
		// Check if Event Administrator role exists
		$event_admin_role_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT role_id FROM $table_name WHERE role_name = %s", 'Event Administrator' ) );
		if ( ! $event_admin_role_id ) {
			$this->wpdb->insert(
				$table_name,
				array(
					'role_name'    => 'Event Administrator',
					'role_type'    => 'event',
					'is_event_role' => 1,
					'description'  => __( 'Event Administrator role - assigned to members for specific events', 'remember' ),
					'created_at'   => current_time( 'mysql' ),
				)
			);
			$event_admin_role_id = $this->wpdb->insert_id;
			
			// Assign default capabilities to Event Administrator
			$event_admin_capabilities = array(
				// Applications
				'remember_create_applications',
				'remember_read_applications',
				'remember_update_applications',
				// Billing
				'remember_create_billing',
				'remember_read_billing',
				'remember_update_billing',
				'remember_delete_billing',
				// Events
				'remember_create_events',
				'remember_read_events',
				'remember_update_events',
				'remember_delete_events',
				'remember_event_data_export',
				// Locations (read only)
				'remember_read_locations',
				// Members (read only)
				'remember_read_members',
				'remember_update_members',
				// Dietary / allergies / medical for event planning (not emergency contact)
				'remember_access_health',
				// Profile printouts for staff records and public event posting
				'remember_print_confidential',
				'remember_print_event_card',
				// Vetting (read only)
				'remember_read_vetting',
				'remember_view_reports',
				'remember_checkin_attendees',
			);
			$role_model->set_capabilities( $event_admin_role_id, $event_admin_capabilities );
		}

		// Check if Vetting role exists
		$vetting_role_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT role_id FROM $table_name WHERE role_name = %s", 'Vetting' ) );
		if ( ! $vetting_role_id ) {
			$this->wpdb->insert(
				$table_name,
				array(
					'role_name'     => 'Vetting',
					'role_type'     => 'system',
					'is_event_role' => 0,
					'description'   => __( 'Vetting team member role', 'remember' ),
					'created_at'    => current_time( 'mysql' ),
				)
			);
			$vetting_role_id = $this->wpdb->insert_id;
			
			// Assign default capabilities to Vetting
			$vetting_capabilities = array(
				// Vetting
				'remember_create_vetting',
				'remember_read_vetting',
				'remember_update_vetting',
				// Applications (read only)
				'remember_read_applications',
				// Events (read only)
				'remember_read_events',
				// Locations (read only)
				'remember_read_locations',
				// Members (read and update)
				'remember_read_members',
				'remember_update_members',
				// Limited PII for vetting decisions
				'remember_access_emergency_contact',
				'remember_access_health',
				'remember_view_reports',
			);
			$role_model->set_capabilities( $vetting_role_id, $vetting_capabilities );
		}

		// Check if System Administrator role exists
		$system_admin_role_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT role_id FROM $table_name WHERE role_name = %s", 'System Administrator' ) );
		if ( ! $system_admin_role_id ) {
			$this->wpdb->insert(
				$table_name,
				array(
					'role_name'     => 'System Administrator',
					'role_type'     => 'system',
					'is_event_role' => 0,
					'description'   => __( 'System Administrator role - full access to all plugin features', 'remember' ),
					'created_at'    => current_time( 'mysql' ),
				)
			);
			$system_admin_role_id = $this->wpdb->insert_id;
			
			// Assign all capabilities to System Administrator
			$all_capabilities = Remember_Capabilities::get_all_capabilities();
			$role_model->set_capabilities( $system_admin_role_id, array_keys( $all_capabilities ) );
		}
	}

	/**
	 * Seed social media platforms.
	 */
	private function seed_social_media_platforms() {
		$table_name = $this->prefix . 'social_media_platforms';
		
		$platforms = array(
			'Facebook',
			'Instagram',
			'X (Twitter)',
			'LinkedIn',
			'YouTube',
			'TikTok',
			'Snapchat',
			'Discord',
			'Reddit',
			'Threads',
		);

		foreach ( $platforms as $index => $platform_name ) {
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT platform_id FROM $table_name WHERE platform_name = %s", $platform_name ) );
			if ( ! $existing ) {
				$this->wpdb->insert(
					$table_name,
					array(
						'platform_name' => $platform_name,
						'is_active'     => 1,
						'sort_order'    => $index,
						'created_at'    => current_time( 'mysql' ),
					)
				);
			}
		}
	}

	/**
	 * Seed instant messenger platforms (idempotent).
	 */
	private function seed_im_platforms() {
		$this->ensure_im_platforms();
	}

	/**
	 * Ensure default IM platforms exist (safe for upgrades).
	 *
	 * @return void
	 */
	public function ensure_im_platforms() {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-im-platforms.php';
		$table = $this->prefix . 'im_platforms';
		if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$index = 0;
		foreach ( Remember_Im_Platforms::defaults() as $key => $name ) {
			$existing = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT platform_id FROM {$table} WHERE platform_key = %s",
					$key
				)
			);
			if ( ! $existing ) {
				$this->wpdb->insert(
					$table,
					array(
						'platform_key'  => $key,
						'platform_name' => $name,
						'is_active'     => 1,
						'sort_order'    => $index,
						'created_at'    => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%d', '%d', '%s' )
				);
			}
			++$index;
		}
	}

	/**
	 * Seed dietary restrictions.
	 */
	private function seed_dietary_restrictions() {
		$table_name = $this->prefix . 'dietary_restrictions';
		
		$restrictions = array(
			__( 'None', 'remember' ),
			__( 'Vegetarian', 'remember' ),
			__( 'Vegan', 'remember' ),
			__( 'Pescatarian', 'remember' ),
			__( 'Gluten-Free', 'remember' ),
			__( 'Celiac', 'remember' ),
			__( 'Dairy-Free', 'remember' ),
			__( 'Egg-Free', 'remember' ),
			__( 'Soy-Free', 'remember' ),
			__( 'Kosher', 'remember' ),
			__( 'Halal', 'remember' ),
			__( 'No Nuts', 'remember' ),
			__( 'No Shellfish', 'remember' ),
			__( 'No Pork', 'remember' ),
			__( 'No Beef', 'remember' ),
			__( 'No Alcohol', 'remember' ),
			__( 'Low Sodium', 'remember' ),
			__( 'Diabetic', 'remember' ),
			__( 'Sugar-Free / No Added Sugar', 'remember' ),
			__( 'Low-FODMAP', 'remember' ),
			__( 'Keto / Low-Carb', 'remember' ),
			__( 'Soft Foods Only', 'remember' ),
		);

		foreach ( $restrictions as $index => $restriction ) {
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT restriction_id FROM $table_name WHERE restriction_name = %s", $restriction ) );
			if ( ! $existing ) {
				$this->wpdb->insert(
					$table_name,
					array(
						'restriction_name' => $restriction,
						'is_active'        => 1,
						'sort_order'       => $index,
						'created_at'       => current_time( 'mysql' ),
					)
				);
			}
		}
	}

	/**
	 * Seed allergies.
	 */
	private function seed_allergies() {
		$table_name = $this->prefix . 'allergies';
		
		$allergies = array(
			__( 'None', 'remember' ),
			__( 'Peanuts', 'remember' ),
			__( 'Tree Nuts', 'remember' ),
			__( 'Almonds', 'remember' ),
			__( 'Cashews', 'remember' ),
			__( 'Walnuts', 'remember' ),
			__( 'Pecans', 'remember' ),
			__( 'Hazelnuts', 'remember' ),
			__( 'Pistachios', 'remember' ),
			__( 'Brazil Nuts', 'remember' ),
			__( 'Macadamia Nuts', 'remember' ),
			__( 'Shellfish', 'remember' ),
			__( 'Crustaceans', 'remember' ),
			__( 'Molluscs', 'remember' ),
			__( 'Fish', 'remember' ),
			__( 'Eggs', 'remember' ),
			__( 'Dairy', 'remember' ),
			__( 'Soy', 'remember' ),
			__( 'Wheat', 'remember' ),
			__( 'Gluten', 'remember' ),
			__( 'Sesame', 'remember' ),
			__( 'Mustard', 'remember' ),
			__( 'Celery', 'remember' ),
			__( 'Lupin', 'remember' ),
			__( 'Sulphites', 'remember' ),
			__( 'Corn', 'remember' ),
			__( 'Garlic', 'remember' ),
			__( 'Onion', 'remember' ),
			__( 'Tomato', 'remember' ),
			__( 'Citrus', 'remember' ),
			__( 'Grapefruit', 'remember' ),
			__( 'Orange', 'remember' ),
			__( 'Lemon', 'remember' ),
			__( 'Lime', 'remember' ),
			__( 'Pomegranate', 'remember' ),
			__( 'Strawberry', 'remember' ),
			__( 'Kiwi', 'remember' ),
			__( 'Banana', 'remember' ),
			__( 'Mango', 'remember' ),
			__( 'Pineapple', 'remember' ),
			__( 'Avocado', 'remember' ),
			__( 'Coconut', 'remember' ),
			__( 'Chocolate', 'remember' ),
			__( 'Caffeine', 'remember' ),
			__( 'Honey', 'remember' ),
			__( 'Latex', 'remember' ),
			__( 'Penicillin', 'remember' ),
			__( 'Aspirin / NSAIDs', 'remember' ),
		);

		foreach ( $allergies as $index => $allergy ) {
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT allergy_id FROM $table_name WHERE allergy_name = %s", $allergy ) );
			if ( ! $existing ) {
				$this->wpdb->insert(
					$table_name,
					array(
						'allergy_name' => $allergy,
						'is_active'    => 1,
						'sort_order'   => $index,
						'created_at'   => current_time( 'mysql' ),
					)
				);
			}
		}
	}

	/**
	 * Seed clothing size options (shirt/pants S–6XL, shoes 6–15).
	 */
	private function seed_clothing_size_options() {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-clothing-sizes.php';

		$table_name = $this->prefix . 'clothing_size_options';
		$defaults   = Remember_Clothing_Sizes::defaults();
		$cols       = $this->wpdb->get_col( "SHOW COLUMNS FROM {$table_name}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
		$has_stocked = is_array( $cols ) && in_array( 'is_stocked', $cols, true );
		$has_issued  = is_array( $cols ) && in_array( 'issued_size_code', $cols, true );

		foreach ( $defaults as $category => $codes ) {
			foreach ( $codes as $index => $code ) {
				$existing = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT option_id FROM {$table_name} WHERE size_category = %s AND size_code = %s",
						$category,
						$code
					)
				);
				if ( $existing ) {
					continue;
				}
				$row = array(
					'size_category' => $category,
					'size_code'     => $code,
					'is_active'     => 1,
					'sort_order'    => (int) $index,
					'created_at'    => current_time( 'mysql' ),
				);
				if ( $has_stocked ) {
					$row['is_stocked'] = 1;
				}
				if ( $has_issued ) {
					$row['issued_size_code'] = $code;
				}
				$this->wpdb->insert( $table_name, $row );
			}
		}
	}

	/**
	 * Seed medical accommodations.
	 */
	private function seed_medical_accommodations() {
		$table_name = $this->prefix . 'medical_accommodations';
		
		$accommodations = array(
			array(
				'name'        => __( 'None', 'remember' ),
				'description' => '',
			),
			array(
				'name'        => __( 'CPAP Machine', 'remember' ),
				'description' => __( 'Requires CPAP machine for sleep', 'remember' ),
			),
			array(
				'name'        => __( 'Wheelchair Accessible', 'remember' ),
				'description' => __( 'Requires wheelchair accessible facilities', 'remember' ),
			),
			array(
				'name'        => __( 'Hearing Assistance', 'remember' ),
				'description' => __( 'Requires hearing assistance devices', 'remember' ),
			),
			array(
				'name'        => __( 'Visual Assistance', 'remember' ),
				'description' => __( 'Requires visual assistance accommodations', 'remember' ),
			),
			array(
				'name'        => __( 'Mobility Assistance', 'remember' ),
				'description' => __( 'Requires mobility assistance', 'remember' ),
			),
			array(
				'name'        => __( 'Medical Equipment Storage', 'remember' ),
				'description' => __( 'Requires secure storage for medical equipment', 'remember' ),
			),
			array(
				'name'        => __( 'Ground Floor / Elevator Access', 'remember' ),
				'description' => __( 'Needs ground-floor lodging or reliable elevator access', 'remember' ),
			),
			array(
				'name'        => __( 'ADA-Accessible Restroom', 'remember' ),
				'description' => __( 'Requires ADA-accessible restroom facilities', 'remember' ),
			),
			array(
				'name'        => __( 'Shower Chair / Grab Bars', 'remember' ),
				'description' => __( 'Needs shower chair and/or grab bars in bathing area', 'remember' ),
			),
			array(
				'name'        => __( 'Refrigerator for Medication', 'remember' ),
				'description' => __( 'Needs refrigerated storage for medication', 'remember' ),
			),
			array(
				'name'        => __( 'Quiet / Low-Stimulus Room', 'remember' ),
				'description' => __( 'Prefers a quiet or low-stimulus sleeping/meeting space', 'remember' ),
			),
			array(
				'name'        => __( 'Extra Time Between Sessions', 'remember' ),
				'description' => __( 'Needs additional transition time between scheduled activities', 'remember' ),
			),
			array(
				'name'        => __( 'Nearby Hospital Access', 'remember' ),
				'description' => __( 'Needs proximity to hospital or urgent medical care', 'remember' ),
			),
			array(
				'name'        => __( 'Allergy-Friendly Environment', 'remember' ),
				'description' => __( 'Needs reduced allergen exposure in lodging or common areas', 'remember' ),
			),
		);

		foreach ( $accommodations as $index => $accommodation ) {
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT accommodation_id FROM $table_name WHERE accommodation_name = %s", $accommodation['name'] ) );
			if ( ! $existing ) {
				$this->wpdb->insert(
					$table_name,
					array(
						'accommodation_name' => $accommodation['name'],
						'description'        => $accommodation['description'],
						'is_active'          => 1,
						'sort_order'         => $index,
						'created_at'         => current_time( 'mysql' ),
					)
				);
			}
		}
	}

	/**
	 * Seed notification settings.
	 */
	private function seed_notification_settings() {
		$table_name = $this->prefix . 'notification_settings';
		
		$notification_types = array(
			'application_received',
			'vetting_assigned',
			'vetting_case_waiting',
			'vetting_case_failed',
			'vetting_scheduled',
			'vetting_completed',
			'member_vetted',
			'member_rejected',
			'member_registered',
			'event_application_submitted',
			'event_application_accepted',
			'event_application_declined',
			'event_application_waitlisted',
			'waitlist_spot_opened',
			'waitlist_promoted',
			'event_ticket_paid',
			'event_invite',
			'survey_issued',
			'payment_recorded',
			'payment_due_reminder',
			'vetting_collaborator_invited',
			'duplicate_hit_admin',
			'duplicate_hit_member',
			'duplicate_merged_survivor',
			'duplicate_merged_locked',
		);

		foreach ( $notification_types as $type ) {
			$existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT setting_id FROM $table_name WHERE notification_type = %s", $type ) );
			if ( ! $existing ) {
				$this->wpdb->insert(
					$table_name,
					array(
						'notification_type' => $type,
						'is_enabled'        => 1,
						'subject_template'  => '',
						'body_template'     => '',
						'created_at'        => current_time( 'mysql' ),
						'updated_at'        => current_time( 'mysql' ),
					)
				);
			}
		}
	}

	/**
	 * Seed payment processors.
	 */
	private function seed_payment_processors() {
		$table_name = $this->prefix . 'payment_processors';
		
		// Manual processor
		$manual = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT processor_id FROM $table_name WHERE processor_type = %s", 'manual' ) );
		if ( ! $manual ) {
			$this->wpdb->insert(
				$table_name,
				array(
					'processor_type' => 'manual',
					'processor_name' => __( 'Manual Entry', 'remember' ),
					'is_active'      => 1,
					'settings'       => '',
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => current_time( 'mysql' ),
				)
			);
		}

		// QuickBooks processor (inactive by default)
		$quickbooks = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT processor_id FROM $table_name WHERE processor_type = %s", 'quickbooks' ) );
		if ( ! $quickbooks ) {
			$this->wpdb->insert(
				$table_name,
				array(
					'processor_type' => 'quickbooks',
					'processor_name' => __( 'QuickBooks Online', 'remember' ),
					'is_active'      => 0,
					'settings'       => '',
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => current_time( 'mysql' ),
				)
			);
		}

		// Xero processor (inactive by default)
		$xero = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT processor_id FROM $table_name WHERE processor_type = %s", 'xero' ) );
		if ( ! $xero ) {
			$this->wpdb->insert(
				$table_name,
				array(
					'processor_type' => 'xero',
					'processor_name' => __( 'Xero', 'remember' ),
					'is_active'      => 0,
					'settings'       => '',
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => current_time( 'mysql' ),
				)
			);
		}
	}
}
