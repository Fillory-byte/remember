<?php
/**
 * Application detail view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-messaging.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-template.php';
$subtotal_disclaimer = Remember_Billing_Messaging::get_subtotal_disclaimer();

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

<div class="remember-application-detail">
	<!-- Header Section -->
	<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
		<div style="display: flex; align-items: center; gap: 20px; justify-content: space-between;">
			<div style="flex: 1;">
				<h2 style="margin: 0 0 10px 0; font-size: 24px;">
					<?php esc_html_e( 'Application', 'remember' ); ?> #<?php echo esc_html( $viewing_application->application_id ); ?>
					<span style="color: <?php echo esc_attr( $status_colors[ $viewing_application->status ] ); ?>; font-size: 14px; font-weight: normal; margin-left: 10px;">
						<?php echo esc_html( $status_labels[ $viewing_application->status ] ); ?>
					</span>
				</h2>
				<div style="color: #666; margin-top: 5px; line-height: 1.8;">
					<?php if ( $viewing_user ) : ?>
						<p style="margin: 5px 0;">
							<strong><?php esc_html_e( 'Member:', 'remember' ); ?></strong>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $viewing_application->member_id ) ); ?>">
								<?php echo esc_html( $viewing_user->display_name ); ?>
							</a>
							<?php if ( $viewing_user->user_email ) : ?>
								<span style="color: #999;">(<?php echo esc_html( $viewing_user->user_email ); ?>)</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $viewing_event ) : ?>
						<p style="margin: 5px 0;">
							<strong><?php esc_html_e( 'Event:', 'remember' ); ?></strong>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&view=' . $viewing_event->event_id ) ); ?>">
								<?php echo esc_html( $viewing_event->event_name ); ?>
							</a>
							<?php if ( $viewing_event->start_date ) : ?>
								<span style="color: #999;">
									(<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $viewing_event->start_date ) ) ); ?>
									<?php if ( $viewing_event->start_date !== $viewing_event->end_date ) : ?>
										- <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $viewing_event->end_date ) ) ); ?>
									<?php endif; ?>)
								</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $viewing_event_role ) : ?>
						<p style="margin: 5px 0;">
							<strong><?php esc_html_e( 'Role:', 'remember' ); ?></strong>
							<?php echo esc_html( $viewing_event_role->role_name ); ?>
							<?php if ( $viewing_event_role->cost > 0 ) : ?>
								<span style="color: #999;">($<?php echo esc_html( number_format( $viewing_event_role->cost, 2 ) ); ?> <?php esc_html_e( 'subtotal', 'remember' ); ?>)</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( isset( $viewing_location ) && $viewing_location ) : ?>
						<p style="margin: 5px 0;">
							<strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-locations&view=' . $viewing_location->location_id ) ); ?>">
								<?php echo esc_html( $viewing_location->location_name ); ?>
							</a>
						</p>
					<?php endif; ?>
				<p style="margin: 5px 0;">
					<strong><?php esc_html_e( 'Billing:', 'remember' ); ?></strong>
					<?php if ( current_user_can( 'remember_read_billing' ) ) : ?>
					<span style="color: <?php echo esc_attr( isset( $viewing_billing_color ) ? $viewing_billing_color : '#72777c' ); ?>;">
						<?php
						if ( ! empty( $viewing_billing_html ) ) {
							echo wp_kses(
								$viewing_billing_html,
								array(
									'a' => array(
										'href'   => true,
										'target' => true,
										'rel'    => true,
										'style'  => true,
									),
								)
							);
						} else {
							echo esc_html( isset( $viewing_billing_label ) ? $viewing_billing_label : __( 'Not invoiced yet', 'remember' ) );
						}
						?>
					</span>
					<?php else : ?>
					<span class="description"><?php esc_html_e( 'Hidden', 'remember' ); ?></span>
					<?php endif; ?>
				</p>
				</div>
			</div>
			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-ticket.php';
			if ( Remember_Ticket::is_eligible( $viewing_application ) ) :
				$ticket_url = Remember_Ticket::get_ticket_url( $viewing_application->application_id );
				?>
				<div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
					<a class="button button-primary" href="<?php echo esc_url( $ticket_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View / Print Ticket', 'remember' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'download', '1', $ticket_url ) ); ?>"><?php esc_html_e( 'Download PDF', 'remember' ); ?></a>
					<?php if ( current_user_can( 'remember_update_applications' ) ) : ?>
					<form method="post" action="" style="margin: 0;">
						<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
						<input type="hidden" name="remember_application_action" value="email_ticket">
						<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Email Ticket', 'remember' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Email this ticket to the member now?', 'remember' ) ); ?>');">
					</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-agreements.php';
	$viewing_acceptances = Remember_Agreements::get_acceptances_for_application( (int) $viewing_application->application_id );
	if ( ! empty( $viewing_acceptances ) ) :
		$remember_acceptance_count = count( $viewing_acceptances );
		?>
		<details class="remember-application-detail-section remember-agreements-acknowledged" style="margin-bottom:20px;">
			<summary>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of agreements */
							_n( 'Agreements acknowledged (%d)', 'Agreements acknowledged (%d)', $remember_acceptance_count, 'remember' ),
							$remember_acceptance_count
						)
					);
					?>
				</strong>
			</summary>
			<div class="remember-agreements-acknowledged-body">
				<?php foreach ( $viewing_acceptances as $acc ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:3px;padding:12px 14px;margin:10px 0;background:#fff;">
						<p style="margin:0 0 6px;">
							<strong><?php echo esc_html( $acc->agreement_title ); ?></strong>
							<span class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: revision number */
										__( '(Revision %d)', 'remember' ),
										(int) $acc->revision_number
									)
								);
								?>
							</span>
						</p>
						<p style="margin:0 0 8px;color:#50575e;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: typed legal name, 2: datetime */
									__( 'Acknowledged by %1$s on %2$s', 'remember' ),
									$acc->typed_legal_name,
									date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $acc->accepted_at ) )
								)
							);
							?>
							<?php if ( ! empty( $acc->ip_address ) ) : ?>
								<span class="description"> · IP <?php echo esc_html( $acc->ip_address ); ?></span>
							<?php endif; ?>
						</p>
						<details>
							<summary><?php esc_html_e( 'View agreement text', 'remember' ); ?></summary>
							<div class="remember-richtext" style="margin-top:8px;"><?php echo wp_kses_post( wpautop( $acc->body ) ); ?></div>
						</details>
					</div>
				<?php endforeach; ?>
			</div>
		</details>
	<?php endif; ?>

	<?php
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-surveys.php';
	$viewing_survey_answers = Remember_Surveys::answers_for_application( (int) $viewing_application->application_id );
	if ( ! empty( $viewing_survey_answers ) ) :
		?>
		<div class="remember-application-detail-section" style="margin-bottom: 15px;">
			<h3><?php esc_html_e( 'Survey answers', 'remember' ); ?></h3>
			<?php foreach ( $viewing_survey_answers as $viewing_survey ) : ?>
				<?php if ( ! empty( $viewing_survey['title'] ) || ! empty( $viewing_survey['submitted_at'] ) ) : ?>
					<p class="description" style="margin: 0 0 8px;">
						<?php
						$remember_survey_meta = array();
						if ( ! empty( $viewing_survey['title'] ) ) {
							$remember_survey_meta[] = $viewing_survey['title'];
						}
						if ( ! empty( $viewing_survey['submitted_at'] ) ) {
							$remember_survey_meta[] = sprintf(
								/* translators: %s: date and time the survey was submitted */
								__( 'Submitted %s', 'remember' ),
								date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $viewing_survey['submitted_at'] ) )
							);
						}
						echo esc_html( implode( ' · ', $remember_survey_meta ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( empty( $viewing_survey['answers'] ) ) : ?>
					<p><?php esc_html_e( 'No answers were given.', 'remember' ); ?></p>
				<?php else : ?>
					<table class="form-table" style="margin: 0 0 12px;">
						<?php foreach ( $viewing_survey['answers'] as $viewing_answer ) : ?>
							<tr>
								<th style="width: 220px;"><?php echo esc_html( $viewing_answer['label'] ); ?></th>
								<td>
									<?php if ( '' === $viewing_answer['value'] ) : ?>
										<span class="description"><?php esc_html_e( 'No answer', 'remember' ); ?></span>
									<?php else : ?>
										<?php echo wp_kses_post( nl2br( esc_html( $viewing_answer['value'] ) ) ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- Application Information Grid -->
	<div class="remember-application-detail-grid">
		<!-- Status History Section -->
		<div class="remember-application-detail-section">
			<h3><?php esc_html_e( 'Status History', 'remember' ); ?></h3>
			<table class="form-table" style="margin: 0;">
				<tr>
					<th style="width: 140px;"><?php esc_html_e( 'Applied:', 'remember' ); ?></th>
					<td>
						<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $viewing_application->applied_at ) ) ); ?>
					</td>
				</tr>
				<?php if ( $viewing_application->processed_at ) : ?>
					<tr>
						<th><?php esc_html_e( 'Processed:', 'remember' ); ?></th>
						<td>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $viewing_application->processed_at ) ) ); ?>
							<?php if ( isset( $processed_by_user ) && $processed_by_user ) : ?>
								<span style="color: #999;">
									<?php esc_html_e( 'by', 'remember' ); ?> <?php echo esc_html( $processed_by_user->display_name ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $viewing_application->notes ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Notes:', 'remember' ); ?></th>
						<td>
							<div style="color: #555; line-height: 1.6;">
								<?php echo wp_kses_post( nl2br( esc_html( $viewing_application->notes ) ) ); ?>
							</div>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<!-- Itemized Charges Section -->
		<div class="remember-application-detail-section">
			<h3><?php esc_html_e( 'Itemized Charges (Subtotal)', 'remember' ); ?></h3>
			<table class="wp-list-table widefat striped" style="margin: 0;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Item', 'remember' ); ?></th>
						<th style="width: 110px;"><?php esc_html_e( 'Qty', 'remember' ); ?></th>
						<th style="width: 140px;"><?php esc_html_e( 'Unit Price', 'remember' ); ?></th>
						<th style="width: 140px;"><?php esc_html_e( 'Line Subtotal', 'remember' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>
							<?php
							$role_label = $viewing_event_role ? $viewing_event_role->role_name : __( 'Role', 'remember' );
							echo esc_html( $role_label );
							?>
						</td>
						<td>1</td>
						<td>$<?php echo esc_html( number_format( $viewing_event_role ? (float) $viewing_event_role->cost : 0, 2 ) ); ?></td>
						<td>$<?php echo esc_html( number_format( $viewing_event_role ? (float) $viewing_event_role->cost : 0, 2 ) ); ?></td>
					</tr>
					<?php if ( ! empty( $viewing_application_addons ) ) : ?>
						<?php foreach ( $viewing_application_addons as $addon_item ) : ?>
							<tr>
								<td>
									<?php echo esc_html( ! empty( $addon_item->merchandise_name ) ? $addon_item->merchandise_name : __( 'Add-on', 'remember' ) ); ?>
								</td>
								<td><?php echo esc_html( intval( $addon_item->quantity ) ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $addon_item->unit_cost, 2 ) ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $addon_item->total_cost, 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php
					$itemized_subtotal = $viewing_event_role ? (float) $viewing_event_role->cost : 0;
					if ( ! empty( $viewing_application_addons ) ) {
						foreach ( $viewing_application_addons as $addon_item ) {
							$itemized_subtotal += (float) $addon_item->total_cost;
						}
					}
					?>
					<tr>
						<th colspan="3" style="text-align:right;"><?php esc_html_e( 'Subtotal', 'remember' ); ?></th>
						<th>$<?php echo esc_html( number_format( $itemized_subtotal, 2 ) ); ?></th>
					</tr>
				</tbody>
			</table>
			<p class="description" style="margin-top: 8px;"><?php echo esc_html( $subtotal_disclaimer ); ?></p>
		</div>

		<!-- Payment Information Section -->
		<?php if ( $viewing_payment && current_user_can( 'remember_read_billing' ) ) : ?>
			<div class="remember-application-detail-section">
				<h3><?php esc_html_e( 'Payment Information', 'remember' ); ?></h3>
				<table class="form-table" style="margin: 0;">
					<tr>
						<th style="width: 140px;"><?php esc_html_e( 'Subtotal Amount:', 'remember' ); ?></th>
						<td>
							<strong>$<?php echo esc_html( number_format( $viewing_payment->total_amount, 2 ) ); ?></strong>
							<p class="description" style="margin: 6px 0 0 0;">
								<?php echo esc_html( $subtotal_disclaimer ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Payment/Credit:', 'remember' ); ?></th>
						<td>
							$<?php echo esc_html( number_format( Remember_Billing_Template::get_payment_and_credit_total( $viewing_payment ), 2 ) ); ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Amount Due:', 'remember' ); ?></th>
						<td>
							<strong style="color: <?php echo $viewing_payment->amount_due > 0 ? '#dc3232' : '#46b450'; ?>;">
								$<?php echo esc_html( number_format( $viewing_payment->amount_due, 2 ) ); ?>
							</strong>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status:', 'remember' ); ?></th>
						<td>
							<?php
							$payment_status_labels = array(
								'pending'  => __( 'Pending', 'remember' ),
								'partial'  => __( 'Partial', 'remember' ),
								'paid'     => __( 'Paid', 'remember' ),
								'refunded' => __( 'Refunded', 'remember' ),
								'cancelled' => __( 'Cancelled', 'remember' ),
							);
							$payment_status_colors = array(
								'pending'  => '#f0b849',
								'partial'  => '#00a0d2',
								'paid'     => '#46b450',
								'refunded' => '#72777c',
								'cancelled' => '#dc3232',
							);
							?>
							<span style="color: <?php echo esc_attr( $payment_status_colors[ $viewing_payment->payment_status ] ); ?>;">
								<?php echo esc_html( $payment_status_labels[ $viewing_payment->payment_status ] ); ?>
							</span>
						</td>
					</tr>
					<?php if ( $viewing_payment->payment_date ) : ?>
						<tr>
							<th><?php esc_html_e( 'Payment Date:', 'remember' ); ?></th>
							<td>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $viewing_payment->payment_date ) ) ); ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $viewing_payment->payment_method ) : ?>
						<tr>
							<th><?php esc_html_e( 'Payment Method:', 'remember' ); ?></th>
							<td>
								<?php echo esc_html( ucfirst( $viewing_payment->payment_method ) ); ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( $viewing_payment->transaction_id ) : ?>
						<tr>
							<th><?php esc_html_e( 'Transaction ID:', 'remember' ); ?></th>
							<td>
								<code><?php echo esc_html( $viewing_payment->transaction_id ); ?></code>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- Action Buttons -->
	<?php
	$remember_can_update_apps     = current_user_can( 'remember_update_applications' );
	$remember_can_allow_reapply   = in_array( $viewing_application->status, array( 'declined', 'cancelled' ), true )
		&& empty( $viewing_application->superseded_at )
		&& $remember_can_update_apps;
	$remember_show_active_actions = $remember_can_update_apps
		&& in_array( $viewing_application->status, array( 'pending', 'waitlisted', 'accepted' ), true );
	?>
	<?php if ( $remember_show_active_actions || $remember_can_allow_reapply || ( ! empty( $viewing_application->superseded_at ) && $remember_can_update_apps ) ) : ?>
		<?php
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-unwind.php';
		$decline_has_invoice = Remember_Billing_Unwind::has_invoice( $viewing_application->application_id );
		?>
		<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-top: 20px;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Actions', 'remember' ); ?></h3>

			<?php if ( ! empty( $viewing_application->superseded_at ) ) : ?>
				<p class="description" style="margin-top:0;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: datetime */
							__( 'Reapply was allowed on %s. This record is historical; the member may submit a new application.', 'remember' ),
							date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $viewing_application->superseded_at ) )
						)
					);
					?>
				</p>
			<?php elseif ( $remember_can_allow_reapply ) : ?>
				<form method="post" action="" style="margin: 0 0 16px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="allow_reapply">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<h4 style="margin: 0 0 8px;"><?php esc_html_e( 'Allow reapply', 'remember' ); ?></h4>
					<p class="description" style="margin-top: 0;">
						<?php esc_html_e( 'Lets this member apply again for the same event and role (and complete agreements again). Keeps this application for history.', 'remember' ); ?>
					</p>
					<p style="margin: 10px 0 0;">
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Allow reapply', 'remember' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Allow this member to reapply for this event and role?', 'remember' ) ); ?>');">
					</p>
				</form>
			<?php endif; ?>

			<?php if ( in_array( $viewing_application->status, array( 'declined', 'cancelled' ), true ) && $decline_has_invoice && $remember_can_update_apps && current_user_can( 'remember_update_billing' ) ) : ?>
				<form method="post" action="" style="margin: 0 0 16px; padding-bottom: 16px; border-bottom: 1px solid #ddd;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="unwind_billing">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<h4 style="margin: 0 0 8px;"><?php esc_html_e( 'Invoice / billing', 'remember' ); ?></h4>
					<p class="description" style="margin-top: 0;">
						<?php esc_html_e( 'Member cancels leave the invoice alone. Choose what should happen in the billing provider.', 'remember' ); ?>
					</p>
					<?php echo Remember_Billing_Unwind::render_action_radios( 'billing_action', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
					<p style="margin: 10px 0 0;">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Apply invoice action', 'remember' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Apply this invoice action in the billing provider?', 'remember' ) ); ?>');">
					</p>
				</form>
			<?php endif; ?>

			<?php if ( $remember_show_active_actions ) : ?>
			<?php if ( 'pending' === $viewing_application->status || 'waitlisted' === $viewing_application->status ) : ?>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="accept">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Accept Application', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Accept this application?', 'remember' ); ?>');">
				</form>
			<?php endif; ?>

			<?php if ( 'pending' === $viewing_application->status ) : ?>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="waitlist">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Move to Waitlist', 'remember' ); ?>">
				</form>
			<?php endif; ?>

			<?php if ( 'accepted' === $viewing_application->status ) : ?>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="reopen_pending">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Move to Pending', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Move this accepted application back to pending?', 'remember' ); ?>');">
				</form>
				<?php if ( current_user_can( 'remember_create_billing' ) ) : ?>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="reprocess_billing">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Reprocess Billing', 'remember' ); ?>">
				</form>
				<?php endif; ?>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="email_ticket_ready">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Resend ticket-ready email', 'remember' ); ?>">
				</form>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="email_ticket_paid">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Send paid-ticket email', 'remember' ); ?>">
				</form>
				<form method="post" action="" style="display: inline-block; margin-right: 10px;">
					<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
					<input type="hidden" name="remember_application_action" value="email_balance_due">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Send balance-due email', 'remember' ); ?>">
				</form>
				<?php if ( empty( $viewing_application->ticket_voided ) ) : ?>
					<form method="post" action="" style="display: inline-block; margin-right: 10px;">
						<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
						<input type="hidden" name="remember_application_action" value="void_ticket">
						<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Void Ticket', 'remember' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Mark this ticket VOID? The application stays accepted.', 'remember' ) ); ?>');">
					</form>
				<?php else : ?>
					<form method="post" action="" style="display: inline-block; margin-right: 10px;">
						<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
						<input type="hidden" name="remember_application_action" value="unvoid_ticket">
						<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Clear ticket VOID', 'remember' ); ?>">
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
				<?php wp_nonce_field( 'remember_application_action', 'remember_application_nonce' ); ?>
				<input type="hidden" name="remember_application_action" value="decline">
				<input type="hidden" name="application_id" value="<?php echo esc_attr( $viewing_application->application_id ); ?>">
				<h4 style="margin: 0 0 8px;"><?php esc_html_e( 'Decline application (admin)', 'remember' ); ?></h4>
				<p class="description" style="margin-top: 0;">
					<?php esc_html_e( 'Sets status to Declined, voids the admission ticket, and optionally updates the billing provider invoice.', 'remember' ); ?>
				</p>
				<?php echo Remember_Billing_Unwind::render_action_radios( 'billing_action', $decline_has_invoice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
				<p style="margin: 10px 0 0;">
					<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Decline Application', 'remember' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Decline this application as admin? Choose invoice handling above before confirming.', 'remember' ) ); ?>');">
				</p>
			</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
