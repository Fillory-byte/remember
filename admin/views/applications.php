<?php
/**
 * Applications view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-payment.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-vetting-workflow.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-messaging.php';

Remember_Logger::debug( 'Applications page loaded' );

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-ticket.php';

$application_model = new Remember_Application();
$event_model = new Remember_Event();
$member_model = new Remember_Member();
$payment_model = new Remember_Payment();
$subtotal_disclaimer = Remember_Billing_Messaging::get_subtotal_disclaimer();

/**
 * Notify event administrators about waitlist/capacity events.
 *
 * @param int    $event_id Event ID.
 * @param string $subject  Email subject.
 * @param string $message  Email body.
 * @return void
 */
function remember_notify_event_admins( $event_id, $subject, $message ) {
	global $wpdb;
	$emails = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT u.user_email
			FROM {$wpdb->prefix}remember_event_applications a
			JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
			JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
			JOIN {$wpdb->users} u ON a.member_id = u.ID
			WHERE a.event_id = %d
			AND a.status = 'accepted'
			AND r.role_name = %s
			AND u.user_email IS NOT NULL
			AND u.user_email != ''",
			$event_id,
			'Event Administrator'
		)
	);

	if ( empty( $emails ) ) {
		return;
	}

	foreach ( $emails as $email ) {
		wp_mail( $email, $subject, $message );
	}
}

// Handle form submissions
if ( isset( $_POST['remember_application_action'] ) && check_admin_referer( 'remember_application_action', 'remember_application_nonce' ) ) {
	$action = sanitize_text_field( $_POST['remember_application_action'] );
	$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
	
	if ( 'add' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_create_applications' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$event_role_id = isset( $_POST['event_role_id'] ) ? absint( $_POST['event_role_id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'pending';
		
		if ( $event_id > 0 && $member_id > 0 && $event_role_id > 0 ) {
			// Check if application already exists
			$existing = $application_model->get_existing_application( $event_id, $member_id, $event_role_id );
			if ( $existing ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'An application for this member, event, and role already exists.', 'remember' ) . '</p></div>';
			} else {
				$data = array(
					'event_id'     => $event_id,
					'member_id'    => $member_id,
					'event_role_id' => $event_role_id,
					'status'       => $status,
				);
				// Check if this is first application and vetting workflow requires vetting on first application
				$is_first = Remember_Vetting_Workflow::is_first_application( $member_id );
				
				$new_application_id = $application_model->create( $data );
				if ( $new_application_id ) {
					// Create vetting case if this is first application and workflow is "first_application"
					if ( $is_first && Remember_Vetting_Workflow::should_vet_on_first_application() ) {
						Remember_Vetting_Workflow::create_vetting_case( $member_id );
					}
					
					Remember_Logger::info( 'Application created', array( 'application_id' => $new_application_id ) );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application created successfully.', 'remember' ) . '</p></div>';
				} else {
					Remember_Logger::error( 'Failed to create application' );
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to create application.', 'remember' ) . '</p></div>';
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please select an event, member, and role.', 'remember' ) . '</p></div>';
		}
	} elseif ( $application_id > 0 ) {
		if ( 'accept' === $action ) {
			// Check capability
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			$application = $application_model->get( $application_id );
			if ( ! $application ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Application not found.', 'remember' ) . '</p></div>';
			} elseif ( ! Remember_Member::is_vetted_member( $member_model->get( $application->member_id ) ) ) {
				Remember_Logger::warning(
					'Application not accepted: member is not vetted',
					array(
						'application_id' => $application_id,
						'member_id'      => (int) $application->member_id,
					)
				);
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This member is not vetted yet. Finish their vetting case before accepting this application.', 'remember' ) . '</p></div>';
			} else {
				global $wpdb;
				$event_role = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er
						LEFT JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
						WHERE er.event_role_id = %d",
						$application->event_role_id
					)
				);

				$accepted_count = $application_model->get_accepted_count_for_event_role( $application->event_role_id, $application_id );
				$is_full = false;
				if ( $event_role && null !== $event_role->max_participants && '' !== $event_role->max_participants ) {
					$is_full = intval( $accepted_count ) >= intval( $event_role->max_participants );
				}

				if ( $is_full ) {
					$result = $application_model->update_status( $application_id, 'waitlisted', get_current_user_id() );
					if ( false !== $result ) {
						$event = $event_model->get( $application->event_id );
						$member = $member_model->get( $application->member_id );
						$user = $member ? get_user_by( 'ID', $member->member_id ) : null;
						$member_name = $user ? $user->display_name : __( 'Unknown Member', 'remember' );
						$event_name = $event ? $event->event_name : __( 'Unknown Event', 'remember' );
						$role_name = $event_role && ! empty( $event_role->role_name ) ? $event_role->role_name : __( 'Event Role', 'remember' );

						remember_notify_event_admins(
							$application->event_id,
							sprintf( __( 'Waitlist update for %s', 'remember' ), $event_name ),
							sprintf(
								__( 'A member was added to the waitlist because capacity is full.%1$sEvent: %2$s%1$sRole: %3$s%1$sMember: %4$s', 'remember' ),
								"\n",
								$event_name,
								$role_name,
								$member_name
							)
						);
						echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Role is at capacity. Application moved to waitlist and Event Administrators were notified.', 'remember' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Role is at capacity, and moving the application to waitlist failed.', 'remember' ) . '</p></div>';
					}
				} else {
					$result = $application_model->update_status( $application_id, 'accepted', get_current_user_id() );
					if ( $result !== false ) {
				Remember_Logger::info( 'Application accepted', array( 'application_id' => $application_id ) );

				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';
				$invoice_notice_shown = false;

				if ( current_user_can( 'remember_create_billing' ) ) {
				if ( Remember_Billing_Provider::is_xero() ) {
					require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-oauth.php';
					if ( Remember_Xero_OAuth::is_connected() ) {
						require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
						$invoice_result = Remember_Xero_Sync::create_invoice_for_application( $application_id );
						if ( is_wp_error( $invoice_result ) ) {
							Remember_Logger::warning(
								'Failed to create Xero invoice for accepted application',
								array(
									'application_id' => $application_id,
									'error'          => $invoice_result->get_error_message(),
								)
							);
							echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Application accepted, but Xero invoice creation failed: ', 'remember' ) . esc_html( $invoice_result->get_error_message() ) . '</p></div>';
						} else {
							echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application accepted and Xero invoice created successfully.', 'remember' ) . '</p></div>';
							echo Remember_Xero_Sync::credit_apply_admin_notices(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- notices are escaped in the helper.
							$email_invoice = Remember_Xero_Sync::invoice_email_held() ? null : Remember_Billing_Provider::email_invoice_for_application( $application_id );
							if ( is_wp_error( $email_invoice ) ) {
								Remember_Logger::warning(
									'Xero invoice email failed after accept',
									array(
										'application_id' => $application_id,
										'error'          => $email_invoice->get_error_message(),
									)
								);
								echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Invoice created, but Xero could not email it to the customer: ', 'remember' ) . esc_html( $email_invoice->get_error_message() ) . '</p></div>';
							} elseif ( true === $email_invoice ) {
								echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Xero invoice emailed to the customer.', 'remember' ) . '</p></div>';
							}
						}
						$invoice_notice_shown = true;
					}
				} elseif ( Remember_Billing_Provider::is_quickbooks() ) {
					require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-oauth.php';
					$qb_settings = Remember_QuickBooks_OAuth::get_settings();
					if ( $qb_settings && ! empty( $qb_settings['access_token'] ) && ! empty( $qb_settings['realm_id'] ) ) {
						require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-sync.php';
						$invoice_result = Remember_QuickBooks_Sync::create_invoice_for_application( $application_id );
						if ( is_wp_error( $invoice_result ) ) {
							Remember_Logger::warning(
								'Failed to create QuickBooks invoice for accepted application',
								array(
									'application_id' => $application_id,
									'error'          => $invoice_result->get_error_message(),
								)
							);
							echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Application accepted, but QuickBooks invoice creation failed: ', 'remember' ) . esc_html( $invoice_result->get_error_message() ) . '</p></div>';
						} else {
							echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application accepted and QuickBooks invoice created successfully.', 'remember' ) . '</p></div>';
							$email_invoice = Remember_Billing_Provider::email_invoice_for_application( $application_id );
							if ( is_wp_error( $email_invoice ) ) {
								Remember_Logger::warning(
									'QuickBooks invoice email failed after accept',
									array(
										'application_id' => $application_id,
										'error'          => $email_invoice->get_error_message(),
									)
								);
								echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Invoice created, but QuickBooks could not email it to the customer: ', 'remember' ) . esc_html( $email_invoice->get_error_message() ) . '</p></div>';
							} elseif ( true === $email_invoice ) {
								echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'QuickBooks invoice emailed to the customer.', 'remember' ) . '</p></div>';
							}
						}
						$invoice_notice_shown = true;
					}
				}
				} // end remember_create_billing gate

				if ( ! $invoice_notice_shown ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application accepted successfully.', 'remember' ) . '</p></div>';
				}

				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
				$notify_result = Remember_Notifications::send_ticket_ready( $application_id );
				if ( is_wp_error( $notify_result ) ) {
					Remember_Logger::warning(
						'Ticket ready email failed after accept',
						array(
							'application_id' => $application_id,
							'error'          => $notify_result->get_error_message(),
						)
					);
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Application accepted, but the ticket email could not be sent: ', 'remember' ) . esc_html( $notify_result->get_error_message() ) . '</p></div>';
				} elseif ( true === $notify_result ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ticket email sent to the member.', 'remember' ) . '</p></div>';
				}
					} else {
						Remember_Logger::error( 'Failed to accept application', array( 'application_id' => $application_id ) );
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to accept application.', 'remember' ) . '</p></div>';
					}
				}
			}
		} elseif ( 'decline' === $action ) {
			// Check capability
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			$application = $application_model->get( $application_id );
			$previous_status = $application ? $application->status : '';
			if ( ! $application || ! in_array( $previous_status, array( 'pending', 'waitlisted', 'accepted' ), true ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Only pending, waitlisted, or accepted applications can be declined.', 'remember' ) . '</p></div>';
			} else {
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-unwind.php';
				$billing_action = isset( $_POST['billing_action'] ) ? Remember_Billing_Unwind::sanitize_action( wp_unslash( $_POST['billing_action'] ) ) : 'leave';
				if ( 'leave' !== $billing_action && ! current_user_can( 'remember_update_billing' ) ) {
					wp_die( __( 'You do not have sufficient permissions to modify billing for this application.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
				}

				$result = $application_model->update_status( $application_id, 'declined', get_current_user_id() );
				if ( $result !== false ) {
					Remember_Logger::info(
						'Application declined',
						array(
							'application_id' => $application_id,
							'billing_action' => $billing_action,
							'previous'       => $previous_status,
						)
					);

					$unwind = Remember_Billing_Unwind::apply(
						$application_id,
						$billing_action,
						sprintf( __( 'Admin declined application #%d', 'remember' ), $application_id )
					);

					if ( 'accepted' === $previous_status ) {
						$event = $event_model->get( $application->event_id );
						$event_name = $event ? $event->event_name : __( 'Unknown Event', 'remember' );
						require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-waitlist.php';
						$waitlist_mode = Remember_Waitlist::sanitize_mode( $event && isset( $event->waitlist_mode ) ? $event->waitlist_mode : 'off' );
						if ( 'off' === $waitlist_mode ) {
							remember_notify_event_admins(
								$application->event_id,
								sprintf( __( 'Seat opened for %s', 'remember' ), $event_name ),
								sprintf( __( 'A previously accepted attendee was declined. A seat is now available for this event.%1$sEvent: %2$s', 'remember' ), "\n", $event_name )
							);
							echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Seat opened and Event Administrators were notified for manual waitlist promotion.', 'remember' ) . '</p></div>';
						} elseif ( 'promoted' === Remember_Waitlist::last_notice() ) {
							echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'The next waiting application for that role was moved to pending.', 'remember' ) . '</p></div>';
						} elseif ( 'notified' === Remember_Waitlist::last_notice() ) {
							echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Event Administrators were emailed that a spot opened.', 'remember' ) . '</p></div>';
						}
					}

					$member_user = get_user_by( 'ID', $application->member_id );
					if ( ! isset( $event_name ) ) {
						$event_obj  = $event_model->get( $application->event_id );
						$event_name = $event_obj ? $event_obj->event_name : '';
					}
					if ( $member_user && is_email( $member_user->user_email ) ) {
						require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
						$notify = Remember_Notifications::send(
							'event_application_declined',
							array(
								'member_name'    => $member_user->display_name,
								'event_name'     => $event_name,
								'application_id' => (string) $application_id,
								'date'           => date_i18n( get_option( 'date_format' ) ),
							),
							$member_user->user_email
						);
						if ( is_wp_error( $notify ) ) {
							Remember_Logger::warning( 'Decline email failed', array( 'error' => $notify->get_error_message() ) );
						}
					}

					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application declined (admin). Ticket marked VOID.', 'remember' ) . '</p></div>';
					if ( is_wp_error( $unwind ) ) {
						echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $unwind->get_error_message() ) . '</p></div>';
					} elseif ( 'leave' !== $billing_action ) {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Billing action applied in the connected provider.', 'remember' ) . '</p></div>';
					}
				} else {
					Remember_Logger::error( 'Failed to decline application', array( 'application_id' => $application_id ) );
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to decline application.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'waitlist' === $action ) {
			// Check capability
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			
			$application = $application_model->get( $application_id );
			$previous_status = $application ? $application->status : '';
			$result = $application_model->update_status( $application_id, 'waitlisted', get_current_user_id() );
			if ( $result !== false ) {
				Remember_Logger::info( 'Application waitlisted', array( 'application_id' => $application_id ) );
				if ( $application ) {
					$event = $event_model->get( $application->event_id );
					$event_name = $event ? $event->event_name : __( 'Unknown Event', 'remember' );
					remember_notify_event_admins(
						$application->event_id,
						sprintf( __( 'Waitlist update for %s', 'remember' ), $event_name ),
						sprintf( __( 'An application was moved to the waitlist.%1$sEvent: %2$s', 'remember' ), "\n", $event_name )
					);
					if ( 'accepted' === $previous_status ) {
						echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Seat opened and Event Administrators were notified for manual waitlist promotion.', 'remember' ) . '</p></div>';
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application moved to waitlist.', 'remember' ) . '</p></div>';
			} else {
				Remember_Logger::error( 'Failed to waitlist application', array( 'application_id' => $application_id ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to waitlist application.', 'remember' ) . '</p></div>';
			}
		} elseif ( 'reopen_pending' === $action ) {
			// Check capability
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}

			$result = $application_model->update_status( $application_id, 'pending', get_current_user_id() );
			if ( false !== $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application moved back to pending.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to move application back to pending.', 'remember' ) . '</p></div>';
			}
		} elseif ( 'allow_reapply' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			$result = $application_model->allow_reapply( $application_id );
			if ( true === $result ) {
				Remember_Logger::info( 'Application superseded for reapply', array( 'application_id' => $application_id ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reapply allowed. The member can submit a new application for this event and role (including agreements). This record is kept for history.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result ) . '</p></div>';
			}
		} elseif ( 'unwind_billing' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) || ! current_user_can( 'remember_update_billing' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-unwind.php';
			$application = $application_model->get( $application_id );
			if ( ! $application || ! in_array( $application->status, array( 'declined', 'cancelled' ), true ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invoice actions are available on declined or cancelled applications.', 'remember' ) . '</p></div>';
			} else {
				$billing_action = isset( $_POST['billing_action'] ) ? Remember_Billing_Unwind::sanitize_action( wp_unslash( $_POST['billing_action'] ) ) : 'leave';
				$unwind         = Remember_Billing_Unwind::apply(
					$application_id,
					$billing_action,
					sprintf( __( 'Admin invoice action on application #%d', 'remember' ), $application_id )
				);
				if ( is_wp_error( $unwind ) ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $unwind->get_error_message() ) . '</p></div>';
				} elseif ( 'leave' === $billing_action ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Invoice left unaltered.', 'remember' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Billing action applied in the connected provider.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'reprocess_billing' === $action ) {
			// Check capability
			if ( ! current_user_can( 'remember_update_applications' ) || ! current_user_can( 'remember_create_billing' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}

			$application = $application_model->get( $application_id );
			if ( ! $application || 'accepted' !== $application->status ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Only accepted applications can be reprocessed for billing.', 'remember' ) . '</p></div>';
			} else {
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';

				if ( Remember_Billing_Provider::is_xero() ) {
					require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-oauth.php';
					if ( Remember_Xero_OAuth::is_connected() ) {
						require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
						$invoice_result = Remember_Xero_Sync::create_invoice_for_application( $application_id );
						if ( is_wp_error( $invoice_result ) ) {
							echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Billing reprocess failed: ', 'remember' ) . esc_html( $invoice_result->get_error_message() ) . '</p></div>';
						} else {
							echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Billing reprocess succeeded. Xero invoice created.', 'remember' ) . '</p></div>';
							echo Remember_Xero_Sync::credit_apply_admin_notices(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- notices are escaped in the helper.
							$email_invoice = Remember_Xero_Sync::invoice_email_held() ? null : Remember_Billing_Provider::email_invoice_for_application( $application_id );
							if ( is_wp_error( $email_invoice ) ) {
								echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Invoice created, but Xero could not email it: ', 'remember' ) . esc_html( $email_invoice->get_error_message() ) . '</p></div>';
							} elseif ( true === $email_invoice ) {
								echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Xero invoice emailed to the customer.', 'remember' ) . '</p></div>';
							}
						}
					} else {
						echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Xero is not connected. Connect Xero in Settings to reprocess billing.', 'remember' ) . '</p></div>';
					}
				} elseif ( Remember_Billing_Provider::is_quickbooks() ) {
					require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-oauth.php';
					$qb_settings = Remember_QuickBooks_OAuth::get_settings();
					if ( $qb_settings && ! empty( $qb_settings['access_token'] ) && ! empty( $qb_settings['realm_id'] ) ) {
						require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-quickbooks-sync.php';
						$invoice_result = Remember_QuickBooks_Sync::create_invoice_for_application( $application_id );
						if ( is_wp_error( $invoice_result ) ) {
							echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Billing reprocess failed: ', 'remember' ) . esc_html( $invoice_result->get_error_message() ) . '</p></div>';
						} else {
							echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Billing reprocess succeeded. QuickBooks invoice created.', 'remember' ) . '</p></div>';
							$email_invoice = Remember_Billing_Provider::email_invoice_for_application( $application_id );
							if ( is_wp_error( $email_invoice ) ) {
								echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Invoice created, but QuickBooks could not email it: ', 'remember' ) . esc_html( $email_invoice->get_error_message() ) . '</p></div>';
							} elseif ( true === $email_invoice ) {
								echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'QuickBooks invoice emailed to the customer.', 'remember' ) . '</p></div>';
							}
						}
					} else {
						echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'QuickBooks is not connected. Connect QuickBooks in Settings to reprocess billing.', 'remember' ) . '</p></div>';
					}
				} else {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No billing provider is selected. Choose QuickBooks or Xero under Settings → General.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'void_ticket' === $action || 'unvoid_ticket' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-ticket.php';
			$voided = ( 'void_ticket' === $action );
			$result = Remember_Ticket::set_voided( $application_id, $voided );
			if ( false !== $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $voided ? __( 'Ticket marked VOID.', 'remember' ) : __( 'Ticket void cleared.', 'remember' ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not update ticket void status.', 'remember' ) . '</p></div>';
			}
		} elseif ( 'email_ticket' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
			$result = Remember_Notifications::send_current_ticket( $application_id );
			if ( true === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ticket email sent to the member.', 'remember' ) . '</p></div>';
			} elseif ( false === $result ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Notification is disabled in Settings → Notifications.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			}
		} elseif ( 'email_ticket_ready' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
			$result = Remember_Notifications::send_ticket_ready( $application_id );
			if ( true === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ticket-ready email sent.', 'remember' ) . '</p></div>';
			} elseif ( false === $result ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Notification is disabled in Settings → Notifications.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			}
		} elseif ( 'email_ticket_paid' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
			$result = Remember_Notifications::send_ticket_paid( $application_id );
			if ( true === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paid-ticket email sent.', 'remember' ) . '</p></div>';
			} elseif ( false === $result ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Notification is disabled in Settings → Notifications.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			}
		} elseif ( 'email_balance_due' === $action ) {
			if ( ! current_user_can( 'remember_update_applications' ) ) {
				wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
			}
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
			$result = Remember_Notifications::send_balance_due( $application_id );
			if ( true === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Balance-due email sent.', 'remember' ) . '</p></div>';
			} elseif ( false === $result ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Notification is disabled in Settings → Notifications.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			}
		}
	} elseif ( 'blast_balance_due' === $action ) {
		if ( ! current_user_can( 'remember_update_applications' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
		$blast_event_id = isset( $_POST['blast_event_id'] ) ? absint( $_POST['blast_event_id'] ) : 0;
		$stats          = Remember_Notifications::blast_balance_due( array( 'event_id' => $blast_event_id ) );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: 1: sent count, 2: skipped, 3: errors */
				__( 'Balance-due blast finished. Sent: %1$d, skipped: %2$d, errors: %3$d.', 'remember' ),
				(int) $stats['sent'],
				(int) $stats['skipped'],
				(int) $stats['errors']
			)
		) . '</p></div>';
	}
}

// Get filter parameters
$filter_event = isset( $_GET['filter_event'] ) ? absint( $_GET['filter_event'] ) : 0;
$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : '';

// Get applications
if ( $filter_event > 0 ) {
	$applications = $application_model->get_by_event( $filter_event );
} elseif ( ! empty( $filter_status ) ) {
	$applications = $application_model->get_by_status( $filter_status );
} else {
	$applications = $application_model->get_all();
}

// Get events for filter
$events = $event_model->get_all();

// Get all members for add form
$all_members = $member_model->get_all();
// Event roles will be loaded dynamically via JavaScript based on selected event

// Check if viewing detail
$viewing_application = null;
if ( isset( $_GET['view'] ) ) {
	$view_id = absint( $_GET['view'] );
	$viewing_application = $application_model->get( $view_id );
	if ( $viewing_application ) {
		// Get related data
		$viewing_event = $event_model->get( $viewing_application->event_id );
		$viewing_member = $member_model->get( $viewing_application->member_id );
		$viewing_user = $viewing_member ? get_user_by( 'ID', $viewing_application->member_id ) : null;
		
		// Get event role info
		global $wpdb;
		$viewing_event_role = $wpdb->get_row( $wpdb->prepare(
			"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er 
			JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
			WHERE er.event_role_id = %d",
			$viewing_application->event_role_id
		) );
		
		// Get payment if exists
		$viewing_payment = $payment_model->get_by_application( $view_id );
		$viewing_billing_label = __( 'Not invoiced yet', 'remember' );
		$viewing_billing_html  = '';
		$viewing_billing_color = '#72777c';
		if ( $viewing_payment && ( ! empty( $viewing_payment->xero_invoice_id ) || ! empty( $viewing_payment->quickbooks_invoice_id ) ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-template.php';
			$link_data = Remember_Billing_Template::get_payment_invoice_link_data( $viewing_payment );
			$viewing_billing_color = '#46b450';

			if ( 'xero' === $link_data['provider'] ) {
				$num_label = ! empty( $link_data['number'] )
					? sprintf( __( 'Invoice #%s', 'remember' ), $link_data['number'] )
					: sprintf( __( 'Invoice ID: %s', 'remember' ), $viewing_payment->xero_invoice_id );
				$viewing_billing_label = sprintf(
					/* translators: %s: invoice number or ID */
					__( 'Invoiced in Xero (%s)', 'remember' ),
					$num_label
				);
			} else {
				$num_label = ! empty( $link_data['number'] )
					? sprintf( __( 'Invoice #%s', 'remember' ), $link_data['number'] )
					: sprintf( __( 'Invoice ID: %s', 'remember' ), $viewing_payment->quickbooks_invoice_id );
				$viewing_billing_label = sprintf(
					/* translators: %s: invoice number or ID */
					__( 'Invoiced in QuickBooks (%s)', 'remember' ),
					$num_label
				);
			}

			if ( ! empty( $link_data['url'] ) ) {
				$viewing_billing_html = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color: %2$s; font-weight: 600;">%3$s</a>',
					esc_url( $link_data['url'] ),
					esc_attr( $viewing_billing_color ),
					esc_html( $viewing_billing_label )
				);
			}
		}

		// Get selected add-ons/merchandise for this application.
		$viewing_application_addons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT am.*, em.merchandise_name, em.description AS addon_description
				FROM {$wpdb->prefix}remember_application_merchandise am
				LEFT JOIN {$wpdb->prefix}remember_event_merchandise em ON am.merchandise_id = em.merchandise_id
				WHERE am.event_application_id = %d
				ORDER BY am.application_merchandise_id ASC",
				$view_id
			)
		);
		
		// Get location if event has one
		if ( $viewing_event && $viewing_event->location_id ) {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-location.php';
			$location_model = new Remember_Location();
			$viewing_location = $location_model->get( $viewing_event->location_id );
		}
		
		// Get processed by user info
		if ( $viewing_application->processed_by ) {
			$processed_by_user = get_user_by( 'ID', $viewing_application->processed_by );
		}
	}
}

// Status labels and colors
$status_labels = array(
	'pending'   => __( 'Pending', 'remember' ),
	'accepted'  => __( 'Accepted', 'remember' ),
	'declined'  => __( 'Declined', 'remember' ),
	'cancelled' => __( 'Cancelled', 'remember' ),
	'waitlisted' => __( 'Waitlisted', 'remember' ),
);
$status_colors = array(
	'pending'   => '#f0b849',
	'accepted'  => '#46b450',
	'declined'  => '#dc3232',
	'cancelled' => '#72777c',
	'waitlisted' => '#00a0d2',
);
?>

<div class="wrap remember-applications">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-waitlist' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Open Waitlist', 'remember' ); ?></a>
	
	<?php if ( ! $viewing_application ) : ?>
		<button type="button" class="page-title-action" onclick="document.getElementById('remember-add-application').style.display='block'; this.style.display='none';"><?php esc_html_e( 'Add New', 'remember' ); ?></button>
	<?php else : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to List', 'remember' ); ?></a>
	<?php endif; ?>
	
	<hr class="wp-header-end">

	<div class="notice notice-info" style="margin: 15px 0;">
		<p>
			<strong><?php esc_html_e( 'Billing note:', 'remember' ); ?></strong>
			<?php echo esc_html( $subtotal_disclaimer ); ?>
		</p>
	</div>

	<?php if ( $viewing_application ) : ?>
		<?php include 'application-detail.php'; ?>
	<?php else : ?>

	<!-- Add Form -->
	<div id="remember-add-application" style="display:none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<h2><?php esc_html_e( 'Add New Application', 'remember' ); ?></h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
			<input type="hidden" name="remember_application_action" value="add">
			
			<table class="form-table">
				<tr>
					<th><label for="event_id"><?php esc_html_e( 'Event', 'remember' ); ?> <span class="description">(required)</span></label></th>
					<td>
						<select id="event_id" name="event_id" class="regular-text" required>
							<option value=""><?php esc_html_e( '-- Select Event --', 'remember' ); ?></option>
							<?php foreach ( $events as $event ) : ?>
								<option value="<?php echo esc_attr( $event->event_id ); ?>"><?php echo esc_html( $event->event_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="member_id"><?php esc_html_e( 'Member', 'remember' ); ?> <span class="description">(required)</span></label></th>
					<td>
						<select id="member_id" name="member_id" class="regular-text" required>
							<option value=""><?php esc_html_e( '-- Select Member --', 'remember' ); ?></option>
							<?php foreach ( $all_members as $member ) : 
								$user = get_user_by( 'ID', $member->member_id );
								if ( ! $user ) continue;
							?>
								<option value="<?php echo esc_attr( $member->member_id ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="event_role_id"><?php esc_html_e( 'Event Role', 'remember' ); ?> <span class="description">(required)</span></label></th>
					<td>
						<select id="event_role_id" name="event_role_id" class="regular-text" required disabled>
							<option value=""><?php esc_html_e( '-- Select Event First --', 'remember' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Select an event first to see available roles for that event.', 'remember' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="status"><?php esc_html_e( 'Initial Status', 'remember' ); ?></label></th>
					<td>
						<select id="status" name="status" class="regular-text">
							<?php foreach ( $status_labels as $status => $label ) : ?>
								<option value="<?php echo esc_attr( $status ); ?>" <?php selected( 'pending', $status ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			
			<p class="submit">
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Application', 'remember' ); ?>">
				<button type="button" class="button" onclick="document.getElementById('remember-add-application').style.display='none'; document.querySelector('.page-title-action').style.display='inline-block';"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
			</p>
		</form>
	</div>

	<!-- Filters -->
	<div class="remember-filters">
		<form method="get" action="">
			<input type="hidden" name="page" value="remember-applications">
			
			<label for="filter_event"><?php esc_html_e( 'Filter by Event:', 'remember' ); ?></label>
			<select id="filter_event" name="filter_event">
				<option value="0"><?php esc_html_e( 'All Events', 'remember' ); ?></option>
				<?php foreach ( $events as $event ) : ?>
					<option value="<?php echo esc_attr( $event->event_id ); ?>" <?php selected( $filter_event, $event->event_id ); ?>>
						<?php echo esc_html( $event->event_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="filter_status"><?php esc_html_e( 'Filter by Status:', 'remember' ); ?></label>
			<select id="filter_status" name="filter_status">
				<option value=""><?php esc_html_e( 'All Statuses', 'remember' ); ?></option>
				<?php foreach ( $status_labels as $status => $label ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'remember' ); ?>">
			<?php if ( $filter_event > 0 || ! empty( $filter_status ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'remember' ); ?></a>
			<?php endif; ?>
		</form>
		<?php if ( current_user_can( 'remember_update_applications' ) ) : ?>
			<form method="post" action="" class="remember-filters__blast">
				<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
				<input type="hidden" name="remember_application_action" value="blast_balance_due">
				<input type="hidden" name="blast_event_id" value="<?php echo esc_attr( $filter_event ); ?>">
				<input type="submit" class="button" value="<?php echo esc_attr( $filter_event > 0 ? __( 'Email balance due (this event)', 'remember' ) : __( 'Email balance due (all accepted with balance)', 'remember' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Send balance-due emails to accepted applicants who still owe?', 'remember' ) ); ?>');">
				<span class="description"><?php esc_html_e( 'Uses the Payment Due Reminder template (includes ticket link).', 'remember' ); ?></span>
			</form>
		<?php endif; ?>
	</div>

	<!-- Applications List -->
	<?php if ( ! empty( $applications ) ) : ?>
		<div class="remember-table-scroll">
		<table class="wp-list-table widefat striped remember-responsive-table remember-list-cards">
			<thead>
				<tr>
					<th class="column-event"><?php esc_html_e( 'Event', 'remember' ); ?></th>
					<th class="column-member"><?php esc_html_e( 'Member', 'remember' ); ?></th>
					<th class="column-role"><?php esc_html_e( 'Role', 'remember' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'remember' ); ?></th>
					<?php if ( current_user_can( 'remember_read_billing' ) ) : ?>
					<th class="column-billing"><?php esc_html_e( 'Billing', 'remember' ); ?></th>
					<?php endif; ?>
					<th class="column-date"><?php esc_html_e( 'Applied', 'remember' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $applications as $application ) : 
					$event = $event_model->get( $application->event_id );
					$member = $member_model->get( $application->member_id );
					$user = $member ? get_user_by( 'ID', $application->member_id ) : null;
					
					// Get role info (we'll need to add this to the model later)
					global $wpdb;
					$event_role = $wpdb->get_row( $wpdb->prepare(
						"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er 
						JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
						WHERE er.event_role_id = %d",
						$application->event_role_id
					) );
					$application_payment = $payment_model->get_by_application( $application->application_id );
					$billing_label = __( 'Not invoiced', 'remember' );
					$billing_color = '#72777c';
					if ( $application_payment && ( ! empty( $application_payment->xero_invoice_id ) || ! empty( $application_payment->quickbooks_invoice_id ) ) ) {
						$billing_label = __( 'Invoiced', 'remember' );
						$billing_color = '#46b450';
					}
				?>
					<tr>
						<td class="column-event" data-label="<?php echo esc_attr__( 'Event', 'remember' ); ?>">
							<?php if ( $event ) : ?>
								<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&view=' . $event->event_id ) ); ?>"><?php echo esc_html( $event->event_name ); ?></a></strong>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
						<td class="column-member" data-label="<?php echo esc_attr__( 'Member', 'remember' ); ?>">
							<?php if ( $user ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $application->member_id ) ); ?>">
									<strong><?php echo esc_html( $user->display_name ); ?></strong>
								</a><br class="remember-list-break">
								<span class="description"><?php echo esc_html( $user->user_email ); ?></span>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Member not found', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-role" data-label="<?php echo esc_attr__( 'Role', 'remember' ); ?>">
							<?php echo $event_role ? esc_html( $event_role->role_name ) : '<span class="description">—</span>'; ?>
						</td>
						<td class="column-status" data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
							<span style="color: <?php echo esc_attr( $status_colors[ $application->status ] ); ?>; font-weight: bold;">
								<?php echo esc_html( $status_labels[ $application->status ] ); ?>
							</span>
						</td>
						<?php if ( current_user_can( 'remember_read_billing' ) ) : ?>
						<td class="column-billing" data-label="<?php echo esc_attr__( 'Billing', 'remember' ); ?>">
							<span style="color: <?php echo esc_attr( $billing_color ); ?>; font-weight: bold;">
								<?php echo esc_html( $billing_label ); ?>
							</span>
						</td>
						<?php endif; ?>
						<td class="column-date" data-label="<?php echo esc_attr__( 'Applied', 'remember' ); ?>">
							<span class="remember-list-meta-label"><?php esc_html_e( 'Applied:', 'remember' ); ?></span>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $application->applied_at ) ) ); ?>
						</td>
						<td class="column-actions" data-label="<?php echo esc_attr__( 'Actions', 'remember' ); ?>">
							<div class="remember-row-actions">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications&view=' . $application->application_id ) ); ?>"><?php esc_html_e( 'View', 'remember' ); ?></a>
							<?php if ( Remember_Ticket::is_eligible( $application ) ) : ?>
								<a href="<?php echo esc_url( Remember_Ticket::get_ticket_url( $application->application_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ticket', 'remember' ); ?></a>
							<?php endif; ?>
							<?php if ( current_user_can( 'remember_update_applications' ) && ( 'pending' === $application->status || 'waitlisted' === $application->status ) ) : ?>
								<form method="post" action="">
									<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
									<input type="hidden" name="remember_application_action" value="accept">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
									<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Accept', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Accept this application?', 'remember' ); ?>');">
								</form>
								
								<form method="post" action="">
									<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
									<input type="hidden" name="remember_application_action" value="decline">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
									<input type="hidden" name="billing_action" value="leave">
									<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Decline', 'remember' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Decline this application? Invoice will be left unaltered. Open the application detail to void or refund.', 'remember' ) ); ?>');">
								</form>
								
								<?php if ( 'pending' === $application->status ) : ?>
									<form method="post" action="">
										<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
										<input type="hidden" name="remember_application_action" value="waitlist">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
										<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Waitlist', 'remember' ); ?>">
									</form>
								<?php endif; ?>
							<?php elseif ( current_user_can( 'remember_update_applications' ) ) : ?>
								<?php if ( 'accepted' === $application->status ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications&view=' . $application->application_id ) ); ?>"><?php esc_html_e( 'Decline…', 'remember' ); ?></a>
									<form method="post" action="">
										<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
										<input type="hidden" name="remember_application_action" value="reopen_pending">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
										<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Move to Pending', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Move this accepted application back to pending?', 'remember' ); ?>');">
									</form>
									<?php if ( current_user_can( 'remember_create_billing' ) ) : ?>
									<form method="post" action="">
										<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
										<input type="hidden" name="remember_application_action" value="reprocess_billing">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
										<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Reprocess Billing', 'remember' ); ?>">
									</form>
									<?php endif; ?>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'Processed', 'remember' ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		
		<p class="description" style="margin-top: 15px;">
			<?php echo esc_html( sprintf( __( 'Showing %d application(s)', 'remember' ), count( $applications ) ) ); ?>
		</p>
		<?php else : ?>
			<p><?php esc_html_e( 'No applications found.', 'remember' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
	// Load event roles when event is selected
	$('#event_id').on('change', function() {
		var selectedEventId = $(this).val();
		var $roleSelect = $('#event_role_id');
		
		if (!selectedEventId) {
			$roleSelect.html('<option value=""><?php esc_html_e( '-- Select Event First --', 'remember' ); ?></option>').prop('disabled', true);
			return;
		}
		
		// Show loading state
		$roleSelect.html('<option value=""><?php esc_html_e( 'Loading roles...', 'remember' ); ?></option>').prop('disabled', true);
		
		// Get AJAX URL - use rememberAjax if available, otherwise fallback to WordPress ajaxurl
		var ajaxUrl = (typeof rememberAjax !== 'undefined' && rememberAjax.ajaxurl) ? rememberAjax.ajaxurl : ajaxurl;
		
		// AJAX request to get event roles
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'remember_get_event_roles',
				event_id: selectedEventId,
				context: 'admin',
				nonce: '<?php echo esc_js( wp_create_nonce( 'remember_get_event_roles_admin' ) ); ?>'
			},
			success: function(response) {
				if (response.success && response.data && response.data.length > 0) {
					$roleSelect.empty().append($('<option>', { value: '' }).text(<?php echo wp_json_encode( __( '-- Select Event Role --', 'remember' ) ); ?>));
					$.each(response.data, function(index, role) {
						$roleSelect.append($('<option>', { value: String(role.event_role_id) }).text(role.role_name || ''));
					});
					$roleSelect.prop('disabled', false);
				} else {
					$roleSelect.html('<option value=""><?php esc_html_e( 'No roles available for this event', 'remember' ); ?></option>').prop('disabled', true);
				}
			},
			error: function(xhr, status, error) {
				console.error('AJAX Error:', status, error);
				console.error('Response:', xhr.responseText);
				$roleSelect.html('<option value=""><?php esc_html_e( 'Error loading roles', 'remember' ); ?></option>').prop('disabled', true);
			}
		});
	});
});
</script>
