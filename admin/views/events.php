<?php
/**
 * Events view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-location.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-merchandise.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-product.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-addon-role-limits.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-agreements.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-event-invites.php';

Remember_Logger::debug( 'Events page loaded' );

$event_model = new Remember_Event();
$location_model = new Remember_Location();
$application_model = new Remember_Application();
$role_model = new Remember_Role();
$merchandise_model = new Remember_Merchandise();
$product_model = new Remember_Product();
$catalog_products = $product_model->get_active();
$catalog_products_by_name = array();
foreach ( $catalog_products as $catalog_product ) {
	$catalog_products_by_name[ $catalog_product->product_name ] = $catalog_product;
}
$has_catalog_products = ! empty( $catalog_products );

// Get all available event roles for the forms
$event_roles = $role_model->get_event_roles();

// Initialize editing event role IDs (will be set if editing)
$editing_event_role_ids = array();
$editing_event_roles_by_id = array();
$editing_addons = array();

/**
 * Build role config payload from submitted form values.
 *
 * @return array
 */
function remember_build_role_configs_from_post() {
	$role_configs = array();
	if ( empty( $_POST['event_roles_config'] ) || ! is_array( $_POST['event_roles_config'] ) ) {
		return $role_configs;
	}

	foreach ( $_POST['event_roles_config'] as $role_id => $config ) {
		$role_id = absint( $role_id );
		if ( $role_id <= 0 || ! is_array( $config ) ) {
			continue;
		}
		$role_configs[ $role_id ] = array(
			'enabled'          => isset( $config['enabled'] ) ? 1 : 0,
			'cost'             => isset( $config['cost'] ) ? floatval( wp_unslash( $config['cost'] ) ) : 0,
			'max_participants' => isset( $config['max_participants'] ) ? sanitize_text_field( wp_unslash( $config['max_participants'] ) ) : '',
		);
	}

	return $role_configs;
}

/**
 * Build add-on payload from submitted form values.
 *
 * @return array
 */
function remember_build_addons_from_post( $product_model ) {
	$addons = array();
	if ( empty( $_POST['event_addons'] ) || ! is_array( $_POST['event_addons'] ) ) {
		return $addons;
	}

	foreach ( $_POST['event_addons'] as $addon ) {
		if ( ! is_array( $addon ) ) {
			continue;
		}

		$product_id = isset( $addon['product_id'] ) ? absint( $addon['product_id'] ) : 0;
		if ( $product_id <= 0 ) {
			continue;
		}

		$product = $product_model->get( $product_id );
		if ( ! $product || empty( $product->product_name ) ) {
			continue;
		}

		$role_limits = array();
		if ( isset( $addon['role_limits'] ) && is_array( $addon['role_limits'] ) ) {
			foreach ( $addon['role_limits'] as $role_id => $max_qty ) {
				$role_limits[ absint( $role_id ) ] = max( 0, absint( $max_qty ) );
			}
		}

		$addons[] = array(
			'merchandise_id'   => isset( $addon['id'] ) ? absint( $addon['id'] ) : 0,
			'merchandise_name' => sanitize_text_field( $product->product_name ),
			'description'      => isset( $product->description ) ? sanitize_textarea_field( $product->description ) : '',
			'cost'             => isset( $addon['cost'] ) ? floatval( wp_unslash( $addon['cost'] ) ) : 0,
			'max_quantity'     => ( isset( $addon['max_quantity'] ) && '' !== $addon['max_quantity'] ) ? absint( $addon['max_quantity'] ) : null,
			'is_available'     => isset( $addon['is_available'] ) ? 1 : 0,
			'role_limits'      => $role_limits,
		);
	}

	return $addons;
}

/**
 * Sync event add-ons (event_merchandise rows) from UI payload.
 *
 * @param Remember_Merchandise $merchandise_model Merchandise model.
 * @param int                  $event_id Event ID.
 * @param array                $addons Addon payload.
 * @return void
 */
/**
 * Render event ↔ agreement pin checkboxes (pinned revision per agreement).
 *
 * @param int $event_id Event ID (0 on add).
 * @return void
 */
function remember_render_event_agreements_field( $event_id = 0 ) {
	$library = Remember_Agreements::get_active_agreements();
	$pins    = $event_id > 0 ? Remember_Agreements::get_event_pin_map( $event_id ) : array();
	// Include inactive agreements already pinned so the event can keep them.
	if ( $event_id > 0 ) {
		foreach ( Remember_Agreements::get_event_pinned_revisions( $event_id ) as $pinned ) {
			$found = false;
			foreach ( $library as $item ) {
				if ( (int) $item->agreement_id === (int) $pinned->agreement_id ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$library[] = Remember_Agreements::get_agreement( (int) $pinned->agreement_id );
			}
		}
	}
	?>
	<p class="description">
		<?php esc_html_e( 'Attach agreements from the library. Each applicant must acknowledge every attached revision when they apply. Changing the library later does not move this event off a pinned revision until you change it here.', 'remember' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements' ) ); ?>"><?php esc_html_e( 'Manage Agreements', 'remember' ); ?></a>
	</p>
	<?php if ( empty( $library ) ) : ?>
		<p class="description"><?php esc_html_e( 'No agreements in the library yet.', 'remember' ); ?></p>
		<?php
		return;
	endif;
	?>
	<div class="remember-event-agreements">
		<?php foreach ( $library as $agreement ) : ?>
			<?php
			if ( ! $agreement ) {
				continue;
			}
			$aid       = (int) $agreement->agreement_id;
			$revisions = Remember_Agreements::get_revisions_for_agreement( $aid );
			if ( empty( $revisions ) ) {
				continue;
			}
			$checked   = isset( $pins[ $aid ] );
			$selected  = $checked ? (int) $pins[ $aid ] : (int) $revisions[0]->revision_id;
			?>
			<div class="remember-event-agreement-row" style="margin:8px 0;padding:8px 10px;border:1px solid #dcdcde;border-radius:3px;background:#fff;">
				<label style="display:block;margin-bottom:6px;">
					<input type="checkbox" name="event_agreement_ids[]" value="<?php echo esc_attr( (string) $aid ); ?>" <?php checked( $checked ); ?>>
					<strong><?php echo esc_html( $agreement->title ); ?></strong>
				</label>
				<label>
					<?php esc_html_e( 'Pinned revision', 'remember' ); ?>
					<select name="event_agreement_revisions[<?php echo esc_attr( (string) $aid ); ?>]">
						<?php foreach ( $revisions as $rev ) : ?>
							<option value="<?php echo esc_attr( (string) $rev->revision_id ); ?>" <?php selected( $selected, (int) $rev->revision_id ); ?>>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: revision number, 2: date */
										__( 'Revision %1$d — %2$s', 'remember' ),
										(int) $rev->revision_number,
										date_i18n( get_option( 'date_format' ), strtotime( $rev->created_at ) )
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

function remember_sync_event_addons( $merchandise_model, $event_id, $addons ) {
	$existing = $merchandise_model->get_all_by_event( $event_id );
	$existing_ids = array_map(
		function( $item ) {
			return absint( $item->merchandise_id );
		},
		$existing
	);

	$incoming_ids = array();
	foreach ( $addons as $addon ) {
		$data = array(
			'event_id'         => $event_id,
			'merchandise_name' => $addon['merchandise_name'],
			'description'      => $addon['description'],
			'cost'             => $addon['cost'],
			'max_quantity'     => $addon['max_quantity'],
			'is_available'     => $addon['is_available'],
		);

		$merchandise_id = 0;
		if ( ! empty( $addon['merchandise_id'] ) ) {
			$merchandise_id = absint( $addon['merchandise_id'] );
			$incoming_ids[] = $merchandise_id;
			$merchandise_model->update( $merchandise_id, $data );
		} else {
			$new_id = $merchandise_model->create( $data );
			if ( $new_id ) {
				$merchandise_id = absint( $new_id );
				$incoming_ids[] = $merchandise_id;
			}
		}

		if ( $merchandise_id > 0 ) {
			$role_limits = isset( $addon['role_limits'] ) && is_array( $addon['role_limits'] ) ? $addon['role_limits'] : array();
			Remember_Addon_Role_Limits::sync_for_merchandise( $event_id, $merchandise_id, $role_limits );
		}
	}

	foreach ( $existing_ids as $existing_id ) {
		if ( ! in_array( $existing_id, $incoming_ids, true ) ) {
			Remember_Addon_Role_Limits::delete_for_merchandise( $existing_id );
			$merchandise_model->delete( $existing_id );
		}
	}
}

/**
 * Render per-role max qty inputs for an add-on row.
 *
 * @param int   $index            Addon row index.
 * @param array $event_roles      Catalog event roles.
 * @param array $limits_by_role_id role_id => max_qty.
 * @return void
 */
function remember_render_addon_role_limits( $index, $event_roles, $limits_by_role_id = array() ) {
	if ( empty( $event_roles ) ) {
		echo '<p class="description">' . esc_html__( 'Add event roles above to configure per-role add-on limits.', 'remember' ) . '</p>';
		return;
	}
	?>
	<div class="remember-addon-role-limits" style="margin-top:10px;">
		<p class="description" style="margin:0 0 6px;">
			<?php esc_html_e( 'Max quantity per event role. Use 0 to hide this add-on from that role (e.g. Inmate uniform = 1 for Inmate, 0 for Guard; Guard polos = 2 for Guard, 0 for Inmate).', 'remember' ); ?>
		</p>
		<table class="widefat striped" style="max-width:36rem;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Event role', 'remember' ); ?></th>
					<th style="width:8rem;"><?php esc_html_e( 'Max qty', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $event_roles as $role ) : ?>
					<?php
					$role_id = absint( $role->role_id );
					$value   = array_key_exists( $role_id, $limits_by_role_id ) ? absint( $limits_by_role_id[ $role_id ] ) : 1;
					?>
					<tr>
						<td><?php echo esc_html( $role->role_name ); ?></td>
						<td>
							<input type="number" min="0" class="small-text" name="event_addons[<?php echo esc_attr( $index ); ?>][role_limits][<?php echo esc_attr( $role_id ); ?>]" value="<?php echo esc_attr( $value ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Waitlist action from the event form.
 *
 * @return string
 */
function remember_event_waitlist_mode() {
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-waitlist.php';
	$mode = isset( $_POST['waitlist_mode'] ) ? wp_unslash( $_POST['waitlist_mode'] ) : 'off';
	return Remember_Waitlist::sanitize_mode( $mode );
}

/**
 * Optional registration window from the event form.
 *
 * @return array|WP_Error
 */
function remember_event_registration_fields() {
	$opens  = isset( $_POST['registration_opens_at'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_opens_at'] ) ) : '';
	$closes = isset( $_POST['registration_closes_at'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_closes_at'] ) ) : '';
	return Remember_Event::registration_bounds_from_input( $opens, $closes );
}

/**
 * Registration open and close inputs. Blank means that side is unlimited.
 *
 * @param object|null $event Event being edited.
 * @return void
 */
function remember_render_registration_window_fields( $event = null ) {
	$opens  = $event ? Remember_Event::registration_datetime_input_value( isset( $event->registration_opens_at ) ? $event->registration_opens_at : '' ) : '';
	$closes = $event ? Remember_Event::registration_datetime_input_value( isset( $event->registration_closes_at ) ? $event->registration_closes_at : '' ) : '';
	$tz     = wp_timezone_string();
	?>
	<tr>
		<th><label for="registration_opens_at"><?php esc_html_e( 'Registration Opens', 'remember' ); ?></label></th>
		<td>
			<input type="datetime-local" id="registration_opens_at" name="registration_opens_at" class="regular-text" value="<?php echo esc_attr( $opens ); ?>">
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: site timezone */
						__( 'Optional. Leave blank to open as soon as the event status is Open. Times use the site timezone (%s).', 'remember' ),
						$tz ? $tz : __( 'site settings', 'remember' )
					)
				);
				?>
			</p>
		</td>
	</tr>
	<tr>
		<th><label for="registration_closes_at"><?php esc_html_e( 'Registration Closes', 'remember' ); ?></label></th>
		<td>
			<input type="datetime-local" id="registration_closes_at" name="registration_closes_at" class="regular-text" value="<?php echo esc_attr( $closes ); ?>">
			<p class="description"><?php esc_html_e( 'Optional. Leave blank to keep registration open until the event status changes.', 'remember' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label for="waitlist_mode"><?php esc_html_e( 'When a spot opens', 'remember' ); ?></label></th>
		<td>
			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-waitlist.php';
			$waitlist_mode = Remember_Waitlist::sanitize_mode( $event && isset( $event->waitlist_mode ) ? $event->waitlist_mode : 'off' );
			?>
			<select id="waitlist_mode" name="waitlist_mode">
				<option value="off" <?php selected( $waitlist_mode, 'off' ); ?>><?php esc_html_e( 'Off', 'remember' ); ?></option>
				<option value="notify" <?php selected( $waitlist_mode, 'notify' ); ?>><?php esc_html_e( 'Email event administrators', 'remember' ); ?></option>
				<option value="auto" <?php selected( $waitlist_mode, 'auto' ); ?>><?php esc_html_e( 'Move the next waiting application to pending', 'remember' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Runs when an accepted application is withdrawn or declined. Off leaves the waitlist for staff. Email tells accepted Event Administrators how many people are waiting for that role. The automatic choice moves the oldest waiting application for that role to pending while registration is open and the role has room. It does not accept the application or create an invoice.', 'remember' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label for="checkin_enabled"><?php esc_html_e( 'Door check-in', 'remember' ); ?></label></th>
		<td>
			<?php $checkin_enabled = $event && ! empty( $event->checkin_enabled ); ?>
			<label>
				<input type="checkbox" id="checkin_enabled" name="checkin_enabled" value="1" <?php checked( $checkin_enabled ); ?>>
				<?php esc_html_e( 'Print a check-in code on the admission ticket', 'remember' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Off leaves the printed ticket unchanged. On adds a code to that same ticket and lists the event on the Check-in screen. Staff can scan it, type it, or search by name.', 'remember' ); ?></p>
		</td>
	</tr>
	<?php
}

/**
 * Whether the event form turned door check-in on.
 *
 * @return int
 */
function remember_event_checkin_enabled() {
	return ! empty( $_POST['checkin_enabled'] ) ? 1 : 0;
}

/**
 * Save invitees and send due invitation emails.
 *
 * @param int $event_id Event ID.
 * @return void
 */
function remember_save_event_invites( $event_id ) {
	$event_id = absint( $event_id );
	if ( $event_id < 1 ) {
		return;
	}
	Remember_Event_Invites::sync_from_request( $event_id );
	$sent = Remember_Event_Invites::send_due_for_event( $event_id );
	if ( $sent > 0 ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %d: number of emails sent */
				_n( 'Invitation email sent to %d member.', 'Invitation emails sent to %d members.', $sent, 'remember' ),
				$sent
			)
		);
		echo '</p></div>';
	}
}

/**
 * Private checkbox and member invite pick list.
 *
 * @param object|null $event Event being edited.
 * @return void
 */
function remember_render_private_invite_fields( $event = null ) {
	$is_private = $event && ! empty( $event->is_private );
	$selected   = array();
	if ( $event && ! empty( $event->event_id ) ) {
		$selected = Remember_Event_Invites::member_ids_for_event( (int) $event->event_id );
	}
	$selected_lookup = array_fill_keys( $selected, true );
	$candidates      = Remember_Event_Invites::invite_candidates();
	$status_labels   = array(
		'pending_vetting' => __( 'Pending Vetting', 'remember' ),
		'unvetted'        => __( 'Unvetted', 'remember' ),
		'in_vetting'      => __( 'In Vetting', 'remember' ),
		'vetted'          => __( 'Vetted', 'remember' ),
		'rejected'        => __( 'Rejected', 'remember' ),
		'inactive'        => __( 'Inactive', 'remember' ),
	);
	?>
	<tr>
		<th><label for="is_private"><?php esc_html_e( 'Private Event', 'remember' ); ?></label></th>
		<td>
			<label>
				<input type="checkbox" id="is_private" name="is_private" value="1" class="remember-event-private-toggle" <?php checked( $is_private ); ?>>
				<?php esc_html_e( 'This is a private event (invite only)', 'remember' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Only invited members see this event. They still complete the usual application.', 'remember' ); ?></p>
		</td>
	</tr>
	<tr class="remember-event-invitees-row"<?php echo $is_private ? '' : ' hidden'; ?>>
		<th><?php esc_html_e( 'Invitees', 'remember' ); ?></th>
		<td>
			<?php if ( empty( $candidates ) ) : ?>
				<p class="description"><?php esc_html_e( 'No members are available to invite yet.', 'remember' ); ?></p>
			<?php else : ?>
				<div class="remember-event-invite-toolbar">
					<input type="search" class="remember-event-invite-filter" placeholder="<?php esc_attr_e( 'Filter members…', 'remember' ); ?>" aria-label="<?php esc_attr_e( 'Filter members', 'remember' ); ?>">
					<button type="button" class="button-link remember-event-invite-select-shown"><?php esc_html_e( 'Select shown', 'remember' ); ?></button>
					<span class="remember-event-invite-toolbar-sep" aria-hidden="true">·</span>
					<button type="button" class="button-link remember-event-invite-clear-shown"><?php esc_html_e( 'Clear shown', 'remember' ); ?></button>
					<span class="remember-event-invite-count-wrap">
						<span class="remember-event-invite-count"><?php echo esc_html( (string) count( $selected ) ); ?></span>
						<?php esc_html_e( 'selected', 'remember' ); ?>
					</span>
				</div>
				<div class="remember-event-invite-list" role="group" aria-label="<?php esc_attr_e( 'Invited members', 'remember' ); ?>">
					<?php foreach ( $candidates as $candidate ) : ?>
						<?php
						$member_id = absint( $candidate->member_id );
						$is_on     = isset( $selected_lookup[ $member_id ] );
						$status    = isset( $candidate->status ) ? (string) $candidate->status : '';
						$label     = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
						$name      = isset( $candidate->display_name ) && '' !== $candidate->display_name ? $candidate->display_name : $candidate->user_email;
						$search    = strtolower( $name . ' ' . $candidate->user_email . ' ' . $label );
						?>
						<label class="<?php echo $is_on ? 'is-checked' : ''; ?>" data-search="<?php echo esc_attr( $search ); ?>">
							<input type="checkbox" name="event_invitees[]" value="<?php echo esc_attr( (string) $member_id ); ?>" <?php checked( $is_on ); ?>>
							<span class="remember-event-invite-name"><?php echo esc_html( $name ); ?></span>
							<?php if ( $label ) : ?>
								<span class="remember-event-invite-meta"><?php echo esc_html( $label ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Invitation emails go out when registration opens, or when you save the event as Open if no open time is set.', 'remember' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

// Handle form submissions
if ( isset( $_POST['remember_event_action'] ) && check_admin_referer( 'remember_event_action', 'remember_event_nonce' ) ) {
	$action = sanitize_text_field( $_POST['remember_event_action'] );
	
	if ( 'add' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_create_events' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		$registration_fields = remember_event_registration_fields();
		$data = array(
			'event_name'        => sanitize_text_field( wp_unslash( $_POST['event_name'] ) ),
			'event_description' => isset( $_POST['event_description'] ) ? wp_kses_post( wp_unslash( $_POST['event_description'] ) ) : '',
			'attendee_details'  => isset( $_POST['attendee_details'] ) ? wp_kses_post( wp_unslash( $_POST['attendee_details'] ) ) : '',
			'location_id'       => ! empty( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : null,
			'start_date'        => sanitize_text_field( wp_unslash( $_POST['start_date'] ) ),
			'end_date'          => sanitize_text_field( wp_unslash( $_POST['end_date'] ) ),
			'is_private'        => isset( $_POST['is_private'] ) ? 1 : 0,
			'status'            => sanitize_text_field( wp_unslash( $_POST['status'] ) ),
			'waitlist_mode'     => remember_event_waitlist_mode(),
			'checkin_enabled'   => remember_event_checkin_enabled(),
			'created_by'        => get_current_user_id(),
		);
		$event_id = 0;
		if ( is_wp_error( $registration_fields ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $registration_fields->get_error_message() ) . '</p></div>';
		} else {
			$data     = array_merge( $data, $registration_fields );
			$event_id = $event_model->create( $data );
		}
		if ( is_wp_error( $registration_fields ) ) {
			// The validation notice above is the failure. Do not also report a database error.
		} elseif ( $event_id ) {
			$role_configs = remember_build_role_configs_from_post();
			$event_model->sync_event_role_configs( $event_id, $role_configs );

			$addons = remember_build_addons_from_post( $product_model );
			remember_sync_event_addons( $merchandise_model, $event_id, $addons );
			Remember_Agreements::sync_event_pins( $event_id, Remember_Agreements::pins_from_request() );
			remember_save_event_invites( $event_id );

			Remember_Logger::info( 'Event created', array( 'event_id' => $event_id ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event created successfully.', 'remember' ) . '</p></div>';
		} else {
			Remember_Logger::error( 'Failed to create event' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to create event.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'edit' === $action && isset( $_POST['event_id'] ) ) {
		// Check capability
		if ( ! current_user_can( 'remember_update_events' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		$event_id = absint( $_POST['event_id'] );
		$registration_fields = remember_event_registration_fields();
		$data = array(
			'event_name'        => sanitize_text_field( wp_unslash( $_POST['event_name'] ) ),
			'event_description' => isset( $_POST['event_description'] ) ? wp_kses_post( wp_unslash( $_POST['event_description'] ) ) : '',
			'attendee_details'  => isset( $_POST['attendee_details'] ) ? wp_kses_post( wp_unslash( $_POST['attendee_details'] ) ) : '',
			'location_id'       => ! empty( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : null,
			'start_date'        => sanitize_text_field( wp_unslash( $_POST['start_date'] ) ),
			'end_date'          => sanitize_text_field( wp_unslash( $_POST['end_date'] ) ),
			'is_private'        => isset( $_POST['is_private'] ) ? 1 : 0,
			'status'            => sanitize_text_field( wp_unslash( $_POST['status'] ) ),
			'waitlist_mode'     => remember_event_waitlist_mode(),
			'checkin_enabled'   => remember_event_checkin_enabled(),
		);
		$result = false;
		if ( is_wp_error( $registration_fields ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $registration_fields->get_error_message() ) . '</p></div>';
		} else {
			$data   = array_merge( $data, $registration_fields );
			$result = $event_model->update( $event_id, $data );
		}
		if ( is_wp_error( $registration_fields ) ) {
			// The validation notice above is the failure. Do not also report a database error.
		} elseif ( $result !== false ) {
			$role_configs = remember_build_role_configs_from_post();
			$event_model->sync_event_role_configs( $event_id, $role_configs );

			$addons = remember_build_addons_from_post( $product_model );
			remember_sync_event_addons( $merchandise_model, $event_id, $addons );
			Remember_Agreements::sync_event_pins( $event_id, Remember_Agreements::pins_from_request() );
			remember_save_event_invites( $event_id );

			Remember_Logger::info( 'Event updated', array( 'event_id' => $event_id, 'role_configs' => $role_configs ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event updated successfully.', 'remember' ) . '</p></div>';
			// Reload the event data to reflect changes
			$editing_event = $event_model->get( $event_id );
			if ( $editing_event ) {
				$editing_event_roles = $event_model->get_event_roles( $event_id );
				$editing_event_role_ids = array_map( function( $role ) {
					return $role->role_id;
				}, $editing_event_roles );
				$editing_event_roles_by_id = array();
				foreach ( $editing_event_roles as $editing_event_role ) {
					$editing_event_roles_by_id[ absint( $editing_event_role->role_id ) ] = $editing_event_role;
				}
				$editing_addons = $merchandise_model->get_all_by_event( $event_id );
			}
		} else {
			Remember_Logger::error( 'Failed to update event', array( 'event_id' => $event_id ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to update event.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'delete' === $action && isset( $_GET['delete'] ) ) {
		// Check capability
		if ( ! current_user_can( 'remember_delete_events' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		$event_id = absint( $_GET['delete'] );
		$result = $event_model->delete( $event_id );
		if ( $result !== false ) {
			Remember_Event_Invites::delete_for_event( $event_id );
			Remember_Logger::info( 'Event deleted', array( 'event_id' => $event_id ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event deleted successfully.', 'remember' ) . '</p></div>';
		} else {
			Remember_Logger::error( 'Failed to delete event', array( 'event_id' => $event_id ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to delete event.', 'remember' ) . '</p></div>';
		}
	}
}

// Get events and locations
$events = $event_model->get_all();
$locations = $location_model->get_active();
$invite_counts = Remember_Event_Invites::counts_by_event();

// Check if viewing detail or editing
$viewing_event = null;
$editing_event = null;
if ( isset( $_GET['view'] ) ) {
	$view_id = absint( $_GET['view'] );
	$viewing_event = $event_model->get( $view_id );
	if ( $viewing_event ) {
		// Get applications for this event
		$event_applications = $application_model->get_by_event( $view_id );
		// Get location if exists
		if ( $viewing_event->location_id ) {
			$viewing_location = $location_model->get( $viewing_event->location_id );
		}
	}
} elseif ( isset( $_GET['edit'] ) ) {
	// Check capability
	if ( ! current_user_can( 'remember_update_events' ) ) {
		wp_die( __( 'You do not have sufficient permissions to edit events.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
	}
	
	$edit_id = absint( $_GET['edit'] );
	$editing_event = $event_model->get( $edit_id );
	if ( $editing_event ) {
		// Get existing event roles for this event
		$editing_event_roles = $event_model->get_event_roles( $edit_id );
		$editing_event_role_ids = array_map( function( $role ) {
			return $role->role_id;
		}, $editing_event_roles );
		foreach ( $editing_event_roles as $editing_event_role ) {
			$editing_event_roles_by_id[ absint( $editing_event_role->role_id ) ] = $editing_event_role;
		}
		$editing_addons = $merchandise_model->get_all_by_event( $edit_id );
	}
}
?>

<div class="wrap remember-events">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php if ( ! $viewing_event && ! $editing_event ) : ?>
		<?php if ( current_user_can( 'remember_create_events' ) ) : ?>
			<button type="button" class="page-title-action" onclick="document.getElementById('remember-add-event').style.display='block'; this.style.display='none';"><?php esc_html_e( 'Add New', 'remember' ); ?></button>
		<?php endif; ?>
	<?php elseif ( $viewing_event ) : ?>
		<?php if ( current_user_can( 'remember_update_events' ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&edit=' . $viewing_event->event_id ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit', 'remember' ); ?></a>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to List', 'remember' ); ?></a>
	<?php else : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Cancel', 'remember' ); ?></a>
	<?php endif; ?>
	
	<hr class="wp-header-end">

	<?php if ( $viewing_event ) : ?>
		<?php include 'event-detail.php'; ?>
	<?php elseif ( $editing_event ) : ?>
		<!-- Edit Form -->
		<div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
			<h2><?php esc_html_e( 'Edit Event', 'remember' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'remember_event_action', 'remember_event_nonce' ); ?>
				<input type="hidden" name="remember_event_action" value="edit">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $editing_event->event_id ); ?>">
				
				<table class="form-table">
					<tr>
						<th><label for="event_name"><?php esc_html_e( 'Event Name', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="text" id="event_name" name="event_name" class="regular-text" value="<?php echo esc_attr( $editing_event->event_name ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="event_description"><?php esc_html_e( 'Public description', 'remember' ); ?></label></th>
						<td>
							<?php
							wp_editor(
								$editing_event->event_description,
								'event_description',
								array(
									'textarea_name' => 'event_description',
									'textarea_rows' => 8,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Shown to everyone browsing the event.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="attendee_details"><?php esc_html_e( 'Attendee-only details', 'remember' ); ?></label></th>
						<td>
							<?php
							wp_editor(
								isset( $editing_event->attendee_details ) ? $editing_event->attendee_details : '',
								'attendee_details',
								array(
									'textarea_name' => 'attendee_details',
									'textarea_rows' => 8,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Logistics and private instructions. Visible only to members with an accepted application for this event.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="location_id"><?php esc_html_e( 'Location', 'remember' ); ?></label></th>
						<td>
							<select id="location_id" name="location_id" class="regular-text">
								<option value=""><?php esc_html_e( '-- Select Location --', 'remember' ); ?></option>
								<?php foreach ( $locations as $location ) : ?>
									<option value="<?php echo esc_attr( $location->location_id ); ?>" <?php selected( $editing_event->location_id, $location->location_id ); ?>><?php echo esc_html( $location->location_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="start_date"><?php esc_html_e( 'Start Date', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="date" id="start_date" name="start_date" class="regular-text" value="<?php echo esc_attr( $editing_event->start_date ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="end_date"><?php esc_html_e( 'End Date', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="date" id="end_date" name="end_date" class="regular-text" value="<?php echo esc_attr( $editing_event->end_date ); ?>" required></td>
					</tr>
					<?php remember_render_registration_window_fields( $editing_event ); ?>
					<?php remember_render_private_invite_fields( $editing_event ); ?>
					<tr>
						<th><label for="status"><?php esc_html_e( 'Status', 'remember' ); ?></label></th>
						<td>
							<select id="status" name="status" class="regular-text">
								<option value="draft" <?php selected( $editing_event->status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'remember' ); ?></option>
								<option value="open" <?php selected( $editing_event->status, 'open' ); ?>><?php esc_html_e( 'Open', 'remember' ); ?></option>
								<option value="closed" <?php selected( $editing_event->status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'remember' ); ?></option>
								<option value="completed" <?php selected( $editing_event->status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'remember' ); ?></option>
								<option value="cancelled" <?php selected( $editing_event->status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'remember' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Event Roles', 'remember' ); ?></label></th>
						<td>
							<p class="description"><?php esc_html_e( 'Enable roles for this event and define per-role subtotal pricing and capacity.', 'remember' ); ?></p>
							<?php if ( ! empty( $event_roles ) ) : ?>
								<table class="widefat striped" style="max-width: 900px; margin-top: 10px;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Enabled', 'remember' ); ?></th>
											<th><?php esc_html_e( 'Role', 'remember' ); ?></th>
											<th><?php esc_html_e( 'Subtotal Price', 'remember' ); ?></th>
											<th><?php esc_html_e( 'Capacity', 'remember' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $event_roles as $role ) : ?>
											<?php
											$existing_role = isset( $editing_event_roles_by_id[ $role->role_id ] ) ? $editing_event_roles_by_id[ $role->role_id ] : null;
											?>
											<tr>
												<td>
													<input type="checkbox" name="event_roles_config[<?php echo esc_attr( $role->role_id ); ?>][enabled]" value="1" <?php checked( isset( $editing_event_role_ids ) && in_array( $role->role_id, $editing_event_role_ids, true ) ); ?>>
												</td>
												<td><?php echo esc_html( $role->role_name ); ?></td>
												<td>
													<input type="number" step="0.01" min="0" class="small-text" name="event_roles_config[<?php echo esc_attr( $role->role_id ); ?>][cost]" value="<?php echo esc_attr( $existing_role ? number_format( (float) $existing_role->cost, 2, '.', '' ) : '0.00' ); ?>">
												</td>
												<td>
													<input type="number" min="1" class="small-text" name="event_roles_config[<?php echo esc_attr( $role->role_id ); ?>][max_participants]" value="<?php echo esc_attr( $existing_role && null !== $existing_role->max_participants ? $existing_role->max_participants : '' ); ?>">
													<span class="description"><?php esc_html_e( 'Blank = unlimited', 'remember' ); ?></span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No event roles available. Create event roles in the Roles section first.', 'remember' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Event Add-ons', 'remember' ); ?></label></th>
						<td>
							<p class="description"><?php esc_html_e( 'Define optional add-ons for this event (uniforms, sessions, services, etc.). Prices are subtotal amounts. Set max quantity per event role below each add-on (0 hides it from that role).', 'remember' ); ?></p>
							<?php if ( ! $has_catalog_products ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'No active products found. Add products first under reMember > Products.', 'remember' ); ?></p>
							<?php endif; ?>
							<div class="remember-addon-rows" data-addon-target="edit">
								<?php if ( ! empty( $editing_addons ) ) : ?>
									<?php foreach ( $editing_addons as $index => $addon ) : ?>
										<?php
										$selected_catalog_product_id = 0;
										if ( isset( $catalog_products_by_name[ $addon->merchandise_name ] ) ) {
											$selected_catalog_product_id = absint( $catalog_products_by_name[ $addon->merchandise_name ]->product_id );
										}
										$limits_by_role = Remember_Addon_Role_Limits::get_for_merchandise_by_role_id(
											absint( $addon->merchandise_id ),
											absint( $editing_event->event_id )
										);
										?>
										<div class="remember-addon-row" style="border:1px solid #ccd0d4; padding:10px; margin:8px 0;">
											<input type="hidden" name="event_addons[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $addon->merchandise_id ); ?>">
											<p>
												<select class="regular-text remember-addon-product-select" name="event_addons[<?php echo esc_attr( $index ); ?>][product_id]" required>
													<option value=""><?php esc_html_e( '-- Select Product --', 'remember' ); ?></option>
													<?php foreach ( $catalog_products as $catalog_product ) : ?>
														<?php
														$catalog_dp = isset( $catalog_product->default_price ) ? (float) $catalog_product->default_price : 0;
														?>
														<option value="<?php echo esc_attr( $catalog_product->product_id ); ?>" data-default-price="<?php echo esc_attr( number_format( $catalog_dp, 2, '.', '' ) ); ?>" <?php selected( $selected_catalog_product_id, $catalog_product->product_id ); ?>>
															<?php echo esc_html( $catalog_product->product_name ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</p>
											<p>
												<label><?php esc_html_e( 'Subtotal Price', 'remember' ); ?> <input type="number" step="0.01" min="0" class="small-text" name="event_addons[<?php echo esc_attr( $index ); ?>][cost]" value="<?php echo esc_attr( number_format( (float) $addon->cost, 2, '.', '' ) ); ?>"></label>
												<label style="margin-left: 15px;"><input type="checkbox" name="event_addons[<?php echo esc_attr( $index ); ?>][is_available]" value="1" <?php checked( (int) $addon->is_available, 1 ); ?>> <?php esc_html_e( 'Available', 'remember' ); ?></label>
												<button type="button" class="button button-link-delete remember-remove-addon"><?php esc_html_e( 'Remove', 'remember' ); ?></button>
											</p>
											<?php remember_render_addon_role_limits( $index, $event_roles, $limits_by_role ); ?>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<button type="button" class="button remember-add-addon" data-addon-target="edit" <?php disabled( ! $has_catalog_products ); ?>><?php esc_html_e( 'Add Add-on', 'remember' ); ?></button>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Agreements', 'remember' ); ?></label></th>
						<td>
							<?php remember_render_event_agreements_field( absint( $editing_event->event_id ) ); ?>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Update Event', 'remember' ); ?>">
				</p>
			</form>
		</div>
	<?php else : ?>
		<!-- Add Form -->
		<div id="remember-add-event" style="display:none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
			<h2><?php esc_html_e( 'Add New Event', 'remember' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'remember_event_action', 'remember_event_nonce' ); ?>
				<input type="hidden" name="remember_event_action" value="add">
				
				<table class="form-table">
					<tr>
						<th><label for="event_name"><?php esc_html_e( 'Event Name', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="text" id="event_name" name="event_name" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="event_description"><?php esc_html_e( 'Public description', 'remember' ); ?></label></th>
						<td>
							<?php
							wp_editor(
								'',
								'event_description_new',
								array(
									'textarea_name' => 'event_description',
									'textarea_rows' => 8,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Shown to everyone browsing the event.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="attendee_details_new"><?php esc_html_e( 'Attendee-only details', 'remember' ); ?></label></th>
						<td>
							<?php
							wp_editor(
								'',
								'attendee_details_new',
								array(
									'textarea_name' => 'attendee_details',
									'textarea_rows' => 8,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Logistics and private instructions. Visible only to members with an accepted application for this event.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="location_id"><?php esc_html_e( 'Location', 'remember' ); ?></label></th>
						<td>
							<select id="location_id" name="location_id" class="regular-text">
								<option value=""><?php esc_html_e( '-- Select Location --', 'remember' ); ?></option>
								<?php foreach ( $locations as $location ) : ?>
									<option value="<?php echo esc_attr( $location->location_id ); ?>"><?php echo esc_html( $location->location_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="start_date"><?php esc_html_e( 'Start Date', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="date" id="start_date" name="start_date" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="end_date"><?php esc_html_e( 'End Date', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="date" id="end_date" name="end_date" class="regular-text" required></td>
					</tr>
					<?php remember_render_registration_window_fields(); ?>
					<?php remember_render_private_invite_fields(); ?>
					<tr>
						<th><label for="status"><?php esc_html_e( 'Status', 'remember' ); ?></label></th>
						<td>
							<select id="status" name="status" class="regular-text">
								<option value="draft"><?php esc_html_e( 'Draft', 'remember' ); ?></option>
								<option value="open"><?php esc_html_e( 'Open', 'remember' ); ?></option>
								<option value="closed"><?php esc_html_e( 'Closed', 'remember' ); ?></option>
								<option value="completed"><?php esc_html_e( 'Completed', 'remember' ); ?></option>
								<option value="cancelled"><?php esc_html_e( 'Cancelled', 'remember' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Event Roles', 'remember' ); ?></label></th>
						<td>
							<p class="description"><?php esc_html_e( 'Enable roles for this event and define per-role subtotal pricing and capacity.', 'remember' ); ?></p>
							<?php if ( ! empty( $event_roles ) ) : ?>
								<table class="widefat striped" style="max-width: 900px; margin-top: 10px;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Enabled', 'remember' ); ?></th>
											<th><?php esc_html_e( 'Role', 'remember' ); ?></th>
											<th><?php esc_html_e( 'Subtotal Price', 'remember' ); ?></th>
											<th><?php esc_html_e( 'Capacity', 'remember' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $event_roles as $role ) : ?>
											<tr>
												<td><input type="checkbox" name="event_roles_config[<?php echo esc_attr( $role->role_id ); ?>][enabled]" value="1"></td>
												<td><?php echo esc_html( $role->role_name ); ?></td>
												<td><input type="number" step="0.01" min="0" class="small-text" name="event_roles_config[<?php echo esc_attr( $role->role_id ); ?>][cost]" value="0.00"></td>
												<td>
													<input type="number" min="1" class="small-text" name="event_roles_config[<?php echo esc_attr( $role->role_id ); ?>][max_participants]" value="">
													<span class="description"><?php esc_html_e( 'Blank = unlimited', 'remember' ); ?></span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No event roles available. Create event roles in the Roles section first.', 'remember' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Event Add-ons', 'remember' ); ?></label></th>
						<td>
							<p class="description"><?php esc_html_e( 'Select from your product catalog, set subtotal pricing, and set max quantity per event role (0 hides the add-on for that role).', 'remember' ); ?></p>
							<?php if ( ! $has_catalog_products ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'No active products found. Add products first under reMember > Products.', 'remember' ); ?></p>
							<?php endif; ?>
							<div class="remember-addon-rows" data-addon-target="add"></div>
							<button type="button" class="button remember-add-addon" data-addon-target="add" <?php disabled( ! $has_catalog_products ); ?>><?php esc_html_e( 'Add Add-on', 'remember' ); ?></button>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Agreements', 'remember' ); ?></label></th>
						<td>
							<?php remember_render_event_agreements_field( 0 ); ?>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Event', 'remember' ); ?>">
					<button type="button" class="button" onclick="document.getElementById('remember-add-event').style.display='none'; document.querySelector('.page-title-action').style.display='inline-block';"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
				</p>
			</form>
		</div>

		<!-- Events List -->
		<?php if ( ! empty( $events ) ) : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
					<th class="column-name"><?php esc_html_e( 'Event Name', 'remember' ); ?></th>
					<th class="column-location"><?php esc_html_e( 'Location', 'remember' ); ?></th>
					<th class="column-dates"><?php esc_html_e( 'Dates', 'remember' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'remember' ); ?></th>
					<th class="column-type"><?php esc_html_e( 'Type', 'remember' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
			</tr>
		</thead>
		<tbody>
				<?php foreach ( $events as $event ) : 
					$location = $event->location_id ? $location_model->get( $event->location_id ) : null;
					$status_labels = array(
						'draft'     => __( 'Draft', 'remember' ),
						'open'      => __( 'Open', 'remember' ),
						'closed'    => __( 'Closed', 'remember' ),
						'completed' => __( 'Completed', 'remember' ),
						'cancelled' => __( 'Cancelled', 'remember' ),
					);
					$status_colors = array(
						'draft'     => '#72777c',
						'open'      => '#46b450',
						'closed'    => '#dc3232',
						'completed' => '#00a0d2',
						'cancelled' => '#dc3232',
					);
				?>
					<tr>
						<td class="column-name"><strong><?php echo esc_html( $event->event_name ); ?></strong></td>
						<td class="column-location"><?php echo $location ? esc_html( $location->location_name ) : '<span class="description">—</span>'; ?></td>
						<td class="column-dates">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) ) ); ?>
							<?php if ( $event->start_date !== $event->end_date ) : ?>
								<br><span class="description"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) ) ); ?></span>
							<?php endif; ?>
							<?php $registration_summary = Remember_Event::registration_window_summary( $event ); ?>
							<?php if ( $registration_summary ) : ?>
								<br><span class="description"><?php echo esc_html( $registration_summary ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-status">
							<span style="color: <?php echo esc_attr( $status_colors[ $event->status ] ); ?>;">
								<?php echo esc_html( $status_labels[ $event->status ] ); ?>
							</span>
						</td>
						<td class="column-type">
							<?php if ( $event->is_private ) : ?>
								<?php
								$invite_count = isset( $invite_counts[ (int) $event->event_id ] ) ? (int) $invite_counts[ (int) $event->event_id ] : 0;
								?>
								<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'Private', 'remember' ); ?>"></span>
								<?php esc_html_e( 'Private', 'remember' ); ?>
								<span class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of invited members */
											_n( '· %d invited', '· %d invited', $invite_count, 'remember' ),
											$invite_count
										)
									);
									?>
								</span>
							<?php else : ?>
								<span class="dashicons dashicons-unlock" title="<?php esc_attr_e( 'Public', 'remember' ); ?>"></span> <?php esc_html_e( 'Public', 'remember' ); ?>
							<?php endif; ?>
						</td>
						<td class="column-actions">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&view=' . $event->event_id ) ); ?>"><?php esc_html_e( 'View', 'remember' ); ?></a>
							<?php if ( current_user_can( 'remember_update_events' ) ) : ?>
								| <a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&edit=' . $event->event_id ) ); ?>"><?php esc_html_e( 'Edit', 'remember' ); ?></a>
							<?php endif; ?>
							<?php if ( current_user_can( 'remember_delete_events' ) ) : ?>
								| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=remember-events&delete=' . $event->event_id ), 'remember_event_action', 'remember_event_nonce' ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this event?', 'remember' ); ?>');"><?php esc_html_e( 'Delete', 'remember' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
		</tbody>
	</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No events found. Add your first event above.', 'remember' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
	// wp_json_encode() is a valid JS string literal; do not wrap in extra quotes (apostrophes in product names break "'...'+parse" otherwise).
	var productOptionsHtml = <?php
	$options = '<option value="">' . esc_html__( '-- Select Product --', 'remember' ) . '</option>';
	foreach ( $catalog_products as $catalog_product ) {
		$catalog_dp = isset( $catalog_product->default_price ) ? (float) $catalog_product->default_price : 0;
		$options   .= '<option value="' . esc_attr( $catalog_product->product_id ) . '" data-default-price="' . esc_attr( number_format( $catalog_dp, 2, '.', '' ) ) . '">' . esc_html( $catalog_product->product_name ) . '</option>';
	}
	echo wp_json_encode( $options );
	?>;

	var eventRoleLimitRows = <?php
	$role_limit_js = '';
	foreach ( $event_roles as $role ) {
		$role_limit_js .= '<tr><td>' . esc_html( $role->role_name ) . '</td><td><input type="number" min="0" class="small-text" name="event_addons[__INDEX__][role_limits][' . absint( $role->role_id ) . ']" value="1"></td></tr>';
	}
	echo wp_json_encode( $role_limit_js );
	?>;

	function buildAddonRoleLimits(index) {
		if (!eventRoleLimitRows) {
			return '<p class="description"><?php echo esc_js( __( 'Add event roles above to configure per-role add-on limits.', 'remember' ) ); ?></p>';
		}
		var body = String(eventRoleLimitRows).split('__INDEX__').join(String(index));
		return '' +
			'<div class="remember-addon-role-limits" style="margin-top:10px;">' +
				'<p class="description" style="margin:0 0 6px;"><?php echo esc_js( __( 'Max quantity per event role. Use 0 to hide this add-on from that role.', 'remember' ) ); ?></p>' +
				'<table class="widefat striped" style="max-width:36rem;"><thead><tr><th><?php echo esc_js( __( 'Event role', 'remember' ) ); ?></th><th style="width:8rem;"><?php echo esc_js( __( 'Max qty', 'remember' ) ); ?></th></tr></thead><tbody>' +
				body +
				'</tbody></table>' +
			'</div>';
	}

	function buildAddonRow(index) {
		return '' +
			'<div class="remember-addon-row" style="border:1px solid #ccd0d4; padding:10px; margin:8px 0;">' +
				'<input type="hidden" name="event_addons[' + index + '][id]" value="">' +
				'<p><select class="regular-text remember-addon-product-select" name="event_addons[' + index + '][product_id]" required>' + productOptionsHtml + '</select></p>' +
				'<p>' +
					'<label><?php echo esc_js( __( 'Subtotal Price', 'remember' ) ); ?> <input type="number" step="0.01" min="0" class="small-text" name="event_addons[' + index + '][cost]" value="0.00"></label>' +
					'<label style="margin-left: 15px;"><input type="checkbox" name="event_addons[' + index + '][is_available]" value="1" checked> <?php echo esc_js( __( 'Available', 'remember' ) ); ?></label>' +
					'<button type="button" class="button button-link-delete remember-remove-addon"><?php echo esc_js( __( 'Remove', 'remember' ) ); ?></button>' +
				'</p>' +
				buildAddonRoleLimits(index) +
			'</div>';
	}

	$('.remember-add-addon').on('click', function() {
		var target = $(this).data('addon-target');
		var $container = $('.remember-addon-rows[data-addon-target="' + target + '"]');
		var index = $container.find('.remember-addon-row').length;
		$container.append(buildAddonRow(index));
	});

	$(document).on('click', '.remember-remove-addon', function() {
		$(this).closest('.remember-addon-row').remove();
	});

	function rememberApplyCatalogDefaultPrice($select) {
		var $opt = $select.find('option:selected');
		var dp = $opt.attr('data-default-price');
		if (typeof dp !== 'undefined' && dp !== null && dp !== '') {
			var n = parseFloat(dp);
			if (!isNaN(n)) {
				$select.closest('.remember-addon-row').find('input[name*="[cost]"]').val(n.toFixed(2));
			}
		}
	}

	$(document).on('change', '.remember-addon-product-select', function() {
		rememberApplyCatalogDefaultPrice($(this));
	});
});
</script>
