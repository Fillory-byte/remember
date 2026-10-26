<?php
/**
 * QuickBooks sync utility class
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-quickbooks-api.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-product.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-remember-qb-item-mapping.php';
require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';

/**
 * QuickBooks sync utility class.
 *
 * Handles syncing data between WordPress and QuickBooks Online.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */
class Remember_QuickBooks_Sync {

	/**
	 * Sync member to QuickBooks customer.
	 *
	 * @param int $member_id Member ID.
	 * @return array|WP_Error Customer data or error.
	 */
	public static function sync_member_to_customer( $member_id ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		
		$member_model = new Remember_Member();
		$member = $member_model->get( $member_id );
		
		if ( ! $member ) {
			return new WP_Error( 'member_not_found', __( 'Member not found.', 'remember' ) );
		}

		$user = get_user_by( 'ID', $member_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'WordPress user not found.', 'remember' ) );
		}

		global $wpdb;
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
				$member_id
			)
		);

		// Legal name is the accounting identity (not stage/display name).
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-import-export.php';
		$legal_parts = Remember_Import_Export::member_resolve_legal_name_parts( $profile, $member_id );
		$first_name  = trim( (string) $legal_parts[0] );
		$last_name   = trim( (string) $legal_parts[1] );
		$full_name   = trim( $first_name . ' ' . $last_name );
		if ( '' === $full_name ) {
			// Last resort only — never use nickname/display name for the customer.
			$full_name = sprintf(
				/* translators: %d: WordPress user ID */
				__( 'Member #%d', 'remember' ),
				absint( $member_id )
			);
		}

		$nickname = trim( (string) get_user_meta( $member_id, 'nickname', true ) );
		if ( '' === $nickname && ! empty( $user->display_name ) ) {
			$nickname = trim( (string) $user->display_name );
		}
		$notes = sprintf(
			/* translators: %d: WordPress user ID */
			__( 'reMember member #%d', 'remember' ),
			absint( $member_id )
		);
		if ( '' !== $nickname ) {
			$notes .= "\n" . sprintf(
				/* translators: %s: member nickname / stage name */
				__( 'Nickname: %s', 'remember' ),
				$nickname
			);
		}

		$customer_data = array(
			'display_name' => $full_name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'email'        => $user->user_email,
			'phone'        => ( $profile && ! empty( $profile->cell_phone ) ) ? $profile->cell_phone : '',
			'notes'        => $notes,
		);

		if ( $profile && ( ! empty( $profile->address_street ) || ! empty( $profile->address_city ) ) ) {
			$customer_data['address'] = array(
				'street'  => $profile->address_street ?? '',
				'city'    => $profile->address_city ?? '',
				'state'   => $profile->address_state ?? '',
				'postal'  => $profile->address_postal ?? '',
				'country' => $profile->address_country ?? 'US',
			);
		}

		// Prefer stored QuickBooks Customer Id; otherwise match by WordPress email (second best).
		$qb_customer_id = (string) get_user_meta( $member_id, 'remember_qb_customer_id', true );
		if ( '' !== $qb_customer_id ) {
			// Only a lookup that says the customer is gone (other company, wrong sandbox) drops
			// the link; any other error keeps it so the member is never moved to another customer.
			// QBO answers an unknown customer Id with fault 2010 rather than "object not found".
			$linked  = Remember_QuickBooks_API::get_customer( $qb_customer_id );
			$missing = is_wp_error( $linked )
				&& ( self::is_qb_object_missing_error( $linked ) || in_array( '2010', self::qb_fault_codes( $linked ), true ) );
			if ( is_wp_error( $linked ) && ! $missing ) {
				Remember_Logger::error(
					'Failed to sync member to QuickBooks customer',
					array(
						'member_id'      => $member_id,
						'qb_customer_id' => $qb_customer_id,
						'error'          => $linked->get_error_message(),
					)
				);
				return $linked;
			}
			if ( $missing ) {
				Remember_Logger::warning(
					'Clearing QuickBooks customer link (customer missing)',
					array(
						'member_id'      => $member_id,
						'qb_customer_id' => $qb_customer_id,
					)
				);
				delete_user_meta( $member_id, 'remember_qb_customer_id' );
				delete_user_meta( $member_id, 'remember_qb_sync_token' );
				$qb_customer_id = '';
			}
		}
		if ( '' === $qb_customer_id ) {
			$qb_customer_id = self::link_customer_by_email( $member_id, $user->user_email );
		}
		if ( '' !== $qb_customer_id ) {
			$customer_data['qb_customer_id'] = $qb_customer_id;
		}

		$result = self::save_customer( $customer_data, $user->user_email );

		if ( is_wp_error( $result ) ) {
			Remember_Logger::error(
				'Failed to sync member to QuickBooks customer',
				array(
					'member_id'      => $member_id,
					'qb_customer_id' => $qb_customer_id,
					'error'          => $result->get_error_message(),
				)
			);
			return $result;
		}

		update_user_meta( $member_id, 'remember_qb_customer_id', $result['Id'] );
		update_user_meta( $member_id, 'remember_qb_sync_token', isset( $result['SyncToken'] ) ? $result['SyncToken'] : '' );

		Remember_Logger::info(
			'Member synced to QuickBooks customer',
			array(
				'member_id'      => $member_id,
				'qb_customer_id' => $result['Id'],
			)
		);

		return $result;
	}

	/**
	 * Link a member to an existing QuickBooks customer with the same primary email.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $email     Member email.
	 * @return string Customer Id, or '' when none matches.
	 */
	private static function link_customer_by_email( $member_id, $email ) {
		$match = Remember_QuickBooks_API::find_customer_by_primary_email( $email );
		if ( ! is_array( $match ) || empty( $match['Id'] ) ) {
			return '';
		}
		update_user_meta( $member_id, 'remember_qb_customer_id', (string) $match['Id'] );
		update_user_meta( $member_id, 'remember_qb_sync_token', isset( $match['SyncToken'] ) ? $match['SyncToken'] : '' );
		Remember_Logger::info(
			'Linked member to existing QuickBooks customer by email',
			array(
				'member_id'      => $member_id,
				'qb_customer_id' => (string) $match['Id'],
			)
		);
		return (string) $match['Id'];
	}

	/**
	 * Create or update the customer, retrying with the email appended when the name is taken.
	 *
	 * QBO display names must be unique across customers, vendors and employees, inactive included.
	 *
	 * @param array  $customer_data Data for Remember_QuickBooks_API::create_customer().
	 * @param string $email         Member email, used to make the name unique.
	 * @return array|WP_Error Customer entity or error.
	 */
	private static function save_customer( $customer_data, $email ) {
		$result = Remember_QuickBooks_API::create_customer( $customer_data );
		if ( ! is_wp_error( $result ) || ! self::is_qb_duplicate_name_error( $result ) || '' === trim( (string) $email ) ) {
			return $result;
		}

		Remember_Logger::info(
			'QuickBooks customer name already in use; retrying with email added',
			array( 'qb_customer_id' => $customer_data['qb_customer_id'] ?? '' )
		);
		$customer_data['display_name'] = $customer_data['display_name'] . ' (' . $email . ')';
		return Remember_QuickBooks_API::create_customer( $customer_data );
	}

	/**
	 * Whether a QBO error is the duplicate display name fault (code 6240).
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function is_qb_duplicate_name_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		return in_array( '6240', self::qb_fault_codes( $error ), true )
			|| false !== stripos( $error->get_error_message(), 'duplicate name' );
	}

	/**
	 * Intuit Fault error codes carried on a WP_Error from the QuickBooks API.
	 *
	 * @param WP_Error $error Error.
	 * @return string[]
	 */
	private static function qb_fault_codes( $error ) {
		$data   = $error->get_error_data();
		$faults = ( is_array( $data ) && isset( $data['body']['Fault']['Error'] ) && is_array( $data['body']['Fault']['Error'] ) ) ? $data['body']['Fault']['Error'] : array();
		$codes  = array();
		foreach ( $faults as $fault ) {
			if ( isset( $fault['code'] ) ) {
				$codes[] = (string) $fault['code'];
			}
		}
		return $codes;
	}

	/**
	 * Create invoice in QuickBooks for accepted application.
	 *
	 * @param int $application_id Application ID.
	 * @return array|WP_Error Invoice data or error.
	 */
	public static function create_invoice_for_application( $application_id ) {
		$application_model = new Remember_Application();
		$application = $application_model->get( $application_id );

		if ( ! $application ) {
			return new WP_Error( 'application_not_found', __( 'Application not found.', 'remember' ) );
		}

		// Only create invoice for accepted applications
		if ( 'accepted' !== $application->status ) {
			return new WP_Error( 'application_not_accepted', __( 'Application is not accepted.', 'remember' ) );
		}

		// Check if invoice already exists
		$payment_model = new Remember_Payment();
		$payment = $payment_model->get_by_application( $application_id );
		
		if ( $payment && ! empty( $payment->quickbooks_invoice_id ) ) {
			$existing_invoice = Remember_QuickBooks_API::get_invoice( $payment->quickbooks_invoice_id );
			$remote_gone      = is_wp_error( $existing_invoice )
				? self::is_qb_object_missing_error( $existing_invoice )
				: Remember_QuickBooks_API::is_invoice_voided( $existing_invoice, $payment->total_amount );

			if ( $remote_gone ) {
				Remember_Logger::info(
					'Clearing voided/missing QuickBooks invoice link before recreate',
					array(
						'application_id'        => $application_id,
						'payment_id'            => $payment->payment_id,
						'quickbooks_invoice_id' => $payment->quickbooks_invoice_id,
					)
				);
				self::mark_qb_invoice_gone( $payment->payment_id, true );
				$payment = $payment_model->get( $payment->payment_id );
			} else {
				return new WP_Error( 'invoice_exists', __( 'Invoice already exists for this application.', 'remember' ) );
			}
		}

		// Always push latest member profile to QBO before attaching a new invoice (partial profiles get updated later).
		$member_id = $application->member_id;
		$sync_result = self::sync_member_to_customer( $member_id );
		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}
		$qb_customer_id = isset( $sync_result['Id'] ) ? $sync_result['Id'] : get_user_meta( $member_id, 'remember_qb_customer_id', true );
		if ( ! $qb_customer_id ) {
			return new WP_Error( 'no_qb_customer', __( 'Could not create or resolve QuickBooks customer for this member.', 'remember' ) );
		}

		// Get event and event role
		$event_model = new Remember_Event();
		$event = $event_model->get( $application->event_id );

		global $wpdb;
		$event_role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er 
				JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
				WHERE er.event_role_id = %d",
				$application->event_role_id
			)
		);

		// Build line items
		$line_items = array();

		// Add role cost as line item
		if ( $event_role && $event_role->cost > 0 ) {
			$role_product_id = self::resolve_qb_item_id_for_role( $event_role->role_id, $event_role->role_name );
			if ( ! is_wp_error( $role_product_id ) ) {
				$line_items[] = array(
					'product_id'  => $role_product_id,
					'quantity'    => 1,
					'amount'      => floatval( $event_role->cost ),
					'description' => sprintf( __( '%s - %s', 'remember' ), $event_role->role_name, $event->event_name ),
				);
			} else {
				Remember_Logger::warning( 'Skipping role cost line item - product not mapped', array(
					'role_name' => $event_role->role_name,
					'error'     => $role_product_id->get_error_message(),
				) );
			}
		}

		// Add merchandise as line items (names come from event_merchandise; QB mapping is by catalog product_id).
		$merchandise_items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT am.*, em.merchandise_name
				FROM {$wpdb->prefix}remember_application_merchandise am
				JOIN {$wpdb->prefix}remember_event_merchandise em ON am.merchandise_id = em.merchandise_id
				WHERE am.event_application_id = %d",
				$application_id
			)
		);

		foreach ( $merchandise_items as $item ) {
			$product_id = self::resolve_qb_item_id_for_catalog_product( $item->merchandise_name );
			if ( ! is_wp_error( $product_id ) ) {
				$line_items[] = array(
					'product_id'  => $product_id,
					'quantity'    => intval( $item->quantity ),
					'amount'      => floatval( $item->total_cost ),
					'description' => $item->merchandise_name,
				);
			} else {
				Remember_Logger::warning( 'Skipping merchandise line item - product not mapped', array(
					'merchandise_name' => $item->merchandise_name,
					'error'            => $product_id->get_error_message(),
				) );
			}
		}

		if ( empty( $line_items ) ) {
			return new WP_Error( 'no_line_items', __( 'No line items available. Map event roles and catalog products to QuickBooks under Settings → QuickBooks.', 'remember' ) );
		}

		// Create invoice in QuickBooks
		$invoice_data = array(
			'customer_id' => $qb_customer_id,
			'line_items'  => $line_items,
		);

		$invoice_result = Remember_QuickBooks_API::create_invoice( $invoice_data );

		if ( is_wp_error( $invoice_result ) ) {
			Remember_Logger::error( 'Failed to create QuickBooks invoice', array(
				'application_id' => $application_id,
				'error'          => $invoice_result->get_error_message(),
			) );
			return $invoice_result;
		}

		// Create or update payment record with invoice ID
		if ( ! $payment ) {
			// Calculate totals
			$total_amount = 0;
			foreach ( $line_items as $item ) {
				$total_amount += $item['amount'];
			}

			$payment_data = array(
				'event_application_id'           => $application_id,
				'member_id'                      => $member_id,
				'role_cost'                      => $event_role ? floatval( $event_role->cost ) : 0,
				'merchandise_cost'               => 0, // Calculate from merchandise items
				'total_amount'                   => $total_amount,
				'amount_paid'                    => 0,
				'amount_due'                     => $total_amount,
				'payment_status'                 => 'pending',
				'quickbooks_invoice_id'          => $invoice_result['Id'],
				'quickbooks_invoice_number'      => isset( $invoice_result['DocNumber'] ) ? sanitize_text_field( (string) $invoice_result['DocNumber'] ) : null,
			);

			$payment_model->create( $payment_data );
		} else {
			$update_invoice = array(
				'quickbooks_invoice_id' => $invoice_result['Id'],
			);
			if ( isset( $invoice_result['DocNumber'] ) ) {
				$update_invoice['quickbooks_invoice_number'] = sanitize_text_field( (string) $invoice_result['DocNumber'] );
			}
			$payment_model->update( $payment->payment_id, $update_invoice );
		}

		Remember_Logger::info( 'QuickBooks invoice created', array(
			'application_id'    => $application_id,
			'invoice_id'        => $invoice_result['Id'],
			'qb_customer_id'    => $qb_customer_id,
		) );

		return $invoice_result;
	}

	/**
	 * Sync payment status from QuickBooks.
	 *
	 * @param int $payment_id Payment ID.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public static function sync_payment_status( $payment_id ) {
		$payment_model = new Remember_Payment();
		$payment = $payment_model->get( $payment_id );

		if ( ! $payment || empty( $payment->quickbooks_invoice_id ) ) {
			return new WP_Error( 'no_invoice', __( 'No QuickBooks invoice ID found for this payment.', 'remember' ) );
		}

		// Get invoice from QuickBooks
		$invoice = Remember_QuickBooks_API::get_invoice( $payment->quickbooks_invoice_id );

		if ( is_wp_error( $invoice ) ) {
			if ( self::is_qb_object_missing_error( $invoice ) ) {
				Remember_Logger::info(
					'QuickBooks invoice no longer exists; marking local invoice cancelled',
					array(
						'payment_id'            => $payment_id,
						'quickbooks_invoice_id' => $payment->quickbooks_invoice_id,
					)
				);
				self::mark_qb_invoice_gone( $payment_id, true );
				return true;
			}
			return $invoice;
		}

		if ( Remember_QuickBooks_API::is_invoice_voided( $invoice, $payment->total_amount ) ) {
			Remember_Logger::info(
				'QuickBooks invoice is voided; marking local invoice cancelled',
				array(
					'payment_id'            => $payment_id,
					'quickbooks_invoice_id' => $payment->quickbooks_invoice_id,
				)
			);
			self::mark_qb_invoice_gone( $payment_id, false );
			return true;
		}

		// Get each QuickBooks Payment applied to this invoice (multiple payments = multiple rows).
		$qb_customer_id = get_user_meta( $payment->member_id, 'remember_qb_customer_id', true );
		$detail_lines   = Remember_QuickBooks_API::get_invoice_payment_lines( $payment->quickbooks_invoice_id, $qb_customer_id );

		if ( is_wp_error( $detail_lines ) ) {
			// Bubble up API/query errors so manual sync UI can report them.
			return $detail_lines;
		}

		$total_paid = 0;
		foreach ( $detail_lines as $row ) {
			$total_paid += floatval( $row['amount'] ?? 0 );
		}

		$invoice_sort_ts = Remember_QuickBooks_API::qb_entity_sort_timestamp( $invoice );

		// If QBO did not return Payment rows but the invoice shows paid, fall back to invoice totals (single register line).
		if ( empty( $detail_lines ) ) {
			$inv_balance = floatval( $invoice['Balance'] ?? 0 );
			$inv_total   = floatval( $invoice['TotalAmt'] ?? 0 );
			if ( $inv_total > 0 && $inv_balance <= 0.001 ) {
				$total_paid   = $inv_total;
				$pay_sort_ts  = $invoice_sort_ts > 0 ? $invoice_sort_ts + 1 : strtotime( current_time( 'mysql' ) );
				$detail_lines = array(
					array(
						'amount'         => $inv_total,
						'txn_date'       => isset( $invoice['TxnDate'] ) ? sanitize_text_field( (string) $invoice['TxnDate'] ) : '',
						'payment_method' => '',
						'qb_payment_id'  => '',
						'sort_ts'        => $pay_sort_ts,
					),
				);
			}
		}

		$refund_lines = Remember_QuickBooks_API::get_invoice_refund_lines( $payment->quickbooks_invoice_id, $qb_customer_id );
		if ( is_wp_error( $refund_lines ) ) {
			return $refund_lines;
		}

		Remember_Logger::dev_log(
			'sync_payment_status refund discovery',
			array(
				'payment_id'     => $payment_id,
				'qb_invoice_id'  => $payment->quickbooks_invoice_id,
				'refund_rows'    => count( $refund_lines ),
			)
		);

		$total_refunded = 0;
		foreach ( $refund_lines as $rrow ) {
			$total_refunded += floatval( $rrow['amount'] ?? 0 );
		}

		$inv_total   = floatval( $invoice['TotalAmt'] ?? 0 );
		$inv_balance = floatval( $invoice['Balance'] ?? 0 );
		if ( $inv_total > 0.001 ) {
			$total_amt = $inv_total;
		} else {
			$total_amt = floatval( $payment->total_amount );
		}

		$credit_memo_total = 0;
		foreach ( $refund_lines as $rrow ) {
			if ( isset( $rrow['ledger_effect'] ) && 'credit' === $rrow['ledger_effect'] ) {
				$credit_memo_total += floatval( $rrow['amount'] ?? 0 );
			}
		}
		$implied_credits = $total_amt - $inv_balance - $total_paid;
		if ( $implied_credits - $credit_memo_total > 0.001 ) {
			$gap               = round( $implied_credits - $credit_memo_total, 2 );
			$total_refunded   += $gap;
			$refund_lines[]    = array(
				'amount'         => $gap,
				'txn_date'       => isset( $invoice['TxnDate'] ) ? sanitize_text_field( (string) $invoice['TxnDate'] ) : '',
				'payment_method' => __( 'Allocated credit', 'remember' ),
				'qb_refund_id'   => 'qb-balance-credit',
				'doc_number'     => '',
				'sort_ts'        => $invoice_sort_ts > 0 ? $invoice_sort_ts + 2 : strtotime( current_time( 'mysql' ) ),
				'ledger_effect'  => 'credit',
			);
		}

		$net_paid = $total_paid - $credit_memo_total;
		if ( $net_paid < 0 ) {
			$net_paid = 0;
		}

		$amount_due      = max( 0, $inv_balance );
		$amount_paid_out = max( 0, $total_paid );
		if ( $amount_due <= 0.001 ) {
			$amount_due = 0;
			if ( $credit_memo_total > 0.001 && $total_paid <= 0.001 ) {
				$payment_status  = 'refunded';
				$amount_paid_out = 0;
			} else {
				$payment_status = 'paid';
			}
		} elseif ( $total_paid > 0.001 || $credit_memo_total > 0.001 ) {
			$payment_status = 'partial';
		} else {
			$payment_status  = 'pending';
			$amount_paid_out = 0;
		}

		$latest_ts = 0;
		foreach ( $detail_lines as $row ) {
			$t = isset( $row['sort_ts'] ) ? (int) $row['sort_ts'] : strtotime( $row['txn_date'] ?? '' );
			if ( $t && $t > $latest_ts ) {
				$latest_ts = $t;
			}
		}
		foreach ( $refund_lines as $row ) {
			$t = isset( $row['sort_ts'] ) ? (int) $row['sort_ts'] : strtotime( $row['txn_date'] ?? '' );
			if ( $t && $t > $latest_ts ) {
				$latest_ts = $t;
			}
		}

		$has_money_activity = $total_paid > 0 || $total_refunded > 0;

		$update_data = array(
			'amount_paid'                => $amount_paid_out,
			'amount_due'                 => $amount_due,
			'total_amount'               => $total_amt,
			'payment_status'             => $payment_status,
			'payment_date'               => $has_money_activity
				? ( $latest_ts > 0 ? date( 'Y-m-d H:i:s', $latest_ts ) : current_time( 'mysql' ) )
				: null,
			'quickbooks_payment_lines'   => ! empty( $detail_lines ) ? wp_json_encode( $detail_lines ) : null,
			'quickbooks_refund_lines'    => ! empty( $refund_lines ) ? wp_json_encode( $refund_lines ) : null,
			'quickbooks_invoice_sort_ts' => $invoice_sort_ts > 0 ? $invoice_sort_ts : null,
		);
		if ( isset( $invoice['DocNumber'] ) ) {
			$update_data['quickbooks_invoice_number'] = sanitize_text_field( (string) $invoice['DocNumber'] );
		}
		$payment_model->update( $payment_id, $update_data );

		Remember_Logger::info( 'Payment status synced from QuickBooks', array(
			'payment_id'       => $payment_id,
			'amount_paid'      => $amount_paid_out,
			'total_refunded'   => $total_refunded,
			'payment_status'   => $payment_status,
		) );

		return true;
	}

	/**
	 * Sync all pending payments.
	 *
	 * @return array Results array with success/error counts.
	 */
	public static function sync_all_payments() {
		global $wpdb;
		
		// Reconcile every row that references a QBO invoice (pending, partial, or paid) so
		// deleted/voided invoices in QuickBooks are detected and cleared on the reMember side.
		$payments = $wpdb->get_results(
			"SELECT payment_id FROM {$wpdb->prefix}remember_payments 
			WHERE quickbooks_invoice_id IS NOT NULL 
			AND quickbooks_invoice_id != '' 
			ORDER BY payment_id ASC"
		);

		$results = array(
			'success' => 0,
			'error'   => 0,
			'errors'  => array(),
		);

		foreach ( $payments as $payment ) {
			$result = self::sync_payment_status( $payment->payment_id );
			if ( is_wp_error( $result ) ) {
				$results['error']++;
				$results['errors'][] = array(
					'payment_id' => $payment->payment_id,
					'error'      => $result->get_error_message(),
				);
			} else {
				$results['success']++;
			}
		}

		// Update last sync time
		$wpdb->update(
			$wpdb->prefix . 'remember_payment_processors',
			array( 'last_sync_at' => current_time( 'mysql' ) ),
			array( 'processor_type' => 'quickbooks' ),
			array( '%s' ),
			array( '%s' )
		);

		return $results;
	}

	/**
	 * Sync payment status for one member's QuickBooks-linked payment rows.
	 *
	 * @param int $member_id Member ID.
	 * @return array{success:int,error:int,errors:array}
	 */
	public static function sync_member_payments( $member_id ) {
		$member_id = absint( $member_id );
		$results   = array(
			'success' => 0,
			'error'   => 0,
			'errors'  => array(),
		);
		if ( $member_id <= 0 ) {
			return $results;
		}

		$payment_model = new Remember_Payment();
		$payments      = $payment_model->get_by_member( $member_id );
		foreach ( $payments as $payment ) {
			if ( empty( $payment->quickbooks_invoice_id ) ) {
				continue;
			}
			$result = self::sync_payment_status( $payment->payment_id );
			if ( is_wp_error( $result ) ) {
				$results['error']++;
				$results['errors'][] = array(
					'payment_id' => $payment->payment_id,
					'error'      => $result->get_error_message(),
				);
			} else {
				$results['success']++;
			}
		}

		return $results;
	}

	/**
	 * Mark a local QBO-linked payment as cancelled after the remote invoice is gone or voided.
	 *
	 * @param int  $payment_id Payment ID.
	 * @param bool $clear_ids  When true, drop the QBO invoice id so a new invoice can be created.
	 */
	private static function mark_qb_invoice_gone( $payment_id, $clear_ids = true ) {
		$payment_model = new Remember_Payment();
		$data          = array(
			'amount_paid'              => 0,
			'amount_due'               => 0,
			'payment_status'           => 'cancelled',
			'payment_date'             => null,
			'quickbooks_payment_lines' => null,
			'quickbooks_refund_lines'  => null,
		);
		if ( $clear_ids ) {
			$data['quickbooks_invoice_id']      = null;
			$data['quickbooks_invoice_number']  = null;
			$data['quickbooks_invoice_sort_ts'] = null;
		}
		$payment_model->update( $payment_id, $data );
	}

	/**
	 * Whether a QuickBooks API error means the invoice or customer no longer exists (deleted, wrong company, etc.).
	 *
	 * @param WP_Error $error Error from a QuickBooks API lookup.
	 * @return bool
	 */
	private static function is_qb_object_missing_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		// Avoid matching auth errors whose messages contain "not found".
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'qb_reauth_required', 'qb_not_configured', 'qb_token_refresh_failed' ), true ) ) {
			return false;
		}
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
		if ( 404 === $status ) {
			return true;
		}
		// Intuit object-not-found Fault often returns 400 with a specific message.
		$msg = strtolower( $error->get_error_message() );
		if ( false !== strpos( $msg, 'object not found' ) || false !== strpos( $msg, 'does not exist' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve QuickBooks Item Id for an event role (by role_id; consistent across events).
	 *
	 * @param int    $role_id   Role ID.
	 * @param string $role_name Role display name (used for auto-match in QBO by name).
	 * @return string|WP_Error QuickBooks Item Id string, or error.
	 */
	private static function resolve_qb_item_id_for_role( $role_id, $role_name ) {
		return self::resolve_qb_item_id( 'role', absint( $role_id ), $role_name );
	}

	/**
	 * Resolve QuickBooks Item Id for a catalog product (add-on) by merchandise name.
	 *
	 * @param string $merchandise_name Must match a row in remember_products (catalog).
	 * @return string|WP_Error QuickBooks Item Id string, or error.
	 */
	private static function resolve_qb_item_id_for_catalog_product( $merchandise_name ) {
		$product_model = new Remember_Product();
		$catalog       = $product_model->get_by_name( $merchandise_name );
		if ( ! $catalog || (int) $catalog->is_active !== 1 ) {
			return new WP_Error(
				'catalog_product_missing',
				sprintf(
					/* translators: %s: product/add-on name */
					__( 'Add-on "%s" is not an active catalog product. Add it under Products first.', 'remember' ),
					$merchandise_name
				)
			);
		}
		return self::resolve_qb_item_id( 'product', absint( $catalog->product_id ), $merchandise_name );
	}

	/**
	 * Resolve QuickBooks Item Id using remember_qb_item_mappings; optional auto-match by Item Name in QBO.
	 *
	 * @param string $entity_type 'role' or 'product'.
	 * @param int    $entity_id   role_id or product_id.
	 * @param string $fallback_name Name to query in QBO when no mapping exists.
	 * @return string|WP_Error QuickBooks Item Id string, or error.
	 */
	private static function resolve_qb_item_id( $entity_type, $entity_id, $fallback_name ) {
		$mapping_model = new Remember_QB_Item_Mapping();
		$row           = $mapping_model->get_by_entity( $entity_type, $entity_id );
		if ( $row && ! empty( $row->quickbooks_product_id ) ) {
			return $row->quickbooks_product_id;
		}

		$qb_items = Remember_QuickBooks_API::query_items( "SELECT * FROM Item WHERE Name = '" . esc_sql( $fallback_name ) . "'" );

		if ( ! is_wp_error( $qb_items ) && ! empty( $qb_items ) ) {
			$qb_product    = $qb_items[0];
			$qb_product_id = $qb_product['Id'];
			$qb_name       = $qb_product['Name'] ?? $fallback_name;
			$mapping_model->upsert( $entity_type, $entity_id, $qb_product_id, $qb_name );
			return $qb_product_id;
		}

		Remember_Logger::warning(
			'QuickBooks Item not mapped and no name match in QBO',
			array(
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'name'        => $fallback_name,
			)
		);

		return new WP_Error(
			'qb_item_not_mapped',
			sprintf(
				/* translators: %s: role or product name */
				__( 'QuickBooks has no mapping for "%s". Set it under Settings → QuickBooks (Event roles + Products).', 'remember' ),
				$fallback_name
			)
		);
	}
}
