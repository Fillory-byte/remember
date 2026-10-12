<?php
/**
 * Fired during plugin deactivation
 *
 * @package    reMember
 * @subpackage reMember/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @package    reMember
 * @subpackage reMember/includes
 */
class Remember_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-logger.php';
		Remember_Logger::info( 'Starting plugin deactivation' );

		// Clear scheduled cron events
		$timestamp = wp_next_scheduled( 'remember_qb_sync' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'remember_qb_sync' );
			Remember_Logger::debug( 'Cleared scheduled cron events' );
		}

		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-profile-duplicates.php';
		Remember_Profile_Duplicates::unschedule();
		require_once plugin_dir_path( __FILE__ ) . 'utilities/class-remember-event-invites.php';
		Remember_Event_Invites::unschedule();

		// Flush rewrite rules
		flush_rewrite_rules();

		Remember_Logger::info( 'Plugin deactivation completed' );
	}
}
