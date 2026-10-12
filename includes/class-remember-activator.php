<?php
/**
 * Fired during plugin activation
 *
 * @package    reMember
 * @subpackage reMember/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @package    reMember
 * @subpackage reMember/includes
 */
class Remember_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// Avoid gateway timeouts on slow hosts (dbDelta, migrations, rewrite flush are heavy).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'max_execution_time', '300' );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-logger.php';
		Remember_Logger::mark_activation_start();
		Remember_Logger::activation_debug(
			'activate: begin',
			array(
				'php_version' => PHP_VERSION,
				'remember_file' => __FILE__,
			)
		);
		Remember_Logger::info( 'Starting plugin activation' );

		// Check WordPress version
		if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
			Remember_Logger::error( 'WordPress version too old', array( 'version' => get_bloginfo( 'version' ) ) );
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( __( 'reMember requires WordPress 5.0 or higher. Please upgrade WordPress and try again.', 'remember' ) );
		}

		// Create database tables
		Remember_Logger::activation_debug( 'before create_tables' );
		Remember_Logger::debug( 'Creating database tables' );
		require_once plugin_dir_path( __FILE__ ) . 'database/class-remember-database.php';
		$database = new Remember_Database();
		$database->create_tables();
		Remember_Logger::activation_debug( 'after create_tables' );
		
		// Update database schema if needed
		Remember_Logger::activation_debug( 'before update_schema', array( 'remember_db_version' => get_option( 'remember_db_version', '0.0.0' ) ) );
		Remember_Logger::debug( 'Updating database schema if needed' );
		require_once plugin_dir_path( __FILE__ ) . 'database/class-remember-database-updater.php';
		Remember_Database_Updater::update_schema();
		Remember_Logger::activation_debug( 'after update_schema', array( 'remember_db_version' => get_option( 'remember_db_version', '0.0.0' ) ) );

		// Seed initial data
		Remember_Logger::activation_debug( 'before seeder.seed' );
		Remember_Logger::debug( 'Seeding initial data' );
		require_once plugin_dir_path( __FILE__ ) . 'database/class-remember-seeder.php';
		$seeder = new Remember_Seeder();
		$seeder->seed();
		Remember_Logger::activation_debug( 'after seeder.seed' );

		// Set up capabilities
		Remember_Logger::activation_debug( 'before setup_capabilities' );
		Remember_Logger::debug( 'Setting up capabilities' );
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-capabilities.php';
		Remember_Capabilities::setup_capabilities();
		Remember_Logger::activation_debug( 'after setup_capabilities' );

		// Grant plugin capabilities to the activating user (admin access).
		// Do NOT create a reMember member, profile, or role assignment — membership
		// must be an affirmative admin action (or public registration), not an install assumption.
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-capabilities.php';

		Remember_Logger::activation_debug( 'before assign_activating_user_caps' );
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->ID > 0 ) {
			$all_capabilities = Remember_Capabilities::get_all_capabilities();
			foreach ( array_keys( $all_capabilities ) as $cap ) {
				$current_user->add_cap( $cap );
			}
			Remember_Logger::activation_debug(
				'after add_cap loop',
				array(
					'user_id'   => $current_user->ID,
					'cap_count' => count( $all_capabilities ),
				)
			);
			Remember_Logger::debug(
				'Granted reMember capabilities to activating user (no member record created)',
				array( 'user_id' => $current_user->ID )
			);
		}

		Remember_Logger::activation_debug( 'after assign_activating_user_caps' );

		// Store plugin version
		update_option( 'remember_version', REMEMBER_VERSION );
		Remember_Logger::debug( 'Plugin version stored', array( 'version' => REMEMBER_VERSION ) );

		// Set default options
		Remember_Logger::activation_debug( 'before default_options merge' );
		$default_options = array(
			'photo_max_size'      => 2097152, // 2MB in bytes
			'photo_max_dimensions' => 800,
			'qb_sync_interval'    => 3600, // 1 hour in seconds
			'vetting_workflow'    => 'on_join', // Default: vet on member join
		);
		$existing_opts = get_option( 'remember_options', array() );
		update_option( 'remember_options', wp_parse_args( $existing_opts, $default_options ) );
		Remember_Logger::activation_debug( 'after default_options merge' );

		// Schedule QuickBooks sync cron job
		if ( ! wp_next_scheduled( 'remember_qb_sync' ) ) {
			wp_schedule_event( time(), 'hourly', 'remember_qb_sync' );
			Remember_Logger::debug( 'Scheduled QuickBooks sync cron job' );
		}

		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-profile-duplicates.php';
		Remember_Profile_Duplicates::maybe_schedule();
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-event-invites.php';
		Remember_Event_Invites::maybe_schedule();

		// Defer rewrite flush to next request so activation does not hit gateway/proxy timeouts.
		update_option( 'remember_activation_needs_rewrite_flush', '1' );

		// Set flag to show setup wizard
		set_transient( 'remember_show_setup_wizard', true, 3600 ); // Show for 1 hour

		Remember_Logger::activation_debug( 'activate: complete' );
		Remember_Logger::info( 'Plugin activation completed successfully' );
	}
}
