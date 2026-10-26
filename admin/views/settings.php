<?php
/**
 * Settings view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-notification-setting.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-oauth.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-api.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-oauth.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-api.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-messaging.php';

Remember_Logger::debug( 'Settings page loaded' );

if ( current_user_can( 'remember_access_settings' ) ) {
	echo '<div class="notice notice-info" style="margin: 12px 0 18px;"><p>';
	echo '<strong>' . esc_html__( 'Getting Started', 'remember' ) . '</strong> — ';
	echo esc_html__( 'Set up locations, roles, optional add-ons (Products), and events in the recommended order, then wire up front-end pages when you are ready.', 'remember' ) . ' ';
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=remember-getting-started' ) ) . '">' . esc_html__( 'Open the Getting Started guide', 'remember' ) . '</a>';
	echo '</p></div>';
}

// Show success message if pages were just set up
if ( isset( $_GET['pages_setup'] ) ) {
	$message = urldecode( sanitize_text_field( $_GET['pages_setup'] ) );
	if ( ! empty( $message ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

if ( isset( $_GET['qb_oauth_error'] ) && 'nocreds' === $_GET['qb_oauth_error'] ) {
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Save your Client ID and Client Secret before connecting to QuickBooks.', 'remember' ) . '</p></div>';
}

if ( isset( $_GET['xero_oauth_error'] ) && 'nocreds' === $_GET['xero_oauth_error'] ) {
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Save your Client ID and Client Secret before connecting to Xero.', 'remember' ) . '</p></div>';
}

// All settings mutations require remember_access_settings (not nonce alone).
if ( isset( $_POST['remember_settings_action'] ) && ! current_user_can( 'remember_access_settings' ) ) {
	wp_die(
		esc_html__( 'You do not have sufficient permissions to perform this action.', 'remember' ),
		esc_html__( 'Access Denied', 'remember' ),
		array( 'response' => 403 )
	);
}

$qb_oauth_notice = get_transient( 'remember_qb_oauth_notice_' . get_current_user_id() );
if ( $qb_oauth_notice && is_array( $qb_oauth_notice ) && ! empty( $qb_oauth_notice['message'] ) ) {
	delete_transient( 'remember_qb_oauth_notice_' . get_current_user_id() );
	$notice_class = ( isset( $qb_oauth_notice['type'] ) && 'success' === $qb_oauth_notice['type'] ) ? 'notice-success' : 'notice-error';
	echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $qb_oauth_notice['message'] ) . '</p></div>';
}

$xero_oauth_notice = get_transient( 'remember_xero_oauth_notice_' . get_current_user_id() );
if ( $xero_oauth_notice && is_array( $xero_oauth_notice ) && ! empty( $xero_oauth_notice['message'] ) ) {
	delete_transient( 'remember_xero_oauth_notice_' . get_current_user_id() );
	$notice_class = ( isset( $xero_oauth_notice['type'] ) && 'success' === $xero_oauth_notice['type'] ) ? 'notice-success' : 'notice-error';
	echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $xero_oauth_notice['message'] ) . '</p></div>';
}

$notification_model = new Remember_Notification_Setting();

// Handle QuickBooks manual sync
if ( isset( $_POST['remember_settings_action'] ) && 'sync_qb_payments' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-sync.php';
	$sync_results = Remember_QuickBooks_Sync::sync_all_payments();
	
	if ( $sync_results['success'] > 0 || $sync_results['error'] > 0 ) {
		$message = sprintf(
			__( 'Sync completed: %d successful, %d errors.', 'remember' ),
			$sync_results['success'],
			$sync_results['error']
		);
		if ( $sync_results['error'] > 0 ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	} else {
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'No payments to sync.', 'remember' ) . '</p></div>';
	}
}

// Handle Xero manual sync
if ( isset( $_POST['remember_settings_action'] ) && 'sync_xero_payments' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
	$sync_results = Remember_Xero_Sync::sync_all_payments();

	if ( $sync_results['success'] > 0 || $sync_results['error'] > 0 ) {
		$message = sprintf(
			__( 'Sync completed: %d successful, %d errors.', 'remember' ),
			$sync_results['success'],
			$sync_results['error']
		);
		if ( $sync_results['error'] > 0 ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	} else {
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'No payments to sync.', 'remember' ) . '</p></div>';
	}
}

// Handle QuickBooks disconnect
if ( isset( $_POST['remember_settings_action'] ) && 'disconnect_qb' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	$settings = Remember_QuickBooks_OAuth::get_settings();
	if ( $settings && ! empty( $settings['access_token'] ) ) {
		Remember_QuickBooks_OAuth::revoke_token(
			$settings['access_token'],
			$settings['client_id'],
			$settings['client_secret']
		);
	}
	
	// Clear settings
	Remember_QuickBooks_OAuth::save_settings( array() );
	
	// Deactivate processor
	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'remember_payment_processors',
		array( 'is_active' => 0 ),
		array( 'processor_type' => 'quickbooks' ),
		array( '%d' ),
		array( '%s' )
	);
	
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'QuickBooks disconnected successfully.', 'remember' ) . '</p></div>';
}

// Handle QuickBooks credentials save
if ( isset( $_POST['remember_settings_action'] ) && 'save_qb_credentials' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	$settings = Remember_QuickBooks_OAuth::get_settings() ?: array();
	$settings['client_id'] = isset( $_POST['qb_client_id'] ) ? sanitize_text_field( $_POST['qb_client_id'] ) : '';
	
	// Only update client_secret if a new value was provided
	if ( ! empty( $_POST['qb_client_secret'] ) ) {
		$settings['client_secret'] = sanitize_text_field( $_POST['qb_client_secret'] );
	}
	
	$settings['environment'] = isset( $_POST['qb_environment'] ) ? sanitize_text_field( $_POST['qb_environment'] ) : 'sandbox';
	
	if ( Remember_QuickBooks_OAuth::save_settings( $settings ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'QuickBooks credentials saved successfully.', 'remember' ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to save QuickBooks credentials.', 'remember' ) . '</p></div>';
	}
}

// Handle Xero disconnect.
if ( isset( $_POST['remember_settings_action'] ) && 'disconnect_xero' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	$settings = Remember_Xero_OAuth::get_settings();
	if ( $settings && ! empty( $settings['refresh_token'] ) && ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) {
		Remember_Xero_OAuth::revoke_token(
			$settings['refresh_token'],
			$settings['client_id'],
			$settings['client_secret']
		);
	} elseif ( $settings && ! empty( $settings['access_token'] ) && ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) {
		Remember_Xero_OAuth::revoke_token(
			$settings['access_token'],
			$settings['client_id'],
			$settings['client_secret']
		);
	}

	Remember_Xero_OAuth::save_settings( array() );

	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'remember_payment_processors',
		array( 'is_active' => 0 ),
		array( 'processor_type' => 'xero' ),
		array( '%d' ),
		array( '%s' )
	);

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Xero disconnected successfully.', 'remember' ) . '</p></div>';
}

// Handle Xero credentials save.
if ( isset( $_POST['remember_settings_action'] ) && 'save_xero_credentials' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	$settings = Remember_Xero_OAuth::get_settings() ?: array();
	$settings['client_id'] = isset( $_POST['xero_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['xero_client_id'] ) ) : '';

	if ( ! empty( $_POST['xero_client_secret'] ) ) {
		$settings['client_secret'] = sanitize_text_field( wp_unslash( $_POST['xero_client_secret'] ) );
	}

	if ( Remember_Xero_OAuth::save_settings( $settings ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Xero credentials saved successfully.', 'remember' ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to save Xero credentials.', 'remember' ) . '</p></div>';
	}
}

// Handle QuickBooks item mapping save (roles + catalog products; must run outside nested forms).
if ( isset( $_POST['remember_settings_action'] ) && 'save_qb_item_mappings' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-remember-qb-item-mapping.php';
	require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-api.php';
	$mapping_model = new Remember_QB_Item_Mapping();
	$qb_items      = Remember_QuickBooks_API::query_items();
	$qb_names_by_id = array();
	if ( ! is_wp_error( $qb_items ) ) {
		foreach ( $qb_items as $item ) {
			if ( ! empty( $item['Id'] ) ) {
				$qb_names_by_id[ (string) $item['Id'] ] = isset( $item['Name'] ) ? (string) $item['Name'] : '';
			}
		}
	}
	if ( isset( $_POST['role_qb_mappings'] ) && is_array( $_POST['role_qb_mappings'] ) ) {
		foreach ( $_POST['role_qb_mappings'] as $role_id => $qb_id ) {
			$role_id = absint( $role_id );
			$qb_id   = isset( $qb_id ) ? sanitize_text_field( wp_unslash( $qb_id ) ) : '';
			$qb_name = ( '' !== $qb_id && isset( $qb_names_by_id[ $qb_id ] ) ) ? $qb_names_by_id[ $qb_id ] : null;
			if ( $role_id > 0 ) {
				$mapping_model->upsert( 'role', $role_id, $qb_id, $qb_name );
			}
		}
	}
	if ( isset( $_POST['product_qb_mappings'] ) && is_array( $_POST['product_qb_mappings'] ) ) {
		foreach ( $_POST['product_qb_mappings'] as $product_id => $qb_id ) {
			$product_id = absint( $product_id );
			$qb_id      = isset( $qb_id ) ? sanitize_text_field( wp_unslash( $qb_id ) ) : '';
			$qb_name    = ( '' !== $qb_id && isset( $qb_names_by_id[ $qb_id ] ) ) ? $qb_names_by_id[ $qb_id ] : null;
			if ( $product_id > 0 ) {
				$mapping_model->upsert( 'product', $product_id, $qb_id, $qb_name );
			}
		}
	}
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'QuickBooks mappings saved successfully.', 'remember' ) . '</p></div>';
}

// Handle Xero item mapping save (roles + catalog products; must run outside nested forms).
if ( isset( $_POST['remember_settings_action'] ) && 'save_xero_item_mappings' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-remember-xero-item-mapping.php';
	require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-api.php';
	$mapping_model   = new Remember_Xero_Item_Mapping();
	$xero_items      = Remember_Xero_API::get_items();
	$xero_names_by_id = array();
	if ( ! is_wp_error( $xero_items ) ) {
		foreach ( $xero_items as $item ) {
			if ( ! empty( $item['ItemID'] ) ) {
				$xero_names_by_id[ (string) $item['ItemID'] ] = isset( $item['Name'] ) ? (string) $item['Name'] : '';
			}
		}
	}
	if ( isset( $_POST['role_xero_mappings'] ) && is_array( $_POST['role_xero_mappings'] ) ) {
		foreach ( $_POST['role_xero_mappings'] as $role_id => $xero_id ) {
			$role_id = absint( $role_id );
			$xero_id = isset( $xero_id ) ? sanitize_text_field( wp_unslash( $xero_id ) ) : '';
			$xero_name = ( '' !== $xero_id && isset( $xero_names_by_id[ $xero_id ] ) ) ? $xero_names_by_id[ $xero_id ] : null;
			if ( $role_id > 0 ) {
				$mapping_model->upsert( 'role', $role_id, $xero_id, $xero_name );
			}
		}
	}
	if ( isset( $_POST['product_xero_mappings'] ) && is_array( $_POST['product_xero_mappings'] ) ) {
		foreach ( $_POST['product_xero_mappings'] as $product_id => $xero_id ) {
			$product_id = absint( $product_id );
			$xero_id    = isset( $xero_id ) ? sanitize_text_field( wp_unslash( $xero_id ) ) : '';
			$xero_name  = ( '' !== $xero_id && isset( $xero_names_by_id[ $xero_id ] ) ) ? $xero_names_by_id[ $xero_id ] : null;
			if ( $product_id > 0 ) {
				$mapping_model->upsert( 'product', $product_id, $xero_id, $xero_name );
			}
		}
	}
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Xero mappings saved successfully.', 'remember' ) . '</p></div>';
}

// Handle form submissions
if ( isset( $_POST['remember_settings_action'] ) && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
	$action = sanitize_text_field( $_POST['remember_settings_action'] );
	
	if ( 'update' === $action ) {
		$options = get_option( 'remember_options', array() );
		
		// Update photo settings
		if ( isset( $_POST['photo_max_size'] ) ) {
			$options['photo_max_size'] = absint( $_POST['photo_max_size'] ) * 1024 * 1024; // Convert MB to bytes
		}
		if ( isset( $_POST['photo_max_dimensions'] ) ) {
			$options['photo_max_dimensions'] = absint( $_POST['photo_max_dimensions'] );
		}
		
		// Update QuickBooks sync interval
		if ( isset( $_POST['qb_sync_interval'] ) ) {
			$options['qb_sync_interval'] = absint( $_POST['qb_sync_interval'] ) * 3600; // Convert hours to seconds
		}

		// Active accounting / billing provider (one at a time).
		if ( isset( $_POST['billing_provider'] ) ) {
			$provider = sanitize_text_field( wp_unslash( $_POST['billing_provider'] ) );
			if ( in_array( $provider, array( 'none', 'quickbooks', 'xero' ), true ) ) {
				$options['billing_provider'] = $provider;
			}
		}

		// Email provider invoice to customer when an application is accepted (default on).
		$options['email_invoice_on_accept'] = ! empty( $_POST['email_invoice_on_accept'] ) ? 1 : 0;

		// Allocate unallocated Xero credit notes onto a new invoice before it is emailed.
		$options['xero_auto_apply_credit'] = ! empty( $_POST['xero_auto_apply_credit'] ) ? 1 : 0;

		// Update subtotal disclaimer message.
		if ( isset( $_POST['subtotal_disclaimer_text'] ) ) {
			$options['subtotal_disclaimer_text'] = sanitize_textarea_field( wp_unslash( $_POST['subtotal_disclaimer_text'] ) );
		}

		// Ticket logo override (attachment ID); 0 clears override.
		if ( isset( $_POST['ticket_logo_id'] ) ) {
			$options['ticket_logo_id'] = absint( $_POST['ticket_logo_id'] );
		}
		
		// Update vetting workflow
		if ( isset( $_POST['vetting_workflow'] ) ) {
			$options['vetting_workflow'] = sanitize_text_field( $_POST['vetting_workflow'] );
		}

		if ( isset( $_POST['vetting_assignment'] ) ) {
			$assignment = sanitize_key( wp_unslash( $_POST['vetting_assignment'] ) );
			$options['vetting_assignment'] = in_array( $assignment, array( 'rotate', 'least_busy' ), true ) ? $assignment : 'team';
		}

		if ( isset( $_POST['vetting_notify_targets'] ) ) {
			$targets = sanitize_text_field( wp_unslash( $_POST['vetting_notify_targets'] ) );
			$options['vetting_notify_targets'] = ( 'assigned' === $targets ) ? 'assigned' : 'team';
		}

		$registration_guard_error = '';
		if ( isset( $_POST['registration_captcha_provider'] ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-registration-guard.php';
			$registration_guard_error = Remember_Registration_Guard::apply_settings( $options );
		}
		
		// Update log level
		if ( isset( $_POST['log_level'] ) ) {
			$log_level = sanitize_text_field( $_POST['log_level'] );
			$valid_levels = array( 'NONE', 'ERROR', 'WARNING', 'INFO', 'DEBUG' );
			if ( in_array( $log_level, $valid_levels, true ) ) {
				$options['log_level'] = $log_level;
			}
		}
		
		update_option( 'remember_options', $options );
		Remember_Logger::info( 'Settings updated' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'remember' ) . '</p></div>';
		if ( ! empty( $registration_guard_error ) ) {
			$registration_guard_messages = array(
				'site_key' => __( 'Registration CAPTCHA was not changed. Enter a site key for the selected provider.', 'remember' ),
				'secret'   => __( 'Registration CAPTCHA was not changed. Enter the secret key.', 'remember' ),
				'seal'     => __( 'Registration CAPTCHA was not changed. The secret could not be stored.', 'remember' ),
			);
			$registration_guard_message = isset( $registration_guard_messages[ $registration_guard_error ] ) ? $registration_guard_messages[ $registration_guard_error ] : __( 'Registration CAPTCHA was not changed.', 'remember' );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $registration_guard_message ) . '</p></div>';
		}
	}
	
	// Handle notification settings update
	if ( 'update_notifications' === $action ) {
		if ( isset( $_POST['notification_settings'] ) && is_array( $_POST['notification_settings'] ) ) {
			foreach ( $_POST['notification_settings'] as $notification_type => $settings ) {
				$data = array(
					'is_enabled' => isset( $settings['enabled'] ) ? 1 : 0,
					'subject_template' => isset( $settings['subject'] ) ? sanitize_text_field( wp_unslash( $settings['subject'] ) ) : '',
					'body_template'    => isset( $settings['body'] ) ? wp_kses_post( wp_unslash( $settings['body'] ) ) : '',
				);
				$notification_model->update_by_type( $notification_type, $data );
			}
			Remember_Logger::info( 'Notification settings updated' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Notification settings saved successfully.', 'remember' ) . '</p></div>';
			if ( isset( $_POST['notification_category'] ) ) {
				$remember_open_notification_category = sanitize_key( wp_unslash( $_POST['notification_category'] ) );
			}
		}
	}
	
	// Handle social media platform management
	if ( 'add_social_platform' === $action ) {
		global $wpdb;
		$platform_name = isset( $_POST['new_platform_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_platform_name'] ) ) : '';
		if ( ! empty( $platform_name ) ) {
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT platform_id FROM {$wpdb->prefix}remember_social_media_platforms WHERE platform_name = %s",
				$platform_name
			) );
			if ( ! $existing ) {
				$wpdb->insert(
					$wpdb->prefix . 'remember_social_media_platforms',
					array(
						'platform_name' => $platform_name,
						'is_active'     => 1,
						'sort_order'    => 999,
						'created_at'    => current_time( 'mysql' ),
					)
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Social media platform added successfully.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Platform already exists.', 'remember' ) . '</p></div>';
			}
		}
	}
	
	if ( 'update_social_platforms' === $action ) {
		global $wpdb;
		if ( isset( $_POST['platforms'] ) && is_array( $_POST['platforms'] ) ) {
			$updated_count = 0;
			$error_count = 0;
			$skipped_count = 0;
			
			// First, verify all platform IDs exist
			$all_platform_ids = array_keys( $_POST['platforms'] );
			$all_platform_ids = array_map( 'absint', $all_platform_ids );
			$all_platform_ids = array_filter( $all_platform_ids );
			
			if ( ! empty( $all_platform_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $all_platform_ids ), '%d' ) );
				$existing_platforms = $wpdb->get_col( $wpdb->prepare(
					"SELECT platform_id FROM {$wpdb->prefix}remember_social_media_platforms WHERE platform_id IN ($placeholders)",
					$all_platform_ids
				) );
				$existing_platform_ids = array_map( 'absint', $existing_platforms );
			} else {
				$existing_platform_ids = array();
			}
			
			foreach ( $_POST['platforms'] as $platform_id => $platform_data ) {
				$platform_id = absint( $platform_id );
				if ( $platform_id <= 0 ) {
					$skipped_count++;
					continue; // Skip invalid platform IDs
				}
				
				// Verify platform exists
				if ( ! in_array( $platform_id, $existing_platform_ids, true ) ) {
					$skipped_count++;
					Remember_Logger::warning( 'Attempted to update non-existent social media platform', array( 'platform_id' => $platform_id ) );
					continue;
				}
				
				// Validate and sanitize data
				$platform_name = isset( $platform_data['name'] ) ? sanitize_text_field( wp_unslash( $platform_data['name'] ) ) : '';
				if ( empty( $platform_name ) ) {
					$error_count++;
					Remember_Logger::warning( 'Skipped update for platform with empty name', array( 'platform_id' => $platform_id ) );
					continue; // Skip if name is empty
				}
				
				$is_active = isset( $platform_data['is_active'] ) ? 1 : 0;
				$sort_order = isset( $platform_data['sort_order'] ) && $platform_data['sort_order'] !== '' 
					? absint( $platform_data['sort_order'] ) 
					: 0;
				
				$result = $wpdb->update(
					$wpdb->prefix . 'remember_social_media_platforms',
					array(
						'platform_name' => $platform_name,
						'is_active'     => $is_active,
						'sort_order'    => $sort_order,
					),
					array( 'platform_id' => $platform_id ),
					array( '%s', '%d', '%d' ),
					array( '%d' )
				);
				
				if ( false === $result ) {
					$error_count++;
					Remember_Logger::error( 'Failed to update social media platform', array(
						'platform_id' => $platform_id,
						'platform_name' => $platform_name,
						'sort_order' => $sort_order,
						'db_error' => $wpdb->last_error,
						'db_query' => $wpdb->last_query,
					) );
				} elseif ( $result >= 0 ) {
					// $result can be 0 if no rows changed, or > 0 if rows were updated
					$updated_count++;
				}
			}
			
			$message_parts = array();
			if ( $updated_count > 0 ) {
				$message_parts[] = sprintf( esc_html__( '%d platform(s) updated', 'remember' ), $updated_count );
			}
			if ( $error_count > 0 ) {
				$message_parts[] = sprintf( esc_html__( '%d update(s) failed', 'remember' ), $error_count );
			}
			if ( $skipped_count > 0 ) {
				$message_parts[] = sprintf( esc_html__( '%d platform(s) skipped', 'remember' ), $skipped_count );
			}
			
			if ( $error_count > 0 || $skipped_count > 0 ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . 
					implode( ', ', $message_parts ) . 
					'. ' . esc_html__( 'Please check the error log for details.', 'remember' ) . 
					'</p></div>';
			} elseif ( $updated_count > 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . 
					esc_html__( 'Social media platforms updated successfully.', 'remember' ) . 
					'</p></div>';
			}
		}
	}
	
	if ( 'delete_social_platform' === $action && isset( $_POST['platform_id'] ) ) {
		global $wpdb;
		$platform_id = absint( $_POST['platform_id'] );
		$wpdb->delete(
			$wpdb->prefix . 'remember_social_media_platforms',
			array( 'platform_id' => $platform_id ),
			array( '%d' )
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Social media platform deleted successfully.', 'remember' ) . '</p></div>';
	}

	if ( 'add_im_platform' === $action ) {
		global $wpdb;
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
		$platform_name = isset( $_POST['new_im_platform_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_im_platform_name'] ) ) : '';
		$platform_key  = isset( $_POST['new_im_platform_key'] ) ? sanitize_key( wp_unslash( $_POST['new_im_platform_key'] ) ) : '';
		if ( '' === $platform_key && '' !== $platform_name ) {
			$platform_key = sanitize_key( $platform_name );
		}
		if ( '' !== $platform_name && '' !== $platform_key ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT platform_id FROM {$wpdb->prefix}remember_im_platforms WHERE platform_key = %s",
					$platform_key
				)
			);
			if ( ! $existing ) {
				$wpdb->insert(
					$wpdb->prefix . 'remember_im_platforms',
					array(
						'platform_key'  => $platform_key,
						'platform_name' => $platform_name,
						'is_active'     => 1,
						'sort_order'    => 999,
						'created_at'    => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%d', '%d', '%s' )
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instant messenger platform added successfully.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That platform key already exists.', 'remember' ) . '</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Platform name and key are required.', 'remember' ) . '</p></div>';
		}
	}

	if ( 'update_im_platforms' === $action ) {
		global $wpdb;
		if ( isset( $_POST['im_platforms'] ) && is_array( $_POST['im_platforms'] ) ) {
			$updated = 0;
			foreach ( $_POST['im_platforms'] as $platform_id => $row ) {
				$platform_id = absint( $platform_id );
				if ( $platform_id <= 0 || ! is_array( $row ) ) {
					continue;
				}
				$name = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';
				$sort = isset( $row['sort_order'] ) ? absint( $row['sort_order'] ) : 0;
				$active = ! empty( $row['is_active'] ) ? 1 : 0;
				if ( '' === $name ) {
					continue;
				}
				$ok = $wpdb->update(
					$wpdb->prefix . 'remember_im_platforms',
					array(
						'platform_name' => $name,
						'sort_order'    => $sort,
						'is_active'     => $active,
					),
					array( 'platform_id' => $platform_id ),
					array( '%s', '%d', '%d' ),
					array( '%d' )
				);
				if ( false !== $ok ) {
					++$updated;
				}
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instant messenger platforms updated successfully.', 'remember' ) . '</p></div>';
		}
	}

	if ( 'update_clothing_sizes' === $action ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
		$posted       = isset( $_POST['clothing_sizes'] ) && is_array( $_POST['clothing_sizes'] ) ? wp_unslash( $_POST['clothing_sizes'] ) : array();
		$stock_posted = isset( $_POST['clothing_stock'] ) && is_array( $_POST['clothing_stock'] ) ? wp_unslash( $_POST['clothing_stock'] ) : array();
		$result       = Remember_Clothing_Sizes::save_catalog( $posted, $stock_posted );
		$remember_open_clothing_tab = true;
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Clothing sizes updated successfully.', 'remember' ) . '</p></div>';
		}
	}

	if ( 'add_clothing_stock' === $action ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
		$remember_open_clothing_tab = true;
		$stock_cat  = isset( $_POST['size_category'] ) ? sanitize_key( wp_unslash( $_POST['size_category'] ) ) : '';
		$stock_code = isset( $_POST['stock_size_code'] ) ? wp_unslash( $_POST['stock_size_code'] ) : '';
		$result     = Remember_Clothing_Sizes::add_stock( $stock_cat, $stock_code );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Stock size added.', 'remember' ) . '</p></div>';
		}
	}

	if ( 'delete_clothing_stock' === $action && isset( $_POST['stock_id'] ) ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
		$remember_open_clothing_tab = true;
		$result = Remember_Clothing_Sizes::delete_stock( absint( $_POST['stock_id'] ) );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Stock size deleted.', 'remember' ) . '</p></div>';
		}
	}

	if ( 'delete_im_platform' === $action && isset( $_POST['platform_id'] ) ) {
		global $wpdb;
		$platform_id = absint( $_POST['platform_id'] );
		$wpdb->delete(
			$wpdb->prefix . 'remember_im_platforms',
			array( 'platform_id' => $platform_id ),
			array( '%d' )
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instant messenger platform deleted successfully.', 'remember' ) . '</p></div>';
	}
}

// Get all notification settings
$all_notifications = $notification_model->get_all();
$notifications_by_category = array();
foreach ( $all_notifications as $notification ) {
	$category = Remember_Notification_Setting::get_type_category( $notification->notification_type );
	if ( ! isset( $notifications_by_category[ $category ] ) ) {
		$notifications_by_category[ $category ] = array();
	}
	$notifications_by_category[ $category ][] = $notification;
}

$options = get_option( 'remember_options', array() );
// Always show the installed files' version. remember_version in the DB can lag after
// Upload → Replace, because silent reactivation skips the activation hook that writes it.
$plugin_version = defined( 'REMEMBER_VERSION' ) ? REMEMBER_VERSION : get_option( 'remember_version', '0.0.0' );

// Get social media platforms
global $wpdb;
$social_platforms = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_social_media_platforms ORDER BY sort_order ASC, platform_name ASC"
);
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
$im_platforms = Remember_Im_Platforms::get_all();
?>

<div class="wrap remember-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form id="remember-main-settings" method="post" action="">
		<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
		<input type="hidden" name="remember_settings_action" value="update">
		
		<h2 class="nav-tab-wrapper">
			<a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'remember' ); ?></a>
			<a href="#shortcodes" class="nav-tab" id="shortcodes-tab-link"><?php esc_html_e( 'Shortcodes', 'remember' ); ?></a>
			<a href="#platforms" class="nav-tab"><?php esc_html_e( 'Platforms', 'remember' ); ?></a>
			<a href="#clothing" class="nav-tab"><?php esc_html_e( 'Clothing', 'remember' ); ?></a>
			<a href="#quickbooks" class="nav-tab"><?php esc_html_e( 'QuickBooks', 'remember' ); ?></a>
			<a href="#xero" class="nav-tab"><?php esc_html_e( 'Xero', 'remember' ); ?></a>
			<a href="#notifications" class="nav-tab"><?php esc_html_e( 'Notifications', 'remember' ); ?></a>
			<a href="#logging" class="nav-tab"><?php esc_html_e( 'Logging', 'remember' ); ?></a>
		</h2>

		<!-- General Settings -->
		<div id="general" class="remember-settings-tab" style="display: block;">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="photo_max_size"><?php esc_html_e( 'Photo Max Size', 'remember' ); ?></label>
					</th>
					<td>
						<input type="number" id="photo_max_size" name="photo_max_size" value="<?php echo esc_attr( isset( $options['photo_max_size'] ) ? round( $options['photo_max_size'] / 1024 / 1024 ) : 2 ); ?>" min="1" max="10" class="small-text">
						<span class="description"><?php esc_html_e( 'MB (Maximum file size for profile photos)', 'remember' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="photo_max_dimensions"><?php esc_html_e( 'Photo Max Dimensions', 'remember' ); ?></label>
					</th>
					<td>
						<input type="number" id="photo_max_dimensions" name="photo_max_dimensions" value="<?php echo esc_attr( isset( $options['photo_max_dimensions'] ) ? $options['photo_max_dimensions'] : 800 ); ?>" min="100" max="2000" class="small-text">
						<span class="description"><?php esc_html_e( 'px (Maximum width/height for profile photos)', 'remember' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="billing_provider"><?php esc_html_e( 'Billing provider', 'remember' ); ?></label>
					</th>
					<td>
						<?php $billing_provider = isset( $options['billing_provider'] ) ? $options['billing_provider'] : 'none'; ?>
						<fieldset>
							<label style="display:block;margin-bottom:0.35em;">
								<input type="radio" name="billing_provider" value="none" <?php checked( $billing_provider, 'none' ); ?>>
								<?php esc_html_e( 'None (manual / cash tracking only)', 'remember' ); ?>
							</label>
							<label style="display:block;margin-bottom:0.35em;">
								<input type="radio" name="billing_provider" value="quickbooks" <?php checked( $billing_provider, 'quickbooks' ); ?>>
								<?php esc_html_e( 'QuickBooks Online', 'remember' ); ?>
							</label>
							<label style="display:block;margin-bottom:0.35em;">
								<input type="radio" name="billing_provider" value="xero" <?php checked( $billing_provider, 'xero' ); ?>>
								<?php esc_html_e( 'Xero', 'remember' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Only one accounting provider is active at a time. Switching does not delete historical invoice IDs on payment rows (QuickBooks and Xero columns can coexist). Finish open billing cycles before switching; new invoices use only the selected provider.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Email invoice on accept', 'remember' ); ?>
					</th>
					<td>
						<?php
						$email_invoice_on_accept = ! array_key_exists( 'email_invoice_on_accept', $options )
							? true
							: ! empty( $options['email_invoice_on_accept'] );
						?>
						<label for="email_invoice_on_accept">
							<input type="checkbox" id="email_invoice_on_accept" name="email_invoice_on_accept" value="1" <?php checked( $email_invoice_on_accept ); ?>>
							<?php esc_html_e( 'After accepting an application, ask Xero or QuickBooks to email the invoice to the customer', 'remember' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Uses the contact/customer email on file in the billing provider. Accept still succeeds if the email step fails. Ticket emails are separate.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Auto-apply open Xero credit', 'remember' ); ?>
					</th>
					<td>
						<label for="xero_auto_apply_credit">
							<input type="checkbox" id="xero_auto_apply_credit" name="xero_auto_apply_credit" value="1" <?php checked( ! empty( $options['xero_auto_apply_credit'] ) ); ?>>
							<?php esc_html_e( 'When Xero is the billing provider, apply the contact’s unallocated credit notes to each new invoice before it is emailed', 'remember' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Oldest authorised credit note first, up to the invoice total. Later invoices in the same run only receive credit that is still unallocated. If allocation fails, the invoice is left in Xero and is not emailed.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="vetting_workflow"><?php esc_html_e( 'Vetting Workflow', 'remember' ); ?></label>
					</th>
					<td>
						<select id="vetting_workflow" name="vetting_workflow" class="regular-text">
							<?php
							$vetting_workflow = isset( $options['vetting_workflow'] ) ? $options['vetting_workflow'] : 'on_join';
							if ( 'first_application' !== $vetting_workflow ) {
								$vetting_workflow = 'on_join';
							}
							?>
							<option value="on_join" <?php selected( $vetting_workflow, 'on_join' ); ?>>
								<?php esc_html_e( 'On Member Join (Default)', 'remember' ); ?>
							</option>
							<option value="first_application" <?php selected( $vetting_workflow, 'first_application' ); ?>>
								<?php esc_html_e( 'On First Event Application', 'remember' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'When should vetting be triggered? "On Member Join" creates a vetting case immediately when a member is created. "On First Event Application" lets members who are not vetted yet apply for events, and opens their vetting case with their first application. Staff can accept the application once vetting is complete.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="vetting_assignment"><?php esc_html_e( 'Automatic case assignment', 'remember' ); ?></label>
					</th>
					<td>
						<select id="vetting_assignment" name="vetting_assignment" class="regular-text">
							<?php
							require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-vetting-workflow.php';
							$vetting_assignment = Remember_Vetting_Workflow::get_assignment_mode();
							?>
							<option value="team" <?php selected( $vetting_assignment, 'team' ); ?>>
								<?php esc_html_e( 'Leave unassigned and email the vetting team', 'remember' ); ?>
							</option>
							<option value="rotate" <?php selected( $vetting_assignment, 'rotate' ); ?>>
								<?php esc_html_e( 'Rotate through the vetting team', 'remember' ); ?>
							</option>
							<option value="least_busy" <?php selected( $vetting_assignment, 'least_busy' ); ?>>
								<?php esc_html_e( 'Assign to the least busy vetter', 'remember' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Who gets a vetting case that opens automatically. The vetting team is everyone with the Vetting role. Unassigned cases email the whole team that a case is waiting, so anyone can assign a primary vetter. Rotation makes each new case go to the next team member in turn as primary vetter. Least busy gives the case to the team member who is primary vetter on the fewest cases that are not completed; a tie goes to whoever is next in the rotation. Assigned vetters get the Vetting Assigned email.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="vetting_notify_targets"><?php esc_html_e( 'Vetting email recipients', 'remember' ); ?></label>
					</th>
					<td>
						<select id="vetting_notify_targets" name="vetting_notify_targets" class="regular-text">
							<?php
							$vetting_notify_targets = isset( $options['vetting_notify_targets'] ) ? $options['vetting_notify_targets'] : 'team';
							if ( 'assigned' !== $vetting_notify_targets ) {
								$vetting_notify_targets = 'team';
							}
							?>
							<option value="team" <?php selected( $vetting_notify_targets, 'team' ); ?>>
								<?php esc_html_e( 'Entire vetting team', 'remember' ); ?>
							</option>
							<option value="assigned" <?php selected( $vetting_notify_targets, 'assigned' ); ?>>
								<?php esc_html_e( 'Assigned vetters only', 'remember' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Who is emailed when a vetter is assigned, a case is scheduled, a case is completed, or a collaborator is invited. The entire team is everyone with the Vetting role. Assigned vetters are the primary vetter and collaborators on that case. The member’s own accepted or rejected email is unchanged.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="registration_captcha_provider"><?php esc_html_e( 'Registration CAPTCHA', 'remember' ); ?></label>
					</th>
					<td>
						<?php
						require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-registration-guard.php';
						$registration_captcha_provider = Remember_Registration_Guard::provider();
						$registration_rate_limit       = Remember_Registration_Guard::rate_limit();
						?>
						<select id="registration_captcha_provider" name="registration_captcha_provider" class="regular-text">
							<option value="none" <?php selected( $registration_captcha_provider, 'none' ); ?>><?php esc_html_e( 'None', 'remember' ); ?></option>
							<option value="turnstile" <?php selected( $registration_captcha_provider, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'remember' ); ?></option>
							<option value="hcaptcha" <?php selected( $registration_captcha_provider, 'hcaptcha' ); ?>><?php esc_html_e( 'hCaptcha', 'remember' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Shown on the public registration form. None leaves the existing nonce and honeypot in place.', 'remember' ); ?></p>
						<p>
							<label for="registration_captcha_site_key"><?php esc_html_e( 'Site key', 'remember' ); ?></label><br>
							<input type="text" id="registration_captcha_site_key" name="registration_captcha_site_key" value="<?php echo esc_attr( Remember_Registration_Guard::site_key() ); ?>" class="regular-text" autocomplete="off">
						</p>
						<p>
							<label for="registration_captcha_secret"><?php esc_html_e( 'Secret key', 'remember' ); ?></label><br>
							<input type="password" id="registration_captcha_secret" name="registration_captcha_secret" value="" class="regular-text" autocomplete="new-password">
							<?php if ( Remember_Registration_Guard::has_secret() ) : ?>
								<span class="description"><?php esc_html_e( 'A secret is saved. Leave this blank to keep it.', 'remember' ); ?></span>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="registration_rate_limit"><?php esc_html_e( 'Registration attempts', 'remember' ); ?></label>
					</th>
					<td>
						<input type="number" id="registration_rate_limit" name="registration_rate_limit" value="<?php echo esc_attr( (string) $registration_rate_limit ); ?>" min="0" max="100" class="small-text">
						<p class="description"><?php esc_html_e( 'How many registration submissions one IP address may make in an hour. 0 turns this limit off. The default is 10.', 'remember' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="subtotal_disclaimer_text"><?php esc_html_e( 'Subtotal Disclaimer', 'remember' ); ?></label>
					</th>
					<td>
						<textarea id="subtotal_disclaimer_text" name="subtotal_disclaimer_text" rows="4" class="large-text"><?php echo esc_textarea( Remember_Billing_Messaging::get_subtotal_disclaimer() ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Shown on billing/application pricing touchpoints. Explains that reMember shows subtotal estimates and the active billing provider (QuickBooks or Xero) sends final totals/taxes/payment options. Leave the default wording to follow the selected provider automatically.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ticket_logo_id"><?php esc_html_e( 'Ticket logo override', 'remember' ); ?></label>
					</th>
					<td>
						<?php
						$ticket_logo_id  = isset( $options['ticket_logo_id'] ) ? absint( $options['ticket_logo_id'] ) : 0;
						$ticket_logo_url = $ticket_logo_id ? wp_get_attachment_image_url( $ticket_logo_id, 'medium' ) : '';
						?>
						<input type="hidden" id="ticket_logo_id" name="ticket_logo_id" value="<?php echo esc_attr( $ticket_logo_id ); ?>">
						<div id="remember-ticket-logo-preview" style="margin-bottom: 8px;">
							<?php if ( $ticket_logo_url ) : ?>
								<img src="<?php echo esc_url( $ticket_logo_url ); ?>" alt="" style="max-height: 72px; width: auto;">
							<?php endif; ?>
						</div>
						<button type="button" class="button" id="remember-ticket-logo-select"><?php esc_html_e( 'Select logo', 'remember' ); ?></button>
						<button type="button" class="button" id="remember-ticket-logo-clear" <?php disabled( ! $ticket_logo_id ); ?>><?php esc_html_e( 'Use site logo', 'remember' ); ?></button>
						<p class="description">
							<?php esc_html_e( 'Admission tickets use the WordPress Site Logo by default. Set an override here if tickets should show a different mark.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Plugin Version', 'remember' ); ?></label>
					</th>
					<td>
						<strong><?php echo esc_html( $plugin_version ); ?></strong>
					</td>
				</tr>
			</table>
		</div>

		<!-- Shortcodes Documentation -->
		<div id="shortcodes" class="remember-settings-tab" style="display: none;">
			<?php require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-page-creator.php'; ?>
			<?php $default_pages = Remember_Page_Creator::get_default_pages(); ?>
			<?php $created_pages = Remember_Page_Creator::get_created_pages(); ?>

			<h2><?php esc_html_e( 'Shortcode Documentation', 'remember' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'reMember provides shortcodes that you can add to any page or post. These shortcodes display member-facing content and forms.', 'remember' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'For Full Site Editing (FSE) themes, you can also use the block patterns available in the "reMember" category.', 'remember' ); ?>
			</p>

			<div style="margin-top: 30px;">
				<?php foreach ( $default_pages as $key => $page_data ) : 
					$is_created = isset( $created_pages[ $key ] );
					$page_id = $is_created ? $created_pages[ $key ] : 0;
				?>
					<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
						<h3 style="margin-top: 0; display: flex; align-items: center; justify-content: space-between;">
							<span><?php echo esc_html( $page_data['title'] ); ?></span>
							<?php if ( $is_created ) : ?>
								<span style="font-size: 0.875em; font-weight: normal; color: #46b450;">
									✓ <?php esc_html_e( 'Page created', 'remember' ); ?>
									<a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>" target="_blank" style="margin-left: 10px;">
										<?php esc_html_e( 'Edit', 'remember' ); ?>
									</a>
									<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" style="margin-left: 10px;">
										<?php esc_html_e( 'View', 'remember' ); ?>
									</a>
								</span>
							<?php endif; ?>
						</h3>
						
						<p style="color: #646970; margin: 10px 0;">
							<?php echo esc_html( $page_data['description'] ); ?>
						</p>

						<div style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 3px; padding: 15px; margin: 15px 0;">
							<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Shortcode:', 'remember' ); ?></strong>
							<code style="display: block; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px; font-size: 14px; word-break: break-all;">
								<?php echo esc_html( $page_data['shortcode'] ); ?>
							</code>
							<button type="button" class="button button-small remember-copy-shortcode" data-shortcode="<?php echo esc_attr( $page_data['shortcode'] ); ?>" style="margin-top: 8px;">
								<?php esc_html_e( 'Copy to Clipboard', 'remember' ); ?>
							</button>
						</div>

						<?php if ( 'remember_dashboard' === $page_data['shortcode'] ) : ?>
							<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
								<h4 style="margin-top: 0;"><?php esc_html_e( 'Features:', 'remember' ); ?></h4>
								<ul style="margin: 0; padding-left: 20px;">
									<li><?php esc_html_e( 'Member status and profile summary', 'remember' ); ?></li>
									<li><?php esc_html_e( 'List of accepted events with links to event directories', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Recent applications with status badges', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Quick links to browse events and edit profile', 'remember' ); ?></li>
								</ul>
							</div>
						<?php elseif ( 'remember_events' === $page_data['shortcode'] ) : ?>
							<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
								<h4 style="margin-top: 0;"><?php esc_html_e( 'Attributes:', 'remember' ); ?></h4>
								<ul style="margin: 0; padding-left: 20px;">
									<li><code>status="open"</code> - <?php esc_html_e( 'Show only open events (default)', 'remember' ); ?></li>
									<li><code>status="all"</code> - <?php esc_html_e( 'Show all events regardless of status', 'remember' ); ?></li>
									<li><code>status="upcoming"</code> - <?php esc_html_e( 'Show only upcoming events', 'remember' ); ?></li>
									<li><code>limit="5"</code> - <?php esc_html_e( 'Limit the number of events displayed', 'remember' ); ?></li>
								</ul>
								<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Example:', 'remember' ); ?></strong> <code>[remember_events status="upcoming" limit="10"]</code></p>
							</div>
						<?php elseif ( 'remember_apply' === $page_data['shortcode'] ) : ?>
							<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
								<h4 style="margin-top: 0;"><?php esc_html_e( 'Attributes:', 'remember' ); ?></h4>
								<ul style="margin: 0; padding-left: 20px;">
									<li><code>event_id="123"</code> - <?php esc_html_e( 'Pre-select a specific event (optional)', 'remember' ); ?></li>
								</ul>
								<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Example:', 'remember' ); ?></strong> <code>[remember_apply event_id="5"]</code></p>
								<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Note:', 'remember' ); ?></strong> <?php esc_html_e( 'If no event_id is provided, users can select an event from a dropdown. The form will automatically load available roles for the selected event.', 'remember' ); ?></p>
							</div>
						<?php elseif ( '[remember_register]' === $page_data['shortcode'] ) : ?>
							<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
								<h4 style="margin-top: 0;"><?php esc_html_e( 'Notes:', 'remember' ); ?></h4>
								<ul style="margin: 0; padding-left: 20px;">
									<li><?php esc_html_e( 'Creates a WordPress user and reMember member record; does not require “Anyone can register” in Settings → General.', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Disable the form site-wide with the remember_allow_public_registration filter.', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Reject an email domain with the remember_registration_disposable_email filter, or stop account creation with remember_registration_pre_create.', 'remember' ); ?></li>
								</ul>
							</div>
						<?php elseif ( 'remember_profile' === $page_data['shortcode'] ) : ?>
							<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
								<h4 style="margin-top: 0;"><?php esc_html_e( 'Features:', 'remember' ); ?></h4>
								<ul style="margin: 0; padding-left: 20px;">
									<li><?php esc_html_e( 'View and edit profile information', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Change their password without using wp-admin', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Manage privacy settings for event sharing', 'remember' ); ?></li>
									<li><?php esc_html_e( 'Supports ?edit=1 URL parameter to show edit mode', 'remember' ); ?></li>
								</ul>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<!-- Log out -->
				<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Log out', 'remember' ); ?></h3>
					<p style="color: #646970; margin: 10px 0;">
						<?php esc_html_e( 'A nonce-protected log out link for the front end. Hidden when the visitor is not logged in. After logout they land on the site home.', 'remember' ); ?>
					</p>
					<div style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 3px; padding: 15px; margin: 15px 0;">
						<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Nav menu (classic):', 'remember' ); ?></strong>
						<p style="margin: 0 0 10px 0;"><?php esc_html_e( 'Appearance → Menus → reMember → Log out → Add to Menu.', 'remember' ); ?></p>
						<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Nav menu (block theme / FSE):', 'remember' ); ?></strong>
						<p style="margin: 0 0 10px 0;"><?php esc_html_e( 'Site Editor → open the Navigation block → + → search “Log out” (reMember). That inserts a nonce-protected item. A Custom Link to /remember-logout also works.', 'remember' ); ?></p>
						<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Shortcode:', 'remember' ); ?></strong>
						<code style="display: block; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px; font-size: 14px; word-break: break-all;">
							[remember_logout]
						</code>
						<button type="button" class="button button-small remember-copy-shortcode" data-shortcode="[remember_logout]" style="margin-top: 8px;">
							<?php esc_html_e( 'Copy to Clipboard', 'remember' ); ?>
						</button>
						<p style="margin: 10px 0 0 0;"><?php esc_html_e( 'Optional: [remember_logout text="Sign out"]', 'remember' ); ?></p>
					</div>
					<p class="description" style="margin: 0;">
						<?php esc_html_e( 'The WordPress admin bar is hidden for accounts whose only WordPress role is Subscriber. Editor, Author, Administrator, and any other WP role still see it. reMember roles do not change this.', 'remember' ); ?>
					</p>
				</div>

				<!-- Event Detail Shortcode -->
				<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Event Detail', 'remember' ); ?></h3>
					<p style="color: #646970; margin: 10px 0;">
						<?php esc_html_e( 'Display detailed information about an event, including dates, location, status, and the attendee directory (for accepted members).', 'remember' ); ?>
					</p>

					<div style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 3px; padding: 15px; margin: 15px 0;">
						<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Shortcode:', 'remember' ); ?></strong>
						<code style="display: block; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px; font-size: 14px; word-break: break-all;">
							[remember_event_detail]
						</code>
						<button type="button" class="button button-small remember-copy-shortcode" data-shortcode="[remember_event_detail]" style="margin-top: 8px;">
							<?php esc_html_e( 'Copy to Clipboard', 'remember' ); ?>
						</button>
					</div>

					<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'Attributes:', 'remember' ); ?></h4>
						<ul style="margin: 0; padding-left: 20px;">
							<li><code>event_id="123"</code> - <?php esc_html_e( 'Optional: The ID of the event. If not provided, uses ?event=ID from URL.', 'remember' ); ?></li>
						</ul>
						<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Note:', 'remember' ); ?></strong> <?php esc_html_e( 'The attendee directory is only visible to members who are accepted to the event. Contact information is displayed only if members have enabled sharing in their privacy settings.', 'remember' ); ?></p>
					</div>
				</div>

				<!-- Event Directory Shortcode -->
				<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Event Member Directory', 'remember' ); ?></h3>
					<p style="color: #646970; margin: 10px 0;">
						<?php esc_html_e( 'Display a directory of members accepted to a specific event. Only shows contact information that members have chosen to share via privacy settings. This is typically embedded within the Event Detail page.', 'remember' ); ?>
					</p>

					<div style="background: #f6f7f7; border: 1px solid #ddd; border-radius: 3px; padding: 15px; margin: 15px 0;">
						<strong style="display: block; margin-bottom: 8px;"><?php esc_html_e( 'Shortcode:', 'remember' ); ?></strong>
						<code style="display: block; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 3px; font-size: 14px; word-break: break-all;">
							[remember_event_directory event_id="123"]
						</code>
						<button type="button" class="button button-small remember-copy-shortcode" data-shortcode="[remember_event_directory event_id=&quot;123&quot;]" style="margin-top: 8px;">
							<?php esc_html_e( 'Copy to Clipboard', 'remember' ); ?>
						</button>
					</div>

					<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'Attributes:', 'remember' ); ?></h4>
						<ul style="margin: 0; padding-left: 20px;">
							<li><code>event_id="123"</code> - <?php esc_html_e( 'Required: The ID of the event to show members for', 'remember' ); ?></li>
						</ul>
						<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Note:', 'remember' ); ?></strong> <?php esc_html_e( 'Only members who are accepted to the event can view the directory. Contact information is displayed only if members have enabled sharing for that type of information in their privacy settings.', 'remember' ); ?></p>
					</div>
				</div>

				<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin-top: 30px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Page Creation', 'remember' ); ?></h3>
					<p>
						<?php esc_html_e( 'You can automatically create pages with these shortcodes using the setup wizard.', 'remember' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-setup' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Open Setup Wizard', 'remember' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
	</form>

		<!-- Social + Instant Messenger Platforms -->
		<div id="platforms" class="remember-settings-tab" style="display: none;">
			<h2><?php esc_html_e( 'Platforms', 'remember' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Social media and instant messenger options members can use on registration and their profile.', 'remember' ); ?></p>

			<div class="remember-platforms-columns">
				<div class="remember-platforms-column">
					<h3><?php esc_html_e( 'Social Media', 'remember' ); ?></h3>

					<form method="post" action="" class="remember-platforms-card">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="add_social_platform">
						<h4><?php esc_html_e( 'Add New Platform', 'remember' ); ?></h4>
						<p>
							<label for="new_platform_name" class="screen-reader-text"><?php esc_html_e( 'Platform Name', 'remember' ); ?></label>
							<input type="text" id="new_platform_name" name="new_platform_name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Mastodon', 'remember' ); ?>">
						</p>
						<p class="submit" style="margin-top:0;">
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Platform', 'remember' ); ?>">
						</p>
					</form>

					<form method="post" action="" class="remember-platforms-card">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="update_social_platforms">
						<h4><?php esc_html_e( 'Manage Platforms', 'remember' ); ?></h4>
						<?php if ( ! empty( $social_platforms ) ) : ?>
							<table class="wp-list-table widefat striped remember-platforms-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Platform Name', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Sort', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Active', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $social_platforms as $platform ) : ?>
										<tr>
											<td>
												<input type="text" name="platforms[<?php echo esc_attr( $platform->platform_id ); ?>][name]"
													value="<?php echo esc_attr( $platform->platform_name ); ?>" class="regular-text" required>
											</td>
											<td>
												<input type="number" name="platforms[<?php echo esc_attr( $platform->platform_id ); ?>][sort_order]"
													value="<?php echo esc_attr( $platform->sort_order ); ?>" class="small-text" min="0">
											</td>
											<td>
												<label>
													<input type="checkbox" name="platforms[<?php echo esc_attr( $platform->platform_id ); ?>][is_active]"
														value="1" <?php checked( $platform->is_active, 1 ); ?>>
													<?php esc_html_e( 'Active', 'remember' ); ?>
												</label>
											</td>
											<td>
												<button type="button" class="button button-small remember-delete-platform"
													data-platform-id="<?php echo esc_attr( $platform->platform_id ); ?>"
													data-platform-name="<?php echo esc_attr( $platform->platform_name ); ?>">
													<?php esc_html_e( 'Delete', 'remember' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="submit">
								<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'remember' ); ?>">
							</p>
						<?php else : ?>
							<p><?php esc_html_e( 'No social media platforms configured.', 'remember' ); ?></p>
						<?php endif; ?>
					</form>
				</div>

				<div class="remember-platforms-column">
					<h3><?php esc_html_e( 'Instant Messenger', 'remember' ); ?></h3>

					<form method="post" action="" class="remember-platforms-card">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="add_im_platform">
						<h4><?php esc_html_e( 'Add New Platform', 'remember' ); ?></h4>
						<p>
							<label for="new_im_platform_name" class="screen-reader-text"><?php esc_html_e( 'Platform Name', 'remember' ); ?></label>
							<input type="text" id="new_im_platform_name" name="new_im_platform_name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Matrix', 'remember' ); ?>">
						</p>
						<p class="submit" style="margin-top:0;">
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Platform', 'remember' ); ?>">
						</p>
					</form>

					<form method="post" action="" class="remember-platforms-card">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="update_im_platforms">
						<h4><?php esc_html_e( 'Manage Platforms', 'remember' ); ?></h4>
						<?php if ( ! empty( $im_platforms ) ) : ?>
							<table class="wp-list-table widefat striped remember-platforms-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Platform Name', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Sort', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Active', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $im_platforms as $platform ) : ?>
										<tr>
											<td>
												<input type="text" name="im_platforms[<?php echo esc_attr( $platform->platform_id ); ?>][name]"
													value="<?php echo esc_attr( $platform->platform_name ); ?>" class="regular-text" required>
											</td>
											<td>
												<input type="number" name="im_platforms[<?php echo esc_attr( $platform->platform_id ); ?>][sort_order]"
													value="<?php echo esc_attr( $platform->sort_order ); ?>" class="small-text" min="0">
											</td>
											<td>
												<label>
													<input type="checkbox" name="im_platforms[<?php echo esc_attr( $platform->platform_id ); ?>][is_active]"
														value="1" <?php checked( $platform->is_active, 1 ); ?>>
													<?php esc_html_e( 'Active', 'remember' ); ?>
												</label>
											</td>
											<td>
												<button type="button" class="button button-small remember-delete-im-platform"
													data-platform-id="<?php echo esc_attr( $platform->platform_id ); ?>"
													data-platform-name="<?php echo esc_attr( $platform->platform_name ); ?>">
													<?php esc_html_e( 'Delete', 'remember' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="submit">
								<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'remember' ); ?>">
							</p>
						<?php else : ?>
							<p><?php esc_html_e( 'No instant messenger platforms configured yet.', 'remember' ); ?></p>
						<?php endif; ?>
					</form>
				</div>
			</div>
		</div>

		<!-- Clothing sizes vs issued inventory -->
		<div id="clothing" class="remember-settings-tab" style="display: none;">
			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
			$clothing_help = Remember_Clothing_Sizes::section_help();
			?>
			<h2><?php esc_html_e( 'Clothing Sizes', 'remember' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Write the sizes you actually have in Stock. Map each member body size to one of those. Quantity tracking is a later table related to Stock.', 'remember' ); ?>
			</p>
			<p class="description"><?php echo esc_html( $clothing_help ); ?></p>

			<?php if ( ! Remember_Clothing_Sizes::has_mapping_columns() || ! Remember_Clothing_Sizes::has_stock_table() ) : ?>
				<p><?php esc_html_e( 'Reload this page after the database update finishes so the stock table is available.', 'remember' ); ?></p>
			<?php else : ?>
				<h3><?php esc_html_e( 'Add stock size', 'remember' ); ?></h3>
				<form method="post" action="" style="max-width: 480px; margin-bottom: 24px;">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="add_clothing_stock">
					<p>
						<label for="clothing_stock_category"><?php esc_html_e( 'Category', 'remember' ); ?></label><br>
						<select id="clothing_stock_category" name="size_category">
							<?php foreach ( Remember_Clothing_Sizes::categories() as $stock_cat ) : ?>
								<option value="<?php echo esc_attr( $stock_cat ); ?>"><?php echo esc_html( Remember_Clothing_Sizes::category_label( $stock_cat ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="clothing_stock_code"><?php esc_html_e( 'Size', 'remember' ); ?></label><br>
						<input type="text" id="clothing_stock_code" name="stock_size_code" class="regular-text" maxlength="20" required placeholder="<?php esc_attr_e( 'e.g. L, XL, 10.5', 'remember' ); ?>">
					</p>
					<p class="submit" style="margin-top:0;">
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Stock Size', 'remember' ); ?>">
					</p>
				</form>

				<form method="post" action="">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="update_clothing_sizes">
					<?php foreach ( Remember_Clothing_Sizes::categories() as $clothing_cat ) : ?>
						<?php
						$clothing_rows    = Remember_Clothing_Sizes::rows_for( $clothing_cat );
						$clothing_stocked = Remember_Clothing_Sizes::stocked_codes( $clothing_cat );
						$clothing_stock   = Remember_Clothing_Sizes::stock_rows( $clothing_cat );
						?>
						<h3><?php echo esc_html( Remember_Clothing_Sizes::category_label( $clothing_cat ) ); ?></h3>
						<h4><?php esc_html_e( 'Stock', 'remember' ); ?></h4>
						<p class="description"><?php esc_html_e( 'Sizes you can issue. These populate Available as.', 'remember' ); ?></p>
						<?php if ( empty( $clothing_stock ) ) : ?>
							<p><?php esc_html_e( 'No stock sizes yet. Add them above, then map member sizes.', 'remember' ); ?></p>
						<?php else : ?>
							<table class="wp-list-table widefat striped" style="max-width: 480px; margin-bottom: 12px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Size', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Sort', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $clothing_stock as $s_row ) : ?>
										<?php $s_id = (int) $s_row->stock_id; ?>
										<tr>
											<td><strong><?php echo esc_html( (string) $s_row->size_code ); ?></strong></td>
											<td>
												<input type="number" name="clothing_stock[<?php echo esc_attr( $s_id ); ?>][sort_order]" value="<?php echo esc_attr( (int) $s_row->sort_order ); ?>" class="small-text" min="0">
											</td>
											<td>
												<button type="button" class="button button-small remember-delete-clothing-stock"
													data-stock-id="<?php echo esc_attr( $s_id ); ?>"
													data-stock-code="<?php echo esc_attr( (string) $s_row->size_code ); ?>">
													<?php esc_html_e( 'Delete', 'remember' ); ?>
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>

						<h4><?php esc_html_e( 'Member sizes', 'remember' ); ?></h4>
						<?php if ( empty( $clothing_rows ) ) : ?>
							<p><?php esc_html_e( 'No sizes in this catalog yet.', 'remember' ); ?></p>
						<?php else : ?>
							<table class="wp-list-table widefat striped" style="max-width: 640px; margin-bottom: 24px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Size', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Active', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Available as', 'remember' ); ?></th>
										<th><?php esc_html_e( 'Sort', 'remember' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $clothing_rows as $c_row ) : ?>
										<?php
										$c_id     = (int) $c_row->option_id;
										$c_code   = (string) $c_row->size_code;
										$c_issued = ! empty( $c_row->issued_size_code ) ? (string) $c_row->issued_size_code : '';
										$c_base   = 'clothing_sizes[' . $clothing_cat . '][' . $c_id . ']';
										?>
										<tr>
											<td><strong><?php echo esc_html( $c_code ); ?></strong></td>
											<td>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( $c_base ); ?>[is_active]" value="1" <?php checked( ! empty( $c_row->is_active ) ); ?>>
													<?php esc_html_e( 'Show to members', 'remember' ); ?>
												</label>
											</td>
											<td>
												<select name="<?php echo esc_attr( $c_base ); ?>[issued_size_code]">
													<option value=""><?php esc_html_e( '— Select —', 'remember' ); ?></option>
													<?php foreach ( $clothing_stocked as $stock_code ) : ?>
														<option value="<?php echo esc_attr( $stock_code ); ?>" <?php selected( $c_issued, $stock_code ); ?>><?php echo esc_html( $stock_code ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td>
												<input type="number" name="<?php echo esc_attr( $c_base ); ?>[sort_order]" value="<?php echo esc_attr( (int) $c_row->sort_order ); ?>" class="small-text" min="0">
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					<?php endforeach; ?>
					<p class="submit">
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Clothing Sizes', 'remember' ); ?>">
					</p>
				</form>
			<?php endif; ?>
		</div>

		<!-- QuickBooks Settings -->
		<div id="quickbooks" class="remember-settings-tab" style="display: none;">
			<?php
			$qb_settings = Remember_QuickBooks_OAuth::get_settings();
			$is_connected = $qb_settings && ! empty( $qb_settings['access_token'] ) && ! empty( $qb_settings['realm_id'] );
			$company_info     = array();
			$qb_status_error  = null;
			$qb_needs_reauth  = false;
			if ( $is_connected ) {
				$company_info_result = Remember_QuickBooks_API::get_company_info();
				if ( is_wp_error( $company_info_result ) ) {
					$qb_status_error = $company_info_result;
					$qb_needs_reauth = Remember_QuickBooks_API::error_requires_reconnect( $company_info_result );
				} else {
					$company_info = $company_info_result;
				}
			}
			$qb_environment_label = ( isset( $qb_settings['environment'] ) && 'production' === $qb_settings['environment'] )
				? __( 'Production', 'remember' )
				: __( 'Sandbox', 'remember' );
			?>
			
			<h2><?php esc_html_e( 'QuickBooks Online Integration', 'remember' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Connect your QuickBooks Online account to automatically create invoices and sync payment status.', 'remember' ); ?>
			</p>
			
			<!-- Connection Status -->
			<?php if ( $is_connected && $qb_status_error ) : ?>
				<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
					<p style="margin: 0; color: #664d03;">
						<strong>
							<?php
							if ( $qb_needs_reauth ) {
								esc_html_e( 'Connection expired', 'remember' );
							} else {
								esc_html_e( 'QuickBooks could not be reached', 'remember' );
							}
							?>
						</strong>
					</p>
					<p style="margin: 8px 0 0;">
						<?php echo esc_html( wp_strip_all_tags( $qb_status_error->get_error_message() ) ); ?>
						<?php
						if ( $qb_needs_reauth ) {
							esc_html_e( ' Use Reconnect QuickBooks below and choose the same company. You do not need to disconnect first.', 'remember' );
						} else {
							esc_html_e( ' The saved connection was kept. Reload this page to try again; reconnect only if this keeps happening.', 'remember' );
						}
						?>
					</p>
					<p style="margin: 5px 0 0; font-size: 13px;">
						<?php esc_html_e( 'Company ID:', 'remember' ); ?>
						<code><?php echo esc_html( $qb_settings['realm_id'] ); ?></code>
						&middot; <?php echo esc_html( $qb_environment_label ); ?>
					</p>
				</div>
			<?php elseif ( $is_connected ) : ?>
				<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 20px;">
					<p style="margin: 0; color: #155724;">
						<strong><?php esc_html_e( 'Connected', 'remember' ); ?></strong>
						<?php if ( ! empty( $company_info['CompanyName'] ) ) : ?>
							&mdash; <?php echo esc_html( $company_info['CompanyName'] ); ?>
						<?php endif; ?>
					</p>
					<p style="margin: 5px 0 0; font-size: 13px;">
						<?php esc_html_e( 'Company ID:', 'remember' ); ?>
						<code><?php echo esc_html( $qb_settings['realm_id'] ); ?></code>
						&middot; <?php echo esc_html( $qb_environment_label ); ?>
					</p>
				</div>
			<?php else : ?>
				<div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
					<p style="margin: 0; color: #721c24;">
						<strong><?php esc_html_e( 'Not Connected', 'remember' ); ?></strong>
					</p>
				</div>
			<?php endif; ?>
			
			<!-- Credentials Form -->
			<form method="post" action="" style="margin-bottom: 30px;">
				<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
				<input type="hidden" name="remember_settings_action" value="save_qb_credentials">
				
				<h3><?php esc_html_e( 'QuickBooks Credentials', 'remember' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Enter your QuickBooks App credentials from the Intuit Developer Portal.', 'remember' ); ?>
					<a href="https://developer.intuit.com/app/developer/qbo/docs/get-started" target="_blank"><?php esc_html_e( 'Get started with QuickBooks API', 'remember' ); ?></a>
				</p>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="remember_qb_redirect_uri"><?php esc_html_e( 'Redirect URI', 'remember' ); ?></label>
						</th>
						<td>
							<input type="text" id="remember_qb_redirect_uri" class="large-text" readonly
								value="<?php echo esc_attr( Remember_QuickBooks_OAuth::get_redirect_uri() ); ?>"
								onclick="this.select();" />
							<p class="description">
								<?php esc_html_e( 'Add this exact URL as a Redirect URI in your Intuit Developer app (Keys & credentials). It must match for sandbox and production.', 'remember' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qb_environment"><?php esc_html_e( 'Environment', 'remember' ); ?></label>
						</th>
						<td>
							<select id="qb_environment" name="qb_environment" class="regular-text">
								<option value="sandbox" <?php selected( isset( $qb_settings['environment'] ) ? $qb_settings['environment'] : 'sandbox', 'sandbox' ); ?>>
									<?php esc_html_e( 'Sandbox (Testing)', 'remember' ); ?>
								</option>
								<option value="production" <?php selected( isset( $qb_settings['environment'] ) ? $qb_settings['environment'] : 'sandbox', 'production' ); ?>>
									<?php esc_html_e( 'Production', 'remember' ); ?>
								</option>
							</select>
							<p class="description"><?php esc_html_e( 'Use Sandbox for testing, Production for live accounts.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qb_client_id"><?php esc_html_e( 'Client ID (OAuth 2.0 Client ID)', 'remember' ); ?></label>
						</th>
						<td>
							<input type="text" id="qb_client_id" name="qb_client_id" class="regular-text" 
							       value="<?php echo esc_attr( isset( $qb_settings['client_id'] ) ? $qb_settings['client_id'] : '' ); ?>" 
							       placeholder="<?php esc_attr_e( 'Enter your Client ID', 'remember' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="qb_client_secret"><?php esc_html_e( 'Client Secret (OAuth 2.0 Client Secret)', 'remember' ); ?></label>
						</th>
						<td>
							<input type="password" id="qb_client_secret" name="qb_client_secret" class="regular-text" 
							       value="" 
							       placeholder="<?php echo esc_attr( isset( $qb_settings['client_secret'] ) ? __( 'Enter new Client Secret to update', 'remember' ) : __( 'Enter your Client Secret', 'remember' ) ); ?>">
							<p class="description">
								<?php if ( isset( $qb_settings['client_secret'] ) ) : ?>
									<?php esc_html_e( 'Client Secret is already saved. Enter a new value to update it.', 'remember' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Enter your Client Secret from the Intuit Developer Portal.', 'remember' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button( __( 'Save Credentials', 'remember' ) ); ?>
			</form>
			
			<!-- Connect/Disconnect -->
			<?php if ( ! empty( $qb_settings['client_id'] ) && ! empty( $qb_settings['client_secret'] ) ) : ?>
				<div style="margin-bottom: 30px;">
					<form method="post" action="" class="remember-qb-connect-form" style="display: inline-block; margin: 0 8px 8px 0;">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="start_qb_oauth" />
						<input type="hidden" name="qb_environment_oauth" id="remember_qb_oauth_environment_field" value="<?php echo esc_attr( isset( $qb_settings['environment'] ) ? $qb_settings['environment'] : 'sandbox' ); ?>" />
						<?php
						submit_button(
							$is_connected ? __( 'Reconnect QuickBooks', 'remember' ) : __( 'Connect to QuickBooks', 'remember' ),
							$qb_needs_reauth || ! $is_connected ? 'primary large' : 'secondary',
							'submit',
							false
						);
						?>
					</form>
					<?php if ( $is_connected ) : ?>
						<form method="post" action="" style="display: inline-block; margin: 0 0 8px;">
							<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
							<input type="hidden" name="remember_settings_action" value="disconnect_qb">
							<?php submit_button( __( 'Disconnect QuickBooks', 'remember' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<p class="description" style="margin-top: 8px;">
						<?php
						if ( $is_connected ) {
							esc_html_e( 'Reconnect signs in to QuickBooks again and replaces the stored tokens; choose the same company, or the reconnect is refused. Disconnect clears the connection and saved credentials entirely.', 'remember' );
						} else {
							esc_html_e( 'Uses the Environment selected above (Sandbox or Production). Authorize the matching company type in Intuit—sandbox tokens only work with the sandbox API host.', 'remember' );
						}
						?>
					</p>
					<script>
					(function() {
						var sel = document.getElementById( 'qb_environment' );
						var mirror = document.getElementById( 'remember_qb_oauth_environment_field' );
						if ( sel && mirror ) {
							mirror.value = sel.value;
							sel.addEventListener( 'change', function() { mirror.value = sel.value; } );
						}
					})();
					</script>
				</div>
			<?php endif; ?>
			
			<!-- Sync Settings -->
			<form method="post" action="">
				<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
				<input type="hidden" name="remember_settings_action" value="update">
				
				<h3><?php esc_html_e( 'Sync Settings', 'remember' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="qb_sync_interval"><?php esc_html_e( 'Sync Interval', 'remember' ); ?></label>
						</th>
						<td>
							<input type="number" id="qb_sync_interval" name="qb_sync_interval" value="<?php echo esc_attr( isset( $options['qb_sync_interval'] ) ? round( $options['qb_sync_interval'] / 3600 ) : 1 ); ?>" min="1" max="24" class="small-text">
							<span class="description"><?php esc_html_e( 'hours (How often to sync payment status from QuickBooks)', 'remember' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Last Sync', 'remember' ); ?></label>
						</th>
						<td>
							<?php
							global $wpdb;
							$last_sync = $wpdb->get_var( "SELECT last_sync_at FROM {$wpdb->prefix}remember_payment_processors WHERE processor_type = 'quickbooks' AND is_active = 1 LIMIT 1" );
							if ( $last_sync ) {
								echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sync ) ) );
							} else {
								echo '<span class="description">' . esc_html__( 'Never', 'remember' ) . '</span>';
							}
							?>
						</td>
					</tr>
				</table>
				
				<?php submit_button( __( 'Save Sync Settings', 'remember' ) ); ?>
			</form>
			
			<!-- Manual Sync -->
			<?php if ( $is_connected ) : ?>
				<form method="post" action="" style="margin-top: 20px;">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="sync_qb_payments">
					<h3><?php esc_html_e( 'Manual Sync', 'remember' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Reconcile every payment that has a QuickBooks invoice ID: update amounts/status, and clear the link if the invoice no longer exists in QuickBooks.', 'remember' ); ?>
					</p>
					<?php submit_button( __( 'Sync Payments Now', 'remember' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
			
			<!-- QuickBooks item mapping (event roles + add-on catalog) -->
			<?php if ( $is_connected ) :
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-product.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-remember-qb-item-mapping.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-api.php';

				if ( isset( $_POST['remember_settings_action'] ) && 'sync_qb_products' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
					$qb_items = Remember_QuickBooks_API::query_items();
					if ( ! is_wp_error( $qb_items ) ) {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'QuickBooks products/services refreshed for mapping dropdowns.', 'remember' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to refresh QuickBooks products/services: ', 'remember' ) . esc_html( $qb_items->get_error_message() ) . '</p></div>';
					}
				}

				$product_model   = new Remember_Product();
				$role_model      = new Remember_Role();
				$mapping_model   = new Remember_QB_Item_Mapping();
				$event_roles     = $role_model->get_event_roles();
				$catalog_products = $product_model->get_active();
				$qb_products     = array();
				$qb_items_result = Remember_QuickBooks_API::query_items();
				if ( ! is_wp_error( $qb_items_result ) ) {
					$qb_products = $qb_items_result;
				}
				?>
				<form id="remember-qb-mapping-form" method="post" action="" class="screen-reader-text" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="save_qb_item_mappings" />
				</form>
				<div class="remember-qb-mapping" style="margin-top: 30px;">
					<h3><?php esc_html_e( 'QuickBooks line mapping', 'remember' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Map each event role and each catalog add-on to a QuickBooks Product/Service. Role mapping uses role_id and applies to all events. Add-ons are defined under Products.', 'remember' ); ?>
					</p>
					<p>
						<button type="button" class="button button-secondary" onclick="document.getElementById('sync-qb-products-form').submit();">
							<?php esc_html_e( 'Refresh QuickBooks item list', 'remember' ); ?>
						</button>
					</p>

					<h4 style="margin-top: 18px;"><?php esc_html_e( 'Event roles', 'remember' ); ?></h4>
					<?php if ( ! empty( $event_roles ) ) : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top: 8px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Role', 'remember' ); ?></th>
									<th><?php esc_html_e( 'QuickBooks item', 'remember' ); ?></th>
									<th style="width: 110px;"><?php esc_html_e( 'QB item ID', 'remember' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $event_roles as $er ) : ?>
									<?php
									$m = $mapping_model->get_by_entity( 'role', $er->role_id );
									$sel = $m && ! empty( $m->quickbooks_product_id ) ? $m->quickbooks_product_id : '';
									?>
									<tr>
										<td><strong><?php echo esc_html( $er->role_name ); ?></strong></td>
										<td>
											<select name="role_qb_mappings[<?php echo esc_attr( $er->role_id ); ?>]" class="regular-text" form="remember-qb-mapping-form">
												<option value=""><?php esc_html_e( '-- Not Mapped --', 'remember' ); ?></option>
												<?php foreach ( $qb_products as $qb_product ) : ?>
													<option value="<?php echo esc_attr( $qb_product['Id'] ); ?>" <?php selected( $sel, $qb_product['Id'] ); ?>>
														<?php echo esc_html( ( $qb_product['Name'] ?? '' ) . ' (' . ( $qb_product['Type'] ?? 'Service' ) . ')' ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><?php echo $sel ? '<code>' . esc_html( $sel ) . '</code>' : '<span class="description">—</span>'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No event roles found. Create roles under Roles.', 'remember' ); ?></p>
					<?php endif; ?>

					<h4 style="margin-top: 22px;"><?php esc_html_e( 'Product catalog (add-ons)', 'remember' ); ?></h4>
					<p class="description">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Products admin URL */
								__( 'Manage catalog items on <a href="%s">Products</a>.', 'remember' ),
								esc_url( admin_url( 'admin.php?page=remember-products' ) )
							)
						);
						?>
					</p>
					<?php if ( ! empty( $catalog_products ) ) : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top: 8px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'remember' ); ?></th>
									<th><?php esc_html_e( 'QuickBooks item', 'remember' ); ?></th>
									<th style="width: 110px;"><?php esc_html_e( 'QB item ID', 'remember' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $catalog_products as $product ) : ?>
									<?php
									$m = $mapping_model->get_by_entity( 'product', $product->product_id );
									$sel = $m && ! empty( $m->quickbooks_product_id ) ? $m->quickbooks_product_id : '';
									?>
									<tr>
										<td><strong><?php echo esc_html( $product->product_name ); ?></strong></td>
										<td>
											<select name="product_qb_mappings[<?php echo esc_attr( $product->product_id ); ?>]" class="regular-text" form="remember-qb-mapping-form">
												<option value=""><?php esc_html_e( '-- Not Mapped --', 'remember' ); ?></option>
												<?php foreach ( $qb_products as $qb_product ) : ?>
													<option value="<?php echo esc_attr( $qb_product['Id'] ); ?>" <?php selected( $sel, $qb_product['Id'] ); ?>>
														<?php echo esc_html( ( $qb_product['Name'] ?? '' ) . ' (' . ( $qb_product['Type'] ?? 'Service' ) . ')' ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><?php echo $sel ? '<code>' . esc_html( $sel ) . '</code>' : '<span class="description">—</span>'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No active catalog products. Add add-ons under Products.', 'remember' ); ?></p>
					<?php endif; ?>

					<p class="submit" style="margin-bottom: 1.5em;">
						<?php submit_button( __( 'Save QuickBooks mappings', 'remember' ), 'primary', 'submit', false, array( 'form' => 'remember-qb-mapping-form' ) ); ?>
					</p>
				</div>

				<form id="sync-qb-products-form" method="post" action="" style="display: none;">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="sync_qb_products">
				</form>
			<?php endif; ?>
		</div>

		<!-- Xero Settings -->
		<div id="xero" class="remember-settings-tab" style="display: none;">
			<?php
			$xero_settings  = Remember_Xero_OAuth::get_settings();
			$xero_connected = Remember_Xero_OAuth::is_connected( $xero_settings );
			$xero_org       = array();
			$xero_org_error = '';
			if ( $xero_connected ) {
				$xero_org_result = Remember_Xero_API::get_organisation();
				if ( ! is_wp_error( $xero_org_result ) && is_array( $xero_org_result ) ) {
					$xero_org = $xero_org_result;
					Remember_Xero_OAuth::clear_last_oauth_result();
				} elseif ( is_wp_error( $xero_org_result ) ) {
					$xero_org_error = $xero_org_result->get_error_message();
				}
			}
			$billing_provider_now = isset( $options['billing_provider'] ) ? $options['billing_provider'] : 'none';
			?>

			<h2><?php esc_html_e( 'Xero Integration', 'remember' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Connect Xero to create invoices and sync payment status (mirrors the QuickBooks workflow). Choose Xero as the Billing provider under General when you are ready to use it.', 'remember' ); ?>
			</p>

			<?php if ( 'xero' !== $billing_provider_now ) : ?>
				<div class="notice notice-warning inline" style="margin: 0 0 1em;">
					<p>
						<?php
						echo esc_html__( 'Xero is not the active billing provider.', 'remember' );
						echo ' ';
						esc_html_e( 'You can still save credentials and connect for testing; invoice automation will follow the General → Billing provider setting.', 'remember' );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			$xero_last_oauth = Remember_Xero_OAuth::get_last_oauth_result();
			if ( $xero_org_error && ! empty( $xero_last_oauth['message'] ) && isset( $xero_last_oauth['type'] ) && 'error' === $xero_last_oauth['type'] ) :
				?>
				<div class="notice notice-error inline" style="margin: 0 0 20px;">
					<p>
						<strong><?php esc_html_e( 'Reconnect did not finish:', 'remember' ); ?></strong>
						<?php echo esc_html( $xero_last_oauth['message'] ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $xero_connected && $xero_org_error ) : ?>
				<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
					<p style="margin: 0; color: #664d03;">
						<strong><?php esc_html_e( 'Connection expired', 'remember' ); ?></strong>
						<?php
						$display_name = '';
						if ( ! empty( $xero_settings['tenant_name'] ) ) {
							$display_name = $xero_settings['tenant_name'];
						}
						if ( $display_name ) {
							echo ' — ' . esc_html( $display_name );
						}
						?>
					</p>
					<p style="margin: 8px 0 0;">
						<?php echo esc_html( $xero_org_error ); ?>
						<?php esc_html_e( ' Use Reconnect Xero below to authorize again. You do not need to disconnect first.', 'remember' ); ?>
					</p>
				</div>
			<?php elseif ( $xero_connected ) : ?>
				<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 20px;">
					<p style="margin: 0; color: #155724;">
						<strong><?php esc_html_e( 'Connected', 'remember' ); ?></strong>
						<?php
						$display_name = '';
						if ( ! empty( $xero_org['Name'] ) ) {
							$display_name = $xero_org['Name'];
						} elseif ( ! empty( $xero_settings['tenant_name'] ) ) {
							$display_name = $xero_settings['tenant_name'];
						}
						if ( $display_name ) {
							echo ' — ' . esc_html( $display_name );
						}
						?>
					</p>
					<?php if ( ! empty( $xero_settings['tenant_id'] ) ) : ?>
						<p style="margin: 5px 0 0; font-size: 13px;">
							<?php esc_html_e( 'Tenant ID:', 'remember' ); ?>
							<code><?php echo esc_html( $xero_settings['tenant_id'] ); ?></code>
						</p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
					<p style="margin: 0; color: #721c24;">
						<strong><?php esc_html_e( 'Not Connected', 'remember' ); ?></strong>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="" style="margin-bottom: 30px;">
				<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
				<input type="hidden" name="remember_settings_action" value="save_xero_credentials">

				<h3><?php esc_html_e( 'Xero Credentials', 'remember' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Enter your Xero app credentials from the Xero Developer portal.', 'remember' ); ?>
					<a href="https://developer.xero.com/app/manage" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'My Apps', 'remember' ); ?></a>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="remember_xero_redirect_uri"><?php esc_html_e( 'Redirect URI', 'remember' ); ?></label>
						</th>
						<td>
							<input type="text" id="remember_xero_redirect_uri" class="large-text" readonly
								value="<?php echo esc_attr( Remember_Xero_OAuth::get_redirect_uri() ); ?>"
								onclick="this.select();" />
							<p class="description">
								<?php esc_html_e( 'Add this exact URL as an OAuth 2.0 redirect URI in your Xero app Configuration. It must match for Connect to succeed.', 'remember' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="xero_client_id"><?php esc_html_e( 'Client ID', 'remember' ); ?></label>
						</th>
						<td>
							<input type="text" id="xero_client_id" name="xero_client_id" class="regular-text"
								value="<?php echo esc_attr( isset( $xero_settings['client_id'] ) ? $xero_settings['client_id'] : '' ); ?>"
								placeholder="<?php esc_attr_e( 'Enter your Client ID', 'remember' ); ?>" autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="xero_client_secret"><?php esc_html_e( 'Client Secret', 'remember' ); ?></label>
						</th>
						<td>
							<input type="password" id="xero_client_secret" name="xero_client_secret" class="regular-text" value=""
								placeholder="<?php echo esc_attr( ! empty( $xero_settings['client_secret'] ) ? __( 'Enter new Client Secret to update', 'remember' ) : __( 'Enter your Client Secret', 'remember' ) ); ?>"
								autocomplete="new-password">
							<p class="description">
								<?php if ( ! empty( $xero_settings['client_secret'] ) ) : ?>
									<?php esc_html_e( 'Client Secret is already saved. Enter a new value to update it.', 'remember' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Enter your Client Secret from the Xero Developer portal.', 'remember' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Credentials', 'remember' ) ); ?>
			</form>

			<?php if ( ! empty( $xero_settings['client_id'] ) && ! empty( $xero_settings['client_secret'] ) ) : ?>
				<div style="margin-bottom: 30px;">
					<form method="post" action="" style="display: inline-block; margin: 0 8px 8px 0;">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="start_xero_oauth" />
						<?php
						submit_button(
							$xero_connected ? __( 'Reconnect Xero', 'remember' ) : __( 'Connect to Xero', 'remember' ),
							$xero_org_error || ! $xero_connected ? 'primary large' : 'secondary',
							'submit',
							false
						);
						?>
					</form>
					<?php if ( $xero_connected ) : ?>
						<form method="post" action="" style="display: inline-block; margin: 0 0 8px;">
							<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
							<input type="hidden" name="remember_settings_action" value="disconnect_xero">
							<?php submit_button( __( 'Disconnect Xero', 'remember' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<p class="description" style="margin-top: 8px;">
						<?php
						if ( $xero_connected ) {
							esc_html_e( 'Reconnect authorizes Xero again and replaces the stored tokens. Disconnect clears the connection entirely.', 'remember' );
						} else {
							esc_html_e( 'You will be asked to authorize one or more Xero organisations. reMember stores the first organisation returned for this connection.', 'remember' );
						}
						?>
					</p>
				</div>

				<?php if ( $xero_connected ) : ?>
					<form method="post" action="" style="margin-bottom: 30px;">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="update">
						<h3><?php esc_html_e( 'Sync Settings', 'remember' ); ?></h3>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="qb_sync_interval_xero"><?php esc_html_e( 'Sync Interval', 'remember' ); ?></label>
								</th>
								<td>
									<input type="number" id="qb_sync_interval_xero" name="qb_sync_interval" value="<?php echo esc_attr( isset( $options['qb_sync_interval'] ) ? round( $options['qb_sync_interval'] / 3600 ) : 1 ); ?>" min="1" max="24" class="small-text">
									<span class="description"><?php esc_html_e( 'hours (How often to sync payment status from Xero)', 'remember' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label><?php esc_html_e( 'Last Sync', 'remember' ); ?></label>
								</th>
								<td>
									<?php
									global $wpdb;
									$xero_last_sync = $wpdb->get_var( "SELECT last_sync_at FROM {$wpdb->prefix}remember_payment_processors WHERE processor_type = 'xero' AND is_active = 1 LIMIT 1" );
									if ( $xero_last_sync ) {
										echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $xero_last_sync ) ) );
									} else {
										echo '<span class="description">' . esc_html__( 'Never', 'remember' ) . '</span>';
									}
									?>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Sync Settings', 'remember' ) ); ?>
					</form>

					<form method="post" action="" style="margin-bottom: 30px;">
						<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
						<input type="hidden" name="remember_settings_action" value="sync_xero_payments">
						<h3><?php esc_html_e( 'Manual Sync', 'remember' ); ?></h3>
						<p class="description">
							<?php esc_html_e( 'Reconcile every payment that has a Xero invoice ID: update amounts/status, and clear the link if the invoice no longer exists in Xero.', 'remember' ); ?>
						</p>
						<?php submit_button( __( 'Sync Payments Now', 'remember' ), 'secondary' ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $xero_connected ) :
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-product.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-remember-xero-item-mapping.php';

				if ( isset( $_POST['remember_settings_action'] ) && 'sync_xero_items' === $_POST['remember_settings_action'] && check_admin_referer( 'remember_settings_action', 'remember_settings_nonce' ) ) {
					$xero_items_refresh = Remember_Xero_API::get_items();
					if ( ! is_wp_error( $xero_items_refresh ) ) {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Xero items refreshed for mapping dropdowns.', 'remember' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to refresh Xero items: ', 'remember' ) . esc_html( $xero_items_refresh->get_error_message() ) . '</p></div>';
					}
				}

				$xero_product_model    = new Remember_Product();
				$xero_role_model       = new Remember_Role();
				$xero_mapping_model    = new Remember_Xero_Item_Mapping();
				$xero_event_roles      = $xero_role_model->get_event_roles();
				$xero_catalog_products = $xero_product_model->get_active();
				$xero_products         = array();
				$xero_items_result     = Remember_Xero_API::get_items();
				if ( ! is_wp_error( $xero_items_result ) ) {
					$xero_products = $xero_items_result;
				}
				?>
				<form id="remember-xero-mapping-form" method="post" action="" class="screen-reader-text" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="save_xero_item_mappings" />
				</form>
				<div class="remember-xero-mapping" style="margin-top: 30px;">
					<h3><?php esc_html_e( 'Xero line mapping', 'remember' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Map each event role and each catalog add-on to a Xero Item. Role mapping uses role_id and applies to all events. Add-ons are defined under Products.', 'remember' ); ?>
					</p>
					<p>
						<button type="button" class="button button-secondary" onclick="document.getElementById('sync-xero-items-form').submit();">
							<?php esc_html_e( 'Refresh Xero item list', 'remember' ); ?>
						</button>
					</p>

					<h4 style="margin-top: 18px;"><?php esc_html_e( 'Event roles', 'remember' ); ?></h4>
					<?php if ( ! empty( $xero_event_roles ) ) : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top: 8px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Role', 'remember' ); ?></th>
									<th><?php esc_html_e( 'Xero item', 'remember' ); ?></th>
									<th style="width: 110px;"><?php esc_html_e( 'Xero item ID', 'remember' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $xero_event_roles as $er ) : ?>
									<?php
									$xm  = $xero_mapping_model->get_by_entity( 'role', $er->role_id );
									$sel = $xm && ! empty( $xm->xero_item_id ) ? $xm->xero_item_id : '';
									?>
									<tr>
										<td><strong><?php echo esc_html( $er->role_name ); ?></strong></td>
										<td>
											<select name="role_xero_mappings[<?php echo esc_attr( $er->role_id ); ?>]" class="regular-text" form="remember-xero-mapping-form">
												<option value=""><?php esc_html_e( '-- Not Mapped --', 'remember' ); ?></option>
												<?php foreach ( $xero_products as $xero_product ) : ?>
													<option value="<?php echo esc_attr( $xero_product['ItemID'] ); ?>" <?php selected( $sel, $xero_product['ItemID'] ); ?>>
														<?php
														$label = isset( $xero_product['Name'] ) ? (string) $xero_product['Name'] : '';
														if ( ! empty( $xero_product['Code'] ) ) {
															$label .= ' [' . $xero_product['Code'] . ']';
														}
														echo esc_html( $label );
														?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><?php echo $sel ? '<code>' . esc_html( $sel ) . '</code>' : '<span class="description">—</span>'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No event roles found. Create roles under Roles.', 'remember' ); ?></p>
					<?php endif; ?>

					<h4 style="margin-top: 22px;"><?php esc_html_e( 'Product catalog (add-ons)', 'remember' ); ?></h4>
					<p class="description">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Products admin URL */
								__( 'Manage catalog items on <a href="%s">Products</a>.', 'remember' ),
								esc_url( admin_url( 'admin.php?page=remember-products' ) )
							)
						);
						?>
					</p>
					<?php if ( ! empty( $xero_catalog_products ) ) : ?>
						<table class="wp-list-table widefat fixed striped" style="margin-top: 8px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'remember' ); ?></th>
									<th><?php esc_html_e( 'Xero item', 'remember' ); ?></th>
									<th style="width: 110px;"><?php esc_html_e( 'Xero item ID', 'remember' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $xero_catalog_products as $product ) : ?>
									<?php
									$xm  = $xero_mapping_model->get_by_entity( 'product', $product->product_id );
									$sel = $xm && ! empty( $xm->xero_item_id ) ? $xm->xero_item_id : '';
									?>
									<tr>
										<td><strong><?php echo esc_html( $product->product_name ); ?></strong></td>
										<td>
											<select name="product_xero_mappings[<?php echo esc_attr( $product->product_id ); ?>]" class="regular-text" form="remember-xero-mapping-form">
												<option value=""><?php esc_html_e( '-- Not Mapped --', 'remember' ); ?></option>
												<?php foreach ( $xero_products as $xero_product ) : ?>
													<option value="<?php echo esc_attr( $xero_product['ItemID'] ); ?>" <?php selected( $sel, $xero_product['ItemID'] ); ?>>
														<?php
														$label = isset( $xero_product['Name'] ) ? (string) $xero_product['Name'] : '';
														if ( ! empty( $xero_product['Code'] ) ) {
															$label .= ' [' . $xero_product['Code'] . ']';
														}
														echo esc_html( $label );
														?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><?php echo $sel ? '<code>' . esc_html( $sel ) . '</code>' : '<span class="description">—</span>'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No active catalog products. Add add-ons under Products.', 'remember' ); ?></p>
					<?php endif; ?>

					<p class="submit" style="margin-bottom: 1.5em;">
						<?php submit_button( __( 'Save Xero mappings', 'remember' ), 'primary', 'submit', false, array( 'form' => 'remember-xero-mapping-form' ) ); ?>
					</p>
				</div>

				<form id="sync-xero-items-form" method="post" action="" style="display: none;">
					<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
					<input type="hidden" name="remember_settings_action" value="sync_xero_items">
				</form>
			<?php endif; ?>

		</div>

		<!-- Notification Settings -->
		<div id="notifications" class="remember-settings-tab" style="display: none;">
			<form method="post" action="" id="remember-notification-settings-form">
				<?php wp_nonce_field( 'remember_settings_action', 'remember_settings_nonce' ); ?>
				<input type="hidden" name="remember_settings_action" value="update_notifications">
				<input type="hidden" name="notification_category" id="remember-notification-category" value="vetting">

				<p class="description" style="margin-bottom: 12px;">
					<?php esc_html_e( 'Configure email notifications and templates. Enable or disable specific notification types and customize the subject and rich-text body. Links in {ticket_url}, {profile_url}, {review_url}, and {vetting_url} become clickable in the sent message.', 'remember' ); ?>
				</p>

				<?php
				$category_labels = array(
					'vetting'      => __( 'Vetting', 'remember' ),
					'applications' => __( 'Applications', 'remember' ),
					'billing'      => __( 'Billing', 'remember' ),
					'general'      => __( 'General', 'remember' ),
				);
				$visible_categories = array();
				foreach ( $category_labels as $category => $label ) {
					if ( isset( $notifications_by_category[ $category ] ) ) {
						$visible_categories[ $category ] = $label;
					}
				}
				?>

				<?php if ( ! empty( $visible_categories ) ) : ?>
					<nav class="nav-tab-wrapper remember-notification-subtabs" aria-label="<?php esc_attr_e( 'Notification groups', 'remember' ); ?>">
						<?php
						$sub_i = 0;
						foreach ( $visible_categories as $category => $label ) :
							++$sub_i;
							?>
							<a href="#notifications-<?php echo esc_attr( $category ); ?>"
								class="nav-tab remember-notification-subtab-link<?php echo 1 === $sub_i ? ' nav-tab-active' : ''; ?>"
								data-category="<?php echo esc_attr( $category ); ?>">
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>

				<?php
				$panel_i = 0;
				foreach ( $visible_categories as $category => $label ) :
					++$panel_i;
					?>
					<div id="notification-panel-<?php echo esc_attr( $category ); ?>"
						class="remember-notification-panel"
						<?php echo 1 === $panel_i ? '' : ' style="display: none;"'; ?>>
						<?php
						foreach ( $notifications_by_category[ $category ] as $notification ) :
							$type_id = 'notification_' . esc_attr( $notification->notification_type );
							$subject_template = ! empty( $notification->subject_template )
								? $notification->subject_template
								: Remember_Notification_Setting::get_default_subject( $notification->notification_type );
							$body_template = Remember_Notification_Setting::body_for_editor(
								isset( $notification->body_template ) ? $notification->body_template : '',
								$notification->notification_type
							);
							$editor_id = 'notif_body_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $notification->notification_type ) );
							?>
							<div class="remember-notification-card">
								<div style="display: flex; align-items: center; margin-bottom: 10px;">
									<label class="remember-toggle-switch" style="margin-right: 10px;">
										<input type="checkbox"
										       id="<?php echo esc_attr( $type_id ); ?>_enabled"
										       name="notification_settings[<?php echo esc_attr( $notification->notification_type ); ?>][enabled]"
										       value="1"
										       <?php checked( $notification->is_enabled, 1 ); ?>>
										<span class="remember-toggle-slider"></span>
									</label>
									<label for="<?php echo esc_attr( $type_id ); ?>_enabled" style="font-weight: 600; cursor: pointer; margin: 0;">
										<?php echo esc_html( Remember_Notification_Setting::get_type_label( $notification->notification_type ) ); ?>
									</label>
								</div>
								<p class="description" style="margin: 5px 0 15px 25px; font-size: 13px;">
									<?php echo esc_html( Remember_Notification_Setting::get_type_description( $notification->notification_type ) ); ?>
								</p>

								<div style="margin-left: 25px;">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row" style="width: 120px; padding: 10px 0;">
												<label for="<?php echo esc_attr( $type_id ); ?>_subject"><?php esc_html_e( 'Subject', 'remember' ); ?></label>
											</th>
											<td style="padding: 10px 0;">
												<input type="text"
												       id="<?php echo esc_attr( $type_id ); ?>_subject"
												       name="notification_settings[<?php echo esc_attr( $notification->notification_type ); ?>][subject]"
												       value="<?php echo esc_attr( $subject_template ); ?>"
												       class="regular-text"
												       placeholder="<?php esc_attr_e( 'Email subject line', 'remember' ); ?>">
												<p class="description" style="margin-top: 5px; font-size: 12px;">
													<?php esc_html_e( 'Available variables: {member_name}, {event_name}, {application_id}, {vetting_id}, {amount}, {date}', 'remember' ); ?>
												</p>
											</td>
										</tr>
										<tr>
											<th scope="row" style="width: 120px; padding: 10px 0; vertical-align: top;">
												<label for="<?php echo esc_attr( $editor_id ); ?>"><?php esc_html_e( 'Body', 'remember' ); ?></label>
											</th>
											<td style="padding: 10px 0;">
												<?php
												wp_editor(
													$body_template,
													$editor_id,
													array(
														'textarea_name' => 'notification_settings[' . esc_attr( $notification->notification_type ) . '][body]',
														'textarea_rows' => 8,
														'media_buttons' => false,
														'teeny'         => true,
														'quicktags'     => false,
													)
												);
												?>
												<p class="description" style="margin-top: 5px; font-size: 12px;">
													<?php esc_html_e( 'Available variables: {member_name}, {event_name}, {application_id}, {vetting_id}, {amount}, {date}, {ticket_url}, {profile_url}', 'remember' ); ?>
												</p>
												<?php if ( in_array( $notification->notification_type, array( 'vetting_assigned', 'vetting_scheduled', 'vetting_completed', 'vetting_collaborator_invited' ), true ) ) : ?>
													<p class="description" style="margin-top: 5px; font-size: 12px;">
														<?php esc_html_e( 'This email also accepts {vetter_name} (the primary vetter) and {vetting_url} (a link to the case).', 'remember' ); ?>
													</p>
												<?php endif; ?>
											</td>
										</tr>
									</table>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save Notification Settings', 'remember' ) ); ?>
			</form>
		</div>

		<!-- Logging Settings -->
		<div id="logging" class="remember-settings-tab" style="display: none;">
			<h3><?php esc_html_e( 'Logging Settings', 'remember' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Configure logging for the reMember plugin. Logs are stored in a web-blocked directory, not at the public /wp-content/debug.log URL.', 'remember' ); ?>
			</p>
			
			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
			$current_log_level      = isset( $options['log_level'] ) ? $options['log_level'] : 'ERROR';
			$log_file_path          = Remember_Logger::get_log_file_path();
			$log_file_exists        = file_exists( $log_file_path );
			$log_file_size          = $log_file_exists ? size_format( filesize( $log_file_path ) ) : '0 B';
			$wp_debug_log_enabled   = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
			$wp_debug_log_path      = $wp_debug_log_enabled ? Remember_Logger::get_wp_debug_log_path() : '';
			$public_debug_log       = WP_CONTENT_DIR . '/debug.log';
			$public_debug_log_exists = file_exists( $public_debug_log );
			?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="log_level"><?php esc_html_e( 'Log Level', 'remember' ); ?></label>
					</th>
					<td>
						<select id="log_level" name="log_level" form="remember-main-settings">
							<option value="NONE" <?php selected( $current_log_level, 'NONE' ); ?>><?php esc_html_e( 'None (disable logging)', 'remember' ); ?></option>
							<option value="ERROR" <?php selected( $current_log_level, 'ERROR' ); ?>><?php esc_html_e( 'Error only', 'remember' ); ?></option>
							<option value="WARNING" <?php selected( $current_log_level, 'WARNING' ); ?>><?php esc_html_e( 'Warning and above', 'remember' ); ?></option>
							<option value="INFO" <?php selected( $current_log_level, 'INFO' ); ?>><?php esc_html_e( 'Info and above', 'remember' ); ?></option>
							<option value="DEBUG" <?php selected( $current_log_level, 'DEBUG' ); ?>><?php esc_html_e( 'Debug (all messages)', 'remember' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select the minimum log level to record. Lower levels include higher levels (e.g., WARNING includes ERROR). Debug also records activation timing and sync diagnostics. reMember keeps its own log and does not need WP_DEBUG_LOG.', 'remember' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log File Information', 'remember' ); ?></th>
					<td>
						<p>
							<strong><?php esc_html_e( 'reMember Log File:', 'remember' ); ?></strong><br>
							<code><?php echo esc_html( $log_file_path ); ?></code>
						</p>
						<p>
							<strong><?php esc_html_e( 'Status:', 'remember' ); ?></strong><br>
							<?php if ( $log_file_exists ) : ?>
								<span style="color: green;"><?php esc_html_e( 'File exists', 'remember' ); ?></span> (<?php echo esc_html( $log_file_size ); ?>)
							<?php else : ?>
								<span style="color: orange;"><?php esc_html_e( 'File does not exist yet', 'remember' ); ?></span>
							<?php endif; ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'When the file reaches 5 MB it is renamed to remember.log.1, replacing the older copy, and a new file is started.', 'remember' ); ?>
						</p>
						<?php if ( $wp_debug_log_enabled ) : ?>
							<p>
								<strong><?php esc_html_e( 'WordPress Debug Log (PHP errors):', 'remember' ); ?></strong><br>
								<code><?php echo esc_html( $wp_debug_log_path ); ?></code>
							</p>
						<?php endif; ?>
						<?php if ( $public_debug_log_exists ) : ?>
							<p>
								<?php esc_html_e( 'A leftover wp-content/debug.log file is blocked from web access on Apache/LiteSpeed. You can delete that file; new logs do not go there.', 'remember' ); ?>
							</p>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'WP_DEBUG_LOG in wp-config.php only controls the WordPress debug log. On nginx, also deny HTTP access to debug.log if you keep a copy under wp-content.', 'remember' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<p class="submit remember-settings-main-save" style="margin-top: 1.75em; padding-top: 1em; border-top: 1px solid #c3c4c7;">
			<?php submit_button( __( 'Save Changes', 'remember' ), 'primary', 'submit', false, array( 'form' => 'remember-main-settings' ) ); ?>
		</p>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Handle delete platform button clicks
	$('.remember-delete-platform').on('click', function(e) {
		e.preventDefault();
		var $button = $(this);
		var platformId = $button.data('platform-id');
		var platformName = $button.data('platform-name');
		
		if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete the platform "%s"?', 'remember' ) ); ?>'.replace('%s', platformName))) {
			return;
		}
		
		// Create a form and submit it
		var $form = $('<form>', {
			method: 'post',
			action: ''
		});
		
		$form.append($('<input>', {
			type: 'hidden',
			name: 'remember_settings_action',
			value: 'delete_social_platform'
		}));
		
		$form.append($('<input>', {
			type: 'hidden',
			name: 'platform_id',
			value: platformId
		}));
		
		$form.append($('<input>', {
			type: 'hidden',
			name: 'remember_settings_nonce',
			value: '<?php echo esc_js( wp_create_nonce( 'remember_settings_action' ) ); ?>'
		}));
		
		$('body').append($form);
		$form.submit();
	});

	$('.remember-delete-im-platform').on('click', function(e) {
		e.preventDefault();
		var $button = $(this);
		var platformId = $button.data('platform-id');
		var platformName = $button.data('platform-name');

		if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete the IM platform "%s"? Existing member selections keep their stored key.', 'remember' ) ); ?>'.replace('%s', platformName))) {
			return;
		}

		var $form = $('<form>', { method: 'post', action: '' });
		$form.append($('<input>', { type: 'hidden', name: 'remember_settings_action', value: 'delete_im_platform' }));
		$form.append($('<input>', { type: 'hidden', name: 'platform_id', value: platformId }));
		$form.append($('<input>', { type: 'hidden', name: 'remember_settings_nonce', value: '<?php echo esc_js( wp_create_nonce( 'remember_settings_action' ) ); ?>' }));
		$('body').append($form);
		$form.submit();
	});

	$('.remember-delete-clothing-stock').on('click', function(e) {
		e.preventDefault();
		var $button = $(this);
		var stockId = $button.data('stock-id');
		var stockCode = $button.data('stock-code');

		if (!confirm('<?php echo esc_js( __( 'Delete stock size "%s"? Remap member sizes that use it first.', 'remember' ) ); ?>'.replace('%s', stockCode))) {
			return;
		}

		var $form = $('<form>', { method: 'post', action: '' });
		$form.append($('<input>', { type: 'hidden', name: 'remember_settings_action', value: 'delete_clothing_stock' }));
		$form.append($('<input>', { type: 'hidden', name: 'stock_id', value: stockId }));
		$form.append($('<input>', { type: 'hidden', name: 'remember_settings_nonce', value: '<?php echo esc_js( wp_create_nonce( 'remember_settings_action' ) ); ?>' }));
		$('body').append($form);
		$form.submit();
	});

	<?php if ( ! empty( $remember_open_clothing_tab ) ) : ?>
	$('.remember-settings #remember-main-settings > .nav-tab-wrapper a[href="#clothing"]').trigger('click');
	<?php endif; ?>
	<?php if ( ! empty( $remember_open_notification_category ) ) : ?>
	if (history.replaceState) {
		history.replaceState(null, null, '#notifications-<?php echo esc_js( $remember_open_notification_category ); ?>');
	}
	$('.remember-settings #remember-main-settings > .nav-tab-wrapper a[href="#notifications"]').trigger('click');
	<?php endif; ?>
});
</script>
