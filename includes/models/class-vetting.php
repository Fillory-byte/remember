<?php
/**
 * Vetting model class
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-base-model.php';

/**
 * Vetting model class.
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */
class Remember_Vetting extends Remember_Base_Model {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'vetting';

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'vetting_id';

	/**
	 * Every status a vetting case can hold.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'pending', 'scheduled', 'in_progress', 'completed' );
	}

	/**
	 * Statuses staff may pick by hand. Completed is set only by recording an
	 * accepted or rejected decision, so the member status and result email follow.
	 *
	 * @return string[]
	 */
	public static function manual_statuses() {
		return array_values( array_diff( self::statuses(), array( 'completed' ) ) );
	}

	/**
	 * Whether a WordPress account may be a primary vetter or collaborator.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_be_vetter( $user_id ) {
		$user_id = absint( $user_id );
		return $user_id > 0 && false !== get_userdata( $user_id ) && user_can( $user_id, 'remember_update_vetting' );
	}

	/**
	 * Whether a user is an active (not declined) collaborator on a case.
	 *
	 * @param int $vetting_id Vetting ID.
	 * @param int $user_id    WordPress user ID.
	 * @return bool
	 */
	public function is_collaborator( $vetting_id, $user_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_vetting_collaborators';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE vetting_id = %d AND member_id = %d AND status != 'declined'",
				absint( $vetting_id ),
				absint( $user_id )
			)
		) > 0;
	}

	/**
	 * Whether a user may work a case: schedule, change status, record the
	 * decision and add collaborators. Every vetter can see every case, but only
	 * the primary vetter, collaborators and users with Access Settings work it.
	 *
	 * @param object $vetting Vetting row.
	 * @param int    $user_id WordPress user ID.
	 * @return bool
	 */
	public function user_can_work( $vetting, $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $vetting || ! self::user_can_be_vetter( $user_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'remember_access_settings' ) ) {
			return true;
		}
		if ( (int) $vetting->primary_vetter_id === $user_id ) {
			return true;
		}
		return $this->is_collaborator( $vetting->vetting_id, $user_id );
	}

	/**
	 * Whether a user may set the primary vetter. Any vetter may while the case
	 * is unassigned; after that only the primary vetter or a user with Access
	 * Settings may reassign it.
	 *
	 * @param object $vetting Vetting row.
	 * @param int    $user_id WordPress user ID.
	 * @return bool
	 */
	public function user_can_assign( $vetting, $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $vetting || ! self::user_can_be_vetter( $user_id ) ) {
			return false;
		}
		if ( empty( $vetting->primary_vetter_id ) || user_can( $user_id, 'remember_access_settings' ) ) {
			return true;
		}
		return (int) $vetting->primary_vetter_id === $user_id;
	}

	/**
	 * Create a new vetting record.
	 *
	 * @param int    $member_id         Member ID.
	 * @param int    $primary_vetter_id Primary vetter user ID (optional, can be 0).
	 * @param string $status            Vetting status.
	 * @return int|false Vetting ID or false on error.
	 */
	public function create( $member_id, $primary_vetter_id = 0, $status = 'pending' ) {
		$data = array(
			'member_id'         => $member_id,
			'status'            => $status,
			'decision'          => 'pending',
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
			// NULL when nobody is assigned. Omitting the column fails on tables
			// where primary_vetter_id is still NOT NULL without a default.
			'primary_vetter_id' => $primary_vetter_id > 0 ? (int) $primary_vetter_id : null,
		);

		return $this->insert( $data );
	}

	/**
	 * Update vetting status.
	 *
	 * @param int    $vetting_id Vetting ID.
	 * @param string $status     New status.
	 * @return int|false
	 */
	public function update_status( $vetting_id, $status ) {
		return $this->update(
			$vetting_id,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Complete vetting with decision.
	 *
	 * @param int    $vetting_id Vetting ID.
	 * @param string $decision   Decision (accepted, rejected).
	 * @return int|false
	 */
	public function complete( $vetting_id, $decision ) {
		return $this->update(
			$vetting_id,
			array(
				'status'        => 'completed',
				'decision'      => $decision,
				'completed_at'  => current_time( 'mysql' ),
				'decision_date' => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Schedule vetting.
	 *
	 * @param int      $vetting_id  Vetting ID.
	 * @param datetime $scheduled_at Scheduled date/time.
	 * @return int|false
	 */
	public function schedule( $vetting_id, $scheduled_at ) {
		return $this->update(
			$vetting_id,
			array(
				'status'        => 'scheduled',
				'scheduled_at'  => $scheduled_at,
				'updated_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get vetting by member (most recent case).
	 *
	 * @param int $member_id Member ID.
	 * @return object|null
	 */
	public function get_by_member( $member_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE member_id = %d ORDER BY created_at DESC LIMIT 1",
				$member_id
			)
		);
	}

	/**
	 * Get most recent vetting case for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return object|null
	 */
	public function get_latest_by_member( $member_id ) {
		return $this->get_by_member( $member_id );
	}

	/**
	 * Get all vetting cases for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	public function get_all_by_member( $member_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE member_id = %d ORDER BY created_at DESC",
				$member_id
			)
		);
	}

	/**
	 * Get vetting records by status.
	 *
	 * @param string $status Vetting status.
	 * @return array
	 */
	public function get_by_status( $status ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE status = %s ORDER BY created_at DESC",
				$status
			)
		);
	}

	/**
	 * Get vetting records assigned to a vetter.
	 *
	 * @param int $vetter_id Vetter user ID.
	 * @return array
	 */
	public function get_by_vetter( $vetter_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE primary_vetter_id = %d ORDER BY created_at DESC",
				$vetter_id
			)
		);
	}

	/**
	 * Get pending vetting records.
	 *
	 * @return array
	 */
	public function get_pending() {
		return $this->get_by_status( 'pending' );
	}

	/**
	 * Get all open (non-completed) vetting records.
	 *
	 * @return array
	 */
	public function get_open() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->get_table()} WHERE status != 'completed' ORDER BY created_at DESC"
		);
	}

	/**
	 * Add a note to a vetting case.
	 *
	 * @param int    $vetting_id   Vetting ID.
	 * @param int    $member_id    User ID who created the note.
	 * @param string $note_content Note content.
	 * @param bool   $is_admin_only Whether note is admin-only.
	 * @return int|false Note ID or false on error.
	 */
	public function add_note( $vetting_id, $member_id, $note_content, $is_admin_only = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_vetting_notes';
		
		return $wpdb->insert(
			$table_name,
			array(
				'vetting_id'    => $vetting_id,
				'member_id'     => $member_id,
				'note_content'  => sanitize_textarea_field( $note_content ),
				'is_admin_only' => $is_admin_only ? 1 : 0,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		) ? $wpdb->insert_id : false;
	}

	/**
	 * Get notes for a vetting case.
	 *
	 * @param int $vetting_id Vetting ID.
	 * @return array
	 */
	public function get_notes( $vetting_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_vetting_notes';
		
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE vetting_id = %d ORDER BY created_at DESC",
				$vetting_id
			)
		);
	}

	/**
	 * Get collaborators for a vetting case.
	 *
	 * @param int $vetting_id Vetting ID.
	 * @return array
	 */
	public function get_collaborators( $vetting_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_vetting_collaborators';
		
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE vetting_id = %d ORDER BY invited_at ASC",
				$vetting_id
			)
		);
	}

	/**
	 * Add a collaborator to a vetting case.
	 *
	 * @param int $vetting_id  Vetting ID.
	 * @param int $member_id   Collaborator user ID.
	 * @param int $invited_by  User ID who invited the collaborator.
	 * @return int|false Collaborator ID or false on error.
	 */
	public function add_collaborator( $vetting_id, $member_id, $invited_by ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_vetting_collaborators';
		
		// Check if already exists
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE vetting_id = %d AND member_id = %d",
			$vetting_id,
			$member_id
		) );
		
		if ( $exists > 0 ) {
			return false; // Already exists
		}
		
		return $wpdb->insert(
			$table_name,
			array(
				'vetting_id'  => $vetting_id,
				'member_id'   => $member_id,
				'invited_by'  => $invited_by,
				'invited_at'  => current_time( 'mysql' ),
				'status'      => 'pending',
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		) ? $wpdb->insert_id : false;
	}
}
