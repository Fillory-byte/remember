<?php
/**
 * Xero sync orchestration.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-xero-api.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-product.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-remember-xero-item-mapping.php';
require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';

/**
 * Xero sync — mirrors Remember_QuickBooks_Sync for the active Xero provider.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */
class Remember_Xero_Sync {

	/**
	 * Result of the credit allocation attempted during the latest invoice create.
	 *
	 * @var array{applied:float,numbers:string[],error:string,hold_email:bool}|null
	 */
	private static $credit_apply = null;

	/**
	 * Sync a member to a Xero Contact.
	 *
	 * @param int $member_id Member ID (WordPress user ID).
	 * @return array|WP_Error Contact data or error.
	 */
	public static function sync_member_to_contact( $member_id ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-import-export.php';

		$member_model = new Remember_Member();
		$member       = $member_model->get( $member_id );
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
		$legal_parts = Remember_Import_Export::member_resolve_legal_name_parts( $profile, $member_id );
		$first_name  = trim( (string) $legal_parts[0] );
		$last_name   = trim( (string) $legal_parts[1] );
		$full_name   = trim( $first_name . ' ' . $last_name );
		if ( '' === $full_name ) {
			// Last resort only — never use nickname/display for Contact.Name.
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

		$contact_data = array(
			'name'           => $full_name,
			'first_name'     => $first_name,
			'last_name'      => $last_name,
			'email'          => $user->user_email,
			'phone'          => ( $profile && ! empty( $profile->cell_phone ) ) ? $profile->cell_phone : '',
			'account_number' => (string) absint( $member_id ),
		);

		if ( '' !== $nickname ) {
			$contact_data['notes'] = sprintf(
				/* translators: %s: member nickname / stage name */
				__( 'Nickname: %s', 'remember' ),
				$nickname
			);
		}

		if ( $profile && ( ! empty( $profile->address_street ) || ! empty( $profile->address_city ) || ! empty( $profile->address_postal ) ) ) {
			$contact_data['address'] = array(
				'street'  => isset( $profile->address_street ) ? $profile->address_street : '',
				'city'    => isset( $profile->address_city ) ? $profile->address_city : '',
				'state'   => isset( $profile->address_state ) ? $profile->address_state : '',
				'postal'  => isset( $profile->address_postal ) ? $profile->address_postal : '',
				'country' => ! empty( $profile->address_country ) ? $profile->address_country : 'US',
			);
		}

		$xero_contact_id = get_user_meta( $member_id, 'remember_xero_contact_id', true );
		if ( $xero_contact_id ) {
			$existing = Remember_Xero_API::get_contact( $xero_contact_id );
			if ( is_wp_error( $existing ) ) {
				Remember_Logger::warning(
					'Clearing Xero contact link (contact missing)',
					array(
						'member_id'       => $member_id,
						'xero_contact_id' => $xero_contact_id,
					)
				);
				delete_user_meta( $member_id, 'remember_xero_contact_id' );
				$xero_contact_id = '';
			} elseif ( ! self::contact_email_matches( $existing, $user->user_email ) ) {
				// The stored contact is still this member. Write the new address onto it
				// instead of dropping the link and creating a second contact.
				Remember_Logger::info(
					'Updating Xero contact email',
					array(
						'member_id'       => $member_id,
						'xero_contact_id' => $xero_contact_id,
					)
				);
				Remember_Logger::debug(
					'Updating Xero contact email',
					array(
						'member_id'       => $member_id,
						'xero_contact_id' => $xero_contact_id,
						'from'            => isset( $existing['EmailAddress'] ) ? (string) $existing['EmailAddress'] : '',
						'to'              => (string) $user->user_email,
					)
				);
			}
		}

		if ( ! $xero_contact_id ) {
			$by_email = Remember_Xero_API::find_contact_by_email( $user->user_email );
			if ( is_array( $by_email ) && ! empty( $by_email['ContactID'] ) && self::contact_email_matches( $by_email, $user->user_email ) ) {
				$xero_contact_id = $by_email['ContactID'];
				update_user_meta( $member_id, 'remember_xero_contact_id', $xero_contact_id );
			}
		}

		if ( $xero_contact_id ) {
			$contact_data['contact_id'] = $xero_contact_id;
		}

		$result = Remember_Xero_API::create_or_update_contact( $contact_data );

		// Name collision on create: retry with email disambiguator.
		if ( is_wp_error( $result ) && empty( $contact_data['contact_id'] ) && self::is_xero_name_conflict_error( $result ) ) {
			$contact_data['name'] = $full_name . ' (' . $user->user_email . ')';
			$result               = Remember_Xero_API::create_or_update_contact( $contact_data );
		}

		// Stale ContactID: clear and retry once (match-by-email or create).
		if ( is_wp_error( $result ) && $xero_contact_id ) {
			delete_user_meta( $member_id, 'remember_xero_contact_id' );
			unset( $contact_data['contact_id'] );

			$by_email = Remember_Xero_API::find_contact_by_email( $user->user_email );
			if ( is_array( $by_email ) && ! empty( $by_email['ContactID'] ) && self::contact_email_matches( $by_email, $user->user_email ) ) {
				$contact_data['contact_id'] = $by_email['ContactID'];
				update_user_meta( $member_id, 'remember_xero_contact_id', $by_email['ContactID'] );
			}
			$result = Remember_Xero_API::create_or_update_contact( $contact_data );
		}

		if ( is_wp_error( $result ) ) {
			Remember_Logger::error(
				'Failed to sync member to Xero contact',
				array(
					'member_id' => $member_id,
					'error'     => $result->get_error_message(),
				)
			);
			return $result;
		}

		if ( ! empty( $result['ContactID'] ) ) {
			update_user_meta( $member_id, 'remember_xero_contact_id', $result['ContactID'] );
		}

		Remember_Logger::info(
			'Member synced to Xero contact',
			array(
				'member_id'       => $member_id,
				'xero_contact_id' => isset( $result['ContactID'] ) ? $result['ContactID'] : '',
				'xero_name'       => isset( $result['Name'] ) ? $result['Name'] : '',
				'expected_name'   => $full_name,
			)
		);

		return $result;
	}

	/**
	 * Whether a Xero contact's primary email matches the member email.
	 *
	 * @param array  $contact Xero contact.
	 * @param string $email   Member email.
	 * @return bool
	 */
	private static function contact_email_matches( $contact, $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! is_array( $contact ) ) {
			return false;
		}
		if ( ! empty( $contact['EmailAddress'] ) && strtolower( trim( (string) $contact['EmailAddress'] ) ) === $email ) {
			return true;
		}
		if ( ! empty( $contact['ContactPersons'] ) && is_array( $contact['ContactPersons'] ) ) {
			foreach ( $contact['ContactPersons'] as $person ) {
				if ( ! empty( $person['EmailAddress'] ) && strtolower( trim( (string) $person['EmailAddress'] ) ) === $email ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether an API error is a contact Name uniqueness conflict.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function is_xero_name_conflict_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$message = strtolower( $error->get_error_message() );
		return ( false !== strpos( $message, 'already assigned' ) || false !== strpos( $message, 'must be unique' ) || false !== strpos( $message, 'name already' ) );
	}

	/**
	 * Resolve Xero ItemID for an event role (by role_id).
	 *
	 * @param int    $role_id   Role ID.
	 * @param string $role_name Role display name (auto-match by Name when unmapped).
	 * @return string|WP_Error Xero ItemID, or error.
	 */
	public static function resolve_xero_item_id_for_role( $role_id, $role_name ) {
		return self::resolve_xero_item_id( 'role', absint( $role_id ), $role_name );
	}

	/**
	 * Resolve Xero ItemID for a catalog product (add-on) by merchandise name.
	 *
	 * @param string $merchandise_name Must match an active remember_products row.
	 * @return string|WP_Error Xero ItemID, or error.
	 */
	public static function resolve_xero_item_id_for_catalog_product( $merchandise_name ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-product.php';
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
		return self::resolve_xero_item_id( 'product', absint( $catalog->product_id ), $merchandise_name );
	}

	/**
	 * Resolve Xero ItemID via remember_xero_item_mappings; optional auto-match by Item Name.
	 *
	 * @param string $entity_type   'role' or 'product'.
	 * @param int    $entity_id     role_id or product_id.
	 * @param string $fallback_name Name to match in Xero when no mapping exists.
	 * @return string|WP_Error Xero ItemID, or error.
	 */
	private static function resolve_xero_item_id( $entity_type, $entity_id, $fallback_name ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-remember-xero-item-mapping.php';

		$mapping_model = new Remember_Xero_Item_Mapping();
		$row           = $mapping_model->get_by_entity( $entity_type, $entity_id );
		if ( $row && ! empty( $row->xero_item_id ) ) {
			return $row->xero_item_id;
		}

		$matched = Remember_Xero_API::find_item_by_name( $fallback_name );
		if ( is_array( $matched ) && ! empty( $matched['ItemID'] ) ) {
			$item_id   = $matched['ItemID'];
			$item_name = isset( $matched['Name'] ) ? $matched['Name'] : $fallback_name;
			$mapping_model->upsert( $entity_type, $entity_id, $item_id, $item_name );
			return $item_id;
		}

		Remember_Logger::warning(
			'Xero Item not mapped and no name match',
			array(
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'name'        => $fallback_name,
			)
		);

		return new WP_Error(
			'xero_item_not_mapped',
			sprintf(
				/* translators: %s: role or product name */
				__( 'Xero has no mapping for "%s". Set it under Settings → Xero (Event roles + Products).', 'remember' ),
				$fallback_name
			)
		);
	}

	/**
	 * Create a Xero invoice for an accepted application.
	 *
	 * @param int $application_id Application ID.
	 * @return array|WP_Error Invoice data or error.
	 */
	public static function create_invoice_for_application( $application_id ) {
		self::$credit_apply = null;
		$application_model = new Remember_Application();
		$application       = $application_model->get( $application_id );

		if ( ! $application ) {
			return new WP_Error( 'application_not_found', __( 'Application not found.', 'remember' ) );
		}

		if ( 'accepted' !== $application->status ) {
			return new WP_Error( 'application_not_accepted', __( 'Application is not accepted.', 'remember' ) );
		}

		$payment_model = new Remember_Payment();
		$payment       = $payment_model->get_by_application( $application_id );

		if ( $payment && ! empty( $payment->xero_invoice_id ) ) {
			// If the remote invoice was voided/deleted, clear the local link and allow recreate.
			$existing_invoice = Remember_Xero_API::get_invoice( $payment->xero_invoice_id );
			$remote_gone      = is_wp_error( $existing_invoice )
				? self::is_xero_invoice_missing_error( $existing_invoice )
				: self::is_xero_invoice_voided_or_deleted( $existing_invoice );

			if ( $remote_gone ) {
				Remember_Logger::info(
					'Clearing voided/missing Xero invoice link before recreate',
					array(
						'application_id'  => $application_id,
						'payment_id'      => $payment->payment_id,
						'xero_invoice_id' => $payment->xero_invoice_id,
					)
				);
				self::clear_local_xero_invoice_link( $payment->payment_id );
				$payment = $payment_model->get( $payment->payment_id );
			} else {
				return new WP_Error( 'invoice_exists', __( 'Invoice already exists for this application.', 'remember' ) );
			}
		}

		$member_id   = $application->member_id;
		$sync_result = self::sync_member_to_contact( $member_id );
		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}
		$xero_contact_id = isset( $sync_result['ContactID'] ) ? $sync_result['ContactID'] : get_user_meta( $member_id, 'remember_xero_contact_id', true );
		if ( ! $xero_contact_id ) {
			return new WP_Error( 'no_xero_contact', __( 'Could not create or resolve Xero contact for this member.', 'remember' ) );
		}

		$event_model = new Remember_Event();
		$event       = $event_model->get( $application->event_id );

		global $wpdb;
		$event_role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er
				JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
				WHERE er.event_role_id = %d",
				$application->event_role_id
			)
		);

		$line_items = array();

		if ( $event_role && floatval( $event_role->cost ) >= 0 ) {
			$role_item_id = self::resolve_xero_item_id_for_role( $event_role->role_id, $event_role->role_name );
			if ( ! is_wp_error( $role_item_id ) ) {
				$line = self::build_line_item_from_xero_item(
					$role_item_id,
					1,
					floatval( $event_role->cost ),
					sprintf(
						/* translators: 1: role name, 2: event name */
						__( '%1$s - %2$s', 'remember' ),
						$event_role->role_name,
						$event ? $event->event_name : ''
					)
				);
				if ( ! is_wp_error( $line ) ) {
					$line_items[] = $line;
				} else {
					Remember_Logger::warning(
						'Skipping role cost line item - Xero item details unavailable',
						array(
							'role_name' => $event_role->role_name,
							'error'     => $line->get_error_message(),
						)
					);
				}
			} else {
				Remember_Logger::warning(
					'Skipping role cost line item - product not mapped',
					array(
						'role_name' => $event_role->role_name,
						'error'     => $role_item_id->get_error_message(),
					)
				);
			}
		}

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
			$product_item_id = self::resolve_xero_item_id_for_catalog_product( $item->merchandise_name );
			if ( ! is_wp_error( $product_item_id ) ) {
				$qty = intval( $item->quantity );
				if ( $qty <= 0 ) {
					$qty = 1;
				}
				$line = self::build_line_item_from_xero_item(
					$product_item_id,
					$qty,
					floatval( $item->total_cost ),
					$item->merchandise_name
				);
				if ( ! is_wp_error( $line ) ) {
					$line_items[] = $line;
				} else {
					Remember_Logger::warning(
						'Skipping merchandise line item - Xero item details unavailable',
						array(
							'merchandise_name' => $item->merchandise_name,
							'error'            => $line->get_error_message(),
						)
					);
				}
			} else {
				Remember_Logger::warning(
					'Skipping merchandise line item - product not mapped',
					array(
						'merchandise_name' => $item->merchandise_name,
						'error'            => $product_item_id->get_error_message(),
					)
				);
			}
		}

		$quoted_amount = $event_role ? floatval( $event_role->cost ) : 0;
		foreach ( $merchandise_items as $item ) {
			$quoted_amount += floatval( $item->total_cost );
		}
		if ( empty( $line_items ) ) {
			if ( $quoted_amount <= 0.001 ) {
				return self::record_zero_charge_payment( $application_id, $member_id, $event_role ? floatval( $event_role->cost ) : 0 );
			}
			Remember_Logger::warning(
				'Xero invoice skipped: no mapped line items',
				array( 'application_id' => (int) $application_id )
			);
			return new WP_Error( 'no_line_items', __( 'No line items available. Map event roles and catalog products to Xero under Settings → Xero.', 'remember' ) );
		}

		$discount_rate = self::contact_sales_discount_rate( $sync_result, $xero_contact_id );
		if ( $discount_rate > 0 ) {
			foreach ( $line_items as $index => $line ) {
				$line_items[ $index ]['discount_rate'] = $discount_rate;
			}
			Remember_Logger::info(
				'Applying the Xero contact discount to a new invoice',
				array(
					'application_id'  => $application_id,
					'xero_contact_id' => $xero_contact_id,
					'discount_rate'   => $discount_rate,
				)
			);
		}

		$discount_factor = $discount_rate > 0 ? ( 1 - ( min( 100, $discount_rate ) / 100 ) ) : 1;
		$total_amount    = 0;
		foreach ( $line_items as $li ) {
			$total_amount += floatval( $li['unit_amount'] ) * floatval( $li['quantity'] ) * $discount_factor;
		}

		$invoice_result = Remember_Xero_API::create_invoice(
			array(
				'contact_id' => $xero_contact_id,
				'line_items' => $line_items,
				'reference'  => sprintf(
					/* translators: 1: event name, 2: application ID */
					__( '%1$s - Application #%2$d', 'remember' ),
					$event && ! empty( $event->event_name ) ? $event->event_name : __( 'Event', 'remember' ),
					absint( $application_id )
				),
			)
		);

		if ( is_wp_error( $invoice_result ) ) {
			Remember_Logger::error(
				'Failed to create Xero invoice',
				array(
					'application_id' => $application_id,
					'error'          => $invoice_result->get_error_message(),
				)
			);
			if ( $total_amount <= 0.001 ) {
				Remember_Logger::info(
					'Xero invoice was not created for a zero charge; marking the application paid',
					array( 'application_id' => $application_id )
				);
				return self::record_zero_charge_payment( $application_id, $member_id, $event_role ? floatval( $event_role->cost ) : 0 );
			}
			return $invoice_result;
		}

		$xero_invoice_id     = isset( $invoice_result['InvoiceID'] ) ? $invoice_result['InvoiceID'] : '';
		$xero_invoice_number = isset( $invoice_result['InvoiceNumber'] ) ? sanitize_text_field( (string) $invoice_result['InvoiceNumber'] ) : null;
		$online_url          = '' !== $xero_invoice_id ? Remember_Xero_API::get_online_invoice_url( $xero_invoice_id ) : '';

		if ( ! $payment ) {
			$payment_data = array(
				'event_application_id'    => $application_id,
				'member_id'               => $member_id,
				'role_cost'               => $event_role ? floatval( $event_role->cost ) : 0,
				'merchandise_cost'        => 0,
				'total_amount'            => $total_amount,
				'amount_paid'             => 0,
				'amount_due'              => $total_amount,
				'payment_status'          => 'pending',
				'xero_invoice_id'         => $xero_invoice_id,
				'xero_invoice_number'     => $xero_invoice_number,
				'xero_online_invoice_url' => '' !== $online_url ? $online_url : null,
			);
			$payment_model->create( $payment_data );
		} else {
			$update_invoice = array(
				'xero_invoice_id' => $xero_invoice_id,
			);
			if ( null !== $xero_invoice_number ) {
				$update_invoice['xero_invoice_number'] = $xero_invoice_number;
			}
			if ( '' !== $online_url ) {
				$update_invoice['xero_online_invoice_url'] = $online_url;
			}
			$payment_model->update( $payment->payment_id, $update_invoice );
		}

		Remember_Logger::info(
			'Xero invoice created',
			array(
				'application_id'   => $application_id,
				'invoice_id'       => $xero_invoice_id,
				'xero_contact_id'  => $xero_contact_id,
			)
		);

		if ( isset( $invoice_result['AmountDue'] ) ) {
			$amount_due = floatval( $invoice_result['AmountDue'] );
		} elseif ( isset( $invoice_result['Total'] ) ) {
			$amount_due = floatval( $invoice_result['Total'] );
		} else {
			$amount_due = $total_amount;
		}
		self::apply_open_credit_to_invoice( $xero_invoice_id, $xero_contact_id, $amount_due );

		$payment_row = $payment_model->get_by_application( $application_id );
		if ( $payment_row && ! empty( $payment_row->xero_invoice_id ) ) {
			$synced = self::sync_payment_status( $payment_row->payment_id );
			if ( is_wp_error( $synced ) ) {
				Remember_Logger::warning(
					'Xero invoice created but payment sync after credit allocation failed',
					array(
						'application_id' => $application_id,
						'error'          => $synced->get_error_message(),
					)
				);
			}
		}

		return $invoice_result;
	}

	/**
	 * Whether create_invoice_for_application recorded a paid zero charge and sent no invoice.
	 *
	 * @param mixed $result Return value from create_invoice_for_application().
	 * @return bool
	 */
	public static function is_zero_charge_result( $result ) {
		return is_array( $result ) && ! empty( $result['remember_zero_charge'] );
	}

	/**
	 * Mark an application paid at $0 when there is nothing to invoice.
	 *
	 * @param int   $application_id Application ID.
	 * @param int   $member_id      Member ID.
	 * @param float $role_cost      Event role price.
	 * @return array{remember_zero_charge:true}
	 */
	private static function record_zero_charge_payment( $application_id, $member_id, $role_cost ) {
		$payment_model = new Remember_Payment();
		$payment       = $payment_model->get_by_application( $application_id );
		$data          = array(
			'role_cost'        => $role_cost,
			'merchandise_cost' => 0,
			'total_amount'     => 0,
			'amount_paid'      => 0,
			'amount_due'       => 0,
			'payment_status'   => 'paid',
			'payment_date'     => current_time( 'mysql' ),
		);
		if ( ! $payment ) {
			$data['event_application_id'] = $application_id;
			$data['member_id']            = $member_id;
			$payment_model->create( $data );
		} elseif ( empty( $payment->xero_invoice_id ) && empty( $payment->quickbooks_invoice_id ) ) {
			$payment_model->update( $payment->payment_id, $data );
		}

		Remember_Logger::info(
			'Application marked paid with no invoice because the charge is zero',
			array( 'application_id' => $application_id )
		);

		return array( 'remember_zero_charge' => true );
	}

	/**
	 * Unallocated authorised credit still sitting on a member's Xero contact.
	 *
	 * @param int $member_id WordPress user ID.
	 * @return array{total:float,count:int,notes:array<int,array{id:string,number:string,remaining:float,sort_ts:int,date:string}>}|null Null when Xero is not in use or the contact has no open credit.
	 */
	public static function member_open_credit( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return null;
		}
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-provider.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-xero-oauth.php';
		if ( ! Remember_Billing_Provider::is_xero() || ! Remember_Xero_OAuth::is_connected() ) {
			return null;
		}
		$contact_id = get_user_meta( $member_id, 'remember_xero_contact_id', true );
		if ( ! $contact_id ) {
			return null;
		}
		$notes = Remember_Xero_API::get_open_credit_notes( $contact_id );
		if ( is_wp_error( $notes ) || empty( $notes ) ) {
			return null;
		}
		$total = 0.0;
		foreach ( $notes as $note ) {
			$total += floatval( $note['remaining'] );
		}
		$total = round( $total, 2 );
		if ( $total <= 0 ) {
			return null;
		}
		return array(
			'total' => $total,
			'count' => count( $notes ),
			'notes' => $notes,
		);
	}

	/**
	 * Admin notices for the credit allocation that ran with the latest invoice create.
	 *
	 * @return string HTML, or empty.
	 */
	public static function credit_apply_admin_notices() {
		if ( ! is_array( self::$credit_apply ) ) {
			return '';
		}
		$html = '';
		if ( self::$credit_apply['applied'] > 0 ) {
			$html .= '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: currency amount */
					__( 'Applied %s of open Xero credit to the invoice before emailing.', 'remember' ),
					number_format_i18n( self::$credit_apply['applied'], 2 )
				)
			) . '</p></div>';
		}
		if ( '' !== self::$credit_apply['error'] ) {
			$html .= '<div class="notice notice-warning is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: error message */
					__( 'Open Xero credit could not be fully applied, so the invoice was not emailed: %s', 'remember' ),
					self::$credit_apply['error']
				)
			) . '</p></div>';
		}
		return $html;
	}

	/**
	 * Whether the invoice email should be skipped because credit allocation failed.
	 *
	 * @return bool
	 */
	public static function invoice_email_held() {
		return is_array( self::$credit_apply ) && ! empty( self::$credit_apply['hold_email'] );
	}

	/**
	 * Allocate unallocated credit notes onto a new invoice, oldest note first.
	 *
	 * No-op when the setting is off. On an allocation error, holds the invoice email.
	 *
	 * @param string $invoice_id InvoiceID.
	 * @param string $contact_id ContactID.
	 * @param float  $amount_due Amount still due on the invoice.
	 * @return void
	 */
	private static function apply_open_credit_to_invoice( $invoice_id, $contact_id, $amount_due ) {
		self::$credit_apply = null;
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-provider.php';
		if ( ! Remember_Billing_Provider::should_auto_apply_xero_credit() ) {
			return;
		}

		$left = round( floatval( $amount_due ), 2 );
		if ( '' === trim( (string) $invoice_id ) || $left <= 0.001 ) {
			self::$credit_apply = array(
				'applied'    => 0.0,
				'numbers'    => array(),
				'error'      => '',
				'hold_email' => false,
			);
			return;
		}

		$notes = Remember_Xero_API::get_open_credit_notes( $contact_id );
		if ( is_wp_error( $notes ) ) {
			self::$credit_apply = array(
				'applied'    => 0.0,
				'numbers'    => array(),
				'error'      => $notes->get_error_message(),
				'hold_email' => true,
			);
			Remember_Logger::warning(
				'Could not load open Xero credit notes for a new invoice',
				array(
					'invoice_id' => $invoice_id,
					'error'      => $notes->get_error_message(),
				)
			);
			return;
		}

		$applied = 0.0;
		$numbers = array();
		$error   = '';
		foreach ( $notes as $note ) {
			if ( $left <= 0.001 ) {
				break;
			}
			$take = round( min( $left, floatval( $note['remaining'] ) ), 2 );
			if ( $take <= 0 ) {
				continue;
			}
			$result = Remember_Xero_API::allocate_credit_note_to_invoice( $note['id'], $invoice_id, $take );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
				Remember_Logger::warning(
					'Failed to allocate a Xero credit note onto a new invoice',
					array(
						'invoice_id'     => $invoice_id,
						'credit_note_id' => $note['id'],
						'amount'         => $take,
						'error'          => $error,
					)
				);
				break;
			}
			$applied += $take;
			$left    -= $take;
			if ( '' !== $note['number'] ) {
				$numbers[] = $note['number'];
			}
		}

		self::$credit_apply = array(
			'applied'    => round( $applied, 2 ),
			'numbers'    => $numbers,
			'error'      => $error,
			'hold_email' => '' !== $error,
		);
		if ( $applied > 0 ) {
			Remember_Logger::info(
				'Applied open Xero credit to a new invoice',
				array(
					'invoice_id' => $invoice_id,
					'applied'    => round( $applied, 2 ),
					'notes'      => $numbers,
				)
			);
		}
	}

	/**
	 * Sales discount percent stored on the Xero contact.
	 *
	 * A contact save does not always return Discount, so a missing value is read again.
	 *
	 * @param mixed  $sync_result Contact returned by the member sync.
	 * @param string $contact_id  ContactID.
	 * @return float
	 */
	private static function contact_sales_discount_rate( $sync_result, $contact_id ) {
		if ( is_array( $sync_result ) && isset( $sync_result['Discount'] ) && is_numeric( $sync_result['Discount'] ) ) {
			return Remember_Xero_API::contact_discount_rate( $sync_result );
		}

		$contact = Remember_Xero_API::get_contact( $contact_id );
		if ( is_wp_error( $contact ) ) {
			Remember_Logger::warning(
				'Could not read the Xero contact discount',
				array(
					'xero_contact_id' => $contact_id,
					'error'           => $contact->get_error_message(),
				)
			);
			return 0.0;
		}

		return Remember_Xero_API::contact_discount_rate( $contact );
	}

	/**
	 * Build a create_invoice line payload from a mapped Xero ItemID.
	 *
	 * @param string $item_id     Xero ItemID.
	 * @param int    $quantity    Quantity.
	 * @param float  $line_total  Line total (UnitAmount = total / qty).
	 * @param string $description Line description.
	 * @return array|WP_Error
	 */
	private static function build_line_item_from_xero_item( $item_id, $quantity, $line_total, $description ) {
		$quantity = max( 1, intval( $quantity ) );
		$item     = Remember_Xero_API::get_item( $item_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		if ( empty( $item['Code'] ) ) {
			return new WP_Error( 'xero_item_no_code', __( 'Xero item is missing a Code.', 'remember' ) );
		}

		$unit_amount = floatval( $line_total ) / $quantity;
		$line        = array(
			'item_code'   => (string) $item['Code'],
			'quantity'    => $quantity,
			'unit_amount' => $unit_amount,
			'description' => $description,
		);

		if ( ! empty( $item['SalesDetails']['AccountCode'] ) ) {
			$line['account_code'] = (string) $item['SalesDetails']['AccountCode'];
		}
		if ( ! empty( $item['SalesDetails']['TaxType'] ) ) {
			$line['tax_type'] = (string) $item['SalesDetails']['TaxType'];
		}

		return $line;
	}

	/**
	 * Sync payment/refund status for one payment row from Xero.
	 *
	 * @param int $payment_id Payment ID.
	 * @return true|WP_Error
	 */
	public static function sync_payment_status( $payment_id ) {
		$payment_model = new Remember_Payment();
		$payment       = $payment_model->get( $payment_id );

		if ( ! $payment || empty( $payment->xero_invoice_id ) ) {
			return new WP_Error( 'no_invoice', __( 'No Xero invoice ID found for this payment.', 'remember' ) );
		}

		$invoice = Remember_Xero_API::get_invoice( $payment->xero_invoice_id );
		if ( is_wp_error( $invoice ) ) {
			if ( self::is_xero_invoice_missing_error( $invoice ) ) {
				Remember_Logger::info(
					'Xero invoice no longer exists; clearing local invoice link',
					array(
						'payment_id'      => $payment_id,
						'xero_invoice_id' => $payment->xero_invoice_id,
					)
				);
				self::clear_local_xero_invoice_link( $payment_id );
				return true;
			}
			return $invoice;
		}

		if ( self::is_xero_invoice_voided_or_deleted( $invoice ) ) {
			Remember_Logger::info(
				'Xero invoice is voided/deleted; marking local invoice cancelled',
				array(
					'payment_id'      => $payment_id,
					'xero_invoice_id' => $payment->xero_invoice_id,
					'status'          => isset( $invoice['Status'] ) ? $invoice['Status'] : '',
				)
			);
			self::mark_xero_invoice_gone( $payment_id, false );
			return true;
		}

		$detail_lines = Remember_Xero_API::get_invoice_payment_lines( $payment->xero_invoice_id );
		if ( is_wp_error( $detail_lines ) ) {
			return $detail_lines;
		}

		$total_paid = 0;
		foreach ( $detail_lines as $row ) {
			$total_paid += floatval( $row['amount'] ?? 0 );
		}

		$invoice_sort_ts = Remember_Xero_API::xero_entity_sort_timestamp( $invoice );

		// Fallback when Payments list is empty but invoice shows paid.
		if ( empty( $detail_lines ) ) {
			$amount_due  = isset( $invoice['AmountDue'] ) ? floatval( $invoice['AmountDue'] ) : null;
			$amount_paid = isset( $invoice['AmountPaid'] ) ? floatval( $invoice['AmountPaid'] ) : 0.0;
			if ( null !== $amount_due && $amount_due <= 0.001 && $amount_paid > 0 ) {
				$total_paid  = $amount_paid;
				$pay_sort_ts = $invoice_sort_ts > 0 ? $invoice_sort_ts + 1 : strtotime( current_time( 'mysql' ) );
				$detail_lines = array(
					array(
						'amount'         => $amount_paid,
						'txn_date'       => ! empty( $invoice['FullyPaidOnDate'] )
							? Remember_Xero_API::normalize_xero_date( $invoice['FullyPaidOnDate'] )
							: ( ! empty( $invoice['Date'] ) ? Remember_Xero_API::normalize_xero_date( $invoice['Date'] ) : '' ),
						'payment_method' => '',
						'qb_payment_id'  => '',
						'sort_ts'        => $pay_sort_ts,
					),
				);
			}
		}

		$xero_contact_id = get_user_meta( $payment->member_id, 'remember_xero_contact_id', true );
		if ( ! $xero_contact_id && ! empty( $invoice['Contact']['ContactID'] ) ) {
			$xero_contact_id = $invoice['Contact']['ContactID'];
		}
		$refund_lines = Remember_Xero_API::get_invoice_refund_lines( $payment->xero_invoice_id, $xero_contact_id );
		if ( is_wp_error( $refund_lines ) ) {
			return $refund_lines;
		}

		// Credit-note allocations reduce amount owed; they are not cash refunds of payments.
		$total_credited = 0;
		foreach ( $refund_lines as $rrow ) {
			$total_credited += floatval( $rrow['amount'] ?? 0 );
		}

		$xero_total     = isset( $invoice['Total'] ) ? floatval( $invoice['Total'] ) : null;
		$total_amt      = null !== $xero_total ? $xero_total : floatval( $payment->total_amount );
		$xero_due       = isset( $invoice['AmountDue'] ) ? floatval( $invoice['AmountDue'] ) : null;
		$xero_paid      = isset( $invoice['AmountPaid'] ) ? floatval( $invoice['AmountPaid'] ) : null;
		$xero_credited  = isset( $invoice['AmountCredited'] ) ? floatval( $invoice['AmountCredited'] ) : null;
		if ( null !== $xero_credited && ( $xero_credited - $total_credited ) > 0.001 ) {
			$gap            = round( $xero_credited - $total_credited, 2 );
			$total_credited += $gap;
			$refund_lines[]  = array(
				'amount'         => $gap,
				'txn_date'       => ! empty( $invoice['Date'] ) ? Remember_Xero_API::normalize_xero_date( $invoice['Date'] ) : '',
				'payment_method' => __( 'Allocated credit', 'remember' ),
				'qb_refund_id'   => 'xero-amount-credited',
				'doc_number'     => '',
				'sort_ts'        => $invoice_sort_ts > 0 ? $invoice_sort_ts + 2 : strtotime( current_time( 'mysql' ) ),
				'ledger_effect'  => 'credit',
			);
		}

		if ( null !== $xero_paid ) {
			$amount_paid_out = max( 0, $xero_paid );
		} else {
			$amount_paid_out = max( 0, $total_paid );
		}

		if ( null !== $xero_due ) {
			$amount_due = max( 0, $xero_due );
		} else {
			$amount_due = max( 0, $total_amt - $amount_paid_out - $total_credited );
		}

		if ( $amount_due <= 0.001 ) {
			$amount_due = 0;
			if ( $amount_paid_out <= 0.001 && $total_credited > 0.001 ) {
				$payment_status = 'refunded';
			} else {
				$payment_status  = 'paid';
				$amount_paid_out = $amount_paid_out > 0 ? $amount_paid_out : $total_amt;
			}
		} elseif ( $amount_paid_out > 0.001 || $total_credited > 0.001 ) {
			$payment_status = 'partial';
		} else {
			$payment_status  = 'pending';
			$amount_paid_out = 0;
			if ( null === $xero_due ) {
				$amount_due = $total_amt;
			}
		}

		$reconstructed_due = $total_amt - $amount_paid_out - $total_credited;
		if ( ( $reconstructed_due - $amount_due ) > 0.001 ) {
			$gap             = round( $reconstructed_due - $amount_due, 2 );
			$total_credited += $gap;
			$refund_lines[]  = array(
				'amount'         => $gap,
				'txn_date'       => ! empty( $invoice['Date'] ) ? Remember_Xero_API::normalize_xero_date( $invoice['Date'] ) : '',
				'payment_method' => __( 'Allocated credit', 'remember' ),
				'qb_refund_id'   => 'xero-amount-due-balance',
				'doc_number'     => '',
				'sort_ts'        => $invoice_sort_ts > 0 ? $invoice_sort_ts + 3 : strtotime( current_time( 'mysql' ) ),
				'ledger_effect'  => 'credit',
			);
		}

		$total_refunded = $total_credited;

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
			'amount_paid'          => $amount_paid_out,
			'amount_due'           => $amount_due,
			'total_amount'         => $total_amt,
			'payment_status'       => $payment_status,
			'payment_date'         => $has_money_activity
				? ( $latest_ts > 0 ? date( 'Y-m-d H:i:s', $latest_ts ) : current_time( 'mysql' ) )
				: null,
			'xero_payment_lines'   => ! empty( $detail_lines ) ? wp_json_encode( $detail_lines ) : null,
			'xero_refund_lines'    => ! empty( $refund_lines ) ? wp_json_encode( $refund_lines ) : null,
			'xero_invoice_sort_ts' => $invoice_sort_ts > 0 ? $invoice_sort_ts : null,
		);
		if ( isset( $invoice['InvoiceNumber'] ) ) {
			$update_data['xero_invoice_number'] = sanitize_text_field( (string) $invoice['InvoiceNumber'] );
		}
		if ( empty( $payment->xero_online_invoice_url ) ) {
			$online_url = Remember_Xero_API::get_online_invoice_url( $payment->xero_invoice_id );
			if ( '' !== $online_url ) {
				$update_data['xero_online_invoice_url'] = $online_url;
			}
		}
		$payment_model->update( $payment_id, $update_data );

		Remember_Logger::info(
			'Payment status synced from Xero',
			array(
				'payment_id'     => $payment_id,
				'amount_paid'    => $amount_paid_out,
				'total_refunded' => $total_refunded,
				'payment_status' => $payment_status,
			)
		);

		return true;
	}

	/**
	 * Sync payment status for one member's Xero-linked payment rows.
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
			if ( empty( $payment->xero_invoice_id ) ) {
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
	 * Sync all payments that reference a Xero invoice.
	 *
	 * @return array{success:int,error:int,errors:array}
	 */
	public static function sync_all_payments() {
		global $wpdb;

		$payments = $wpdb->get_results(
			"SELECT payment_id FROM {$wpdb->prefix}remember_payments
			WHERE xero_invoice_id IS NOT NULL
			AND xero_invoice_id != ''
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

		$wpdb->update(
			$wpdb->prefix . 'remember_payment_processors',
			array( 'last_sync_at' => current_time( 'mysql' ) ),
			array( 'processor_type' => 'xero' ),
			array( '%s' ),
			array( '%s' )
		);

		return $results;
	}

	/**
	 * Clear Xero invoice fields on a local payment row.
	 *
	 * @param int $payment_id Payment ID.
	 */
	private static function mark_xero_invoice_gone( $payment_id, $clear_ids = true ) {
		$payment_model = new Remember_Payment();
		$data          = array(
			'amount_paid'         => 0,
			'amount_due'          => 0,
			'payment_status'      => 'cancelled',
			'payment_date'        => null,
			'xero_payment_lines'  => null,
			'xero_refund_lines'   => null,
		);
		if ( $clear_ids ) {
			$data['xero_invoice_id']         = null;
			$data['xero_invoice_number']     = null;
			$data['xero_online_invoice_url'] = null;
			$data['xero_invoice_sort_ts']    = null;
		}
		$payment_model->update( $payment_id, $data );
	}

	/**
	 * Clear Xero invoice fields on a local payment row (recreate path).
	 *
	 * @param int $payment_id Payment ID.
	 */
	private static function clear_local_xero_invoice_link( $payment_id ) {
		self::mark_xero_invoice_gone( $payment_id, true );
	}

	/**
	 * Whether a Xero invoice payload is voided or deleted.
	 *
	 * @param array $invoice Invoice entity.
	 * @return bool
	 */
	private static function is_xero_invoice_voided_or_deleted( $invoice ) {
		if ( ! is_array( $invoice ) ) {
			return false;
		}
		$status = isset( $invoice['Status'] ) ? strtoupper( (string) $invoice['Status'] ) : '';
		return in_array( $status, array( 'VOIDED', 'DELETED' ), true );
	}

	/**
	 * Whether a Xero API error means the invoice is missing.
	 *
	 * Only treat hard not-found responses as missing. Do NOT match arbitrary
	 * "not found" substrings (e.g. "Refresh token not found") — that incorrectly
	 * clears local invoice links and payment amounts.
	 *
	 * @param WP_Error $error Error from get_invoice().
	 * @return bool
	 */
	private static function is_xero_invoice_missing_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		if ( 'xero_invoice_not_found' === $error->get_error_code() ) {
			return true;
		}
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] === 404 ) {
			return true;
		}
		return false;
	}
}
