<?php
/**
 * Notification setting model class
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
 * Notification setting model class.
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */
class Remember_Notification_Setting extends Remember_Base_Model {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'notification_settings';

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'setting_id';

	/**
	 * Get notification setting by type.
	 *
	 * @param string $notification_type Notification type.
	 * @return object|null
	 */
	public function get_by_type( $notification_type ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE notification_type = %s",
				$notification_type
			)
		);
	}

	/**
	 * Get all notification settings.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_all( $args = array() ) {
		$defaults = array(
			'orderby' => 'notification_type',
			'order'   => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );
		
		global $wpdb;
		$query = "SELECT * FROM {$this->get_table()}";
		
		if ( ! empty( $args['orderby'] ) ) {
			$orderby = $this->sanitize_orderby( $args['orderby'] );
			$order   = $this->sanitize_order( $args['order'] );
			$query  .= " ORDER BY `{$orderby}` {$order}";
		}
		
		return $wpdb->get_results( $query );
	}

	/**
	 * Update notification setting.
	 *
	 * @param string $notification_type Notification type.
	 * @param array  $data              Data to update.
	 * @return int|false
	 */
	public function update_by_type( $notification_type, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update(
			$this->get_table(),
			$data,
			array( 'notification_type' => $notification_type ),
			array( '%d', '%s', '%s', '%s' ), // Format for is_enabled, subject_template, body_template, updated_at
			array( '%s' ) // Format for notification_type in WHERE clause
		);
	}

	/**
	 * Get notification type label.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public static function get_type_label( $type ) {
		$labels = array(
			'application_received'          => __( 'Application Received', 'remember' ),
			'vetting_assigned'              => __( 'Vetting Assigned', 'remember' ),
			'vetting_case_waiting'          => __( 'Vetting Case Waiting', 'remember' ),
			'vetting_case_failed'           => __( 'Vetting Case Not Opened (Admin)', 'remember' ),
			'vetting_scheduled'             => __( 'Vetting Scheduled', 'remember' ),
			'vetting_completed'             => __( 'Vetting Completed', 'remember' ),
			'member_vetted'                 => __( 'Member Vetted', 'remember' ),
			'member_rejected'               => __( 'Member Rejected', 'remember' ),
			'member_registered'             => __( 'New Member Registration (Admin)', 'remember' ),
			'member_profile_saved'          => __( 'Profile Saved', 'remember' ),
			'event_application_submitted'   => __( 'Event Application Submitted', 'remember' ),
			'event_application_accepted'     => __( 'Event Application Accepted', 'remember' ),
			'event_application_declined'    => __( 'Event Application Declined', 'remember' ),
			'event_application_waitlisted'  => __( 'Event Application Waitlisted', 'remember' ),
			'waitlist_spot_opened'          => __( 'Waitlist Spot Opened (Staff)', 'remember' ),
			'waitlist_promoted'             => __( 'Waitlist Spot Offered', 'remember' ),
			'event_ticket_paid'             => __( 'Event Ticket Paid', 'remember' ),
			'survey_issued'                 => __( 'Survey Issued', 'remember' ),
			'payment_recorded'              => __( 'Payment Recorded', 'remember' ),
			'payment_due_reminder'          => __( 'Payment Due Reminder', 'remember' ),
			'event_invite'                  => __( 'Private Event Invitation', 'remember' ),
			'vetting_collaborator_invited'  => __( 'Vetting Collaborator Invited', 'remember' ),
			'duplicate_hit_admin'           => __( 'Possible Duplicate Profiles (Admin)', 'remember' ),
			'duplicate_hit_member'          => __( 'Possible Duplicate Profile', 'remember' ),
			'duplicate_merged_survivor'     => __( 'Profiles Merged — Keep This Login', 'remember' ),
			'duplicate_merged_locked'       => __( 'Profile No Longer Valid', 'remember' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Get notification type description.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public static function get_type_description( $type ) {
		$descriptions = array(
			'application_received'          => __( 'Sent to the roles chosen on this template when a member or a staff member creates an event application. Until that choice is saved, System Administrators receive it.', 'remember' ),
			'vetting_assigned'              => __( 'Sent when a vetter is assigned to a vetting case. The roles on this template receive it after you change them. Until then, it follows Vetting email recipients on the General screen.', 'remember' ),
			'vetting_case_waiting'          => __( 'Sent when a vetting case opens automatically with no primary vetter. Until the roles on this template are changed, everyone with the Vetting role receives it.', 'remember' ),
			'vetting_case_failed'           => __( 'Sent to reMember System Administrators when a vetting case could not be opened automatically, so one can be opened by hand.', 'remember' ),
			'vetting_scheduled'             => __( 'Sent when a vetting case is scheduled. The roles on this template receive it after you change them. Until then, it follows Vetting email recipients on the General screen.', 'remember' ),
			'vetting_completed'             => __( 'Sent when a vetting case is completed. The roles on this template receive it after you change them. Until then, it follows Vetting email recipients on the General screen.', 'remember' ),
			'member_vetted'                 => __( 'Sent to the member when their vetting case is accepted.', 'remember' ),
			'member_rejected'               => __( 'Sent to the member when their vetting case is rejected.', 'remember' ),
			'member_registered'             => __( 'Sent to reMember System Administrators when a new member registers or is added.', 'remember' ),
			'member_profile_saved'          => __( 'Sent to the member when the public registration form saves their profile. It says the profile was saved and that vetting or event invitation emails may follow.', 'remember' ),
			'event_application_submitted'   => __( 'Sent to member when they submit an event application.', 'remember' ),
			'event_application_accepted'     => __( 'Sent to member when their event application is accepted (includes ticket link).', 'remember' ),
			'event_application_declined'    => __( 'Sent to member when their event application is declined.', 'remember' ),
			'event_application_waitlisted'  => __( 'Sent to member when their event application is waitlisted.', 'remember' ),
			'waitlist_spot_opened'          => __( 'Sent when an accepted spot opens and the event is set to email staff. Until the roles on this template are changed, it goes to Event Administrators accepted to that event.', 'remember' ),
			'waitlist_promoted'             => __( 'Sent to a member when their waitlisted application is moved to pending because a spot opened.', 'remember' ),
			'event_ticket_paid'             => __( 'Sent to member when their event ticket is paid in full (includes ticket link).', 'remember' ),
			'survey_issued'                 => __( 'Sent to accepted participants when a follow-on survey is issued.', 'remember' ),
			'payment_recorded'              => __( 'Sent when a payment is recorded.', 'remember' ),
			'payment_due_reminder'          => __( 'Sent as a reminder when payment is due (also used for balance-due blasts).', 'remember' ),
			'event_invite'                  => __( 'Sent to invited members when a private event\'s registration opens, or when the event is saved as Open if no open time is set. The invite is not an application.', 'remember' ),
			'vetting_collaborator_invited'  => __( 'Sent when a collaborator is invited to a vetting case. The roles on this template receive it after you change them. Until then, it follows Vetting email recipients on the General screen.', 'remember' ),
			'duplicate_hit_admin'           => __( 'Sent to reMember System Administrators when two profiles look like duplicates.', 'remember' ),
			'duplicate_hit_member'          => __( 'Not sent on scan. Kept for templates; members are emailed when a merge completes instead.', 'remember' ),
			'duplicate_merged_survivor'     => __( 'Sent to the remaining profile after a merge.', 'remember' ),
			'duplicate_merged_locked'       => __( 'Sent to the locked-out profile after a merge.', 'remember' ),
		);
		return isset( $descriptions[ $type ] ) ? $descriptions[ $type ] : '';
	}

	/**
	 * Get notification type category.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public static function get_type_category( $type ) {
		$vetting = array(
			'vetting_assigned',
			'vetting_case_waiting',
			'vetting_case_failed',
			'vetting_scheduled',
			'vetting_completed',
			'vetting_collaborator_invited',
			'member_vetted',
			'member_rejected',
		);
		$applications = array(
			'application_received',
			'event_application_submitted',
			'event_application_accepted',
			'event_application_declined',
			'event_application_waitlisted',
			'waitlist_spot_opened',
			'waitlist_promoted',
			'event_ticket_paid',
			'event_invite',
			'survey_issued',
		);
		$billing = array(
			'payment_recorded',
			'payment_due_reminder',
		);
		if ( in_array( $type, $vetting, true ) ) {
			return 'vetting';
		}
		if ( in_array( $type, $applications, true ) ) {
			return 'applications';
		}
		if ( in_array( $type, $billing, true ) ) {
			return 'billing';
		}
		return 'general';
	}

	/**
	 * Get default subject template for notification type.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public static function get_default_subject( $type ) {
		$templates = array(
			'application_received'          => __( 'New Application Received - {member_name}', 'remember' ),
			'vetting_assigned'              => __( 'Vetting Case Assigned - {member_name}', 'remember' ),
			'vetting_case_waiting'          => __( 'New Vetting Case Waiting - {member_name}', 'remember' ),
			'vetting_case_failed'           => __( 'Vetting case could not be opened - {member_name}', 'remember' ),
			'vetting_scheduled'             => __( 'Vetting Case Scheduled - {member_name}', 'remember' ),
			'vetting_completed'             => __( 'Vetting Case Completed - {member_name}', 'remember' ),
			'member_vetted'                 => __( 'Welcome! You\'ve Been Vetted - {member_name}', 'remember' ),
			'member_rejected'               => __( 'Vetting update for {member_name}', 'remember' ),
			'member_registered'             => __( 'New member registration - {member_name}', 'remember' ),
			'member_profile_saved'          => __( 'Your profile was saved', 'remember' ),
			'event_application_submitted'   => __( 'Application Submitted for {event_name}', 'remember' ),
			'event_application_accepted'     => __( 'Application Accepted for {event_name}', 'remember' ),
			'event_application_declined'    => __( 'Application Update for {event_name}', 'remember' ),
			'event_application_waitlisted'  => __( 'Application Waitlisted for {event_name}', 'remember' ),
			'waitlist_spot_opened'          => __( 'A spot opened for {event_name}', 'remember' ),
			'waitlist_promoted'             => __( 'A spot opened for {event_name}', 'remember' ),
			'event_ticket_paid'             => __( 'Your paid ticket for {event_name}', 'remember' ),
			'event_invite'                  => __( 'You are invited to apply for {event_name}', 'remember' ),
			'survey_issued'                 => __( 'Survey for {event_name}', 'remember' ),
			'payment_recorded'              => __( 'Payment Recorded - \${amount}', 'remember' ),
			'payment_due_reminder'          => __( 'Payment Reminder - \${amount_due} Due for {event_name}', 'remember' ),
			'vetting_collaborator_invited'  => __( 'Invitation to Collaborate on Vetting Case', 'remember' ),
			'duplicate_hit_admin'           => __( 'Possible duplicate profiles — review required', 'remember' ),
			'duplicate_hit_member'          => __( 'Your profile was flagged as a possible duplicate', 'remember' ),
			'duplicate_merged_survivor'     => __( 'Your profiles have been merged — keep using this login', 'remember' ),
			'duplicate_merged_locked'       => __( 'This profile is no longer valid', 'remember' ),
		);
		return isset( $templates[ $type ] ) ? $templates[ $type ] : '';
	}

	/**
	 * Body HTML for the notification editor (stored template or default).
	 *
	 * @param string $stored Stored body_template (may be empty).
	 * @param string $type   Notification type.
	 * @return string
	 */
	public static function body_for_editor( $stored, $type ) {
		$body = ( is_string( $stored ) && '' !== trim( $stored ) ) ? $stored : self::get_default_body( $type );
		if ( false === strpos( $body, '<' ) ) {
			return wpautop( $body );
		}
		return $body;
	}

	/**
	 * Get default body template for notification type.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	public static function get_default_body( $type ) {
		$templates = array(
			'application_received' => __( "Hello,\n\nA new application has been received from {member_name}.\n\nApplication ID: {application_id}\nEvent: {event_name}\nDate: {date}\n\nPlease review the application in the admin panel.", 'remember' ),
			
			'vetting_assigned' => __( "Hello,\n\n{vetter_name} has been assigned as the primary vetter for a vetting case.\n\nMember: {member_name}\nVetting Case ID: {vetting_id}\nDate: {date}\n\nOpen the case:\n{vetting_url}", 'remember' ),

			'vetting_case_waiting' => __( "Hello,\n\nA new vetting case is waiting for a vetter.\n\nMember: {member_name}\nVetting Case ID: {vetting_id}\nDate: {date}\n\nOpen the case and assign a primary vetter:\n{vetting_url}", 'remember' ),

			'vetting_case_failed' => __( "Hello,\n\nreMember could not open a vetting case automatically for {member_name}. The member is not in the vetting queue until a case is opened.\n\nMember: {member_name}\nEmail: {member_email}\nDate: {date}\n\nOpen a vetting case for this member:\n{vetting_url}\n\nWhen logging is turned on (Settings, Logging), the reason is in the reMember log.", 'remember' ),
			
			'vetting_scheduled' => __( "Hello,\n\nThe vetting case for {member_name} has been scheduled.\n\nVetting Case ID: {vetting_id}\nScheduled Date: {date}\n\nPlease prepare for the scheduled vetting session.", 'remember' ),
			
			'vetting_completed' => __( "Hello,\n\nThe vetting case for {member_name} has been completed.\n\nVetting Case ID: {vetting_id}\nCompletion Date: {date}\n\nPlease review the decision in the admin panel.", 'remember' ),
			
			'member_vetted' => __( "Hello {member_name},\n\nCongratulations! Your vetting process has been completed and you have been accepted as a member.\n\nYou can now apply for events and participate in our community.\n\nWelcome aboard!\n\nThe Team", 'remember' ),

			'member_rejected' => __( "Hello {member_name},\n\nThank you for your interest. Your vetting process has been completed, and we are unable to accept your membership at this time.\n\nIf you have questions, please contact us.\n\nThe Team", 'remember' ),

			'member_registered' => __( "Hello,\n\nA new member has registered.\n\nName: {member_name}\nEmail: {member_email}\nUsername: {username}\nStatus: {status}\nDate: {date}\n\nView profile:\n{profile_url}\n\nVetting queue:\n{vetting_url}", 'remember' ),

			'member_profile_saved' => __( "Hello {member_name},\n\nYour new profile was saved. Please look for vetting or event invitation notifications which may soon follow.", 'remember' ),
			
			'event_application_submitted' => __( "Hello {member_name},\n\nThank you for submitting your application for {event_name}.\n\nApplication ID: {application_id}\nDate: {date}\n\nWe have received your application and will review it shortly. You will be notified once a decision has been made.\n\nThank you,\nThe Team", 'remember' ),
			
			'event_application_accepted' => __( "Hello {member_name},\n\nGreat news! Your application for {event_name} has been accepted.\n\nApplication ID: {application_id}\nTicket ID: {ticket_id}\nEvent: {event_name}\nDates: {event_dates}\nLocation: {event_location}\nPayment status: {payment_status}\nAmount due: \${amount_due}\n\nView or print your admission ticket (also serves as a receipt):\n{ticket_url}\n\nIf a balance remains, please complete payment. You will receive another email with your paid ticket once payment is recorded.\n\nBest regards,\nThe Team", 'remember' ),
			
			'event_application_declined' => __( "Hello {member_name},\n\nThank you for your interest in {event_name}.\n\nApplication ID: {application_id}\nEvent: {event_name}\nDate: {date}\n\nUnfortunately, we are unable to accept your application at this time. We appreciate your interest and encourage you to apply for future events.\n\nBest regards,\nThe Team", 'remember' ),
			
			'event_application_waitlisted' => __( "Hello {member_name},\n\nYour application for {event_name} has been placed on our waitlist.\n\nApplication ID: {application_id}\nEvent: {event_name}\nDate: {date}\n\nWe will notify you if a spot becomes available.\n\nThank you for your patience,\nThe Team", 'remember' ),

			'waitlist_spot_opened' => __( "A spot opened for {event_name} ({role_name}). {waitlist_count} people are waiting.", 'remember' ),

			'waitlist_promoted' => __( "Hello {member_name},\n\nA spot opened for {event_name} ({role_name}). Your application is pending review.\n\nApplication ID: {application_id}\nDate: {date}\n\nThe Team", 'remember' ),

			'event_ticket_paid' => __( "Hello {member_name},\n\nYour payment for {event_name} has been recorded as paid in full.\n\nTicket ID: {ticket_id}\nApplication ID: {application_id}\nEvent: {event_name}\nDates: {event_dates}\nLocation: {event_location}\n\nView or print your paid admission ticket / receipt:\n{ticket_url}\n\nWe look forward to seeing you at the event.\n\nBest regards,\nThe Team", 'remember' ),

			'event_invite' => __( "Hello {member_name},\n\nYou are invited to apply for {event_name}.\n\nDates: {event_dates}\nLocation: {event_location}\n\nThis invitation is not an application. If you want to attend, start an application here:\n{apply_url}\n\nThe Team", 'remember' ),

			'survey_issued' => __( "Hello {member_name},\n\nPlease complete {survey_title} for {event_name}.\n\n{survey_url}\n\nThank you,\nThe Team", 'remember' ),
			
			'payment_recorded' => __( "Hello {member_name},\n\nThis email confirms that a payment has been recorded.\n\nAmount: \${amount}\nDate: {date}\nApplication ID: {application_id}\n\nThank you for your payment.\n\nBest regards,\nThe Team", 'remember' ),
			
			'payment_due_reminder' => __( "Hello {member_name},\n\nThis is a reminder that payment is due for your accepted application.\n\nAmount Due: \${amount_due}\nApplication ID: {application_id}\nTicket ID: {ticket_id}\nEvent: {event_name}\nDates: {event_dates}\nLocation: {event_location}\nPayment status: {payment_status}\n\nView your ticket (PAYMENT REQUIRED until paid):\n{ticket_url}\n\nPlease submit your payment at your earliest convenience.\n\nThank you,\nThe Team", 'remember' ),
			
			'vetting_collaborator_invited' => __( "Hello,\n\nYou have been invited to collaborate on a vetting case.\n\nMember: {member_name}\nVetting Case ID: {vetting_id}\nDate: {date}\n\nPlease review the case and provide your input.\n\nThank you,\nThe Team", 'remember' ),

			'duplicate_hit_admin' => __( "Hello,\n\nTwo member profiles look like possible duplicates and need a review.\n\nMember IDs: {member_a_id} and {member_b_id}\nMatching fields: {match_fields}\nDate: {date}\n\nReview and merge, or mark them as not duplicates:\n{review_url}\n\nThis message does not include profile contents.", 'remember' ),

			'duplicate_hit_member' => __( "Hello {member_name},\n\nYour profile was flagged as a possible duplicate of another account. A system administrator will review this. You do not need to do anything right now.\n\nDate: {date}\n\nIf you believe this is a mistake, you can reply to this email or contact a system administrator.\n\nThank you,\nThe Team", 'remember' ),

			'duplicate_merged_survivor' => __( "Hello {member_name},\n\nTwo profiles that appeared to belong to you have been merged. Going forward, always log in with this account.\n\nDate: {date}\n\nIf anything looks wrong, contact a system administrator.\n\nThank you,\nThe Team", 'remember' ),

			'duplicate_merged_locked' => __( "Hello {member_name},\n\nThis profile is no longer valid because it was merged with another account. You will not be able to log in here.\n\nIf you believe that is an error, contact a system administrator.\n\nDate: {date}\n\nThank you,\nThe Team", 'remember' ),
		);
		return isset( $templates[ $type ] ) ? $templates[ $type ] : '';
	}
}
