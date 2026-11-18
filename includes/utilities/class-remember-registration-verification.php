<?php
/**
 * Email confirmation for accounts created by the public registration form.
 *
 * A new registrant is not logged in. They get a link by email, and the account
 * cannot sign in until the link is followed. Accounts that are never confirmed
 * are deleted after a week, which also frees the username and address.
 *
 * Only accounts created by the registration form carry the pending marker, so
 * existing users and staff-created members are not affected.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';

/**
 * Registration email verification.
 */
class Remember_Registration_Verification {

	const META_SINCE    = 'remember_email_unverified_since';
	const META_HASH     = 'remember_email_verify_hash';
	const META_SENT     = 'remember_email_verify_sent';
	const CRON_HOOK     = 'remember_prune_unverified_registrations';
	const EXPIRY_DAYS   = 7;
	const RESEND_WINDOW = 300;

	/**
	 * Hook the confirmation link, the login block, the login messages and the cleanup.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_requests' ) );
		add_filter( 'authenticate', array( __CLASS__, 'block_unverified_login' ), 100 );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily cleanup once.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$scheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) ) {
				Remember_Logger::warning(
					'Unconfirmed registration cleanup could not be scheduled; unconfirmed accounts will stay until it is',
					array( 'error' => $scheduled->get_error_message() )
				);
			} else {
				Remember_Logger::debug( 'Unconfirmed registration cleanup scheduled', array( 'hook' => self::CRON_HOOK ) );
			}
		}
	}

	/**
	 * Remove the daily cleanup (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Whether this account is waiting for its email to be confirmed.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_pending( $user_id ) {
		return (int) get_user_meta( absint( $user_id ), self::META_SINCE, true ) > 0;
	}

	/**
	 * Mark a new account as unconfirmed without emailing anything.
	 *
	 * Call this straight after the account is created so the login block applies
	 * before any slower registration work runs.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the account is now marked as unconfirmed.
	 */
	public static function mark_pending( $user_id ) {
		$user_id = absint( $user_id );
		$now     = time();
		if ( (int) get_user_meta( $user_id, self::META_SINCE, true ) > 0 ) {
			return true;
		}
		return (bool) update_user_meta( $user_id, self::META_SINCE, $now );
	}

	/**
	 * Mark a new account as unconfirmed and email the link.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the email was handed to the mail system.
	 */
	public static function start( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! self::mark_pending( $user_id ) ) {
			Remember_Logger::error(
				'New registration could not be marked as unconfirmed; the account can log in without confirming its email',
				array( 'user_id' => $user_id )
			);
		}
		return self::send_link( $user_id );
	}

	/**
	 * Create a fresh token and email the confirmation link.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function send_link( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			Remember_Logger::warning( 'Registration confirmation email not sent: account not found', array( 'user_id' => $user_id ) );
			return false;
		}

		$token = bin2hex( random_bytes( 16 ) );
		if ( ! update_user_meta( $user_id, self::META_HASH, hash( 'sha256', $token ) ) ) {
			Remember_Logger::error(
				'Registration confirmation email not sent: the confirmation key could not be saved',
				array( 'user_id' => $user_id )
			);
			return false;
		}
		$sent_at = time();
		if ( (int) get_user_meta( $user_id, self::META_SENT, true ) !== $sent_at && ! update_user_meta( $user_id, self::META_SENT, $sent_at ) ) {
			Remember_Logger::warning(
				'Registration confirmation send time could not be saved; the resend limit will not apply to this account',
				array( 'user_id' => $user_id )
			);
		}

		$url = add_query_arg(
			array(
				'remember_verify_email' => '1',
				'uid'                   => $user_id,
				'key'                   => $token,
			),
			home_url( '/' )
		);

		$site    = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Confirm your email address for %s', 'remember' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: site name, 2: confirmation link, 3: number of days */
			__( "Thank you for registering with %1\$s.\n\nPlease confirm your email address by opening this link:\n%2\$s\n\nYou will not be able to log in until you do. If you do not confirm within %3\$d days, the registration is removed.\n\nIf you did not register, you can ignore this email.", 'remember' ),
			$site,
			$url,
			self::EXPIRY_DAYS
		);

		/**
		 * Filter the confirmation email before it is sent.
		 *
		 * @param array   $mail { subject, message }.
		 * @param WP_User $user The registrant.
		 * @param string  $url  Confirmation link.
		 */
		$mail = apply_filters(
			'remember_registration_verification_email',
			array(
				'subject' => $subject,
				'message' => $body,
			),
			$user,
			$url
		);

		$start = Remember_Logger::start_timer();
		$sent  = wp_mail( $user->user_email, $mail['subject'], $mail['message'] );
		if ( ! $sent ) {
			Remember_Logger::error(
				'Registration confirmation email failed to send',
				array(
					'user_id'    => $user_id,
					'elapsed_ms' => Remember_Logger::elapsed_ms( $start ),
				)
			);
			return false;
		}
		Remember_Logger::debug(
			'Registration confirmation email handed to wp_mail',
			array(
				'user_id'    => $user_id,
				'elapsed_ms' => Remember_Logger::elapsed_ms( $start ),
			)
		);
		return true;
	}

	/**
	 * Handle the confirmation link and the resend link.
	 *
	 * @return void
	 */
	public static function handle_requests() {
		if ( isset( $_GET['remember_verify_email'], $_GET['uid'], $_GET['key'] ) ) {
			self::confirm( absint( $_GET['uid'] ), sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
		}
		if ( isset( $_GET['remember_resend_verify'], $_GET['k'] ) ) {
			self::resend( absint( $_GET['remember_resend_verify'] ), sanitize_text_field( wp_unslash( $_GET['k'] ) ) );
		}
	}

	/**
	 * Confirm an email address from the emailed link.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Token from the link.
	 * @return void
	 */
	private static function confirm( $user_id, $key ) {
		$stored = $user_id > 0 ? (string) get_user_meta( $user_id, self::META_HASH, true ) : '';
		if ( '' !== $stored && self::is_pending( $user_id ) && hash_equals( $stored, hash( 'sha256', $key ) ) ) {
			if ( ! delete_user_meta( $user_id, self::META_SINCE ) ) {
				Remember_Logger::error(
					'Registration email confirmed, but the account could not be unlocked; it still cannot log in',
					array( 'user_id' => $user_id )
				);
				wp_safe_redirect( add_query_arg( 'remember_email_verify', 'failed', wp_login_url() ) );
				exit;
			}
			delete_user_meta( $user_id, self::META_HASH );
			delete_user_meta( $user_id, self::META_SENT );
			Remember_Logger::info( 'Registration email confirmed', array( 'user_id' => $user_id ) );
			wp_safe_redirect( add_query_arg( 'remember_email_verify', 'done', wp_login_url() ) );
			exit;
		}
		Remember_Logger::debug(
			'Registration confirmation link refused: not valid, already used, or the account was removed',
			array( 'user_id' => $user_id )
		);
		wp_safe_redirect( add_query_arg( 'remember_email_verify', 'invalid', wp_login_url() ) );
		exit;
	}

	/**
	 * Signed token for the resend link, so it cannot be built for someone else's account.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function resend_key( $user_id ) {
		return hash_hmac( 'sha256', 'remember-resend|' . absint( $user_id ), wp_salt( 'auth' ) );
	}

	/**
	 * Send a new confirmation email, at most once per few minutes.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Signed token.
	 * @return void
	 */
	private static function resend( $user_id, $key ) {
		if ( $user_id > 0 && hash_equals( self::resend_key( $user_id ), $key ) && self::is_pending( $user_id ) ) {
			$last = (int) get_user_meta( $user_id, self::META_SENT, true );
			if ( time() - $last >= self::RESEND_WINDOW ) {
				if ( self::send_link( $user_id ) ) {
					Remember_Logger::info( 'Registration confirmation email sent again', array( 'user_id' => $user_id ) );
				}
			} else {
				Remember_Logger::debug(
					'Registration confirmation email not sent again: the last one was sent too recently',
					array(
						'user_id'         => $user_id,
						'seconds_waiting' => self::RESEND_WINDOW - ( time() - $last ),
					)
				);
			}
		} else {
			Remember_Logger::debug(
				'Registration confirmation resend refused: link not valid or account already confirmed',
				array( 'user_id' => $user_id )
			);
		}
		wp_safe_redirect( add_query_arg( 'remember_email_verify', 'resent', wp_login_url() ) );
		exit;
	}

	/**
	 * Stop an unconfirmed account from logging in.
	 *
	 * Runs after the password check, so the message is only shown to someone who
	 * knew the password and nothing is revealed about other accounts.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_unverified_login( $user ) {
		if ( ! $user instanceof WP_User || ! self::is_pending( $user->ID ) ) {
			return $user;
		}

		Remember_Logger::debug( 'Login refused: email address not confirmed yet', array( 'user_id' => (int) $user->ID ) );

		$resend = add_query_arg(
			array(
				'remember_resend_verify' => $user->ID,
				'k'                      => self::resend_key( $user->ID ),
			),
			home_url( '/' )
		);

		return new WP_Error(
			'remember_email_unverified',
			sprintf(
				/* translators: %s: link to send the email again */
				__( '<strong>Error:</strong> Please confirm your email address first. Open the link we emailed you. %s', 'remember' ),
				'<a href="' . esc_url( $resend ) . '">' . esc_html__( 'Send the email again', 'remember' ) . '</a>'
			)
		);
	}

	/**
	 * Show the outcome of the confirmation link on the login screen.
	 *
	 * @param string $message Existing login message.
	 * @return string
	 */
	public static function login_message( $message ) {
		if ( ! isset( $_GET['remember_email_verify'] ) ) {
			return $message;
		}
		$state = sanitize_key( wp_unslash( $_GET['remember_email_verify'] ) );
		$texts = array(
			'done'    => __( 'Your email address is confirmed. You can log in now.', 'remember' ),
			'invalid' => __( 'That confirmation link is not valid or has expired. Log in to have a new one sent.', 'remember' ),
			'resent'  => __( 'If that account is waiting for confirmation, a new email is on its way. It can take a few minutes.', 'remember' ),
			'failed'  => __( 'Your email address could not be confirmed. Open the link in the email again in a few minutes.', 'remember' ),
		);
		if ( ! isset( $texts[ $state ] ) ) {
			return $message;
		}
		return $message . '<p class="message">' . esc_html( $texts[ $state ] ) . '</p>';
	}

	/**
	 * Delete registrations that were never confirmed.
	 *
	 * @return int Number of accounts removed.
	 */
	public static function prune() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$start = Remember_Logger::start_timer();
		$ids   = get_users(
			array(
				'meta_key'     => self::META_SINCE,
				'meta_value'   => time() - ( self::EXPIRY_DAYS * DAY_IN_SECONDS ),
				'meta_compare' => '<',
				'meta_type'    => 'NUMERIC',
				'number'       => 200,
				'fields'       => 'ID',
			)
		);

		$removed       = 0;
		$skipped_admin = 0;
		$failed        = 0;
		foreach ( $ids as $user_id ) {
			$user_id = absint( $user_id );
			if ( user_can( $user_id, 'manage_options' ) ) {
				++$skipped_admin;
				Remember_Logger::warning(
					'Unconfirmed registration not removed: the account is an administrator',
					array( 'user_id' => $user_id )
				);
				continue;
			}

			// The deleted_user hook removes the photo and the member's reMember data. wp_delete_user() reports success even when its delete query fails.
			if ( wp_delete_user( $user_id ) && ! get_user_by( 'id', $user_id ) ) {
				++$removed;
			} else {
				++$failed;
				Remember_Logger::error(
					'Unconfirmed registration account could not be fully deleted; check this account under Users and delete it by hand',
					array( 'user_id' => $user_id )
				);
			}
		}

		$summary = array(
			'found'         => count( $ids ),
			'removed'       => $removed,
			'skipped_admin' => $skipped_admin,
			'failed'        => $failed,
			'more_waiting'  => 200 === count( $ids ),
			'elapsed_ms'    => Remember_Logger::elapsed_ms( $start ),
		);
		if ( empty( $ids ) ) {
			Remember_Logger::debug( 'Unconfirmed registration cleanup finished: none were due', $summary );
		} elseif ( $failed > 0 ) {
			Remember_Logger::warning( 'Unconfirmed registration cleanup finished with failures', $summary );
		} else {
			Remember_Logger::info( 'Unconfirmed registration cleanup finished', $summary );
		}
		return $removed;
	}
}
