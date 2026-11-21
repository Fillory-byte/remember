<?php
/**
 * Cancelled events: who held a place, their tickets, and the refund or credit checklist.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';

/**
 * An attendee is on an event's cancellation list when their application was accepted at the
 * time the event was cancelled (or staff added them afterwards). Their ticket then reads
 * EVENT CANCELLED, and staff record a refund or credit for each one by hand.
 */
class Remember_Event_Cancellation {

	/**
	 * Allowed refund-or-credit choices.
	 *
	 * @var string[]
	 */
	const CHOICES = array( 'refund', 'credit' );

	/**
	 * Whether an event row is cancelled.
	 *
	 * @param object|null $event Event row.
	 * @return bool
	 */
	public static function is_cancelled( $event ) {
		return is_object( $event ) && isset( $event->status ) && 'cancelled' === (string) $event->status;
	}

	/**
	 * Whether an application is on the cancellation list of its (cancelled) event.
	 *
	 * @param object|null $application Application row.
	 * @param object|null $event       Event row.
	 * @return bool
	 */
	public static function covers( $application, $event ) {
		return self::is_cancelled( $event )
			&& is_object( $application )
			&& ! empty( $application->cancelled_with_event_at );
	}

	/**
	 * Date the event was cancelled, as stored (Y-m-d), or ''.
	 *
	 * @param object|null $event Event row.
	 * @return string
	 */
	public static function cancelled_on( $event ) {
		if ( ! is_object( $event ) || empty( $event->cancelled_at ) ) {
			return '';
		}
		$date = substr( (string) $event->cancelled_at, 0, 10 );
		return '0000-00-00' === $date ? '' : $date;
	}

	/**
	 * Cancellation date in the site's date format, or ''.
	 *
	 * @param object|null $event Event row.
	 * @return string
	 */
	public static function cancelled_on_display( $event ) {
		$date = self::cancelled_on( $event );
		return '' === $date ? '' : date_i18n( get_option( 'date_format' ), strtotime( $date ) );
	}

	/**
	 * Sentence printed on a cancelled ticket.
	 *
	 * @param object|null $event Event row.
	 * @return string
	 */
	public static function notice_text( $event ) {
		$organiser = get_bloginfo( 'name' );
		$date      = self::cancelled_on_display( $event );
		if ( '' !== $date ) {
			return sprintf(
				/* translators: 1: organisation name, 2: date */
				__( 'This event was cancelled by %1$s on %2$s. This ticket is not valid for entry.', 'remember' ),
				$organiser,
				$date
			);
		}
		return sprintf(
			/* translators: %s: organisation name */
			__( 'This event was cancelled by %s. This ticket is not valid for entry.', 'remember' ),
			$organiser
		);
	}

	/**
	 * Cancellation fields from the event form, for the event row.
	 *
	 * The date defaults to today when the event is cancelled without one.
	 *
	 * @param string $status New event status.
	 * @return array{cancelled_at:?string,cancellation_reason:?string}|WP_Error
	 */
	public static function fields_from_request( $status ) {
		$date   = isset( $_POST['cancelled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cancelled_at'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the event form checks its nonce first.
		$reason = isset( $_POST['cancellation_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cancellation_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the event form checks its nonce first.

		if ( '' !== $date && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) ) {
			Remember_Logger::warning(
				'Event save refused: the cancellation date is not a valid date',
				array(
					'cancelled_at' => $date,
					'user_id'      => get_current_user_id(),
				)
			);
			return new WP_Error( 'remember_cancelled_at_invalid', __( 'The "Cancelled on" date is not a valid date. Nothing was saved.', 'remember' ) );
		}
		if ( 'cancelled' === $status && '' === $date ) {
			$date = current_time( 'Y-m-d' );
		}

		return array(
			'cancelled_at'        => '' !== $date ? $date : null,
			'cancellation_reason' => '' !== trim( $reason ) ? $reason : null,
		);
	}

	/**
	 * Put every application accepted right now on a cancelled event's list.
	 *
	 * Runs after each save of a cancelled event, so attendees accepted later are added too.
	 *
	 * @param int    $event_id      Event ID.
	 * @param string $status_before Event status before the save ('' for a new event).
	 * @return int|false Applications added, or false when the list could not be saved.
	 */
	public static function sync_list_after_save( $event_id, $status_before ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$added    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}remember_event_applications
				SET cancelled_with_event_at = %s
				WHERE event_id = %d AND status = 'accepted' AND superseded_at IS NULL AND cancelled_with_event_at IS NULL",
				current_time( 'mysql' ),
				$event_id
			)
		);
		if ( false === $added ) {
			Remember_Logger::error(
				'Event cancelled, but its attendees could not be put on the cancellation list; their tickets do not show the cancellation yet',
				array(
					'event_id' => $event_id,
					'error'    => $wpdb->last_error,
				)
			);
			return false;
		}

		if ( 'cancelled' !== $status_before ) {
			Remember_Logger::info(
				'Event cancelled',
				array(
					'event_id'  => $event_id,
					'attendees' => (int) $added,
					'user_id'   => get_current_user_id(),
				)
			);
		} elseif ( $added > 0 ) {
			Remember_Logger::info(
				'Attendees added to a cancelled event\'s cancellation list',
				array(
					'event_id'  => $event_id,
					'attendees' => (int) $added,
					'user_id'   => get_current_user_id(),
				)
			);
		}
		return (int) $added;
	}

	/**
	 * Attendees on an event's cancellation list, with what they paid.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int,object>
	 */
	public static function attendees( $event_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.application_id, a.member_id, a.status, a.cancelled_with_event_at, a.cancellation_choice,
					a.cancellation_done_at, a.cancellation_done_by, a.cancellation_emailed_at,
					u.display_name, u.user_email, r.role_name,
					p.payment_id, p.payment_status, p.total_amount, p.amount_paid, p.amount_due,
					p.xero_invoice_id, p.xero_invoice_number, p.quickbooks_invoice_id, p.quickbooks_invoice_number
				FROM {$wpdb->prefix}remember_event_applications a
				LEFT JOIN {$wpdb->users} u ON u.ID = a.member_id
				LEFT JOIN {$wpdb->prefix}remember_event_roles er ON er.event_role_id = a.event_role_id
				LEFT JOIN {$wpdb->prefix}remember_roles r ON r.role_id = er.role_id
				LEFT JOIN {$wpdb->prefix}remember_payments p ON p.event_application_id = a.application_id
				WHERE a.event_id = %d AND a.cancelled_with_event_at IS NOT NULL
				ORDER BY u.display_name ASC, a.application_id ASC",
				absint( $event_id )
			)
		);
		if ( '' !== $wpdb->last_error ) {
			Remember_Logger::error(
				'Cancellation list could not be loaded',
				array(
					'event_id' => absint( $event_id ),
					'error'    => $wpdb->last_error,
				)
			);
			return array();
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Applications that left on or after the cancellation date and are not on the list.
	 *
	 * Covers attendees staff declined, or who withdrew, while handling the cancellation
	 * before the list existed. Staff decide whether each one belongs on it.
	 *
	 * @param object $event Event row.
	 * @return array<int,object>
	 */
	public static function late_leavers( $event ) {
		global $wpdb;
		$date = self::cancelled_on( $event );
		if ( ! self::is_cancelled( $event ) || '' === $date ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.application_id, a.member_id, a.status, a.processed_at, u.display_name, u.user_email
				FROM {$wpdb->prefix}remember_event_applications a
				LEFT JOIN {$wpdb->users} u ON u.ID = a.member_id
				WHERE a.event_id = %d AND a.status IN ('declined', 'cancelled') AND a.superseded_at IS NULL
					AND a.cancelled_with_event_at IS NULL AND a.processed_at >= %s
				ORDER BY u.display_name ASC, a.application_id ASC",
				(int) $event->event_id,
				$date . ' 00:00:00'
			)
		);
		if ( '' !== $wpdb->last_error ) {
			Remember_Logger::error(
				'Applications that left after the cancellation could not be loaded',
				array(
					'event_id' => (int) $event->event_id,
					'error'    => $wpdb->last_error,
				)
			);
			return array();
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Progress on an event's list.
	 *
	 * @param array<int,object> $attendees Rows from attendees().
	 * @return array{total:int,done:int,emailed:int}
	 */
	public static function progress( $attendees ) {
		$done    = 0;
		$emailed = 0;
		foreach ( $attendees as $row ) {
			if ( ! empty( $row->cancellation_done_at ) ) {
				++$done;
			}
			if ( ! empty( $row->cancellation_emailed_at ) ) {
				++$emailed;
			}
		}
		return array(
			'total'   => count( $attendees ),
			'done'    => $done,
			'emailed' => $emailed,
		);
	}

	/**
	 * Cancelled events whose list still has attendees not ticked off.
	 *
	 * @return array<int,object> Rows with event_id, event_name, total, open.
	 */
	public static function outstanding_events() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT e.event_id, e.event_name, COUNT(a.application_id) AS total,
				SUM(CASE WHEN a.cancellation_done_at IS NULL THEN 1 ELSE 0 END) AS open
			FROM {$wpdb->prefix}remember_events e
			INNER JOIN {$wpdb->prefix}remember_event_applications a ON a.event_id = e.event_id
			WHERE e.status = 'cancelled' AND a.cancelled_with_event_at IS NOT NULL
			GROUP BY e.event_id, e.event_name
			HAVING open > 0
			ORDER BY e.start_date ASC"
		);
		if ( '' !== $wpdb->last_error ) {
			Remember_Logger::warning(
				'Cancelled events still needing refunds or credits could not be listed; the reminder is not shown',
				array( 'error' => $wpdb->last_error )
			);
			return array();
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Billing provider an attendee's invoice belongs to: xero, quickbooks or ''.
	 *
	 * @param object $row Row from attendees().
	 * @return string
	 */
	public static function provider_for( $row ) {
		if ( ! empty( $row->xero_invoice_id ) ) {
			return 'xero';
		}
		if ( ! empty( $row->quickbooks_invoice_id ) ) {
			return 'quickbooks';
		}
		return '';
	}

	/**
	 * Invoice number of an attendee's invoice, or ''.
	 *
	 * @param object $row Row from attendees().
	 * @return string
	 */
	public static function invoice_number_for( $row ) {
		if ( ! empty( $row->xero_invoice_number ) ) {
			return (string) $row->xero_invoice_number;
		}
		if ( ! empty( $row->quickbooks_invoice_number ) ) {
			return (string) $row->quickbooks_invoice_number;
		}
		return '';
	}

	/**
	 * Whether money has been received on an attendee's invoice, per the last billing sync.
	 *
	 * @param object $row Row from attendees().
	 * @return bool
	 */
	public static function has_paid( $row ) {
		return isset( $row->amount_paid ) && (float) $row->amount_paid > 0.001;
	}

	/**
	 * Whether an attendee's invoice is open and unpaid, so voiding it is the whole job.
	 *
	 * @param object $row Row from attendees().
	 * @return bool
	 */
	public static function can_void( $row ) {
		return '' !== self::provider_for( $row )
			&& ! self::has_paid( $row )
			&& ! in_array( (string) $row->payment_status, array( 'cancelled', 'refunded' ), true );
	}

	/**
	 * Where an attendee stands on the checklist.
	 *
	 * @param object $row Row from attendees() or an application row joined to its payment.
	 * @return string choose|refund_pending|credit_pending|refund_issued|credit_issued|void_pending|voided|nothing_owed|nothing_owed_done
	 */
	public static function state_for( $row ) {
		$done   = ! empty( $row->cancellation_done_at );
		$choice = isset( $row->cancellation_choice ) ? (string) $row->cancellation_choice : '';
		if ( in_array( $choice, self::CHOICES, true ) ) {
			return $choice . ( $done ? '_issued' : '_pending' );
		}
		if ( self::has_paid( $row ) ) {
			return 'choose';
		}
		if ( isset( $row->payment_status ) && 'cancelled' === (string) $row->payment_status && '' !== self::provider_for( $row ) ) {
			return 'voided';
		}
		if ( ! $done && self::can_void( $row ) ) {
			return 'void_pending';
		}
		return $done ? 'nothing_owed_done' : 'nothing_owed';
	}

	/**
	 * Short label for a checklist state.
	 *
	 * @param string $state State from state_for().
	 * @return string
	 */
	public static function state_label( $state ) {
		$labels = array(
			'choose'            => __( 'Refund or credit not chosen yet', 'remember' ),
			'refund_pending'    => __( 'Refund pending', 'remember' ),
			'credit_pending'    => __( 'Credit pending', 'remember' ),
			'refund_issued'     => __( 'Refund issued', 'remember' ),
			'credit_issued'     => __( 'Credit issued', 'remember' ),
			'void_pending'      => __( 'Nothing paid; invoice still open', 'remember' ),
			'voided'            => __( 'Nothing paid; invoice voided', 'remember' ),
			'nothing_owed'      => __( 'Nothing paid; nothing to refund or credit', 'remember' ),
			'nothing_owed_done' => __( 'Nothing paid; nothing to refund or credit', 'remember' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : '';
	}

	/**
	 * Tone class for a checklist state.
	 *
	 * @param string $state State from state_for().
	 * @return string
	 */
	public static function state_tone( $state ) {
		if ( in_array( $state, array( 'refund_issued', 'credit_issued', 'voided', 'nothing_owed_done' ), true ) ) {
			return 'success';
		}
		if ( 'nothing_owed' === $state ) {
			return 'muted';
		}
		return 'warning';
	}

	/**
	 * Label for the Mark done button in a given state.
	 *
	 * @param string $state State from state_for().
	 * @return string
	 */
	public static function done_button_label( $state ) {
		if ( 'refund_pending' === $state ) {
			return __( 'Mark refund issued', 'remember' );
		}
		if ( 'credit_pending' === $state ) {
			return __( 'Mark credit issued', 'remember' );
		}
		return __( 'Mark done', 'remember' );
	}

	/**
	 * Steps billing staff follow for an attendee's recorded choice.
	 *
	 * @param object $row Row from attendees().
	 * @return string[] Empty when there is nothing to do in the billing system.
	 */
	public static function steps_for( $row ) {
		$provider = self::provider_for( $row );
		$choice   = (string) $row->cancellation_choice;
		if ( '' === $provider || ! self::has_paid( $row ) || ! in_array( $choice, self::CHOICES, true ) ) {
			return array();
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-unwind.php';
		$received = (float) $row->amount_paid;
		$balance  = max( 0.0, (float) $row->amount_due );
		$invoice  = self::invoice_number_for( $row );
		return 'credit' === $choice
			? Remember_Billing_Unwind::credit_steps( $provider, $invoice, $received, $balance )
			: Remember_Billing_Unwind::refund_steps( $provider, $invoice, $received, $balance );
	}

	/**
	 * Load one application on a cancelled event's list.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event ID it must belong to.
	 * @return object|WP_Error
	 */
	private static function listed_application( $application_id, $event_id ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		$application = ( new Remember_Application() )->get( absint( $application_id ) );
		$event       = ( new Remember_Event() )->get( absint( $event_id ) );
		if ( ! $application || ! $event || (int) $application->event_id !== (int) $event->event_id ) {
			return new WP_Error( 'remember_cancellation_not_found', __( 'That attendee is not on this event.', 'remember' ) );
		}
		if ( ! self::covers( $application, $event ) ) {
			return new WP_Error( 'remember_cancellation_not_listed', __( 'That attendee is not on this event\'s cancellation list.', 'remember' ) );
		}
		return $application;
	}

	/**
	 * Write cancellation columns on one application.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $data           Column => value.
	 * @return bool
	 */
	private static function write( $application_id, $data ) {
		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'remember_event_applications',
			$data,
			array( 'application_id' => absint( $application_id ) )
		);
		return false !== $result;
	}

	/**
	 * Record (or clear) whether an attendee gets a refund or a credit.
	 *
	 * @param int    $application_id Application ID.
	 * @param int    $event_id       Event ID.
	 * @param string $choice         refund|credit|'' to clear.
	 * @return true|WP_Error
	 */
	public static function set_choice( $application_id, $event_id, $choice ) {
		$choice      = sanitize_key( (string) $choice );
		$application = self::listed_application( $application_id, $event_id );
		if ( is_wp_error( $application ) ) {
			Remember_Logger::warning( 'Refund or credit choice not saved: ' . $application->get_error_message(), array( 'application_id' => absint( $application_id ), 'event_id' => absint( $event_id ) ) );
			return $application;
		}
		if ( '' !== $choice && ! in_array( $choice, self::CHOICES, true ) ) {
			Remember_Logger::warning( 'Refund or credit choice not saved: unknown choice', array( 'application_id' => absint( $application_id ), 'choice' => $choice ) );
			return new WP_Error( 'remember_cancellation_bad_choice', __( 'Choose Refund or Credit.', 'remember' ) );
		}
		if ( ! empty( $application->cancellation_done_at ) ) {
			Remember_Logger::debug( 'Refund or credit not changed: attendee already ticked off as done', array( 'application_id' => absint( $application_id ) ) );
			return new WP_Error( 'remember_cancellation_done', __( 'This attendee is already marked done. Undo that first to change their choice.', 'remember' ) );
		}
		if ( ! self::write( $application_id, array( 'cancellation_choice' => '' !== $choice ? $choice : null ) ) ) {
			global $wpdb;
			Remember_Logger::error(
				'Refund or credit choice could not be saved',
				array(
					'application_id' => absint( $application_id ),
					'choice'         => $choice,
					'error'          => $wpdb->last_error,
				)
			);
			return new WP_Error( 'remember_cancellation_save_failed', __( 'The choice could not be saved. Try again.', 'remember' ) );
		}
		Remember_Logger::info(
			'' !== $choice ? 'Cancelled event: refund or credit chosen, pending until staff mark it issued' : 'Cancelled event: refund or credit choice cleared',
			array(
				'application_id' => absint( $application_id ),
				'event_id'       => absint( $event_id ),
				'choice'         => $choice,
				'user_id'        => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Tick an attendee off as handled, or undo that.
	 *
	 * Someone who paid needs a recorded choice first, so the list says what was done.
	 *
	 * @param int  $application_id Application ID.
	 * @param int  $event_id       Event ID.
	 * @param bool $done           Done or not.
	 * @return true|WP_Error
	 */
	public static function set_done( $application_id, $event_id, $done ) {
		$application = self::listed_application( $application_id, $event_id );
		if ( is_wp_error( $application ) ) {
			Remember_Logger::warning( 'Cancellation checklist not updated: ' . $application->get_error_message(), array( 'application_id' => absint( $application_id ), 'event_id' => absint( $event_id ) ) );
			return $application;
		}
		if ( $done && empty( $application->cancellation_choice ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
			$payment = ( new Remember_Payment() )->get_by_application( absint( $application_id ) );
			if ( $payment && (float) $payment->amount_paid > 0.001 ) {
				Remember_Logger::debug( 'Attendee not marked done: paid, and no refund or credit chosen', array( 'application_id' => absint( $application_id ) ) );
				return new WP_Error( 'remember_cancellation_no_choice', __( 'This attendee paid. Choose Refund or Credit, issue it in the billing system, then mark it issued.', 'remember' ) );
			}
		}
		$data = $done
			? array(
				'cancellation_done_at' => current_time( 'mysql' ),
				'cancellation_done_by' => get_current_user_id(),
			)
			: array(
				'cancellation_done_at' => null,
				'cancellation_done_by' => null,
			);
		if ( ! self::write( $application_id, $data ) ) {
			global $wpdb;
			Remember_Logger::error(
				'Cancellation checklist could not be saved',
				array(
					'application_id' => absint( $application_id ),
					'done'           => (bool) $done,
					'error'          => $wpdb->last_error,
				)
			);
			return new WP_Error( 'remember_cancellation_save_failed', __( 'The checklist could not be saved. Try again.', 'remember' ) );
		}
		Remember_Logger::info(
			$done ? 'Cancelled event: attendee marked done' : 'Cancelled event: attendee marked not done',
			array(
				'application_id' => absint( $application_id ),
				'event_id'       => absint( $event_id ),
				'choice'         => (string) $application->cancellation_choice,
				'user_id'        => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Void an attendee's unpaid invoice in the billing system, when staff ask.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event ID.
	 * @return true|WP_Error
	 */
	public static function void_unpaid_invoice( $application_id, $event_id ) {
		$application = self::listed_application( $application_id, $event_id );
		if ( is_wp_error( $application ) ) {
			Remember_Logger::warning( 'Invoice not voided: ' . $application->get_error_message(), array( 'application_id' => absint( $application_id ), 'event_id' => absint( $event_id ) ) );
			return $application;
		}
		require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
		$payment = ( new Remember_Payment() )->get_by_application( absint( $application_id ) );
		if ( ! $payment || ! self::can_void( $payment ) ) {
			Remember_Logger::debug(
				'Invoice not voided: there is no open unpaid invoice',
				array(
					'application_id' => absint( $application_id ),
					'payment_status' => $payment ? (string) $payment->payment_status : null,
				)
			);
			return new WP_Error( 'remember_cancellation_not_voidable', __( 'There is no open unpaid invoice to void. If money was received, record Refund or Credit and follow the steps instead.', 'remember' ) );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-unwind.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-ticket.php';
		$was_voided = ! empty( $application->ticket_voided );
		$result     = Remember_Billing_Unwind::apply(
			absint( $application_id ),
			'void',
			sprintf(
				/* translators: %d: event ID */
				__( 'Event #%d cancelled', 'remember' ),
				absint( $event_id )
			)
		);
		if ( true === $result && 'skipped_inactive_provider' === Remember_Billing_Unwind::last_outcome() ) {
			$result = new WP_Error( 'remember_cancellation_inactive_provider', __( 'The invoice was not changed: it belongs to a billing provider that is no longer active. Void it in that provider directly.', 'remember' ) );
		}
		if ( is_wp_error( $result ) && ! $was_voided ) {
			// apply() voids the ticket before it tries the invoice; undo that when the invoice was left as it was.
			Remember_Ticket::set_voided( absint( $application_id ), false );
			Remember_Logger::debug(
				'Ticket void undone because the invoice was not voided',
				array(
					'application_id' => absint( $application_id ),
					'code'           => $result->get_error_code(),
				)
			);
		}
		return $result;
	}

	/**
	 * Put an attendee who left after the cancellation date on the list.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event ID.
	 * @return true|WP_Error
	 */
	public static function add_late_leaver( $application_id, $event_id ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		$event = ( new Remember_Event() )->get( absint( $event_id ) );
		$found = false;
		foreach ( self::late_leavers( $event ) as $row ) {
			if ( (int) $row->application_id === absint( $application_id ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			Remember_Logger::warning(
				'Application not added to the cancellation list: it did not leave on or after the cancellation date',
				array(
					'application_id' => absint( $application_id ),
					'event_id'       => absint( $event_id ),
				)
			);
			return new WP_Error( 'remember_cancellation_not_late_leaver', __( 'That application cannot be added to this list.', 'remember' ) );
		}
		if ( ! self::write( $application_id, array( 'cancelled_with_event_at' => current_time( 'mysql' ) ) ) ) {
			global $wpdb;
			Remember_Logger::error(
				'Application could not be added to the cancellation list',
				array(
					'application_id' => absint( $application_id ),
					'error'          => $wpdb->last_error,
				)
			);
			return new WP_Error( 'remember_cancellation_save_failed', __( 'The attendee could not be added. Try again.', 'remember' ) );
		}
		Remember_Logger::info(
			'Application that left after the cancellation added to the cancellation list',
			array(
				'application_id' => absint( $application_id ),
				'event_id'       => absint( $event_id ),
				'user_id'        => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Email one attendee that the event is cancelled, with their ticket link.
	 *
	 * @param int $application_id Application ID.
	 * @param int $event_id       Event ID.
	 * @return true|false|WP_Error False when the template is turned off.
	 */
	public static function email_attendee( $application_id, $event_id ) {
		$application = self::listed_application( $application_id, $event_id );
		if ( is_wp_error( $application ) ) {
			Remember_Logger::warning( 'Cancellation email not sent: ' . $application->get_error_message(), array( 'application_id' => absint( $application_id ), 'event_id' => absint( $event_id ) ) );
			return $application;
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		$result = Remember_Notifications::send_event_cancelled( absint( $application_id ) );
		if ( true === $result && ! self::write( $application_id, array( 'cancellation_emailed_at' => current_time( 'mysql' ) ) ) ) {
			global $wpdb;
			Remember_Logger::warning(
				'Cancellation email sent, but the time it was sent was not recorded; it may be sent again',
				array(
					'application_id' => absint( $application_id ),
					'error'          => $wpdb->last_error,
				)
			);
		}
		return $result;
	}

	/**
	 * Email every attendee on the list who has not been emailed yet.
	 *
	 * @param int $event_id Event ID.
	 * @return array{sent:int,skipped:int,errors:int,disabled:bool}
	 */
	public static function email_not_yet_emailed( $event_id ) {
		$start = Remember_Logger::start_timer();
		$stats = array(
			'sent'     => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'disabled' => false,
		);
		foreach ( self::attendees( $event_id ) as $row ) {
			if ( ! empty( $row->cancellation_emailed_at ) ) {
				++$stats['skipped'];
				continue;
			}
			$result = self::email_attendee( (int) $row->application_id, $event_id );
			if ( true === $result ) {
				++$stats['sent'];
			} elseif ( false === $result ) {
				$stats['disabled'] = true;
				break;
			} else {
				++$stats['errors'];
			}
		}
		Remember_Logger::info(
			'Cancellation emails sent',
			array_merge(
				array(
					'event_id'   => absint( $event_id ),
					'user_id'    => get_current_user_id(),
					'elapsed_ms' => Remember_Logger::elapsed_ms( $start ),
				),
				$stats
			)
		);
		return $stats;
	}
}
