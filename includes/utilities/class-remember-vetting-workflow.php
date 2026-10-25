<?php
/**
 * Vetting workflow utility class
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Vetting workflow utility class.
 *
 * Handles configurable vetting workflow logic.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */
class Remember_Vetting_Workflow {

	/**
	 * Get the configured vetting workflow.
	 *
	 * Anything other than first-application vetting is member-join vetting.
	 * A blank or unexpected stored value used to match neither check, so a
	 * new member got the signup email and no case.
	 *
	 * @return string 'on_join' or 'first_application'
	 */
	public static function get_workflow() {
		$options  = get_option( 'remember_options', array() );
		$workflow = isset( $options['vetting_workflow'] ) ? $options['vetting_workflow'] : 'on_join';
		return 'first_application' === $workflow ? 'first_application' : 'on_join';
	}

	/**
	 * Check if vetting should be created on member join.
	 *
	 * @return bool
	 */
	public static function should_vet_on_join() {
		return self::get_workflow() === 'on_join';
	}

	/**
	 * Check if vetting should be created on first application.
	 *
	 * @return bool
	 */
	public static function should_vet_on_first_application() {
		return self::get_workflow() === 'first_application';
	}

	/**
	 * Check if a member has any applications.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public static function member_has_applications( $member_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}remember_event_applications WHERE member_id = %d",
			$member_id
		) );
		return $count > 0;
	}

	/**
	 * Check if this is the member's first application.
	 *
	 * Call before the new application is saved.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public static function is_first_application( $member_id ) {
		return ! self::member_has_applications( $member_id );
	}

	/**
	 * Create vetting case for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return int|false Vetting ID or false on error.
	 */
	public static function create_vetting_case( $member_id ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		
		$vetting_model = new Remember_Vetting();
		
		// Get default vetter (first user with vetting capability, or current user, or 0 if none)
		$vetters = get_users( array(
			'capability__in' => array( 'remember_create_vetting', 'remember_update_vetting' ),
			'number' => 1,
		) );
		
		$primary_vetter_id = 0; // Default to no vetter assigned
		if ( ! empty( $vetters ) ) {
			$primary_vetter_id = $vetters[0]->ID;
		} elseif ( get_current_user_id() > 0 ) {
			$current_user = wp_get_current_user();
			if ( $current_user->has_cap( 'remember_create_vetting' ) || $current_user->has_cap( 'remember_update_vetting' ) ) {
				$primary_vetter_id = get_current_user_id();
			}
		}
		
		// Create vetting case. primary_vetter_id may be 0 when nobody can be assigned.
		$vetting_id = $vetting_model->create( $member_id, $primary_vetter_id, 'pending' );
		if ( ! $vetting_id && self::repair_vetting_schema_for_insert( $vetting_model->get_last_error() ) ) {
			$vetting_id = $vetting_model->create( $member_id, $primary_vetter_id, 'pending' );
		}

		if ( ! $vetting_id ) {
			Remember_Logger::warning(
				'Vetting case not created',
				array(
					'member_id' => $member_id,
					'db_error'  => $vetting_model->get_last_error(),
				)
			);
			return false;
		}

		// Update member status to in_vetting when a new case is created.
		// This allows re-vetting of previously vetted or rejected members.
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member_model = new Remember_Member();
		$member       = $member_model->get( $member_id );
		if ( $member ) {
			$member_model->update_status( $member_id, 'in_vetting' );
		}

		// Attribute the system note to the person who is logged in. Public
		// registration has no user, and user 1 may not exist, so skip the note
		// rather than fail or mis-attribute the case.
		$note_author = get_current_user_id();
		if ( $note_author > 0 ) {
			$system_note = __( 'SYSTEM: Vetting case created automatically', 'remember' );
			if ( $primary_vetter_id > 0 ) {
				$vetter_user = get_user_by( 'ID', $primary_vetter_id );
				if ( $vetter_user ) {
					$system_note .= sprintf( __( ' with primary vetter %s', 'remember' ), $vetter_user->display_name );
				}
			}
			$note_id = $vetting_model->add_note( $vetting_id, $note_author, $system_note, true );
			if ( ! $note_id ) {
				Remember_Logger::warning(
					'Vetting case created but system note was not saved',
					array(
						'member_id'  => $member_id,
						'vetting_id' => $vetting_id,
					)
				);
			}
		}

		Remember_Logger::info( 'Vetting case created', array( 'member_id' => $member_id, 'vetting_id' => $vetting_id ) );

		return $vetting_id;
	}

	/**
	 * Make an old vetting table accept a case with no vetter, then allow retry.
	 *
	 * Sites created before 1.1.0 have primary_vetter_id NOT NULL. The 1.1.0
	 * updater recorded success even when that ALTER failed, so a public
	 * registration (no assigned vetter) still cannot insert a row.
	 *
	 * @param string $db_error Last insert error.
	 * @return bool True when the schema changed and the insert should be retried.
	 */
	private static function repair_vetting_schema_for_insert( $db_error ) {
		global $wpdb;

		$changed = false;
		$table   = $wpdb->prefix . 'remember_vetting';
		$column  = $wpdb->get_row( "SHOW COLUMNS FROM {$table} WHERE Field = 'primary_vetter_id'" );

		if ( $column && isset( $column->Null ) && false === strpos( $column->Null, 'YES' ) ) {
			$result = $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN primary_vetter_id BIGINT(20) UNSIGNED DEFAULT NULL" );
			if ( false === $result ) {
				Remember_Logger::error(
					'Failed to allow an unassigned primary vetter',
					array( 'error' => $wpdb->last_error )
				);
			} else {
				Remember_Logger::info( 'Vetting table updated to allow an unassigned primary vetter' );
				$changed = true;
			}
		}

		if ( is_string( $db_error ) && false !== stripos( $db_error, 'Duplicate entry' ) ) {
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'member_id' AND Non_unique = 0" );
			if ( ! empty( $indexes ) ) {
				$dropped = $wpdb->query( "ALTER TABLE {$table} DROP INDEX member_id" );
				if ( false === $dropped ) {
					Remember_Logger::error(
						'Failed to drop unique key on vetting.member_id',
						array( 'error' => $wpdb->last_error )
					);
				} else {
					$added = $wpdb->query( "ALTER TABLE {$table} ADD INDEX member_id (member_id)" );
					if ( false === $added ) {
						Remember_Logger::error(
							'Failed to add regular index on vetting.member_id',
							array( 'error' => $wpdb->last_error )
						);
					}
					$changed = true;
				}
			}
		}

		return $changed;
	}
}
