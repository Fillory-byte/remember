<?php
/**
 * Private event invitees, visibility, and invitation emails.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Invite-only private events.
 *
 * Uninvited members do not see the event. Invitees still complete the usual
 * application. Emails go out when registration is live (status Open, and the
 * optional registration window has opened).
 */
class Remember_Event_Invites {

	const CRON_HOOK = 'remember_send_event_invites';

	/**
	 * Register cron.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_due' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule hourly send for events whose registration window opens later.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
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
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'remember_event_invites';
	}

	/**
	 * Whether the invites table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		static $exists = null;
		if ( null === $exists ) {
			$table  = self::table_name();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $exists;
	}

	/**
	 * Whether the event is invite-only.
	 *
	 * @param object|null $event Event row.
	 * @return bool
	 */
	public static function is_private( $event ) {
		return is_object( $event ) && ! empty( $event->is_private );
	}

	/**
	 * Member IDs invited to an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	public static function member_ids_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 || ! self::table_exists() ) {
			return array();
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT member_id FROM ' . self::table_name() . ' WHERE event_id = %d ORDER BY invite_id ASC',
				$event_id
			)
		);
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_map( 'absint', $ids );
	}

	/**
	 * Invite counts keyed by event ID.
	 *
	 * @return array<int,int>
	 */
	public static function counts_by_event() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT event_id, COUNT(*) AS invite_count FROM ' . self::table_name() . ' GROUP BY event_id' );
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ absint( $row->event_id ) ] = absint( $row->invite_count );
			}
		}
		return $out;
	}

	/**
	 * Whether this member is on the invite list.
	 *
	 * @param int $event_id  Event ID.
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public static function member_is_invited( $event_id, $member_id ) {
		global $wpdb;
		$event_id  = absint( $event_id );
		$member_id = absint( $member_id );
		if ( $event_id < 1 || $member_id < 1 || ! self::table_exists() ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT invite_id FROM ' . self::table_name() . ' WHERE event_id = %d AND member_id = %d',
				$event_id,
				$member_id
			)
		);
		return absint( $found ) > 0;
	}

	/**
	 * Whether the member may apply to this event.
	 *
	 * Public events: anyone who already qualifies. Private: invitees only.
	 *
	 * @param object|null $event     Event row.
	 * @param int         $member_id Member ID.
	 * @return bool
	 */
	public static function member_can_apply( $event, $member_id ) {
		if ( ! is_object( $event ) ) {
			return false;
		}
		if ( ! self::is_private( $event ) ) {
			return true;
		}
		return self::member_is_invited( (int) $event->event_id, (int) $member_id );
	}

	/**
	 * Whether the member may see this event on the public list or detail page.
	 *
	 * Invitees see it. Anyone who already applied still sees it so they can
	 * follow their application. Everyone else does not.
	 *
	 * @param object|null $event     Event row.
	 * @param int         $member_id Member ID.
	 * @return bool
	 */
	public static function member_can_see( $event, $member_id ) {
		if ( ! is_object( $event ) ) {
			return false;
		}
		if ( ! self::is_private( $event ) ) {
			return true;
		}
		$member_id = absint( $member_id );
		$event_id  = absint( $event->event_id );
		if ( $member_id < 1 ) {
			return false;
		}
		if ( self::member_is_invited( $event_id, $member_id ) ) {
			return true;
		}
		return self::member_has_application( $event_id, $member_id );
	}

	/**
	 * Filter events the member may see.
	 *
	 * @param array $events    Event rows.
	 * @param int   $member_id Member ID.
	 * @return array
	 */
	public static function visible_events( $events, $member_id ) {
		$out = array();
		foreach ( (array) $events as $event ) {
			if ( self::member_can_see( $event, $member_id ) ) {
				$out[] = $event;
			}
		}
		return $out;
	}

	/**
	 * Members staff can pick as invitees (not merged).
	 *
	 * @return array<int,object> member_id, display_name, user_email, status.
	 */
	public static function invite_candidates() {
		global $wpdb;
		$members_table = $wpdb->prefix . 'remember_members';
		return $wpdb->get_results(
			"SELECT m.member_id, m.status, u.display_name, u.user_email
			FROM {$members_table} m
			INNER JOIN {$wpdb->users} u ON u.ID = m.member_id
			WHERE (m.status IS NULL OR m.status <> 'merged')
			ORDER BY u.display_name ASC, u.user_email ASC"
		);
	}

	/**
	 * Posted invitee IDs from the event form.
	 *
	 * @return int[]
	 */
	public static function member_ids_from_request() {
		$raw = isset( $_POST['event_invitees'] ) ? wp_unslash( $_POST['event_invitees'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies nonce.
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * Replace the invite list with the posted checkboxes.
	 *
	 * Existing emailed_at stamps stay when the member is still invited.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public static function sync_from_request( $event_id ) {
		self::replace_invitees( $event_id, self::member_ids_from_request(), get_current_user_id() );
	}

	/**
	 * Set the invitee list.
	 *
	 * @param int   $event_id   Event ID.
	 * @param int[] $member_ids Member IDs.
	 * @param int   $invited_by Staff user ID.
	 * @return void
	 */
	public static function replace_invitees( $event_id, $member_ids, $invited_by ) {
		global $wpdb;
		$event_id   = absint( $event_id );
		$invited_by = absint( $invited_by );
		if ( $event_id < 1 || ! self::table_exists() ) {
			return;
		}

		$wanted = array();
		foreach ( (array) $member_ids as $member_id ) {
			$member_id = absint( $member_id );
			if ( $member_id > 0 ) {
				$wanted[ $member_id ] = $member_id;
			}
		}

		$existing = self::member_ids_for_event( $event_id );
		foreach ( $existing as $member_id ) {
			if ( ! isset( $wanted[ $member_id ] ) ) {
				$wpdb->delete(
					self::table_name(),
					array(
						'event_id'  => $event_id,
						'member_id' => $member_id,
					),
					array( '%d', '%d' )
				);
			}
		}

		$now = current_time( 'mysql' );
		foreach ( $wanted as $member_id ) {
			if ( in_array( $member_id, $existing, true ) ) {
				continue;
			}
			$wpdb->insert(
				self::table_name(),
				array(
					'event_id'   => $event_id,
					'member_id'  => $member_id,
					'invited_by' => $invited_by > 0 ? $invited_by : 0,
					'invited_at' => $now,
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Remove invites when an event is deleted.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public static function delete_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 || ! self::table_exists() ) {
			return;
		}
		$wpdb->delete( self::table_name(), array( 'event_id' => $event_id ), array( '%d' ) );
	}

	/**
	 * Cron: send pending invites for every private event whose registration is live.
	 *
	 * @return int Emails sent.
	 */
	public static function send_due() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$events_table = $wpdb->prefix . 'remember_events';
		$event_ids    = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT e.event_id FROM ' . $events_table . ' e INNER JOIN ' . self::table_name() . ' i ON i.event_id = e.event_id WHERE e.is_private = %d AND e.status = %s AND i.invited_emailed_at IS NULL',
				1,
				'open'
			)
		);
		$sent = 0;
		if ( is_array( $event_ids ) ) {
			foreach ( $event_ids as $event_id ) {
				$sent += self::send_due_for_event( (int) $event_id );
			}
		}
		return $sent;
	}

	/**
	 * Send pending invite emails for one event if registration is live.
	 *
	 * @param int $event_id Event ID.
	 * @return int Emails sent.
	 */
	public static function send_due_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 || ! self::table_exists() ) {
			return 0;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		$event = ( new Remember_Event() )->get( $event_id );
		if ( ! self::registration_is_live( $event ) ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT invite_id, member_id FROM ' . self::table_name() . ' WHERE event_id = %d AND invited_emailed_at IS NULL',
				$event_id
			)
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$context_base = self::event_context( $event );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';

		$sent = 0;
		$now  = current_time( 'mysql' );
		foreach ( $rows as $row ) {
			$user = get_user_by( 'id', (int) $row->member_id );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				continue;
			}
			$context = $context_base;
			$context['member_name'] = $user->display_name;
			$result                 = Remember_Notifications::send( 'event_invite', $context, $user->user_email );
			if ( is_wp_error( $result ) ) {
				Remember_Logger::warning(
					'Private event invite email failed',
					array(
						'event_id'  => $event_id,
						'member_id' => (int) $row->member_id,
						'error'     => $result->get_error_message(),
					)
				);
				continue;
			}
			$wpdb->update(
				self::table_name(),
				array( 'invited_emailed_at' => $now ),
				array( 'invite_id' => (int) $row->invite_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( true === $result ) {
				++$sent;
			}
		}
		if ( $sent > 0 ) {
			Remember_Logger::info(
				'Private event invite emails sent',
				array(
					'event_id' => $event_id,
					'sent'     => $sent,
				)
			);
		}
		return $sent;
	}

	/**
	 * Status is Open and the optional registration window currently allows apply.
	 *
	 * @param object|null $event Event row.
	 * @return bool
	 */
	public static function registration_is_live( $event ) {
		if ( ! is_object( $event ) || empty( $event->is_private ) ) {
			return false;
		}
		if ( ! isset( $event->status ) || 'open' !== $event->status ) {
			return false;
		}
		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		return '' === Remember_Event::registration_block_reason( $event );
	}

	/**
	 * Placeholder map for an invite email.
	 *
	 * @param object $event Event row.
	 * @return array
	 */
	private static function event_context( $event ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-audit.php';
		$location = '';
		if ( ! empty( $event->location_id ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../models/class-location.php';
			$row = ( new Remember_Location() )->get( (int) $event->location_id );
			if ( $row && ! empty( $row->location_name ) ) {
				$location = (string) $row->location_name;
			}
		}
		$start = ! empty( $event->start_date ) ? date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) ) : '';
		$end   = ! empty( $event->end_date ) ? date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) ) : '';
		$dates = $start;
		if ( $end && $end !== $start ) {
			$dates = $start . ' – ' . $end;
		}
		return array(
			'event_name'     => isset( $event->event_name ) ? (string) $event->event_name : '',
			'event_dates'    => $dates,
			'event_location' => $location,
			'apply_url'      => Remember_Profile_Audit::get_apply_url( (int) $event->event_id ),
			'date'           => date_i18n( get_option( 'date_format' ) ),
		);
	}

	/**
	 * Whether this member already has an application for the event.
	 *
	 * @param int $event_id  Event ID.
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	private static function member_has_application( $event_id, $member_id ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT application_id FROM ' . $wpdb->prefix . 'remember_event_applications WHERE event_id = %d AND member_id = %d LIMIT 1',
				$event_id,
				$member_id
			)
		);
		return absint( $found ) > 0;
	}
}
