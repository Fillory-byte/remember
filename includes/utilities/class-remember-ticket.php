<?php
/**
 * Admission ticket data assembler.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds ticket DTOs and access helpers for printable admission tickets.
 */
class Remember_Ticket {

	/**
	 * Whether an application is eligible for a ticket view.
	 *
	 * @param object $application Application row.
	 * @return bool
	 */
	public static function is_eligible( $application ) {
		if ( ! $application || empty( $application->application_id ) ) {
			return false;
		}
		if ( ! empty( $application->ticket_voided ) ) {
			return true;
		}
		return isset( $application->status ) && 'accepted' === $application->status;
	}

	/**
	 * Ticket URL for print/view.
	 *
	 * @param int $application_id Application ID.
	 * @return string
	 */
	public static function get_ticket_url( $application_id ) {
		$application_id = absint( $application_id );
		$url            = add_query_arg(
			array(
				'remember_ticket' => $application_id,
			),
			home_url( '/' )
		);
		return wp_nonce_url( $url, 'remember_ticket_' . $application_id, 'remember_ticket_nonce' );
	}

	/**
	 * Whether the current (or given) user may view this ticket.
	 *
	 * @param int      $application_id Application ID.
	 * @param int|null $user_id        Optional user ID (defaults to current).
	 * @return bool
	 */
	public static function user_can_view( $application_id, $user_id = null ) {
		$application_id = absint( $application_id );
		if ( $application_id <= 0 ) {
			return false;
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		// Staff with application read/update may view any eligible ticket; members may view their own.
		if (
			user_can( $user_id, 'remember_read_applications' )
			|| user_can( $user_id, 'remember_update_applications' )
		) {
			return true;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		$application_model = new Remember_Application();
		$application       = $application_model->get( $application_id );
		if ( ! $application || ! self::is_eligible( $application ) ) {
			return false;
		}

		return absint( $application->member_id ) === $user_id;
	}

	/**
	 * Logo URL: reMember override, else WP custom logo.
	 *
	 * @return string
	 */
	public static function get_logo_url() {
		$options = get_option( 'remember_options', array() );
		if ( ! empty( $options['ticket_logo_id'] ) ) {
			$url = wp_get_attachment_image_url( absint( $options['ticket_logo_id'] ), 'medium' );
			if ( $url ) {
				return $url;
			}
		}

		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id > 0 ) {
			$url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Adverse stamp only: void | payment_required | empty.
	 *
	 * @param array $data Ticket data from get_data().
	 * @return string
	 */
	public static function get_stamp( $data ) {
		if ( ! empty( $data['ticket_voided'] ) ) {
			return 'void';
		}
		$payment_status = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
		if ( in_array( $payment_status, array( 'cancelled', 'refunded' ), true ) ) {
			return 'void';
		}
		if ( in_array( $payment_status, array( 'pending', 'partial', '' ), true ) ) {
			return 'payment_required';
		}
		return '';
	}

	/**
	 * Assemble ticket data for an application.
	 *
	 * @param int $application_id Application ID.
	 * @return array|WP_Error
	 */
	public static function get_data( $application_id ) {
		global $wpdb;

		$application_id = absint( $application_id );
		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-location.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-checkin.php';

		$application_model = new Remember_Application();
		$application       = $application_model->get( $application_id );
		if ( ! $application ) {
			return new WP_Error( 'remember_ticket_not_found', __( 'Application not found.', 'remember' ) );
		}
		if ( ! self::is_eligible( $application ) ) {
			return new WP_Error( 'remember_ticket_not_eligible', __( 'A ticket is only available for accepted applications.', 'remember' ) );
		}

		$event_model = new Remember_Event();
		$event       = $event_model->get( $application->event_id );

		$location = null;
		if ( $event && ! empty( $event->location_id ) ) {
			$location_model = new Remember_Location();
			$location       = $location_model->get( $event->location_id );
		}

		$event_role = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er
				LEFT JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
				WHERE er.event_role_id = %d",
				$application->event_role_id
			)
		);

		$merch_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT am.*, em.merchandise_name
				FROM {$wpdb->prefix}remember_application_merchandise am
				LEFT JOIN {$wpdb->prefix}remember_event_merchandise em ON am.merchandise_id = em.merchandise_id
				WHERE am.event_application_id = %d
				ORDER BY am.application_merchandise_id ASC",
				$application_id
			)
		);

		$payment_model = new Remember_Payment();
		$payment       = $payment_model->get_by_application( $application_id );

		$user = get_user_by( 'ID', $application->member_id );

		$line_items = array();
		if ( $event_role && floatval( $event_role->cost ) > 0 ) {
			$line_items[] = array(
				'description' => sprintf(
					/* translators: 1: role name, 2: event name */
					__( '%1$s — %2$s', 'remember' ),
					$event_role->role_name,
					$event ? $event->event_name : ''
				),
				'quantity'    => 1,
				'unit_cost'   => floatval( $event_role->cost ),
				'total'       => floatval( $event_role->cost ),
			);
		} elseif ( $event_role ) {
			$line_items[] = array(
				'description' => sprintf(
					/* translators: 1: role name, 2: event name */
					__( '%1$s — %2$s', 'remember' ),
					$event_role->role_name,
					$event ? $event->event_name : ''
				),
				'quantity'    => 1,
				'unit_cost'   => 0,
				'total'       => 0,
			);
		}

		if ( $merch_rows ) {
			foreach ( $merch_rows as $row ) {
				$line_items[] = array(
					'description' => ! empty( $row->merchandise_name ) ? $row->merchandise_name : __( 'Add-on', 'remember' ),
					'quantity'    => absint( $row->quantity ),
					'unit_cost'   => floatval( $row->unit_cost ),
					'total'       => floatval( $row->total_cost ),
				);
			}
		}

		$payment_status = $payment ? (string) $payment->payment_status : '';
		$total_amount   = $payment ? floatval( $payment->total_amount ) : 0;
		$amount_paid    = $payment ? floatval( $payment->amount_paid ) : 0;
		$amount_due     = $payment ? floatval( $payment->amount_due ) : 0;

		if ( ! $payment ) {
			foreach ( $line_items as $item ) {
				$total_amount += floatval( $item['total'] );
			}
			$amount_due = $total_amount;
		}

		$list_total       = 0.0;
		foreach ( $line_items as $item ) {
			$list_total += floatval( $item['total'] );
		}
		$list_total       = round( $list_total, 2 );
		$discount_amount  = round( $list_total - round( $total_amount, 2 ), 2 );
		$discount_percent = 0.0;
		if ( $discount_amount > 0.001 && $list_total > 0.001 ) {
			$discount_percent = round( ( $discount_amount / $list_total ) * 100, 2 );
		} else {
			$discount_amount = 0.0;
		}

		$invoice_number = '';
		if ( $payment ) {
			if ( ! empty( $payment->xero_invoice_number ) ) {
				$invoice_number = $payment->xero_invoice_number;
			} elseif ( ! empty( $payment->quickbooks_invoice_number ) ) {
				$invoice_number = $payment->quickbooks_invoice_number;
			}
		}

		$location_lines = array();
		if ( $location ) {
			if ( ! empty( $location->location_name ) ) {
				$location_lines[] = $location->location_name;
			}
			$street = trim( (string) ( $location->address_street ?? '' ) );
			if ( '' !== $street ) {
				$location_lines[] = $street;
			}
			$city_line = trim(
				implode(
					', ',
					array_filter(
						array(
							$location->address_city ?? '',
							$location->address_state ?? '',
							$location->address_postal ?? '',
						)
					)
				)
			);
			if ( '' !== $city_line ) {
				$location_lines[] = $city_line;
			}
			if ( ! empty( $location->address_country ) ) {
				$location_lines[] = $location->address_country;
			}
		}

		$dates = '';
		if ( $event && ! empty( $event->start_date ) ) {
			$dates = date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) );
			if ( ! empty( $event->end_date ) && $event->end_date !== $event->start_date ) {
				$dates .= ' – ' . date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) );
			}
		}

		$data = array(
			'application_id'     => (int) $application->application_id,
			'ticket_id'          => 'RM-' . (int) $application->application_id,
			'ticket_voided'      => ! empty( $application->ticket_voided ),
			'application_status' => $application->status,
			'member_id'          => (int) $application->member_id,
			'member_name'        => self::attendee_name( $user ),
			'member_email'       => $user ? $user->user_email : '',
			'event_id'           => $event ? (int) $event->event_id : 0,
			'event_name'         => $event ? $event->event_name : __( 'Unknown Event', 'remember' ),
			'event_dates'        => $dates,
			'location_lines'     => $location_lines,
			'role_name'          => $event_role ? $event_role->role_name : '',
			'line_items'         => $line_items,
			'discount_amount'    => $discount_amount,
			'discount_percent'   => $discount_percent,
			'payment_status'     => $payment_status,
			'total_amount'       => $total_amount,
			'amount_paid'        => $amount_paid,
			'amount_due'         => $amount_due,
			'invoice_number'     => $invoice_number,
			'checkin_code'       => ( $event && ! empty( $event->checkin_enabled ) ) ? Remember_Checkin::code( $application->application_id, $event->event_id ) : '',
			'vendor_name'        => get_bloginfo( 'name' ),
			'vendor_url'         => home_url( '/' ),
			'logo_url'           => self::get_logo_url(),
			'generated_at'       => current_time( 'mysql' ),
		);

		$data['stamp'] = self::get_stamp( $data );

		return $data;
	}

	/**
	 * Name printed on a ticket: legal first and last, not the public display name.
	 *
	 * A blank legal name falls back to the WordPress first and last name, then the display name, so the ticket is never blank.
	 *
	 * @param WP_User|false $user Member user.
	 * @return string
	 */
	private static function attendee_name( $user ) {
		if ( ! $user ) {
			return __( 'Unknown Member', 'remember' );
		}

		global $wpdb;
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT legal_first_name, legal_last_name FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
				$user->ID
			)
		);
		$legal = '';
		if ( $profile ) {
			$legal = trim( trim( (string) $profile->legal_first_name ) . ' ' . trim( (string) $profile->legal_last_name ) );
		}
		if ( '' === $legal ) {
			$legal = trim(
				trim( (string) get_user_meta( $user->ID, 'first_name', true ) ) . ' ' . trim( (string) get_user_meta( $user->ID, 'last_name', true ) )
			);
		}
		if ( '' !== $legal ) {
			return $legal;
		}

		return $user->display_name ? $user->display_name : __( 'Unknown Member', 'remember' );
	}

	/**
	 * Set or clear ticket voided flag.
	 *
	 * @param int  $application_id Application ID.
	 * @param bool $voided         Voided state.
	 * @return int|false
	 */
	public static function set_voided( $application_id, $voided = true ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		$application_model = new Remember_Application();
		return $application_model->update(
			absint( $application_id ),
			array(
				'ticket_voided' => $voided ? 1 : 0,
			)
		);
	}
}
