<?php
/**
 * Members view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-vetting.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-payment.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-image-uploader.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-vetting-workflow.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-import-export.php';

Remember_Logger::debug( 'Members page loaded' );

$member_model = new Remember_Member();
$vetting_model = new Remember_Vetting();
$payment_model = new Remember_Payment();
$application_model = new Remember_Application();
$event_model = new Remember_Event();

// Status labels and colors (needed before form handlers).
$status_labels = array(
	'pending_vetting' => __( 'Pending Vetting', 'remember' ),
	'unvetted'        => __( 'Unvetted', 'remember' ),
	'in_vetting'      => __( 'In Vetting', 'remember' ),
	'vetted'          => __( 'Vetted', 'remember' ),
	'rejected'        => __( 'Rejected', 'remember' ),
	'inactive'        => __( 'Inactive', 'remember' ),
	'merged'          => __( 'Merged', 'remember' ),
);
$status_colors = array(
	'pending_vetting' => '#f0b849',
	'unvetted'        => '#72777c',
	'in_vetting'      => '#00a0d2',
	'vetted'          => '#46b450',
	'rejected'        => '#dc3232',
	'inactive'        => '#72777c',
	'merged'          => '#50575e',
);

// Check if viewing a specific member
$view_member_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;

// Attendees-only staff may list/view only attendees on their events — compute early so view + mutations can enforce it.
$current_user_id      = get_current_user_id();
$has_members_access   = current_user_can( 'remember_read_members' );
$has_attendees_access = current_user_can( 'remember_read_attendees' );
$is_attendees_only    = $has_attendees_access && ! $has_members_access;

// Handle form submissions
if ( isset( $_POST['remember_member_action'] ) && check_admin_referer( 'remember_member_action', 'remember_member_nonce' ) ) {
	$action = sanitize_text_field( $_POST['remember_member_action'] );
	$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

	// Attendees-only users must not mutate member records (create/update require remember_*_members anyway;
	// this blocks any future attendees write caps or mistaken grants from acting out of scope).
	if ( $is_attendees_only && $member_id > 0 && ! $member_model->guard_can_view_attendee( $current_user_id, $member_id ) ) {
		wp_die(
			esc_html__( 'You do not have permission to modify this member.', 'remember' ),
			esc_html__( 'Access Denied', 'remember' ),
			array( 'response' => 403 )
		);
	}
	
	if ( 'add' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_create_members' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		// Create WordPress user first
		$username = isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '';
		$last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '';
		$password = isset( $_POST['password'] ) ? $_POST['password'] : wp_generate_password( 12, false );
		$status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'pending_vetting';
		
		if ( empty( $username ) || empty( $email ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Username and email are required.', 'remember' ) . '</p></div>';
		} else {
			// Check if user already exists
			if ( username_exists( $username ) || email_exists( $email ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'A user with this username or email already exists.', 'remember' ) . '</p></div>';
			} else {
				// Create WordPress user
				$user_id = wp_create_user( $username, $password, $email );
				
				if ( is_wp_error( $user_id ) ) {
					Remember_Logger::error( 'Failed to create WordPress user', array( 'error' => $user_id->get_error_message() ) );
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $user_id->get_error_message() ) . '</p></div>';
				} else {
					// Update user meta
					if ( ! empty( $first_name ) ) {
						update_user_meta( $user_id, 'first_name', $first_name );
					}
					if ( ! empty( $last_name ) ) {
						update_user_meta( $user_id, 'last_name', $last_name );
					}
					// Public display name defaults to username — never auto-set from legal name.
					wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => $username,
							'nickname'     => $username,
						)
					);
					
					// Determine initial status based on vetting workflow
					$vetting_workflow = Remember_Vetting_Workflow::get_workflow();
					if ( 'first_application' === $vetting_workflow ) {
						// If vetting is on first application, member starts as unvetted but can apply
						$status = 'unvetted'; // New status for members waiting for first application
					} else {
						// Default: vetting on join, so status is pending_vetting
						$status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'pending_vetting';
					}
					
					// Create member record
					global $wpdb;
					$member_record_id = $member_model->create( $user_id, $status );
					if ( $member_record_id ) {
						$vetting_result = Remember_Vetting_Workflow::open_automatic_case( $user_id, 'join' );
						if ( false === $vetting_result ) {
							Remember_Logger::warning( 'Member created but vetting case creation failed', array( 'member_id' => $user_id ) );
						}
						
						Remember_Logger::info( 'Member created', array( 'member_id' => $user_id, 'status' => $status ) );
						require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
						Remember_Notifications::notify_member_registered( $user_id );
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member created successfully.', 'remember' ) . '</p></div>';
					} else {
						// Get database error immediately after failed insert
						$db_error = $wpdb->last_error;
						$db_query = $wpdb->last_query;
						
						Remember_Logger::error( 'Failed to create member record in wp_remember_members table', array( 
							'user_id' => $user_id,
							'status' => $status,
							'db_error' => $db_error,
							'db_query' => $db_query,
						) );
						
						// Build clear error message
						$error_message = sprintf( 
							__( 'The WordPress user was created successfully, but the member record in the database could not be created. The member record stores the member status (%s) and other plugin-specific data.', 'remember' ),
							esc_html( $status )
						);
						
						if ( ! empty( $db_error ) ) {
							$error_message .= ' ' . sprintf( __( '<strong>Database Error:</strong> %s', 'remember' ), esc_html( $db_error ) );
						} else {
							$error_message .= ' ' . __( '<strong>Note:</strong> No specific database error was reported. This may indicate a database constraint violation (e.g., duplicate entry) or a missing database table.', 'remember' );
						}
						
						// Check if table exists
						$table_name = $wpdb->prefix . 'remember_members';
						$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
						if ( ! $table_exists ) {
							$error_message .= ' ' . sprintf( __( '<strong>Critical:</strong> The database table %s does not exist. Please deactivate and reactivate the plugin to create the required database tables.', 'remember' ), esc_html( $table_name ) );
						}
						
						// Check if user already has a member record
						$existing_member = $member_model->get( $user_id );
						if ( $existing_member ) {
							$error_message .= ' ' . sprintf( __( '<strong>Note:</strong> A member record already exists for this user (ID: %d).', 'remember' ), $user_id );
						}
						
						echo '<div class="notice notice-error is-dismissible"><p>' . $error_message . '</p></div>';
					}
				}
			}
		}
	} elseif ( 'convert_wp_user' === $action ) {
		if ( ! current_user_can( 'remember_create_members' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		$wp_user_id = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;
		$status     = isset( $_POST['convert_status'] ) ? sanitize_text_field( wp_unslash( $_POST['convert_status'] ) ) : 'pending_vetting';
		$confirmed  = ! empty( $_POST['confirm_convert_member'] );

		if ( ! $confirmed ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please confirm that you intend to make this WordPress user a reMember member.', 'remember' ) . '</p></div>';
		} elseif ( $wp_user_id < 1 || ! get_userdata( $wp_user_id ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Select a valid WordPress user.', 'remember' ) . '</p></div>';
		} elseif ( $member_model->get( $wp_user_id ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That WordPress user is already a reMember member.', 'remember' ) . '</p></div>';
		} else {
			if ( ! array_key_exists( $status, $status_labels ) ) {
				$vetting_workflow = Remember_Vetting_Workflow::get_workflow();
				$status = ( 'first_application' === $vetting_workflow ) ? 'unvetted' : 'pending_vetting';
			}

			$created = $member_model->create_from_wp_user( $wp_user_id, $status );
			if ( $created ) {
				if ( 'pending_vetting' === $status ) {
					$vetting_result = Remember_Vetting_Workflow::open_automatic_case( $wp_user_id, 'join' );
					if ( false === $vetting_result ) {
						Remember_Logger::warning( 'Converted WP user but vetting case creation failed', array( 'member_id' => $wp_user_id ) );
					}
				}

				$wp_user = get_userdata( $wp_user_id );
				Remember_Logger::info(
					'WP user converted to reMember member by admin',
					array(
						'member_id'  => $wp_user_id,
						'status'     => $status,
						'by_user_id' => get_current_user_id(),
					)
				);
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
				Remember_Notifications::notify_member_registered( $wp_user_id );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
					sprintf(
						/* translators: 1: display name or login, 2: status label */
						__( '%1$s is now a reMember member (%2$s). Edit their profile and roles as needed.', 'remember' ),
						$wp_user ? $wp_user->display_name : (string) $wp_user_id,
						isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status
					)
				) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create the member record. Check the logs for details.', 'remember' ) . '</p></div>';
			}
		}
	} elseif ( $member_id > 0 && 'update_status' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_update_members' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		$status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';
		if ( ! empty( $status ) ) {
			$result = $member_model->update_status( $member_id, $status );
			if ( $result !== false ) {
				Remember_Logger::info( 'Member status updated', array( 'member_id' => $member_id, 'status' => $status ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member status updated successfully.', 'remember' ) . '</p></div>';
			} else {
				Remember_Logger::error( 'Failed to update member status', array( 'member_id' => $member_id ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to update member status.', 'remember' ) . '</p></div>';
			}
		}
	} elseif ( $member_id > 0 && 'update_profile' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_update_members' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		// The target must be a real reMember member, and the current user must be
		// allowed to administer that WordPress account: non-admins may edit members
		// but not administrators.
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-capabilities.php';
		if ( ! $member_model->get( $member_id ) || ! Remember_Capabilities::current_user_can_administer_account( $member_id ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';

		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-sensitive-profile.php';

		$profile_check = Remember_Profile_Fields::collect_profile_data_from_request();
		$meta_check    = Remember_Profile_Fields::collect_meta_from_request();
		$pq_answers    = Remember_Profile_Questions::collect_from_request();
		// Health and emergency are written only when that section was opened on the edit screen.
		$remember_saved_topics   = Remember_Sensitive_Profile::posted_topics();
		$remember_save_health    = in_array( 'health', $remember_saved_topics, true );
		$remember_save_emergency = in_array( 'emergency', $remember_saved_topics, true );
		// Admin: only nickname, display name, legal first/last, cell phone are required.
		$missing_key = Remember_Profile_Fields::first_missing_required( $profile_check, $meta_check, 'admin' );

		$member_number = Remember_Profile_Fields::sanitize_member_number(
			isset( $_POST['member_number'] ) ? wp_unslash( $_POST['member_number'] ) : ''
		);
		$remember_email_error = isset( $_POST['user_email'] )
			? Remember_Profile_Fields::user_email_change_error( $member_id, wp_unslash( $_POST['user_email'] ) )
			: '';
		$member_number_error = '';
		if ( null === $member_number ) {
			$member_number_error = __( 'Member number must be alphanumeric (letters and numbers only).', 'remember' );
		} elseif ( '' !== $member_number && Remember_Profile_Fields::is_member_number_taken( $member_number, $member_id ) ) {
			$member_number_error = __( 'That member number is already assigned to another member.', 'remember' );
		}

		if ( '' !== $missing_key ) {
			$labels = Remember_Profile_Fields::labels();
			$label  = isset( $labels[ $missing_key ] ) ? $labels[ $missing_key ] : __( 'Required field', 'remember' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: field label */
					__( '%s is required.', 'remember' ),
					$label
				)
			) . '</p></div>';
		} elseif ( $remember_save_health && Remember_Profile_Fields::allergy_reaction_is_missing() ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( Remember_Profile_Fields::allergy_reaction_prompt() ) . '</p></div>';
		} elseif ( Remember_Profile_Fields::interests_is_over_limit( $profile_check['interests'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( Remember_Profile_Fields::interests_too_long_message() ) . '</p></div>';
		} elseif ( '' !== $member_number_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $member_number_error ) . '</p></div>';
		} elseif ( '' !== $remember_email_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( Remember_Profile_Fields::user_email_change_message( $remember_email_error ) ) . '</p></div>';
		} else {
		
		global $wpdb;
		
		// Update WordPress user display_name and nickname if provided
		if ( isset( $_POST['display_name'] ) || isset( $_POST['nickname'] ) || isset( $_POST['user_email'] ) ) {
			$user_update_data = array( 'ID' => $member_id );
			
			if ( isset( $_POST['display_name'] ) ) {
				$user_update_data['display_name'] = sanitize_text_field( wp_unslash( $_POST['display_name'] ) );
			}

			$remember_email_changed = false;
			if ( isset( $_POST['user_email'] ) ) {
				$posted_email = sanitize_email( wp_unslash( $_POST['user_email'] ) );
				$current_account = get_userdata( $member_id );
				if ( $current_account && strtolower( $current_account->user_email ) !== strtolower( $posted_email ) ) {
					$user_update_data['user_email'] = $posted_email;
					$remember_email_changed         = true;
				}
			}
			
			$update_result = wp_update_user( $user_update_data );
			
			if ( ! is_wp_error( $update_result ) ) {
				// Update nickname in user meta
				if ( isset( $_POST['nickname'] ) ) {
					update_user_meta( $member_id, 'nickname', sanitize_text_field( wp_unslash( $_POST['nickname'] ) ) );
				}
				Remember_Logger::info( 'WordPress user updated', array( 'user_id' => $member_id ) );
				if ( ! empty( $remember_email_changed ) ) {
					Remember_Logger::info( 'Member account email updated', array( 'user_id' => $member_id, 'by_user_id' => get_current_user_id() ) );
					Remember_Logger::debug(
						'Member account email updated',
						array(
							'user_id' => $member_id,
							'email'   => isset( $posted_email ) ? $posted_email : '',
						)
					);
				}
			} else {
				Remember_Logger::error( 'Failed to update WordPress user', array( 'user_id' => $member_id, 'error' => $update_result->get_error_message() ) );
			}
		}
		
		// Get max image dimensions from settings
		$options = get_option( 'remember_options', array() );
		$max_image_size = isset( $options['photo_max_dimensions'] ) ? absint( $options['photo_max_dimensions'] ) : 800;
		
		// Handle photo upload
		if ( ! empty( $_FILES['photo_file']['name'] ) ) {
			// Get current member to check for existing photo
			$current_member = $member_model->get( $member_id );

			$upload_result = Remember_Image_Uploader::upload_square_image( $_FILES['photo_file'], $max_image_size );
			if ( ! is_wp_error( $upload_result ) ) {
				// Update member photo_url
				$member_model->update_photo( $member_id, $upload_result['url'] );
				// Delete the old photo only once the new one is saved.
				if ( $current_member && $current_member->photo_url && $current_member->photo_url !== $upload_result['url'] ) {
					Remember_Image_Uploader::delete_image( $current_member->photo_url );
				}
				Remember_Logger::info( 'Member photo updated', array( 'member_id' => $member_id ) );
			} else {
				Remember_Logger::error( 'Photo upload failed', array( 'error' => $upload_result->get_error_message() ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $upload_result->get_error_message() ) . '</p></div>';
			}
		}
		
		// Handle photo deletion
		if ( isset( $_POST['delete_photo'] ) && $_POST['delete_photo'] === '1' ) {
			$current_member = $member_model->get( $member_id );
			if ( $current_member && $current_member->photo_url ) {
				Remember_Image_Uploader::delete_image( $current_member->photo_url );
				$member_model->update_photo( $member_id, null );
				Remember_Logger::info( 'Member photo deleted', array( 'member_id' => $member_id ) );
			}
		}
		
		// Save timezone to WP user meta (not member_profiles)
		if ( isset( $_POST['timezone_string'] ) ) {
			$timezone_string = sanitize_text_field( wp_unslash( $_POST['timezone_string'] ) );
			if ( ! empty( $timezone_string ) ) {
				update_user_meta( $member_id, 'timezone_string', $timezone_string );
			} else {
				// Default if empty
				update_user_meta( $member_id, 'timezone_string', 'America/Los_Angeles' );
			}
		}
		
		// Collect profile data (timezone is NOT stored here, it's in WP user meta)
		$profile_data = array(
			'legal_first_name' => isset( $_POST['legal_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['legal_first_name'] ) ) : '',
			'legal_last_name' => isset( $_POST['legal_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['legal_last_name'] ) ) : '',
			'address_street' => isset( $_POST['address_street'] ) ? sanitize_text_field( wp_unslash( $_POST['address_street'] ) ) : '',
			'address_city' => isset( $_POST['address_city'] ) ? sanitize_text_field( wp_unslash( $_POST['address_city'] ) ) : '',
			'address_state' => isset( $_POST['address_state'] ) ? sanitize_text_field( wp_unslash( $_POST['address_state'] ) ) : '',
			'address_postal' => isset( $_POST['address_postal'] ) ? sanitize_text_field( wp_unslash( $_POST['address_postal'] ) ) : '',
			'address_country' => isset( $_POST['address_country'] ) ? sanitize_text_field( wp_unslash( $_POST['address_country'] ) ) : '',
			'cell_phone' => isset( $_POST['cell_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['cell_phone'] ) ) : '',
			'im_handle' => isset( $_POST['im_handle'] ) ? sanitize_text_field( wp_unslash( $_POST['im_handle'] ) ) : '',
			'im_type' => isset( $_POST['im_type'] ) ? Remember_Im_Platforms::sanitize_key_value( wp_unslash( $_POST['im_type'] ) ) : Remember_Im_Platforms::default_key(),
			'interests' => isset( $_POST['interests'] ) ? Remember_Profile_Fields::sanitize_interests( wp_unslash( $_POST['interests'] ) ) : '',
			'shirt_size' => isset( $_POST['shirt_size'] ) ? Remember_Clothing_Sizes::sanitize( 'shirt', wp_unslash( $_POST['shirt_size'] ) ) : '',
			'pants_size' => isset( $_POST['pants_size'] ) ? Remember_Clothing_Sizes::sanitize( 'pants', wp_unslash( $_POST['pants_size'] ) ) : '',
			'shoe_size' => isset( $_POST['shoe_size'] ) ? Remember_Clothing_Sizes::sanitize( 'shoe', wp_unslash( $_POST['shoe_size'] ) ) : '',
			'share_email_with_events' => isset( $_POST['share_email_with_events'] ) ? 1 : 0,
			'share_phone_with_events' => isset( $_POST['share_phone_with_events'] ) ? 1 : 0,
			'share_location_with_events' => isset( $_POST['share_location_with_events'] ) ? 1 : 0,
			'share_im_with_events' => isset( $_POST['share_im_with_events'] ) ? 1 : 0,
			'share_interests_with_events' => isset( $_POST['share_interests_with_events'] ) ? 1 : 0,
			'share_photo_with_events' => isset( $_POST['share_photo_with_events'] ) ? 1 : 0,
			'member_number' => ( '' === $member_number ) ? null : $member_number,
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => get_current_user_id(),
		);

		// A closed section, or one the editor may not see, is left exactly as stored.
		if ( $remember_save_health ) {
			Remember_Profile_Fields::ensure_allergy_reaction_column();
			$profile_data['allergy_reaction'] = Remember_Profile_Fields::allergy_reaction_for_storage();
		}

		if ( $remember_save_emergency ) {
			$profile_data['emergency_contact_first']        = isset( $_POST['emergency_contact_first'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_contact_first'] ) ) : '';
			$profile_data['emergency_contact_last']         = isset( $_POST['emergency_contact_last'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_contact_last'] ) ) : '';
			$profile_data['emergency_contact_phone']        = isset( $_POST['emergency_contact_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_contact_phone'] ) ) : '';
			$profile_data['emergency_contact_relationship'] = isset( $_POST['emergency_contact_relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['emergency_contact_relationship'] ) ) : '';
		}
		
		// Check if profile exists
		$existing_profile = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
			$member_id
		) );
		
		if ( $existing_profile ) {
			// Update existing profile
			$result = $wpdb->update(
				$wpdb->prefix . 'remember_member_profiles',
				$profile_data,
				array( 'member_id' => $member_id )
			);
		} else {
			// Create new profile
			$profile_data['member_id'] = $member_id;
			$profile_data['created_at'] = current_time( 'mysql' );
			$result = $wpdb->insert(
				$wpdb->prefix . 'remember_member_profiles',
				$profile_data
			);
		}
		
		if ( $result !== false ) {
			// Update social media
			$wpdb->delete( $wpdb->prefix . 'remember_member_social_media', array( 'member_id' => $member_id ) );
			if ( isset( $_POST['social_media'] ) && is_array( $_POST['social_media'] ) ) {
				foreach ( $_POST['social_media'] as $platform_id => $handle ) {
					$handle = sanitize_text_field( $handle );
					if ( ! empty( $handle ) ) {
						$wpdb->insert(
							$wpdb->prefix . 'remember_member_social_media',
							array(
								'member_id' => $member_id,
								'platform_id' => absint( $platform_id ),
								'handle' => $handle,
								'created_at' => current_time( 'mysql' ),
							)
						);
					}
				}
			}
			
			// Update dietary / medical / allergies only when the health section was opened and the editor may see it.
			// Otherwise skip these writes so a closed section or a forged POST cannot wipe or change them.
			if ( $remember_save_health ) {
				$wpdb->delete( $wpdb->prefix . 'remember_member_dietary_restrictions', array( 'member_id' => $member_id ) );
				if ( isset( $_POST['dietary_restrictions'] ) && is_array( $_POST['dietary_restrictions'] ) ) {
					foreach ( $_POST['dietary_restrictions'] as $restriction_id ) {
						$wpdb->insert(
							$wpdb->prefix . 'remember_member_dietary_restrictions',
							array(
								'member_id' => $member_id,
								'restriction_id' => absint( $restriction_id ),
							)
						);
					}
				}

				$wpdb->delete( $wpdb->prefix . 'remember_member_medical_accommodations', array( 'member_id' => $member_id ) );
				if ( isset( $_POST['medical_accommodations'] ) && is_array( $_POST['medical_accommodations'] ) ) {
					foreach ( $_POST['medical_accommodations'] as $accommodation_id ) {
						$wpdb->insert(
							$wpdb->prefix . 'remember_member_medical_accommodations',
							array(
								'member_id' => $member_id,
								'accommodation_id' => absint( $accommodation_id ),
							)
						);
					}
				}

				$wpdb->delete( $wpdb->prefix . 'remember_member_allergies', array( 'member_id' => $member_id ) );
				if ( isset( $_POST['allergies'] ) && is_array( $_POST['allergies'] ) ) {
					foreach ( $_POST['allergies'] as $allergy_id ) {
						$wpdb->insert(
							$wpdb->prefix . 'remember_member_allergies',
							array(
								'member_id' => $member_id,
								'allergy_id' => absint( $allergy_id ),
							)
						);
					}
				}
			}
			
			// Handle role assignment (only if user has update_members capability)
			if ( current_user_can( 'remember_update_members' ) ) {
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-capabilities.php';
				$posted_role_ids = isset( $_POST['member_roles'] ) ? array_map( 'absint', $_POST['member_roles'] ) : array();
				$posted_role_ids = array_values( array_unique( array_filter( $posted_role_ids ) ) );

				// Get current roles
				$current_role_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
					"SELECT role_id FROM {$wpdb->prefix}remember_member_roles WHERE member_id = %d",
					$member_id
				) ) );

				// Roles the editor cannot assign stay on the member (and cannot be newly added).
				$locked_role_ids = array();
				foreach ( $current_role_ids as $current_role_id ) {
					if ( ! Remember_Capabilities::current_user_can_assign_role( $current_role_id ) ) {
						$locked_role_ids[] = $current_role_id;
					}
				}
				$assignable_posted = Remember_Capabilities::filter_assignable_role_ids( $posted_role_ids );
				$selected_role_ids = array_values( array_unique( array_merge( $locked_role_ids, $assignable_posted ) ) );
				
				// Remove roles that are no longer selected (never remove locked roles).
				$roles_to_remove = array_diff( $current_role_ids, $selected_role_ids );
				if ( ! empty( $roles_to_remove ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $roles_to_remove ), '%d' ) );
					$wpdb->query( $wpdb->prepare(
						"DELETE FROM {$wpdb->prefix}remember_member_roles 
						WHERE member_id = %d AND role_id IN ($placeholders)",
						array_merge( array( $member_id ), $roles_to_remove )
					) );
					// Sync capabilities after role removal
					Remember_Capabilities::sync_user_capabilities_from_roles( $member_id );
				}
				
				// Add new roles
				$roles_to_add = array_diff( $selected_role_ids, $current_role_ids );
				if ( ! empty( $roles_to_add ) ) {
					foreach ( $roles_to_add as $role_id ) {
						$wpdb->insert(
							$wpdb->prefix . 'remember_member_roles',
							array(
								'member_id'   => $member_id,
								'role_id'     => $role_id,
								'approved_at' => current_time( 'mysql' ),
								'approved_by' => get_current_user_id(),
								'created_at'  => current_time( 'mysql' ),
							),
							array( '%d', '%d', '%s', '%d', '%s' )
						);
					}
				}
				
				// Sync capabilities to WordPress user
				Remember_Capabilities::sync_user_capabilities_from_roles( $member_id );
				
				Remember_Logger::info( 'Member roles updated', array( 'member_id' => $member_id, 'roles' => $selected_role_ids ) );
			}
			
			Remember_Profile_Questions::save_for_member( $member_id, $pq_answers );
			if ( ! empty( $remember_saved_topics ) ) {
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-access-log.php';
				Remember_Access_Log::record( $member_id, Remember_Access_Log::what_from_topics( $remember_saved_topics ), __( 'Member edit saved', 'remember' ) );
			}
			Remember_Logger::info( 'Member profile updated', array( 'member_id' => $member_id, 'sensitive_topics' => $remember_saved_topics ) );
			do_action( 'remember_member_profile_saved', $member_id );
			// Set success flag and clear edit mode (no redirect to avoid headers already sent)
			$profile_update_success = true;
			$view_member_id = $member_id; // Ensure we're viewing this member
			unset( $_GET['edit'] ); // Clear edit flag to show view mode
		} else {
			Remember_Logger::error( 'Failed to update member profile', array( 'member_id' => $member_id ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to update member profile.', 'remember' ) . '</p></div>';
		}
		} // end required-field checks
	} elseif ( $member_id > 0 && 'sync_qb_customer' === $action ) {
		if ( ! current_user_can( 'remember_update_members' ) || ! current_user_can( 'remember_update_billing' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-oauth.php';
		$qb_settings = Remember_QuickBooks_OAuth::get_settings();
		if ( empty( $qb_settings['access_token'] ) || empty( $qb_settings['realm_id'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'QuickBooks is not connected. Connect under Settings → QuickBooks.', 'remember' ) . '</p></div>';
		} else {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-sync.php';
			$result = Remember_QuickBooks_Sync::sync_member_to_customer( $member_id );
			if ( is_wp_error( $result ) ) {
				Remember_Logger::error(
					'Manual QuickBooks customer sync failed',
					array(
						'member_id' => $member_id,
						'error'     => $result->get_error_message(),
					)
				);
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				Remember_Logger::info( 'Manual QuickBooks customer sync succeeded', array( 'member_id' => $member_id ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Customer updated in QuickBooks.', 'remember' ) . '</p></div>';
			}
		}
		$view_member_id = $member_id;
	} elseif ( $member_id > 0 && 'sync_xero_contact' === $action ) {
		if ( ! current_user_can( 'remember_update_members' ) || ! current_user_can( 'remember_update_billing' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-oauth.php';
		if ( ! Remember_Xero_OAuth::is_connected() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Xero is not connected. Connect under Settings → Xero.', 'remember' ) . '</p></div>';
		} else {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
			$result = Remember_Xero_Sync::sync_member_to_contact( $member_id );
			if ( is_wp_error( $result ) ) {
				Remember_Logger::error(
					'Manual Xero contact sync failed',
					array(
						'member_id' => $member_id,
						'error'     => $result->get_error_message(),
					)
				);
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				Remember_Logger::info( 'Manual Xero contact sync succeeded', array( 'member_id' => $member_id ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Contact updated in Xero.', 'remember' ) . '</p></div>';
			}
		}
		$view_member_id = $member_id;
	} elseif ( $member_id > 0 && 'add_profile_note' === $action ) {
		if ( ! current_user_can( 'remember_update_members' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-profile-note.php';
		$note_content  = isset( $_POST['note_content'] ) ? wp_unslash( $_POST['note_content'] ) : '';
		$is_admin_only = ! empty( $_POST['is_admin_only'] );
		$note_model    = new Remember_Profile_Note();
		$note_id       = $note_model->add( $member_id, get_current_user_id(), $note_content, $is_admin_only );
		if ( $note_id ) {
			Remember_Logger::info(
				'Profile note added',
				array(
					'member_id'     => $member_id,
					'note_id'       => $note_id,
					'is_admin_only' => $is_admin_only ? 1 : 0,
				)
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Profile note added.', 'remember' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Enter a note before saving.', 'remember' ) . '</p></div>';
		}
		$view_member_id = $member_id;
	}
}

// Get filter parameters
$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : '';
$filter_role = isset( $_GET['filter_role'] ) ? absint( $_GET['filter_role'] ) : 0;
$filter_search = isset( $_GET['filter_search'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_search'] ) ) : '';
$members_per_page = 25;
$members_paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

$member_sort_columns = array( 'name', 'status', 'roles', 'joined', 'updated' );
$member_sort_meta_key = 'remember_members_list_sort';
$saved_member_sort    = get_user_meta( $current_user_id, $member_sort_meta_key, true );
if ( ! is_array( $saved_member_sort ) ) {
	$saved_member_sort = array();
}
$member_orderby     = '';
$member_order       = 'ASC';
$requested_orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
$requested_order    = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : '';
if ( in_array( $requested_orderby, $member_sort_columns, true ) && in_array( $requested_order, array( 'ASC', 'DESC' ), true ) ) {
	$member_orderby = $requested_orderby;
	$member_order   = $requested_order;
	$stored_sort    = array(
		'orderby' => $member_orderby,
		'order'   => $member_order,
	);
	if ( $saved_member_sort !== $stored_sort ) {
		update_user_meta( $current_user_id, $member_sort_meta_key, $stored_sort );
	}
} elseif (
	isset( $saved_member_sort['orderby'], $saved_member_sort['order'] )
	&& in_array( $saved_member_sort['orderby'], $member_sort_columns, true )
	&& in_array( $saved_member_sort['order'], array( 'ASC', 'DESC' ), true )
) {
	$member_orderby = $saved_member_sort['orderby'];
	$member_order   = $saved_member_sort['order'];
}

if ( ! function_exists( 'remember_members_sort_header' ) ) {
	/**
	 * Print one sortable members-list heading.
	 *
	 * @param string $column        Column key.
	 * @param string $label         Heading text.
	 * @param string $orderby       Active column.
	 * @param string $order         ASC or DESC.
	 * @param string $filter_status Status filter.
	 * @param int    $filter_role   Role filter.
	 * @return void
	 */
	function remember_members_sort_header( $column, $label, $orderby, $order, $filter_status, $filter_role, $filter_search = '' ) {
		$is_current = ( $column === $orderby );
		$next       = ( $is_current && 'ASC' === $order ) ? 'DESC' : 'ASC';
		$class      = $is_current
			? 'manage-column column-' . $column . ' sorted ' . strtolower( $order )
			: 'manage-column column-' . $column . ' sortable ' . strtolower( $next );
		$aria       = '';
		if ( $is_current ) {
			$aria = ( 'ASC' === $order ) ? ' aria-sort="ascending"' : ' aria-sort="descending"';
		}
		$args = array(
			'page'    => 'remember-members',
			'orderby' => $column,
			'order'   => $next,
		);
		if ( '' !== (string) $filter_status ) {
			$args['filter_status'] = $filter_status;
		}
		if ( (int) $filter_role > 0 ) {
			$args['filter_role'] = (int) $filter_role;
		}
		if ( '' !== (string) $filter_search ) {
			$args['filter_search'] = (string) $filter_search;
		}
		$hint = ( 'ASC' === $next )
			? __( 'Sort ascending.', 'remember' )
			: __( 'Sort descending.', 'remember' );
		echo '<th scope="col" class="' . esc_attr( $class ) . '"' . $aria . '>';
		echo '<a href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">';
		echo '<span>' . esc_html( $label ) . '</span>';
		echo '<span class="sorting-indicators">';
		echo '<span class="sorting-indicator asc" aria-hidden="true"></span>';
		echo '<span class="sorting-indicator desc" aria-hidden="true"></span>';
		echo '</span>';
		echo '<span class="screen-reader-text">' . esc_html( $hint ) . '</span>';
		echo '</a></th>';
	}
}

// Get role name if filtering by role
$role_name = '';
if ( $filter_role > 0 ) {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
	$role_model = new Remember_Role();
	$role = $role_model->get( $filter_role );
	$role_name = $role ? $role->role_name : '';
}

// Check capabilities - if user has attendees access but not full members access, show only attendees
// ($has_members_access / $has_attendees_access / $is_attendees_only / $current_user_id set earlier.)

// Get members: one SQL query handles the status/role filters, search, sort and pagination.
$members_query_args = array(
	'status'   => $filter_status,
	'role_id'  => $filter_role,
	'search'   => $filter_search,
	'orderby'  => '' !== $member_orderby ? $member_orderby : 'name',
	'order'    => '' !== $member_orderby ? $member_order : 'ASC',
	'per_page' => $members_per_page,
	'paged'    => $members_paged,
);
if ( $is_attendees_only ) {
	// Attendees-only staff see only their event attendees, and have no role filter.
	$attendee_rows = $member_model->get_attendees_for_guard( $current_user_id );
	$attendee_ids  = array();
	foreach ( (array) $attendee_rows as $attendee_row ) {
		$attendee_ids[] = (int) $attendee_row->member_id;
	}
	$members_query_args['role_id']    = 0;
	$members_query_args['member_ids'] = $attendee_ids;
}
$members_result = $member_model->query_for_list( $members_query_args );
$members        = $members_result['items'];
$members_total  = (int) $members_result['total'];
$members_pages  = (int) ceil( $members_total / max( 1, $members_per_page ) );

// Status labels and colors defined earlier (before form handlers).

// If viewing a specific member, show detail view
if ( $view_member_id > 0 ) {
	if ( $is_attendees_only && ! $member_model->guard_can_view_attendee( $current_user_id, $view_member_id ) ) {
		wp_die(
			esc_html__( 'You do not have permission to view this member.', 'remember' ),
			esc_html__( 'Access Denied', 'remember' ),
			array( 'response' => 403 )
		);
	}

	$view_member = $member_model->get( $view_member_id );
	if ( ! $view_member ) {
		wp_die( __( 'Member not found.', 'remember' ) );
	}
	
	$view_user = get_user_by( 'ID', $view_member_id );
	if ( ! $view_user ) {
		wp_die( __( 'WordPress user not found.', 'remember' ) );
	}
	
	// Get member profile
	global $wpdb;
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';
	Remember_Profile_Fields::ensure_allergy_reaction_column();
	$view_profile = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
		$view_member_id
	) );
	
	// Get member roles
	$view_roles = $wpdb->get_results( $wpdb->prepare(
		"SELECT mr.*, r.role_name, r.role_type, r.is_event_role 
		FROM {$wpdb->prefix}remember_member_roles mr
		JOIN {$wpdb->prefix}remember_roles r ON mr.role_id = r.role_id
		WHERE mr.member_id = %d
		ORDER BY r.is_event_role DESC, r.role_name ASC",
		$view_member_id
	) );
	
	// Get social media profiles
	$view_social_media = $wpdb->get_results( $wpdb->prepare(
		"SELECT msm.*, smp.platform_name 
		FROM {$wpdb->prefix}remember_member_social_media msm
		JOIN {$wpdb->prefix}remember_social_media_platforms smp ON msm.platform_id = smp.platform_id
		WHERE msm.member_id = %d
		ORDER BY smp.sort_order ASC, smp.platform_name ASC",
		$view_member_id
	) );
	
	// Refresh amounts / lines from the active billing provider before the ledger.
	// Billing is only read, synced, and rendered for staff who hold the billing cap.
	$remember_can_read_billing = current_user_can( 'remember_read_billing' );

	$view_payments = array();
	if ( $remember_can_read_billing ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';
		// Always hit Xero/QBO on this screen so the register matches the provider, not a cached snapshot.
		Remember_Billing_Provider::sync_member_payments( $view_member_id, 0 );

		$view_payments = $payment_model->get_by_member( $view_member_id );
	}
	
	// Get applications for context
	$view_applications = $application_model->get_by_member( $view_member_id );
	
	// Get all vetting cases for this member
	$view_vetting_cases = $vetting_model->get_all_by_member( $view_member_id );

	require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-profile-note.php';
	$view_profile_notes = ( new Remember_Profile_Note() )->get_for_member( $view_member_id, true );
	
	// Update member status based on last completed vetting case
	if ( ! empty( $view_vetting_cases ) ) {
		// Find the most recent completed case
		$last_completed = null;
		foreach ( $view_vetting_cases as $case ) {
			if ( 'completed' === $case->status && ! empty( $case->decision ) && 'pending' !== $case->decision ) {
				if ( ! $last_completed || strtotime( $case->decision_date ) > strtotime( $last_completed->decision_date ) ) {
					$last_completed = $case;
				}
			}
		}
		
		// Update member status if we have a completed case
		if ( $last_completed ) {
			if ( 'accepted' === $last_completed->decision && 'vetted' !== $view_member->status ) {
				$member_model->update_status( $view_member_id, 'vetted' );
				$view_member = $member_model->get( $view_member_id ); // Refresh member data
			} elseif ( 'rejected' === $last_completed->decision && 'rejected' !== $view_member->status ) {
				$member_model->update_status( $view_member_id, 'rejected' );
				$view_member = $member_model->get( $view_member_id ); // Refresh member data
			}
		}
	}
	
	// Calculate running balance for billing register
	$running_balance = 0;
	$billing_register = array();
	
	// Build chronological register (invoices and payments)
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';
	$billing_use_xero = Remember_Billing_Provider::is_xero();

	foreach ( $view_payments as $payment ) {
		// Get application and event info
		$application = $application_model->get( $payment->event_application_id );
		$event = $application ? $event_model->get( $application->event_id ) : null;

		// Prefer active-provider columns; fall back to the other provider for historical rows.
		$invoice_number = '';
		$invoice_sort_ts_raw = 0;
		$payment_lines_json = '';
		$refund_lines_json = '';
		$provider_label = __( 'QuickBooks', 'remember' );

		$refund_reduces_balance = false;
		$is_cancelled           = ( isset( $payment->payment_status ) && 'cancelled' === $payment->payment_status );

		if ( $billing_use_xero ) {
			$provider_label = __( 'Xero', 'remember' );
			if ( ! empty( $payment->xero_invoice_number ) || ! empty( $payment->xero_invoice_id ) ) {
				$invoice_number           = ! empty( $payment->xero_invoice_number ) ? $payment->xero_invoice_number : '';
				$invoice_sort_ts_raw      = ! empty( $payment->xero_invoice_sort_ts ) ? (int) $payment->xero_invoice_sort_ts : 0;
				$payment_lines_json       = ! empty( $payment->xero_payment_lines ) ? $payment->xero_payment_lines : '';
				$refund_lines_json        = ! empty( $payment->xero_refund_lines ) ? $payment->xero_refund_lines : '';
				$refund_reduces_balance   = true;
			} else {
				$invoice_number      = ! empty( $payment->quickbooks_invoice_number ) ? $payment->quickbooks_invoice_number : '';
				$invoice_sort_ts_raw = ! empty( $payment->quickbooks_invoice_sort_ts ) ? (int) $payment->quickbooks_invoice_sort_ts : 0;
				$payment_lines_json  = ! empty( $payment->quickbooks_payment_lines ) ? $payment->quickbooks_payment_lines : '';
				$refund_lines_json   = ! empty( $payment->quickbooks_refund_lines ) ? $payment->quickbooks_refund_lines : '';
				$provider_label      = __( 'QuickBooks', 'remember' );
			}
		} else {
			if ( ! empty( $payment->quickbooks_invoice_number ) || ! empty( $payment->quickbooks_invoice_id ) ) {
				$invoice_number      = ! empty( $payment->quickbooks_invoice_number ) ? $payment->quickbooks_invoice_number : '';
				$invoice_sort_ts_raw = ! empty( $payment->quickbooks_invoice_sort_ts ) ? (int) $payment->quickbooks_invoice_sort_ts : 0;
				$payment_lines_json  = ! empty( $payment->quickbooks_payment_lines ) ? $payment->quickbooks_payment_lines : '';
				$refund_lines_json   = ! empty( $payment->quickbooks_refund_lines ) ? $payment->quickbooks_refund_lines : '';
			} elseif ( ! empty( $payment->xero_invoice_id ) || ! empty( $payment->xero_invoice_number ) ) {
				$provider_label           = __( 'Xero', 'remember' );
				$invoice_number           = ! empty( $payment->xero_invoice_number ) ? $payment->xero_invoice_number : '';
				$invoice_sort_ts_raw      = ! empty( $payment->xero_invoice_sort_ts ) ? (int) $payment->xero_invoice_sort_ts : 0;
				$payment_lines_json       = ! empty( $payment->xero_payment_lines ) ? $payment->xero_payment_lines : '';
				$refund_lines_json        = ! empty( $payment->xero_refund_lines ) ? $payment->xero_refund_lines : '';
				$refund_reduces_balance   = true;
			}
		}
		
		// Invoice entry (when payment record created)
		$invoice_description = $event ? sprintf( __( 'Invoice: %s', 'remember' ), $event->event_name ) : __( 'Invoice', 'remember' );
		$invoice_url         = '';
		if ( ! empty( $invoice_number ) ) {
			$invoice_description .= ' ' . sprintf( __( '(Invoice #%s)', 'remember' ), $invoice_number );
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-template.php';
			$link_data   = Remember_Billing_Template::get_payment_invoice_link_data( $payment );
			$invoice_url = ! empty( $link_data['url'] ) ? $link_data['url'] : '';
		}

		$invoice_sort_ts = $invoice_sort_ts_raw > 0 ? $invoice_sort_ts_raw : strtotime( $payment->created_at );
		if ( $invoice_sort_ts <= 0 ) {
			$invoice_sort_ts = strtotime( $payment->created_at );
		}

		if ( $is_cancelled ) {
			$billing_register[] = array(
				'date'            => date( 'Y-m-d H:i:s', $invoice_sort_ts ),
				'sort_ts'         => $invoice_sort_ts,
				'type'            => 'invoice',
				'description'     => $invoice_description,
				'invoice_number'  => $invoice_number,
				'invoice_url'     => $invoice_url,
				'debit'           => 0,
				'credit'          => 0,
				'balance'         => 0,
				'status'          => 'cancelled',
				'payment_id'      => $payment->payment_id,
				'application_id'  => $payment->event_application_id,
				'affects_balance' => false,
			);
			continue;
		}

		$applied_credits = 0;

		$billing_register[] = array(
			'date' => date( 'Y-m-d H:i:s', $invoice_sort_ts ),
			'sort_ts' => $invoice_sort_ts,
			'type' => 'invoice',
			'description' => $invoice_description,
			'invoice_number' => $invoice_number,
			'invoice_url' => $invoice_url,
			'debit' => $payment->total_amount,
			'credit' => 0,
			'balance' => 0, // Will calculate after sorting
			'status' => $payment->payment_status,
			'payment_id' => $payment->payment_id,
			'application_id' => $payment->event_application_id,
		);
		
		// Payment row(s): one line per external Payment when synced; otherwise one aggregated line.
		$qb_payment_lines = array();
		if ( ! empty( $payment_lines_json ) ) {
			$decoded = json_decode( $payment_lines_json, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$qb_payment_lines = $decoded;
			}
		}

		if ( ! empty( $qb_payment_lines ) ) {
			foreach ( $qb_payment_lines as $qb_line ) {
				$credit = isset( $qb_line['amount'] ) ? floatval( $qb_line['amount'] ) : 0;
				if ( $credit <= 0 ) {
					continue;
				}
				$method = isset( $qb_line['payment_method'] ) ? $qb_line['payment_method'] : '';
				$desc   = sprintf(
					__( 'Payment - %s', 'remember' ),
					$method !== '' ? $method : $provider_label
				);
				if ( ! empty( $qb_line['qb_payment_id'] ) ) {
					$desc .= ' ' . sprintf( __( '(#%s)', 'remember' ), $qb_line['qb_payment_id'] );
				}
				$line_sort_ts = isset( $qb_line['sort_ts'] ) ? (int) $qb_line['sort_ts'] : 0;
				if ( $line_sort_ts <= 0 ) {
					$line_sort_ts = ! empty( $qb_line['txn_date'] )
						? strtotime( $qb_line['txn_date'] . ' 12:00:00' )
						: strtotime( $payment->payment_date ? $payment->payment_date : 'now' );
				}
				$billing_register[] = array(
					'date' => date( 'Y-m-d H:i:s', $line_sort_ts ),
					'sort_ts' => $line_sort_ts,
					'type' => 'payment',
					'description' => $desc,
					'debit' => 0,
					'credit' => $credit,
					'balance' => 0,
					'status' => $payment->payment_status,
					'payment_id' => $payment->payment_id,
					'transaction_id' => $payment->transaction_id,
					'qb_payment_id' => isset( $qb_line['qb_payment_id'] ) ? (string) $qb_line['qb_payment_id'] : '',
				);
				$applied_credits += $credit;
			}
		} elseif ( $payment->amount_paid > 0 && $payment->payment_date ) {
			$manual_ts = strtotime( $payment->payment_date );
			if ( $manual_ts <= 0 ) {
				$manual_ts = strtotime( $payment->created_at );
			}
			$billing_register[] = array(
				'date' => date( 'Y-m-d H:i:s', $manual_ts ),
				'sort_ts' => $manual_ts,
				'type' => 'payment',
				'description' => sprintf( __( 'Payment - %s', 'remember' ), $payment->payment_method ?: __( 'Manual', 'remember' ) ),
				'debit' => 0,
				'credit' => $payment->amount_paid,
				'balance' => 0, // Will calculate after sorting
				'status' => $payment->payment_status,
				'payment_id' => $payment->payment_id,
				'transaction_id' => $payment->transaction_id,
			);
			$applied_credits += floatval( $payment->amount_paid );
		}

		// Refund / credit note row(s), chronological with payments.
		$qb_refund_lines = array();
		if ( ! empty( $refund_lines_json ) ) {
			$decoded = json_decode( $refund_lines_json, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$qb_refund_lines = $decoded;
			}
		}
		foreach ( $qb_refund_lines as $rf_line ) {
			$amount = isset( $rf_line['amount'] ) ? floatval( $rf_line['amount'] ) : 0;
			if ( $amount <= 0 ) {
				continue;
			}
			$method = isset( $rf_line['payment_method'] ) ? $rf_line['payment_method'] : '';
			$desc   = sprintf(
				__( 'Refund - %s', 'remember' ),
				$method !== '' ? $method : $provider_label
			);
			if ( ! empty( $rf_line['doc_number'] ) ) {
				$desc .= ' ' . sprintf( __( '(Receipt #%s)', 'remember' ), $rf_line['doc_number'] );
			} elseif ( ! empty( $rf_line['qb_refund_id'] ) ) {
				$desc .= ' ' . sprintf( __( '(#%s)', 'remember' ), $rf_line['qb_refund_id'] );
			}
			$rf_sort_ts = isset( $rf_line['sort_ts'] ) ? (int) $rf_line['sort_ts'] : 0;
			if ( $rf_sort_ts <= 0 ) {
				$rf_sort_ts = ! empty( $rf_line['txn_date'] )
					? strtotime( $rf_line['txn_date'] . ' 12:00:00' )
					: strtotime( $payment->payment_date ? $payment->payment_date : 'now' );
			}
			// Xero credit-note allocations reduce amount owed (credit). QBO refund receipts stay audit-only.
			$as_credit = $refund_reduces_balance
				|| ( isset( $rf_line['ledger_effect'] ) && 'credit' === $rf_line['ledger_effect'] );
			$billing_register[] = array(
				'date'         => date( 'Y-m-d H:i:s', $rf_sort_ts ),
				'sort_ts'      => $rf_sort_ts,
				'type'         => 'refund',
				'description'  => $desc,
				'debit'        => $as_credit ? 0 : $amount,
				'credit'       => $as_credit ? $amount : 0,
				'balance'      => 0,
				'status'       => $payment->payment_status,
				'payment_id'   => $payment->payment_id,
				'qb_refund_id' => isset( $rf_line['qb_refund_id'] ) ? (string) $rf_line['qb_refund_id'] : '',
				'affects_balance' => $as_credit,
			);
			if ( $as_credit ) {
				$applied_credits += $amount;
			}
		}

		// Provider remaining due is the source of truth. If local lines still show more
		// owed than Xero/QBO AmountDue, add a credit so Current Balance matches.
		$provider_due = isset( $payment->amount_due ) ? floatval( $payment->amount_due ) : null;
		if ( null !== $provider_due ) {
			$from_lines = floatval( $payment->total_amount ) - $applied_credits;
			$gap        = round( $from_lines - $provider_due, 2 );
			if ( $gap > 0.001 ) {
				$gap_ts = $invoice_sort_ts + 3;
				$billing_register[] = array(
					'date'            => date( 'Y-m-d H:i:s', $gap_ts ),
					'sort_ts'         => $gap_ts,
					'type'            => 'refund',
					'description'     => __( 'Allocated credit', 'remember' ),
					'debit'           => 0,
					'credit'          => $gap,
					'balance'         => 0,
					'status'          => $payment->payment_status,
					'payment_id'      => $payment->payment_id,
					'qb_refund_id'    => 'provider-amount-due',
					'affects_balance' => true,
				);
			}
		}
	}

	// Unallocated credit notes exist on the contact before any invoice, so they belong on the register too.
	if ( $billing_use_xero && isset( $view_member_id ) ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
		$remember_open_credit = Remember_Xero_Sync::member_open_credit( (int) $view_member_id );
		if ( is_array( $remember_open_credit ) && ! empty( $remember_open_credit['notes'] ) ) {
			foreach ( $remember_open_credit['notes'] as $open_note ) {
				$open_ts = ! empty( $open_note['sort_ts'] ) ? (int) $open_note['sort_ts'] : 0;
				if ( $open_ts <= 0 && ! empty( $open_note['date'] ) ) {
					$open_ts = strtotime( $open_note['date'] . ' 12:00:00' );
				}
				if ( $open_ts <= 0 ) {
					$open_ts = time();
				}
				$open_number = ! empty( $open_note['number'] ) ? (string) $open_note['number'] : __( 'Credit note', 'remember' );
				// RemainingCredit only. The allocated part is already on the invoice
				// (refund line and AmountDue), so this row must not enter that math.
				$billing_register[] = array(
					'date'            => date( 'Y-m-d H:i:s', $open_ts ),
					'sort_ts'         => $open_ts,
					'type'            => 'credit',
					'description'     => sprintf(
						/* translators: %s: credit note number */
						__( 'Open credit note %s', 'remember' ),
						$open_number
					),
					'debit'           => 0,
					'credit'          => isset( $open_note['remaining'] ) ? floatval( $open_note['remaining'] ) : 0,
					'balance'         => 0,
					'status'          => 'available',
					'credit_note_id'  => isset( $open_note['id'] ) ? (string) $open_note['id'] : '',
					'affects_balance' => false,
				);
			}
		}
	}
	
	// Sort by QuickBooks timestamps (invoice before payment if tied).
	usort(
		$billing_register,
		function ( $a, $b ) {
			$ta = isset( $a['sort_ts'] ) ? (int) $a['sort_ts'] : strtotime( $a['date'] );
			$tb = isset( $b['sort_ts'] ) ? (int) $b['sort_ts'] : strtotime( $b['date'] );
			if ( $ta === $tb ) {
				$order = array(
					'invoice' => 0,
					'payment' => 1,
					'credit'  => 2,
					'refund'  => 3,
				);
				$oa = isset( $order[ $a['type'] ] ) ? $order[ $a['type'] ] : 3;
				$ob = isset( $order[ $b['type'] ] ) ? $order[ $b['type'] ] : 3;
				if ( $oa !== $ob ) {
					return $oa <=> $ob;
				}
				if ( 'payment' === ( $a['type'] ?? '' ) && 'payment' === ( $b['type'] ?? '' ) ) {
					return strcmp( (string) ( $a['qb_payment_id'] ?? '' ), (string) ( $b['qb_payment_id'] ?? '' ) );
				}
				if ( 'refund' === ( $a['type'] ?? '' ) && 'refund' === ( $b['type'] ?? '' ) ) {
					return strcmp( (string) ( $a['qb_refund_id'] ?? '' ), (string) ( $b['qb_refund_id'] ?? '' ) );
				}
				return ( $a['payment_id'] ?? 0 ) <=> ( $b['payment_id'] ?? 0 );
			}
			return $ta <=> $tb;
		}
	);
	
	// Running balance: invoices debit, payments credit, Xero credit notes / QBO credit memos credit.
	// Cancelled invoices, QBO refund-receipt audit rows, and still-unallocated credit notes do not change this walk.
	// Unallocated credit is applied once, below, from RemainingCredit. After Xero allocates a note, RemainingCredit
	// drops and the same amount is already inside AmountDue, so it is not subtracted again.
	foreach ( $billing_register as &$entry ) {
		$balance_memo = ( 'cancelled' === ( $entry['status'] ?? '' ) )
			|| ( in_array( ( $entry['type'] ?? '' ), array( 'refund', 'credit' ), true ) && empty( $entry['affects_balance'] ) );
		if ( $balance_memo ) {
			$entry['balance'] = $running_balance;
			continue;
		}
		$running_balance += $entry['debit'] - $entry['credit'];
		$entry['balance'] = $running_balance;
	}
	unset( $entry );

	$provider_balance = 0.0;
	foreach ( $view_payments as $p ) {
		if ( isset( $p->payment_status ) && 'cancelled' === $p->payment_status ) {
			continue;
		}
		$provider_balance += isset( $p->amount_due ) ? floatval( $p->amount_due ) : 0.0;
	}
	$unallocated_credit = ( isset( $remember_open_credit ) && is_array( $remember_open_credit ) )
		? floatval( $remember_open_credit['total'] )
		: 0.0;
	// Amount due already excludes credit allocated to invoices. Subtract only what is still open.
	$running_balance = round( $provider_balance - $unallocated_credit, 2 );

	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';
	require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-oauth.php';
	require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-oauth.php';

	$remember_qb_settings           = Remember_QuickBooks_OAuth::get_settings();
	$remember_qb_show_sync_customer = Remember_Billing_Provider::is_quickbooks()
		&& current_user_can( 'remember_update_members' )
		&& current_user_can( 'remember_update_billing' )
		&& ! empty( $remember_qb_settings['access_token'] )
		&& ! empty( $remember_qb_settings['realm_id'] );

	$remember_xero_show_sync_contact = Remember_Billing_Provider::is_xero()
		&& current_user_can( 'remember_update_members' )
		&& current_user_can( 'remember_update_billing' )
		&& Remember_Xero_OAuth::is_connected();
}
?>

<div class="wrap remember-members">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php if ( $view_member_id > 0 ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back to Members', 'remember' ); ?></a>
	<?php elseif ( ! isset( $_GET['add'] ) && current_user_can( 'remember_create_members' ) ) : ?>
		<button type="button" class="page-title-action" onclick="document.getElementById('remember-add-member').style.display='block'; document.getElementById('remember-convert-wp-user').style.display='none'; this.style.display='none'; var c=document.getElementById('remember-convert-toggle'); if(c){c.style.display='inline-block';}"><?php esc_html_e( 'Add New', 'remember' ); ?></button>
		<button type="button" class="page-title-action" id="remember-convert-toggle" onclick="document.getElementById('remember-convert-wp-user').style.display='block'; document.getElementById('remember-add-member').style.display='none'; this.style.display='none'; var a=document.querySelector('.remember-members .page-title-action'); if(a){a.style.display='inline-block';}"><?php esc_html_e( 'Convert WP User', 'remember' ); ?></button>
	<?php endif; ?>
	<hr class="wp-header-end">
	
	<?php if ( isset( $profile_update_success ) && $profile_update_success ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Member profile updated successfully.', 'remember' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $view_member_id > 0 ) : ?>
		<!-- Member Detail View -->
		<?php include 'member-detail.php'; ?>
	<?php else : ?>
		<!-- Add Form -->
	<?php if ( current_user_can( 'remember_create_members' ) ) : ?>
	<div id="remember-add-member" style="display:none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<h2><?php esc_html_e( 'Add New Member', 'remember' ); ?></h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'remember_member_action', 'remember_member_nonce' ); ?>
			<input type="hidden" name="remember_member_action" value="add">
			
			<table class="form-table">
				<tr>
					<th><label for="username"><?php esc_html_e( 'Username', 'remember' ); ?> <span class="description">(required)</span></label></th>
					<td><input type="text" id="username" name="username" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="email"><?php esc_html_e( 'Email', 'remember' ); ?> <span class="description">(required)</span></label></th>
					<td><input type="email" id="email" name="email" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="first_name"><?php esc_html_e( 'First Name', 'remember' ); ?></label></th>
					<td><input type="text" id="first_name" name="first_name" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="last_name"><?php esc_html_e( 'Last Name', 'remember' ); ?></label></th>
					<td><input type="text" id="last_name" name="last_name" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="password"><?php esc_html_e( 'Password', 'remember' ); ?></label></th>
					<td>
						<input type="text" id="password" name="password" class="regular-text">
						<p class="description"><?php esc_html_e( 'Leave blank to auto-generate. Password will be emailed to the user.', 'remember' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="status"><?php esc_html_e( 'Initial Status', 'remember' ); ?></label></th>
					<td>
						<select id="status" name="status" class="regular-text">
							<?php foreach ( $status_labels as $status => $label ) : ?>
								<?php if ( 'merged' === $status ) { continue; } ?>
								<option value="<?php echo esc_attr( $status ); ?>" <?php selected( 'pending_vetting', $status ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			
			<p class="submit">
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Member', 'remember' ); ?>">
				<button type="button" class="button" onclick="document.getElementById('remember-add-member').style.display='none'; document.querySelectorAll('.remember-members .page-title-action').forEach(function(b){b.style.display='inline-block';});"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
			</p>
		</form>
	</div>

	<?php
	$non_member_wp_users = $member_model->get_non_member_wp_users();
	?>
	<div id="remember-convert-wp-user" style="display:none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<h2><?php esc_html_e( 'Convert WordPress User to Member', 'remember' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Existing WordPress users (including site admins) are not members until you convert them here, or until they register via the public member form. This does not change their WordPress login or display name.', 'remember' ); ?></p>
		<?php if ( empty( $non_member_wp_users ) ) : ?>
			<p><?php esc_html_e( 'Every WordPress user on this site already has a reMember member record.', 'remember' ); ?></p>
			<p>
				<button type="button" class="button" onclick="document.getElementById('remember-convert-wp-user').style.display='none'; document.querySelectorAll('.remember-members .page-title-action').forEach(function(b){b.style.display='inline-block';});"><?php esc_html_e( 'Close', 'remember' ); ?></button>
			</p>
		<?php else : ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'remember_member_action', 'remember_member_nonce' ); ?>
				<input type="hidden" name="remember_member_action" value="convert_wp_user">
				<table class="form-table">
					<tr>
						<th><label for="wp_user_id"><?php esc_html_e( 'WordPress User', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td>
							<select id="wp_user_id" name="wp_user_id" class="regular-text" required>
								<option value=""><?php esc_html_e( '— Select a user —', 'remember' ); ?></option>
								<?php foreach ( $non_member_wp_users as $wp_user_row ) : ?>
									<option value="<?php echo esc_attr( $wp_user_row->ID ); ?>">
										<?php
										echo esc_html(
											sprintf(
												'%1$s (%2$s) — %3$s',
												$wp_user_row->display_name ? $wp_user_row->display_name : $wp_user_row->user_login,
												$wp_user_row->user_login,
												$wp_user_row->user_email
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="convert_status"><?php esc_html_e( 'Initial Status', 'remember' ); ?></label></th>
						<td>
							<select id="convert_status" name="convert_status" class="regular-text">
								<?php foreach ( $status_labels as $status => $label ) : ?>
									<?php if ( 'merged' === $status ) { continue; } ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( 'pending_vetting', $status ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Confirmation', 'remember' ); ?></th>
						<td>
							<label for="confirm_convert_member">
								<input type="checkbox" name="confirm_convert_member" id="confirm_convert_member" value="1" required>
								<?php esc_html_e( 'I intentionally want to make this WordPress user a reMember member.', 'remember' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Make Member', 'remember' ); ?>">
					<button type="button" class="button" onclick="document.getElementById('remember-convert-wp-user').style.display='none'; document.querySelectorAll('.remember-members .page-title-action').forEach(function(b){b.style.display='inline-block';});"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
	</div>
	<?php endif; // remember_create_members ?>

	<?php if ( ! $is_attendees_only ) : ?>
		<!-- Filters -->
		<div class="remember-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
			<form method="get" action="">
				<input type="hidden" name="page" value="remember-members">
				<?php if ( '' !== $member_orderby ) : ?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $member_orderby ); ?>">
					<input type="hidden" name="order" value="<?php echo esc_attr( $member_order ); ?>">
				<?php endif; ?>
				
				<label for="filter_status"><?php esc_html_e( 'Filter by Status:', 'remember' ); ?></label>
				<select id="filter_status" name="filter_status" style="margin-right: 20px;">
					<option value=""><?php esc_html_e( 'All Statuses', 'remember' ); ?></option>
					<?php foreach ( $status_labels as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<?php
				// Get roles for filter (only if user has full members access)
				if ( $has_members_access ) {
					require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
					$role_model = new Remember_Role();
					$all_roles = $role_model->get_all();
				?>
					<label for="filter_role"><?php esc_html_e( 'Filter by Role:', 'remember' ); ?></label>
					<select id="filter_role" name="filter_role" style="margin-right: 20px;">
						<option value="0"><?php esc_html_e( 'All Roles', 'remember' ); ?></option>
						<?php foreach ( $all_roles as $role ) : ?>
							<option value="<?php echo esc_attr( $role->role_id ); ?>" <?php selected( $filter_role, $role->role_id ); ?>>
								<?php echo esc_html( $role->role_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php } ?>

				<label for="filter_search" class="screen-reader-text"><?php esc_html_e( 'Search members', 'remember' ); ?></label>
				<input type="search" id="filter_search" name="filter_search" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, member #', 'remember' ); ?>" style="margin-right: 10px; min-width: 220px;">

				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'remember' ); ?>">
				<?php if ( ! empty( $filter_status ) || $filter_role > 0 || '' !== $filter_search ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'remember' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
	<?php else : ?>
		<div class="notice notice-info" style="margin: 20px 0;">
			<p>
				<strong><?php esc_html_e( 'Attendees View', 'remember' ); ?></strong><br>
				<?php esc_html_e( 'You are viewing attendees (members with accepted applications) for events you are working on.', 'remember' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Members List -->
	<?php if ( ! empty( $members ) ) : ?>
		<?php
		// Sorting and the status/role/search filters already ran in SQL; here we only
		// batch-fetch the display data for the rows actually on this page.
		$list_roles      = array();
		$list_updated    = array();
		$list_users      = array();
		$list_profiles   = array();
		$list_member_ids = array();
		foreach ( $members as $list_member ) {
			$list_member_ids[] = (int) $list_member->member_id;
		}
		$list_member_ids = array_values( array_unique( array_filter( $list_member_ids ) ) );
		if ( $list_member_ids ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $list_member_ids ), '%d' ) );
			$role_rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT mr.member_id, r.role_name, r.is_event_role
					FROM {$wpdb->prefix}remember_member_roles mr
					INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
					WHERE mr.member_id IN ($placeholders)
					ORDER BY r.is_event_role DESC, r.role_name ASC",
					$list_member_ids
				)
			);
			if ( is_array( $role_rows ) ) {
				foreach ( $role_rows as $role_row ) {
					$list_roles[ (int) $role_row->member_id ][] = $role_row;
				}
			}
			$profile_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id IN ($placeholders)",
					$list_member_ids
				)
			);
			if ( is_array( $profile_rows ) ) {
				foreach ( $profile_rows as $profile_row ) {
					$list_profiles[ (int) $profile_row->member_id ] = $profile_row;
				}
			}
			$list_user_objs = get_users(
				array(
					'include' => $list_member_ids,
					'fields'  => array( 'ID', 'display_name', 'user_email' ),
				)
			);
			foreach ( $list_user_objs as $list_user_obj ) {
				$list_users[ (int) $list_user_obj->ID ] = $list_user_obj;
			}
		}
		foreach ( $members as $list_member ) {
			$sort_id         = (int) $list_member->member_id;
			$profile_updated = isset( $list_profiles[ $sort_id ] ) ? (string) $list_profiles[ $sort_id ]->updated_at : '';
			if ( '' === $profile_updated || '0000-00-00 00:00:00' === $profile_updated ) {
				$profile_updated = isset( $list_member->updated_at ) ? (string) $list_member->updated_at : '';
			}
			$list_updated[ $sort_id ] = ( '0000-00-00 00:00:00' === $profile_updated ) ? '' : $profile_updated;
		}
		?>
		<div class="remember-table-scroll">
		<table class="wp-list-table widefat striped remember-responsive-table">
			<thead>
				<tr>
					<?php remember_members_sort_header( 'name', __( 'Name', 'remember' ), $member_orderby, $member_order, $filter_status, $filter_role, $filter_search ); ?>
					<th class="column-email" scope="col"><?php esc_html_e( 'Contact', 'remember' ); ?></th>
					<?php remember_members_sort_header( 'status', __( 'Status', 'remember' ), $member_orderby, $member_order, $filter_status, $filter_role, $filter_search ); ?>
					<?php remember_members_sort_header( 'roles', __( 'Roles', 'remember' ), $member_orderby, $member_order, $filter_status, $filter_role, $filter_search ); ?>
					<?php remember_members_sort_header( 'joined', __( 'Joined', 'remember' ), $member_orderby, $member_order, $filter_status, $filter_role, $filter_search ); ?>
					<?php remember_members_sort_header( 'updated', __( 'Updated', 'remember' ), $member_orderby, $member_order, $filter_status, $filter_role, $filter_search ); ?>
					<th class="column-actions" scope="col"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $members as $member ) :
						$member_id_row = (int) $member->member_id;
						$user = isset( $list_users[ $member_id_row ] ) ? $list_users[ $member_id_row ] : get_user_by( 'ID', $member_id_row );
					if ( ! $user ) {
						continue; // Skip if user doesn't exist
					}

					// Profile info (batch-fetched for this page above).
					$profile = isset( $list_profiles[ $member_id_row ] ) ? $list_profiles[ $member_id_row ] : null;

					// Get vetting info
					$vetting = $vetting_model->get_by_member( $member->member_id );
						?>
						<tr>
						<td class="column-name" data-label="<?php echo esc_attr__( 'Name', 'remember' ); ?>">
							<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $member->member_id ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a></strong>
							<?php if ( $profile ) : ?>
								<br><span class="description"><?php echo esc_html( Remember_Import_Export::member_list_legal_name_line( $profile, (int) $member->member_id ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-email" data-label="<?php echo esc_attr__( 'Contact', 'remember' ); ?>">
							<div class="remember-member-list-lines">
								<?php if ( ! empty( $user->user_email ) ) : ?>
									<span class="remember-member-list-line remember-member-list-line--email">
										<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
										<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
									</span>
								<?php endif; ?>
								<?php if ( $profile && $profile->cell_phone ) : ?>
									<span class="remember-member-list-line remember-member-list-line--phone">
										<span class="dashicons dashicons-phone" aria-hidden="true"></span>
										<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $profile->cell_phone ) ); ?>"><?php echo esc_html( $profile->cell_phone ); ?></a>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td class="column-status" data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
							<span style="color: <?php echo esc_attr( $status_colors[ $member->status ] ); ?>; font-weight: bold;">
								<?php echo esc_html( $status_labels[ $member->status ] ); ?>
								</span>
							</td>
						<td class="column-roles">
							<?php
							$member_list_roles = isset( $list_roles[ (int) $member->member_id ] ) ? $list_roles[ (int) $member->member_id ] : array();
							if ( $member_list_roles ) :
								?>
								<div class="remember-member-list-roles">
									<?php foreach ( $member_list_roles as $member_role ) : ?>
										<span class="remember-member-detail-role <?php echo $member_role->is_event_role ? 'is-event' : 'is-system'; ?>" title="<?php echo $member_role->is_event_role ? esc_attr__( 'Event role', 'remember' ) : esc_attr__( 'System role', 'remember' ); ?>">
											<?php echo esc_html( $member_role->role_name ); ?>
										</span>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
						<td class="column-joined" data-label="<?php echo esc_attr__( 'Joined', 'remember' ); ?>">
							<span class="remember-member-list-joined-label"><?php esc_html_e( 'Joined:', 'remember' ); ?></span>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $member->created_at ) ) ); ?>
						</td>
						<td class="column-updated" data-label="<?php echo esc_attr__( 'Updated', 'remember' ); ?>">
							<span class="remember-member-list-joined-label"><?php esc_html_e( 'Updated:', 'remember' ); ?></span>
							<?php
							$updated_raw = isset( $list_updated[ (int) $member->member_id ] ) ? $list_updated[ (int) $member->member_id ] : '';
							if ( '' !== $updated_raw ) {
								echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $updated_raw ) ) );
							} else {
								echo '<span class="description">' . esc_html__( '—', 'remember' ) . '</span>';
							}
							?>
						</td>
						<td class="column-actions" data-label="<?php echo esc_attr__( 'Actions', 'remember' ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $member->member_id ) ); ?>"><?php esc_html_e( 'View Profile', 'remember' ); ?></a>
							<?php 
							// Only show vetting link if there's a non-completed case
							if ( $vetting && 'completed' !== $vetting->status ) : ?>
								| <a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting&view=' . $vetting->vetting_id ) ); ?>"><?php esc_html_e( 'In Vetting', 'remember' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		
		<?php
		$members_range_start = $members_total > 0 ? ( ( $members_paged - 1 ) * $members_per_page ) + 1 : 0;
		$members_range_end   = min( $members_total, $members_paged * $members_per_page );
		$members_pagination_args = array( 'page' => 'remember-members' );
		if ( '' !== $filter_status ) {
			$members_pagination_args['filter_status'] = $filter_status;
		}
		if ( $filter_role > 0 ) {
			$members_pagination_args['filter_role'] = $filter_role;
		}
		if ( '' !== $filter_search ) {
			$members_pagination_args['filter_search'] = $filter_search;
		}
		if ( '' !== $member_orderby ) {
			$members_pagination_args['orderby'] = $member_orderby;
			$members_pagination_args['order']   = $member_order;
		}
		$members_pagination_base = add_query_arg( array_merge( $members_pagination_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: first row number, 2: last row number, 3: total members */
							__( 'Showing %1$d-%2$d of %3$d', 'remember' ),
							$members_range_start,
							$members_range_end,
							$members_total
						)
					);
					?>
				</span>
				<?php
				if ( $members_pages > 1 ) {
					echo wp_kses_post(
						paginate_links(
							array(
								'base'     => $members_pagination_base,
								'format'   => '',
								'current'  => $members_paged,
								'total'    => $members_pages,
								'add_args' => false,
							)
						)
					);
				}
				?>
			</div>
		</div>
	<?php else : ?>
		<p class="description" style="margin-top: 15px;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: total members matching the filters */
					__( 'No members found (%d match the current filters).', 'remember' ),
					$members_total
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php endif; ?>
</div>
