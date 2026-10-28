<?php
/**
 * Member model class
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
 * Member model class.
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */
class Remember_Member extends Remember_Base_Model {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'members';

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'member_id';

	/**
	 * Statuses the members table can store.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'pending_vetting', 'unvetted', 'in_vetting', 'vetted', 'rejected', 'inactive', 'merged' );
	}

	/**
	 * Get all members, excluding merged (locked) profiles unless requested.
	 *
	 * @param array $args Query arguments. Set include_merged to true to include locked profiles.
	 * @return array
	 */
	public function get_all( $args = array() ) {
		$defaults = array(
			'limit'           => -1,
			'offset'          => 0,
			'orderby'         => $this->primary_key,
			'order'           => 'ASC',
			'include_merged'  => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT * FROM {$this->get_table()}";
		if ( empty( $args['include_merged'] ) ) {
			$query .= " WHERE (status IS NULL OR status != 'merged')";
		}

		if ( ! empty( $args['orderby'] ) ) {
			$orderby = $this->sanitize_orderby( $args['orderby'] );
			$order   = $this->sanitize_order( $args['order'] );
			$query  .= " ORDER BY `{$orderby}` {$order}";
		}

		if ( $args['limit'] > 0 ) {
			$query .= $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}

		return $this->wpdb->get_results( $query );
	}

	/**
	 * Create a new member.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  Member status.
	 * @return int|false Member ID or false on error.
	 */
	public function create( $user_id, $status = 'pending_vetting' ) {
		$status = strtolower( trim( (string) $status ) );
		if ( '' === $status ) {
			$status = 'pending_vetting';
		}
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}

		$data = array(
			'member_id'  => $user_id,
			'status'     => $status,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		return $this->insert( $data );
	}

	/**
	 * Create a member (and stub profile if missing) from an existing WordPress user.
	 *
	 * Does not change display_name / nickname. Legal name fields are seeded from
	 * WP first_name / last_name meta only (never from display_name).
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  Initial member status.
	 * @return int|false Member ID on success, false on failure.
	 */
	public function create_from_wp_user( $user_id, $status = 'pending_vetting' ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return false;
		}

		if ( $this->get( $user_id ) ) {
			return false;
		}

		$member_id = $this->create( $user_id, $status );
		if ( ! $member_id ) {
			return false;
		}

		global $wpdb;
		$profiles_table = $wpdb->prefix . 'remember_member_profiles';
		$existing_profile = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT profile_id FROM {$profiles_table} WHERE member_id = %d",
				$user_id
			)
		);

		if ( ! $existing_profile ) {
			$first_name = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
			$last_name  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );

			$wpdb->insert(
				$profiles_table,
				array(
					'member_id'                      => $user_id,
					'legal_first_name'               => $first_name,
					'legal_last_name'                => $last_name,
					'emergency_contact_first'        => $first_name,
					'emergency_contact_last'         => $last_name,
					'emergency_contact_phone'        => '',
					'emergency_contact_relationship' => '',
					'created_at'                     => current_time( 'mysql' ),
					'updated_at'                     => current_time( 'mysql' ),
					'updated_by'                     => get_current_user_id() ? get_current_user_id() : $user_id,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		return $user_id;
	}

	/**
	 * WordPress users who do not yet have a reMember member row.
	 *
	 * @return array List of WP_User-like objects with ID, user_login, display_name, user_email.
	 */
	public function get_non_member_wp_users() {
		global $wpdb;
		$members_table = $this->get_table();

		return $wpdb->get_results(
			"SELECT u.ID, u.user_login, u.display_name, u.user_email
			FROM {$wpdb->users} u
			LEFT JOIN {$members_table} m ON u.ID = m.member_id
			WHERE m.member_id IS NULL
			ORDER BY u.user_login ASC"
		);
	}

	/**
	 * Update member status.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $status    New status.
	 * @return int|false
	 */
	public function update_status( $member_id, $status ) {
		return $this->update(
			$member_id,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Update member photo.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $photo_url Photo URL.
	 * @return int|false
	 */
	public function update_photo( $member_id, $photo_url ) {
		return $this->update(
			$member_id,
			array(
				'photo_url'  => $photo_url,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get member by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return object|null
	 */
	public function get_by_user_id( $user_id ) {
		return $this->get( $user_id );
	}

	/**
	 * Whether the member has vetted status (may apply to events and use member-only features).
	 *
	 * @param object|null $member Row from get().
	 * @return bool
	 */
	public static function is_vetted_member( $member ) {
		$is_vetted = $member && isset( $member->status ) && 'vetted' === $member->status;
		return (bool) apply_filters( 'remember_member_is_vetted', $is_vetted, $member );
	}

	/**
	 * Get members by status.
	 *
	 * @param string $status Member status.
	 * @param array  $args   Query arguments.
	 * @return array
	 */
	public function get_by_status( $status, $args = array() ) {
		$args['where'] = array( 'status' => $status );
		return $this->get_all_with_where( $args );
	}

	/**
	 * Get members by role ID.
	 *
	 * @param int   $role_id Role ID.
	 * @param array $args    Query arguments.
	 * @return array
	 */
	public function get_by_role( $role_id, $args = array() ) {
		global $wpdb;
		$role_id = absint( $role_id );
		
		$query = "SELECT m.* FROM {$this->get_table()} m 
			INNER JOIN {$wpdb->prefix}remember_member_roles mr ON m.member_id = mr.member_id 
			WHERE mr.role_id = %d AND (m.status IS NULL OR m.status != 'merged')
			ORDER BY m.member_id ASC";
		
		return $wpdb->get_results( $wpdb->prepare( $query, $role_id ) );
	}

	/**
	 * Get attendees (members with accepted applications) for events where the current user is working.
	 * A user is "working" on an event if they have an accepted application for that event.
	 *
	 * @param int $guard_member_id The member ID of the guard/user viewing attendees.
	 * @return array Array of member objects.
	 */
	public function get_attendees_for_guard( $guard_member_id ) {
		global $wpdb;
		
		// Get all events where this guard/user has an accepted application (they're working on these events)
		$guard_event_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT event_id 
			FROM {$wpdb->prefix}remember_event_applications 
			WHERE member_id = %d AND status = 'accepted'",
			$guard_member_id
		) );
		
		if ( empty( $guard_event_ids ) ) {
			return array();
		}
		
		// Get all member IDs who have accepted applications for those events (these are the attendees)
		$attendee_member_ids = $wpdb->get_col(
			"SELECT DISTINCT member_id 
			FROM {$wpdb->prefix}remember_event_applications 
			WHERE event_id IN (" . implode( ',', array_map( 'absint', $guard_event_ids ) ) . ") 
			AND status = 'accepted' 
			AND member_id != " . absint( $guard_member_id )
		);
		
		if ( empty( $attendee_member_ids ) ) {
			return array();
		}
		
		// Get the member records
		$placeholders = implode( ',', array_fill( 0, count( $attendee_member_ids ), '%d' ) );
		$query = $wpdb->prepare(
			"SELECT * FROM {$this->get_table()} 
			WHERE member_id IN ($placeholders) 
			ORDER BY member_id ASC",
			$attendee_member_ids
		);
		
		return $wpdb->get_results( $query );
	}

	/**
	 * Whether an attendees-only guard may view a specific member.
	 *
	 * True when both share at least one event where each has an accepted application,
	 * and the target is not the guard themselves. Matches get_attendees_for_guard() scope.
	 *
	 * @param int $guard_member_id  Guard (viewer) member ID.
	 * @param int $target_member_id Member being viewed.
	 * @return bool
	 */
	public function guard_can_view_attendee( $guard_member_id, $target_member_id ) {
		global $wpdb;

		$guard_member_id  = absint( $guard_member_id );
		$target_member_id = absint( $target_member_id );

		if ( $guard_member_id <= 0 || $target_member_id <= 0 || $guard_member_id === $target_member_id ) {
			return false;
		}

		$shared = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				FROM {$wpdb->prefix}remember_event_applications g
				INNER JOIN {$wpdb->prefix}remember_event_applications t
					ON g.event_id = t.event_id
				WHERE g.member_id = %d
					AND g.status = 'accepted'
					AND t.member_id = %d
					AND t.status = 'accepted'
				LIMIT 1",
				$guard_member_id,
				$target_member_id
			)
		);

		return ! empty( $shared );
	}

	/**
	 * Get event roles assigned to this member.
	 *
	 * @param int $member_id Member ID.
	 * @return array Array of role IDs.
	 */
	public function get_member_event_role_ids( $member_id ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT mr.role_id 
				FROM {$wpdb->prefix}remember_member_roles mr 
				INNER JOIN {$wpdb->prefix}remember_roles r ON mr.role_id = r.role_id 
				WHERE mr.member_id = %d 
				AND r.role_type = 'event'",
				$member_id
			)
		);
	}

	/**
	 * Get all valid members (those with existing WordPress users).
	 * Filters out orphaned records where the WordPress user no longer exists.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_all_valid( $args = array() ) {
		global $wpdb;
		
		$defaults = array(
			'limit'   => -1,
			'offset'  => 0,
			'orderby' => $this->primary_key,
			'order'   => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT m.* FROM {$this->get_table()} m 
			INNER JOIN {$wpdb->prefix}users u ON m.member_id = u.ID
			WHERE (m.status IS NULL OR m.status != 'merged')";

		if ( ! empty( $args['orderby'] ) ) {
			$orderby = $this->sanitize_orderby( $args['orderby'] );
			$order   = $this->sanitize_order( $args['order'] );
			$query  .= " ORDER BY m.`{$orderby}` {$order}";
		}

		if ( $args['limit'] > 0 ) {
			$query .= $wpdb->prepare( " LIMIT %d OFFSET %d", $args['limit'], $args['offset'] );
		}

		return $wpdb->get_results( $query );
	}

	/**
	 * Get all records with WHERE conditions.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function get_all_with_where( $args = array() ) {
		$defaults = array(
			'where'   => array(),
			'limit'   => -1,
			'offset'  => 0,
			'orderby' => $this->primary_key,
			'order'   => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT * FROM {$this->get_table()}";

		if ( ! empty( $args['where'] ) ) {
			$conditions = array();
			foreach ( $args['where'] as $column => $value ) {
				if ( is_numeric( $value ) ) {
					$conditions[] = $this->wpdb->prepare( "{$column} = %d", $value );
				} else {
					$conditions[] = $this->wpdb->prepare( "{$column} = %s", $value );
				}
			}
			$query .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		if ( ! empty( $args['orderby'] ) ) {
			$orderby = $this->sanitize_orderby( $args['orderby'] );
			$order   = $this->sanitize_order( $args['order'] );
			$query  .= " ORDER BY `{$orderby}` {$order}";
		}

		if ( $args['limit'] > 0 ) {
			$query .= $this->wpdb->prepare( " LIMIT %d OFFSET %d", $args['limit'], $args['offset'] );
		}

		return $this->wpdb->get_results( $query );
	}

	/**
	 * Build public display-name choices (mirrors WordPress core profile behavior).
	 *
	 * @param WP_User     $user     User object.
	 * @param string|null $nickname Optional nickname override (defaults to user meta).
	 * @return array Unique display name strings suitable for a select list.
	 */
	public static function get_public_display_name_choices( $user, $nickname = null ) {
		if ( ! $user instanceof WP_User ) {
			return array();
		}

		if ( null === $nickname ) {
			$nickname = get_user_meta( $user->ID, 'nickname', true );
		}
		$nickname   = is_string( $nickname ) ? trim( $nickname ) : '';
		$first_name = trim( (string) $user->first_name );
		$last_name  = trim( (string) $user->last_name );

		$choices = array();
		if ( '' !== $nickname ) {
			$choices[] = $nickname;
		}
		if ( ! empty( $user->user_login ) ) {
			$choices[] = $user->user_login;
		}
		if ( '' !== $first_name ) {
			$choices[] = $first_name;
		}
		if ( '' !== $last_name ) {
			$choices[] = $last_name;
		}
		if ( '' !== $first_name && '' !== $last_name ) {
			$choices[] = trim( $first_name . ' ' . $last_name );
			$choices[] = trim( $last_name . ' ' . $first_name );
		}
		if ( ! empty( $user->display_name ) ) {
			$choices[] = $user->display_name;
		}

		$choices = array_values( array_unique( array_filter( $choices, 'strlen' ) ) );

		/**
		 * Filter public display name choices for profile editing.
		 *
		 * @param array   $choices Display name options.
		 * @param WP_User $user    User object.
		 */
		return apply_filters( 'remember_public_display_name_choices', $choices, $user );
	}

	/**
	 * Resolve a submitted display name against allowed public choices.
	 *
	 * @param WP_User $user               User object (with updated first/last if already applied).
	 * @param string  $nickname           Nickname to use when building choices.
	 * @param string  $requested_display  Requested display name.
	 * @return string Safe display name.
	 */
	public static function resolve_public_display_name( $user, $nickname, $requested_display ) {
		$choices  = self::get_public_display_name_choices( $user, $nickname );
		$requested = is_string( $requested_display ) ? trim( $requested_display ) : '';

		if ( '' !== $requested && in_array( $requested, $choices, true ) ) {
			return $requested;
		}

		$nickname = is_string( $nickname ) ? trim( $nickname ) : '';
		if ( '' !== $nickname ) {
			return $nickname;
		}

		if ( ! empty( $user->user_login ) ) {
			return $user->user_login;
		}

		return ! empty( $user->display_name ) ? $user->display_name : '';
	}
}
