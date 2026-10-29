<?php
/**
 * Capabilities management for the plugin
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Capabilities management class.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */
class Remember_Capabilities {

	/**
	 * Modules that expose Create / Read / Update / Delete capabilities.
	 *
	 * @return array<string, string> module_key => label
	 */
	public static function get_capability_modules() {
		return array(
			'members'      => __( 'Members', 'remember' ),
			'attendees'    => __( 'Attendees', 'remember' ),
			'events'       => __( 'Events', 'remember' ),
			'applications' => __( 'Applications', 'remember' ),
			'vetting'      => __( 'Vetting', 'remember' ),
			'billing'      => __( 'Billing', 'remember' ),
			'locations'    => __( 'Locations', 'remember' ),
			'roles'        => __( 'Roles', 'remember' ),
		);
	}

	/**
	 * CRUD actions shown as matrix columns.
	 *
	 * Keys match the capability suffix (remember_{action}_{module}). Column
	 * labels use "Edit" for update because that is how staff think about it.
	 *
	 * @return array<string, string> action_key => column label
	 */
	public static function get_capability_actions() {
		return array(
			'create' => __( 'Create', 'remember' ),
			'read'   => __( 'Read', 'remember' ),
			'update' => __( 'Edit', 'remember' ),
			'delete' => __( 'Delete', 'remember' ),
		);
	}

	/**
	 * Non-CRUD capabilities shown as a flat list under the matrix.
	 *
	 * @return array<string, string> capability => label
	 */
	public static function get_special_capabilities() {
		return array(
			'remember_access_settings'          => __( 'Access Settings', 'remember' ),
			'remember_view_reports'             => __( 'View Reports', 'remember' ),
			'remember_event_data_export'        => __( 'Export Event Data', 'remember' ),
			'remember_import_export'            => __( 'Import / Export Data', 'remember' ),
			'remember_access_emergency_contact' => __( 'Access Emergency Contact', 'remember' ),
			'remember_access_health'            => __( 'Access Health Information', 'remember' ),
			'remember_print_confidential'       => __( 'Print Confidential Profile', 'remember' ),
			'remember_print_event_card'         => __( 'Print Event Card', 'remember' ),
			'remember_merge_profiles'           => __( 'Merge Duplicate Profiles', 'remember' ),
			'remember_checkin_attendees'        => __( 'Check In Attendees', 'remember' ),
		);
	}

	/**
	 * Get all available plugin capabilities.
	 *
	 * @return array Array of capability => label pairs.
	 */
	public static function get_all_capabilities() {
		$capabilities = array();

		foreach ( self::get_capability_modules() as $module => $label ) {
			$capabilities[ "remember_create_{$module}" ] = sprintf( __( 'Create %s', 'remember' ), $label );
			$capabilities[ "remember_read_{$module}" ]   = sprintf( __( 'Read %s', 'remember' ), $label );
			$capabilities[ "remember_update_{$module}" ] = sprintf( __( 'Update %s', 'remember' ), $label );
			$capabilities[ "remember_delete_{$module}" ] = sprintf( __( 'Delete %s', 'remember' ), $label );
		}

		return array_merge( $capabilities, self::get_special_capabilities() );
	}

	/**
	 * Sync role capabilities to WordPress user capabilities.
	 * Grants all capabilities from assigned reMember roles to the WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True on success, false on failure.
	 */
	public static function sync_user_capabilities_from_roles( $user_id ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		
		// Get all reMember capabilities
		$all_capabilities = self::get_all_capabilities();
		$all_cap_keys = array_keys( $all_capabilities );
		
		// Remove all reMember capabilities first
		foreach ( $all_cap_keys as $cap ) {
			$user->remove_cap( $cap );
		}
		
		// Get all roles assigned to this member
		$role_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT role_id FROM {$wpdb->prefix}remember_member_roles WHERE member_id = %d",
			$user_id
		) );
		
		if ( empty( $role_ids ) ) {
			return true; // No roles assigned, capabilities already removed
		}
		
		// Get all capabilities from assigned roles
		$placeholders = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
		$capabilities = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT capability FROM {$wpdb->prefix}remember_role_capabilities WHERE role_id IN ($placeholders)",
			$role_ids
		) );
		
		// Grant capabilities to user
		foreach ( $capabilities as $capability ) {
			if ( in_array( $capability, $all_cap_keys, true ) ) {
				$user->add_cap( $capability );
			}
		}
		
		return true;
	}

	/**
	 * Recalculate WordPress caps for every member who holds this role.
	 *
	 * @param int $role_id Role ID.
	 * @return int Members synced.
	 */
	public static function sync_members_for_role( $role_id ) {
		global $wpdb;
		$role_id = absint( $role_id );
		if ( $role_id < 1 ) {
			return 0;
		}
		$member_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
				$role_id
			)
		);
		return self::sync_users( is_array( $member_ids ) ? $member_ids : array() );
	}

	/**
	 * Recalculate WordPress caps for the given members.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return int Members synced.
	 */
	public static function sync_users( $user_ids ) {
		$count = 0;
		if ( ! is_array( $user_ids ) ) {
			return 0;
		}
		foreach ( array_unique( array_map( 'absint', $user_ids ) ) as $user_id ) {
			if ( $user_id < 1 ) {
				continue;
			}
			if ( self::sync_user_capabilities_from_roles( $user_id ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Get capability label.
	 *
	 * @param string $capability Capability name.
	 * @return string Capability label.
	 */
	public static function get_capability_label( $capability ) {
		$capabilities = self::get_all_capabilities();
		return isset( $capabilities[ $capability ] ) ? $capabilities[ $capability ] : $capability;
	}

	/**
	 * Whether the current user may grant a plugin capability to a role.
	 *
	 * WordPress administrators may grant any reMember cap. Everyone else may
	 * only grant capabilities they already hold.
	 *
	 * @param string $capability Capability key.
	 * @return bool
	 */
	public static function current_user_can_grant( $capability ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$allowed = array_keys( self::get_all_capabilities() );
		if ( ! in_array( $capability, $allowed, true ) ) {
			return false;
		}
		return current_user_can( $capability );
	}

	/**
	 * Keep only capabilities the current user is allowed to grant.
	 *
	 * @param array $capabilities Capability keys from a form POST.
	 * @return array
	 */
	public static function filter_grantable_capabilities( $capabilities ) {
		if ( ! is_array( $capabilities ) ) {
			return array();
		}
		$out = array();
		foreach ( $capabilities as $cap ) {
			$cap = sanitize_text_field( (string) $cap );
			if ( '' === $cap || ! self::current_user_can_grant( $cap ) ) {
				continue;
			}
			$out[] = $cap;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Merge posted role capabilities without letting an editor strip or add
	 * caps they cannot grant.
	 *
	 * Caps already on the role that the editor cannot grant are preserved.
	 * Caps the editor can grant follow the posted checklist.
	 *
	 * @param array $existing Existing capability keys on the role.
	 * @param array $posted   Capability keys from the form.
	 * @return array
	 */
	public static function merge_role_capabilities( $existing, $posted ) {
		$existing = is_array( $existing ) ? $existing : array();
		$posted   = is_array( $posted ) ? $posted : array();
		$allowed  = array_keys( self::get_all_capabilities() );

		$preserved = array();
		foreach ( $existing as $cap ) {
			if ( ! in_array( $cap, $allowed, true ) ) {
				continue;
			}
			if ( ! self::current_user_can_grant( $cap ) ) {
				$preserved[] = $cap;
			}
		}

		$from_post = self::filter_grantable_capabilities( $posted );

		return array_values( array_unique( array_merge( $preserved, $from_post ) ) );
	}

	/**
	 * Whether a role is the protected System Administrator seed role.
	 *
	 * @param object|null $role Role row.
	 * @return bool
	 */
	public static function is_protected_system_role( $role ) {
		return is_object( $role ) && isset( $role->role_name ) && 'System Administrator' === $role->role_name;
	}

	/**
	 * Whether the current user may assign a given reMember role to a member.
	 *
	 * Event roles with no capabilities are always assignable (when the caller
	 * already holds remember_update_members). System roles are assignable only
	 * when every capability on the role is grantable by the current user.
	 *
	 * @param int $role_id Role ID.
	 * @return bool
	 */
	public static function current_user_can_assign_role( $role_id ) {
		$role_id = absint( $role_id );
		if ( $role_id < 1 ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';
		$role_model = new Remember_Role();
		$role       = $role_model->get( $role_id );
		if ( ! $role ) {
			return false;
		}

		$caps = $role_model->get_capabilities( $role_id );
		if ( empty( $caps ) ) {
			return true;
		}

		foreach ( $caps as $cap ) {
			if ( ! self::current_user_can_grant( $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the current user may administer a given WordPress account.
	 *
	 * A user may always administer their own account, and administrators (users
	 * with manage_options) may administer anyone. Everyone else may act only on
	 * non-privileged accounts: never on administrators, users who can edit other
	 * users, or network super admins. This guards reMember actions that write to
	 * WordPress credentials (email, password) so delegated staff cannot take over
	 * a higher-privileged account. It deliberately does NOT use the edit_user meta
	 * cap, which maps to the admin-only edit_users capability and would block every
	 * delegated staff user from managing ordinary members.
	 *
	 * @param int $target_id Target WordPress user ID.
	 * @return bool
	 */
	public static function current_user_can_administer_account( $target_id ) {
		$target_id = absint( $target_id );
		if ( $target_id < 1 ) {
			return false;
		}
		if ( get_current_user_id() === $target_id ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( user_can( $target_id, 'manage_options' ) || user_can( $target_id, 'edit_users' ) || is_super_admin( $target_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Filter a list of role IDs down to those the current user may assign.
	 *
	 * @param array $role_ids Role IDs.
	 * @return array
	 */
	public static function filter_assignable_role_ids( $role_ids ) {
		if ( ! is_array( $role_ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $role_ids as $role_id ) {
			$role_id = absint( $role_id );
			if ( $role_id > 0 && self::current_user_can_assign_role( $role_id ) ) {
				$out[] = $role_id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Set up all plugin capabilities.
	 *
	 * @since    1.0.0
	 */
	public static function setup_capabilities() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::activation_debug( 'setup_capabilities: start' );

		// Get administrator role
		$admin_role = get_role( 'administrator' );
		
		if ( $admin_role ) {
			$capabilities = self::get_all_capabilities();
			foreach ( array_keys( $capabilities ) as $cap ) {
				$admin_role->add_cap( $cap );
			}
			Remember_Logger::activation_debug( 'setup_capabilities: caps added to administrator', array( 'count' => count( $capabilities ) ) );
		} else {
			Remember_Logger::activation_debug( 'setup_capabilities: administrator role missing' );
		}

		Remember_Logger::activation_debug( 'setup_capabilities: end' );
	}

	/**
	 * Remove all plugin capabilities.
	 *
	 * @since    1.0.0
	 */
	public static function remove_capabilities() {
		$admin_role = get_role( 'administrator' );
		
		if ( $admin_role ) {
			$capabilities = self::get_all_capabilities();
			foreach ( array_keys( $capabilities ) as $cap ) {
				$admin_role->remove_cap( $cap );
			}
		}
	}
}
