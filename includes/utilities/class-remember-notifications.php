<?php
/**
 * Member notification email sender.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sends templated emails using notification_settings rows.
 */
class Remember_Notifications {

	/**
	 * Send a notification by type with placeholder context.
	 *
	 * @param string $type    Notification type key.
	 * @param array  $context Placeholder values (without braces).
	 * @param string $to      Recipient email.
	 * @return bool|WP_Error True on success, false if disabled, WP_Error on failure.
	 */
	public static function send( $type, $context, $to ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-notification-setting.php';
		$model  = new Remember_Notification_Setting();
		$setting = $model->get_by_type( $type );

		$enabled = true;
		$subject = Remember_Notification_Setting::get_default_subject( $type );
		$body    = Remember_Notification_Setting::get_default_body( $type );

		if ( $setting ) {
			$enabled = ! empty( $setting->is_enabled );
			if ( ! empty( $setting->subject_template ) ) {
				$subject = $setting->subject_template;
			}
			if ( ! empty( $setting->body_template ) ) {
				$body = $setting->body_template;
			}
		}

		if ( ! $enabled ) {
			return false;
		}

		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'remember_notify_bad_email', __( 'Invalid recipient email.', 'remember' ) );
		}

		$context = self::normalize_context( $context );
		$subject = self::replace_placeholders( $subject, $context );
		$body    = self::unwrap_prefixed_url_placeholders( $body );
		$body    = self::replace_placeholders( $body, self::html_context( $context ) );
		$body    = self::repair_doubled_url_schemes( $body );
		$body    = self::to_html_email( $body );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $body, $headers );

		if ( ! $sent ) {
			return new WP_Error( 'remember_notify_send_failed', __( 'Failed to send notification email.', 'remember' ) );
		}

		return true;
	}

	/**
	 * Placeholder keys whose values are absolute URLs.
	 *
	 * @return string[]
	 */
	private static function url_placeholder_keys() {
		return array( 'ticket_url', 'profile_url', 'review_url', 'vetting_url', 'survey_url', 'apply_url' );
	}

	/**
	 * Remove a scheme an editor glued onto a URL placeholder.
	 *
	 * The rich-text link tool stores href="http://{profile_url}". After the
	 * placeholder is replaced that becomes http://https://…, which mail clients
	 * follow as http://https//… .
	 *
	 * @param string $text Template body before placeholder replacement.
	 * @return string
	 */
	private static function unwrap_prefixed_url_placeholders( $text ) {
		$keys = implode( '|', self::url_placeholder_keys() );
		return preg_replace( '#https?://(\{(?:' . $keys . ')\})#i', '$1', (string) $text );
	}

	/**
	 * Collapse a scheme that was prefixed onto an absolute URL.
	 *
	 * Covers both http://https://host and the colon-dropped http://https//host.
	 *
	 * @param string $text Body after placeholder replacement.
	 * @return string
	 */
	private static function repair_doubled_url_schemes( $text ) {
		$text = preg_replace( '#https?://(https?://)#i', '$1', (string) $text );
		return preg_replace( '#https?://(https?)//#i', '$1://', $text );
	}

	/**
	 * Escape placeholder values for HTML bodies. URL keys stay usable in href.
	 *
	 * @param array $context Placeholder map.
	 * @return array
	 */
	private static function html_context( $context ) {
		$url_keys = self::url_placeholder_keys();
		$safe     = array();
		foreach ( (array) $context as $key => $value ) {
			$value = (string) $value;
			if ( in_array( $key, $url_keys, true ) ) {
				$safe[ $key ] = esc_url( $value );
			} else {
				$safe[ $key ] = esc_html( $value );
			}
		}
		return $safe;
	}

	/**
	 * Wrap a template body as an HTML email. Plain-text templates become paragraphs.
	 *
	 * @param string $body Body after placeholder replacement.
	 * @return string
	 */
	private static function to_html_email( $body ) {
		$body = (string) $body;
		if ( false === strpos( $body, '<' ) ) {
			$body = wpautop( $body );
		}
		$body = make_clickable( $body );
		$body = wp_kses_post( $body );

		return '<!DOCTYPE html><html><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1d2327;">' . $body . '</body></html>';
	}

	/**
	 * Emails for reMember System Administrators (not WordPress administrators).
	 *
	 * Falls back to WordPress Administrator users when no System Administrator
	 * member has a valid email, so a new registration is not silently dropped.
	 *
	 * @return string[]
	 */
	public static function admin_emails() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT mr.member_id
				FROM {$wpdb->prefix}remember_member_roles mr
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
				WHERE r.role_name = %s",
				'System Administrator'
			)
		);
		$emails = array();
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$user = get_userdata( (int) $id );
				if ( $user && is_email( $user->user_email ) ) {
					$emails[] = $user->user_email;
				}
			}
		}
		if ( empty( $emails ) ) {
			$wp_admins = get_users(
				array(
					'role'   => 'administrator',
					'fields' => array( 'ID', 'user_email' ),
					'number' => 50,
				)
			);
			foreach ( (array) $wp_admins as $user ) {
				if ( ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
					$emails[] = $user->user_email;
				}
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Who receives staff vetting emails.
	 *
	 * `team` is everyone with the Vetting role. `assigned` is the primary
	 * vetter and collaborators on that case. The member's own result email
	 * does not use this.
	 *
	 * @return string team|assigned
	 */
	public static function vetting_notify_scope() {
		$options = get_option( 'remember_options', array() );
		$scope   = isset( $options['vetting_notify_targets'] ) ? $options['vetting_notify_targets'] : 'team';
		return 'assigned' === $scope ? 'assigned' : 'team';
	}

	/**
	 * Emails for members who hold the Vetting role.
	 *
	 * @return string[]
	 */
	public static function vetting_team_emails() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-vetting-workflow.php';
		return self::emails_for_user_ids( Remember_Vetting_Workflow::vetting_team_ids() );
	}

	/**
	 * Staff recipients for one vetting case, following the notification setting.
	 *
	 * @param int $vetting_id Vetting case ID.
	 * @return string[]
	 */
	public static function vetting_case_recipient_emails( $vetting_id ) {
		if ( 'team' === self::vetting_notify_scope() ) {
			return self::vetting_team_emails();
		}

		$vetting_id = absint( $vetting_id );
		if ( $vetting_id < 1 ) {
			return array();
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
		$case = ( new Remember_Vetting() )->get( $vetting_id );
		if ( ! $case ) {
			return array();
		}

		$ids = array();
		if ( ! empty( $case->primary_vetter_id ) ) {
			$ids[] = (int) $case->primary_vetter_id;
		}
		foreach ( ( new Remember_Vetting() )->get_collaborators( $vetting_id ) as $collaborator ) {
			if ( ! empty( $collaborator->member_id ) ) {
				$ids[] = (int) $collaborator->member_id;
			}
		}

		return self::emails_for_user_ids( $ids );
	}

	/**
	 * Valid email addresses for a list of user IDs.
	 *
	 * @param array $user_ids User IDs.
	 * @return string[]
	 */
	private static function emails_for_user_ids( $user_ids ) {
		$emails = array();
		foreach ( (array) $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Email staff about a vetting case, using the recipient setting.
	 *
	 * A waiting case has nobody assigned, so it always goes to the whole vetting team.
	 *
	 * @param string $type       vetting_assigned, vetting_case_waiting, vetting_scheduled, vetting_completed, or vetting_collaborator_invited.
	 * @param int    $vetting_id Vetting case ID.
	 * @return void
	 */
	public static function notify_vetting_staff( $type, $vetting_id ) {
		$allowed = array(
			'vetting_assigned',
			'vetting_case_waiting',
			'vetting_scheduled',
			'vetting_completed',
			'vetting_collaborator_invited',
		);
		if ( ! in_array( $type, $allowed, true ) ) {
			return;
		}

		$vetting_id = absint( $vetting_id );
		require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
		$case = ( new Remember_Vetting() )->get( $vetting_id );
		if ( ! $case || empty( $case->member_id ) ) {
			return;
		}

		$emails = 'vetting_case_waiting' === $type ? self::vetting_team_emails() : self::vetting_case_recipient_emails( $vetting_id );
		if ( empty( $emails ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Vetting email had no recipients',
				array(
					'type'       => $type,
					'vetting_id' => $vetting_id,
					'scope'      => self::vetting_notify_scope(),
				)
			);
			return;
		}

		$extra = array( 'vetting_id' => $vetting_id );
		if ( 'vetting_scheduled' === $type && ! empty( $case->scheduled_at ) ) {
			$extra['date'] = date_i18n(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $case->scheduled_at )
			);
		}

		$context = self::context_for_member( (int) $case->member_id, $extra );
		foreach ( $emails as $email ) {
			$result = self::send( $type, $context, $email );
			if ( is_wp_error( $result ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
				Remember_Logger::warning(
					'Vetting staff email failed',
					array(
						'type'       => $type,
						'vetting_id' => $vetting_id,
						'to'         => $email,
						'error'      => $result->get_error_message(),
					)
				);
			}
		}
	}

	/**
	 * Placeholder context for a member (registration and vetting result mail).
	 *
	 * @param int   $member_id Member / WordPress user ID.
	 * @param array $extra     Extra placeholders (vetting_id, decision, etc.).
	 * @return array
	 */
	public static function context_for_member( $member_id, $extra = array() ) {
		$member_id = absint( $member_id );
		$user      = get_user_by( 'ID', $member_id );
		$status    = '';

		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member = ( new Remember_Member() )->get( $member_id );
		if ( $member && isset( $member->status ) ) {
			$status = (string) $member->status;
		}

		$vetting_id  = isset( $extra['vetting_id'] ) ? absint( $extra['vetting_id'] ) : 0;
		if ( $vetting_id < 1 && $member_id > 0 ) {
			require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
			$latest = ( new Remember_Vetting() )->get_by_member( $member_id );
			if ( $latest && ! empty( $latest->vetting_id ) ) {
				$vetting_id = absint( $latest->vetting_id );
			}
		}
		$vetting_url = admin_url( 'admin.php?page=remember-vetting' );
		if ( $vetting_id > 0 ) {
			$vetting_url = admin_url( 'admin.php?page=remember-vetting&view=' . $vetting_id );
		}

		$base = array(
			'member_name'  => $user ? $user->display_name : '',
			'member_email' => ( $user && is_email( $user->user_email ) ) ? $user->user_email : '',
			'username'     => $user ? $user->user_login : '',
			'member_id'    => (string) $member_id,
			'status'       => $status,
			'date'         => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'profile_url'  => admin_url( 'admin.php?page=remember-members&view=' . $member_id ),
			'vetting_id'   => $vetting_id > 0 ? (string) $vetting_id : '',
			'vetting_url'  => $vetting_url,
			'decision'     => '',
		);

		return wp_parse_args( $extra, $base );
	}

	/**
	 * Email System Administrators that a new member registered.
	 *
	 * @param int $member_id Member / WordPress user ID.
	 * @return void
	 */
	public static function notify_member_registered( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id < 1 ) {
			return;
		}

		$emails = self::admin_emails();
		if ( empty( $emails ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'New member registered but no admin email recipients',
				array( 'member_id' => $member_id )
			);
			return;
		}

		$context = self::context_for_member( $member_id );
		foreach ( $emails as $email ) {
			$result = self::send( 'member_registered', $context, $email );
			if ( is_wp_error( $result ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
				Remember_Logger::warning(
					'Member registration admin email failed',
					array(
						'member_id' => $member_id,
						'to'        => $email,
						'error'     => $result->get_error_message(),
					)
				);
			}
		}
	}

	/**
	 * Email the member the vetting decision (accepted or rejected).
	 *
	 * @param int    $member_id  Member / WordPress user ID.
	 * @param string $decision   accepted or rejected.
	 * @param int    $vetting_id Vetting case ID.
	 * @return bool|WP_Error True on success, false if disabled, WP_Error on failure.
	 */
	public static function send_vetting_result( $member_id, $decision, $vetting_id = 0 ) {
		$member_id = absint( $member_id );
		$decision  = sanitize_key( $decision );
		if ( $member_id < 1 || ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			return false;
		}

		$user = get_user_by( 'ID', $member_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new WP_Error( 'remember_notify_no_email', __( 'Member has no valid email.', 'remember' ) );
		}

		$type = ( 'accepted' === $decision ) ? 'member_vetted' : 'member_rejected';
		$context = self::context_for_member(
			$member_id,
			array(
				'vetting_id' => absint( $vetting_id ),
				'decision'   => $decision,
			)
		);

		$result = self::send( $type, $context, $user->user_email );
		if ( is_wp_error( $result ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Vetting result email failed',
				array(
					'member_id'  => $member_id,
					'vetting_id' => absint( $vetting_id ),
					'decision'   => $decision,
					'error'      => $result->get_error_message(),
				)
			);
		}

		return $result;
	}

	/**
	 * Email ticket-ready / accepted notice after accept.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_ticket_ready( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$result = self::send( 'event_application_accepted', $context['placeholders'], $context['email'] );
		if ( true === $result ) {
			self::mark_ticket_ready_emailed( $application_id );
		}
		return $result;
	}

	/**
	 * Email paid ticket notice.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_ticket_paid( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$result = self::send( 'event_ticket_paid', $context['placeholders'], $context['email'] );
		if ( true === $result ) {
			self::mark_ticket_paid_emailed( $application_id );
		}
		return $result;
	}

	/**
	 * Email the current ticket (admin one-click).
	 *
	 * Uses paid template when payment_status is paid; otherwise ticket-ready / accept template.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_current_ticket( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$payment_status = isset( $context['placeholders']['payment_status'] ) ? $context['placeholders']['payment_status'] : '';
		if ( 'paid' === $payment_status ) {
			return self::send_ticket_paid( $application_id );
		}

		return self::send_ticket_ready( $application_id );
	}

	/**
	 * Balance-due / payment reminder for one application.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_balance_due( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$payment_status = $context['placeholders']['payment_status'] ?? '';
		if ( 'paid' === $payment_status ) {
			return new WP_Error( 'remember_notify_already_paid', __( 'Application is already paid in full.', 'remember' ) );
		}

		return self::send( 'payment_due_reminder', $context['placeholders'], $context['email'] );
	}

	/**
	 * Blast balance-due emails to accepted applicants who still owe.
	 *
	 * @param array $args {
	 *     @type int $event_id Optional event filter.
	 * }
	 * @return array{sent:int,skipped:int,errors:int}
	 */
	public static function blast_balance_due( $args = array() ) {
		global $wpdb;
		$args     = wp_parse_args( $args, array( 'event_id' => 0 ) );
		$event_id = absint( $args['event_id'] );

		$sql = "SELECT a.application_id
			FROM {$wpdb->prefix}remember_event_applications a
			LEFT JOIN {$wpdb->prefix}remember_payments p ON p.event_application_id = a.application_id
			WHERE a.status = 'accepted'
			AND (a.ticket_voided IS NULL OR a.ticket_voided = 0)
			AND (
				p.payment_id IS NULL
				OR p.payment_status IN ('pending', 'partial')
			)";
		$query_args = array();
		if ( $event_id > 0 ) {
			$sql         .= ' AND a.event_id = %d';
			$query_args[] = $event_id;
		}

		if ( ! empty( $query_args ) ) {
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $query_args ) );
		} else {
			$rows = $wpdb->get_col( $sql );
		}

		$stats = array(
			'sent'    => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		foreach ( (array) $rows as $application_id ) {
			$result = self::send_balance_due( absint( $application_id ) );
			if ( true === $result ) {
				$stats['sent']++;
			} elseif ( false === $result ) {
				$stats['skipped']++;
			} else {
				$stats['errors']++;
			}
		}

		return $stats;
	}

	/**
	 * Handle payment status transitions for paid ticket email.
	 *
	 * @param int    $payment_id  Payment ID.
	 * @param string $old_status  Previous status.
	 * @param string $new_status  New status.
	 * @param object $payment     Payment row after update.
	 * @return void
	 */
	public static function maybe_send_on_payment_status_change( $payment_id, $old_status, $new_status, $payment ) {
		if ( 'paid' !== $new_status || 'paid' === $old_status ) {
			return;
		}
		if ( empty( $payment->event_application_id ) ) {
			return;
		}
		$result = self::send_ticket_paid( (int) $payment->event_application_id );
		if ( is_wp_error( $result ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Ticket paid email failed',
				array(
					'application_id' => (int) $payment->event_application_id,
					'error'          => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Build placeholder context for an application.
	 *
	 * @param int $application_id Application ID.
	 * @return array|WP_Error
	 */
	private static function context_for_application( $application_id ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-ticket.php';
		$data = Remember_Ticket::get_data( $application_id );
		if ( is_wp_error( $data ) ) {
			// Accepted-or-voided required for ticket data; fall back for edge cases.
			require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
			$application_model = new Remember_Application();
			$application       = $application_model->get( $application_id );
			if ( ! $application ) {
				return $data;
			}
			$user  = get_user_by( 'ID', $application->member_id );
			$event = ( new Remember_Event() )->get( $application->event_id );
			$payment = ( new Remember_Payment() )->get_by_application( $application_id );
			$placeholders = array(
				'member_name'     => $user ? $user->display_name : '',
				'event_name'      => $event ? $event->event_name : '',
				'application_id'  => (string) $application_id,
				'ticket_id'       => 'RM-' . (int) $application_id,
				'ticket_url'      => Remember_Ticket::get_ticket_url( $application_id ),
				'amount'          => $payment ? number_format( (float) $payment->amount_paid, 2 ) : '0.00',
				'amount_due'      => $payment ? number_format( (float) $payment->amount_due, 2 ) : '0.00',
				'payment_status'  => $payment ? (string) $payment->payment_status : '',
				'event_location'  => '',
				'event_dates'     => '',
				'date'            => date_i18n( get_option( 'date_format' ) ),
			);
			$email = $user ? $user->user_email : '';
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'remember_notify_no_email', __( 'Member has no valid email.', 'remember' ) );
			}
			return array(
				'email'        => $email,
				'placeholders' => $placeholders,
			);
		}

		$email = $data['member_email'];
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'remember_notify_no_email', __( 'Member has no valid email.', 'remember' ) );
		}

		$placeholders = array(
			'member_name'    => $data['member_name'],
			'event_name'     => $data['event_name'],
			'application_id' => (string) $data['application_id'],
			'ticket_id'      => $data['ticket_id'],
			'ticket_url'     => Remember_Ticket::get_ticket_url( $data['application_id'] ),
			'amount'         => number_format( (float) $data['amount_paid'], 2 ),
			'amount_due'     => number_format( (float) $data['amount_due'], 2 ),
			'payment_status' => $data['payment_status'],
			'event_location' => ! empty( $data['location_lines'] ) ? implode( ', ', $data['location_lines'] ) : '',
			'event_dates'    => $data['event_dates'],
			'date'           => date_i18n( get_option( 'date_format' ) ),
		);

		return array(
			'email'        => $email,
			'placeholders' => $placeholders,
		);
	}

	/**
	 * @param array $context Context.
	 * @return array
	 */
	private static function normalize_context( $context ) {
		$defaults = array(
			'member_name'    => '',
			'event_name'     => '',
			'application_id' => '',
			'ticket_id'      => '',
			'ticket_url'     => '',
			'amount'         => '',
			'amount_due'     => '',
			'payment_status' => '',
			'event_location' => '',
			'event_dates'    => '',
			'date'           => date_i18n( get_option( 'date_format' ) ),
			'vetting_id'     => '',
			'review_url'     => '',
			'match_fields'   => '',
			'member_a_id'    => '',
			'member_b_id'    => '',
			'member_email'   => '',
			'username'       => '',
			'member_id'      => '',
			'status'         => '',
			'profile_url'    => '',
			'vetting_url'    => '',
			'decision'       => '',
			'survey_title'   => '',
			'survey_url'     => '',
			'role_name'      => '',
			'waitlist_count' => '',
			'apply_url'      => '',
		);
		return wp_parse_args( $context, $defaults );
	}

	/**
	 * @param string $text    Template text.
	 * @param array  $context Placeholders.
	 * @return string
	 */
	private static function replace_placeholders( $text, $context ) {
		foreach ( $context as $key => $value ) {
			$text = str_replace( '{' . $key . '}', (string) $value, $text );
		}
		return $text;
	}

	/**
	 * @param int $application_id Application ID.
	 * @return void
	 */
	private static function mark_ticket_ready_emailed( $application_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'remember_event_applications',
			array( 'ticket_ready_emailed_at' => current_time( 'mysql' ) ),
			array( 'application_id' => absint( $application_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $application_id Application ID.
	 * @return void
	 */
	private static function mark_ticket_paid_emailed( $application_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'remember_payments',
			array( 'ticket_paid_emailed_at' => current_time( 'mysql' ) ),
			array( 'event_application_id' => absint( $application_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
