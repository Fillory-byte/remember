<?php
/**
 * Application form template
 *
 * @package    reMember
 * @subpackage reMember/public/partials
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-merchandise.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-vetting-workflow.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-messaging.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-agreements.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-surveys.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-event-invites.php';

// $event_id should be set by shortcode handler
if ( ! isset( $event_id ) ) {
	$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
}
$event_model = new Remember_Event();
$application_model = new Remember_Application();
$merchandise_model = new Remember_Merchandise();
$member_id      = get_current_user_id();
$member_model   = new Remember_Member();
$member_row     = $member_model->get( $member_id );
$subtotal_disclaimer = Remember_Billing_Messaging::get_subtotal_disclaimer();

// Handle form submission
$submission_success = false;
$submission_error = '';
// True when the member can correct the error, so the form is shown again with what they entered.
$keep_form = false;

if ( isset( $_POST['remember_apply_action'] ) && check_admin_referer( 'remember_apply_action', 'remember_apply_nonce' ) ) {
	if ( ! Remember_Vetting_Workflow::member_can_apply( $member_row ) ) {
		$submission_error = __( 'Member is not yet vetted.', 'remember' );
	} else {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	$event_role_id = isset( $_POST['event_role_id'] ) ? absint( $_POST['event_role_id'] ) : 0;

	if ( $event_id > 0 && $event_role_id > 0 ) {
		$apply_event = $event_model->get( $event_id );
		if ( $apply_event && ! Remember_Event_Invites::member_can_apply( $apply_event, $member_id ) ) {
			$registration_block = __( 'This event is by invitation only.', 'remember' );
		} else {
			$registration_block = $apply_event ? Remember_Event::registration_block_reason( $apply_event ) : __( 'Please select an event and role.', 'remember' );
		}
		if ( $registration_block ) {
			$submission_error = $registration_block;
		} else {
		// Check if application already exists
		$existing = $application_model->get_existing_application( $event_id, $member_id, $event_role_id );
		if ( $existing ) {
			$submission_error = __( 'You have already applied for this event and role.', 'remember' );
			$keep_form        = true;
		} else {
			$survey_error = Remember_Surveys::validate_apply( $event_id, $event_role_id, $member_id );
			if ( $survey_error ) {
				$submission_error = $survey_error;
				$keep_form        = true;
			} else {
			$agreement_error = Remember_Agreements::validate_apply_acceptances( $event_id );
			if ( $agreement_error ) {
				$submission_error = $agreement_error;
				$keep_form        = true;
			} else {
				$currency_error = Remember_Profile_Audit::validate_application_confirmation(
					isset( $_POST['remember_profile_currency_confirm'] ) ? wp_unslash( $_POST['remember_profile_currency_confirm'] ) : '',
					$member_id
				);
				if ( $currency_error ) {
					$submission_error = $currency_error;
					$keep_form        = true;
				} else {
			$data = array(
				'event_id'      => $event_id,
				'member_id'     => $member_id,
				'event_role_id' => $event_role_id,
				'status'        => 'pending',
			);

			$is_first = Remember_Vetting_Workflow::is_first_application( $member_id );
			$new_application_id = $application_model->create( $data );

			if ( $new_application_id ) {
				Remember_Surveys::save_apply_response( $new_application_id, $event_id, $member_id, $event_role_id );
				Remember_Agreements::save_apply_acceptances( $new_application_id, $event_id );
				Remember_Profile_Audit::touch_updated( $member_id, $member_id );

				// Save selected add-ons for this application (role-aware max qty; 0 = not offered).
				if ( isset( $_POST['event_addons'] ) && is_array( $_POST['event_addons'] ) ) {
					global $wpdb;
					require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-addon-role-limits.php';
					$available_addons = array();
					foreach ( Remember_Addon_Role_Limits::get_available_addons_for_role( $event_id, $event_role_id ) as $addon ) {
						$available_addons[ absint( $addon->merchandise_id ) ] = $addon;
					}

					foreach ( $_POST['event_addons'] as $merchandise_id => $addon_data ) {
						$merchandise_id = absint( $merchandise_id );
						if ( $merchandise_id <= 0 || ! is_array( $addon_data ) || ! isset( $available_addons[ $merchandise_id ] ) ) {
							continue;
						}
						if ( empty( $addon_data['selected'] ) ) {
							continue;
						}

						$addon    = $available_addons[ $merchandise_id ];
						$quantity = Remember_Addon_Role_Limits::clamp_quantity(
							$merchandise_id,
							$event_role_id,
							isset( $addon_data['quantity'] ) ? absint( $addon_data['quantity'] ) : 1
						);
						if ( $quantity < 1 ) {
							continue;
						}

						$unit_cost  = floatval( $addon->cost );
						$total_cost = $unit_cost * $quantity;

						$wpdb->insert(
							$wpdb->prefix . 'remember_application_merchandise',
							array(
								'event_application_id' => $new_application_id,
								'merchandise_id'       => $merchandise_id,
								'quantity'             => $quantity,
								'unit_cost'            => $unit_cost,
								'total_cost'           => $total_cost,
								'created_at'           => current_time( 'mysql' ),
							),
							array( '%d', '%d', '%d', '%f', '%f', '%s' )
						);
					}
				}

				// Already vetted members are skipped. A first application opens a case only after that.
				Remember_Vetting_Workflow::open_automatic_case( $member_id, 'first_application', $is_first );

				$submission_success = true;
			} else {
				$submission_error = __( 'Failed to submit application. Please try again.', 'remember' );
				$keep_form        = true;
			}
				}
			}
			}
		}
		}
	} else {
		$submission_error = __( 'Please select an event and role.', 'remember' );
		$keep_form        = true;
	}
	}
}

// Get selected event if provided
$selected_event = null;
$invite_denied  = false;
if ( $event_id > 0 ) {
	$selected_event = $event_model->get( $event_id );
	if ( $selected_event && ! Remember_Event_Invites::member_can_apply( $selected_event, $member_id ) ) {
		$invite_denied  = Remember_Event_Invites::is_private( $selected_event );
		$selected_event = null;
		$event_id       = 0;
	}
}

// Get open events if no event is pre-selected. Hide events outside their registration window.
$open_events = array();
if ( ! $selected_event ) {
	foreach ( $event_model->get_by_status( 'open' ) as $open_event ) {
		if ( Remember_Event_Invites::member_can_apply( $open_event, $member_id ) && '' === Remember_Event::registration_block_reason( $open_event ) ) {
			$open_events[] = $open_event;
		}
	}
}
$registration_block = $selected_event ? Remember_Event::registration_block_reason( $selected_event ) : '';

// After a correctable error, hand the posted role and add-ons to the script and pre-render the survey answers.
$restore             = array(
	'roleId' => 0,
	'addons' => array(),
);
$restore_survey_html = '';
if ( $keep_form && $selected_event ) {
	$restore['roleId'] = isset( $_POST['event_role_id'] ) ? absint( $_POST['event_role_id'] ) : 0;
	if ( isset( $_POST['event_addons'] ) && is_array( $_POST['event_addons'] ) ) {
		foreach ( wp_unslash( $_POST['event_addons'] ) as $merchandise_id => $addon_data ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cast with absint().
			$merchandise_id = absint( $merchandise_id );
			if ( $merchandise_id > 0 && is_array( $addon_data ) && ! empty( $addon_data['selected'] ) ) {
				$restore['addons'][ $merchandise_id ] = max( 1, isset( $addon_data['quantity'] ) ? absint( $addon_data['quantity'] ) : 1 );
			}
		}
	}
	if ( $restore['roleId'] > 0 ) {
		$restore_survey_html = Remember_Surveys::render_apply_html( (int) $event_id, $restore['roleId'], true, $member_id );
	}
}
$restore['addons'] = (object) $restore['addons'];
?>

<?php if ( $submission_success ) : ?>
	<div class="remember-notice remember-success">
		<p><?php esc_html_e( 'Application submitted successfully! You will be notified when your application is reviewed.', 'remember' ); ?></p>
		<?php if ( ! Remember_Member::is_vetted_member( $member_row ) ) : ?>
			<p><?php esc_html_e( 'Your vetting is under way. Staff will review this application once your vetting is complete.', 'remember' ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( $subtotal_disclaimer ); ?></p>
		<p><a href="<?php echo esc_url( Remember_Profile_Audit::get_dashboard_url() ); ?>" class="remember-button remember-button-primary">
			<?php esc_html_e( 'Return to Dashboard', 'remember' ); ?>
		</a></p>
	</div>
<?php elseif ( ! empty( $submission_error ) && ! $keep_form ) : ?>
	<div class="remember-notice remember-error">
		<p><?php echo esc_html( $submission_error ); ?></p>
	</div>
<?php elseif ( ! empty( $invite_denied ) ) : ?>
	<div class="remember-notice remember-warning">
		<p><?php esc_html_e( 'This event is by invitation only.', 'remember' ); ?></p>
	</div>
<?php elseif ( ! Remember_Vetting_Workflow::member_can_apply( $member_row ) ) : ?>
	<div class="remember-notice remember-warning">
		<p><?php esc_html_e( 'Member is not yet vetted.', 'remember' ); ?></p>
	</div>
<?php elseif ( $registration_block ) : ?>
	<div class="remember-notice remember-warning">
		<p><?php echo esc_html( $registration_block ); ?></p>
	</div>
<?php else : ?>
	<div class="remember-apply-form">
		<?php if ( ! empty( $submission_error ) ) : ?>
			<div class="remember-notice remember-error">
				<p><?php echo esc_html( $submission_error ); ?></p>
			</div>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Apply for Event', 'remember' ); ?></h2>

		<?php if ( $selected_event ) : ?>
			<div class="remember-event-summary">
				<h3><?php echo esc_html( $selected_event->event_name ); ?></h3>
				<?php if ( ! empty( $selected_event->event_description ) ) : ?>
					<div class="remember-event-description">
						<?php echo wp_kses_post( wpautop( $selected_event->event_description ) ); ?>
					</div>
				<?php endif; ?>
				<p>
					<strong><?php esc_html_e( 'Dates:', 'remember' ); ?></strong>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $selected_event->start_date ) ) ); ?>
					<?php if ( $selected_event->start_date !== $selected_event->end_date ) : ?>
						- <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $selected_event->end_date ) ) ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="" class="remember-form">
			<?php wp_nonce_field( 'remember_apply_action', 'remember_apply_nonce' ); ?>
			<input type="hidden" name="remember_apply_action" value="submit">

			<div class="remember-notice" style="margin-bottom: 20px;">
				<p style="margin: 0;">
					<strong><?php esc_html_e( 'Billing note:', 'remember' ); ?></strong>
					<?php echo esc_html( $subtotal_disclaimer ); ?>
				</p>
			</div>

			<?php if ( ! $selected_event ) : ?>
				<div class="remember-form-group">
					<label for="event_id" class="remember-form-label">
						<?php esc_html_e( 'Event', 'remember' ); ?>
						<span class="remember-required">*</span>
					</label>
					<select id="event_id" name="event_id" class="remember-form-control" required>
						<option value=""><?php esc_html_e( '-- Select Event --', 'remember' ); ?></option>
						<?php foreach ( $open_events as $event ) : ?>
							<option value="<?php echo esc_attr( $event->event_id ); ?>">
								<?php echo esc_html( $event->event_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php else : ?>
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
			<?php endif; ?>

			<div class="remember-form-group">
				<label for="event_role_id" class="remember-form-label">
					<?php esc_html_e( 'Role', 'remember' ); ?>
					<span class="remember-required">*</span>
				</label>
				<select id="event_role_id" name="event_role_id" class="remember-form-control" required <?php echo $selected_event ? '' : 'disabled'; ?>>
					<option value=""><?php echo $selected_event ? esc_html__( '-- Select Role --', 'remember' ) : esc_html__( '-- Select Event First --', 'remember' ); ?></option>
				</select>
				<p class="remember-form-help">
					<?php esc_html_e( 'Select an event first to see available roles for that event.', 'remember' ); ?>
				</p>
			</div>

			<div class="remember-form-group">
				<label class="remember-form-label"><?php esc_html_e( 'Event Add-ons (optional)', 'remember' ); ?></label>
				<div id="remember-event-addons">
					<p class="remember-form-help"><?php esc_html_e( 'Select an event and role to see add-ons available for that role.', 'remember' ); ?></p>
				</div>
			</div>

			<div id="remember-event-survey" class="remember-form-group"<?php echo '' === $restore_survey_html ? ' hidden' : ''; ?>>
				<div id="remember-event-survey-fields"><?php echo $restore_survey_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Remember_Surveys::render_fields(). ?></div>
			</div>

			<div class="remember-form-group">
				<label class="remember-form-label"><?php esc_html_e( 'Agreements', 'remember' ); ?></label>
				<div id="remember-event-agreements">
					<?php if ( $selected_event ) : ?>
						<?php echo Remember_Agreements::render_apply_html( (int) $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
					<?php else : ?>
						<p class="remember-form-help"><?php esc_html_e( 'Select an event to see required agreements.', 'remember' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php Remember_Profile_Audit::render_confirm_field( 'remember_apply_profile_currency_confirm' ); ?>

			<div class="remember-form-group">
				<button type="submit" class="remember-button remember-button-primary" disabled>
					<?php esc_html_e( 'Submit Application', 'remember' ); ?>
				</button>
				<a href="<?php echo esc_url( Remember_Profile_Audit::get_dashboard_url() ); ?>" class="remember-button remember-button-secondary">
					<?php esc_html_e( 'Cancel', 'remember' ); ?>
				</a>
			</div>
		</form>
	</div>

	<script>
	jQuery(document).ready(function($) {
		var eventId = <?php echo $selected_event ? $event_id : '0'; ?>;
		var $roleSelect = $('#event_role_id');
		var $eventSelect = $('#event_id');
		var $addonsContainer = $('#remember-event-addons');
		var $agreementsContainer = $('#remember-event-agreements');
		var $surveyWrap = $('#remember-event-survey');
		var $surveyFields = $('#remember-event-survey-fields');
		var restore = <?php echo wp_json_encode( $restore ); ?>;
		var pendingAddons = restore.addons || {};

		function currentEventId() {
			var fromSelect = $eventSelect.val();
			if (fromSelect) {
				return fromSelect;
			}
			var hidden = $('input[name="event_id"]').val();
			return hidden || eventId || '';
		}

		function loadEventSurvey(selectedEventId, selectedRoleId) {
			if (!selectedEventId || !selectedRoleId) {
				$surveyFields.empty();
				$surveyWrap.prop('hidden', true);
				return;
			}
			$.ajax({
				url: typeof rememberPublic !== 'undefined' && rememberPublic.ajaxurl ? rememberPublic.ajaxurl : ajaxurl,
				type: 'POST',
				data: {
					action: 'remember_get_event_survey',
					event_id: selectedEventId,
					event_role_id: selectedRoleId,
					nonce: '<?php echo esc_js( wp_create_nonce( 'remember_get_event_survey' ) ); ?>'
				},
				success: function(response) {
					var html = response.success && response.data ? response.data.html : '';
					if (html) {
						$surveyFields.html(html);
						$surveyWrap.prop('hidden', false);
						if (window.rememberInitSurvey) {
							window.rememberInitSurvey($surveyFields);
						}
					} else {
						$surveyFields.empty();
						$surveyWrap.prop('hidden', true);
					}
				}
			});
		}

		function loadEventAgreements(selectedEventId) {
			if (!selectedEventId) {
				$agreementsContainer.html('<p class="remember-form-help"><?php echo esc_js( __( 'Select an event to see required agreements.', 'remember' ) ); ?></p>');
				return;
			}
			$agreementsContainer.html('<p class="remember-form-help"><?php echo esc_js( __( 'Loading agreements...', 'remember' ) ); ?></p>');
			$.ajax({
				url: typeof rememberPublic !== 'undefined' && rememberPublic.ajaxurl ? rememberPublic.ajaxurl : ajaxurl,
				type: 'POST',
				data: {
					action: 'remember_get_event_agreements',
					event_id: selectedEventId,
					nonce: '<?php echo esc_js( wp_create_nonce( 'remember_get_event_agreements' ) ); ?>'
				},
				success: function(response) {
					if (response.success && response.data && response.data.html) {
						$agreementsContainer.html(response.data.html);
					} else {
						$agreementsContainer.html('<p class="remember-form-help"><?php echo esc_js( __( 'No agreements are required for this event.', 'remember' ) ); ?></p>');
					}
				},
				error: function() {
					$agreementsContainer.html('<p class="remember-form-help"><?php echo esc_js( __( 'Error loading agreements.', 'remember' ) ); ?></p>');
				}
			});
		}

		// Load roles when event is selected or if pre-selected
		function loadEventRoles(selectedEventId) {
			if (!selectedEventId) {
				$roleSelect.html('<option value=""><?php esc_html_e( '-- Select Event First --', 'remember' ); ?></option>').prop('disabled', true);
				return;
			}

			$roleSelect.html('<option value=""><?php esc_html_e( 'Loading roles...', 'remember' ); ?></option>').prop('disabled', true);

			$.ajax({
				url: typeof rememberPublic !== 'undefined' && rememberPublic.ajaxurl ? rememberPublic.ajaxurl : ajaxurl,
				type: 'POST',
				data: {
					action: 'remember_get_event_roles',
					event_id: selectedEventId,
					nonce: '<?php echo wp_create_nonce( 'remember_get_event_roles' ); ?>'
				},
				success: function(response) {
					if (response.success && response.data && response.data.length > 0) {
						$roleSelect.empty().append($('<option>', { value: '' }).text(<?php echo wp_json_encode( __( '-- Select Role --', 'remember' ) ); ?>));
						$.each(response.data, function(index, role) {
							$roleSelect.append($('<option>', { value: String(role.event_role_id) }).text(role.role_name || ''));
						});
						$roleSelect.prop('disabled', false);
						restoreRole();
					} else {
						$roleSelect.html('<option value=""><?php esc_html_e( 'No roles available. You may not have any event roles assigned, or this event has no roles matching your assigned roles.', 'remember' ); ?></option>').prop('disabled', true);
					}
				},
				error: function() {
					$roleSelect.html('<option value=""><?php esc_html_e( 'Error loading roles', 'remember' ); ?></option>').prop('disabled', true);
				}
			});
		}

		// Re-select the role from a failed submit once. The survey for it is already rendered with the posted answers.
		function restoreRole() {
			var roleId = restore.roleId ? String(restore.roleId) : '';
			restore.roleId = 0;
			if (!roleId) {
				return;
			}
			var offered = $roleSelect.find('option').filter(function() {
				return this.value === roleId;
			}).length > 0;
			if (!offered) {
				pendingAddons = {};
				loadEventSurvey('', '');
				return;
			}
			$roleSelect.val(roleId);
			loadEventAddons(currentEventId(), roleId);
		}

		function renderAddons(addons) {
			if (!addons || addons.length === 0) {
				$addonsContainer.html('<p class="remember-form-help"><?php esc_html_e( 'No add-ons are available for this event.', 'remember' ); ?></p>');
				return;
			}

			$addonsContainer.empty();
			$.each(addons, function(index, addon) {
				var id = parseInt(addon.merchandise_id, 10);
				if (!id) {
					return;
				}
				var cost = Number(addon.cost);
				if (isNaN(cost)) {
					cost = 0;
				}
				var maxQty = parseInt(addon.max_quantity, 10);
				if (isNaN(maxQty) || maxQty < 1) {
					maxQty = 0;
				}

				var $item = $('<div>', {
					'class': 'remember-addon-item',
					css: { border: '1px solid #ddd', padding: '10px', marginBottom: '10px' }
				});

				var $label = $('<label>', { css: { display: 'block', marginBottom: '6px' } });
				$label.append($('<input>', {
					type: 'checkbox',
					'class': 'remember-addon-toggle',
					name: 'event_addons[' + id + '][selected]',
					value: '1',
					'data-addon-id': id
				}));
				$label.append(' ');
				$label.append($('<strong>').text(addon.merchandise_name || ''));
				$label.append(' - $' + cost.toFixed(2) + ' ' + <?php echo wp_json_encode( __( 'subtotal', 'remember' ) ); ?>);
				$item.append($label);

				if (addon.description) {
					$item.append($('<p>', {
						'class': 'remember-form-help',
						css: { margin: '0 0 8px 0' }
					}).text(addon.description));
				}

				var $qty = $('<input>', {
					type: 'number',
					'class': 'small-text remember-addon-qty',
					name: 'event_addons[' + id + '][quantity]',
					value: 1,
					min: 1,
					disabled: true
				});
				if (maxQty > 0) {
					$qty.attr('max', maxQty);
				}
				if (pendingAddons[id]) {
					var keptQty = parseInt(pendingAddons[id], 10) || 1;
					if (maxQty > 0 && keptQty > maxQty) {
						keptQty = maxQty;
					}
					$label.find('.remember-addon-toggle').prop('checked', true);
					$qty.val(keptQty).prop('disabled', false);
				}
				$item.append($('<label>').text(<?php echo wp_json_encode( __( 'Quantity', 'remember' ) ); ?> + ' ').append($qty));
				if (maxQty > 0) {
					$item.append($('<span>', {
						'class': 'remember-form-help',
						css: { marginLeft: '8px' }
					}).text(' (max ' + maxQty + ')'));
				}

				$addonsContainer.append($item);
			});
		}

		function loadEventAddons(selectedEventId, selectedRoleId) {
			if (!selectedEventId) {
				$addonsContainer.html('<p class="remember-form-help"><?php esc_html_e( 'Select an event and role to see add-ons available for that role.', 'remember' ); ?></p>');
				return;
			}
			if (!selectedRoleId) {
				$addonsContainer.html('<p class="remember-form-help"><?php esc_html_e( 'Select a role to see add-ons available for that role.', 'remember' ); ?></p>');
				return;
			}

			$addonsContainer.html('<p class="remember-form-help"><?php esc_html_e( 'Loading add-ons...', 'remember' ); ?></p>');

			$.ajax({
				url: typeof rememberPublic !== 'undefined' && rememberPublic.ajaxurl ? rememberPublic.ajaxurl : ajaxurl,
				type: 'POST',
				data: {
					action: 'remember_get_event_addons',
					event_id: selectedEventId,
					event_role_id: selectedRoleId,
					nonce: '<?php echo wp_create_nonce( 'remember_get_event_addons' ); ?>'
				},
				success: function(response) {
					if (response.success && response.data && response.data.length) {
						renderAddons(response.data);
					} else {
						$addonsContainer.html('<p class="remember-form-help"><?php esc_html_e( 'No add-ons are available for this role.', 'remember' ); ?></p>');
					}
					pendingAddons = {};
				},
				error: function() {
					$addonsContainer.html('<p class="remember-form-help"><?php esc_html_e( 'Error loading add-ons.', 'remember' ); ?></p>');
					pendingAddons = {};
				}
			});
		}

		// Load roles if event is pre-selected
		if (eventId > 0) {
			loadEventRoles(eventId);
			if (window.rememberInitSurvey) {
				window.rememberInitSurvey($surveyFields);
			}
		}

		// Load roles when event selection changes
		$eventSelect.on('change', function() {
			var selectedEventId = $(this).val();
			loadEventRoles(selectedEventId);
			loadEventAddons(selectedEventId, '');
			loadEventAgreements(selectedEventId);
			loadEventSurvey(selectedEventId, '');
		});

		$roleSelect.on('change', function() {
			loadEventAddons(currentEventId(), $(this).val());
			loadEventSurvey(currentEventId(), $(this).val());
		});

		$(document).on('change', '.remember-addon-toggle', function() {
			var addonId = $(this).data('addon-id');
			var $qtyInput = $('.remember-addon-qty[name="event_addons[' + addonId + '][quantity]"]');
			$qtyInput.prop('disabled', !$(this).is(':checked'));
		});
	});
	</script>
<?php endif; ?>
