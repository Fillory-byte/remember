<?php
/**
 * QuickBooks API wrapper class
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-quickbooks-oauth.php';
require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';

/**
 * QuickBooks API wrapper class.
 *
 * Handles all QuickBooks Online API calls.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */
class Remember_QuickBooks_API {

	/**
	 * QuickBooks API base URL.
	 */
	const QB_API_BASE = 'https://sandbox-quickbooks.api.intuit.com/v3/company/';

	/**
	 * Minor version for v3 API requests (Intuit recommends always sending this).
	 *
	 * @link https://developer.intuit.com/app/developer/qbo/docs/learn/rest-api-features#minor-versions
	 */
	const QB_MINOR_VERSION = 73;

	/**
	 * Get API base URL (sandbox or production).
	 *
	 * @return string API base URL.
	 */
	private static function get_api_base() {
		$settings = Remember_QuickBooks_OAuth::get_settings();
		$environment = isset( $settings['environment'] ) ? $settings['environment'] : 'sandbox';

		if ( 'production' === $environment ) {
			return 'https://quickbooks.api.intuit.com/v3/company/';
		}

		return self::QB_API_BASE;
	}

	/**
	 * Get company ID from settings.
	 *
	 * @return string|false Company ID or false if not set.
	 */
	private static function get_company_id() {
		$settings = Remember_QuickBooks_OAuth::get_settings();
		return isset( $settings['realm_id'] ) ? $settings['realm_id'] : false;
	}

	/**
	 * Get valid access token (refresh if needed).
	 *
	 * @return string|WP_Error Access token or error.
	 */
	private static function get_access_token() {
		$settings = Remember_QuickBooks_OAuth::get_settings();

		$access = isset( $settings['access_token'] ) ? trim( (string) $settings['access_token'] ) : '';
		if ( ! is_string( $access ) || strlen( $access ) < 20 ) {
			Remember_Logger::error(
				'QuickBooks API: access token missing or invalid after decrypt',
				array( 'code' => 'no_token' )
			);
			return new WP_Error( 'no_token', __( 'No access token available. Please reconnect QuickBooks.', 'remember' ) );
		}

		// Check if token is expired (with 5 minute buffer). Missing expires_at (0) means unknown — refresh if possible.
		$expires_at    = isset( $settings['expires_at'] ) ? (int) $settings['expires_at'] : 0;
		$needs_refresh = ( $expires_at <= 0 ) || ( time() >= ( $expires_at - 300 ) );

		if ( $needs_refresh ) {
			$refresh_token = isset( $settings['refresh_token'] ) ? trim( (string) $settings['refresh_token'] ) : '';
			if ( strlen( $refresh_token ) < 10 ) {
				Remember_Logger::error( 'QuickBooks API: refresh token missing or invalid', array( 'code' => 'no_refresh_token' ) );
				return new WP_Error( 'no_refresh_token', __( 'QuickBooks session expired. Please reconnect QuickBooks.', 'remember' ) );
			}
			$refresh_result = Remember_QuickBooks_OAuth::refresh_token(
				$refresh_token,
				$settings['client_id'],
				$settings['client_secret']
			);

			if ( is_wp_error( $refresh_result ) ) {
				Remember_Logger::error(
					'QuickBooks API: token refresh failed',
					array(
						'code'    => $refresh_result->get_error_code(),
						'message' => $refresh_result->get_error_message(),
					)
				);
				return $refresh_result;
			}

			// Update settings with new tokens
			$settings['access_token'] = $refresh_result['access_token'];
			$settings['refresh_token'] = isset( $refresh_result['refresh_token'] ) ? $refresh_result['refresh_token'] : $settings['refresh_token'];
			$rin = isset( $refresh_result['expires_in'] ) ? (int) $refresh_result['expires_in'] : 3600;
			if ( $rin < 60 ) {
				$rin = 3600;
			}
			$settings['expires_at'] = time() + $rin;
			Remember_QuickBooks_OAuth::save_settings( $settings );

			return trim( (string) $settings['access_token'] );
		}

		return $access;
	}

	/**
	 * Human-readable message from QuickBooks JSON error body (handles Intuit lowercase fault/error shape).
	 *
	 * @param array|null $body Decoded JSON body.
	 * @return string
	 */
	private static function parse_qb_error_message( $body ) {
		if ( ! is_array( $body ) ) {
			return __( 'QuickBooks API error.', 'remember' );
		}

		// Legacy XML-style JSON (Fault / Error / Message).
		if ( ! empty( $body['Fault']['Error'][0]['Message'] ) ) {
			return (string) $body['Fault']['Error'][0]['Message'];
		}

		// REST JSON (fault / error[] with detail or message; keys may be mixed case).
		$fault = isset( $body['fault'] ) ? $body['fault'] : ( isset( $body['Fault'] ) ? $body['Fault'] : null );
		if ( is_array( $fault ) ) {
			$errors = isset( $fault['error'] ) ? $fault['error'] : ( isset( $fault['Error'] ) ? $fault['Error'] : null );
			if ( is_array( $errors ) && ! empty( $errors[0] ) && is_array( $errors[0] ) ) {
				$err = $errors[0];
				if ( ! empty( $err['Detail'] ) ) {
					return (string) $err['Detail'];
				}
				if ( ! empty( $err['detail'] ) ) {
					return (string) $err['detail'];
				}
				if ( ! empty( $err['Message'] ) ) {
					return (string) $err['Message'];
				}
				if ( ! empty( $err['message'] ) ) {
					return (string) $err['message'];
				}
			}
		}

		return __( 'QuickBooks API error.', 'remember' );
	}

	/**
	 * Make API request.
	 *
	 * @param string $method HTTP method (GET, POST, etc.).
	 * @param string $endpoint API endpoint.
	 * @param array  $data Request data.
	 * @return array|WP_Error Response data or error.
	 */
	private static function api_request( $method, $endpoint, $data = array() ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$company_id = self::get_company_id();
		if ( ! $company_id ) {
			Remember_Logger::error(
				'QuickBooks API: no company (realm) ID',
				array( 'code' => 'no_company_id' )
			);
			return new WP_Error( 'no_company_id', __( 'No company ID available. Please reconnect QuickBooks.', 'remember' ) );
		}

		$url = self::get_api_base() . $company_id . '/' . $endpoint;
		$url .= ( strpos( $url, '?' ) !== false ? '&' : '?' ) . 'minorversion=' . self::QB_MINOR_VERSION;

		$qb_opts = Remember_QuickBooks_OAuth::get_settings();
		$qb_env  = isset( $qb_opts['environment'] ) ? $qb_opts['environment'] : 'unknown';

		$token = trim( (string) $token );

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'          => 'application/json',
			),
			'timeout' => 60,
		);

		if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Remember_Logger::error(
				'QuickBooks API: HTTP transport error',
				array(
					'method'   => $method,
					'endpoint' => $endpoint,
					'code'     => $response->get_error_code(),
					'message'  => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );

		if ( $status_code >= 400 ) {
			$error_message = self::parse_qb_error_message( $body );

			$body_for_log = is_array( $body ) ? $body : $raw_body;
			$encoded      = is_string( $body_for_log ) ? $body_for_log : wp_json_encode( $body_for_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( is_string( $encoded ) && strlen( $encoded ) > 6000 ) {
				$encoded = substr( $encoded, 0, 6000 ) . '...<truncated>';
			}

			Remember_Logger::error(
				'QuickBooks API: error response',
				array(
					'method'        => $method,
					'endpoint'      => $endpoint,
					'status'        => $status_code,
					'qb_message'    => $error_message,
					'response_body' => $encoded,
					'environment'   => $qb_env,
					'api_base'      => self::get_api_base(),
					'realm_id'      => substr( (string) $company_id, 0, 6 ) . '…',
				)
			);

			return new WP_Error(
				'qb_api_error',
				$error_message,
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
		}

		return $body;
	}

	/**
	 * Normalize QueryResponse Customer list to a numeric array of customer rows.
	 *
	 * @param array $response Decoded API response.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_customer_query_response( $response ) {
		if ( ! isset( $response['QueryResponse']['Customer'] ) ) {
			return array();
		}
		$raw = $response['QueryResponse']['Customer'];
		if ( ! is_array( $raw ) ) {
			return array();
		}
		if ( isset( $raw['Id'] ) ) {
			return array( $raw );
		}
		return $raw;
	}

	/**
	 * Fetch a single Customer by Id (includes current SyncToken).
	 *
	 * @param string $customer_id QuickBooks Customer Id.
	 * @return array|WP_Error Customer entity or error.
	 */
	public static function get_customer( $customer_id ) {
		$customer_id = trim( (string) $customer_id );
		if ( '' === $customer_id ) {
			return new WP_Error( 'invalid_customer_id', __( 'Invalid QuickBooks customer ID.', 'remember' ) );
		}
		$response = self::api_request( 'GET', 'customer/' . rawurlencode( $customer_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['Customer'] ) ? $response['Customer'] : $response;
	}

	/**
	 * Find customers whose PrimaryEmailAddr matches (first match wins).
	 *
	 * @param string $email Email address.
	 * @return array|null Customer row or null if none.
	 */
	public static function find_customer_by_primary_email( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$escaped = str_replace( "'", "''", $email );
		$query     = "SELECT * FROM Customer WHERE PrimaryEmailAddr = '" . $escaped . "'";
		$response  = self::api_request( 'GET', 'query?query=' . rawurlencode( $query ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		foreach ( self::normalize_customer_query_response( $response ) as $row ) {
			$found = isset( $row['PrimaryEmailAddr']['Address'] ) ? (string) $row['PrimaryEmailAddr']['Address'] : '';
			if ( 0 === strcasecmp( trim( $found ), $email ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Create or update customer in QuickBooks.
	 *
	 * @param array $customer_data Customer data.
	 * @return array|WP_Error Customer data or error.
	 */
	public static function create_customer( $customer_data ) {
		$data = array(
			'DisplayName' => $customer_data['display_name'],
			'GivenName'    => $customer_data['first_name'],
			'FamilyName'   => $customer_data['last_name'],
		);

		if ( ! empty( $customer_data['email'] ) ) {
			$data['PrimaryEmailAddr'] = array( 'Address' => $customer_data['email'] );
		}

		if ( ! empty( $customer_data['phone'] ) ) {
			$data['PrimaryPhone'] = array( 'FreeFormNumber' => $customer_data['phone'] );
		}

		// Notes belong to the bookkeeper once the customer exists, so they are only set on create.
		if ( empty( $customer_data['qb_customer_id'] ) && ! empty( $customer_data['notes'] ) ) {
			$data['Notes'] = $customer_data['notes'];
		}

		if ( ! empty( $customer_data['address'] ) ) {
			$bill_addr = array(
				'Line1'                  => isset( $customer_data['address']['street'] ) ? $customer_data['address']['street'] : '',
				'City'                   => isset( $customer_data['address']['city'] ) ? $customer_data['address']['city'] : '',
				'CountrySubDivisionCode' => isset( $customer_data['address']['state'] ) ? $customer_data['address']['state'] : '',
				'PostalCode'             => isset( $customer_data['address']['postal'] ) ? $customer_data['address']['postal'] : '',
				'Country'                => isset( $customer_data['address']['country'] ) ? $customer_data['address']['country'] : '',
			);
			$bill_addr = array_filter(
				$bill_addr,
				function( $value ) {
					return '' !== trim( (string) $value );
				}
			);
			if ( ! empty( $bill_addr ) ) {
				$data['BillAddr'] = $bill_addr;
				$data['ShipAddr'] = $bill_addr;
			}
		}

		// Updates require a current SyncToken from QBO (stored tokens go stale after edits in QBO or missing meta).
		if ( ! empty( $customer_data['qb_customer_id'] ) ) {
			$existing = self::get_customer( $customer_data['qb_customer_id'] );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			$existing_email = '';
			if ( ! empty( $existing['PrimaryEmailAddr']['Address'] ) ) {
				$existing_email = (string) $existing['PrimaryEmailAddr']['Address'];
			}
			$next_email = isset( $customer_data['email'] ) ? (string) $customer_data['email'] : '';
			if ( '' !== $next_email && strtolower( $existing_email ) !== strtolower( $next_email ) ) {
				require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';
				Remember_Logger::info(
					'Updating QuickBooks customer email',
					array( 'qb_customer_id' => $customer_data['qb_customer_id'] )
				);
				Remember_Logger::debug(
					'Updating QuickBooks customer email',
					array(
						'qb_customer_id' => $customer_data['qb_customer_id'],
						'from'           => $existing_email,
						'to'             => $next_email,
					)
				);
			}
			// A full update blanks every writable field left out, wiping terms, notes and the like set in QBO.
			$data['sparse']    = true;
			$data['Id']        = $customer_data['qb_customer_id'];
			$data['SyncToken'] = isset( $existing['SyncToken'] ) ? (string) $existing['SyncToken'] : '0';
			if ( isset( $data['ShipAddr'] ) && ! self::ship_address_follows_billing( $existing ) ) {
				unset( $data['ShipAddr'] );
			}
			$endpoint = 'customer';
			$method   = 'POST';
		} else {
			$endpoint = 'customer';
			$method   = 'POST';
		}

		// QBO v3 expects the Customer object as the top-level payload.
		$response = self::api_request( $method, $endpoint, $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['Customer'] ) ? $response['Customer'] : $response;
	}

	/**
	 * Whether a customer's shipping address is empty or still mirrors the billing address.
	 *
	 * @param array $customer Customer entity from QBO.
	 * @return bool True when reMember may keep the shipping address in step with billing.
	 */
	private static function ship_address_follows_billing( $customer ) {
		$fields = array( 'Line1', 'Line2', 'City', 'CountrySubDivisionCode', 'PostalCode', 'Country' );
		$pick   = static function ( $addr ) use ( $fields ) {
			$out = array();
			foreach ( $fields as $field ) {
				$out[ $field ] = is_array( $addr ) && isset( $addr[ $field ] ) ? strtolower( trim( (string) $addr[ $field ] ) ) : '';
			}
			return $out;
		};

		$ship = $pick( $customer['ShipAddr'] ?? null );
		if ( '' === implode( '', $ship ) ) {
			return true;
		}
		return $ship === $pick( $customer['BillAddr'] ?? null );
	}

	/**
	 * Email an invoice to the customer (QuickBooks Online).
	 *
	 * @param string      $invoice_id Invoice Id.
	 * @param string|null $send_to    Optional override email; omit to use customer email on file.
	 * @return true|WP_Error
	 */
	public static function email_invoice( $invoice_id, $send_to = null ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'qb_email_no_id', __( 'Missing QuickBooks invoice ID.', 'remember' ) );
		}

		$endpoint = 'invoice/' . rawurlencode( $invoice_id ) . '/send';
		$send_to  = is_string( $send_to ) ? trim( $send_to ) : '';
		if ( '' !== $send_to && is_email( $send_to ) ) {
			$endpoint .= '?sendTo=' . rawurlencode( $send_to );
		}

		$response = self::api_request( 'POST', $endpoint );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Remember_Logger::info( 'QuickBooks invoice email requested', array( 'invoice_id' => $invoice_id ) );
		return true;
	}

	/**
	 * Create invoice in QuickBooks.
	 *
	 * @param array $invoice_data Invoice data.
	 * @return array|WP_Error Invoice data or error.
	 */
	public static function create_invoice( $invoice_data ) {
		$data = array(
			'CustomerRef' => array(
				'value' => $invoice_data['customer_id'],
			),
			'Line' => array(),
		);

		if ( ! empty( $invoice_data['memo'] ) ) {
			$data['CustomerMemo'] = array( 'value' => substr( (string) $invoice_data['memo'], 0, 1000 ) );
		}
		// QBO does not copy the customer's email onto API-created invoices; its reminders need one.
		if ( ! empty( $invoice_data['bill_email'] ) && is_email( $invoice_data['bill_email'] ) ) {
			$data['BillEmail'] = array( 'Address' => (string) $invoice_data['bill_email'] );
		}

		foreach ( $invoice_data['line_items'] as $item ) {
			$amount      = round( floatval( $item['amount'] ), 2 );
			$qty         = max( 1, intval( $item['quantity'] ?? 1 ) );
			$description = ! empty( $item['description'] ) ? (string) $item['description'] : '';

			// QBO rejects a line whose Amount is not Qty x UnitPrice, so fall back to one unit at the total.
			$unit_price = round( $amount / $qty, 2 );
			if ( abs( $unit_price * $qty - $amount ) > 0.001 ) {
				$description = trim( sprintf( '%d x %s', $qty, $description ) );
				$qty         = 1;
				$unit_price  = $amount;
			}

			$line_item = array(
				'Amount'              => $amount,
				'DetailType'          => 'SalesItemLineDetail',
				'SalesItemLineDetail' => array(
					'ItemRef'   => array(
						'value' => $item['product_id'],
					),
					'Qty'       => $qty,
					'UnitPrice' => $unit_price,
				),
			);

			if ( '' !== $description ) {
				$line_item['Description'] = $description;
			}

			$data['Line'][] = $line_item;
		}

		// QBO v3 expects the Invoice object as the top-level payload.
		$response = self::api_request( 'POST', 'invoice', $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['Invoice'] ) ? $response['Invoice'] : $response;
	}

	/**
	 * Void a QuickBooks invoice.
	 *
	 * @param string $invoice_id Invoice Id.
	 * @return array|WP_Error Voided invoice payload or error.
	 */
	public static function void_invoice( $invoice_id ) {
		$invoice = self::get_invoice( $invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}
		if ( empty( $invoice['Id'] ) || ! isset( $invoice['SyncToken'] ) ) {
			return new WP_Error( 'qb_void_missing_token', __( 'QuickBooks invoice is missing Id/SyncToken for void.', 'remember' ) );
		}

		$response = self::api_request(
			'POST',
			'invoice?operation=void',
			array(
				'Id'        => (string) $invoice['Id'],
				'SyncToken' => (string) $invoice['SyncToken'],
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['Invoice'] ) ? $response['Invoice'] : $response;
	}

	/**
	 * Whether a QuickBooks invoice entity has been voided.
	 *
	 * Voided invoices still GET successfully; TotalAmt is zeroed and PrivateNote often says "Voided".
	 *
	 * @param array      $invoice     Invoice payload.
	 * @param float|null $local_total Local invoice total; used when TotalAmt is 0 after a void.
	 * @return bool
	 */
	public static function is_invoice_voided( $invoice, $local_total = null ) {
		if ( ! is_array( $invoice ) ) {
			return false;
		}
		$note = isset( $invoice['PrivateNote'] ) ? strtolower( (string) $invoice['PrivateNote'] ) : '';
		if ( false !== strpos( $note, 'voided' ) ) {
			return true;
		}
		$total = floatval( $invoice['TotalAmt'] ?? 0 );
		if ( $total <= 0.001 && null !== $local_total && floatval( $local_total ) > 0.001 ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a QBO payment / refund / credit memo looks voided or deleted.
	 *
	 * @param array $txn Transaction payload.
	 * @return bool
	 */
	public static function is_txn_voided( $txn ) {
		if ( ! is_array( $txn ) ) {
			return true;
		}
		$note = isset( $txn['PrivateNote'] ) ? strtolower( (string) $txn['PrivateNote'] ) : '';
		if ( false !== strpos( $note, 'voided' ) ) {
			return true;
		}
		if ( isset( $txn['TotalAmt'] ) && floatval( $txn['TotalAmt'] ) <= 0.001 ) {
			return true;
		}
		return false;
	}

	/**
	 * Deep link into QuickBooks Online UI for an invoice (staff browser).
	 *
	 * @param string $invoice_id QBO Invoice Id (entity Id, not DocNumber).
	 * @return string Absolute URL, or empty if no id.
	 */
	public static function get_invoice_app_url( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return '';
		}

		$settings    = Remember_QuickBooks_OAuth::get_settings();
		$environment = ( is_array( $settings ) && ! empty( $settings['environment'] ) )
			? (string) $settings['environment']
			: 'sandbox';
		$host        = ( 'production' === $environment )
			? 'https://app.qbo.intuit.com'
			: 'https://app.sandbox.qbo.intuit.com';

		return $host . '/app/invoice?txnId=' . rawurlencode( $invoice_id );
	}

	/**
	 * Get invoice by ID.
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return array|WP_Error Invoice data or error.
	 */
	public static function get_invoice( $invoice_id ) {
		$response = self::api_request( 'GET', 'invoice/' . rawurlencode( trim( (string) $invoice_id ) ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['QueryResponse']['Invoice'][0] ) ) {
			return $response['QueryResponse']['Invoice'][0];
		}
		if ( isset( $response['Invoice'] ) && is_array( $response['Invoice'] ) ) {
			return $response['Invoice'];
		}
		return new WP_Error( 'qb_invoice_unreadable', __( 'QuickBooks did not return the invoice.', 'remember' ) );
	}

	/**
	 * Get payment for invoice.
	 *
	 * @param string      $invoice_id  Invoice ID.
	 * @param string|null $customer_id Optional QuickBooks Customer Id to narrow the search.
	 * @return array|WP_Error Payment data or error.
	 */
	public static function get_invoice_payment( $invoice_id, $customer_id = null ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'invalid_invoice_id', __( 'Invalid QuickBooks invoice ID.', 'remember' ) );
		}

		// First, pull a reasonably-scoped set of payments from QBO. We can't reliably filter on
		// Line.LinkedTxn in the SQL WHERE clause across all environments, so we:
		// 1) Optionally narrow by CustomerRef when a customer_id is available.
		// 2) Filter by LinkedTxn.TxnId = invoice_id in PHP.
		$customer_id = $customer_id ? trim( (string) $customer_id ) : '';
		$where_parts = array();
		if ( '' !== $customer_id ) {
			$where_parts[] = "CustomerRef = '" . $customer_id . "'";
		}

		$query = 'SELECT * FROM Payment';
		if ( ! empty( $where_parts ) ) {
			$query .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		$response = self::api_request( 'GET', 'query?query=' . rawurlencode( $query ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['QueryResponse']['Payment'] ) ) {
			return array();
		}

		$raw = $response['QueryResponse']['Payment'];
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Normalize single Payment → list of one.
		$payments = isset( $raw['Id'] ) ? array( $raw ) : $raw;

		$matches = array();
		foreach ( $payments as $payment ) {
			if ( ! is_array( $payment ) || ! isset( $payment['Line'] ) ) {
				continue;
			}
			$lines = $payment['Line'];
			if ( isset( $lines['Amount'] ) ) {
				$lines = array( $lines );
			}
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( $lines as $line ) {
				if ( ! is_array( $line ) || ! isset( $line['LinkedTxn'] ) ) {
					continue;
				}
				$linked = $line['LinkedTxn'];
				if ( isset( $linked['TxnId'] ) ) {
					$linked = array( $linked );
				}
				if ( ! is_array( $linked ) ) {
					continue;
				}
				foreach ( $linked as $lt ) {
					if ( isset( $lt['TxnId'] ) && (string) $lt['TxnId'] === $invoice_id ) {
						$matches[] = $payment;
						// Stop scanning this Payment's lines only; other Payment entities may also apply.
						break 2;
					}
				}
			}
		}

		return $matches;
	}

	/**
	 * Best-effort Unix timestamp for ordering entities (CreateTime preferred, then LastUpdatedTime, then TxnDate).
	 *
	 * @param array $entity QuickBooks Invoice or Payment payload.
	 * @return int
	 */
	public static function qb_entity_sort_timestamp( $entity ) {
		if ( ! is_array( $entity ) ) {
			return 0;
		}
		// Prefer document date for ledger chronology over sync timestamps.
		if ( ! empty( $entity['TxnDate'] ) ) {
			$t = strtotime( $entity['TxnDate'] . ' 12:00:00' );
			if ( $t ) {
				return $t;
			}
		}
		if ( ! empty( $entity['MetaData']['CreateTime'] ) ) {
			$t = strtotime( $entity['MetaData']['CreateTime'] );
			if ( $t ) {
				return $t;
			}
		}
		if ( ! empty( $entity['MetaData']['LastUpdatedTime'] ) ) {
			$t = strtotime( $entity['MetaData']['LastUpdatedTime'] );
			if ( $t ) {
				return $t;
			}
		}
		return 0;
	}

	/**
	 * Per–QuickBooks Payment rows applied to an invoice (multiple partial payments = multiple rows).
	 *
	 * @param string      $invoice_id  Invoice entity Id.
	 * @param string|null $customer_id Optional QuickBooks Customer Id.
	 * @return array|WP_Error List of rows with keys amount, txn_date, payment_method, qb_payment_id.
	 */
	public static function get_invoice_payment_lines( $invoice_id, $customer_id = null ) {
		$payments = self::get_invoice_payment( $invoice_id, $customer_id );
		if ( is_wp_error( $payments ) ) {
			return $payments;
		}

		$invoice_id = trim( (string) $invoice_id );
		$out          = array();

		foreach ( $payments as $payment ) {
			$row = self::summarize_payment_for_invoice( $payment, $invoice_id );
			if ( null !== $row ) {
				$out[] = $row;
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				$ta = isset( $a['sort_ts'] ) ? (int) $a['sort_ts'] : strtotime( $a['txn_date'] ?? '' );
				$tb = isset( $b['sort_ts'] ) ? (int) $b['sort_ts'] : strtotime( $b['txn_date'] ?? '' );
				if ( $ta === $tb ) {
					return strcmp( (string) ( $a['qb_payment_id'] ?? '' ), (string) ( $b['qb_payment_id'] ?? '' ) );
				}
				return $ta <=> $tb;
			}
		);

		return $out;
	}

	/**
	 * One row per Payment entity: amount applied to this invoice, date, method, Id.
	 *
	 * @param array  $payment    QuickBooks Payment payload.
	 * @param string $invoice_id Invoice entity Id.
	 * @return array<string, mixed>|null
	 */
	private static function summarize_payment_for_invoice( $payment, $invoice_id ) {
		if ( ! is_array( $payment ) || ! isset( $payment['Line'] ) ) {
			return null;
		}
		if ( self::is_txn_voided( $payment ) ) {
			return null;
		}

		$lines = $payment['Line'];
		if ( isset( $lines['Amount'] ) ) {
			$lines = array( $lines );
		}
		if ( ! is_array( $lines ) ) {
			return null;
		}

		$amount_for_invoice = 0.0;
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['LinkedTxn'] ) ) {
				continue;
			}
			$linked = $line['LinkedTxn'];
			if ( isset( $linked['TxnId'] ) ) {
				$linked = array( $linked );
			}
			if ( ! is_array( $linked ) ) {
				continue;
			}
			foreach ( $linked as $lt ) {
				if ( isset( $lt['TxnId'] ) && (string) $lt['TxnId'] === $invoice_id ) {
					$amount_for_invoice += isset( $line['Amount'] ) ? floatval( $line['Amount'] ) : floatval( $payment['TotalAmt'] ?? 0 );
				}
			}
		}

		if ( $amount_for_invoice <= 0 ) {
			return null;
		}

		return array(
			'amount'          => $amount_for_invoice,
			'txn_date'        => isset( $payment['TxnDate'] ) ? sanitize_text_field( (string) $payment['TxnDate'] ) : '',
			'payment_method'  => self::qb_payment_method_label( $payment ),
			'qb_payment_id'   => isset( $payment['Id'] ) ? (string) $payment['Id'] : '',
			'sort_ts'         => self::qb_entity_sort_timestamp( $payment ),
		);
	}

	/**
	 * Human-readable payment method from a QuickBooks Payment payload.
	 *
	 * @param array $payment Payment array.
	 * @return string
	 */
	private static function qb_payment_method_label( $payment ) {
		if ( ! is_array( $payment ) ) {
			return '';
		}
		if ( isset( $payment['PaymentMethodRef']['name'] ) ) {
			return sanitize_text_field( (string) $payment['PaymentMethodRef']['name'] );
		}
		// Do not show raw numeric method IDs when QBO omits the name (falls back to "QuickBooks" in UI).
		if ( isset( $payment['PaymentMethodRef']['value'] ) ) {
			$v = (string) $payment['PaymentMethodRef']['value'];
			if ( $v !== '' && preg_match( '/^\d+$/', $v ) ) {
				return '';
			}
			return sanitize_text_field( $v );
		}
		return '';
	}

	/**
	 * Fetch a single RefundReceipt by Id.
	 *
	 * @param string $refund_receipt_id QuickBooks RefundReceipt Id.
	 * @return array|WP_Error
	 */
	public static function get_refund_receipt( $refund_receipt_id ) {
		$refund_receipt_id = trim( (string) $refund_receipt_id );
		if ( '' === $refund_receipt_id ) {
			return new WP_Error( 'invalid_refund_receipt_id', __( 'Invalid QuickBooks refund receipt ID.', 'remember' ) );
		}
		$response = self::api_request( 'GET', 'refundreceipt/' . rawurlencode( $refund_receipt_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['RefundReceipt'] ) ? $response['RefundReceipt'] : $response;
	}

	/**
	 * One row for a RefundReceipt when the Invoice already links to it (use full document total).
	 *
	 * @param array $refund QuickBooks RefundReceipt payload.
	 * @return array<string, mixed>|null
	 */
	private static function summarize_refund_receipt_row( $refund ) {
		if ( ! is_array( $refund ) || self::is_txn_voided( $refund ) ) {
			return null;
		}
		$amt = floatval( $refund['TotalAmt'] ?? 0 );
		if ( $amt <= 0 ) {
			return null;
		}
		return array(
			'amount'         => $amt,
			'txn_date'       => isset( $refund['TxnDate'] ) ? sanitize_text_field( (string) $refund['TxnDate'] ) : '',
			'payment_method' => self::qb_payment_method_label( $refund ),
			'qb_refund_id'   => isset( $refund['Id'] ) ? (string) $refund['Id'] : '',
			'doc_number'     => isset( $refund['DocNumber'] ) ? sanitize_text_field( (string) $refund['DocNumber'] ) : '',
			'sort_ts'        => self::qb_entity_sort_timestamp( $refund ),
		);
	}

	/**
	 * QuickBooks Payment Ids that apply to this invoice (refund receipts often link to Payment, not Invoice).
	 *
	 * @param string      $invoice_id  Invoice entity Id.
	 * @param string|null $customer_id QuickBooks Customer Id.
	 * @return array<int, string>
	 */
	private static function collect_qb_payment_ids_for_invoice( $invoice_id, $customer_id ) {
		$payments = self::get_invoice_payment( $invoice_id, $customer_id );
		if ( is_wp_error( $payments ) || ! is_array( $payments ) ) {
			return array();
		}
		$ids = array();
		foreach ( $payments as $p ) {
			if ( ! is_array( $p ) || empty( $p['Id'] ) || self::is_txn_voided( $p ) ) {
				continue;
			}
			$ids[] = (string) $p['Id'];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether a LinkedTxn on a refund line/document targets this invoice or one of its payments.
	 *
	 * @param array $lt            LinkedTxn element.
	 * @param string $invoice_id   Invoice Id.
	 * @param array $qb_payment_ids Payment Ids from collect_qb_payment_ids_for_invoice().
	 * @return bool
	 */
	private static function refund_linked_txn_matches_invoice_or_payment( $lt, $invoice_id, array $qb_payment_ids ) {
		if ( ! is_array( $lt ) || ! isset( $lt['TxnId'] ) ) {
			return false;
		}
		$txn_id = (string) $lt['TxnId'];
		$tt     = isset( $lt['TxnType'] ) ? (string) $lt['TxnType'] : '';

		if ( $txn_id === (string) $invoice_id ) {
			return ( '' === $tt || false !== stripos( $tt, 'Invoice' ) );
		}

		// Payment Id is unambiguous; TxnType may be missing or vendor-specific.
		if ( ! empty( $qb_payment_ids ) && in_array( $txn_id, $qb_payment_ids, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether any nested LinkedTxn in a line (e.g. under SalesItemLineDetail) matches invoice/payment.
	 *
	 * @param array $node Line or subtree.
	 * @param string $invoice_id Invoice Id.
	 * @param array $qb_payment_ids Payment Ids.
	 * @return bool
	 */
	private static function refund_array_contains_matching_linked_txn( $node, $invoice_id, array $qb_payment_ids ) {
		if ( ! is_array( $node ) ) {
			return false;
		}
		if ( isset( $node['LinkedTxn'] ) ) {
			$linked = $node['LinkedTxn'];
			if ( isset( $linked['TxnId'] ) ) {
				$linked = array( $linked );
			}
			foreach ( (array) $linked as $lt ) {
				if ( is_array( $lt ) && self::refund_linked_txn_matches_invoice_or_payment( $lt, $invoice_id, $qb_payment_ids ) ) {
					return true;
				}
			}
		}
		foreach ( $node as $key => $child ) {
			if ( 'LinkedTxn' === $key || ! is_array( $child ) ) {
				continue;
			}
			if ( self::refund_array_contains_matching_linked_txn( $child, $invoice_id, $qb_payment_ids ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Line amount when LinkedTxn appears only on nested detail objects (QBO often nests under SalesItemLineDetail).
	 *
	 * @param array $line RefundReceipt Line element.
	 * @param string $invoice_id Invoice Id.
	 * @param array $qb_payment_ids Payment Ids.
	 * @return float
	 */
	private static function refund_line_amount_with_nested_linked_txn( $line, $invoice_id, array $qb_payment_ids ) {
		if ( ! is_array( $line ) ) {
			return 0.0;
		}
		if ( ! self::refund_array_contains_matching_linked_txn( $line, $invoice_id, $qb_payment_ids ) ) {
			return 0.0;
		}
		return isset( $line['Amount'] ) ? floatval( $line['Amount'] ) : 0.0;
	}

	/**
	 * Amount from RefundReceipt line(s) applied to a specific Invoice.
	 *
	 * Refunds created from “Refund receipt” in QBO usually reference the Payment entity (#153, #154),
	 * not the Invoice — we match both Invoice and Payment LinkedTxn.
	 *
	 * @param array  $refund           QuickBooks RefundReceipt payload.
	 * @param string $invoice_id       Invoice entity Id.
	 * @param array  $qb_payment_ids   QuickBooks Payment Ids applied to this invoice.
	 * @return array<string, mixed>|null
	 */
	private static function summarize_refund_for_invoice( $refund, $invoice_id, array $qb_payment_ids = array() ) {
		if ( ! is_array( $refund ) ) {
			return null;
		}
		if ( self::is_txn_voided( $refund ) ) {
			return null;
		}
		$invoice_id = (string) $invoice_id;
		$amount     = 0.0;

		// Document-level LinkedTxn (some companies populate this).
		if ( ! empty( $refund['LinkedTxn'] ) ) {
			$linked = $refund['LinkedTxn'];
			if ( isset( $linked['TxnId'] ) ) {
				$linked = array( $linked );
			}
			if ( is_array( $linked ) ) {
				foreach ( $linked as $lt ) {
					if ( self::refund_linked_txn_matches_invoice_or_payment( $lt, $invoice_id, $qb_payment_ids ) ) {
						$amount = floatval( $refund['TotalAmt'] ?? 0 );
						break;
					}
				}
			}
		}

		if ( $amount <= 0 && ! empty( $refund['Line'] ) ) {
			$lines = $refund['Line'];
			if ( isset( $lines['Amount'] ) ) {
				$lines = array( $lines );
			}
			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					if ( ! is_array( $line ) ) {
						continue;
					}
					if ( ! empty( $line['LinkedTxn'] ) ) {
						$linked = $line['LinkedTxn'];
						if ( isset( $linked['TxnId'] ) ) {
							$linked = array( $linked );
						}
						if ( is_array( $linked ) ) {
							foreach ( $linked as $lt ) {
								if ( self::refund_linked_txn_matches_invoice_or_payment( $lt, $invoice_id, $qb_payment_ids ) ) {
									$amount += isset( $line['Amount'] ) ? floatval( $line['Amount'] ) : 0.0;
								}
							}
						}
					}
				}
			}
		}

		// LinkedTxn often lives under SalesItemLineDetail (or other nested detail), not on the Line root.
		if ( $amount <= 0 && ! empty( $refund['Line'] ) ) {
			$lines = $refund['Line'];
			if ( isset( $lines['Amount'] ) ) {
				$lines = array( $lines );
			}
			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					if ( ! is_array( $line ) ) {
						continue;
					}
					$nested_add = self::refund_line_amount_with_nested_linked_txn( $line, $invoice_id, $qb_payment_ids );
					if ( $nested_add > 0 ) {
						$amount += $nested_add;
					}
				}
			}
		}

		if ( $amount <= 0 ) {
			return null;
		}

		return array(
			'amount'         => $amount,
			'txn_date'       => isset( $refund['TxnDate'] ) ? sanitize_text_field( (string) $refund['TxnDate'] ) : '',
			'payment_method' => self::qb_payment_method_label( $refund ),
			'qb_refund_id'   => isset( $refund['Id'] ) ? (string) $refund['Id'] : '',
			'doc_number'     => isset( $refund['DocNumber'] ) ? sanitize_text_field( (string) $refund['DocNumber'] ) : '',
			'sort_ts'        => self::qb_entity_sort_timestamp( $refund ),
		);
	}

	/**
	 * Refund receipt rows in QuickBooks that apply to this invoice (for billing register + net paid).
	 *
	 * @param string      $invoice_id  Invoice entity Id.
	 * @param string|null $customer_id QuickBooks Customer Id (narrows RefundReceipt query).
	 * @return array|WP_Error List of rows with keys amount, txn_date, payment_method, qb_refund_id, doc_number, sort_ts.
	 */
	public static function get_invoice_refund_lines( $invoice_id, $customer_id = null ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'invalid_invoice_id', __( 'Invalid QuickBooks invoice ID.', 'remember' ) );
		}

		$out  = array();
		$seen = array();

		$invoice = self::get_invoice( $invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		// Prefer user meta; fall back to Invoice.CustomerRef so RefundReceipt queries still run if meta is empty.
		$customer_id_resolved = $customer_id ? trim( (string) $customer_id ) : '';
		if ( '' === $customer_id_resolved && ! empty( $invoice['CustomerRef']['value'] ) ) {
			$customer_id_resolved = (string) $invoice['CustomerRef']['value'];
		}

		$qb_payment_ids = self::collect_qb_payment_ids_for_invoice( $invoice_id, $customer_id_resolved );

		// 1) Invoice → LinkedTxn → RefundReceipt (reliable when QBO links the refund on the invoice).
		if ( ! empty( $invoice['LinkedTxn'] ) ) {
			$linked = $invoice['LinkedTxn'];
			if ( isset( $linked['TxnId'] ) ) {
				$linked = array( $linked );
			}
			if ( is_array( $linked ) ) {
				foreach ( $linked as $lt ) {
					if ( ! is_array( $lt ) || empty( $lt['TxnId'] ) ) {
						continue;
					}
					$tt = isset( $lt['TxnType'] ) ? (string) $lt['TxnType'] : '';
					if ( false === stripos( $tt, 'Refund' ) ) {
						continue;
					}
					$rid = (string) $lt['TxnId'];
					if ( '' === $rid || isset( $seen[ $rid ] ) ) {
						continue;
					}
					$rr = self::get_refund_receipt( $rid );
					if ( is_wp_error( $rr ) || ! is_array( $rr ) ) {
						continue;
					}
					$seen[ $rid ] = true;
					$row = self::summarize_refund_receipt_row( $rr );
					if ( null !== $row ) {
						$out[] = $row;
					}
				}
			}
		}

		// 2) RefundReceipt WHERE CustomerRef — match line/document links to this invoice.
		$refund_query_list = array();
		if ( '' !== $customer_id_resolved ) {
			$query    = "SELECT * FROM RefundReceipt WHERE CustomerRef = '" . esc_sql( $customer_id_resolved ) . "'";
			$response = self::api_request( 'GET', 'query?query=' . rawurlencode( $query ) );
			if ( ! is_wp_error( $response ) && isset( $response['QueryResponse']['RefundReceipt'] ) ) {
				$raw  = $response['QueryResponse']['RefundReceipt'];
				$list = isset( $raw['Id'] ) ? array( $raw ) : ( is_array( $raw ) ? $raw : array() );
				$refund_query_list = $list;
				foreach ( $list as $r ) {
					if ( ! is_array( $r ) || empty( $r['Id'] ) ) {
						continue;
					}
					$rid = (string) $r['Id'];
					if ( isset( $seen[ $rid ] ) ) {
						continue;
					}
					$full = self::get_refund_receipt( $rid );
					$rr   = is_wp_error( $full ) ? $r : $full;
					$row  = self::summarize_refund_for_invoice( $rr, $invoice_id, $qb_payment_ids );
					if ( null !== $row ) {
						$seen[ $rid ] = true;
						$out[]        = $row;
					}
				}
			}
		}

		// 3) Single RefundReceipt for this customer and no parseable LinkedTxn — attribute to this invoice (typical one-invoice customers).
		if ( empty( $out ) && 1 === count( $refund_query_list ) ) {
			$r = $refund_query_list[0];
			if ( is_array( $r ) && ! empty( $r['Id'] ) ) {
				$rid = (string) $r['Id'];
				if ( ! isset( $seen[ $rid ] ) ) {
					$full = self::get_refund_receipt( $rid );
					$rr   = is_wp_error( $full ) ? $r : $full;
					$row  = self::summarize_refund_receipt_row( $rr );
					if ( null !== $row ) {
						$out[] = $row;
					}
				}
			}
		}

		// 4) Credit memos applied to this invoice reduce Amount Due (ledger credit).
		if ( '' !== $customer_id_resolved ) {
			$cm_query = "SELECT * FROM CreditMemo WHERE CustomerRef = '" . esc_sql( $customer_id_resolved ) . "'";
			$cm_resp  = self::api_request( 'GET', 'query?query=' . rawurlencode( $cm_query ) );
			if ( ! is_wp_error( $cm_resp ) && isset( $cm_resp['QueryResponse']['CreditMemo'] ) ) {
				$raw  = $cm_resp['QueryResponse']['CreditMemo'];
				$list = isset( $raw['Id'] ) ? array( $raw ) : ( is_array( $raw ) ? $raw : array() );
				foreach ( $list as $memo ) {
					$row = self::summarize_credit_memo_for_invoice( $memo, $invoice_id );
					if ( null === $row ) {
						continue;
					}
					$rid = isset( $row['qb_refund_id'] ) ? (string) $row['qb_refund_id'] : '';
					if ( '' !== $rid && isset( $seen[ $rid ] ) ) {
						continue;
					}
					if ( '' !== $rid ) {
						$seen[ $rid ] = true;
					}
					$out[] = $row;
				}
			}
		}

		Remember_Logger::dev_log(
			'QuickBooks get_invoice_refund_lines',
			array(
				'invoice_id'           => $invoice_id,
				'customer_id_param'    => $customer_id,
				'customer_id_resolved'   => $customer_id_resolved,
				'qb_payment_ids'        => $qb_payment_ids,
				'refund_query_count'    => count( $refund_query_list ),
				'matched_refund_rows'   => count( $out ),
			)
		);

		usort(
			$out,
			function ( $a, $b ) {
				$ta = isset( $a['sort_ts'] ) ? (int) $a['sort_ts'] : strtotime( $a['txn_date'] ?? '' );
				$tb = isset( $b['sort_ts'] ) ? (int) $b['sort_ts'] : strtotime( $b['txn_date'] ?? '' );
				if ( $ta === $tb ) {
					return strcmp( (string) ( $a['qb_refund_id'] ?? '' ), (string) ( $b['qb_refund_id'] ?? '' ) );
				}
				return $ta <=> $tb;
			}
		);

		return $out;
	}

	/**
	 * Credit memo amount applied to a specific invoice.
	 *
	 * @param array  $memo       CreditMemo payload.
	 * @param string $invoice_id Invoice Id.
	 * @return array<string, mixed>|null
	 */
	private static function summarize_credit_memo_for_invoice( $memo, $invoice_id ) {
		if ( ! is_array( $memo ) || self::is_txn_voided( $memo ) ) {
			return null;
		}
		$invoice_id = (string) $invoice_id;
		$amount     = 0.0;

		if ( ! empty( $memo['LinkedTxn'] ) ) {
			$linked = $memo['LinkedTxn'];
			if ( isset( $linked['TxnId'] ) ) {
				$linked = array( $linked );
			}
			if ( is_array( $linked ) ) {
				foreach ( $linked as $lt ) {
					if ( is_array( $lt ) && isset( $lt['TxnId'] ) && (string) $lt['TxnId'] === $invoice_id ) {
						$amount = floatval( $memo['TotalAmt'] ?? 0 );
						break;
					}
				}
			}
		}

		if ( $amount <= 0 && ! empty( $memo['Line'] ) ) {
			$lines = $memo['Line'];
			if ( isset( $lines['Amount'] ) ) {
				$lines = array( $lines );
			}
			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					if ( ! is_array( $line ) || empty( $line['LinkedTxn'] ) ) {
						continue;
					}
					$linked = $line['LinkedTxn'];
					if ( isset( $linked['TxnId'] ) ) {
						$linked = array( $linked );
					}
					if ( ! is_array( $linked ) ) {
						continue;
					}
					foreach ( $linked as $lt ) {
						if ( is_array( $lt ) && isset( $lt['TxnId'] ) && (string) $lt['TxnId'] === $invoice_id ) {
							$amount += isset( $line['Amount'] ) ? floatval( $line['Amount'] ) : 0.0;
						}
					}
				}
			}
		}

		if ( $amount <= 0 ) {
			return null;
		}

		return array(
			'amount'         => $amount,
			'txn_date'       => isset( $memo['TxnDate'] ) ? sanitize_text_field( (string) $memo['TxnDate'] ) : '',
			'payment_method' => __( 'Credit memo', 'remember' ),
			'qb_refund_id'   => isset( $memo['Id'] ) ? (string) $memo['Id'] : '',
			'doc_number'     => isset( $memo['DocNumber'] ) ? sanitize_text_field( (string) $memo['DocNumber'] ) : '',
			'sort_ts'        => self::qb_entity_sort_timestamp( $memo ),
			'ledger_effect'  => 'credit',
		);
	}

	/**
	 * Normalize QueryResponse Item payload to a list of item arrays.
	 *
	 * @param array $response Decoded API response.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_item_query_response( $response ) {
		if ( ! isset( $response['QueryResponse']['Item'] ) ) {
			return array();
		}
		$raw = $response['QueryResponse']['Item'];
		if ( ! is_array( $raw ) ) {
			return array();
		}
		// Single item is returned as one associative array with an "Id" key, not 0..n.
		if ( isset( $raw['Id'] ) ) {
			return array( $raw );
		}
		return $raw;
	}

	/**
	 * Remove QuickBooks Category rows (folder/grouping only; not invoice line items).
	 *
	 * @param array $items List of Item entities.
	 * @return array<int, array<string, mixed>>
	 */
	private static function exclude_category_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$excluded_exact_names = array( 'Hours', 'Services' );
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['Type'] ) ? (string) $item['Type'] : '';
			if ( strcasecmp( $type, 'Category' ) === 0 ) {
				continue;
			}
			$name = isset( $item['Name'] ) ? trim( (string) $item['Name'] ) : '';
			if ( in_array( $name, $excluded_exact_names, true ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Query items/products.
	 *
	 * @param string $query SQL-like query.
	 * @return array|WP_Error Items or error.
	 */
	public static function query_items( $query = "SELECT * FROM Item" ) {
		$response = self::api_request( 'GET', 'query?query=' . urlencode( $query ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = self::normalize_item_query_response( $response );
		return self::exclude_category_items( $items );
	}

	/**
	 * Get company info.
	 *
	 * @return array|WP_Error Company info or error.
	 */
	public static function get_company_info() {
		$response = self::api_request( 'GET', 'companyinfo/' . self::get_company_id() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['CompanyInfo'] ) ? $response['CompanyInfo'] : array();
	}
}
