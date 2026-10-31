<?php
/**
 * What happens when an accepted event spot opens.
 *
 * Off leaves the waitlist alone. Notify emails event administrators. Auto
 * moves the oldest waitlisted application for that role to pending and emails
 * the member. Auto does not accept the application or create an invoice.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Waitlist response when a spot is freed.
 */
class Remember_Waitlist {

	/**
	 * Last outcome for the admin screen that freed the spot.
	 *
	 * @var string
	 */
	private static $last_notice = '';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'remember_application_spot_freed', array( __CLASS__, 'on_spot_freed' ), 10, 3 );
	}

	/**
	 * @return string promoted, notified, or empty.
	 */
	public static function last_notice() {
		return self::$last_notice;
	}

	/**
	 * @param string $mode Posted or stored mode.
	 * @return string off|notify|auto
	 */
	public static function sanitize_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( 'notify', 'auto' ), true ) ) {
			return 'off';
		}
		return $mode;
	}

	/**
	 * @param int $application_id Application that gave up an accepted spot.
	 * @param int $event_id       Event ID.
	 * @param int $event_role_id  Event role ID.
	 * @return void
	 */
	public static function on_spot_freed( $application_id, $event_id, $event_role_id ) {
		unset( $application_id );
		self::$last_notice = '';
		$event_id      = absint( $event_id );
		$event_role_id = absint( $event_role_id );
		if ( $event_id < 1 || $event_role_id < 1 ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		$event = ( new Remember_Event() )->get( $event_id );
		if ( ! $event ) {
			return;
		}
		$mode = self::sanitize_mode( isset( $event->waitlist_mode ) ? $event->waitlist_mode : 'off' );
		if ( 'off' === $mode ) {
			return;
		}

		$context = self::context( $event, $event_role_id );
		if ( 'auto' === $mode && self::can_promote( $event, $event_role_id ) ) {
			$promoted = self::promote_next( $event_role_id, $context );
			if ( $promoted ) {
				self::$last_notice = 'promoted';
				return;
			}
		}

		self::email_staff( $event_id, $context );
		self::$last_notice = 'notified';
	}

	/**
	 * @param object $event         Event row.
	 * @param int    $event_role_id Event role ID.
	 * @return bool
	 */
	private static function can_promote( $event, $event_role_id ) {
		if ( ! is_object( $event ) || 'open' !== (string) $event->status ) {
			return false;
		}
		if ( '' !== Remember_Event::registration_block_reason( $event ) ) {
			return false;
		}
		global $wpdb;
		$role = $wpdb->get_row( $wpdb->prepare( 'SELECT max_participants FROM ' . $wpdb->prefix . 'remember_event_roles WHERE event_role_id = %d', $event_role_id ) );
		if ( ! $role ) {
			return false;
		}
		if ( null === $role->max_participants || '' === $role->max_participants ) {
			return true;
		}
		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		$accepted = ( new Remember_Application() )->get_accepted_count_for_event_role( $event_role_id );
		return $accepted < (int) $role->max_participants;
	}

	/**
	 * @param int   $event_role_id Event role ID.
	 * @param array $context       Email placeholders.
	 * @return bool
	 */
	private static function promote_next( $event_role_id, $context ) {
		global $wpdb;
		$next_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT application_id FROM ' . $wpdb->prefix . 'remember_event_applications WHERE event_role_id = %d AND status = %s AND superseded_at IS NULL ORDER BY COALESCE(waitlisted_at, applied_at) ASC, application_id ASC LIMIT 1',
				$event_role_id,
				'waitlisted'
			)
		);
		if ( $next_id < 1 ) {
			return false;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		$result = ( new Remember_Application() )->update_status( $next_id, 'pending' );
		if ( false === $result ) {
			Remember_Logger::warning( 'Waitlist auto-promote failed', array( 'application_id' => $next_id ) );
			return false;
		}

		$application = ( new Remember_Application() )->get( $next_id );
		$user        = $application ? get_user_by( 'id', (int) $application->member_id ) : false;
		Remember_Logger::info(
			'Waitlist application moved to pending',
			array(
				'application_id' => $next_id,
				'event_id'       => $application ? (int) $application->event_id : 0,
				'member_id'      => $application ? (int) $application->member_id : 0,
			)
		);
		if ( $user && is_email( $user->user_email ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
			$context['member_name']    = $user->display_name;
			$context['application_id'] = (string) $next_id;
			Remember_Notifications::send( 'waitlist_promoted', $context, $user->user_email );
		}
		return true;
	}

	/**
	 * @param int   $event_id Event ID.
	 * @param array $context  Email placeholders.
	 * @return void
	 */
	private static function email_staff( $event_id, $context ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		foreach ( Remember_Notifications::emails_for_staff_type( 'waitlist_spot_opened', array( 'event_id' => $event_id ) ) as $email ) {
			Remember_Notifications::send( 'waitlist_spot_opened', $context, $email );
		}
	}

	/**
	 * @param object $event         Event row.
	 * @param int    $event_role_id Event role ID.
	 * @return array
	 */
	private static function context( $event, $event_role_id ) {
		global $wpdb;
		$role_name = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT r.role_name FROM ' . $wpdb->prefix . 'remember_event_roles er INNER JOIN ' . $wpdb->prefix . 'remember_roles r ON r.role_id = er.role_id WHERE er.event_role_id = %d',
				$event_role_id
			)
		);
		$waiting = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'remember_event_applications WHERE event_role_id = %d AND status = %s AND superseded_at IS NULL',
				$event_role_id,
				'waitlisted'
			)
		);
		return array(
			'event_name'     => isset( $event->event_name ) ? (string) $event->event_name : '',
			'event_id'       => isset( $event->event_id ) ? (string) $event->event_id : '',
			'role_name'      => is_string( $role_name ) ? $role_name : '',
			'waitlist_count' => (string) $waiting,
			'date'           => date_i18n( get_option( 'date_format' ) ),
		);
	}

	/**
	 * Accepted participants whose event role is Event Administrator.
	 *
	 * @param int $event_id Event ID.
	 * @return string[]
	 */
	public static function accepted_event_administrator_emails( $event_id ) {
		global $wpdb;
		$member_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT a.member_id FROM ' . $wpdb->prefix . 'remember_event_applications a INNER JOIN ' . $wpdb->prefix . 'remember_event_roles er ON a.event_role_id = er.event_role_id INNER JOIN ' . $wpdb->prefix . 'remember_roles r ON er.role_id = r.role_id WHERE a.event_id = %d AND a.status = %s AND r.role_name = %s',
				$event_id,
				'accepted',
				'Event Administrator'
			)
		);
		if ( ! is_array( $member_ids ) ) {
			return array();
		}
		$clean = array();
		foreach ( $member_ids as $member_id ) {
			$user = get_user_by( 'id', (int) $member_id );
			if ( $user && is_email( $user->user_email ) ) {
				$clean[] = $user->user_email;
			}
		}
		return $clean;
	}
}
