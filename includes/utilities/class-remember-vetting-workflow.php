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
	 * How automatically created vetting cases are assigned.
	 *
	 * @return string 'team' (no primary vetter; the vetting team is emailed), 'rotate', or 'least_busy'.
	 */
	public static function get_assignment_mode() {
		$options = get_option( 'remember_options', array() );
		$mode    = isset( $options['vetting_assignment'] ) ? $options['vetting_assignment'] : 'team';
		return in_array( $mode, array( 'rotate', 'least_busy' ), true ) ? $mode : 'team';
	}

	/**
	 * WordPress user IDs of members who hold the Vetting role, lowest first.
	 *
	 * @return int[]
	 */
	public static function vetting_team_ids() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT mr.member_id
				FROM {$wpdb->prefix}remember_member_roles mr
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
				WHERE r.role_name = %s",
				'Vetting'
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ), 'get_userdata' ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Next vetter in the rotation, after the one who got the last automatic case.
	 *
	 * @return int User ID, or 0 when nobody holds the Vetting role.
	 */
	public static function next_rotation_vetter() {
		return self::rotation_pick( self::vetting_team_ids() );
	}

	/**
	 * Vetter with the fewest open cases as primary vetter.
	 *
	 * Ties go to the next tied vetter in the rotation.
	 *
	 * @return int User ID, or 0 when nobody holds the Vetting role.
	 */
	public static function least_busy_vetter() {
		$team = self::vetting_team_ids();
		if ( empty( $team ) ) {
			return 0;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $team ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT primary_vetter_id, COUNT(*) AS open_cases
				FROM {$wpdb->prefix}remember_vetting
				WHERE status != 'completed' AND primary_vetter_id IN ($placeholders)
				GROUP BY primary_vetter_id",
				$team
			)
		);

		$load = array_fill_keys( $team, 0 );
		foreach ( (array) $rows as $row ) {
			$load[ (int) $row->primary_vetter_id ] = (int) $row->open_cases;
		}

		return self::rotation_pick( array_keys( $load, min( $load ), true ) );
	}

	/**
	 * First candidate after the vetter who got the last automatic case.
	 *
	 * @param int[] $candidates User IDs, lowest first.
	 * @return int User ID, or 0 when there are no candidates.
	 */
	private static function rotation_pick( $candidates ) {
		if ( empty( $candidates ) ) {
			return 0;
		}
		$last = (int) get_option( 'remember_vetting_rotation_last', 0 );
		foreach ( $candidates as $user_id ) {
			if ( $user_id > $last ) {
				return $user_id;
			}
		}
		return $candidates[0];
	}

	/**
	 * Whether this member may submit event applications.
	 *
	 * Vetted members always may. With first-application vetting, members who
	 * have not been vetted yet may too: their first application opens the case,
	 * and staff cannot accept it until the member is vetted.
	 *
	 * @param object|null $member Row from Remember_Member::get().
	 * @return bool
	 */
	public static function member_can_apply( $member ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';

		if ( Remember_Member::is_vetted_member( $member ) ) {
			return true;
		}

		return self::should_vet_on_first_application()
			&& $member
			&& isset( $member->status )
			&& in_array( $member->status, array( 'unvetted', 'in_vetting' ), true );
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

		$assignment = self::get_assignment_mode();
		if ( 'rotate' === $assignment ) {
			$primary_vetter_id = self::next_rotation_vetter();
		} elseif ( 'least_busy' === $assignment ) {
			$primary_vetter_id = self::least_busy_vetter();
		} else {
			$primary_vetter_id = 0;
		}
		if ( 'team' !== $assignment && $primary_vetter_id < 1 ) {
			Remember_Logger::warning(
				'Automatic vetting assignment found nobody with the Vetting role; case left unassigned',
				array(
					'member_id'  => $member_id,
					'assignment' => $assignment,
				)
			);
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
			} else {
				$system_note .= __( ' with no primary vetter; the vetting team was notified', 'remember' );
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

		if ( $primary_vetter_id > 0 ) {
			update_option( 'remember_vetting_rotation_last', $primary_vetter_id, false );
		}

		Remember_Logger::info(
			'Vetting case created',
			array(
				'member_id'         => $member_id,
				'vetting_id'        => $vetting_id,
				'assignment'        => $assignment,
				'primary_vetter_id' => $primary_vetter_id,
			)
		);

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		Remember_Notifications::notify_vetting_staff( $primary_vetter_id > 0 ? 'vetting_assigned' : 'vetting_case_waiting', $vetting_id );

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
