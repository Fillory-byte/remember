<?php
/**
 * Member dashboard template
 *
 * @package    reMember
 * @subpackage reMember/public/partials
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-vetting.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-location.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-merchandise.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-addon-role-limits.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-event-invites.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-payment.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-template.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-messaging.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-import-export.php';

$user = wp_get_current_user();
$member_model = new Remember_Member();
$event_model = new Remember_Event();
$application_model = new Remember_Application();
$vetting_model = new Remember_Vetting();
$location_model = new Remember_Location();
$merchandise_model = new Remember_Merchandise();

// Get member record
$member = $member_model->get( get_current_user_id() );
$member_id_for_queries = ( $member && ! empty( $member->member_id ) ) ? absint( $member->member_id ) : get_current_user_id();

// Handle member-side application actions (edit pending/waitlisted, withdraw accepted).
if ( isset( $_POST['remember_member_application_action'] ) && check_admin_referer( 'remember_member_application_action', 'remember_member_application_nonce' ) ) {
	$member_action = sanitize_text_field( wp_unslash( $_POST['remember_member_application_action'] ) );
	$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
	$application = $application_id > 0 ? $application_model->get( $application_id ) : null;

	if ( $application && absint( $application->member_id ) === absint( $member_id_for_queries ) ) {
		if ( 'update_application' === $member_action && in_array( $application->status, array( 'pending', 'waitlisted' ), true ) ) {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
			$currency_error = Remember_Profile_Audit::validate_application_confirmation(
				isset( $_POST['remember_profile_currency_confirm'] ) ? wp_unslash( $_POST['remember_profile_currency_confirm'] ) : '',
				(int) $member_id_for_queries
			);
			if ( '' !== $currency_error ) {
				echo '<div class="remember-notice remember-error"><p>' . esc_html( $currency_error ) . '</p></div>';
			} else {
			global $wpdb;
			$new_event_role_id = isset( $_POST['event_role_id'] ) ? absint( $_POST['event_role_id'] ) : 0;
			$role_error        = '';
			// A role change is a fresh choice: the event must still be open to this member and the role offered to them.
			if ( $new_event_role_id > 0 && $new_event_role_id !== absint( $application->event_role_id ) ) {
				$application_event = $event_model->get( absint( $application->event_id ) );
				if ( ! $application_event || ! Remember_Event_Invites::member_can_apply( $application_event, (int) $member_id_for_queries ) ) {
					$role_error = __( 'This event is by invitation only.', 'remember' );
				} else {
					$role_error = Remember_Event::registration_block_reason( $application_event );
				}
				if ( '' === $role_error && ! $event_model->member_offered_event_role( absint( $application->event_id ), (int) $member_id_for_queries, $new_event_role_id ) ) {
					$role_error = __( 'Please choose a role from the list.', 'remember' );
				}
			}
			if ( '' !== $role_error ) {
				echo '<div class="remember-notice remember-error"><p>' . esc_html( $role_error ) . '</p></div>';
			} else {
			if ( $new_event_role_id > 0 && $new_event_role_id !== absint( $application->event_role_id ) ) {
				$application_model->update( $application_id, array( 'event_role_id' => $new_event_role_id ) );
			}

			// Replace add-ons with current selection (role-aware max qty).
			$wpdb->delete(
				$wpdb->prefix . 'remember_application_merchandise',
				array( 'event_application_id' => $application_id ),
				array( '%d' )
			);
			$effective_role_id = $new_event_role_id;
			if ( $effective_role_id < 1 ) {
				$effective_role_id = absint( $application->event_role_id );
			}
			$event_addons = Remember_Addon_Role_Limits::get_available_addons_for_role( $application->event_id, $effective_role_id );
			$addon_map    = array();
			foreach ( $event_addons as $event_addon ) {
				$addon_map[ absint( $event_addon->merchandise_id ) ] = $event_addon;
			}

			if ( isset( $_POST['event_addons'] ) && is_array( $_POST['event_addons'] ) ) {
				foreach ( $_POST['event_addons'] as $addon_id => $addon_data ) {
					$addon_id = absint( $addon_id );
					if ( $addon_id <= 0 || empty( $addon_data['selected'] ) || ! isset( $addon_map[ $addon_id ] ) ) {
						continue;
					}
					$addon    = $addon_map[ $addon_id ];
					$quantity = Remember_Addon_Role_Limits::clamp_quantity(
						$addon_id,
						$effective_role_id,
						isset( $addon_data['quantity'] ) ? absint( $addon_data['quantity'] ) : 1
					);
					if ( $quantity < 1 ) {
						continue;
					}

					$unit_cost  = (float) $addon->cost;
					$total_cost = $unit_cost * $quantity;
					$wpdb->insert(
						$wpdb->prefix . 'remember_application_merchandise',
						array(
							'event_application_id' => $application_id,
							'merchandise_id'       => $addon_id,
							'quantity'             => $quantity,
							'unit_cost'            => $unit_cost,
							'total_cost'           => $total_cost,
							'created_at'           => current_time( 'mysql' ),
						),
						array( '%d', '%d', '%d', '%f', '%f', '%s' )
					);
				}
			}
			Remember_Profile_Audit::touch_updated( (int) $member_id_for_queries, get_current_user_id() );
			echo '<div class="remember-notice remember-notice-success"><p>' . esc_html__( 'Application updated. Your profile currency confirmation was recorded.', 'remember' ) . '</p></div>';
			}
			}
		} elseif ( 'withdraw_application' === $member_action && 'accepted' === $application->status ) {
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-unwind.php';
			$updated = $application_model->update_status( $application_id, 'cancelled', get_current_user_id() );
			if ( false !== $updated ) {
				// Void ticket only; leave invoice for admin to handle in the back end.
				Remember_Billing_Unwind::apply(
					$application_id,
					'leave',
					sprintf( __( 'Member cancelled application #%d', 'remember' ), $application_id )
				);
				echo '<div class="remember-notice remember-notice-success"><p>' . esc_html__( 'Application cancelled. Your ticket is marked VOID.', 'remember' ) . '</p></div>';
			}
		}
	}
}

// Get member profile
global $wpdb;
$profile = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
		$member_id_for_queries
	)
);

// Get applications
$applications = $application_model->get_by_member( $member_id_for_queries );

// Get accepted events (where member has accepted application)
$accepted_events = array();
$past_events = array();
$upcoming_events = array();
$today = current_time( 'Y-m-d' );

foreach ( $applications as $app ) {
	if ( 'accepted' === $app->status ) {
		$event = $event_model->get( $app->event_id );
		if ( $event ) {
			$accepted_events[] = $event;
			// Separate into past and upcoming
			if ( $event->end_date < $today ) {
				$past_events[] = $event;
			} else {
				$upcoming_events[] = $event;
			}
		}
	}
}

// Get all vetting cases for this member
$vetting_cases = $vetting_model->get_all_by_member( $member_id_for_queries );

// Refresh amounts from the active billing provider before rendering.
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';
Remember_Billing_Provider::sync_member_payments( $member_id_for_queries );

// Payments for billing table (same data as admin; scoped to this member).
$payment_model         = new Remember_Payment();
$member_payments       = $payment_model->get_by_member( $member_id_for_queries );
$payment_event_names   = array();
$billing_subtotal_note = Remember_Billing_Messaging::get_subtotal_disclaimer();
foreach ( $member_payments as $mp ) {
	$aid = isset( $mp->event_application_id ) ? absint( $mp->event_application_id ) : 0;
	if ( $aid <= 0 ) {
		$payment_event_names[ $mp->payment_id ] = '—';
		continue;
	}
	$pay_app = $application_model->get( $aid );
	if ( ! $pay_app ) {
		$payment_event_names[ $mp->payment_id ] = '—';
		continue;
	}
	$pay_event = $event_model->get( $pay_app->event_id );
	$payment_event_names[ $mp->payment_id ] = $pay_event ? $pay_event->event_name : __( 'Unknown event', 'remember' );
}

// Get profile page URL
$created_pages = get_option( 'remember_created_pages', array() );
$profile_page_id = isset( $created_pages['profile'] ) ? $created_pages['profile'] : 0;
$profile_page_url = $profile_page_id ? get_permalink( $profile_page_id ) : '';
$events_page_id  = isset( $created_pages['events'] ) ? absint( $created_pages['events'] ) : 0;
if ( ! $events_page_id ) {
	$events_page = get_page_by_path( 'events' );
	$events_page_id = $events_page ? (int) $events_page->ID : 0;
}
$events_page_url = $events_page_id ? get_permalink( $events_page_id ) : home_url( '/events/' );

// Status labels
$status_labels = array(
	'pending_vetting' => __( 'Pending Vetting', 'remember' ),
	'unvetted'        => __( 'Unvetted', 'remember' ),
	'in_vetting'      => __( 'In Vetting', 'remember' ),
	'vetted'          => __( 'Vetted', 'remember' ),
	'rejected'        => __( 'Rejected', 'remember' ),
	'inactive'        => __( 'Inactive', 'remember' ),
);

$status_colors = array(
	'pending_vetting' => '#666',
	'unvetted'        => '#666',
	'in_vetting'      => '#2271b1',
	'vetted'          => '#46b450',
	'rejected'        => '#dc3232',
	'inactive'        => '#666',
);

$app_status_labels = array(
	'pending'    => __( 'Pending', 'remember' ),
	'accepted'   => __( 'Accepted', 'remember' ),
	'declined'   => __( 'Declined', 'remember' ),
	'waitlisted' => __( 'Waitlisted', 'remember' ),
	'cancelled'  => __( 'Withdrawn', 'remember' ),
);

$selected_application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
$selected_application = null;
$selected_event = null;
$selected_event_role = null;
$selected_event_roles = array();
$selected_event_addons = array();
$selected_application_addons = array();
if ( $selected_application_id > 0 ) {
	$selected_application = $application_model->get( $selected_application_id );
	if ( $selected_application && absint( $selected_application->member_id ) === absint( $member_id_for_queries ) ) {
		$selected_event = $event_model->get( $selected_application->event_id );
		$selected_event_roles = $event_model->get_event_roles( $selected_application->event_id );
		foreach ( $selected_event_roles as $role_option ) {
			if ( absint( $role_option->event_role_id ) === absint( $selected_application->event_role_id ) ) {
				$selected_event_role = $role_option;
				break;
			}
		}
		$selected_event_addons = Remember_Addon_Role_Limits::get_available_addons_for_role(
			$selected_application->event_id,
			$selected_application->event_role_id
		);
		$selected_application_addons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}remember_application_merchandise WHERE event_application_id = %d",
				$selected_application_id
			)
		);
	} else {
		$selected_application = null;
	}
}

$selected_addon_map = array();
foreach ( $selected_application_addons as $selected_addon_row ) {
	$selected_addon_map[ absint( $selected_addon_row->merchandise_id ) ] = $selected_addon_row;
}
?>

<div class="remember-dashboard">
	<?php if ( $selected_application ) : ?>
		<div class="remember-dashboard-card-compact" style="margin-bottom: 20px;">
			<h3><?php esc_html_e( 'Application Detail', 'remember' ); ?> #<?php echo esc_html( $selected_application->application_id ); ?></h3>
			<p><strong><?php esc_html_e( 'Event:', 'remember' ); ?></strong> <?php echo esc_html( $selected_event ? $selected_event->event_name : __( 'Unknown Event', 'remember' ) ); ?></p>
			<p><strong><?php esc_html_e( 'Status:', 'remember' ); ?></strong> <?php echo esc_html( $app_status_labels[ $selected_application->status ] ?? $selected_application->status ); ?></p>
			<p><strong><?php esc_html_e( 'Applied:', 'remember' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $selected_application->applied_at ) ) ); ?></p>

			<?php
			$itemized_subtotal = $selected_event_role ? (float) $selected_event_role->cost : 0;
			foreach ( $selected_application_addons as $selected_addon_row ) {
				$itemized_subtotal += (float) $selected_addon_row->total_cost;
			}
			?>
			<p><strong><?php esc_html_e( 'Subtotal:', 'remember' ); ?></strong> $<?php echo esc_html( number_format( $itemized_subtotal, 2 ) ); ?></p>

			<?php if ( in_array( $selected_application->status, array( 'pending', 'waitlisted' ), true ) ) : ?>
				<form method="post" action="" class="remember-form" style="margin-top: 15px;">
					<?php wp_nonce_field( 'remember_member_application_action', 'remember_member_application_nonce' ); ?>
					<input type="hidden" name="remember_member_application_action" value="update_application">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $selected_application->application_id ); ?>">

					<div class="remember-form-group">
						<label class="remember-form-label" for="member_app_event_role_id"><?php esc_html_e( 'Role', 'remember' ); ?></label>
						<select id="member_app_event_role_id" name="event_role_id" class="remember-form-control">
							<?php foreach ( $selected_event_roles as $role_option ) : ?>
								<option value="<?php echo esc_attr( $role_option->event_role_id ); ?>" <?php selected( absint( $selected_application->event_role_id ), absint( $role_option->event_role_id ) ); ?>>
									<?php echo esc_html( $role_option->role_name ); ?> ($<?php echo esc_html( number_format( (float) $role_option->cost, 2 ) ); ?> subtotal)
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="remember-form-group">
						<label class="remember-form-label"><?php esc_html_e( 'Add-ons', 'remember' ); ?></label>
						<p class="remember-description"><?php esc_html_e( 'Add-ons shown are for the selected role. Change role and save if needed; max qty is per role.', 'remember' ); ?></p>
						<?php if ( ! empty( $selected_event_addons ) ) : ?>
							<?php foreach ( $selected_event_addons as $addon_option ) : ?>
								<?php
								$existing_addon = isset( $selected_addon_map[ absint( $addon_option->merchandise_id ) ] ) ? $selected_addon_map[ absint( $addon_option->merchandise_id ) ] : null;
								$role_max       = isset( $addon_option->max_quantity ) ? absint( $addon_option->max_quantity ) : 1;
								?>
								<div style="border: 1px solid #ddd; padding: 8px; margin-bottom: 8px;">
									<label>
										<input type="checkbox" name="event_addons[<?php echo esc_attr( $addon_option->merchandise_id ); ?>][selected]" value="1" <?php checked( null !== $existing_addon ); ?>>
										<strong><?php echo esc_html( $addon_option->merchandise_name ); ?></strong>
										($<?php echo esc_html( number_format( (float) $addon_option->cost, 2 ) ); ?> subtotal)
									</label>
									<label style="margin-left: 10px;">
										<?php esc_html_e( 'Qty', 'remember' ); ?>
										<input type="number" class="small-text" min="1" max="<?php echo esc_attr( $role_max ); ?>" name="event_addons[<?php echo esc_attr( $addon_option->merchandise_id ); ?>][quantity]" value="<?php echo esc_attr( $existing_addon ? min( absint( $existing_addon->quantity ), $role_max ) : 1 ); ?>">
										<span class="remember-description">(max <?php echo esc_html( (string) $role_max ); ?>)</span>
									</label>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<p class="remember-description"><?php esc_html_e( 'No add-ons available for this role.', 'remember' ); ?></p>
						<?php endif; ?>
					</div>

					<?php
					require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
					Remember_Profile_Audit::render_confirm_field( 'remember_dashboard_profile_currency_confirm' );
					?>

					<button type="submit" class="remember-button remember-button-primary" disabled><?php esc_html_e( 'Save Application Changes', 'remember' ); ?></button>
				</form>
			<?php elseif ( 'accepted' === $selected_application->status || ! empty( $selected_application->ticket_voided ) ) : ?>
				<?php
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-ticket.php';
				if ( Remember_Ticket::is_eligible( $selected_application ) ) :
					$ticket_url = Remember_Ticket::get_ticket_url( $selected_application->application_id );
					?>
					<p style="margin-top: 15px;">
						<a class="remember-button remember-button-primary" href="<?php echo esc_url( $ticket_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View / Print Ticket', 'remember' ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( 'accepted' === $selected_application->status ) : ?>
				<form method="post" action="" style="margin-top: 15px;" class="remember-cancel-application">
					<?php wp_nonce_field( 'remember_member_application_action', 'remember_member_application_nonce' ); ?>
					<input type="hidden" name="remember_member_application_action" value="withdraw_application">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $selected_application->application_id ); ?>">
					<h4 style="margin: 0 0 8px;"><?php esc_html_e( 'Cancel application', 'remember' ); ?></h4>
					<p class="remember-description" style="margin-top: 0;">
						<?php esc_html_e( 'This cancels your application and voids your admission ticket. Billing questions are handled by an administrator.', 'remember' ); ?>
					</p>
					<button type="submit" class="remember-button remember-button-secondary" style="margin-top: 10px;" onclick="return confirm('<?php echo esc_js( __( 'Cancel this application?', 'remember' ) ); ?>');"><?php esc_html_e( 'Cancel Application', 'remember' ); ?></button>
				</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php else : ?>
	<div class="remember-dashboard-header-compact">
		<div class="remember-header-left">
			<?php if ( $member && ! empty( $member->photo_url ) ) : ?>
				<div class="remember-member-avatar-large">
					<img src="<?php echo esc_url( $member->photo_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
				</div>
			<?php endif; ?>
			<div class="remember-header-info">
				<h2><?php echo esc_html( sprintf( __( 'Welcome, %s', 'remember' ), $user->display_name ) ); ?></h2>
				<?php if ( $member ) : ?>
					<p class="remember-member-status" style="color: <?php echo esc_attr( $status_colors[ $member->status ] ?? '#666' ); ?>;">
						<?php echo esc_html( $status_labels[ $member->status ] ?? $member->status ); ?>
					</p>
				<?php endif; ?>
				<?php if ( $profile ) : ?>
					<div class="remember-header-profile-info">
						<?php
						$remember_legal_name_line = trim( Remember_Import_Export::member_list_legal_name_line( $profile, get_current_user_id() ) );
						if ( ! empty( $remember_legal_name_line ) ) :
							?>
							<span class="remember-header-info-item">
								<strong><?php esc_html_e( 'Legal Name (private):', 'remember' ); ?></strong>
								<?php echo esc_html( $remember_legal_name_line ); ?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $profile->cell_phone ) ) : ?>
							<span class="remember-header-info-item">
								<strong><?php esc_html_e( 'Phone:', 'remember' ); ?></strong>
								<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $profile->cell_phone ) ); ?>">
									<?php echo esc_html( $profile->cell_phone ); ?>
								</a>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $profile_page_url ) : ?>
			<a href="<?php echo esc_url( $profile_page_url ); ?>" class="remember-button remember-button-primary">
				<?php esc_html_e( 'View Profile', 'remember' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<div class="remember-dashboard-grid-compact">

		<!-- Vetting Status -->
		<div class="remember-dashboard-card-compact">
			<h3><?php esc_html_e( 'Vetting Status', 'remember' ); ?></h3>
			<?php if ( ! empty( $vetting_cases ) ) : ?>
				<div class="remember-vetting-cases">
					<?php 
					$current_user_id = get_current_user_id();
					foreach ( array_slice( $vetting_cases, 0, 3 ) as $case ) : 
						$case_notes = $vetting_model->get_notes( $case->vetting_id );
						// Filter out admin-only notes
						$member_visible_notes = array_filter( $case_notes, function( $note ) {
							return ! $note->is_admin_only;
						} );
					?>
						<div class="remember-vetting-case-item">
							<div class="remember-vetting-case-header">
								<strong><?php printf( esc_html__( 'Case #%d', 'remember' ), $case->vetting_id ); ?></strong>
								<span class="remember-status-badge remember-status-<?php echo esc_attr( $case->status ); ?>">
									<?php echo esc_html( ucfirst( str_replace( '_', ' ', $case->status ) ) ); ?>
								</span>
							</div>
							<?php if ( 'scheduled' === $case->status && ! empty( $case->scheduled_at ) ) : 
								$scheduled_display = Remember_Timezone::format_with_your_time( $case->scheduled_at, $current_user_id, true );
							?>
								<div class="remember-vetting-scheduled">
									<small><?php esc_html_e( 'Scheduled:', 'remember' ); ?> <?php echo esc_html( $scheduled_display ); ?></small>
								</div>
							<?php endif; ?>
							<?php if ( 'completed' === $case->status && ! empty( $case->decision ) && 'pending' !== $case->decision ) : ?>
								<div class="remember-vetting-decision">
									<small><strong><?php esc_html_e( 'Decision:', 'remember' ); ?></strong> <?php echo esc_html( ucfirst( $case->decision ) ); ?></small>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $member_visible_notes ) ) : ?>
								<div class="remember-vetting-notes-preview">
									<details>
										<summary><?php printf( esc_html__( 'Notes (%d)', 'remember' ), count( $member_visible_notes ) ); ?></summary>
										<ul class="remember-vetting-notes-list">
											<?php foreach ( array_slice( $member_visible_notes, 0, 3 ) as $note ) : 
												$note_author = get_user_by( 'ID', $note->member_id );
											?>
												<li>
													<small>
														<strong><?php echo $note_author ? esc_html( $note_author->display_name ) : esc_html__( 'System', 'remember' ); ?>:</strong>
														<?php echo esc_html( wp_trim_words( $note->note_content, 15 ) ); ?>
														<br>
														<span class="remember-note-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $note->created_at ) ) ); ?></span>
													</small>
												</li>
											<?php endforeach; ?>
										</ul>
									</details>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php if ( count( $vetting_cases ) > 3 ) : ?>
						<p class="remember-view-more"><small><?php esc_html_e( 'View all cases in profile', 'remember' ); ?></small></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="remember-description"><?php esc_html_e( 'No vetting cases yet.', 'remember' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Applications -->
		<div class="remember-dashboard-card-compact">
			<h3><?php esc_html_e( 'Your Applications', 'remember' ); ?></h3>
			<?php if ( ! empty( $applications ) ) : ?>
				<ul class="remember-application-list-compact">
					<?php foreach ( array_slice( $applications, 0, 5 ) as $app ) : 
						$event = $event_model->get( $app->event_id );
					?>
						<li>
							<span class="remember-app-event">
								<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'dashboard', 'application_id' => $app->application_id ), get_permalink() ) ); ?>">
									<?php echo $event ? esc_html( $event->event_name ) : __( 'Unknown Event', 'remember' ); ?>
								</a>
							</span>
							<span class="remember-status-badge remember-status-<?php echo esc_attr( $app->status ); ?>">
								<?php echo esc_html( $app_status_labels[ $app->status ] ?? $app->status ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $applications ) > 5 ) : ?>
					<p class="remember-view-more"><small><a href="<?php echo esc_url( get_permalink() . '?view=applications' ); ?>">
						<?php esc_html_e( 'View all applications', 'remember' ); ?>
					</a></small></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="remember-description"><?php esc_html_e( 'No applications yet.', 'remember' ); ?></p>
			<?php endif; ?>
			<p style="margin-top: 0.75em;">
				<a href="<?php echo esc_url( $events_page_url ); ?>" class="remember-button remember-button-secondary">
					<?php esc_html_e( 'Browse Events', 'remember' ); ?>
				</a>
			</p>
		</div>

		<!-- My Events -->
		<div class="remember-dashboard-card-compact">
			<div class="remember-events-header">
				<h3><?php esc_html_e( 'My Events', 'remember' ); ?></h3>
				<?php if ( ! empty( $past_events ) ) : ?>
					<label class="remember-show-past-events">
						<input type="checkbox" id="remember-show-past-events" onchange="document.querySelectorAll('.remember-past-event').forEach(el => el.style.display = this.checked ? 'block' : 'none');">
						<small><?php esc_html_e( 'Show past events', 'remember' ); ?></small>
					</label>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $accepted_events ) ) : ?>
				<div class="remember-vetting-cases">
					<?php foreach ( $upcoming_events as $event ) : 
						$event_detail_pages = get_option( 'remember_created_pages', array() );
						$event_detail_page_id = isset( $event_detail_pages['event_detail'] ) ? $event_detail_pages['event_detail'] : 0;
						
						if ( $event_detail_page_id ) {
							$event_url = add_query_arg( 'event', $event->event_id, get_permalink( $event_detail_page_id ) );
						} else {
							$event_url = add_query_arg( 'event', $event->event_id, get_permalink() );
						}
						
						// Get location
						$location = $event->location_id ? $location_model->get( $event->location_id ) : null;
						$location_display = '';
						if ( $location ) {
							$location_parts = array_filter( array( $location->location_name, $location->address_city, $location->address_state ) );
							$location_display = implode( ', ', $location_parts );
						}
						
						// Format dates
						$start_date = date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) );
						$end_date = date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) );
						$date_display = $event->start_date === $event->end_date ? $start_date : $start_date . ' - ' . $end_date;
					?>
						<div class="remember-vetting-case-item">
							<div class="remember-vetting-case-header">
								<strong><a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->event_name ); ?></a></strong>
							</div>
							<?php if ( ! empty( $location_display ) ) : ?>
								<div class="remember-vetting-scheduled">
									<small><strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong> <?php echo esc_html( $location_display ); ?></small>
								</div>
							<?php endif; ?>
							<div class="remember-vetting-scheduled">
								<small><strong><?php esc_html_e( 'Dates:', 'remember' ); ?></strong> <?php echo esc_html( $date_display ); ?></small>
							</div>
						</div>
					<?php endforeach; ?>
					<?php foreach ( $past_events as $event ) : 
						$event_detail_pages = get_option( 'remember_created_pages', array() );
						$event_detail_page_id = isset( $event_detail_pages['event_detail'] ) ? $event_detail_pages['event_detail'] : 0;
						
						if ( $event_detail_page_id ) {
							$event_url = add_query_arg( 'event', $event->event_id, get_permalink( $event_detail_page_id ) );
						} else {
							$event_url = add_query_arg( 'event', $event->event_id, get_permalink() );
						}
						
						// Get location
						$location = $event->location_id ? $location_model->get( $event->location_id ) : null;
						$location_display = '';
						if ( $location ) {
							$location_parts = array_filter( array( $location->location_name, $location->address_city, $location->address_state ) );
							$location_display = implode( ', ', $location_parts );
						}
						
						// Format dates
						$start_date = date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) );
						$end_date = date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) );
						$date_display = $event->start_date === $event->end_date ? $start_date : $start_date . ' - ' . $end_date;
					?>
						<div class="remember-vetting-case-item remember-past-event" style="display: none;">
							<div class="remember-vetting-case-header">
								<strong><a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->event_name ); ?></a></strong>
							</div>
							<?php if ( ! empty( $location_display ) ) : ?>
								<div class="remember-vetting-scheduled">
									<small><strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong> <?php echo esc_html( $location_display ); ?></small>
								</div>
							<?php endif; ?>
							<div class="remember-vetting-scheduled">
								<small><strong><?php esc_html_e( 'Dates:', 'remember' ); ?></strong> <?php echo esc_html( $date_display ); ?></small>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="remember-description"><?php esc_html_e( 'No accepted events yet.', 'remember' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="remember-dashboard-billing remember-dashboard-card-compact">
		<h3><?php esc_html_e( 'Billing', 'remember' ); ?></h3>
		<p class="remember-description remember-billing-note"><?php echo esc_html( $billing_subtotal_note ); ?></p>
		<?php
		require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
		$remember_open_credit = Remember_Xero_Sync::member_open_credit( get_current_user_id() );
		$remember_open_notes  = ( is_array( $remember_open_credit ) && ! empty( $remember_open_credit['notes'] ) ) ? $remember_open_credit['notes'] : array();
		?>
		<?php if ( ! empty( $member_payments ) || ! empty( $remember_open_notes ) ) : ?>
			<div id="remember-member-billing" class="remember-billing-table-wrap remember-billing-table-frame">
				<?php
				Remember_Billing_Template::render_payments_table(
					array(
						'payments'            => $member_payments,
						'context'             => 'member',
						'payment_event_names' => $payment_event_names,
						'open_credits'        => $remember_open_notes,
					)
				);
				?>
			</div>
			<?php if ( ! empty( $remember_open_notes ) && Remember_Billing_Provider::should_auto_apply_xero_credit() ) : ?>
				<p class="remember-description" style="margin-top: 0.75em;">
					<?php esc_html_e( 'Open credit is applied to your next invoice.', 'remember' ); ?>
				</p>
			<?php endif; ?>
			<p class="remember-description" style="margin-top: 0.75em;">
				<?php
				$remember_billing_row_count = count( $member_payments ) + count( $remember_open_notes );
				echo esc_html(
					sprintf(
						/* translators: %d: number of billing rows */
						_n( '%d billing record', '%d billing records', $remember_billing_row_count, 'remember' ),
						$remember_billing_row_count
					)
				);
				?>
			</p>
		<?php else : ?>
			<p class="remember-description"><?php esc_html_e( 'No billing records yet.', 'remember' ); ?></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</div>
