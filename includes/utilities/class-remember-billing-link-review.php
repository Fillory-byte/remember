<?php
/**
 * A person approves every billing link found by email match.
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
 * When a member with no stored Xero contact or QuickBooks customer has an email that matches
 * an existing one, the sync does not link them. The match is held on the member until someone
 * with billing access approves it (link) or rejects it (the next sync creates a new contact).
 * While held, the member's sync fails, so no invoice is created for them.
 */
class Remember_Billing_Link_Review {

	/**
	 * User meta holding the match waiting for review.
	 *
	 * @var string
	 */
	const HOLD_META = 'remember_billing_link_hold';

	/**
	 * User meta listing contacts a person rejected, per provider.
	 *
	 * @var string
	 */
	const REJECTED_META = 'remember_billing_link_rejected';

	/**
	 * Error code returned by a held sync.
	 *
	 * @var string
	 */
	const ERROR_CODE = 'remember_billing_link_on_hold';

	/**
	 * Admin-post action for approve and reject.
	 *
	 * @var string
	 */
	const ACTION = 'remember_billing_link_review';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_pending_dialogs' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_flash_notice' ) );
	}

	/**
	 * Message a member sees while their billing link is held.
	 *
	 * @return string
	 */
	public static function member_message() {
		return __( 'Your invoice can\'t be processed right now. There is a possible error in the link between your membership and billing accounts. Contact a system administrator and provide them this error.', 'remember' );
	}

	/**
	 * Provider label for messages.
	 *
	 * @param string $provider xero or quickbooks.
	 * @return string
	 */
	public static function provider_label( $provider ) {
		return 'xero' === $provider ? __( 'Xero', 'remember' ) : __( 'QuickBooks', 'remember' );
	}

	/**
	 * The held match for a member, or null.
	 *
	 * @param int $member_id Member ID.
	 * @return array|null
	 */
	public static function get_hold( $member_id ) {
		$hold = get_user_meta( absint( $member_id ), self::HOLD_META, true );
		if ( ! is_array( $hold ) || empty( $hold['provider'] ) || empty( $hold['contact_id'] ) ) {
			return null;
		}
		return $hold;
	}

	/**
	 * Whether a person rejected this contact for this member.
	 *
	 * @param int    $member_id  Member ID.
	 * @param string $provider   xero or quickbooks.
	 * @param string $contact_id Contact or customer ID.
	 * @return bool
	 */
	public static function is_rejected( $member_id, $provider, $contact_id ) {
		$rejected = get_user_meta( absint( $member_id ), self::REJECTED_META, true );
		if ( ! is_array( $rejected ) || empty( $rejected[ $provider ] ) || ! is_array( $rejected[ $provider ] ) ) {
			return false;
		}
		return in_array( strtolower( (string) $contact_id ), array_map( 'strtolower', $rejected[ $provider ] ), true );
	}

	/**
	 * Whether the current request is a staff member on an admin screen who can decide on the spot.
	 *
	 * @param int $member_id Member being synced.
	 * @return bool
	 */
	public static function staff_can_decide_now( $member_id ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || absint( $member_id ) === $user_id ) {
			return false;
		}
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		return current_user_can( 'remember_update_billing' );
	}

	/**
	 * Error for a sync that is blocked by a held link, or null when the member is not held.
	 *
	 * Call this before any lookup or write. A hold left from another provider is cleared,
	 * because that contact can no longer be linked.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $provider  Active provider: xero or quickbooks.
	 * @return WP_Error|null
	 */
	public static function blocked_error( $member_id, $provider ) {
		$member_id = absint( $member_id );
		$hold      = self::get_hold( $member_id );
		if ( ! $hold ) {
			return null;
		}
		if ( $provider !== $hold['provider'] ) {
			delete_user_meta( $member_id, self::HOLD_META );
			Remember_Logger::info(
				'Billing link review cleared because the billing provider changed',
				array(
					'member_id'     => $member_id,
					'held_provider' => $hold['provider'],
					'provider'      => $provider,
				)
			);
			return null;
		}

		if ( self::member_email_changed( $member_id, $hold ) && self::claim_hold( $member_id, $hold ) ) {
			// The match was found for an email the member no longer has. Let the sync look again.
			Remember_Logger::info(
				'Billing link review cleared because the member\'s email changed since the match was found',
				array(
					'member_id'  => $member_id,
					'provider'   => $provider,
					'contact_id' => $hold['contact_id'],
				)
			);
			return null;
		}

		Remember_Logger::warning(
			'Billing sync skipped: the member\'s billing link is waiting for review',
			array(
				'member_id'  => $member_id,
				'provider'   => $provider,
				'contact_id' => $hold['contact_id'],
			)
		);

		if ( self::staff_can_decide_now( $member_id ) ) {
			self::queue_dialog( $member_id );
		} elseif ( empty( $hold['emailed_at'] ) ) {
			self::notify_staff( $member_id, $hold );
		}

		return self::hold_error( $member_id, $hold );
	}

	/**
	 * Hold a match found by email. Returns null when a person already rejected this contact,
	 * so the caller carries on as if nothing matched.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $provider  xero or quickbooks.
	 * @param array  $contact   { id, name, email } of the matched contact.
	 * @return WP_Error|null
	 */
	public static function hold_match( $member_id, $provider, $contact ) {
		$member_id  = absint( $member_id );
		$contact_id = isset( $contact['id'] ) ? (string) $contact['id'] : '';
		if ( '' === $contact_id ) {
			return null;
		}

		if ( self::is_rejected( $member_id, $provider, $contact_id ) ) {
			Remember_Logger::debug(
				'Email match ignored: a person rejected this contact for the member',
				array(
					'member_id'  => $member_id,
					'provider'   => $provider,
					'contact_id' => $contact_id,
				)
			);
			return null;
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			$trigger = 'system';
		} elseif ( $user_id === $member_id ) {
			$trigger = 'member';
		} else {
			$trigger = 'staff';
		}

		$member = get_userdata( $member_id );
		$hold   = array(
			'provider'      => $provider,
			'contact_id'    => $contact_id,
			'contact_name'  => isset( $contact['name'] ) ? (string) $contact['name'] : '',
			'contact_email' => isset( $contact['email'] ) ? (string) $contact['email'] : '',
			'member_email'  => $member ? (string) $member->user_email : '',
			'raised_at'     => current_time( 'mysql' ),
			'raised_by'     => $user_id,
			'trigger'       => $trigger,
			'emailed_at'    => '',
		);
		update_user_meta( $member_id, self::HOLD_META, $hold );

		Remember_Logger::warning(
			'Billing link held for review: the member\'s email matches an existing contact',
			array(
				'member_id'  => $member_id,
				'provider'   => $provider,
				'contact_id' => $contact_id,
				'trigger'    => $trigger,
				'user_id'    => $user_id,
			)
		);
		Remember_Logger::debug(
			'Billing link held for review',
			array(
				'member_id'     => $member_id,
				'contact_name'  => $hold['contact_name'],
				'contact_email' => $hold['contact_email'],
			)
		);

		if ( self::staff_can_decide_now( $member_id ) ) {
			self::queue_dialog( $member_id );
		} else {
			self::notify_staff( $member_id, $hold );
		}

		return self::hold_error( $member_id, $hold );
	}

	/**
	 * The error a held sync returns. Members see the fixed member message; staff see the reason.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $hold      Held match.
	 * @return WP_Error
	 */
	private static function hold_error( $member_id, $hold ) {
		if ( get_current_user_id() === absint( $member_id ) ) {
			$message = self::member_message();
		} else {
			$message = sprintf(
				/* translators: 1: Xero or QuickBooks, 2: matched contact name */
				__( 'Billing for this member is on hold. Their email matches the existing %1$s contact "%2$s", and a person must approve that link before any invoice is created. Approve or reject it on the member\'s profile.', 'remember' ),
				self::provider_label( $hold['provider'] ),
				'' !== $hold['contact_name'] ? $hold['contact_name'] : $hold['contact_id']
			);
		}
		return new WP_Error(
			self::ERROR_CODE,
			$message,
			array(
				'member_id'  => absint( $member_id ),
				'provider'   => $hold['provider'],
				'contact_id' => $hold['contact_id'],
			)
		);
	}

	/**
	 * Whether an error is a held billing link.
	 *
	 * @param mixed $error Value to check.
	 * @return bool
	 */
	public static function is_hold_error( $error ) {
		return is_wp_error( $error ) && self::ERROR_CODE === $error->get_error_code();
	}

	/**
	 * Email the staff roles on the Billing Link Needs Review template, once per hold.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $hold      Held match.
	 * @return void
	 */
	private static function notify_staff( $member_id, $hold ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		$emails = Remember_Notifications::admin_broadcast_emails( 'billing_link_review' );
		if ( empty( $emails ) ) {
			Remember_Logger::warning(
				'Billing link review email had no recipients',
				array(
					'member_id' => absint( $member_id ),
				)
			);
			return;
		}

		$context = Remember_Notifications::context_for_member(
			absint( $member_id ),
			array(
				'billing_provider' => self::provider_label( $hold['provider'] ),
				'matched_contact'  => '' !== $hold['contact_name'] ? $hold['contact_name'] : $hold['contact_id'],
				'review_url'       => self::review_url( $member_id ),
			)
		);

		$start = Remember_Logger::start_timer();
		$sent  = 0;
		foreach ( $emails as $email ) {
			if ( true === Remember_Notifications::send( 'billing_link_review', $context, $email, array( 'quiet' => true ) ) ) {
				++$sent;
			}
		}

		if ( $sent > 0 ) {
			$hold['emailed_at'] = current_time( 'mysql' );
			update_user_meta( absint( $member_id ), self::HOLD_META, $hold );
		}

		Remember_Logger::info(
			'Billing link review email sent',
			array(
				'member_id'  => absint( $member_id ),
				'sent'       => $sent,
				'recipients' => count( $emails ),
				'elapsed_ms' => Remember_Logger::elapsed_ms( $start ),
			)
		);
	}

	/**
	 * Admin URL of the review panel on the member's profile.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	public static function review_url( $member_id ) {
		return admin_url( 'admin.php?page=remember-members&view=' . absint( $member_id ) ) . '#remember-billing-link-review';
	}

	/**
	 * Show the review dialog to the current staff member on their next admin page.
	 *
	 * @param int $member_id Member ID.
	 * @return void
	 */
	private static function queue_dialog( $member_id ) {
		$key     = 'remember_billing_link_dialog_' . get_current_user_id();
		$pending = get_transient( $key );
		$pending = is_array( $pending ) ? $pending : array();
		if ( ! in_array( absint( $member_id ), $pending, true ) ) {
			$pending[] = absint( $member_id );
		}
		set_transient( $key, $pending, 10 * MINUTE_IN_SECONDS );
		Remember_Logger::debug(
			'Billing link review dialog queued for staff',
			array(
				'member_id' => absint( $member_id ),
				'user_id'   => get_current_user_id(),
			)
		);
	}

	/**
	 * Link the held contact, then sync the member to it.
	 *
	 * @param int $member_id Member ID.
	 * @return true|WP_Error
	 */
	public static function approve( $member_id ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-provider.php';
		$member_id = absint( $member_id );
		$hold      = self::get_hold( $member_id );
		if ( ! $hold ) {
			Remember_Logger::debug( 'Billing link decision ignored: the member has no hold', array( 'member_id' => $member_id ) );
			return new WP_Error( 'remember_billing_link_no_hold', __( 'This member has no billing link waiting for review.', 'remember' ) );
		}
		if ( Remember_Billing_Provider::get() !== $hold['provider'] ) {
			delete_user_meta( $member_id, self::HOLD_META );
			Remember_Logger::warning(
				'Billing link not approved: the billing provider changed since the match was found',
				array(
					'member_id'     => $member_id,
					'held_provider' => $hold['provider'],
					'user_id'       => get_current_user_id(),
				)
			);
			return new WP_Error( 'remember_billing_link_provider_changed', __( 'The billing provider changed since this match was found, so it was cleared. Sync the member again.', 'remember' ) );
		}

		$verified = self::verify_match( $member_id, $hold );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! self::claim_hold( $member_id, $hold ) ) {
			return self::lost_claim_error( $member_id, $hold, 'approve' );
		}

		if ( 'xero' === $hold['provider'] ) {
			update_user_meta( $member_id, 'remember_xero_contact_id', $hold['contact_id'] );
		} else {
			update_user_meta( $member_id, 'remember_qb_customer_id', $hold['contact_id'] );
			delete_user_meta( $member_id, 'remember_qb_sync_token' );
		}

		Remember_Logger::info(
			'Billing link approved: member linked to the contact their email matched',
			array(
				'member_id'  => $member_id,
				'provider'   => $hold['provider'],
				'contact_id' => $hold['contact_id'],
				'user_id'    => get_current_user_id(),
			)
		);

		return self::sync_now( $member_id, $hold['provider'] );
	}

	/**
	 * Reject the held contact. The next sync creates a new contact for the member.
	 *
	 * @param int $member_id Member ID.
	 * @return true|WP_Error
	 */
	public static function reject( $member_id ) {
		$member_id = absint( $member_id );
		$hold      = self::get_hold( $member_id );
		if ( ! $hold ) {
			Remember_Logger::debug( 'Billing link decision ignored: the member has no hold', array( 'member_id' => $member_id ) );
			return new WP_Error( 'remember_billing_link_no_hold', __( 'This member has no billing link waiting for review.', 'remember' ) );
		}

		if ( ! self::claim_hold( $member_id, $hold ) ) {
			return self::lost_claim_error( $member_id, $hold, 'reject' );
		}

		$rejected = get_user_meta( $member_id, self::REJECTED_META, true );
		$rejected = is_array( $rejected ) ? $rejected : array();
		if ( empty( $rejected[ $hold['provider'] ] ) || ! is_array( $rejected[ $hold['provider'] ] ) ) {
			$rejected[ $hold['provider'] ] = array();
		}
		if ( ! in_array( $hold['contact_id'], $rejected[ $hold['provider'] ], true ) ) {
			$rejected[ $hold['provider'] ][] = $hold['contact_id'];
		}
		update_user_meta( $member_id, self::REJECTED_META, $rejected );

		Remember_Logger::info(
			'Billing link rejected: the member will get a new contact on the next sync',
			array(
				'member_id'  => $member_id,
				'provider'   => $hold['provider'],
				'contact_id' => $hold['contact_id'],
				'user_id'    => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Whether the member's email is no longer the one the match was found for.
	 *
	 * Holds raised before the email was stored have no email to compare, and are left alone.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $hold      Held match.
	 * @return bool
	 */
	private static function member_email_changed( $member_id, $hold ) {
		$held = isset( $hold['member_email'] ) ? trim( (string) $hold['member_email'] ) : '';
		if ( '' === $held ) {
			return false;
		}
		$user = get_userdata( absint( $member_id ) );
		if ( ! $user ) {
			return true;
		}
		return 0 !== strcasecmp( $held, trim( (string) $user->user_email ) );
	}

	/**
	 * Take the hold so only one decision can act on it.
	 *
	 * Deletes the hold only while it is still exactly the one that was read, so of two
	 * decisions made at once (approve and reject, or two approvals) only one gets true.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $hold      Hold as read by get_hold().
	 * @return bool True when this call removed the hold.
	 */
	private static function claim_hold( $member_id, $hold ) {
		// delete_user_meta() unslashes the value it compares, so slash it to match what was stored.
		return (bool) delete_user_meta( absint( $member_id ), self::HOLD_META, wp_slash( $hold ) );
	}

	/**
	 * Error for a decision whose claim failed: say whether the hold was decided or only changed.
	 *
	 * The hold is also rewritten when staff are emailed about it, so a failed claim does not
	 * always mean someone else decided.
	 *
	 * @param int    $member_id Member ID.
	 * @param array  $hold      Hold as read before the claim.
	 * @param string $decision  approve or reject.
	 * @return WP_Error
	 */
	private static function lost_claim_error( $member_id, $hold, $decision ) {
		$now = self::get_hold( $member_id );
		if ( ! $now ) {
			return self::already_decided_error( $member_id, $decision );
		}
		Remember_Logger::info(
			'Billing link decision not applied: the hold was updated while it was being reviewed',
			array(
				'member_id'  => absint( $member_id ),
				'decision'   => $decision,
				'contact_id' => $hold['contact_id'],
			)
		);
		return new WP_Error( 'remember_billing_link_hold_updated', __( 'This billing link was updated while you were reviewing it. Reload the page and try again.', 'remember' ) );
	}

	/**
	 * Error for a decision that lost the race to another one.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $decision  approve or reject.
	 * @return WP_Error
	 */
	private static function already_decided_error( $member_id, $decision ) {
		Remember_Logger::warning(
			'Billing link decision not applied: the hold was already decided',
			array(
				'member_id' => absint( $member_id ),
				'decision'  => $decision,
				'user_id'   => get_current_user_id(),
			)
		);
		return new WP_Error( 'remember_billing_link_already_decided', __( 'Someone else already reviewed this billing link. Nothing was changed.', 'remember' ) );
	}

	/**
	 * Check that the held match is still true before it is linked.
	 *
	 * The member must still have the email the match was found for, the contact must still
	 * exist, and it must still carry that email. A match that is no longer true is cleared, so
	 * the next sync looks again. A check that could not be made leaves the hold in place.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $hold      Held match.
	 * @return true|WP_Error
	 */
	private static function verify_match( $member_id, $hold ) {
		$user = get_userdata( $member_id );
		if ( ! $user ) {
			return new WP_Error( 'remember_billing_link_no_member', __( 'This member could not be found.', 'remember' ) );
		}
		$email = trim( (string) $user->user_email );

		if ( self::member_email_changed( $member_id, $hold ) ) {
			if ( ! self::claim_hold( $member_id, $hold ) ) {
				return self::lost_claim_error( $member_id, $hold, 'approve' );
			}
			Remember_Logger::warning(
				'Billing link not approved: the member\'s email changed since the match was found',
				array(
					'member_id'  => $member_id,
					'provider'   => $hold['provider'],
					'contact_id' => $hold['contact_id'],
					'user_id'    => get_current_user_id(),
				)
			);
			return new WP_Error( 'remember_billing_link_email_changed', __( 'The member\'s email changed after this match was found, so it was cleared. Sync the member again to look for a match.', 'remember' ) );
		}

		$start = Remember_Logger::start_timer();
		if ( 'xero' === $hold['provider'] ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return self::unverified_error( $member_id, $hold, __( 'Xero is not connected, so the contact cannot be checked.', 'remember' ), 'not_connected' );
			}
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-api.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-sync.php';
			$contact = Remember_Xero_API::get_contact( $hold['contact_id'] );
			$gone    = Remember_Xero_API::is_contact_not_found_error( $contact );
			$matches = is_array( $contact ) && Remember_Xero_Sync::contact_email_matches( $contact, $email );
		} else {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			$settings = Remember_QuickBooks_OAuth::get_settings();
			if ( empty( $settings['access_token'] ) || empty( $settings['realm_id'] ) ) {
				return self::unverified_error( $member_id, $hold, __( 'QuickBooks is not connected, so the customer cannot be checked.', 'remember' ), 'not_connected' );
			}
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-api.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-sync.php';
			$contact = Remember_QuickBooks_API::get_customer( $hold['contact_id'] );
			$gone    = Remember_QuickBooks_Sync::is_qb_object_missing_error( $contact );
			$found   = ( is_array( $contact ) && isset( $contact['PrimaryEmailAddr']['Address'] ) ) ? trim( (string) $contact['PrimaryEmailAddr']['Address'] ) : '';
			$matches = '' !== $found && 0 === strcasecmp( $found, $email );
		}
		Remember_Logger::debug(
			'Held billing match re-checked before approval',
			array(
				'member_id'   => $member_id,
				'provider'    => $hold['provider'],
				'still_there' => ! $gone && ! is_wp_error( $contact ),
				'matches'     => $matches,
				'elapsed_ms'  => Remember_Logger::elapsed_ms( $start ),
			)
		);

		if ( is_wp_error( $contact ) && ! $gone ) {
			return self::unverified_error( $member_id, $hold, $contact->get_error_message(), (string) $contact->get_error_code() );
		}
		if ( ! $gone && ( ! is_array( $contact ) || ( 'quickbooks' === $hold['provider'] && empty( $contact['Id'] ) ) ) ) {
			return self::unverified_error( $member_id, $hold, __( 'The billing system sent a reply that could not be read.', 'remember' ), 'unreadable_reply' );
		}
		if ( $gone || ! $matches ) {
			if ( ! self::claim_hold( $member_id, $hold ) ) {
				return self::lost_claim_error( $member_id, $hold, 'approve' );
			}
			Remember_Logger::warning(
				'Billing link not approved: the matched contact is gone or no longer has the member\'s email',
				array(
					'member_id'  => $member_id,
					'provider'   => $hold['provider'],
					'contact_id' => $hold['contact_id'],
					'gone'       => (bool) $gone,
					'user_id'    => get_current_user_id(),
				)
			);
			return new WP_Error( 'remember_billing_link_match_gone', __( 'The matched billing contact was changed or removed, so this match was cleared. Sync the member again to look for a match.', 'remember' ) );
		}
		return true;
	}

	/**
	 * Error for an approval that could not check the contact. The hold stays.
	 *
	 * @param int    $member_id Member ID.
	 * @param array  $hold      Held match.
	 * @param string $reason    Why the check could not be made, for staff.
	 * @param string $code      Short code for the log.
	 * @return WP_Error
	 */
	private static function unverified_error( $member_id, $hold, $reason, $code ) {
		Remember_Logger::warning(
			'Billing link not approved: the matched contact could not be checked',
			array(
				'member_id'  => $member_id,
				'provider'   => $hold['provider'],
				'contact_id' => $hold['contact_id'],
				'reason'     => $code,
				'user_id'    => get_current_user_id(),
			)
		);
		// The provider's message can include an email address, so it stays at debug.
		Remember_Logger::debug( 'Reason the matched contact could not be checked', array( 'message' => $reason ) );
		return new WP_Error(
			'remember_billing_link_unverified',
			sprintf(
				/* translators: %s: reason the contact could not be checked */
				__( 'The matched contact could not be checked, so nothing was linked. The match is still waiting for review. (%s)', 'remember' ),
				rtrim( $reason, '. ' )
			)
		);
	}

	/**
	 * Sync the member to the provider after an approval, when it is connected.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $provider  xero or quickbooks.
	 * @return true|WP_Error
	 */
	private static function sync_now( $member_id, $provider ) {
		if ( 'xero' === $provider ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				Remember_Logger::debug( 'Approved billing link not synced: Xero is not connected', array( 'member_id' => $member_id ) );
				return true;
			}
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-sync.php';
			$result = Remember_Xero_Sync::sync_member_to_contact( $member_id );
		} else {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			$settings = Remember_QuickBooks_OAuth::get_settings();
			if ( empty( $settings['access_token'] ) || empty( $settings['realm_id'] ) ) {
				Remember_Logger::debug( 'Approved billing link not synced: QuickBooks is not connected', array( 'member_id' => $member_id ) );
				return true;
			}
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-sync.php';
			$result = Remember_QuickBooks_Sync::sync_member_to_customer( $member_id );
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Admin-post handler for Approve and Reject.
	 *
	 * @return void
	 */
	public static function handle_decision() {
		$member_id = isset( $_POST['member_id'] ) ? absint( wp_unslash( $_POST['member_id'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $member_id );
		if ( ! current_user_can( 'remember_update_billing' ) ) {
			Remember_Logger::warning(
				'Billing link review refused: the user cannot update billing',
				array(
					'member_id' => $member_id,
					'user_id'   => get_current_user_id(),
				)
			);
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'remember' ), esc_html__( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		$decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$contact_id = isset( $_POST['contact_id'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_id'] ) ) : '';
		$redirect   = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), '' ) : '';
		if ( '' === $redirect ) {
			$redirect = admin_url( 'admin.php?page=remember-members&view=' . $member_id );
		}

		$hold = self::get_hold( $member_id );
		$name = self::member_name( $member_id );
		if ( ! $hold || $contact_id !== $hold['contact_id'] ) {
			Remember_Logger::warning(
				'Billing link review ignored: the match on the form is no longer waiting',
				array(
					'member_id'  => $member_id,
					'contact_id' => $contact_id,
					'user_id'    => get_current_user_id(),
				)
			);
			self::set_flash( 'warning', __( 'That billing link was already reviewed or has changed. Nothing was done.', 'remember' ) );
		} elseif ( 'approve' === $decision ) {
			$result = self::approve( $member_id );
			if ( is_wp_error( $result ) && 0 === strpos( (string) $result->get_error_code(), 'remember_billing_link_' ) ) {
				// Nothing was linked: the match was cleared, could not be checked, or was already decided.
				self::set_flash( 'warning', $result->get_error_message() );
			} elseif ( is_wp_error( $result ) ) {
				Remember_Logger::error(
					'Approved billing link but the sync failed',
					array(
						'member_id' => $member_id,
						'error'     => $result->get_error_message(),
					)
				);
				self::set_flash(
					'warning',
					sprintf(
						/* translators: 1: member name, 2: error message */
						__( '%1$s is linked to the matched contact, but the sync failed: %2$s', 'remember' ),
						$name,
						$result->get_error_message()
					)
				);
			} else {
				self::set_flash(
					'success',
					sprintf(
						/* translators: 1: member name, 2: Xero or QuickBooks */
						__( '%1$s is now linked to the matched %2$s contact. If an invoice was waiting, use Reprocess billing on the application to create it.', 'remember' ),
						$name,
						self::provider_label( $hold['provider'] )
					)
				);
			}
		} elseif ( 'reject' === $decision ) {
			$rejected = self::reject( $member_id );
			if ( is_wp_error( $rejected ) ) {
				self::set_flash( 'warning', $rejected->get_error_message() );
			} else {
				self::set_flash(
					'success',
					sprintf(
						/* translators: 1: member name, 2: Xero or QuickBooks */
						__( 'Match rejected. %1$s will get a new %2$s contact the next time they are synced. If an invoice was waiting, use Reprocess billing on the application.', 'remember' ),
						$name,
						self::provider_label( $hold['provider'] )
					)
				);
			}
		} else {
			Remember_Logger::warning(
				'Billing link review ignored: unknown decision',
				array(
					'member_id' => $member_id,
					'decision'  => substr( $decision, 0, 20 ),
				)
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Member display name for messages.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function member_name( $member_id ) {
		$user = get_userdata( absint( $member_id ) );
		return $user ? (string) $user->display_name : sprintf(
			/* translators: %d: member ID */
			__( 'Member #%d', 'remember' ),
			absint( $member_id )
		);
	}

	/**
	 * Store a one-time notice for the current user.
	 *
	 * @param string $type    success or warning.
	 * @param string $message Message.
	 * @return void
	 */
	private static function set_flash( $type, $message ) {
		set_transient(
			'remember_billing_link_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and clear the current user's notice.
	 *
	 * @return void
	 */
	public static function render_flash_notice() {
		$key   = 'remember_billing_link_notice_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'success' === $flash['type'] ? 'notice-success' : 'notice-warning';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
	}

	/**
	 * Who found the match, for staff.
	 *
	 * @param array $hold Held match.
	 * @return string
	 */
	private static function trigger_text( $hold ) {
		$when = ! empty( $hold['raised_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $hold['raised_at'] ) )
			: '';
		if ( 'member' === $hold['trigger'] ) {
			/* translators: %s: date and time */
			return sprintf( __( 'Found on %s when the member\'s own update was sent to billing.', 'remember' ), $when );
		}
		if ( 'staff' === $hold['trigger'] ) {
			$by = ! empty( $hold['raised_by'] ) ? get_userdata( (int) $hold['raised_by'] ) : false;
			/* translators: 1: date and time, 2: staff name */
			return sprintf( __( 'Found on %1$s when %2$s sent this member to billing.', 'remember' ), $when, $by ? $by->display_name : __( 'a staff member', 'remember' ) );
		}
		/* translators: %s: date and time */
		return sprintf( __( 'Found on %s during an automatic sync.', 'remember' ), $when );
	}

	/**
	 * The member, the matched contact and the Approve / Reject buttons.
	 *
	 * @param int    $member_id   Member ID.
	 * @param array  $hold        Held match.
	 * @param string $redirect_to Where to return after the decision.
	 * @param bool   $show_member Whether to name the member (the dialog can open on any screen).
	 * @return void
	 */
	private static function render_review_body( $member_id, $hold, $redirect_to, $show_member ) {
		$user     = get_userdata( absint( $member_id ) );
		$provider = self::provider_label( $hold['provider'] );
		?>
		<dl class="remember-billing-link-facts">
			<?php if ( $show_member ) : ?>
				<dt><?php esc_html_e( 'Member', 'remember' ); ?></dt>
				<dd>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . absint( $member_id ) ) ); ?>"><?php echo esc_html( self::member_name( $member_id ) ); ?></a>
					<?php if ( $user && is_email( $user->user_email ) ) : ?>
						<br /><span class="remember-billing-link-muted"><?php echo esc_html( $user->user_email ); ?></span>
					<?php endif; ?>
				</dd>
			<?php endif; ?>
			<dt>
				<?php
				/* translators: %s: Xero or QuickBooks */
				echo esc_html( sprintf( __( 'Matched %s contact', 'remember' ), $provider ) );
				?>
			</dt>
			<dd>
				<?php echo esc_html( '' !== $hold['contact_name'] ? $hold['contact_name'] : __( '(no name)', 'remember' ) ); ?>
				<?php if ( '' !== $hold['contact_email'] ) : ?>
					<br /><span class="remember-billing-link-muted"><?php echo esc_html( $hold['contact_email'] ); ?></span>
				<?php endif; ?>
				<br /><span class="remember-billing-link-muted">
					<?php
					/* translators: %s: contact or customer ID */
					echo esc_html( sprintf( __( 'ID %s', 'remember' ), $hold['contact_id'] ) );
					?>
				</span>
			</dd>
		</dl>
		<p class="remember-billing-link-muted"><?php echo esc_html( self::trigger_text( $hold ) ); ?></p>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: Xero or QuickBooks */
					__( 'Approve only if this %s contact really belongs to this member: their invoices will go to it. Reject to give the member a new contact of their own on the next sync.', 'remember' ),
					$provider
				)
			);
			?>
		</p>
		<?php if ( current_user_can( 'remember_update_billing' ) ) : ?>
			<div class="remember-billing-link-actions">
				<?php foreach ( array( 'approve', 'reject' ) as $decision ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION . '_' . absint( $member_id ) ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
						<input type="hidden" name="member_id" value="<?php echo esc_attr( absint( $member_id ) ); ?>" />
						<input type="hidden" name="contact_id" value="<?php echo esc_attr( $hold['contact_id'] ); ?>" />
						<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
						<?php if ( 'approve' === $decision ) : ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve link', 'remember' ); ?></button>
						<?php else : ?>
							<button type="submit" class="button"><?php esc_html_e( 'Reject, create a new contact', 'remember' ); ?></button>
						<?php endif; ?>
					</form>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="remember-billing-link-muted"><?php esc_html_e( 'Someone with billing access must approve or reject it.', 'remember' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Panel on the member's profile while a link is held.
	 *
	 * @param int $member_id Member ID.
	 * @return void
	 */
	public static function render_profile_panel( $member_id ) {
		$hold = self::get_hold( $member_id );
		if ( ! $hold ) {
			return;
		}
		?>
		<div id="remember-billing-link-review" class="notice notice-warning inline remember-billing-link-panel">
			<h3><?php esc_html_e( 'Billing on hold: link needs review', 'remember' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: Xero or QuickBooks */
						__( 'This member\'s email matches an existing %s contact, so reMember has not linked them. No invoice will be created for this member until the link is approved or rejected.', 'remember' ),
						self::provider_label( $hold['provider'] )
					)
				);
				?>
			</p>
			<?php self::render_review_body( $member_id, $hold, admin_url( 'admin.php?page=remember-members&view=' . absint( $member_id ) ), false ); ?>
		</div>
		<?php
	}

	/**
	 * Open the review dialog for matches this staff member just ran into.
	 *
	 * @return void
	 */
	public static function render_pending_dialogs() {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}
		$key     = 'remember_billing_link_dialog_' . $user_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}
		delete_transient( $key );
		if ( ! current_user_can( 'remember_update_billing' ) ) {
			return;
		}

		$redirect_to = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$shown       = 0;
		foreach ( $pending as $member_id ) {
			$hold = self::get_hold( $member_id );
			if ( ! $hold ) {
				continue;
			}
			++$shown;
			?>
			<dialog class="remember-billing-link-dialog" aria-labelledby="remember-billing-link-title-<?php echo esc_attr( absint( $member_id ) ); ?>">
				<h2 id="remember-billing-link-title-<?php echo esc_attr( absint( $member_id ) ); ?>"><?php esc_html_e( 'Approve this billing link?', 'remember' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Xero or QuickBooks */
							__( 'This member has no %s contact yet, and their email matches one that already exists. reMember will not link them or create an invoice until you decide.', 'remember' ),
							self::provider_label( $hold['provider'] )
						)
					);
					?>
				</p>
				<?php self::render_review_body( $member_id, $hold, $redirect_to, true ); ?>
				<form method="dialog" class="remember-billing-link-later">
					<button type="submit" class="button-link"><?php esc_html_e( 'Decide later (billing stays on hold; it is on the member\'s profile)', 'remember' ); ?></button>
				</form>
			</dialog>
			<?php
		}
		if ( $shown < 1 ) {
			return;
		}
		Remember_Logger::debug(
			'Billing link review dialog shown',
			array(
				'user_id' => $user_id,
				'members' => array_map( 'absint', $pending ),
			)
		);
		?>
		<script>
			( function () {
				var dialogs = document.querySelectorAll( 'dialog.remember-billing-link-dialog' );
				var openNext = function ( index ) {
					if ( index >= dialogs.length || 'function' !== typeof dialogs[ index ].showModal ) {
						return;
					}
					dialogs[ index ].addEventListener( 'close', function () { openNext( index + 1 ); } );
					dialogs[ index ].showModal();
				};
				openNext( 0 );
			}() );
		</script>
		<?php
	}
}
