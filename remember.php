<?php
/**
 * Plugin Name: reMember
 * Plugin URI: https://github.com/ctucker1984/remember
 * Description: Membership communities for WordPress — member profiles, events and locations, applications and vetting, admission tickets, and billing with QuickBooks Online or Xero.
 * Version: 2.2.8
 * Update URI: https://github.com/ctucker1984/remember
 * Author: ctucker1984
 * Author URI: https://github.com/ctucker1984
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: remember
 * Domain Path: /languages
 *
 * @package reMember
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/*
 * Guard against a second copy of the plugin (e.g. GitHub zip extracted as
 * remember-1.1.1/ while remember/ is still active). Loading both fatals on
 * redeclared functions/classes/constants.
 */
if ( defined( 'REMEMBER_LOADED' ) ) {
	if ( is_admin() ) {
		add_action(
			'admin_notices',
			static function () {
				$active = defined( 'REMEMBER_PLUGIN_DIR' ) ? REMEMBER_PLUGIN_DIR : '';
				echo '<div class="notice notice-error"><p>';
				echo esc_html__(
					'reMember is already loaded from another folder. Delete the duplicate plugin directory (for example remember-1.1.1) and keep a single install at wp-content/plugins/remember/.',
					'remember'
				);
				if ( $active ) {
					echo ' <code>' . esc_html( $active ) . '</code>';
				}
				echo '</p></div>';
			}
		);
	}
	return;
}
define( 'REMEMBER_LOADED', true );

/**
 * Currently plugin version.
 */
define( 'REMEMBER_VERSION', '2.2.8' );

/**
 * Plugin directory path.
 */
define( 'REMEMBER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'REMEMBER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_remember() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-remember-activator.php';
	Remember_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_remember() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-remember-deactivator.php';
	Remember_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_remember' );
register_deactivation_hook( __FILE__, 'deactivate_remember' );

/*
 * Upload → Replace does not deactivate like dashboard updates. Register early
 * so an active 1.3.0+ install can safely overwrite itself later.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/utilities/class-remember-plugin-upgrader.php';
Remember_Plugin_Upgrader::init();

/*
 * Offer dashboard updates from GitHub Releases. The Update URI header opts this
 * plugin out of WordPress.org, so nothing else would ever report a new version.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/utilities/class-remember-github-updater.php';
Remember_GitHub_Updater::init();

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function run_remember() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-remember.php';
	$plugin = new Remember();
	$plugin->run();
}

// Run the plugin after WordPress is fully loaded
add_action( 'plugins_loaded', 'run_remember' );

/**
 * Register WP-CLI commands after the plugin has loaded.
 *
 * @return void
 */
function remember_register_cli() {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}
	require_once plugin_dir_path( __FILE__ ) . 'includes/cli/class-remember-cli.php';
	WP_CLI::add_command( 'remember', 'Remember_CLI' );
}
add_action( 'plugins_loaded', 'remember_register_cli', 20 );
