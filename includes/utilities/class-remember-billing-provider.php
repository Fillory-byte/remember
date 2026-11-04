<?php
/**
 * Active billing / accounting provider helpers.
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
 * Site-wide billing provider: none | quickbooks | xero.
 */
class Remember_Billing_Provider {

	/**
	 * Get the configured billing provider.
	 *
	 * @return string none|quickbooks|xero
	 */
	public static function get() {
		$options  = get_option( 'remember_options', array() );
		$provider = isset( $options['billing_provider'] ) ? (string) $options['billing_provider'] : 'none';
		if ( ! in_array( $provider, array( 'none', 'quickbooks', 'xero' ), true ) ) {
			return 'none';
		}
		return $provider;
	}

	/**
	 * Whether QuickBooks is the active provider.
	 *
	 * @return bool
	 */
	public static function is_quickbooks() {
		return 'quickbooks' === self::get();
	}

	/**
	 * Whether Xero is the active provider.
	 *
	 * @return bool
	 */
	public static function is_xero() {
		return 'xero' === self::get();
	}

	/**
	 * Sync provider invoice/payment status for one member's payment rows.
	 *
	 * Used when a member opens their dashboard so amounts stay current.
	 *
	 * @param int $member_id WordPress user / member ID.
	 * @param int $throttle_seconds Skip if synced this recently (0 = always). Default 60.
	 * @return array{success:int,error:int,skipped:bool}
	 */
	public static function sync_member_payments( $member_id, $throttle_seconds = 60 ) {
		$member_id = absint( $member_id );
		$empty     = array(
			'success' => 0,
			'error'   => 0,
			'skipped' => true,
		);
		if ( $member_id <= 0 ) {
			return $empty;
		}

		$throttle_seconds = absint( $throttle_seconds );
		if ( $throttle_seconds > 0 ) {
			$transient_key = 'remember_member_billing_sync_' . $member_id;
			if ( false !== get_transient( $transient_key ) ) {
				Remember_Logger::debug(
					'Member billing re-check skipped: ran recently',
					array(
						'member_id'        => $member_id,
						'throttle_seconds' => $throttle_seconds,
					)
				);
				return $empty;
			}
		}

		$results = array(
			'success' => 0,
			'error'   => 0,
			'skipped' => false,
		);

		if ( self::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-sync.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return $empty;
			}
			$results = Remember_Xero_Sync::sync_member_payments( $member_id );
		} elseif ( self::is_quickbooks() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-sync.php';
			$qb = Remember_QuickBooks_OAuth::get_settings();
			if ( empty( $qb['access_token'] ) || empty( $qb['realm_id'] ) ) {
				return $empty;
			}
			$results = Remember_QuickBooks_Sync::sync_member_payments( $member_id );
		} else {
			return $empty;
		}

		if ( $throttle_seconds > 0 ) {
			set_transient( 'remember_member_billing_sync_' . $member_id, 1, $throttle_seconds );
		}

		$results['skipped'] = false;
		return $results;
	}

	/**
	 * Sync all provider-linked payment rows (admin Billing list).
	 *
	 * @param int $throttle_seconds Skip if synced this recently (0 = always). Default 60.
	 * @return array{success:int,error:int,skipped:bool}
	 */
	public static function sync_all_payments( $throttle_seconds = 60 ) {
		$empty = array(
			'success' => 0,
			'error'   => 0,
			'skipped' => true,
		);

		$throttle_seconds = absint( $throttle_seconds );
		if ( $throttle_seconds > 0 ) {
			if ( false !== get_transient( 'remember_billing_list_sync' ) ) {
				Remember_Logger::debug( 'Billing re-check skipped: ran within the last minute', array( 'throttle_seconds' => $throttle_seconds ) );
				return $empty;
			}
		}

		$results = array(
			'success' => 0,
			'error'   => 0,
			'skipped' => false,
		);

		if ( self::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-sync.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return $empty;
			}
			$results = Remember_Xero_Sync::sync_all_payments();
		} elseif ( self::is_quickbooks() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-sync.php';
			$qb = Remember_QuickBooks_OAuth::get_settings();
			if ( empty( $qb['access_token'] ) || empty( $qb['realm_id'] ) ) {
				return $empty;
			}
			$results = Remember_QuickBooks_Sync::sync_all_payments();
		} else {
			return $empty;
		}

		if ( $throttle_seconds > 0 ) {
			set_transient( 'remember_billing_list_sync', 1, $throttle_seconds );
		}

		$results['skipped'] = false;
		return $results;
	}

	/**
	 * Whether to email the provider invoice to the customer after create-on-accept.
	 *
	 * Default on when unset; Settings can disable.
	 *
	 * @return bool
	 */
	public static function should_email_invoice_on_accept() {
		$options = get_option( 'remember_options', array() );
		if ( ! array_key_exists( 'email_invoice_on_accept', $options ) ) {
			return true;
		}
		return ! empty( $options['email_invoice_on_accept'] );
	}

	/**
	 * Whether new Xero invoices should consume unallocated credit notes before email.
	 *
	 * Default off. QuickBooks ignores this setting.
	 *
	 * @return bool
	 */
	public static function should_auto_apply_xero_credit() {
		$options = get_option( 'remember_options', array() );
		return ! empty( $options['xero_auto_apply_credit'] );
	}

	/**
	 * Email the active-provider invoice for an application (soft-fail friendly).
	 *
	 * Call after a successful create_invoice_for_application. Uses stored payment row IDs.
	 *
	 * @param int $application_id Event application ID.
	 * @return true|null|WP_Error true sent, null skipped, WP_Error on failure.
	 */
	public static function email_invoice_for_application( $application_id ) {
		$application_id = absint( $application_id );
		if ( $application_id <= 0 ) {
			return new WP_Error( 'email_invoice_bad_app', __( 'Invalid application.', 'remember' ) );
		}
		if ( ! self::should_email_invoice_on_accept() ) {
			return null;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
		$payment_model = new Remember_Payment();
		$payment       = $payment_model->get_by_application( $application_id );
		if ( ! $payment ) {
			return new WP_Error( 'email_invoice_no_payment', __( 'No payment row found for this application.', 'remember' ) );
		}

		if ( self::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-api.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return null;
			}
			$invoice_id = ! empty( $payment->xero_invoice_id ) ? (string) $payment->xero_invoice_id : '';
			if ( '' === $invoice_id ) {
				return new WP_Error( 'email_invoice_no_id', __( 'Missing Xero invoice ID.', 'remember' ) );
			}
			return Remember_Xero_API::email_invoice( $invoice_id );
		}

		if ( self::is_quickbooks() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-api.php';
			$qb = Remember_QuickBooks_OAuth::get_settings();
			if ( empty( $qb['access_token'] ) || empty( $qb['realm_id'] ) ) {
				return null;
			}
			$invoice_id = ! empty( $payment->quickbooks_invoice_id ) ? (string) $payment->quickbooks_invoice_id : '';
			if ( '' === $invoice_id ) {
				return new WP_Error( 'email_invoice_no_id', __( 'Missing QuickBooks invoice ID.', 'remember' ) );
			}
			$member = get_userdata( (int) $payment->member_id );
			$send_to = ( $member && ! empty( $member->user_email ) ) ? $member->user_email : null;
			return Remember_QuickBooks_API::email_invoice( $invoice_id, $send_to );
		}

		return null;
	}
}
