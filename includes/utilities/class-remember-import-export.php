<?php
/**
 * Import/Export utility class
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../models/class-location.php';
require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . 'class-remember-capabilities.php';

/**
 * Import/Export utility class.
 *
 * Handles CSV import/export for Members, Events, and Locations.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */
class Remember_Import_Export {

	/**
	 * Download template CSV for members.
	 *
	 * @return void
	 */
	public static function download_members_template() {
		$filename = 'members-template.csv';
		
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		
		$output = fopen( 'php://output', 'w' );
		
		// Add BOM for Excel compatibility
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		
		$headers = self::member_csv_headers();
		self::write_csv_line( $output, $headers );

		// Example row (core columns; custom field short-name columns empty).
		$example = array(
			'', // User ID
			'john.doe@example.com',
			'John Doe',
			'Ghost',
			'John',
			'Doe',
			'pending_vetting',
			'John',
			'Doe',
			'123 Main St',
			'Anytown',
			'CA',
			'12345',
			'US',
			'+15551234567',
			'America/Los_Angeles',
			'@johndoe',
			'telegram',
			'L',
			'L',
			'10',
			'L',
			'L',
			'10',
			'Gaming, Hiking',
		);
		if ( current_user_can( 'remember_access_emergency_contact' ) ) {
			$example = array_merge( $example, array( 'Jane', 'Doe', '+15559876543', 'Spouse' ) );
		}
		if ( current_user_can( 'remember_access_health' ) ) {
			$example = array_merge( $example, array( 'Vegetarian', 'Peanuts', '' ) );
		}
		while ( count( $example ) < count( $headers ) ) {
			$example[] = '';
		}
		self::write_csv_line( $output, $example );
		
		fclose( $output );
		exit;
	}

	/**
	 * Member CSV column headers (core + custom profile question short names).
	 *
	 * Emergency contact and health columns are included only when the current
	 * user may read them, so exports and templates never leak those fields.
	 *
	 * @return array<int, string>
	 */
	public static function member_csv_headers() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		$headers = array(
			'User ID',
			'Email',
			'Display Name',
			'Nickname',
			'First Name',
			'Last Name',
			'Status',
			'Legal First Name',
			'Legal Last Name',
			'Street Address',
			'City',
			'State',
			'Postal Code',
			'Country',
			'Cell Phone',
			'Timezone',
			'IM Handle',
			'IM Type',
			'Shirt Size',
			'Pants Size',
			'Shoe Size',
			'Available Shirt',
			'Available Pants',
			'Available Shoe',
			'Interests',
		);
		if ( current_user_can( 'remember_access_emergency_contact' ) ) {
			$headers = array_merge(
				$headers,
				array(
					'Emergency Contact First',
					'Emergency Contact Last',
					'Emergency Contact Phone',
					'Emergency Contact Relationship',
				)
			);
		}
		if ( current_user_can( 'remember_access_health' ) ) {
			$headers = array_merge(
				$headers,
				array(
					'Dietary Restrictions',
					'Allergies',
					'Allergy Reaction',
					'Medical Accommodations',
				)
			);
		}
		return array_merge( $headers, Remember_Profile_Questions::export_field_keys() );
	}

	/**
	 * Open a CSV download or a file. Sends HTTP headers only for a download.
	 *
	 * @param string $path     Empty to stream the download.
	 * @param string $filename Download filename.
	 * @return resource|false
	 */
	private static function begin_csv_export( $path, $filename ) {
		$path = (string) $path;
		if ( '' === $path ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			$output = fopen( 'php://output', 'w' );
		} else {
			$output = fopen( $path, 'w' );
		}
		if ( ! is_resource( $output ) ) {
			return false;
		}
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		return $output;
	}

	/**
	 * Close a CSV export. A browser download ends the request.
	 *
	 * @param resource $output Handle.
	 * @param string   $path   Empty when this is a download.
	 * @return bool
	 */
	private static function finish_csv_export( $output, $path ) {
		fclose( $output );
		if ( '' === (string) $path ) {
			exit;
		}
		return true;
	}

	/**
	 * Export members to CSV.
	 *
	 * @param string $path File path. Empty streams a download and exits.
	 * @return bool True when a file was written.
	 */
	public static function export_members( $path = '' ) {
		if ( ! current_user_can( 'remember_read_members' ) && ! current_user_can( 'remember_read_attendees' ) ) {
			wp_die( __( 'You do not have sufficient permissions to export members.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		$member_model = new Remember_Member();
		$has_members_access   = current_user_can( 'remember_read_members' );
		$has_attendees_access = current_user_can( 'remember_read_attendees' );
		$is_attendees_only    = $has_attendees_access && ! $has_members_access;

		if ( $is_attendees_only ) {
			$members = $member_model->get_attendees_for_guard( get_current_user_id() );
		} else {
			$members = $member_model->get_all();
		}
		if ( ! is_array( $members ) ) {
			$members = array();
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';
		$remember_export_what = Remember_Access_Log::what_from_topics( Remember_Access_Log::topics_for_current_user() );
		if ( '' !== $remember_export_what ) {
			Remember_Access_Log::record( 0, $remember_export_what, __( 'Member CSV', 'remember' ), count( $members ) );
		}
		
		$filename = 'members-export-' . date( 'Y-m-d-H-i-s' ) . '.csv';
		$output   = self::begin_csv_export( $path, $filename );
		if ( ! is_resource( $output ) ) {
			return false;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-clothing-sizes.php';
		$headers    = self::member_csv_headers();
		$field_keys = Remember_Profile_Questions::export_field_keys();
		self::write_csv_line( $output, $headers );
		
		// Data rows
		foreach ( $members as $member ) {
			$user = get_user_by( 'ID', $member->member_id );
			if ( ! $user ) {
				continue;
			}
			
			// Get profile
			global $wpdb;
			$profile = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
					$member->member_id
				)
			);

			$timezone = get_user_meta( $user->ID, 'timezone_string', true );
			if ( '' === (string) $timezone && $profile && ! empty( $profile->timezone ) ) {
				$timezone = $profile->timezone;
			}

			$custom = Remember_Profile_Questions::get_responses_by_field_key( (int) $member->member_id );
			$row    = array(
				$member->member_id,
				$user->user_email,
				$user->display_name,
				get_user_meta( $user->ID, 'nickname', true ),
				$user->first_name,
				$user->last_name,
				$member->status,
				$profile->legal_first_name ?? '',
				$profile->legal_last_name ?? '',
				$profile->address_street ?? '',
				$profile->address_city ?? '',
				$profile->address_state ?? '',
				$profile->address_postal ?? '',
				$profile->address_country ?? '',
				$profile->cell_phone ?? '',
				$timezone,
				$profile->im_handle ?? '',
				$profile->im_type ?? '',
				$profile->shirt_size ?? '',
				$profile->pants_size ?? '',
				$profile->shoe_size ?? '',
				Remember_Clothing_Sizes::issued_for( 'shirt', $profile->shirt_size ?? '' ),
				Remember_Clothing_Sizes::issued_for( 'pants', $profile->pants_size ?? '' ),
				Remember_Clothing_Sizes::issued_for( 'shoe', $profile->shoe_size ?? '' ),
				$profile->interests ?? '',
			);
			if ( current_user_can( 'remember_access_emergency_contact' ) ) {
				$row[] = $profile->emergency_contact_first ?? '';
				$row[] = $profile->emergency_contact_last ?? '';
				$row[] = $profile->emergency_contact_phone ?? '';
				$row[] = $profile->emergency_contact_relationship ?? '';
			}
			if ( current_user_can( 'remember_access_health' ) ) {
				$row[] = self::member_list_labels( (int) $member->member_id, 'dietary' );
				$row[] = self::member_list_labels( (int) $member->member_id, 'allergies' );
				$row[] = $profile->allergy_reaction ?? '';
				$row[] = self::member_list_labels( (int) $member->member_id, 'medical' );
			}
			foreach ( $field_keys as $fkey ) {
				$row[] = isset( $custom[ $fkey ] ) ? $custom[ $fkey ] : '';
			}
			self::write_csv_line( $output, $row );
		}
		
		return self::finish_csv_export( $output, $path );
	}

	/**
	 * Download template CSV for events.
	 *
	 * @return void
	 */
	public static function download_events_template() {
		$filename = 'events-template.csv';
		
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		
		$output = fopen( 'php://output', 'w' );
		
		// Add BOM for Excel compatibility
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		
		// Headers only
		self::write_csv_line( $output, array(
			'Event ID',
			'Event Name',
			'Description',
			'Start Date',
			'End Date',
			'Status',
			'Is Private',
			'Location ID',
			'Location Name',
		) );
		
		// Add example row
		self::write_csv_line( $output, array(
			'', // Event ID - leave blank for new events
			'Summer Retreat 2024',
			'Annual summer gathering',
			'2024-07-15',
			'2024-07-20',
			'open',
			'No',
			'', // Location ID - leave blank if using Location Name
			'Main Campus', // Or use Location ID above
		) );
		
		fclose( $output );
		exit;
	}

	/**
	 * Export events to CSV.
	 *
	 * @param string $path File path. Empty streams a download and exits.
	 * @return bool True when a file was written.
	 */
	public static function export_events( $path = '' ) {
		$event_model = new Remember_Event();
		$events = $event_model->get_all();
		
		$filename = 'events-export-' . date( 'Y-m-d-H-i-s' ) . '.csv';
		$output   = self::begin_csv_export( $path, $filename );
		if ( ! is_resource( $output ) ) {
			return false;
		}

		// Headers
		self::write_csv_line( $output, array(
			'Event ID',
			'Event Name',
			'Description',
			'Start Date',
			'End Date',
			'Status',
			'Is Private',
			'Location ID',
			'Location Name',
		) );
		
		// Data rows
		foreach ( $events as $event ) {
			$location_name = '';
			if ( $event->location_id ) {
				$location_model = new Remember_Location();
				$location = $location_model->get( $event->location_id );
				$location_name = $location ? $location->location_name : '';
			}
			
			self::write_csv_line( $output, array(
				$event->event_id,
				$event->event_name,
				$event->event_description ?? '',
				$event->start_date,
				$event->end_date,
				$event->status,
				$event->is_private ? 'Yes' : 'No',
				$event->location_id ?? '',
				$location_name,
			) );
		}

		return self::finish_csv_export( $output, $path );
	}

	/**
	 * Download template CSV for locations.
	 *
	 * @return void
	 */
	public static function download_locations_template() {
		$filename = 'locations-template.csv';
		
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		
		$output = fopen( 'php://output', 'w' );
		
		// Add BOM for Excel compatibility
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		
		// Headers only
		self::write_csv_line( $output, array(
			'Location ID',
			'Location Name',
			'Street Address',
			'City',
			'State',
			'Postal Code',
			'Country',
			'Details',
			'Is Active',
		) );
		
		// Add example row
		self::write_csv_line( $output, array(
			'', // Location ID - leave blank for new locations
			'Main Campus',
			'456 University Ave',
			'Springfield',
			'IL',
			'62701',
			'US',
			'Main event location with parking',
			'Yes',
		) );
		
		fclose( $output );
		exit;
	}

	/**
	 * Export locations to CSV.
	 *
	 * @param string $path File path. Empty streams a download and exits.
	 * @return bool True when a file was written.
	 */
	public static function export_locations( $path = '' ) {
		$location_model = new Remember_Location();
		$locations = $location_model->get_all();
		
		$filename = 'locations-export-' . date( 'Y-m-d-H-i-s' ) . '.csv';
		$output   = self::begin_csv_export( $path, $filename );
		if ( ! is_resource( $output ) ) {
			return false;
		}

		// Headers
		self::write_csv_line( $output, array(
			'Location ID',
			'Location Name',
			'Street Address',
			'City',
			'State',
			'Postal Code',
			'Country',
			'Details',
			'Is Active',
		) );
		
		// Data rows
		foreach ( $locations as $location ) {
			self::write_csv_line( $output, array(
				$location->location_id,
				$location->location_name,
				$location->address_street ?? '',
				$location->address_city ?? '',
				$location->address_state ?? '',
				$location->address_postal ?? '',
				$location->address_country ?? '',
				$location->details ?? '',
				$location->is_active ? 'Yes' : 'No',
			) );
		}

		return self::finish_csv_export( $output, $path );
	}

	/**
	 * True if a name field should be ignored (empty, Excel zero, etc.).
	 *
	 * @param mixed $value Field value.
	 * @return bool
	 */
	private static function member_import_is_placeholder_name( $value ) {
		$s = trim( (string) $value );
		if ( '' === $s ) {
			return true;
		}
		if ( '0' === $s ) {
			return true;
		}
		if ( is_numeric( $s ) && (float) $s === 0.0 ) {
			return true;
		}
		return false;
	}

	/**
	 * Trim; empty string if value is a placeholder.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function member_import_normalize_csv_name( $value ) {
		$s = trim( (string) $value );
		if ( self::member_import_is_placeholder_name( $s ) ) {
			return '';
		}
		return $s;
	}

	/**
	 * Row error for a database write that returned false. Zero changed rows is not a failure.
	 *
	 * @param int    $row_number CSV row number.
	 * @param string $message    What failed, without the row number.
	 * @param string $db_error   Database error captured before any later query.
	 * @return string
	 */
	private static function import_row_write_error( $row_number, $message, $db_error = '' ) {
		$db_error = trim( (string) $db_error );
		if ( '' === $db_error ) {
			return sprintf(
				/* translators: 1: CSV row number, 2: what failed */
				__( 'Row %1$d: %2$s', 'remember' ),
				$row_number,
				$message
			);
		}
		return sprintf(
			/* translators: 1: CSV row number, 2: what failed, 3: database error */
			__( 'Row %1$d: %2$s %3$s', 'remember' ),
			$row_number,
			$message,
			$db_error
		);
	}

	/**
	 * Legal names from CSV + WordPress user when placeholders appear in the sheet.
	 *
	 * @param array    $row_data Row keyed by CSV header.
	 * @param int|null $user_id  WordPress user ID after create/update, or null.
	 * @return array With keys legal_first_name and legal_last_name.
	 */
	private static function member_import_legal_names( $row_data, $user_id ) {
		$legal_first = self::member_import_normalize_csv_name( $row_data['Legal First Name'] ?? '' );
		$legal_last  = self::member_import_normalize_csv_name( $row_data['Legal Last Name'] ?? '' );
		$first       = self::member_import_normalize_csv_name( $row_data['First Name'] ?? '' );
		$last        = self::member_import_normalize_csv_name( $row_data['Last Name'] ?? '' );

		if ( self::member_import_is_placeholder_name( $legal_first ) ) {
			$legal_first = $first;
		}
		if ( self::member_import_is_placeholder_name( $legal_last ) ) {
			$legal_last = $last;
		}

		if ( $user_id ) {
			if ( self::member_import_is_placeholder_name( $legal_first ) ) {
				$meta_first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
				if ( ! self::member_import_is_placeholder_name( $meta_first ) ) {
					$legal_first = $meta_first;
				}
			}
			if ( self::member_import_is_placeholder_name( $legal_last ) ) {
				$meta_last = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
				if ( ! self::member_import_is_placeholder_name( $meta_last ) ) {
					$legal_last = $meta_last;
				}
			}
		}

		// Never derive legal name from display_name — public identity is separate.
		if ( self::member_import_is_placeholder_name( $legal_first ) ) {
			$legal_first = '';
		}
		if ( self::member_import_is_placeholder_name( $legal_last ) ) {
			$legal_last = '';
		}

		return array(
			'legal_first_name' => $legal_first,
			'legal_last_name'  => $legal_last,
		);
	}

	/**
	 * Resolve legal first/last for display when DB has placeholders (e.g. "0") or one side is missing.
	 *
	 * @param object|null $profile Profile row or null.
	 * @param int         $user_id WordPress user ID.
	 * @return array Two strings: first name, last name.
	 */
	public static function member_resolve_legal_name_parts( $profile, $user_id ) {
		if ( ! $profile ) {
			return array( '', '' );
		}
		$first = trim( (string) $profile->legal_first_name );
		$last  = trim( (string) $profile->legal_last_name );

		if ( self::member_import_is_placeholder_name( $first ) ) {
			$first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
		}
		if ( self::member_import_is_placeholder_name( $last ) ) {
			$last = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
		}

		// Do not fall back to display_name: that field is the member's public identity
		// and must not be treated as a legal-name substitute.
		if ( self::member_import_is_placeholder_name( $first ) ) {
			$first = '';
		}
		if ( self::member_import_is_placeholder_name( $last ) ) {
			$last = '';
		}

		return array( $first, $last );
	}

	/**
	 * Human-readable legal name line for admin lists when profile rows still hold placeholders.
	 *
	 * @param object|null $profile Profile row or null.
	 * @param int         $user_id WordPress user ID.
	 * @return string
	 */
	public static function member_list_legal_name_line( $profile, $user_id ) {
		list( $first, $last ) = self::member_resolve_legal_name_parts( $profile, $user_id );
		$line = trim( $first . ' ' . $last );
		// Legacy bad imports stored literal "0" as first name; show surname only for display.
		if ( preg_match( '/^0\s+(.+)/u', $line, $matches ) ) {
			return trim( $matches[1] );
		}
		return $line;
	}

	/**
	 * Import members from CSV.
	 *
	 * @param string $file_path Path to CSV file.
	 * @param bool   $dry_run   Validate rows and skip every save.
	 * @return array Results array with success/error counts.
	 */
	public static function import_members( $file_path, $dry_run = false ) {
		$results = array(
			'success' => 0,
			'error'   => 0,
			'errors'  => array(),
		);
		
		if ( ! file_exists( $file_path ) ) {
			$results['errors'][] = __( 'File not found.', 'remember' );
			return $results;
		}
		
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			$results['errors'][] = __( 'Could not open file for reading.', 'remember' );
			return $results;
		}
		
		// Skip BOM if present
		$bom = fread( $handle, 3 );
		if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
			rewind( $handle );
		}
		
		// Read headers
		$headers = self::read_csv_line( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			$results['errors'][] = __( 'Could not read CSV headers.', 'remember' );
			return $results;
		}
		
		$member_model = new Remember_Member();
		$row_number = 1;
		
		while ( ( $data = self::read_csv_line( $handle ) ) !== false ) {
			$row_number++;
			
			if ( count( $data ) < count( $headers ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Insufficient columns.', 'remember' ), $row_number );
				continue;
			}
			
			// Ignore extra trailing cells (e.g. a trailing comma from Excel).
			$row_data = array_combine( $headers, array_slice( $data, 0, count( $headers ) ) );
			
			// Required fields
			if ( empty( $row_data['Email'] ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Email is required.', 'remember' ), $row_number );
				continue;
			}
			
			$status = 'pending_vetting';
			if ( array_key_exists( 'Status', $row_data ) && '' !== trim( (string) $row_data['Status'] ) ) {
				$status = strtolower( trim( (string) $row_data['Status'] ) );
				if ( ! in_array( $status, Remember_Member::statuses(), true ) ) {
					Remember_Logger::warning(
						'Member import rejected status',
						array(
							'row'    => $row_number,
							'status' => $status,
						)
					);
					$results['error']++;
					$results['errors'][] = sprintf( __( 'Row %d: Status is not a member status.', 'remember' ), $row_number );
					continue;
				}
			}

			// Check if user exists
			$user = get_user_by( 'email', $row_data['Email'] );
			$user_id = null;
			$created_user = false;
			
			if ( ! $user ) {
				// Create WordPress user
				$username = sanitize_user( $row_data['Email'] );
				$password = wp_generate_password( 12, false );
				$display_name = ! empty( $row_data['Display Name'] ) ? $row_data['Display Name'] : $row_data['Email'];

				if ( $dry_run ) {
					$results['success']++;
					continue;
				}
				
				$user_id = wp_create_user( $username, $password, $row_data['Email'] );
				
				if ( is_wp_error( $user_id ) ) {
					$results['error']++;
					$results['errors'][] = sprintf( __( 'Row %d: Could not create user - %s', 'remember' ), $row_number, $user_id->get_error_message() );
					continue;
				}
				$created_user = true;
				
				// Update user data (do not store Excel "0" placeholders in user meta).
				wp_update_user( array(
					'ID'           => $user_id,
					'display_name' => $display_name,
					'first_name'   => self::member_import_normalize_csv_name( $row_data['First Name'] ?? '' ),
					'last_name'    => self::member_import_normalize_csv_name( $row_data['Last Name'] ?? '' ),
				) );
			} else {
				$user_id = $user->ID;
				if ( ! Remember_Capabilities::current_user_can_administer_account( $user_id ) ) {
					Remember_Logger::warning(
						'Member import skipped a privileged WordPress account',
						array(
							'row'        => $row_number,
							'user_id'    => $user_id,
							'by_user_id' => get_current_user_id(),
						)
					);
					$results['error']++;
					$results['errors'][] = sprintf( __( 'Row %d: That email belongs to a WordPress administrator or a user who can edit other users.', 'remember' ), $row_number );
					continue;
				}
				if ( $dry_run ) {
					$results['success']++;
					continue;
				}
				// Sync WP name fields from CSV on re-import (clears Excel "0" placeholders).
				$user_update = array( 'ID' => $user_id );
				if ( ! empty( trim( (string) ( $row_data['Display Name'] ?? '' ) ) ) ) {
					$user_update['display_name'] = trim( (string) $row_data['Display Name'] );
				}
				if ( array_key_exists( 'First Name', $row_data ) ) {
					$user_update['first_name'] = self::member_import_normalize_csv_name( $row_data['First Name'] );
				}
				if ( array_key_exists( 'Last Name', $row_data ) ) {
					$user_update['last_name'] = self::member_import_normalize_csv_name( $row_data['Last Name'] );
				}
				if ( count( $user_update ) > 1 ) {
					wp_update_user( $user_update );
				}
			}
			
			// Check if member record exists
			$member = $member_model->get( $user_id );
			if ( ! $member ) {
				$member_id = $member_model->create( $user_id, $status );
				
				if ( ! $member_id ) {
					if ( $created_user ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
						wp_delete_user( $user_id );
						Remember_Logger::error(
							'Member import removed a user after the member row failed',
							array(
								'user_id' => $user_id,
								'row'     => $row_number,
							)
						);
					}
					$results['error']++;
					$results['errors'][] = sprintf( __( 'Row %d: Could not create member record.', 'remember' ), $row_number );
					continue;
				}
			}
			
			if ( array_key_exists( 'Nickname', $row_data ) ) {
				$nick = sanitize_text_field( (string) $row_data['Nickname'] );
				if ( '' !== $nick ) {
					update_user_meta( $user_id, 'nickname', $nick );
				}
			}
			if ( array_key_exists( 'Timezone', $row_data ) ) {
				$tz = sanitize_text_field( (string) $row_data['Timezone'] );
				if ( '' !== $tz ) {
					update_user_meta( $user_id, 'timezone_string', $tz );
				}
			}

			// Create or update profile
			global $wpdb;
			$profile = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
					$user_id
				)
			);

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-clothing-sizes.php';
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-im-platforms.php';
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-fields.php';

			$is_new_profile = ! $profile;
			$profile_data   = array(
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id() ? get_current_user_id() : null,
			);
			$legal_names    = self::member_import_legal_names( $row_data, $user_id );
			if ( $is_new_profile || array_key_exists( 'Legal First Name', $row_data ) || array_key_exists( 'First Name', $row_data ) ) {
				$profile_data['legal_first_name'] = $legal_names['legal_first_name'];
			}
			if ( $is_new_profile || array_key_exists( 'Legal Last Name', $row_data ) || array_key_exists( 'Last Name', $row_data ) ) {
				$profile_data['legal_last_name'] = $legal_names['legal_last_name'];
			}

			$text_columns = array(
				'Street Address' => 'address_street',
				'City'           => 'address_city',
				'State'          => 'address_state',
				'Postal Code'    => 'address_postal',
				'Cell Phone'     => 'cell_phone',
				'IM Handle'      => 'im_handle',
			);
			foreach ( $text_columns as $column => $field ) {
				if ( $is_new_profile || array_key_exists( $column, $row_data ) ) {
					$profile_data[ $field ] = (string) ( $row_data[ $column ] ?? '' );
				}
			}
			if ( $is_new_profile || array_key_exists( 'Country', $row_data ) ) {
				$profile_data['address_country'] = (string) ( $row_data['Country'] ?? 'US' );
			}
			if ( $is_new_profile || array_key_exists( 'Timezone', $row_data ) ) {
				$profile_data['timezone'] = array_key_exists( 'Timezone', $row_data )
					? sanitize_text_field( (string) $row_data['Timezone'] )
					: '';
			}
			if ( $is_new_profile || array_key_exists( 'IM Type', $row_data ) ) {
				$profile_data['im_type'] = Remember_Im_Platforms::sanitize_key_value( (string) ( $row_data['IM Type'] ?? '' ) );
			}
			if ( $is_new_profile || array_key_exists( 'Shirt Size', $row_data ) ) {
				$profile_data['shirt_size'] = Remember_Clothing_Sizes::sanitize( 'shirt', (string) ( $row_data['Shirt Size'] ?? '' ) );
			}
			if ( $is_new_profile || array_key_exists( 'Pants Size', $row_data ) ) {
				$profile_data['pants_size'] = Remember_Clothing_Sizes::sanitize( 'pants', (string) ( $row_data['Pants Size'] ?? '' ) );
			}
			if ( $is_new_profile || array_key_exists( 'Shoe Size', $row_data ) ) {
				$profile_data['shoe_size'] = Remember_Clothing_Sizes::sanitize( 'shoe', (string) ( $row_data['Shoe Size'] ?? '' ) );
			}
			if ( $is_new_profile || array_key_exists( 'Interests', $row_data ) ) {
				$profile_data['interests'] = array_key_exists( 'Interests', $row_data )
					? Remember_Profile_Fields::clamp_interests( $row_data['Interests'] )
					: '';
			}

			if ( current_user_can( 'remember_access_health' ) && array_key_exists( 'Allergy Reaction', $row_data ) ) {
				Remember_Profile_Fields::ensure_allergy_reaction_column();
				$profile_data['allergy_reaction'] = sanitize_textarea_field( (string) $row_data['Allergy Reaction'] );
			}

			if ( current_user_can( 'remember_access_emergency_contact' ) ) {
				$emergency_columns = array(
					'Emergency Contact First'        => 'emergency_contact_first',
					'Emergency Contact Last'         => 'emergency_contact_last',
					'Emergency Contact Phone'        => 'emergency_contact_phone',
					'Emergency Contact Relationship' => 'emergency_contact_relationship',
				);
				foreach ( $emergency_columns as $column => $field ) {
					if ( $is_new_profile || array_key_exists( $column, $row_data ) ) {
						$profile_data[ $field ] = (string) ( $row_data[ $column ] ?? '' );
					}
				}
			}
			
			if ( $profile ) {
				$saved = $wpdb->update(
					$wpdb->prefix . 'remember_member_profiles',
					$profile_data,
					array( 'member_id' => $user_id )
				);
			} else {
				$profile_data['member_id']  = $user_id;
				$profile_data['created_at'] = current_time( 'mysql' );
				$saved = $wpdb->insert(
					$wpdb->prefix . 'remember_member_profiles',
					$profile_data
				);
			}
			if ( false === $saved ) {
				$db_error = $wpdb->last_error;
				$results['error']++;
				$results['errors'][] = self::import_row_write_error( $row_number, __( 'Could not save the profile.', 'remember' ), $db_error );
				Remember_Logger::error(
					'Member import profile save failed',
					array(
						'user_id'  => $user_id,
						'row'      => $row_number,
						'db_error' => $db_error,
					)
				);
				continue;
			}

			if ( current_user_can( 'remember_access_health' ) ) {
				if ( array_key_exists( 'Dietary Restrictions', $row_data ) ) {
					self::sync_member_list_from_csv( (int) $user_id, 'dietary', (string) $row_data['Dietary Restrictions'] );
				}
				if ( array_key_exists( 'Allergies', $row_data ) ) {
					self::sync_member_list_from_csv( (int) $user_id, 'allergies', (string) $row_data['Allergies'] );
				}
				if ( array_key_exists( 'Medical Accommodations', $row_data ) ) {
					self::sync_member_list_from_csv( (int) $user_id, 'medical', (string) $row_data['Medical Accommodations'] );
				}
			}

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
			foreach ( Remember_Profile_Questions::export_field_keys() as $fkey ) {
				if ( ! array_key_exists( $fkey, $row_data ) ) {
					continue;
				}
				$raw = trim( (string) $row_data[ $fkey ] );
				if ( '' === $raw ) {
					continue;
				}
				Remember_Profile_Questions::save_by_field_key( (int) $user_id, $fkey, $raw );
			}
			
			$results['success']++;
		}
		
		fclose( $handle );
		return $results;
	}

	/**
	 * Import events from CSV.
	 *
	 * @param string $file_path Path to CSV file.
	 * @param bool   $dry_run   Validate rows and skip every save.
	 * @return array Results array with success/error counts.
	 */
	public static function import_events( $file_path, $dry_run = false ) {
		$results = array(
			'success' => 0,
			'error'   => 0,
			'errors'  => array(),
		);
		
		if ( ! file_exists( $file_path ) ) {
			$results['errors'][] = __( 'File not found.', 'remember' );
			return $results;
		}
		
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			$results['errors'][] = __( 'Could not open file for reading.', 'remember' );
			return $results;
		}
		
		// Skip BOM if present
		$bom = fread( $handle, 3 );
		if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
			rewind( $handle );
		}
		
		// Read headers
		$headers = self::read_csv_line( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			$results['errors'][] = __( 'Could not read CSV headers.', 'remember' );
			return $results;
		}
		
		$event_model = new Remember_Event();
		$row_number = 1;
		
		while ( ( $data = self::read_csv_line( $handle ) ) !== false ) {
			$row_number++;
			
			if ( count( $data ) < count( $headers ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Insufficient columns.', 'remember' ), $row_number );
				continue;
			}
			
			// Ignore extra trailing cells (e.g. a trailing comma from Excel).
			$row_data = array_combine( $headers, array_slice( $data, 0, count( $headers ) ) );
			
			// Required fields
			if ( empty( $row_data['Event Name'] ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Event Name is required.', 'remember' ), $row_number );
				continue;
			}
			
			if ( empty( $row_data['Start Date'] ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Start Date is required.', 'remember' ), $row_number );
				continue;
			}
			
			// Parse dates
			$start_date = self::parse_date( $row_data['Start Date'] );
			$end_date = ! empty( $row_data['End Date'] ) ? self::parse_date( $row_data['End Date'] ) : $start_date;
			
			if ( ! $start_date ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Invalid Start Date format.', 'remember' ), $row_number );
				continue;
			}
			
			// Get location ID if location name or ID provided
			$location_id = null;
			if ( ! empty( $row_data['Location Name'] ) ) {
				$location_model = new Remember_Location();
				$location = $location_model->get_by_name( $row_data['Location Name'] );
				if ( $location ) {
					$location_id = $location->location_id;
				} else {
					// Don't fail if location not found, just log a warning
					$results['errors'][] = sprintf( __( 'Row %d: Location "%s" not found. Event will be created without location.', 'remember' ), $row_number, $row_data['Location Name'] );
				}
			} elseif ( ! empty( $row_data['Location ID'] ) ) {
				$location_id = absint( $row_data['Location ID'] );
			}
			
			$event_data = array(
				'event_name'        => $row_data['Event Name'],
				'event_description' => $row_data['Description'] ?? '',
				'start_date'        => $start_date,
				'end_date'          => $end_date,
				'status'            => ! empty( $row_data['Status'] ) ? $row_data['Status'] : 'draft',
				'is_private'        => ( ! empty( $row_data['Is Private'] ) && strtolower( $row_data['Is Private'] ) === 'yes' ) ? 1 : 0,
				'location_id'       => $location_id,
			);

			if ( $dry_run ) {
				$results['success']++;
				continue;
			}
			
			$event_id = $event_model->create( $event_data );
			
			if ( ! $event_id ) {
				global $wpdb;
				$db_error = $wpdb->last_error;
				$results['error']++;
				$results['errors'][] = self::import_row_write_error( $row_number, __( 'Could not create event.', 'remember' ), $db_error );
				Remember_Logger::error(
					'Event import save failed',
					array(
						'row'      => $row_number,
						'db_error' => $db_error,
					)
				);
				continue;
			}
			
			$results['success']++;
		}
		
		fclose( $handle );
		return $results;
	}

	/**
	 * Import locations from CSV.
	 *
	 * @param string $file_path Path to CSV file.
	 * @param bool   $dry_run   Validate rows and skip every save.
	 * @return array Results array with success/error counts.
	 */
	public static function import_locations( $file_path, $dry_run = false ) {
		$results = array(
			'success' => 0,
			'error'   => 0,
			'errors'  => array(),
		);
		
		if ( ! file_exists( $file_path ) ) {
			$results['errors'][] = __( 'File not found.', 'remember' );
			return $results;
		}
		
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			$results['errors'][] = __( 'Could not open file for reading.', 'remember' );
			return $results;
		}
		
		// Skip BOM if present
		$bom = fread( $handle, 3 );
		if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
			rewind( $handle );
		}
		
		// Read headers
		$headers = self::read_csv_line( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			$results['errors'][] = __( 'Could not read CSV headers.', 'remember' );
			return $results;
		}
		
		$location_model = new Remember_Location();
		$row_number = 1;
		
		while ( ( $data = self::read_csv_line( $handle ) ) !== false ) {
			$row_number++;
			
			if ( count( $data ) < count( $headers ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Insufficient columns.', 'remember' ), $row_number );
				continue;
			}
			
			// Ignore extra trailing cells (e.g. a trailing comma from Excel).
			$row_data = array_combine( $headers, array_slice( $data, 0, count( $headers ) ) );
			
			// Required fields
			if ( empty( $row_data['Location Name'] ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Location Name is required.', 'remember' ), $row_number );
				continue;
			}
			
			// Check if location already exists
			$existing = $location_model->get_by_name( $row_data['Location Name'] );
			if ( $existing ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Location "%s" already exists.', 'remember' ), $row_number, $row_data['Location Name'] );
				continue;
			}
			
			$location_data = array(
				'location_name'  => $row_data['Location Name'],
				'address_street' => $row_data['Street Address'] ?? '',
				'address_city'   => $row_data['City'] ?? '',
				'address_state'  => $row_data['State'] ?? '',
				'address_postal' => $row_data['Postal Code'] ?? '',
				'address_country' => $row_data['Country'] ?? 'US',
				'details'        => $row_data['Details'] ?? '',
				'is_active'      => ( ! empty( $row_data['Is Active'] ) && strtolower( $row_data['Is Active'] ) === 'yes' ) ? 1 : 0,
			);

			if ( $dry_run ) {
				$results['success']++;
				continue;
			}
			
			$location_id = $location_model->create( $location_data );
			
			if ( ! $location_id ) {
				global $wpdb;
				$db_error = $wpdb->last_error;
				$results['error']++;
				$results['errors'][] = self::import_row_write_error( $row_number, __( 'Could not create location.', 'remember' ), $db_error );
				Remember_Logger::error(
					'Location import save failed',
					array(
						'row'      => $row_number,
						'db_error' => $db_error,
					)
				);
				continue;
			}
			
			$results['success']++;
		}
		
		fclose( $handle );
		return $results;
	}

	/**
	 * Parse date string to MySQL format.
	 *
	 * @param string $date_string Date string in various formats.
	 * @return string|false MySQL date string or false on failure.
	 */
	private static function parse_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return false;
		}
		
		$date_string = trim( $date_string );
		
		// Try common date formats
		$formats = array(
			'Y-m-d',
			'm/d/Y',
			'd/m/Y',
			'Y/m/d',
			'm-d-Y',
			'd-m-Y',
		);
		
		foreach ( $formats as $format ) {
			$date = DateTime::createFromFormat( $format, $date_string );
			if ( $date !== false ) {
				// Check if the date was parsed correctly
				$errors = DateTime::getLastErrors();
				if ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
					continue;
				}
				return $date->format( 'Y-m-d' );
			}
		}
		
		// Try strtotime as fallback
		$timestamp = strtotime( $date_string );
		if ( $timestamp !== false ) {
			$parsed = date( 'Y-m-d', $timestamp );
			// Validate the parsed date
			if ( $parsed && $parsed !== '1970-01-01' ) {
				return $parsed;
			}
		}
		
		return false;
	}

	/**
	 * Characters that make Excel/LibreOffice treat a CSV cell as a formula.
	 *
	 * @return string[]
	 */
	private static function csv_formula_prefixes() {
		return array( '=', '+', '-', '@', "\t", "\r" );
	}

	/**
	 * Prefix formula-like cells so spreadsheets open them as text.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	private static function csv_formula_escape_cell( $value ) {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		} elseif ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		} elseif ( null === $value ) {
			$value = '';
		} else {
			$value = (string) $value;
		}
		if ( '' === $value ) {
			return $value;
		}
		$first = substr( $value, 0, 1 );
		if ( in_array( $first, self::csv_formula_prefixes(), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Undo export escaping so a re-imported file round-trips.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	private static function csv_formula_unescape_cell( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) < 2 || "'" !== $value[0] ) {
			return $value;
		}
		$second = $value[1];
		if ( in_array( $second, self::csv_formula_prefixes(), true ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/**
	 * Write one CSV row with formula-injection escaping.
	 *
	 * @param resource          $handle Open write handle.
	 * @param array<int, mixed> $row    Cells.
	 * @return int|false
	 */
	private static function write_csv_line( $handle, $row ) {
		$safe = array();
		foreach ( $row as $cell ) {
			$safe[] = self::csv_formula_escape_cell( $cell );
		}
		return fputcsv( $handle, $safe, ',', '"', '\\' );
	}

	/**
	 * Read one CSV row (PHP 8.4+ requires explicit $escape for fgetcsv()).
	 *
	 * @param resource $handle Open read handle.
	 * @return array<int, string>|false|null
	 */
	private static function read_csv_line( $handle ) {
		$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! is_array( $row ) ) {
			return $row;
		}
		foreach ( $row as $i => $cell ) {
			$row[ $i ] = self::csv_formula_unescape_cell( $cell );
		}
		return $row;
	}

	/**
	 * Export accepted participants for one event (organizer roster dump).
	 *
	 * @param int $event_id Event ID.
	 * @return void Exits after download, or returns on invalid event.
	 */
	public static function export_event_participants( $event_id ) {
		global $wpdb;

		$event_id = absint( $event_id );
		if ( $event_id <= 0 ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-clothing-sizes.php';

		$event_model = new Remember_Event();
		$event       = $event_model->get( $event_id );
		if ( ! $event ) {
			return;
		}

		$apps_table  = $wpdb->prefix . 'remember_event_applications';
		$er_table    = $wpdb->prefix . 'remember_event_roles';
		$roles_table = $wpdb->prefix . 'remember_roles';
		$merch_table = $wpdb->prefix . 'remember_event_merchandise';
		$app_merch   = $wpdb->prefix . 'remember_application_merchandise';

		$addons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT merchandise_id, merchandise_name FROM {$merch_table}
				WHERE event_id = %d
				ORDER BY merchandise_name ASC, merchandise_id ASC",
				$event_id
			)
		);
		if ( ! is_array( $addons ) ) {
			$addons = array();
		}

		$field_keys = Remember_Profile_Questions::active_export_field_keys();

		$headers = array(
			'Application ID',
			'Event ID',
			'Event Name',
			'Event Role',
			'Applied At',
			'Status',
			'Ticket Voided',
			'Checked In At',
			'Checked In By',
			'Ticket Ready Emailed At',
			'User ID',
			'Email',
			'Display Name',
			'Nickname',
			'First Name',
			'Last Name',
			'Legal First Name',
			'Legal Last Name',
			'Street Address',
			'City',
			'State',
			'Postal Code',
			'Country',
			'Cell Phone',
			'Timezone',
			'IM Type',
			'IM Handle',
			'Shirt Size',
			'Pants Size',
			'Shoe Size',
			'Available Shirt',
			'Available Pants',
			'Available Shoe',
		);
		if ( current_user_can( 'remember_access_emergency_contact' ) ) {
			$headers = array_merge(
				$headers,
				array(
					'Emergency Contact First',
					'Emergency Contact Last',
					'Emergency Contact Phone',
					'Emergency Contact Relationship',
				)
			);
		}
		if ( current_user_can( 'remember_access_health' ) ) {
			$headers = array_merge(
				$headers,
				array(
					'Dietary Restrictions',
					'Allergies',
					'Allergy Reaction',
					'Medical Accommodations',
				)
			);
		}
		$headers = array_merge( $headers, $field_keys );
		foreach ( $addons as $addon ) {
			$headers[] = (string) $addon->merchandise_name;
		}
		$headers[] = 'Add-ons Summary';

		$applications = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, r.role_name
				FROM {$apps_table} a
				LEFT JOIN {$er_table} er ON a.event_role_id = er.event_role_id
				LEFT JOIN {$roles_table} r ON er.role_id = r.role_id
				WHERE a.event_id = %d
					AND a.status = 'accepted'
					AND a.superseded_at IS NULL
				ORDER BY r.role_name ASC, a.applied_at ASC, a.application_id ASC",
				$event_id
			)
		);
		if ( ! is_array( $applications ) ) {
			$applications = array();
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';
		$remember_export_what = Remember_Access_Log::what_from_topics( Remember_Access_Log::topics_for_current_user() );
		if ( '' !== $remember_export_what ) {
			Remember_Access_Log::record(
				0,
				$remember_export_what,
				sprintf(
					/* translators: %s: event name */
					__( 'Event participants CSV: %s', 'remember' ),
					isset( $event->event_name ) ? (string) $event->event_name : (string) $event_id
				),
				count( $applications )
			);
		}

		$filename = 'event-' . $event_id . '-participants-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		self::write_csv_line( $output, $headers );

		foreach ( $applications as $app ) {
			$user = get_user_by( 'ID', (int) $app->member_id );
			if ( ! $user ) {
				continue;
			}

			$profile = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
					(int) $app->member_id
				)
			);

			$qty_by_merch = array();
			$merch_rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT merchandise_id, quantity FROM {$app_merch} WHERE event_application_id = %d",
					(int) $app->application_id
				)
			);
			if ( is_array( $merch_rows ) ) {
				foreach ( $merch_rows as $mr ) {
					$qty_by_merch[ (int) $mr->merchandise_id ] = (int) $mr->quantity;
				}
			}

			$summary_parts = array();
			foreach ( $addons as $addon ) {
				$mid = (int) $addon->merchandise_id;
				if ( isset( $qty_by_merch[ $mid ] ) && $qty_by_merch[ $mid ] > 0 ) {
					$summary_parts[] = $addon->merchandise_name . '×' . $qty_by_merch[ $mid ];
				}
			}

			$custom = Remember_Profile_Questions::get_responses_by_field_key( (int) $app->member_id );
			$checked_in_by = '';
			if ( ! empty( $app->checked_in_by ) ) {
				$checker = get_user_by( 'id', (int) $app->checked_in_by );
				$checked_in_by = $checker ? $checker->display_name : '';
			}

			$row = array(
				(int) $app->application_id,
				$event_id,
				$event->event_name,
				isset( $app->role_name ) ? $app->role_name : '',
				isset( $app->applied_at ) ? $app->applied_at : '',
				'accepted',
				! empty( $app->ticket_voided ) ? 'Yes' : 'No',
				isset( $app->checked_in_at ) ? (string) $app->checked_in_at : '',
				$checked_in_by,
				isset( $app->ticket_ready_emailed_at ) ? (string) $app->ticket_ready_emailed_at : '',
				(int) $app->member_id,
				$user->user_email,
				$user->display_name,
				get_user_meta( $user->ID, 'nickname', true ),
				$user->first_name,
				$user->last_name,
				$profile->legal_first_name ?? '',
				$profile->legal_last_name ?? '',
				$profile->address_street ?? '',
				$profile->address_city ?? '',
				$profile->address_state ?? '',
				$profile->address_postal ?? '',
				$profile->address_country ?? '',
				$profile->cell_phone ?? '',
				get_user_meta( $user->ID, 'timezone_string', true ),
				$profile->im_type ?? '',
				$profile->im_handle ?? '',
				$profile->shirt_size ?? '',
				$profile->pants_size ?? '',
				$profile->shoe_size ?? '',
				Remember_Clothing_Sizes::issued_for( 'shirt', $profile->shirt_size ?? '' ),
				Remember_Clothing_Sizes::issued_for( 'pants', $profile->pants_size ?? '' ),
				Remember_Clothing_Sizes::issued_for( 'shoe', $profile->shoe_size ?? '' ),
			);
			if ( current_user_can( 'remember_access_emergency_contact' ) ) {
				$row[] = $profile->emergency_contact_first ?? '';
				$row[] = $profile->emergency_contact_last ?? '';
				$row[] = $profile->emergency_contact_phone ?? '';
				$row[] = $profile->emergency_contact_relationship ?? '';
			}
			if ( current_user_can( 'remember_access_health' ) ) {
				$row[] = self::member_list_labels( (int) $app->member_id, 'dietary' );
				$row[] = self::member_list_labels( (int) $app->member_id, 'allergies' );
				$row[] = $profile->allergy_reaction ?? '';
				$row[] = self::member_list_labels( (int) $app->member_id, 'medical' );
			}

			foreach ( $field_keys as $fkey ) {
				$row[] = isset( $custom[ $fkey ] ) ? $custom[ $fkey ] : '';
			}
			foreach ( $addons as $addon ) {
				$mid = (int) $addon->merchandise_id;
				$row[] = ( isset( $qty_by_merch[ $mid ] ) && $qty_by_merch[ $mid ] > 0 ) ? (string) $qty_by_merch[ $mid ] : '';
			}
			$row[] = implode( '; ', $summary_parts );

			self::write_csv_line( $output, $row );
		}

		fclose( $output );
		exit;
	}

	/**
	 * CSV headers for custom field definitions.
	 *
	 * @return string[]
	 */
	public static function profile_questions_csv_headers() {
		return array(
			'Short Name',
			'Question',
			'Type',
			'Options',
			'Required',
			'Active',
			'Event Card',
			'Order',
		);
	}

	/**
	 * Download template CSV for custom field definitions.
	 *
	 * @return void
	 */
	public static function download_profile_questions_template() {
		$filename = 'custom-fields-template.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		self::write_csv_line( $output, self::profile_questions_csv_headers() );
		self::write_csv_line(
			$output,
			array(
				'ice_cream_flavor',
				'What is your favorite ice cream?',
				'select',
				'vanilla|Vanilla;chocolate|Chocolate',
				'Yes',
				'Yes',
				'No',
				'10',
			)
		);
		fclose( $output );
		exit;
	}

	/**
	 * Export custom field definitions to CSV.
	 *
	 * @return void
	 */
	public static function export_profile_questions() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-profile-question.php';

		$model = new Remember_Profile_Question();
		$rows  = $model->get_all_ordered();
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$filename = 'custom-fields-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		self::write_csv_line( $output, self::profile_questions_csv_headers() );

		foreach ( $rows as $q ) {
			$opt_parts = array();
			foreach ( Remember_Profile_Questions::parse_options( $q->options_json ) as $opt ) {
				$opt_parts[] = $opt['key'] . '|' . $opt['label'];
			}
			self::write_csv_line(
				$output,
				array(
					$q->field_key,
					$q->label,
					$q->field_type,
					implode( ';', $opt_parts ),
					! empty( $q->is_required ) ? 'Yes' : 'No',
					! empty( $q->is_active ) ? 'Yes' : 'No',
					! empty( $q->show_on_event_card ) ? 'Yes' : 'No',
					isset( $q->sort_order ) ? (int) $q->sort_order : 0,
				)
			);
		}
		fclose( $output );
		exit;
	}

	/**
	 * Import custom field definitions from CSV (upsert by Short Name).
	 *
	 * @param string $file_path Path to CSV.
	 * @param bool   $dry_run   Validate rows and skip every save.
	 * @return array{success:int,error:int,errors:string[]}
	 */
	public static function import_profile_questions( $file_path, $dry_run = false ) {
		$results = array(
			'success' => 0,
			'error'   => 0,
			'errors'  => array(),
		);

		if ( ! file_exists( $file_path ) ) {
			$results['errors'][] = __( 'File not found.', 'remember' );
			return $results;
		}

		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			$results['errors'][] = __( 'Could not open file for reading.', 'remember' );
			return $results;
		}

		$bom = fread( $handle, 3 );
		if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
			rewind( $handle );
		}

		$headers = self::read_csv_line( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			$results['errors'][] = __( 'Could not read CSV headers.', 'remember' );
			return $results;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		require_once plugin_dir_path( __FILE__ ) . '../models/class-profile-question.php';
		$model      = new Remember_Profile_Question();
		$row_number = 1;

		while ( ( $data = self::read_csv_line( $handle ) ) !== false ) {
			$row_number++;
			if ( count( $data ) < 2 ) {
				continue;
			}
			while ( count( $data ) < count( $headers ) ) {
				$data[] = '';
			}
			$row_data = array_combine( $headers, array_slice( $data, 0, count( $headers ) ) );
			if ( ! is_array( $row_data ) ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Could not parse columns.', 'remember' ), $row_number );
				continue;
			}

			$label = trim( (string) ( $row_data['Question'] ?? '' ) );
			$key   = Remember_Profile_Questions::sanitize_field_key( (string) ( $row_data['Short Name'] ?? '' ) );
			if ( '' === $key && '' !== $label ) {
				$key = Remember_Profile_Questions::suggest_field_key_from_label( $label );
			}
			if ( '' === $key || '' === $label ) {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Short Name and Question are required.', 'remember' ), $row_number );
				continue;
			}

			$type = sanitize_key( (string) ( $row_data['Type'] ?? 'text' ) );
			if ( ! in_array( $type, array( 'text', 'select', 'multiselect' ), true ) ) {
				$type = 'text';
			}

			$options_raw = trim( (string) ( $row_data['Options'] ?? '' ) );
			$options     = null;
			if ( Remember_Profile_Questions::type_uses_options( $type ) ) {
				$opt_rows = array();
				foreach ( preg_split( '/\s*;\s*/', $options_raw ) as $chunk ) {
					$chunk = trim( (string) $chunk );
					if ( '' === $chunk ) {
						continue;
					}
					if ( false !== strpos( $chunk, '|' ) ) {
						list( $okey, $olabel ) = array_map( 'trim', explode( '|', $chunk, 2 ) );
					} else {
						$okey   = $chunk;
						$olabel = $chunk;
					}
					$okey = Remember_Profile_Questions::sanitize_field_key( $okey );
					if ( '' === $okey ) {
						continue;
					}
					$opt_rows[] = array(
						'key'   => $okey,
						'label' => sanitize_text_field( $olabel ),
					);
				}
				$options = Remember_Profile_Questions::encode_options( $opt_rows );
				if ( null === $options ) {
					$results['error']++;
					$results['errors'][] = sprintf( __( 'Row %d: Options required for type %s.', 'remember' ), $row_number, $type );
					continue;
				}
			}

			$required = self::csv_yes_no( $row_data['Required'] ?? 'No' );
			$active   = self::csv_yes_no( $row_data['Active'] ?? 'Yes', true );
			$order    = isset( $row_data['Order'] ) ? intval( $row_data['Order'] ) : 0;
			// A CSV written before this column existed leaves the flag alone rather than
			// silently clearing an admin's decision about what may be posted publicly.
			$event_card = array_key_exists( 'Event Card', $row_data )
				? ( self::csv_yes_no( $row_data['Event Card'] ) ? 1 : 0 )
				: null;

			$existing = $model->get_by_field_key( $key );
			$payload  = array(
				'label'        => $label,
				'field_key'    => $key,
				'field_type'   => $type,
				'options_json' => $options,
				'is_required'  => $required ? 1 : 0,
				'is_active'    => $active ? 1 : 0,
				'sort_order'   => $order,
				'updated_at'   => current_time( 'mysql' ),
			);
			if ( null !== $event_card ) {
				$payload['show_on_event_card'] = $event_card;
			}

			if ( $dry_run ) {
				$results['success']++;
				continue;
			}

			if ( $existing ) {
				$ok = $model->update( (int) $existing->question_id, $payload );
				$ok = ( false !== $ok );
			} else {
				$payload['created_at'] = current_time( 'mysql' );
				$ok                    = (bool) $model->insert( $payload );
			}

			if ( $ok ) {
				$results['success']++;
			} else {
				$results['error']++;
				$results['errors'][] = sprintf( __( 'Row %d: Could not save custom field %s.', 'remember' ), $row_number, $key );
			}
		}

		fclose( $handle );
		return $results;
	}

	/**
	 * Parse Yes/No CSV cell.
	 *
	 * @param mixed $value   Raw value.
	 * @param bool  $default Default when empty.
	 * @return bool
	 */
	private static function csv_yes_no( $value, $default = false ) {
		$v = strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return (bool) $default;
		}
		return in_array( $v, array( '1', 'yes', 'y', 'true' ), true );
	}

	/**
	 * Replace member dietary / allergy / medical selections from a comma-separated label list.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $type      dietary|allergies|medical.
	 * @param string $csv       Labels.
	 * @return void
	 */
	private static function sync_member_list_from_csv( $member_id, $type, $csv ) {
		global $wpdb;
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return;
		}

		$labels = array();
		foreach ( explode( ',', (string) $csv ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$labels[] = $part;
			}
		}

		if ( 'dietary' === $type ) {
			$catalog = $wpdb->prefix . 'remember_dietary_restrictions';
			$junction = $wpdb->prefix . 'remember_member_dietary_restrictions';
			$id_col   = 'restriction_id';
			$name_col = 'restriction_name';
		} elseif ( 'allergies' === $type ) {
			$catalog  = $wpdb->prefix . 'remember_allergies';
			$junction = $wpdb->prefix . 'remember_member_allergies';
			$id_col   = 'allergy_id';
			$name_col = 'allergy_name';
		} else {
			$catalog  = $wpdb->prefix . 'remember_medical_accommodations';
			$junction = $wpdb->prefix . 'remember_member_medical_accommodations';
			$id_col   = 'accommodation_id';
			$name_col = 'accommodation_name';
		}

		$wpdb->delete( $junction, array( 'member_id' => $member_id ), array( '%d' ) );
		foreach ( $labels as $label ) {
			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT {$id_col} FROM {$catalog} WHERE {$name_col} = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column/table names are fixed.
					$label
				)
			);
			if ( ! $id ) {
				continue;
			}
			$wpdb->insert(
				$junction,
				array(
					'member_id' => $member_id,
					$id_col     => (int) $id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Comma-separated labels for a member's dietary / allergy / medical selections.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $type      dietary|allergies|medical.
	 * @return string
	 */
	private static function member_list_labels( $member_id, $type ) {
		global $wpdb;
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return '';
		}

		if ( 'dietary' === $type ) {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT dr.restriction_name
					FROM {$wpdb->prefix}remember_dietary_restrictions dr
					INNER JOIN {$wpdb->prefix}remember_member_dietary_restrictions mdr ON dr.restriction_id = mdr.restriction_id
					WHERE mdr.member_id = %d
					ORDER BY dr.sort_order ASC, dr.restriction_name ASC",
					$member_id
				)
			);
		} elseif ( 'allergies' === $type ) {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT a.allergy_name
					FROM {$wpdb->prefix}remember_allergies a
					INNER JOIN {$wpdb->prefix}remember_member_allergies ma ON a.allergy_id = ma.allergy_id
					WHERE ma.member_id = %d
					ORDER BY a.sort_order ASC, a.allergy_name ASC",
					$member_id
				)
			);
		} else {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ma.accommodation_name
					FROM {$wpdb->prefix}remember_medical_accommodations ma
					INNER JOIN {$wpdb->prefix}remember_member_medical_accommodations mma ON ma.accommodation_id = mma.accommodation_id
					WHERE mma.member_id = %d
					ORDER BY ma.sort_order ASC, ma.accommodation_name ASC",
					$member_id
				)
			);
		}

		if ( ! is_array( $names ) || empty( $names ) ) {
			return '';
		}
		return implode( ', ', $names );
	}
}
