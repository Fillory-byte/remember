<?php
/**
 * Duplicate profile detection, review, and merge.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Cron scan, admin review, merge, and lockout for duplicate member profiles.
 */
class Remember_Profile_Duplicates {

	const PASSWORD_META = 'remember_password_updated_at';
	const MERGED_META   = 'remember_merged_into';
	const CRON_HOOK     = 'remember_duplicate_scan';
	const MERGE_CAP     = 'remember_merge_profiles';

	/**
	 * Hits table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'remember_profile_duplicate_hits';
	}

	/**
	 * Whether the current user may review and merge duplicates.
	 *
	 * @return bool
	 */
	public static function current_user_can_merge() {
		return current_user_can( self::MERGE_CAP );
	}

	/**
	 * Emails for reMember System Administrators (not WordPress administrators).
	 *
	 * @return string[]
	 */
	public static function admin_emails() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		return Remember_Notifications::admin_emails();
	}

	/**
	 * Stamp when a password is set or changed.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function stamp_password( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::PASSWORD_META, current_time( 'mysql' ) );
		}
	}

	/**
	 * When the password was last entered, falling back to user_registered.
	 *
	 * @param int $user_id User ID.
	 * @return string MySQL datetime.
	 */
	public static function password_updated_at( $user_id ) {
		$stamp = get_user_meta( absint( $user_id ), self::PASSWORD_META, true );
		if ( is_string( $stamp ) && '' !== $stamp ) {
			return $stamp;
		}
		$user = get_userdata( absint( $user_id ) );
		return $user && ! empty( $user->user_registered ) ? (string) $user->user_registered : '';
	}

	/**
	 * Schedule daily scan.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear cron on deactivate.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Block login for a merged (locked) profile.
	 *
	 * @param WP_User|WP_Error $user User or error.
	 * @return WP_User|WP_Error
	 */
	public static function filter_authenticate( $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}
		if ( self::member_is_merged( $user->ID ) ) {
			return new WP_Error(
				'remember_profile_merged',
				__( 'This profile is no longer valid. If you believe that is an error, contact a system administrator.', 'remember' )
			);
		}
		return $user;
	}

	/**
	 * Block lost-password mail for a merged (locked) profile.
	 *
	 * @param bool $allow   Whether reset is allowed.
	 * @param int  $user_id User ID.
	 * @return bool
	 */
	public static function filter_allow_password_reset( $allow, $user_id ) {
		if ( self::member_is_merged( $user_id ) ) {
			return false;
		}
		return $allow;
	}

	/**
	 * Do not send lost-password mail for a merged (locked) profile.
	 *
	 * @param bool    $send       Whether to send.
	 * @param string  $user_login Login.
	 * @param WP_User $user_data  User.
	 * @return bool
	 */
	public static function filter_send_retrieve_password_email( $send, $user_login, $user_data ) {
		unset( $user_login );
		if ( $user_data instanceof WP_User && self::member_is_merged( $user_data->ID ) ) {
			return false;
		}
		return $send;
	}

	/**
	 * Whether a member record is locked after a merge.
	 *
	 * @param int $user_id User / member ID.
	 * @return bool
	 */
	public static function member_is_merged( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member = ( new Remember_Member() )->get( $user_id );
		return $member && isset( $member->status ) && 'merged' === $member->status;
	}

	/**
	 * Emergency-contact profile columns (gated by remember_access_emergency_contact).
	 *
	 * @return string[]
	 */
	public static function emergency_fields() {
		return array(
			'emergency_contact_first',
			'emergency_contact_last',
			'emergency_contact_phone',
			'emergency_contact_relationship',
		);
	}

	/**
	 * Admin URL to review a hit (or the list).
	 *
	 * @param int $hit_id Hit ID, or 0 for the list.
	 * @return string
	 */
	public static function review_url( $hit_id = 0 ) {
		$args = array( 'page' => 'remember-duplicates' );
		$hit_id = absint( $hit_id );
		if ( $hit_id > 0 ) {
			$args['hit'] = $hit_id;
		}
		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	/**
	 * Load a hit row.
	 *
	 * @param int $hit_id Hit ID.
	 * @return object|null
	 */
	public static function get_hit( $hit_id ) {
		global $wpdb;
		$table = self::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE hit_id = %d", absint( $hit_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Hits for the admin list.
	 *
	 * @param string $status pending|dismissed|merged|closed|all.
	 * @return object[]
	 */
	public static function get_hits( $status = 'pending' ) {
		global $wpdb;
		$table = self::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$status = sanitize_key( $status );
		if ( 'all' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pending hits waiting for review.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table  = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Register cron, login lockout, and password stamps.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'scan' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'filter_authenticate' ), 20 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'filter_allow_password_reset' ), 10, 2 );
		add_filter( 'send_retrieve_password_email', array( __CLASS__, 'filter_send_retrieve_password_email' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'stamp_password' ) );
		add_action( 'password_reset', array( __CLASS__, 'on_password_reset' ), 10, 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Stamp password after a lost-password reset.
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	public static function on_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::stamp_password( $user->ID );
		}
	}

	/**
	 * Profile columns the admin can pick during merge.
	 *
	 * @return string[]
	 */
	public static function profile_columns() {
		return array(
			'legal_first_name',
			'legal_last_name',
			'cell_phone',
			'address_street',
			'address_city',
			'address_state',
			'address_postal',
			'address_country',
			'timezone',
			'im_type',
			'im_handle',
			'interests',
			'allergy_reaction',
			'shirt_size',
			'pants_size',
			'shoe_size',
			'emergency_contact_first',
			'emergency_contact_last',
			'emergency_contact_phone',
			'emergency_contact_relationship',
			'share_email_with_events',
			'share_phone_with_events',
			'share_location_with_events',
			'share_im_with_events',
			'share_interests_with_events',
			'share_photo_with_events',
			'member_number',
		);
	}

	/**
	 * Side-by-side fields shown on the review screen.
	 *
	 * @return array<string,string> field key => label.
	 */
	public static function review_fields() {
		return array(
			'display_name'                     => __( 'Display name', 'remember' ),
			'user_email'                       => __( 'Email', 'remember' ),
			'legal_first_name'                 => __( 'Legal first name', 'remember' ),
			'legal_last_name'                  => __( 'Legal last name', 'remember' ),
			'cell_phone'                       => __( 'Cell phone', 'remember' ),
			'address_street'                   => __( 'Street', 'remember' ),
			'address_city'                     => __( 'City', 'remember' ),
			'address_state'                    => __( 'State', 'remember' ),
			'address_postal'                   => __( 'Postal code', 'remember' ),
			'address_country'                  => __( 'Country', 'remember' ),
			'timezone'                         => __( 'Time zone', 'remember' ),
			'im_type'                          => __( 'Instant messenger type', 'remember' ),
			'im_handle'                        => __( 'Instant messenger handle', 'remember' ),
			'interests'                        => __( 'Interests', 'remember' ),
			'allergy_reaction'                 => __( 'Allergy reaction', 'remember' ),
			'shirt_size'                       => __( 'Shirt size', 'remember' ),
			'pants_size'                       => __( 'Pants size', 'remember' ),
			'shoe_size'                        => __( 'Shoe size', 'remember' ),
			'emergency_contact_first'          => __( 'Emergency contact first name', 'remember' ),
			'emergency_contact_last'           => __( 'Emergency contact last name', 'remember' ),
			'emergency_contact_phone'          => __( 'Emergency contact phone', 'remember' ),
			'emergency_contact_relationship'   => __( 'Emergency contact relationship', 'remember' ),
			'share_email_with_events'          => __( 'Share email with events', 'remember' ),
			'share_phone_with_events'          => __( 'Share phone with events', 'remember' ),
			'share_location_with_events'       => __( 'Share location with events', 'remember' ),
			'share_im_with_events'             => __( 'Share IM with events', 'remember' ),
			'share_interests_with_events'      => __( 'Share interests with events', 'remember' ),
			'share_photo_with_events'          => __( 'Share photo with events', 'remember' ),
			'member_number'                    => __( 'Member number', 'remember' ),
			'photo_url'                        => __( 'Photo', 'remember' ),
			'status'                           => __( 'Member status', 'remember' ),
		);
	}

	/**
	 * Display value for a review field from a snapshot.
	 *
	 * @param array  $snap  Snapshot from snapshot().
	 * @param string $field Field key.
	 * @return string
	 */
	public static function snapshot_display( $snap, $field ) {
		if ( in_array( $field, self::emergency_fields(), true ) && ! current_user_can( 'remember_access_emergency_contact' ) ) {
			return '';
		}
		if ( 'display_name' === $field ) {
			return ( $snap['user'] && isset( $snap['user']->display_name ) ) ? (string) $snap['user']->display_name : '';
		}
		if ( 'user_email' === $field ) {
			return ( $snap['user'] && isset( $snap['user']->user_email ) ) ? (string) $snap['user']->user_email : '';
		}
		if ( 'photo_url' === $field ) {
			return ( $snap['member'] && ! empty( $snap['member']->photo_url ) ) ? (string) $snap['member']->photo_url : '';
		}
		if ( 'status' === $field ) {
			return ( $snap['member'] && ! empty( $snap['member']->status ) ) ? (string) $snap['member']->status : '';
		}
		$profile = isset( $snap['profile'] ) ? $snap['profile'] : null;
		if ( $profile && isset( $profile->$field ) && null !== $profile->$field ) {
			return (string) $profile->$field;
		}
		return '';
	}

	/**
	 * Normalize a comparable string.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function normalize( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Normalize a handle (@Name → name).
	 *
	 * @param string $value Raw handle.
	 * @return string
	 */
	public static function normalize_handle( $value ) {
		$value = self::normalize( $value );
		$value = ltrim( $value, '@' );
		$value = preg_replace( '/[^a-z0-9._-]/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether two strings match or are similar enough.
	 *
	 * @param string $a         First.
	 * @param string $b         Second.
	 * @param int    $threshold similar_text percent.
	 * @param bool   $as_handle Normalize as handles.
	 * @return bool
	 */
	public static function similar( $a, $b, $threshold = 85, $as_handle = false ) {
		$a = $as_handle ? self::normalize_handle( $a ) : self::normalize( $a );
		$b = $as_handle ? self::normalize_handle( $b ) : self::normalize( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		if ( strlen( $a ) < 2 || strlen( $b ) < 2 ) {
			return false;
		}
		similar_text( $a, $b, $percent );
		return (float) $percent >= (float) $threshold;
	}

	/**
	 * Scan active members and insert new pending hits.
	 *
	 * @return int Number of new hits.
	 */
	public static function scan() {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		$table = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return 0;
		}

		$members = $wpdb->get_results(
			"SELECT m.member_id, m.status, p.legal_first_name, p.legal_last_name, p.address_city, p.address_state, p.im_handle, p.updated_at AS profile_updated_at,
				u.display_name, u.user_email
			FROM {$wpdb->prefix}remember_members m
			INNER JOIN {$wpdb->prefix}users u ON u.ID = m.member_id
			LEFT JOIN {$wpdb->prefix}remember_member_profiles p ON p.member_id = m.member_id
			WHERE m.status IS NULL OR m.status != 'merged'"
		);
		if ( empty( $members ) ) {
			return 0;
		}

		$social_rows = $wpdb->get_results(
			"SELECT member_id, platform_id, handle FROM {$wpdb->prefix}remember_member_social_media"
		);
		$social = array();
		if ( is_array( $social_rows ) ) {
			foreach ( $social_rows as $row ) {
				$hid = self::normalize_handle( $row->handle );
				if ( '' === $hid ) {
					continue;
				}
				$social[ (int) $row->member_id ][] = array(
					'platform_id' => (int) $row->platform_id,
					'handle'      => $hid,
					'raw'         => (string) $row->handle,
				);
			}
		}

		$existing = $wpdb->get_results( "SELECT member_a_id, member_b_id, status FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$known    = array();
		if ( is_array( $existing ) ) {
			foreach ( $existing as $row ) {
				$key           = (int) $row->member_a_id . ':' . (int) $row->member_b_id;
				$known[ $key ] = (string) $row->status;
			}
		}

		$created = 0;
		$count   = count( $members );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $members[ $i ];
				$b = $members[ $j ];
				$id_a = (int) $a->member_id;
				$id_b = (int) $b->member_id;
				if ( $id_a > $id_b ) {
					$tmp  = $a;
					$a    = $b;
					$b    = $tmp;
					$id_a = (int) $a->member_id;
					$id_b = (int) $b->member_id;
				}
				$key = $id_a . ':' . $id_b;
				if ( isset( $known[ $key ] ) ) {
					continue;
				}
				$reasons = self::match_reasons( $a, $b, isset( $social[ $id_a ] ) ? $social[ $id_a ] : array(), isset( $social[ $id_b ] ) ? $social[ $id_b ] : array() );
				if ( empty( $reasons ) ) {
					continue;
				}
				$ok = $wpdb->insert(
					$table,
					array(
						'member_a_id'   => $id_a,
						'member_b_id'   => $id_b,
						'status'        => 'pending',
						'match_reasons' => wp_json_encode( $reasons ),
						'created_at'    => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s', '%s' )
				);
				if ( $ok ) {
					$known[ $key ] = 'pending';
					++$created;
					self::notify_new_hit( (int) $wpdb->insert_id, $id_a, $id_b, $reasons );
				}
			}
		}

		return $created;
	}

	/**
	 * Match dimensions. A hit needs a handle match, or two overlapping dimensions.
	 * City and state together count as one location dimension so same-town pairs do not flood.
	 *
	 * @param object $a        Member row A.
	 * @param object $b        Member row B.
	 * @param array  $social_a Social handles A.
	 * @param array  $social_b Social handles B.
	 * @return array<int,array<string,string>>
	 */
	public static function match_reasons( $a, $b, $social_a, $social_b ) {
		$hits      = array();
		$strong    = false;
		$dimension = 0;

		if ( self::similar( isset( $a->legal_first_name ) ? $a->legal_first_name : '', isset( $b->legal_first_name ) ? $b->legal_first_name : '' ) ) {
			++$dimension;
			$hits[] = array(
				'field' => 'legal_first_name',
				'label' => __( 'Legal first name', 'remember' ),
				'a'     => (string) $a->legal_first_name,
				'b'     => (string) $b->legal_first_name,
			);
		}
		if ( self::similar( isset( $a->legal_last_name ) ? $a->legal_last_name : '', isset( $b->legal_last_name ) ? $b->legal_last_name : '' ) ) {
			++$dimension;
			$hits[] = array(
				'field' => 'legal_last_name',
				'label' => __( 'Legal last name', 'remember' ),
				'a'     => (string) $a->legal_last_name,
				'b'     => (string) $b->legal_last_name,
			);
		}
		$city_match  = self::similar( isset( $a->address_city ) ? $a->address_city : '', isset( $b->address_city ) ? $b->address_city : '', 90 );
		$state_match = self::similar( isset( $a->address_state ) ? $a->address_state : '', isset( $b->address_state ) ? $b->address_state : '', 90 );
		if ( $city_match || $state_match ) {
			++$dimension;
			if ( $city_match ) {
				$hits[] = array(
					'field' => 'address_city',
					'label' => __( 'City', 'remember' ),
					'a'     => (string) $a->address_city,
					'b'     => (string) $b->address_city,
				);
			}
			if ( $state_match ) {
				$hits[] = array(
					'field' => 'address_state',
					'label' => __( 'State', 'remember' ),
					'a'     => (string) $a->address_state,
					'b'     => (string) $b->address_state,
				);
			}
		}
		if ( self::similar( isset( $a->display_name ) ? $a->display_name : '', isset( $b->display_name ) ? $b->display_name : '' ) ) {
			++$dimension;
			$hits[] = array(
				'field' => 'display_name',
				'label' => __( 'Display name', 'remember' ),
				'a'     => (string) $a->display_name,
				'b'     => (string) $b->display_name,
			);
		}
		if ( self::similar( isset( $a->im_handle ) ? $a->im_handle : '', isset( $b->im_handle ) ? $b->im_handle : '', 85, true ) ) {
			++$dimension;
			$strong = true;
			$hits[] = array(
				'field' => 'im_handle',
				'label' => __( 'Instant messenger', 'remember' ),
				'a'     => (string) $a->im_handle,
				'b'     => (string) $b->im_handle,
			);
		}

		foreach ( $social_a as $sa ) {
			foreach ( $social_b as $sb ) {
				if ( $sa['handle'] === $sb['handle'] || ( (int) $sa['platform_id'] === (int) $sb['platform_id'] && self::similar( $sa['raw'], $sb['raw'], 85, true ) ) ) {
					++$dimension;
					$strong = true;
					$hits[] = array(
						'field' => 'social:' . (int) $sa['platform_id'],
						'label' => __( 'Social media handle', 'remember' ),
						'a'     => $sa['raw'],
						'b'     => $sb['raw'],
					);
					break 2;
				}
			}
		}

		if ( $strong || $dimension >= 2 ) {
			return $hits;
		}
		return array();
	}

	/**
	 * Emails reMember System Administrators when a new pending hit is stored.
	 * Members are not emailed on scan (false positives); they are emailed when a merge completes.
	 *
	 * @param int   $hit_id  Hit ID.
	 * @param int   $id_a    Member A.
	 * @param int   $id_b    Member B.
	 * @param array $reasons Match reasons.
	 * @return void
	 */
	public static function notify_new_hit( $hit_id, $id_a, $id_b, $reasons ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		$review = self::review_url( $hit_id );
		$labels = array();
		foreach ( $reasons as $reason ) {
			if ( ! empty( $reason['label'] ) ) {
				$labels[] = $reason['label'];
			}
		}
		$reason_list = implode( ', ', array_unique( $labels ) );

		foreach ( Remember_Notifications::admin_broadcast_emails( 'duplicate_hit_admin' ) as $email ) {
			Remember_Notifications::send(
				'duplicate_hit_admin',
				array(
					'member_name' => '',
					'date'        => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					'review_url'  => $review,
					'match_fields'=> $reason_list,
					'member_a_id' => (string) $id_a,
					'member_b_id' => (string) $id_b,
				),
				$email
			);
		}

		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'notified_at' => current_time( 'mysql' ) ),
			array( 'hit_id' => absint( $hit_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a pair as not duplicates so they will not re-flag.
	 *
	 * @param int $hit_id Hit ID.
	 * @return true|\WP_Error
	 */
	public static function dismiss( $hit_id ) {
		$hit = self::get_hit( $hit_id );
		if ( ! $hit || 'pending' !== $hit->status ) {
			return new WP_Error( 'invalid_hit', __( 'That duplicate review is not pending.', 'remember' ) );
		}
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'      => 'dismissed',
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => current_time( 'mysql' ),
			),
			array( 'hit_id' => (int) $hit->hit_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Snapshot used by the side-by-side review.
	 *
	 * @param int $member_id Member ID.
	 * @return array<string,mixed>
	 */
	public static function snapshot( $member_id ) {
		global $wpdb;
		$member_id = absint( $member_id );
		$user      = get_userdata( $member_id );
		$profile   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d", $member_id ) );
		$member    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_members WHERE member_id = %d", $member_id ) );
		$social    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT msm.*, smp.platform_name FROM {$wpdb->prefix}remember_member_social_media msm
				LEFT JOIN {$wpdb->prefix}remember_social_media_platforms smp ON smp.platform_id = msm.platform_id
				WHERE msm.member_id = %d ORDER BY smp.sort_order ASC, smp.platform_name ASC",
				$member_id
			)
		);
		return array(
			'member_id'           => $member_id,
			'user'                => $user,
			'profile'             => $profile,
			'member'              => $member,
			'social'              => is_array( $social ) ? $social : array(),
			'roles'               => self::member_role_rows( $member_id ),
			'password_updated_at' => self::password_updated_at( $member_id ),
			'profile_updated_at'  => $profile && ! empty( $profile->updated_at ) ? (string) $profile->updated_at : '',
		);
	}

	/**
	 * reMember roles on a member.
	 *
	 * @param int $member_id Member ID.
	 * @return object[]
	 */
	public static function member_role_rows( $member_id ) {
		global $wpdb;
		$member_id = absint( $member_id );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.role_id, r.role_name, r.role_type, r.is_event_role
				FROM {$wpdb->prefix}remember_member_roles mr
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
				WHERE mr.member_id = %d
				ORDER BY r.is_event_role ASC, r.role_name ASC",
				$member_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Unique roles from both snapshots, with which side has each role.
	 *
	 * @param array $snap_a Snapshot A.
	 * @param array $snap_b Snapshot B.
	 * @return array<int,array<string,mixed>>
	 */
	public static function combined_role_rows( $snap_a, $snap_b ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-capabilities.php';
		$by_id = array();
		foreach ( array( 'a' => $snap_a, 'b' => $snap_b ) as $side => $snap ) {
			$roles = isset( $snap['roles'] ) && is_array( $snap['roles'] ) ? $snap['roles'] : array();
			foreach ( $roles as $row ) {
				$rid = (int) $row->role_id;
				if ( ! isset( $by_id[ $rid ] ) ) {
					$by_id[ $rid ] = array(
						'role_id'       => $rid,
						'role_name'     => (string) $row->role_name,
						'is_event_role' => ! empty( $row->is_event_role ),
						'on_a'          => false,
						'on_b'          => false,
						'can_assign'    => Remember_Capabilities::current_user_can_assign_role( $rid ),
					);
				}
				$by_id[ $rid ][ 'on_' . $side ] = true;
			}
		}
		return array_values( $by_id );
	}

	/**
	 * Merge after admin field selection. Password always comes from the later password stamp.
	 *
	 * @param int   $hit_id         Hit ID.
	 * @param int   $survivor_id    Remaining member.
	 * @param array $choices        field_key => 'a'|'b'.
	 * @param array $keep_role_ids  Role IDs to leave on the survivor.
	 * @return true|\WP_Error
	 */
	public static function merge( $hit_id, $survivor_id, $choices, $keep_role_ids = array() ) {
		$hit = self::get_hit( $hit_id );
		if ( ! $hit || 'pending' !== $hit->status ) {
			return new WP_Error( 'invalid_hit', __( 'That duplicate review is not pending.', 'remember' ) );
		}
		$survivor_id = absint( $survivor_id );
		if ( $survivor_id !== (int) $hit->member_a_id && $survivor_id !== (int) $hit->member_b_id ) {
			return new WP_Error( 'invalid_survivor', __( 'Pick which profile remains.', 'remember' ) );
		}
		$locked_id = $survivor_id === (int) $hit->member_a_id ? (int) $hit->member_b_id : (int) $hit->member_a_id;
		$snap_a    = self::snapshot( (int) $hit->member_a_id );
		$snap_b    = self::snapshot( (int) $hit->member_b_id );
		$by_side   = array(
			'a' => $snap_a,
			'b' => $snap_b,
		);

		if ( ! current_user_can( 'remember_access_emergency_contact' ) ) {
			foreach ( self::emergency_fields() as $hidden_field ) {
				unset( $choices[ $hidden_field ] );
			}
		}

		$keep_role_ids = array_values( array_unique( array_map( 'absint', (array) $keep_role_ids ) ) );
		$undo_payload  = array(
			'a'              => self::capture_member_state( (int) $hit->member_a_id ),
			'b'              => self::capture_member_state( (int) $hit->member_b_id ),
			'closed_hit_ids' => array(),
		);

		$profile_map   = self::profile_columns();
		$survivor_side = $survivor_id === (int) $hit->member_a_id ? 'a' : 'b';
		$profile_data  = array();
		$surv_profile  = $by_side[ $survivor_side ]['profile'];
		if ( $surv_profile ) {
			foreach ( $profile_map as $key ) {
				$profile_data[ $key ] = isset( $surv_profile->$key ) ? $surv_profile->$key : '';
			}
		}
		foreach ( $profile_map as $field ) {
			if ( empty( $choices[ $field ] ) || ! isset( $by_side[ $choices[ $field ] ] ) ) {
				continue;
			}
			$from = $by_side[ $choices[ $field ] ]['profile'];
			if ( $from && isset( $from->$field ) ) {
				$profile_data[ $field ] = $from->$field;
			}
		}

		global $wpdb;
		$lock_notify_email = '';
		$lock_user_start   = get_userdata( $locked_id );
		if ( $lock_user_start && is_email( $lock_user_start->user_email ) ) {
			$lock_notify_email = $lock_user_start->user_email;
		}

		if ( ! empty( $profile_data ) ) {
			if ( array_key_exists( 'member_number', $profile_data ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}remember_member_profiles SET member_number = NULL WHERE member_id = %d",
						$locked_id
					)
				);
			}
			$profile_data['updated_at'] = current_time( 'mysql' );
			$profile_data['updated_by'] = get_current_user_id();
			$wpdb->update(
				$wpdb->prefix . 'remember_member_profiles',
				$profile_data,
				array( 'member_id' => $survivor_id )
			);
			if ( ! empty( $profile_data['timezone'] ) ) {
				update_user_meta( $survivor_id, 'timezone_string', $profile_data['timezone'] );
			}
		}

		if ( ! empty( $choices['display_name'] ) && isset( $by_side[ $choices['display_name'] ]['user'] ) ) {
			$from_user = $by_side[ $choices['display_name'] ]['user'];
			if ( $from_user ) {
				wp_update_user(
					array(
						'ID'           => $survivor_id,
						'display_name' => $from_user->display_name,
					)
				);
			}
		}
		if ( ! empty( $choices['user_email'] ) && isset( $by_side[ $choices['user_email'] ]['user'] ) ) {
			$from_user = $by_side[ $choices['user_email'] ]['user'];
			if ( $from_user && is_email( $from_user->user_email ) ) {
				$desired = $from_user->user_email;
				if ( (int) $from_user->ID !== $survivor_id ) {
					wp_update_user(
						array(
							'ID'         => $locked_id,
							'user_email' => sprintf( 'merged-%d-%s@example.com', $locked_id, wp_generate_password( 6, false ) ),
						)
					);
				}
				wp_update_user(
					array(
						'ID'         => $survivor_id,
						'user_email' => $desired,
					)
				);
			}
		}

		$member_patch = array( 'updated_at' => current_time( 'mysql' ) );
		if ( ! empty( $choices['photo_url'] ) && isset( $by_side[ $choices['photo_url'] ]['member'] ) ) {
			$from_member = $by_side[ $choices['photo_url'] ]['member'];
			if ( $from_member ) {
				$member_patch['photo_url'] = $from_member->photo_url;
			}
		}
		if ( ! empty( $choices['status'] ) && isset( $by_side[ $choices['status'] ]['member'] ) ) {
			$from_member = $by_side[ $choices['status'] ]['member'];
			if ( $from_member && ! empty( $from_member->status ) && 'merged' !== $from_member->status ) {
				$member_patch['status'] = $from_member->status;
			}
		}
		$wpdb->update(
			$wpdb->prefix . 'remember_members',
			$member_patch,
			array( 'member_id' => $survivor_id )
		);

		$pass_a = strtotime( self::password_updated_at( (int) $hit->member_a_id ) );
		$pass_b = strtotime( self::password_updated_at( (int) $hit->member_b_id ) );
		$pass_from = $pass_b > $pass_a ? (int) $hit->member_b_id : (int) $hit->member_a_id;
		if ( $pass_from !== $survivor_id ) {
			$src = get_userdata( $pass_from );
			if ( $src && ! empty( $src->user_pass ) ) {
				$wpdb->update(
					$wpdb->users,
					array( 'user_pass' => $src->user_pass ),
					array( 'ID' => $survivor_id ),
					array( '%s' ),
					array( '%d' )
				);
				update_user_meta( $survivor_id, self::PASSWORD_META, self::password_updated_at( $pass_from ) );
			}
		}

		self::reassign_rows( $locked_id, $survivor_id );
		self::apply_surviving_roles( $survivor_id, $locked_id, $keep_role_ids );

		$wpdb->update(
			$wpdb->prefix . 'remember_members',
			array(
				'status'     => 'merged',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'member_id' => $locked_id )
		);
		update_user_meta( $locked_id, self::MERGED_META, $survivor_id );
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			$sessions = WP_Session_Tokens::get_instance( $locked_id );
			$sessions->destroy_all();
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}remember_member_profiles SET member_number = NULL WHERE member_id = %d",
				$locked_id
			)
		);

		$closed = self::close_stale_hits( $locked_id, (int) $hit->hit_id );
		$undo_payload['closed_hit_ids'] = $closed;

		$wpdb->update(
			self::table_name(),
			array(
				'status'        => 'merged',
				'survivor_id'   => $survivor_id,
				'locked_id'     => $locked_id,
				'reviewed_by'   => get_current_user_id(),
				'reviewed_at'   => current_time( 'mysql' ),
				'undo_snapshot' => wp_json_encode( $undo_payload ),
			),
			array( 'hit_id' => (int) $hit->hit_id ),
			array( '%s', '%d', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		self::notify_merged( $survivor_id, $locked_id, $lock_notify_email );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::info(
			'Profiles merged',
			array(
				'hit_id'       => (int) $hit->hit_id,
				'survivor_id'  => $survivor_id,
				'locked_id'    => $locked_id,
			)
		);
		return true;
	}

	/**
	 * Close other pending reviews that include the locked profile.
	 *
	 * @param int $locked_id     Locked member.
	 * @param int $except_hit_id Hit that was just merged.
	 * @return int[] Closed hit IDs.
	 */
	private static function close_stale_hits( $locked_id, $except_hit_id ) {
		global $wpdb;
		$table = self::table_name();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT hit_id FROM {$table} WHERE status = %s AND hit_id != %d AND (member_a_id = %d OR member_b_id = %d)",
				'pending',
				absint( $except_hit_id ),
				absint( $locked_id ),
				absint( $locked_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $ids ) ) {
			return array();
		}
		$in = implode( ',', array_map( 'absint', $ids ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, reviewed_by = %d, reviewed_at = %s WHERE hit_id IN ({$in})",
				'closed',
				get_current_user_id(),
				current_time( 'mysql' )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'absint', $ids );
	}

	/**
	 * Set the survivor's roles from the merge picker, without granting roles the editor cannot assign.
	 *
	 * @param int   $survivor_id    Remaining member.
	 * @param int   $locked_id      Locked member.
	 * @param array $requested_ids  Role IDs checked on the form.
	 * @return void
	 */
	private static function apply_surviving_roles( $survivor_id, $locked_id, $requested_ids ) {
		global $wpdb;
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-capabilities.php';

		$survivor_id   = absint( $survivor_id );
		$locked_id     = absint( $locked_id );
		$requested_ids = array_values( array_unique( array_map( 'absint', (array) $requested_ids ) ) );

		$surv_roles = $wpdb->get_col( $wpdb->prepare( "SELECT role_id FROM {$wpdb->prefix}remember_member_roles WHERE member_id = %d", $survivor_id ) );
		$lock_roles = $wpdb->get_col( $wpdb->prepare( "SELECT role_id FROM {$wpdb->prefix}remember_member_roles WHERE member_id = %d", $locked_id ) );
		$surv_roles = array_map( 'absint', is_array( $surv_roles ) ? $surv_roles : array() );
		$lock_roles = array_map( 'absint', is_array( $lock_roles ) ? $lock_roles : array() );
		$all_ids    = array_values( array_unique( array_merge( $surv_roles, $lock_roles ) ) );

		$keep = array();
		foreach ( $all_ids as $role_id ) {
			$can       = Remember_Capabilities::current_user_can_assign_role( $role_id );
			$on_surv   = in_array( $role_id, $surv_roles, true );
			$requested = in_array( $role_id, $requested_ids, true );
			if ( $can ) {
				if ( $requested ) {
					$keep[] = $role_id;
				}
			} elseif ( $on_surv ) {
				$keep[] = $role_id;
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_roles', array( 'member_id' => $survivor_id ) );
		$wpdb->delete( $wpdb->prefix . 'remember_member_roles', array( 'member_id' => $locked_id ) );
		foreach ( $keep as $role_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'remember_member_roles',
				array(
					'member_id'  => $survivor_id,
					'role_id'    => $role_id,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s' )
			);
		}
		Remember_Capabilities::sync_user_capabilities_from_roles( $survivor_id );
		Remember_Capabilities::sync_user_capabilities_from_roles( $locked_id );
	}

	/**
	 * Serializable member state for merge undo.
	 *
	 * @param int $member_id Member ID.
	 * @return array<string,mixed>
	 */
	private static function capture_member_state( $member_id ) {
		global $wpdb;
		$member_id = absint( $member_id );
		$user      = get_userdata( $member_id );
		$member    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_members WHERE member_id = %d", $member_id ), ARRAY_A );
		$profile   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d", $member_id ), ARRAY_A );
		$social    = $wpdb->get_results( $wpdb->prepare( "SELECT platform_id, handle FROM {$wpdb->prefix}remember_member_social_media WHERE member_id = %d", $member_id ), ARRAY_A );
		$pq        = $wpdb->get_results( $wpdb->prepare( "SELECT question_id, value_text FROM {$wpdb->prefix}remember_profile_question_responses WHERE member_id = %d", $member_id ), ARRAY_A );

		return array(
			'member_id'     => $member_id,
			'user_pass'     => $user ? (string) $user->user_pass : '',
			'user_email'    => $user ? (string) $user->user_email : '',
			'display_name'  => $user ? (string) $user->display_name : '',
			'password_meta' => (string) get_user_meta( $member_id, self::PASSWORD_META, true ),
			'timezone'      => (string) get_user_meta( $member_id, 'timezone_string', true ),
			'member'        => is_array( $member ) ? $member : array(),
			'profile'       => is_array( $profile ) ? $profile : array(),
			'role_ids'      => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT role_id FROM {$wpdb->prefix}remember_member_roles WHERE member_id = %d", $member_id ) ) ),
			'social'        => is_array( $social ) ? $social : array(),
			'dietary'       => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT restriction_id FROM {$wpdb->prefix}remember_member_dietary_restrictions WHERE member_id = %d", $member_id ) ) ),
			'allergies'     => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT allergy_id FROM {$wpdb->prefix}remember_member_allergies WHERE member_id = %d", $member_id ) ) ),
			'medical'       => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT accommodation_id FROM {$wpdb->prefix}remember_member_medical_accommodations WHERE member_id = %d", $member_id ) ) ),
			'pq'            => is_array( $pq ) ? $pq : array(),
			'application_ids' => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT application_id FROM {$wpdb->prefix}remember_event_applications WHERE member_id = %d", $member_id ) ) ),
			'payment_ids'   => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT payment_id FROM {$wpdb->prefix}remember_payments WHERE member_id = %d", $member_id ) ) ),
			'vetting_ids'   => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT vetting_id FROM {$wpdb->prefix}remember_vetting WHERE member_id = %d", $member_id ) ) ),
			'note_ids'      => array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT note_id FROM {$wpdb->prefix}remember_profile_notes WHERE member_id = %d", $member_id ) ) ),
		);
	}

	/**
	 * Restore a member from capture_member_state().
	 *
	 * @param array $state Captured state.
	 * @return void
	 */
	private static function restore_member_state( $state ) {
		global $wpdb;
		$member_id = isset( $state['member_id'] ) ? absint( $state['member_id'] ) : 0;
		if ( $member_id < 1 ) {
			return;
		}

		if ( ! empty( $state['user_pass'] ) ) {
			$wpdb->update( $wpdb->users, array( 'user_pass' => $state['user_pass'] ), array( 'ID' => $member_id ), array( '%s' ), array( '%d' ) );
		}
		$user_update = array( 'ID' => $member_id );
		if ( ! empty( $state['display_name'] ) ) {
			$user_update['display_name'] = $state['display_name'];
		}
		if ( ! empty( $state['user_email'] ) && is_email( $state['user_email'] ) ) {
			$user_update['user_email'] = $state['user_email'];
		}
		if ( count( $user_update ) > 1 ) {
			wp_update_user( $user_update );
		}
		if ( array_key_exists( 'password_meta', $state ) ) {
			if ( '' === (string) $state['password_meta'] ) {
				delete_user_meta( $member_id, self::PASSWORD_META );
			} else {
				update_user_meta( $member_id, self::PASSWORD_META, $state['password_meta'] );
			}
		}
		if ( ! empty( $state['timezone'] ) ) {
			update_user_meta( $member_id, 'timezone_string', $state['timezone'] );
		}
		delete_user_meta( $member_id, self::MERGED_META );

		if ( ! empty( $state['member'] ) && is_array( $state['member'] ) ) {
			$row = $state['member'];
			unset( $row['member_id'] );
			if ( ! empty( $row ) ) {
				$wpdb->update( $wpdb->prefix . 'remember_members', $row, array( 'member_id' => $member_id ) );
			}
		}
		if ( ! empty( $state['profile'] ) && is_array( $state['profile'] ) ) {
			$row = $state['profile'];
			unset( $row['profile_id'], $row['member_id'] );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}remember_member_profiles SET member_number = NULL WHERE member_id = %d", $member_id ) );
			if ( ! empty( $row ) ) {
				$wpdb->update( $wpdb->prefix . 'remember_member_profiles', $row, array( 'member_id' => $member_id ) );
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_roles', array( 'member_id' => $member_id ) );
		foreach ( isset( $state['role_ids'] ) ? (array) $state['role_ids'] : array() as $role_id ) {
			$role_id = absint( $role_id );
			if ( $role_id > 0 ) {
				$wpdb->insert( $wpdb->prefix . 'remember_member_roles', array( 'member_id' => $member_id, 'role_id' => $role_id, 'created_at' => current_time( 'mysql' ) ), array( '%d', '%d', '%s' ) );
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_social_media', array( 'member_id' => $member_id ) );
		foreach ( isset( $state['social'] ) ? (array) $state['social'] : array() as $social ) {
			if ( empty( $social['platform_id'] ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->prefix . 'remember_member_social_media',
				array(
					'member_id'   => $member_id,
					'platform_id' => absint( $social['platform_id'] ),
					'handle'      => isset( $social['handle'] ) ? $social['handle'] : '',
					'created_at'  => current_time( 'mysql' ),
				)
			);
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_dietary_restrictions', array( 'member_id' => $member_id ) );
		foreach ( isset( $state['dietary'] ) ? (array) $state['dietary'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->insert( $wpdb->prefix . 'remember_member_dietary_restrictions', array( 'member_id' => $member_id, 'restriction_id' => $id ), array( '%d', '%d' ) );
			}
		}
		$wpdb->delete( $wpdb->prefix . 'remember_member_allergies', array( 'member_id' => $member_id ) );
		foreach ( isset( $state['allergies'] ) ? (array) $state['allergies'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->insert( $wpdb->prefix . 'remember_member_allergies', array( 'member_id' => $member_id, 'allergy_id' => $id ), array( '%d', '%d' ) );
			}
		}
		$wpdb->delete( $wpdb->prefix . 'remember_member_medical_accommodations', array( 'member_id' => $member_id ) );
		foreach ( isset( $state['medical'] ) ? (array) $state['medical'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->insert( $wpdb->prefix . 'remember_member_medical_accommodations', array( 'member_id' => $member_id, 'accommodation_id' => $id ), array( '%d', '%d' ) );
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_profile_question_responses', array( 'member_id' => $member_id ) );
		foreach ( isset( $state['pq'] ) ? (array) $state['pq'] : array() as $pq ) {
			if ( empty( $pq['question_id'] ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->prefix . 'remember_profile_question_responses',
				array(
					'member_id'   => $member_id,
					'question_id' => absint( $pq['question_id'] ),
					'value_text'  => isset( $pq['value_text'] ) ? $pq['value_text'] : '',
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				)
			);
		}

		foreach ( isset( $state['application_ids'] ) ? (array) $state['application_ids'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->update( $wpdb->prefix . 'remember_event_applications', array( 'member_id' => $member_id ), array( 'application_id' => $id ) );
			}
		}
		foreach ( isset( $state['payment_ids'] ) ? (array) $state['payment_ids'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->update( $wpdb->prefix . 'remember_payments', array( 'member_id' => $member_id ), array( 'payment_id' => $id ) );
			}
		}
		foreach ( isset( $state['vetting_ids'] ) ? (array) $state['vetting_ids'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->update( $wpdb->prefix . 'remember_vetting', array( 'member_id' => $member_id ), array( 'vetting_id' => $id ) );
			}
		}
		foreach ( isset( $state['note_ids'] ) ? (array) $state['note_ids'] : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$wpdb->update( $wpdb->prefix . 'remember_profile_notes', array( 'member_id' => $member_id ), array( 'note_id' => $id ) );
			}
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-capabilities.php';
		Remember_Capabilities::sync_user_capabilities_from_roles( $member_id );
	}

	/**
	 * Restore both profiles from the snapshot stored at merge time.
	 *
	 * @param int $hit_id Hit ID.
	 * @return true|\WP_Error
	 */
	public static function undo( $hit_id ) {
		$hit = self::get_hit( $hit_id );
		if ( ! $hit || 'merged' !== $hit->status ) {
			return new WP_Error( 'invalid_hit', __( 'That merge cannot be undone.', 'remember' ) );
		}
		$payload = array();
		if ( ! empty( $hit->undo_snapshot ) ) {
			$decoded = json_decode( $hit->undo_snapshot, true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		if ( empty( $payload['a'] ) || empty( $payload['b'] ) ) {
			return new WP_Error( 'no_snapshot', __( 'This merge was done before undo snapshots existed, so it cannot be reversed automatically.', 'remember' ) );
		}

		$a_id = absint( $payload['a']['member_id'] );
		$b_id = absint( $payload['b']['member_id'] );
		if ( $a_id < 1 || $b_id < 1 ) {
			return new WP_Error( 'no_snapshot', __( 'The stored merge snapshot is incomplete.', 'remember' ) );
		}

		global $wpdb;
		$table = self::table_name();
		$tmp_a = sprintf( 'undo-%d-a-%s@example.com', $a_id, wp_generate_password( 6, false ) );
		$tmp_b = sprintf( 'undo-%d-b-%s@example.com', $b_id, wp_generate_password( 6, false ) );
		wp_update_user( array( 'ID' => $a_id, 'user_email' => $tmp_a ) );
		wp_update_user( array( 'ID' => $b_id, 'user_email' => $tmp_b ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}remember_member_profiles SET member_number = NULL WHERE member_id IN (%d, %d)", $a_id, $b_id ) );

		self::restore_member_state( $payload['a'] );
		self::restore_member_state( $payload['b'] );

		if ( ! empty( $payload['closed_hit_ids'] ) && is_array( $payload['closed_hit_ids'] ) ) {
			$in = implode( ',', array_map( 'absint', $payload['closed_hit_ids'] ) );
			if ( '' !== $in ) {
				$wpdb->query( "UPDATE {$table} SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL WHERE hit_id IN ({$in}) AND status = 'closed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, survivor_id = NULL, locked_id = NULL, reviewed_by = %d, reviewed_at = %s, undo_snapshot = NULL WHERE hit_id = %d",
				'pending',
				get_current_user_id(),
				current_time( 'mysql' ),
				(int) $hit->hit_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::info(
			'Profile merge undone',
			array(
				'hit_id' => (int) $hit->hit_id,
				'a'      => $a_id,
				'b'      => $b_id,
			)
		);
		return true;
	}

	/**
	 * Move rows from the locked profile onto the survivor, skipping unique conflicts.
	 *
	 * @param int $from_id Locked member.
	 * @param int $to_id   Survivor.
	 * @return void
	 */
	private static function reassign_rows( $from_id, $to_id ) {
		global $wpdb;
		$from_id = absint( $from_id );
		$to_id   = absint( $to_id );

		$pair_tables = array(
			$wpdb->prefix . 'remember_member_dietary_restrictions'     => array( 'member_id', 'restriction_id' ),
			$wpdb->prefix . 'remember_member_allergies'                => array( 'member_id', 'allergy_id' ),
			$wpdb->prefix . 'remember_member_medical_accommodations'   => array( 'member_id', 'accommodation_id' ),
		);
		foreach ( $pair_tables as $table => $cols ) {
			$member_col = $cols[0];
			$key_col    = $cols[1];
			$keep_ids   = $wpdb->get_col( $wpdb->prepare( "SELECT {$key_col} FROM {$table} WHERE {$member_col} = %d", $to_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! empty( $keep_ids ) ) {
				$in = implode( ',', array_map( 'absint', $keep_ids ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$member_col} = %d AND {$key_col} IN ({$in})", $from_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->update( $table, array( $member_col => $to_id ), array( $member_col => $from_id ) );
		}

		$social_from = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_member_social_media WHERE member_id = %d", $from_id ) );
		if ( is_array( $social_from ) ) {
			foreach ( $social_from as $row ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT social_media_id FROM {$wpdb->prefix}remember_member_social_media WHERE member_id = %d AND platform_id = %d",
						$to_id,
						$row->platform_id
					)
				);
				if ( $exists ) {
					$wpdb->delete( $wpdb->prefix . 'remember_member_social_media', array( 'social_media_id' => (int) $row->social_media_id ) );
				} else {
					$wpdb->update(
						$wpdb->prefix . 'remember_member_social_media',
						array( 'member_id' => $to_id ),
						array( 'social_media_id' => (int) $row->social_media_id )
					);
				}
			}
		}

		$pq_from = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_profile_question_responses WHERE member_id = %d", $from_id ) );
		if ( is_array( $pq_from ) ) {
			foreach ( $pq_from as $row ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT response_id FROM {$wpdb->prefix}remember_profile_question_responses WHERE member_id = %d AND question_id = %d",
						$to_id,
						$row->question_id
					)
				);
				if ( $exists ) {
					$wpdb->delete( $wpdb->prefix . 'remember_profile_question_responses', array( 'response_id' => (int) $row->response_id ) );
				} else {
					$wpdb->update(
						$wpdb->prefix . 'remember_profile_question_responses',
						array( 'member_id' => $to_id ),
						array( 'response_id' => (int) $row->response_id )
					);
				}
			}
		}

		$apps = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}remember_event_applications WHERE member_id = %d", $from_id ) );
		if ( is_array( $apps ) ) {
			foreach ( $apps as $app ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT application_id FROM {$wpdb->prefix}remember_event_applications WHERE member_id = %d AND event_id = %d AND event_role_id = %d AND (superseded_at IS NULL OR superseded_at = '0000-00-00 00:00:00') LIMIT 1",
						$to_id,
						$app->event_id,
						$app->event_role_id
					)
				);
				if ( $exists ) {
					continue;
				}
				$wpdb->update(
					$wpdb->prefix . 'remember_event_applications',
					array( 'member_id' => $to_id ),
					array( 'application_id' => (int) $app->application_id )
				);
			}
		}

		$wpdb->update( $wpdb->prefix . 'remember_payments', array( 'member_id' => $to_id ), array( 'member_id' => $from_id ) );
		$wpdb->update( $wpdb->prefix . 'remember_vetting', array( 'member_id' => $to_id ), array( 'member_id' => $from_id ) );

		require_once plugin_dir_path( __FILE__ ) . '../models/class-profile-note.php';
		( new Remember_Profile_Note() )->reassign_member( $from_id, $to_id );
	}

	/**
	 * Post-merge emails. Locked profile is told it is invalid; survivor is told to keep using this login.
	 *
	 * @param int    $survivor_id        Remaining member.
	 * @param int    $locked_id          Locked member.
	 * @param string $lock_notify_email  Original locked-profile email (before rewrite).
	 * @return void
	 */
	private static function notify_merged( $survivor_id, $locked_id, $lock_notify_email = '' ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		$surv = get_userdata( $survivor_id );
		$lock = get_userdata( $locked_id );
		if ( $surv && is_email( $surv->user_email ) ) {
			Remember_Notifications::send(
				'duplicate_merged_survivor',
				array(
					'member_name' => $surv->display_name,
					'date'        => date_i18n( get_option( 'date_format' ) ),
				),
				$surv->user_email
			);
		}
		$lock_email = is_email( $lock_notify_email ) ? $lock_notify_email : ( $lock && is_email( $lock->user_email ) ? $lock->user_email : '' );
		if ( $lock && is_email( $lock_email ) ) {
			Remember_Notifications::send(
				'duplicate_merged_locked',
				array(
					'member_name' => $lock->display_name,
					'date'        => date_i18n( get_option( 'date_format' ) ),
				),
				$lock_email
			);
		}
	}
}
