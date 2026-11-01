<?php
/**
 * Vetting view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-notifications.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-vetting.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-vetting-workflow.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-capabilities.php';

Remember_Logger::debug( 'Vetting page loaded' );

$vetting_model = new Remember_Vetting();
$member_model = new Remember_Member();

// Handle form submissions
if ( isset( $_POST['remember_vetting_action'] ) && check_admin_referer( 'remember_vetting_action', 'remember_vetting_nonce' ) ) {
	$action = sanitize_text_field( $_POST['remember_vetting_action'] );
	$vetting_id = isset( $_POST['vetting_id'] ) ? absint( $_POST['vetting_id'] ) : 0;
	
	if ( $vetting_id > 0 ) {
		if ( ! current_user_can( 'remember_update_vetting' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		$acting_case = $vetting_model->get( $vetting_id );
		$refusal     = '';
		if ( ! $acting_case ) {
			$refusal = __( 'That vetting case was not found.', 'remember' );
		} elseif ( 'completed' === $acting_case->status ) {
			$refusal = __( 'This vetting case is completed and can no longer be changed.', 'remember' );
		} elseif ( 'assign' === $action ) {
			if ( ! $vetting_model->user_can_assign( $acting_case, get_current_user_id() ) ) {
				$refusal = __( 'Only the primary vetter or a user with Access Settings can reassign this case.', 'remember' );
			}
		} elseif ( in_array( $action, array( 'schedule', 'complete', 'update_status', 'add_collaborator' ), true ) ) {
			if ( ! $vetting_model->user_can_work( $acting_case, get_current_user_id() ) ) {
				$refusal = __( 'Only the primary vetter, collaborators, and users with Access Settings can work this case.', 'remember' );
			}
		}

		if ( '' !== $refusal ) {
			Remember_Logger::warning(
				'Vetting case action refused',
				array(
					'vetting_id' => $vetting_id,
					'action'     => $action,
					'user_id'    => get_current_user_id(),
				)
			);
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $refusal ) . '</p></div>';
		} elseif ( 'assign' === $action ) {
			$vetter_id = isset( $_POST['primary_vetter_id'] ) ? absint( $_POST['primary_vetter_id'] ) : 0;
			if ( $vetter_id > 0 && ! Remember_Vetting::user_can_be_vetter( $vetter_id ) ) {
				Remember_Logger::warning(
					'Vetter assignment refused an account without the vetting permission',
					array(
						'vetting_id' => $vetting_id,
						'vetter_id'  => $vetter_id,
						'user_id'    => get_current_user_id(),
					)
				);
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Choose a vetter who can update vetting cases.', 'remember' ) . '</p></div>';
			} elseif ( $vetter_id > 0 ) {
				$vetter_user = get_user_by( 'ID', $vetter_id );
				$result = $vetting_model->update( $vetting_id, array(
					'primary_vetter_id' => $vetter_id,
					'updated_at' => current_time( 'mysql' ),
				) );
				if ( $result !== false ) {
					// Add system note
					$system_note = sprintf( 
						__( 'SYSTEM: Primary vetter assigned to %s', 'remember' ),
						$vetter_user ? $vetter_user->display_name : __( 'Unknown', 'remember' )
					);
					$vetting_model->add_note( $vetting_id, get_current_user_id(), $system_note, true );
					
					Remember_Logger::info( 'Vetter assigned', array( 'vetting_id' => $vetting_id, 'vetter_id' => $vetter_id ) );
					Remember_Notifications::notify_vetting_staff( 'vetting_assigned', $vetting_id );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Vetter assigned successfully.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'schedule' === $action ) {
			// Handle both old datetime-local format and new separate fields
			$scheduled_at = '';
			if ( isset( $_POST['scheduled_at'] ) && ! empty( $_POST['scheduled_at'] ) ) {
				// Old format (datetime-local)
				$scheduled_at = sanitize_text_field( $_POST['scheduled_at'] );
			} elseif ( isset( $_POST['scheduled_date'] ) && ! empty( $_POST['scheduled_date'] ) ) {
				// New format (separate date, hour, minute, ampm)
				$date = sanitize_text_field( $_POST['scheduled_date'] );
				$hour = isset( $_POST['scheduled_hour'] ) ? absint( $_POST['scheduled_hour'] ) : 12;
				$minute = isset( $_POST['scheduled_minute'] ) ? absint( $_POST['scheduled_minute'] ) : 0;
				$ampm = isset( $_POST['scheduled_ampm'] ) ? sanitize_text_field( $_POST['scheduled_ampm'] ) : 'AM';
				
				// Convert 12-hour to 24-hour format
				if ( 'PM' === $ampm && $hour < 12 ) {
					$hour += 12;
				} elseif ( 'AM' === $ampm && $hour === 12 ) {
					$hour = 0;
				}
				
				// Format as MySQL datetime (in organization timezone)
				$scheduled_at = sprintf( '%s %02d:%02d:00', $date, $hour, $minute );
			}
			
			if ( ! empty( $scheduled_at ) ) {
				$result = $vetting_model->schedule( $vetting_id, $scheduled_at );
				if ( $result !== false ) {
					// Add system note
					$formatted_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $scheduled_at ) );
					$system_note = sprintf( 
						__( 'SYSTEM: Vetting scheduled for %s', 'remember' ),
						$formatted_date
					);
					$vetting_model->add_note( $vetting_id, get_current_user_id(), $system_note, true );
					
					Remember_Logger::info( 'Vetting scheduled', array( 'vetting_id' => $vetting_id, 'scheduled_at' => $scheduled_at ) );
					Remember_Notifications::notify_vetting_staff( 'vetting_scheduled', $vetting_id );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Vetting scheduled successfully.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'complete' === $action ) {
			$decision = isset( $_POST['decision'] ) ? sanitize_text_field( $_POST['decision'] ) : '';
			if ( in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
				$result = $vetting_model->complete( $vetting_id, $decision );
				if ( $result !== false ) {
					// Update member status based on most recent completed vetting case
					$vetting = $vetting_model->get( $vetting_id );
					if ( $vetting ) {
						$latest_case = Remember_Vetting_Workflow::latest_completed_case( $vetting->member_id );
						if ( $latest_case ) {
							Remember_Vetting_Workflow::sync_member_status_from_latest_decision( $vetting->member_id );
							if ( 'accepted' === $latest_case->decision && (int) $latest_case->vetting_id === (int) $vetting_id ) {
								do_action( 'remember_member_vetted', $vetting->member_id );
							}
							if ( (int) $latest_case->vetting_id === (int) $vetting_id ) {
								Remember_Notifications::send_vetting_result( $vetting->member_id, $latest_case->decision, $vetting_id );
								Remember_Notifications::notify_vetting_staff( 'vetting_completed', $vetting_id );
							}
						}
					}
					
					// Add system note
					$decision_label = 'accepted' === $decision ? __( 'Accepted', 'remember' ) : __( 'Rejected', 'remember' );
					$system_note = sprintf( 
						__( 'SYSTEM: Vetting case completed with decision: %s', 'remember' ),
						$decision_label
					);
					$vetting_model->add_note( $vetting_id, get_current_user_id(), $system_note, true );
					
					Remember_Logger::info( 'Vetting completed', array( 'vetting_id' => $vetting_id, 'decision' => $decision ) );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Vetting decision recorded successfully.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'add_note' === $action ) {
			$note_content = isset( $_POST['note_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note_content'] ) ) : '';
			$is_admin_only = isset( $_POST['is_admin_only'] ) ? 1 : 0;
			if ( ! empty( $note_content ) ) {
				$note_id = $vetting_model->add_note( $vetting_id, get_current_user_id(), $note_content, $is_admin_only );
				if ( $note_id ) {
					Remember_Logger::info( 'Vetting note added', array( 'vetting_id' => $vetting_id, 'note_id' => $note_id ) );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Note added successfully.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'add_collaborator' === $action ) {
			$collaborator_id = isset( $_POST['collaborator_id'] ) ? absint( $_POST['collaborator_id'] ) : 0;
			if ( $collaborator_id > 0 && ! Remember_Vetting::user_can_be_vetter( $collaborator_id ) ) {
				Remember_Logger::warning(
					'Collaborator refused an account without the vetting permission',
					array(
						'vetting_id'      => $vetting_id,
						'collaborator_id' => $collaborator_id,
						'user_id'         => get_current_user_id(),
					)
				);
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Choose a collaborator who can update vetting cases.', 'remember' ) . '</p></div>';
			} elseif ( $collaborator_id > 0 ) {
				$collab_user = get_user_by( 'ID', $collaborator_id );
				$collab_id = $vetting_model->add_collaborator( $vetting_id, $collaborator_id, get_current_user_id() );
				if ( $collab_id ) {
					// Add system note
					$system_note = sprintf( 
						__( 'SYSTEM: Collaborator %s added to case', 'remember' ),
						$collab_user ? $collab_user->display_name : __( 'Unknown', 'remember' )
					);
					$vetting_model->add_note( $vetting_id, get_current_user_id(), $system_note, true );
					
					Remember_Logger::info( 'Collaborator added', array( 'vetting_id' => $vetting_id, 'collaborator_id' => $collaborator_id ) );
					Remember_Notifications::notify_vetting_staff( 'vetting_collaborator_invited', $vetting_id );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Collaborator added successfully.', 'remember' ) . '</p></div>';
				}
			}
		} elseif ( 'update_status' === $action ) {
			$new_status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
			if ( '' !== $new_status && ! in_array( $new_status, Remember_Vetting::manual_statuses(), true ) ) {
				Remember_Logger::warning(
					'Vetting status change refused an unknown or decision-only status',
					array(
						'vetting_id' => $vetting_id,
						'status'     => $new_status,
						'user_id'    => get_current_user_id(),
					)
				);
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Choose Pending, Scheduled, or In Progress. To complete a case, record an Accepted or Rejected decision.', 'remember' ) . '</p></div>';
			} elseif ( '' !== $new_status ) {
				$old_status = $acting_case->status;

				$result = $vetting_model->update_status( $vetting_id, $new_status );
				if ( $result !== false && $old_status !== $new_status ) {
					// Add system note
					$status_labels = array(
						'pending'     => __( 'Pending', 'remember' ),
						'scheduled'   => __( 'Scheduled', 'remember' ),
						'in_progress' => __( 'In Progress', 'remember' ),
						'completed'   => __( 'Completed', 'remember' ),
					);
					$old_label = isset( $status_labels[ $old_status ] ) ? $status_labels[ $old_status ] : $old_status;
					$new_label = isset( $status_labels[ $new_status ] ) ? $status_labels[ $new_status ] : $new_status;
					$system_note = sprintf( 
						__( 'SYSTEM: Case status changed from %s to %s', 'remember' ),
						$old_label,
						$new_label
					);
					$vetting_model->add_note( $vetting_id, get_current_user_id(), $system_note, true );
					
					Remember_Logger::info( 'Vetting status updated', array( 'vetting_id' => $vetting_id, 'status' => $new_status ) );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Status updated successfully.', 'remember' ) . '</p></div>';
				}
			}
		}
	} elseif ( 'create_vetting' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_create_vetting' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		// Create new vetting case
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$primary_vetter_id = isset( $_POST['primary_vetter_id'] ) ? absint( $_POST['primary_vetter_id'] ) : 0;
		
		$create_refusal = '';
		if ( $member_id > 0 && ! $member_model->get( $member_id ) ) {
			$create_refusal = __( 'That member was not found.', 'remember' );
		} elseif ( $member_id > 0 && ! Remember_Capabilities::current_user_can_administer_account( $member_id ) ) {
			$create_refusal = __( 'You cannot open a vetting case on a WordPress administrator or a user who can edit other users.', 'remember' );
		} elseif ( $primary_vetter_id > 0 && ! Remember_Vetting::user_can_be_vetter( $primary_vetter_id ) ) {
			$create_refusal = __( 'Choose a vetter who can update vetting cases.', 'remember' );
		}

		if ( '' !== $create_refusal ) {
			Remember_Logger::warning(
				'Vetting case creation refused',
				array(
					'member_id' => $member_id,
					'vetter_id' => $primary_vetter_id,
					'user_id'   => get_current_user_id(),
				)
			);
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $create_refusal ) . '</p></div>';
		} elseif ( $member_id > 0 ) {
			// Create vetting case (multiple cases allowed per member)
			$vetting_id = $vetting_model->create( $member_id, $primary_vetter_id, 'pending' );
			if ( $vetting_id ) {
				// Update member status to in_vetting when a new case is created
				// This allows re-vetting of previously vetted or rejected members
				$member_model->update_status( $member_id, 'in_vetting' );
				
				// Add system note for case creation
				$current_user = wp_get_current_user();
				$system_note = sprintf( 
					__( 'SYSTEM: Vetting case created by %s', 'remember' ),
					$current_user->display_name
				);
				if ( $primary_vetter_id > 0 ) {
					$vetter_user = get_user_by( 'ID', $primary_vetter_id );
					if ( $vetter_user ) {
						$system_note .= sprintf( __( ' with primary vetter %s', 'remember' ), $vetter_user->display_name );
					}
				}
				$vetting_model->add_note( $vetting_id, get_current_user_id(), $system_note, true );
				
				Remember_Logger::info( 'Vetting case created manually', array( 'member_id' => $member_id, 'vetting_id' => $vetting_id ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Vetting case created successfully.', 'remember' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=remember-vetting&view=' . $vetting_id ) ) . '">' . esc_html__( 'View case', 'remember' ) . '</a></p></div>';
			} else {
				$db_error = $vetting_model->get_last_error();
				Remember_Logger::error( 'Failed to create vetting case', array( 'member_id' => $member_id, 'db_error' => $db_error ) );
				$error_message = __( 'Failed to create vetting case.', 'remember' );
				if ( ! empty( $db_error ) ) {
					$error_message .= ' ' . sprintf( __( 'Database error: %s', 'remember' ), esc_html( $db_error ) );
				}
				echo '<div class="notice notice-error is-dismissible"><p>' . $error_message . '</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please select a member.', 'remember' ) . '</p></div>';
		}
	}
}

// Check if viewing a specific vetting case
$viewing_vetting_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
$viewing_vetting = $viewing_vetting_id > 0 ? $vetting_model->get( $viewing_vetting_id ) : null;

// Get filter parameters (only if not viewing a case)
$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : 'all';

// Get vetting records (only if not viewing a case)
if ( ! $viewing_vetting ) {
	if ( ! empty( $filter_status ) && 'all' !== $filter_status ) {
		$vetting_records = $vetting_model->get_by_status( $filter_status );
	} else {
		// Get all records ordered by created_at DESC (newest first)
		$vetting_records = $vetting_model->get_all( array(
			'orderby' => 'created_at',
			'order'   => 'DESC',
		) );
	}
}

// Primary vetters and collaborators must be able to update cases.
$vetters = get_users( array(
	'capability' => 'remember_update_vetting',
) );

// If viewing a case, get related data
if ( $viewing_vetting ) {
	$viewing_member = $member_model->get( $viewing_vetting->member_id );
	$viewing_user = $viewing_member ? get_user_by( 'ID', $viewing_vetting->member_id ) : null;
	$viewing_vetter = ! empty( $viewing_vetting->primary_vetter_id ) ? get_user_by( 'ID', $viewing_vetting->primary_vetter_id ) : null;
	$viewing_notes = $vetting_model->get_notes( $viewing_vetting_id );
	$viewing_collaborators = $vetting_model->get_collaborators( $viewing_vetting_id );
	$can_work_case         = $vetting_model->user_can_work( $viewing_vetting, get_current_user_id() );
	$can_assign_case       = $vetting_model->user_can_assign( $viewing_vetting, get_current_user_id() );
	
	// Get member profile for phone number
	global $wpdb;
	$viewing_profile = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
		$viewing_vetting->member_id
	) );
	
	// Get member's applications
	$application_model = new Remember_Application();
	$viewing_applications = $application_model->get_by_member( $viewing_vetting->member_id );
	
	// Get events for applications
	$event_model = new Remember_Event();
}

// Status labels and colors
$status_labels = array(
	'pending'     => __( 'Pending', 'remember' ),
	'scheduled'   => __( 'Scheduled', 'remember' ),
	'in_progress' => __( 'In Progress', 'remember' ),
	'completed'   => __( 'Completed', 'remember' ),
);
$status_colors = array(
	'pending'     => '#f0b849',
	'scheduled'   => '#00a0d2',
	'in_progress' => '#2271b1',
	'completed'   => '#46b450',
);
$decision_labels = array(
	'pending'  => __( 'Pending', 'remember' ),
	'accepted' => __( 'Accepted', 'remember' ),
	'rejected' => __( 'Rejected', 'remember' ),
);
?>

<div class="wrap remember-vetting">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php 
	// Check if we should pre-show the create form
	$pre_selected_member_id = isset( $_GET['member_id'] ) ? absint( $_GET['member_id'] ) : 0;
	$show_create_form = $pre_selected_member_id > 0 || ( isset( $_GET['action'] ) && 'create' === $_GET['action'] );
	?>
	
	<?php if ( $viewing_vetting ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Vetting Queue', 'remember' ); ?></a>
	<?php else : ?>
		<?php if ( ! $show_create_form && current_user_can( 'remember_create_vetting' ) ) : ?>
			<button type="button" class="page-title-action" onclick="document.getElementById('remember-create-vetting').style.display='block'; this.style.display='none';"><?php esc_html_e( 'Create Vetting Case', 'remember' ); ?></button>
		<?php endif; ?>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php if ( $viewing_vetting ) : ?>
		<?php include 'vetting-detail.php'; ?>
	<?php else : ?>
	<!-- Create Vetting Case Form -->
	<div id="remember-create-vetting" style="<?php echo $show_create_form ? 'display:block;' : 'display:none;'; ?> margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<h2><?php esc_html_e( 'Create New Vetting Case', 'remember' ); ?></h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
			<input type="hidden" name="remember_vetting_action" value="create_vetting">
			
			<table class="form-table">
				<tr>
					<th><label for="member_id"><?php esc_html_e( 'Member', 'remember' ); ?> <span class="description">(required)</span></label></th>
					<td>
						<select id="member_id" name="member_id" class="regular-text" required>
							<option value=""><?php esc_html_e( '-- Select Member --', 'remember' ); ?></option>
							<?php
							$all_members = $member_model->get_all();
							global $wpdb;
							$profiles_by_member_id = array();
							if ( ! empty( $all_members ) ) {
								$member_ids_for_profiles = array_unique(
									array_map(
										static function ( $row ) {
											return absint( $row->member_id );
										},
										$all_members
									)
								);
								$member_ids_for_profiles = array_filter( $member_ids_for_profiles );
								if ( ! empty( $member_ids_for_profiles ) ) {
									$in_list = implode( ',', array_map( 'absint', $member_ids_for_profiles ) );
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint'd.
									$profile_rows = $wpdb->get_results(
										"SELECT member_id, legal_first_name, legal_last_name FROM {$wpdb->prefix}remember_member_profiles WHERE member_id IN ($in_list)"
									);
									foreach ( $profile_rows as $prow ) {
										$profiles_by_member_id[ absint( $prow->member_id ) ] = $prow;
									}
								}
							}
							foreach ( $all_members as $m ) :
								$user = get_user_by( 'ID', $m->member_id );
								if ( ! $user ) {
									continue;
								}
								$profile = isset( $profiles_by_member_id[ $m->member_id ] ) ? $profiles_by_member_id[ $m->member_id ] : null;
								$legal_first = $profile ? trim( (string) $profile->legal_first_name ) : '';
								$legal_last  = $profile ? trim( (string) $profile->legal_last_name ) : '';
								$legal_name  = trim( $legal_first . ' ' . $legal_last );
								if ( '' === $legal_name ) {
									$legal_name = trim(
										(string) get_user_meta( $m->member_id, 'first_name', true ) . ' ' . (string) get_user_meta( $m->member_id, 'last_name', true )
									);
								}
								if ( '' === $legal_name ) {
									$legal_name = $user->display_name;
								}
								$display_part = $user->display_name ? $user->display_name : $user->user_login;
								$option_label = sprintf(
									'%s (%s - %s)',
									$legal_name,
									$display_part,
									$user->user_login
								);
							?>
								<option value="<?php echo esc_attr( $m->member_id ); ?>" <?php selected( $pre_selected_member_id, $m->member_id ); ?>>
									<?php echo esc_html( $option_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Select a member. Multiple vetting cases can be created for the same member.', 'remember' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="primary_vetter_id"><?php esc_html_e( 'Primary Vetter', 'remember' ); ?></label></th>
					<td>
						<select id="primary_vetter_id" name="primary_vetter_id" class="regular-text">
							<option value="0"><?php esc_html_e( '-- Assign Later --', 'remember' ); ?></option>
							<?php foreach ( $vetters as $v ) : ?>
								<option value="<?php echo esc_attr( $v->ID ); ?>">
									<?php echo esc_html( $v->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'You can assign a vetter now or later.', 'remember' ); ?></p>
					</td>
				</tr>
			</table>
			
			<p class="submit">
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Create Vetting Case', 'remember' ); ?>">
				<?php if ( $pre_selected_member_id > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $pre_selected_member_id ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'remember' ); ?></a>
				<?php else : ?>
					<button type="button" class="button" onclick="document.getElementById('remember-create-vetting').style.display='none'; document.querySelector('.page-title-action').style.display='inline-block';"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<!-- Filters -->
	<div class="remember-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<form method="get" action="">
			<input type="hidden" name="page" value="remember-vetting">
			
			<label for="filter_status"><?php esc_html_e( 'Filter by Status:', 'remember' ); ?></label>
			<select id="filter_status" name="filter_status" style="margin-right: 20px;">
				<option value="all"><?php esc_html_e( 'All Statuses', 'remember' ); ?></option>
				<?php foreach ( $status_labels as $status => $label ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'remember' ); ?>">
			<?php if ( 'all' !== $filter_status ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting&filter_status=all' ) ); ?>" class="button"><?php esc_html_e( 'Show All', 'remember' ); ?></a>
			<?php endif; ?>
		</form>
	</div>

	<!-- Vetting Records List -->
	<?php if ( ! empty( $vetting_records ) ) : ?>
		<div class="remember-table-scroll">
		<table class="wp-list-table widefat striped remember-responsive-table remember-list-cards">
			<thead>
				<tr>
					<th class="column-case"><?php esc_html_e( 'Case', 'remember' ); ?></th>
					<th class="column-member"><?php esc_html_e( 'Member', 'remember' ); ?></th>
					<th class="column-vetter"><?php esc_html_e( 'Primary Vetter', 'remember' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'remember' ); ?></th>
					<th class="column-scheduled"><?php esc_html_e( 'Scheduled', 'remember' ); ?></th>
					<th class="column-decision"><?php esc_html_e( 'Decision', 'remember' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $vetting_records as $vetting ) : 
					$member = $member_model->get( $vetting->member_id );
					$user = $member ? get_user_by( 'ID', $vetting->member_id ) : null;
					$vetter = get_user_by( 'ID', $vetting->primary_vetter_id );
				?>
					<tr>
						<td class="column-case" data-label="<?php echo esc_attr__( 'Case', 'remember' ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting&view=' . $vetting->vetting_id ) ); ?>">
								<strong>#<?php echo esc_html( $vetting->vetting_id ); ?></strong>
							</a>
						</td>
						<td class="column-member" data-label="<?php echo esc_attr__( 'Member', 'remember' ); ?>">
							<?php if ( $user ) : ?>
								<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $vetting->member_id ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a></strong><br class="remember-list-break">
								<span class="description"><?php echo esc_html( $user->user_email ); ?></span>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Member not found', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-vetter" data-label="<?php echo esc_attr__( 'Primary Vetter', 'remember' ); ?>">
							<span class="remember-list-meta-label"><?php esc_html_e( 'Vetter:', 'remember' ); ?></span>
							<?php if ( $vetter ) : ?>
								<?php 
								// Check if vetter is also a member
								$vetter_member = $member_model->get( $vetting->primary_vetter_id );
								if ( $vetter_member ) :
									// Link to member profile
								?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $vetting->primary_vetter_id ) ); ?>"><?php echo esc_html( $vetter->display_name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $vetter->display_name ); ?>
								<?php endif; ?>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
						<td class="column-status" data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
							<span style="color: <?php echo esc_attr( $status_colors[ $vetting->status ] ); ?>; font-weight: bold;">
								<?php echo esc_html( $status_labels[ $vetting->status ] ); ?>
							</span>
						</td>
						<td class="column-scheduled" data-label="<?php echo esc_attr__( 'Scheduled', 'remember' ); ?>">
							<span class="remember-list-meta-label"><?php esc_html_e( 'Scheduled:', 'remember' ); ?></span>
							<?php if ( $vetting->scheduled_at ) : ?>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $vetting->scheduled_at ) ) ); ?>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
						<td class="column-decision<?php echo ( 'completed' === $vetting->status ) ? '' : ' remember-list-empty'; ?>" data-label="<?php echo esc_attr__( 'Decision', 'remember' ); ?>">
							<?php if ( 'completed' === $vetting->status ) : ?>
								<span style="color: <?php echo 'accepted' === $vetting->decision ? '#46b450' : '#dc3232'; ?>; font-weight: bold;">
									<?php echo esc_html( $decision_labels[ $vetting->decision ] ); ?>
								</span>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
						<td class="column-actions" data-label="<?php echo esc_attr__( 'Actions', 'remember' ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting&view=' . $vetting->vetting_id ) ); ?>"><?php esc_html_e( 'View Case', 'remember' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		
		<p class="description" style="margin-top: 15px;">
			<?php echo esc_html( sprintf( __( 'Showing %d vetting record(s)', 'remember' ), count( $vetting_records ) ) ); ?>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'No vetting records found.', 'remember' ); ?></p>
	<?php endif; ?>
	<?php endif; ?>
</div>
