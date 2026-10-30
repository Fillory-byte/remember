<?php
/**
 * Optional door check-in for an event's existing admission ticket.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Signed ticket codes, check-in, and undo.
 */
class Remember_Checkin {

	/**
	 * Events that print a check-in code.
	 *
	 * @return array
	 */
	public static function enabled_events() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT event_id, event_name, start_date
			FROM {$wpdb->prefix}remember_events
			WHERE checkin_enabled = 1
			ORDER BY start_date DESC, event_id DESC"
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Accepted attendance and how many of those are checked in.
	 *
	 * @param int $event_id Event ID.
	 * @return array{checked:int,accepted:int}
	 */
	public static function counts( $event_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'remember_event_applications';
		$accepted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE event_id = %d AND status = 'accepted' AND superseded_at IS NULL",
				absint( $event_id )
			)
		);
		$checked = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE event_id = %d AND status = 'accepted' AND superseded_at IS NULL AND checked_in_at IS NOT NULL",
				absint( $event_id )
			)
		);
		return array(
			'checked'  => $checked,
			'accepted' => $accepted,
		);
	}

	/**
	 * Code printed on a ticket. It is not the application id by itself.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event ID.
	 * @return string
	 */
	public static function code( $application_id, $event_id ) {
		return absint( $application_id ) . '.' . self::signature( $application_id, $event_id );
	}

	/**
	 * Look up a scanned or typed code for one event.
	 *
	 * @param string $code     Ticket code.
	 * @param int    $event_id Event selected on the door screen.
	 * @return array{result:string,row:object|null}
	 */
	public static function inspect( $code, $event_id ) {
		$parsed = self::parse_code( $code );
		if ( ! $parsed ) {
			return array( 'result' => 'invalid', 'row' => null );
		}

		$row = self::application_row( $parsed['application_id'] );
		if ( ! $row || ! hash_equals( self::signature( $row->application_id, $row->event_id ), $parsed['signature'] ) ) {
			return array( 'result' => 'invalid', 'row' => null );
		}
		if ( (int) $row->event_id !== absint( $event_id ) ) {
			return array( 'result' => 'wrong_event', 'row' => $row );
		}

		return array(
			'result' => self::attendance_result( $row ),
			'row'    => $row,
		);
	}

	/**
	 * Check in an accepted ticket that has not been checked in.
	 *
	 * @param string $code     Ticket code.
	 * @param int    $event_id Event selected on the door screen.
	 * @return array{result:string,row:object|null}
	 */
	public static function check_in( $code, $event_id ) {
		$found = self::inspect( $code, $event_id );
		if ( 'ready' !== $found['result'] || empty( $found['row'] ) ) {
			$found_app_id = ! empty( $found['row']->application_id ) ? (int) $found['row']->application_id : 0;
			if ( 'already' === $found['result'] ) {
				Remember_Logger::info(
					'Check-in skipped: attendee already checked in',
					array(
						'application_id' => $found_app_id,
						'event_id'       => absint( $event_id ),
					)
				);
			} else {
				Remember_Logger::warning(
					'Check-in code rejected',
					array(
						'result'         => $found['result'],
						'application_id' => $found_app_id,
						'event_id'       => absint( $event_id ),
					)
				);
			}
			return $found;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'remember_event_applications';
		$now   = current_time( 'mysql' );
		$saved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET checked_in_at = %s, checked_in_by = %d
				WHERE application_id = %d AND checked_in_at IS NULL",
				$now,
				get_current_user_id(),
				(int) $found['row']->application_id
			)
		);
		if ( false === $saved ) {
			Remember_Logger::error(
				'Check-in failed',
				array(
					'application_id' => (int) $found['row']->application_id,
					'event_id'       => absint( $event_id ),
					'error'          => $wpdb->last_error,
				)
			);
			return array( 'result' => 'invalid', 'row' => $found['row'] );
		}

		$row = self::application_row( $found['row']->application_id );
		if ( empty( $saved ) ) {
			Remember_Logger::info(
				'Check-in skipped: attendee already checked in',
				array(
					'application_id' => (int) $found['row']->application_id,
					'event_id'       => absint( $event_id ),
				)
			);
			return array( 'result' => 'already', 'row' => $row );
		}

		Remember_Logger::info(
			'Attendee checked in',
			array(
				'application_id' => (int) $found['row']->application_id,
				'event_id'       => absint( $event_id ),
			)
		);
		return array( 'result' => 'checked_in', 'row' => $row );
	}

	/**
	 * Check in from a name search result on the selected event.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event selected on the door screen.
	 * @return array{result:string,row:object|null}
	 */
	public static function check_in_application( $application_id, $event_id ) {
		$row = self::application_row( $application_id );
		if ( ! $row || (int) $row->event_id !== absint( $event_id ) ) {
			return array( 'result' => 'invalid', 'row' => null );
		}
		return self::check_in( self::code( $row->application_id, $row->event_id ), $event_id );
	}

	/**
	 * Clear a check-in on the selected event.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event selected on the door screen.
	 * @return array{result:string,row:object|null}
	 */
	public static function undo( $application_id, $event_id ) {
		global $wpdb;
		$row = self::application_row( $application_id );
		if ( ! $row || (int) $row->event_id !== absint( $event_id ) ) {
			return array( 'result' => 'invalid', 'row' => null );
		}
		$state = self::attendance_result( $row );
		if ( 'already' !== $state ) {
			return array( 'result' => $state, 'row' => $row );
		}

		$table = $wpdb->prefix . 'remember_event_applications';
		$error = '';
		$saved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET checked_in_at = NULL, checked_in_by = NULL
				WHERE application_id = %d AND event_id = %d",
				(int) $row->application_id,
				absint( $event_id )
			)
		);
		if ( false === $saved ) {
			$error = $wpdb->last_error;
		}
		if ( false === $saved ) {
			Remember_Logger::error(
				'Check-in undo failed',
				array(
					'application_id' => (int) $row->application_id,
					'event_id'       => absint( $event_id ),
					'error'          => $error,
				)
			);
			return array( 'result' => 'invalid', 'row' => $row );
		}

		Remember_Logger::info(
			'Check-in undone',
			array(
				'application_id' => (int) $row->application_id,
				'event_id'       => absint( $event_id ),
			)
		);
		return array(
			'result' => 'undone',
			'row'    => self::application_row( $row->application_id ),
		);
	}

	/**
	 * Accepted attendees on an event whose name or email matches.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $term     Search text.
	 * @return array
	 */
	public static function search( $event_id, $term ) {
		global $wpdb;
		$term = trim( (string) $term );
		if ( strlen( $term ) < 2 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.application_id, a.member_id, a.checked_in_at, r.role_name
				FROM {$wpdb->prefix}remember_event_applications a
				LEFT JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
				LEFT JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
				WHERE a.event_id = %d AND a.status = 'accepted' AND a.superseded_at IS NULL
				ORDER BY a.application_id ASC",
				absint( $event_id )
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$matches = array();
		$needle  = strtolower( $term );
		foreach ( $rows as $row ) {
			$user = get_user_by( 'id', (int) $row->member_id );
			if ( ! $user ) {
				continue;
			}
			$haystack = strtolower( $user->display_name . ' ' . $user->user_email );
			if ( false === strpos( $haystack, $needle ) ) {
				continue;
			}
			$row->display_name = $user->display_name;
			$row->user_email   = $user->user_email;
			$matches[]         = $row;
			if ( count( $matches ) >= 15 ) {
				break;
			}
		}
		return $matches;
	}

	/**
	 * Person and role for a result row.
	 *
	 * @param object $row Application row.
	 * @return array{name:string,email:string,role:string,when:string,by:string}
	 */
	public static function label( $row ) {
		$user = get_user_by( 'id', (int) $row->member_id );
		$by   = '';
		if ( ! empty( $row->checked_in_by ) ) {
			$staff = get_user_by( 'id', (int) $row->checked_in_by );
			$by    = $staff ? $staff->display_name : '';
		}
		$when = '';
		if ( ! empty( $row->checked_in_at ) ) {
			$when = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->checked_in_at ) );
		}
		return array(
			'name'  => $user ? $user->display_name : __( 'Unknown member', 'remember' ),
			'email' => $user ? $user->user_email : '',
			'role'  => isset( $row->role_name ) ? (string) $row->role_name : '',
			'when'  => $when,
			'by'    => $by,
		);
	}

	/**
	 * Truncated signature for one application on one event.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event ID.
	 * @return string
	 */
	private static function signature( $application_id, $event_id ) {
		return substr( hash_hmac( 'sha256', absint( $application_id ) . '|' . absint( $event_id ), wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Split a ticket code.
	 *
	 * @param string $code Raw code.
	 * @return array{application_id:int,signature:string}|null
	 */
	private static function parse_code( $code ) {
		$code = strtolower( trim( (string) $code ) );
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{16})$/', $code, $matches ) ) {
			return null;
		}
		return array(
			'application_id' => (int) $matches[1],
			'signature'      => $matches[2],
		);
	}

	/**
	 * Why a matching ticket cannot be checked in, or ready / already.
	 *
	 * @param object $row Application row.
	 * @return string
	 */
	private static function attendance_result( $row ) {
		if ( empty( $row->checkin_enabled ) ) {
			return 'disabled';
		}
		if ( 'accepted' !== (string) $row->status || ! empty( $row->superseded_at ) ) {
			return 'not_accepted';
		}
		if ( ! empty( $row->ticket_voided ) ) {
			return 'void';
		}
		if ( ! empty( $row->checked_in_at ) ) {
			return 'already';
		}
		return 'ready';
	}

	/**
	 * Application plus the event check-in flag and role name.
	 *
	 * @param int $application_id Application ID.
	 * @return object|null
	 */
	private static function application_row( $application_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.application_id, a.event_id, a.member_id, a.status, a.ticket_voided, a.superseded_at, a.checked_in_at, a.checked_in_by, e.checkin_enabled, r.role_name
				FROM {$wpdb->prefix}remember_event_applications a
				INNER JOIN {$wpdb->prefix}remember_events e ON a.event_id = e.event_id
				LEFT JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
				LEFT JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
				WHERE a.application_id = %d",
				absint( $application_id )
			)
		);
	}
}
