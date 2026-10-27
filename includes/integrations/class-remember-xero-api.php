<?php
/**
 * Xero API wrapper class (Phase 1 shell).
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-xero-oauth.php';
require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';

/**
 * Xero Accounting API helpers.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */
class Remember_Xero_API {

	const API_BASE = 'https://api.xero.com/api.xro/2.0/';

	/**
	 * Ensure a valid access token (refresh if expired / near expiry).
	 *
	 * @return array|WP_Error Settings with access_token, or error.
	 */
	public static function ensure_access_token() {
		$settings = Remember_Xero_OAuth::get_settings();
		if ( ! $settings || empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			return new WP_Error( 'xero_not_configured', __( 'Xero credentials are not configured.', 'remember' ) );
		}

		$expires_at = isset( $settings['expires_at'] ) ? (int) $settings['expires_at'] : 0;
		$needs_refresh = empty( $settings['access_token'] ) || ( $expires_at > 0 && $expires_at <= ( time() + 60 ) );

		if ( ! $needs_refresh ) {
			return $settings;
		}

		if ( empty( $settings['refresh_token'] ) ) {
			return new WP_Error( 'xero_reauth_required', __( 'Xero connection expired. Please reconnect.', 'remember' ) );
		}

		$token_data = Remember_Xero_OAuth::refresh_token(
			$settings['refresh_token'],
			$settings['client_id'],
			$settings['client_secret']
		);

		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		$settings['access_token']  = isset( $token_data['access_token'] ) ? $token_data['access_token'] : '';
		if ( ! empty( $token_data['refresh_token'] ) ) {
			$settings['refresh_token'] = $token_data['refresh_token'];
		}
		$expires_in = isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 1800;
		if ( $expires_in < 60 ) {
			$expires_in = 1800;
		}
		$settings['expires_at'] = time() + $expires_in;

		Remember_Xero_OAuth::save_settings( $settings );

		// Re-load so decrypted tokens are present for callers.
		return Remember_Xero_OAuth::get_settings();
	}

	/**
	 * Perform an authenticated Xero API request.
	 *
	 * Private and relative-only: the request carries the Bearer token and tenant
	 * header, so it must never be sent anywhere but API_BASE.
	 *
	 * @param string $method   HTTP method.
	 * @param string $path     Path under api.xro/2.0/ (no leading slash).
	 * @param array  $body     Optional JSON body (array).
	 * @param array  $query    Optional query args.
	 * @return array|WP_Error Decoded JSON or error.
	 */
	private static function request( $method, $path, $body = null, $query = array() ) {
		$settings = self::ensure_access_token();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if ( empty( $settings['tenant_id'] ) ) {
			return new WP_Error( 'xero_no_tenant', __( 'No Xero organisation (tenant) is connected.', 'remember' ) );
		}

		$url = self::API_BASE . ltrim( (string) $path, '/' );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'     => strtoupper( $method ),
			'headers'    => array(
				'Accept'         => 'application/json',
				'Content-Type'   => 'application/json',
				'Authorization'  => 'Bearer ' . $settings['access_token'],
				'Xero-tenant-id' => $settings['tenant_id'],
			),
			'user-agent' => 'reMember-WordPress/' . ( defined( 'REMEMBER_VERSION' ) ? REMEMBER_VERSION : '1.1.0' ),
			'timeout'    => 45,
			'redirection'=> 0,
			'sslverify'  => true,
		);

		if ( null !== $body && in_array( strtoupper( $method ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			Remember_Logger::error(
				'Xero API request could not be sent',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = __( 'Xero API request failed.', 'remember' );
			$details = self::xero_error_details( $decoded );
			if ( $details ) {
				$message = implode( ' ', $details );
			} elseif ( is_array( $decoded ) && ! empty( $decoded['Message'] ) ) {
				$message = (string) $decoded['Message'];
			} elseif ( is_array( $decoded ) && ! empty( $decoded['Detail'] ) ) {
				$message = (string) $decoded['Detail'];
			}
			Remember_Logger::error(
				'Xero API error',
				array(
					'status'  => $status,
					'path'    => $path,
					'message' => $message,
					'body'    => $decoded ? $decoded : $raw_body,
				)
			);
			return new WP_Error( 'xero_api_error', $message, array( 'status' => $status, 'body' => $decoded ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Fetch organisation info for the connected tenant (Settings UI).
	 *
	 * @return array|WP_Error Organisation payload or error.
	 */
	public static function get_organisation() {
		$result = self::request( 'GET', 'Organisation' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Organisations'][0] ) && is_array( $result['Organisations'][0] ) ) {
			return $result['Organisations'][0];
		}
		return $result;
	}

	/**
	 * Deep link into the Xero UI for an ACCREC invoice (staff browser).
	 *
	 * @param string $invoice_id Xero InvoiceID.
	 * @return string Absolute URL, or empty if no id.
	 */
	public static function get_invoice_app_url( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return '';
		}

		static $resolved_shortcode = null;
		static $shortcode_attempted = false;

		$settings  = Remember_Xero_OAuth::get_settings();
		$shortcode = ( is_array( $settings ) && ! empty( $settings['org_shortcode'] ) )
			? (string) $settings['org_shortcode']
			: '';

		if ( '' === $shortcode && null !== $resolved_shortcode ) {
			$shortcode = $resolved_shortcode;
		}

		if ( '' === $shortcode && ! $shortcode_attempted && is_array( $settings ) && Remember_Xero_OAuth::is_connected( $settings ) ) {
			$shortcode_attempted = true;
			$org                 = self::get_organisation();
			if ( ! is_wp_error( $org ) && ! empty( $org['ShortCode'] ) ) {
				$shortcode                 = sanitize_text_field( (string) $org['ShortCode'] );
				$resolved_shortcode        = $shortcode;
				$settings['org_shortcode'] = $shortcode;
				Remember_Xero_OAuth::save_settings( $settings );
			} else {
				$resolved_shortcode = '';
			}
		}

		$path = '/AccountsReceivable/View.aspx?InvoiceID=' . rawurlencode( $invoice_id );
		if ( '' !== $shortcode ) {
			return 'https://go.xero.com/organisationlogin/default.aspx?shortcode=' . rawurlencode( $shortcode ) . '&redirecturl=' . rawurlencode( $path );
		}

		return 'https://go.xero.com' . $path;
	}

	/**
	 * Customer-facing online invoice URL (view / pay), not the staff Xero UI.
	 *
	 * Uses GET Invoices/{InvoiceID}/OnlineInvoice. Unavailable for drafts.
	 * Prefer the stored payment column (`xero_online_invoice_url`) at render time;
	 * call this during invoice create/sync to populate that column.
	 *
	 * @param string $invoice_id Xero InvoiceID.
	 * @return string Absolute URL, or empty if unavailable.
	 */
	public static function get_online_invoice_url( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return '';
		}

		$result = self::request( 'GET', 'Invoices/' . rawurlencode( $invoice_id ) . '/OnlineInvoice' );
		if ( is_wp_error( $result ) ) {
			Remember_Logger::warning(
				'Xero online invoice URL unavailable',
				array(
					'invoice_id' => $invoice_id,
					'error'      => $result->get_error_message(),
				)
			);
			return '';
		}

		if ( ! empty( $result['OnlineInvoices'][0]['OnlineInvoiceUrl'] ) ) {
			return esc_url_raw( (string) $result['OnlineInvoices'][0]['OnlineInvoiceUrl'] );
		}
		if ( ! empty( $result['OnlineInvoices']['OnlineInvoice']['OnlineInvoiceUrl'] ) ) {
			// Legacy nested shape seen in older Xero docs.
			return esc_url_raw( (string) $result['OnlineInvoices']['OnlineInvoice']['OnlineInvoiceUrl'] );
		}

		Remember_Logger::warning(
			'Xero OnlineInvoice response missing OnlineInvoiceUrl',
			array(
				'invoice_id' => $invoice_id,
				'keys'       => is_array( $result ) ? array_keys( $result ) : array(),
			)
		);
		return '';
	}

	/**
	 * Get a Contact by Xero ContactID.
	 *
	 * @param string $contact_id ContactID.
	 * @return array|WP_Error Contact array or error.
	 */
	public static function get_contact( $contact_id ) {
		$contact_id = trim( (string) $contact_id );
		if ( '' === $contact_id ) {
			return new WP_Error( 'invalid_contact_id', __( 'Invalid Xero contact ID.', 'remember' ) );
		}
		$result = self::request( 'GET', 'Contacts/' . rawurlencode( $contact_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Contacts'][0] ) && is_array( $result['Contacts'][0] ) ) {
			return $result['Contacts'][0];
		}
		return new WP_Error( 'xero_contact_not_found', __( 'Xero contact not found.', 'remember' ) );
	}

	/**
	 * Default sales discount on a Xero contact, as a percent from 0 to 100.
	 *
	 * Xero stores this on the contact and does not copy it onto an invoice
	 * created through the API. 0 means the contact has no discount.
	 *
	 * @param array $contact Contact from get_contact() or a contact save.
	 * @return float
	 */
	public static function contact_discount_rate( $contact ) {
		if ( ! is_array( $contact ) || ! isset( $contact['Discount'] ) || ! is_numeric( $contact['Discount'] ) ) {
			return 0.0;
		}
		$rate = floatval( $contact['Discount'] );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		if ( $rate > 100 ) {
			$rate = 100.0;
		}
		return $rate;
	}

	/**
	 * Find first Contact whose EmailAddress matches.
	 *
	 * @param string $email Email address.
	 * @return array|null Contact or null.
	 */
	public static function find_contact_by_email( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		// Xero where-clause: EmailAddress=="addr"
		$escaped = str_replace( '"', '""', $email );
		$where   = 'EmailAddress!=null&&EmailAddress=="' . $escaped . '"';
		$result  = self::request( 'GET', 'Contacts', null, array( 'where' => $where ) );
		if ( is_wp_error( $result ) || empty( $result['Contacts'] ) || ! is_array( $result['Contacts'] ) ) {
			return null;
		}

		$email_l = strtolower( $email );
		foreach ( $result['Contacts'] as $contact ) {
			if ( ! is_array( $contact ) ) {
				continue;
			}
			if ( ! empty( $contact['EmailAddress'] ) && strtolower( trim( (string) $contact['EmailAddress'] ) ) === $email_l ) {
				return $contact;
			}
			if ( ! empty( $contact['ContactPersons'] ) && is_array( $contact['ContactPersons'] ) ) {
				foreach ( $contact['ContactPersons'] as $person ) {
					if ( ! empty( $person['EmailAddress'] ) && strtolower( trim( (string) $person['EmailAddress'] ) ) === $email_l ) {
						return $contact;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Create or update a Xero Contact.
	 *
	 * @param array $contact_data Keys: name, first_name, last_name, email, phone, address[], account_number?, notes?, contact_id?.
	 * @return array|WP_Error Contact entity or error.
	 */
	public static function create_or_update_contact( $contact_data ) {
		$contact = array(
			'Name' => ! empty( $contact_data['name'] ) ? $contact_data['name'] : '',
		);
		if ( ! empty( $contact_data['first_name'] ) ) {
			$contact['FirstName'] = $contact_data['first_name'];
		}
		if ( ! empty( $contact_data['last_name'] ) ) {
			$contact['LastName'] = $contact_data['last_name'];
		}
		if ( ! empty( $contact_data['email'] ) ) {
			$contact['EmailAddress'] = $contact_data['email'];
		}
		if ( ! empty( $contact_data['account_number'] ) ) {
			$contact['AccountNumber'] = (string) $contact_data['account_number'];
		}
		if ( isset( $contact_data['notes'] ) ) {
			$contact['Notes'] = (string) $contact_data['notes'];
		}
		if ( ! empty( $contact_data['phone'] ) ) {
			$contact['Phones'] = array(
				array(
					'PhoneType'   => 'MOBILE',
					'PhoneNumber' => $contact_data['phone'],
				),
			);
		}
		if ( ! empty( $contact_data['address'] ) && is_array( $contact_data['address'] ) ) {
			$addr = array(
				'AddressType'  => 'STREET',
				'AddressLine1' => isset( $contact_data['address']['street'] ) ? $contact_data['address']['street'] : '',
				'City'         => isset( $contact_data['address']['city'] ) ? $contact_data['address']['city'] : '',
				'Region'       => isset( $contact_data['address']['state'] ) ? $contact_data['address']['state'] : '',
				'PostalCode'   => isset( $contact_data['address']['postal'] ) ? $contact_data['address']['postal'] : '',
				'Country'      => isset( $contact_data['address']['country'] ) ? $contact_data['address']['country'] : '',
			);
			$addr = array_filter(
				$addr,
				static function ( $value, $key ) {
					if ( 'AddressType' === $key ) {
						return true;
					}
					return '' !== trim( (string) $value );
				},
				ARRAY_FILTER_USE_BOTH
			);
			if ( count( $addr ) > 1 ) {
				// STREET + POBOX so invoicing and contact card both show the address.
				$pobox         = $addr;
				$pobox['AddressType'] = 'POBOX';
				$contact['Addresses'] = array( $addr, $pobox );
			}
		}
		if ( ! empty( $contact_data['contact_id'] ) ) {
			$contact['ContactID'] = $contact_data['contact_id'];
		}

		$result = self::request(
			'POST',
			'Contacts',
			array(
				'Contacts' => array( $contact ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Contacts'][0] ) && is_array( $result['Contacts'][0] ) ) {
			return $result['Contacts'][0];
		}
		return new WP_Error( 'xero_contact_save_failed', __( 'Xero did not return a contact after save.', 'remember' ), $result );
	}

	/**
	 * List Items (products/services) for the connected organisation.
	 *
	 * @param array $query Optional query args (e.g. where).
	 * @return array|WP_Error List of Item arrays, or error.
	 */
	public static function get_items( $query = array() ) {
		$result = self::request( 'GET', 'Items', null, $query );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['Items'] ) || ! is_array( $result['Items'] ) ) {
			return array();
		}
		return array_values( $result['Items'] );
	}

	/**
	 * Get one Item by ItemID.
	 *
	 * @param string $item_id ItemID.
	 * @return array|WP_Error Item array or error.
	 */
	public static function get_item( $item_id ) {
		$item_id = trim( (string) $item_id );
		if ( '' === $item_id ) {
			return new WP_Error( 'invalid_item_id', __( 'Invalid Xero item ID.', 'remember' ) );
		}
		$result = self::request( 'GET', 'Items/' . rawurlencode( $item_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Items'][0] ) && is_array( $result['Items'][0] ) ) {
			return $result['Items'][0];
		}
		return new WP_Error( 'xero_item_not_found', __( 'Xero item not found.', 'remember' ) );
	}

	/**
	 * Find first Item whose Name matches exactly.
	 *
	 * @param string $name Item name.
	 * @return array|null Item or null.
	 */
	public static function find_item_by_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return null;
		}
		$escaped = str_replace( '"', '""', $name );
		$where   = 'Name=="' . $escaped . '"';
		$result  = self::get_items( array( 'where' => $where ) );
		if ( is_wp_error( $result ) || empty( $result[0] ) || ! is_array( $result[0] ) ) {
			return null;
		}
		return $result[0];
	}

	/**
	 * Email an AUTHORISED invoice to the related Xero contact.
	 *
	 * @param string $invoice_id Xero InvoiceID.
	 * @return true|WP_Error
	 */
	public static function email_invoice( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'xero_email_no_id', __( 'Missing Xero invoice ID.', 'remember' ) );
		}

		$result = self::request( 'POST', 'Invoices/' . rawurlencode( $invoice_id ) . '/Email', array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Remember_Logger::info( 'Xero invoice email requested', array( 'invoice_id' => $invoice_id ) );
		return true;
	}

	/**
	 * Create an ACCREC (sales) invoice in Xero.
	 *
	 * @param array $invoice_data Keys: contact_id, line_items[{item_code, quantity, unit_amount, description, tax_type?, discount_rate?}], reference?, date?, due_date?.
	 * @return array|WP_Error Invoice entity or error.
	 */
	public static function create_invoice( $invoice_data ) {
		if ( empty( $invoice_data['contact_id'] ) ) {
			return new WP_Error( 'xero_invoice_no_contact', __( 'Xero invoice requires a contact.', 'remember' ) );
		}
		if ( empty( $invoice_data['line_items'] ) || ! is_array( $invoice_data['line_items'] ) ) {
			return new WP_Error( 'xero_invoice_no_lines', __( 'Xero invoice requires at least one line item.', 'remember' ) );
		}

		$line_items = array();
		foreach ( $invoice_data['line_items'] as $item ) {
			$qty = isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 1.0;
			if ( $qty <= 0 ) {
				$qty = 1.0;
			}
			$line = array(
				'Description' => ! empty( $item['description'] ) ? (string) $item['description'] : '',
				'Quantity'    => $qty,
				'UnitAmount'  => isset( $item['unit_amount'] ) ? floatval( $item['unit_amount'] ) : 0.0,
			);
			if ( ! empty( $item['item_code'] ) ) {
				$line['ItemCode'] = (string) $item['item_code'];
			}
			if ( ! empty( $item['tax_type'] ) ) {
				$line['TaxType'] = (string) $item['tax_type'];
			}
			if ( ! empty( $item['account_code'] ) ) {
				$line['AccountCode'] = (string) $item['account_code'];
			}
			if ( isset( $item['discount_rate'] ) && is_numeric( $item['discount_rate'] ) ) {
				$discount_rate = floatval( $item['discount_rate'] );
				if ( $discount_rate > 0 ) {
					$line['DiscountRate'] = $discount_rate > 100 ? 100.0 : $discount_rate;
				}
			}
			$line_items[] = $line;
		}

		$today = gmdate( 'Y-m-d' );
		$invoice = array(
			'Type'            => 'ACCREC',
			'Contact'         => array(
				'ContactID' => (string) $invoice_data['contact_id'],
			),
			'LineItems'       => $line_items,
			'Date'            => ! empty( $invoice_data['date'] ) ? (string) $invoice_data['date'] : $today,
			'DueDate'         => ! empty( $invoice_data['due_date'] ) ? (string) $invoice_data['due_date'] : gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			'LineAmountTypes' => 'NoTax',
			'Status'          => ! empty( $invoice_data['status'] ) ? (string) $invoice_data['status'] : 'AUTHORISED',
		);
		if ( ! empty( $invoice_data['reference'] ) ) {
			$invoice['Reference'] = (string) $invoice_data['reference'];
		}

		$result = self::request(
			'POST',
			'Invoices',
			array(
				'Invoices' => array( $invoice ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Invoices'][0] ) && is_array( $result['Invoices'][0] ) ) {
			return $result['Invoices'][0];
		}
		return new WP_Error( 'xero_invoice_save_failed', __( 'Xero did not return an invoice after save.', 'remember' ), $result );
	}

	/**
	 * Void a Xero invoice (Status → VOIDED).
	 *
	 * @param string $invoice_id InvoiceID.
	 * @return array|WP_Error Updated invoice or error.
	 */
	public static function void_invoice( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'invalid_invoice_id', __( 'Invalid Xero invoice ID.', 'remember' ) );
		}

		$invoice = self::get_invoice( $invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$status = isset( $invoice['Status'] ) ? strtoupper( (string) $invoice['Status'] ) : '';
		if ( in_array( $status, array( 'VOIDED', 'DELETED' ), true ) ) {
			return $invoice;
		}
		if ( in_array( $status, array( 'DRAFT', 'SUBMITTED' ), true ) ) {
			return self::set_invoice_status( $invoice_id, 'DELETED' );
		}

		// Refuse before touching credit allocations, so a refused void leaves the invoice as it was.
		$amount_paid = isset( $invoice['AmountPaid'] ) ? floatval( $invoice['AmountPaid'] ) : 0.0;
		if ( $amount_paid > 0.001 || ! empty( $invoice['Payments'] ) ) {
			return new WP_Error(
				'xero_invoice_has_payment',
				__( 'Xero will not void this invoice because a payment is applied. Remove that payment in Xero, or choose Refund instead of Void.', 'remember' )
			);
		}

		if ( ! empty( $invoice['CreditNotes'] ) && is_array( $invoice['CreditNotes'] ) ) {
			foreach ( $invoice['CreditNotes'] as $note ) {
				if ( ! is_array( $note ) || empty( $note['CreditNoteID'] ) ) {
					continue;
				}
				$removed = self::remove_credit_allocations_for_invoice( (string) $note['CreditNoteID'], $invoice_id );
				if ( is_wp_error( $removed ) ) {
					return $removed;
				}
			}
		}

		return self::set_invoice_status( $invoice_id, 'VOIDED' );
	}

	/**
	 * Update an invoice status without resending its line items.
	 *
	 * @param string $invoice_id InvoiceID.
	 * @param string $status     VOIDED or DELETED.
	 * @return array|WP_Error Updated invoice or error.
	 */
	private static function set_invoice_status( $invoice_id, $status ) {
		$result = self::request(
			'POST',
			'Invoices',
			array(
				'Invoices' => array(
					array(
						'InvoiceID' => $invoice_id,
						'Status'    => $status,
					),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Invoices'][0] ) && is_array( $result['Invoices'][0] ) ) {
			return $result['Invoices'][0];
		}
		return new WP_Error( 'xero_invoice_void_failed', __( 'Xero did not confirm the invoice void.', 'remember' ), $result );
	}

	/**
	 * Drop allocations from one credit note onto one invoice. The credit note stays authorised.
	 *
	 * @param string $credit_note_id CreditNoteID.
	 * @param string $invoice_id     InvoiceID.
	 * @return true|WP_Error
	 */
	private static function remove_credit_allocations_for_invoice( $credit_note_id, $invoice_id ) {
		$result = self::request( 'GET', 'CreditNotes/' . rawurlencode( $credit_note_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$note = ! empty( $result['CreditNotes'][0] ) && is_array( $result['CreditNotes'][0] ) ? $result['CreditNotes'][0] : array();
		if ( empty( $note['Allocations'] ) || ! is_array( $note['Allocations'] ) ) {
			return true;
		}
		foreach ( $note['Allocations'] as $alloc ) {
			if ( ! is_array( $alloc ) || empty( $alloc['AllocationID'] ) ) {
				continue;
			}
			$alloc_invoice = isset( $alloc['Invoice']['InvoiceID'] ) ? (string) $alloc['Invoice']['InvoiceID'] : '';
			if ( $alloc_invoice !== $invoice_id ) {
				continue;
			}
			$deleted = self::request(
				'DELETE',
				'CreditNotes/' . rawurlencode( $credit_note_id ) . '/Allocations/' . rawurlencode( (string) $alloc['AllocationID'] )
			);
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}
		return true;
	}

	/**
	 * Specific Xero validation lines. The top-level message is only "A validation exception occurred".
	 *
	 * @param mixed $decoded Decoded error body.
	 * @return string[]
	 */
	private static function xero_error_details( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$messages = array();
		$elements = isset( $decoded['Elements'] ) && is_array( $decoded['Elements'] ) ? $decoded['Elements'] : array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['ValidationErrors'] ) || ! is_array( $element['ValidationErrors'] ) ) {
				continue;
			}
			foreach ( $element['ValidationErrors'] as $error ) {
				if ( is_array( $error ) && ! empty( $error['Message'] ) ) {
					$messages[] = (string) $error['Message'];
				}
			}
		}
		return array_values( array_unique( $messages ) );
	}

	/**
	 * Get an invoice by InvoiceID.
	 *
	 * @param string $invoice_id InvoiceID.
	 * @return array|WP_Error Invoice array or error.
	 */
	public static function get_invoice( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'invalid_invoice_id', __( 'Invalid Xero invoice ID.', 'remember' ) );
		}
		$result = self::request( 'GET', 'Invoices/' . rawurlencode( $invoice_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['Invoices'][0] ) && is_array( $result['Invoices'][0] ) ) {
			return $result['Invoices'][0];
		}
		return new WP_Error( 'xero_invoice_not_found', __( 'Xero invoice not found.', 'remember' ) );
	}

	/**
	 * Payment rows applied to a Xero invoice (register-friendly shape).
	 *
	 * Keys match QuickBooks lines for UI reuse: amount, txn_date, payment_method, qb_payment_id, sort_ts.
	 *
	 * @param string $invoice_id InvoiceID.
	 * @return array|WP_Error
	 */
	public static function get_invoice_payment_lines( $invoice_id ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'invalid_invoice_id', __( 'Invalid Xero invoice ID.', 'remember' ) );
		}

		$where  = 'Invoice.InvoiceID=Guid("' . $invoice_id . '")';
		$result = self::request( 'GET', 'Payments', null, array( 'where' => $where ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array();
		if ( empty( $result['Payments'] ) || ! is_array( $result['Payments'] ) ) {
			return $out;
		}

		foreach ( $result['Payments'] as $payment ) {
			if ( ! is_array( $payment ) ) {
				continue;
			}
			$status = isset( $payment['Status'] ) ? (string) $payment['Status'] : '';
			if ( 'DELETED' === strtoupper( $status ) ) {
				continue;
			}
			$amount = isset( $payment['Amount'] ) ? floatval( $payment['Amount'] ) : 0.0;
			if ( $amount <= 0 ) {
				continue;
			}
			$txn_date = '';
			if ( ! empty( $payment['Date'] ) ) {
				$txn_date = self::normalize_xero_date( $payment['Date'] );
			}
			$method = '';
			if ( ! empty( $payment['PaymentType'] ) ) {
				$method = (string) $payment['PaymentType'];
			} elseif ( ! empty( $payment['Account']['Name'] ) ) {
				$method = (string) $payment['Account']['Name'];
			}
			$payment_id = isset( $payment['PaymentID'] ) ? (string) $payment['PaymentID'] : '';
			$out[]      = array(
				'amount'         => $amount,
				'txn_date'       => $txn_date,
				'payment_method' => $method,
				'qb_payment_id'  => $payment_id,
				'doc_number'     => '',
				'sort_ts'        => self::xero_entity_sort_timestamp( $payment ),
			);
		}

		return $out;
	}

	/**
	 * Credit note allocations against a Xero invoice (refund-like register rows).
	 *
	 * Keys match QuickBooks refund lines: amount, txn_date, payment_method, qb_refund_id, doc_number, sort_ts.
	 *
	 * @param string      $invoice_id InvoiceID.
	 * @param string|null $contact_id Optional ContactID to narrow CreditNotes query.
	 * @return array|WP_Error
	 */
	public static function get_invoice_refund_lines( $invoice_id, $contact_id = null ) {
		$invoice_id = trim( (string) $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'invalid_invoice_id', __( 'Invalid Xero invoice ID.', 'remember' ) );
		}

		$query = array();
		$contact_id = $contact_id ? trim( (string) $contact_id ) : '';
		if ( '' !== $contact_id ) {
			$query['where'] = 'Contact.ContactID=Guid("' . $contact_id . '")&&Type=="ACCRECCREDIT"';
		}

		$notes = array();
		$page  = 1;
		do {
			$query['page'] = $page;
			$result        = self::request( 'GET', 'CreditNotes', null, $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$batch = ( ! empty( $result['CreditNotes'] ) && is_array( $result['CreditNotes'] ) ) ? $result['CreditNotes'] : array();
			foreach ( $batch as $note ) {
				$notes[] = $note;
			}
			++$page;
		} while ( count( $batch ) >= 100 && $page <= 10 );

		$out = array();
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}
			$status = isset( $note['Status'] ) ? strtoupper( (string) $note['Status'] ) : '';
			if ( in_array( $status, array( 'DELETED', 'VOIDED', 'DRAFT' ), true ) ) {
				continue;
			}
			if ( empty( $note['Allocations'] ) || ! is_array( $note['Allocations'] ) ) {
				continue;
			}
			$allocated = 0.0;
			foreach ( $note['Allocations'] as $alloc ) {
				if ( ! is_array( $alloc ) || empty( $alloc['Invoice']['InvoiceID'] ) ) {
					continue;
				}
				if ( (string) $alloc['Invoice']['InvoiceID'] !== $invoice_id ) {
					continue;
				}
				$allocated += isset( $alloc['Amount'] ) ? floatval( $alloc['Amount'] ) : 0.0;
			}
			if ( $allocated <= 0 ) {
				continue;
			}
			$txn_date = ! empty( $note['Date'] ) ? self::normalize_xero_date( $note['Date'] ) : '';
			$out[]    = array(
				'amount'         => $allocated,
				'txn_date'       => $txn_date,
				'payment_method' => __( 'Credit note', 'remember' ),
				'qb_refund_id'   => isset( $note['CreditNoteID'] ) ? (string) $note['CreditNoteID'] : '',
				'doc_number'     => isset( $note['CreditNoteNumber'] ) ? sanitize_text_field( (string) $note['CreditNoteNumber'] ) : '',
				'sort_ts'        => self::xero_entity_sort_timestamp( $note ),
				'ledger_effect'  => 'credit',
			);
		}

		return $out;
	}

	/**
	 * Authorised sales credit notes for a contact that still have an unallocated balance.
	 *
	 * Oldest first, so the next invoice consumes credit in the order it was issued.
	 *
	 * @param string $contact_id Xero ContactID.
	 * @return array<int,array{id:string,number:string,remaining:float,sort_ts:int,date:string}>|WP_Error
	 */
	public static function get_open_credit_notes( $contact_id ) {
		$contact_id = trim( (string) $contact_id );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $contact_id ) ) {
			return new WP_Error( 'xero_cn_no_contact', __( 'Invalid Xero contact for credit notes.', 'remember' ) );
		}

		$query = array(
			'where' => 'Contact.ContactID=Guid("' . $contact_id . '")&&Type=="ACCRECCREDIT"&&Status=="AUTHORISED"',
		);
		$notes = array();
		$page  = 1;
		do {
			$query['page'] = $page;
			$result        = self::request( 'GET', 'CreditNotes', null, $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$batch = ( ! empty( $result['CreditNotes'] ) && is_array( $result['CreditNotes'] ) ) ? $result['CreditNotes'] : array();
			foreach ( $batch as $note ) {
				$notes[] = $note;
			}
			++$page;
		} while ( count( $batch ) >= 100 && $page <= 10 );

		$out = array();
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) || empty( $note['CreditNoteID'] ) ) {
				continue;
			}
			$remaining = isset( $note['RemainingCredit'] ) ? floatval( $note['RemainingCredit'] ) : 0.0;
			if ( $remaining <= 0.001 ) {
				continue;
			}
			$out[] = array(
				'id'        => (string) $note['CreditNoteID'],
				'number'    => isset( $note['CreditNoteNumber'] ) ? sanitize_text_field( (string) $note['CreditNoteNumber'] ) : '',
				'remaining' => round( $remaining, 2 ),
				'sort_ts'   => self::xero_entity_sort_timestamp( $note ),
				'date'      => ! empty( $note['Date'] ) ? self::normalize_xero_date( $note['Date'] ) : '',
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['sort_ts'] === $b['sort_ts'] ) {
					return strcmp( $a['number'], $b['number'] );
				}
				return $a['sort_ts'] <=> $b['sort_ts'];
			}
		);

		return $out;
	}

	/**
	 * Allocate part of an authorised credit note onto an authorised invoice.
	 *
	 * @param string $credit_note_id CreditNoteID.
	 * @param string $invoice_id     InvoiceID.
	 * @param float  $amount         Amount to allocate.
	 * @return array|WP_Error
	 */
	public static function allocate_credit_note_to_invoice( $credit_note_id, $invoice_id, $amount ) {
		$credit_note_id = trim( (string) $credit_note_id );
		$invoice_id     = trim( (string) $invoice_id );
		$amount         = round( floatval( $amount ), 2 );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $credit_note_id ) || ! preg_match( '/^[a-f0-9-]{36}$/i', $invoice_id ) ) {
			return new WP_Error( 'xero_cn_alloc_ids', __( 'Invalid Xero credit note or invoice ID.', 'remember' ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'xero_cn_alloc_amount', __( 'Credit allocation amount must be greater than zero.', 'remember' ) );
		}

		return self::request(
			'PUT',
			'CreditNotes/' . rawurlencode( $credit_note_id ) . '/Allocations',
			array(
				'Allocations' => array(
					array(
						'Invoice' => array( 'InvoiceID' => $invoice_id ),
						'Amount'  => $amount,
						'Date'    => gmdate( 'Y-m-d' ),
					),
				),
			)
		);
	}

	/**
	 * Sort timestamp for a Xero entity (document Date first for ledger chronology).
	 *
	 * @param array $entity Xero entity.
	 * @return int Unix timestamp (site timezone when possible).
	 */
	public static function xero_entity_sort_timestamp( $entity ) {
		if ( ! is_array( $entity ) ) {
			return 0;
		}
		foreach ( array( 'Date', 'FullyPaidOnDate', 'UpdatedDateUTC' ) as $key ) {
			if ( empty( $entity[ $key ] ) ) {
				continue;
			}
			$ts = self::parse_xero_datetime( $entity[ $key ] );
			if ( $ts > 0 ) {
				return $ts;
			}
		}
		return 0;
	}

	/**
	 * Normalize a Xero date field to Y-m-d.
	 *
	 * @param mixed $value Raw date.
	 * @return string
	 */
	public static function normalize_xero_date( $value ) {
		$ts = self::parse_xero_datetime( $value );
		if ( $ts <= 0 ) {
			return is_string( $value ) ? sanitize_text_field( substr( $value, 0, 10 ) ) : '';
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Parse Xero date /Date(ms+0000)/ or ISO string to unix timestamp.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function parse_xero_datetime( $value ) {
		if ( is_numeric( $value ) ) {
			$n = (float) $value;
			return (int) ( $n > 20000000000 ? ( $n / 1000 ) : $n );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}
		if ( preg_match( '/\/Date\((\d+)([+-]\d+)?\)\//', $value, $m ) ) {
			return (int) floor( ( (float) $m[1] ) / 1000 );
		}
		$ts = strtotime( $value );
		return $ts ? (int) $ts : 0;
	}
}
