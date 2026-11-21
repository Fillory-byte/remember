<?php
/**
 * Refund or credit checklist for a cancelled event (event detail screen).
 *
 * Expects $viewing_event and $cancellation_notices from events.php.
 *
 * @package    reMember
 * @subpackage reMember/admin/views/partials
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../../includes/utilities/class-remember-event-cancellation.php';
require_once plugin_dir_path( __FILE__ ) . '../../../includes/utilities/class-remember-ticket-renderer.php';

$cancel_event_id   = (int) $viewing_event->event_id;
$cancel_attendees  = Remember_Event_Cancellation::attendees( $cancel_event_id );
$cancel_progress   = Remember_Event_Cancellation::progress( $cancel_attendees );
$cancel_leavers    = Remember_Event_Cancellation::late_leavers( $viewing_event );
$cancel_see_money  = current_user_can( 'remember_read_billing' );
$cancel_can_bill   = current_user_can( 'remember_update_billing' );
$cancel_can_email  = current_user_can( 'remember_update_applications' );
$cancel_not_mailed = $cancel_progress['total'] - $cancel_progress['emailed'];
$cancel_date       = Remember_Event_Cancellation::cancelled_on_display( $viewing_event );
$cancel_reason     = isset( $viewing_event->cancellation_reason ) ? (string) $viewing_event->cancellation_reason : '';
$cancel_date_fmt   = get_option( 'date_format' );

if ( ! function_exists( 'remember_cancellation_button' ) ) {
	/**
	 * One-click form for a checklist action.
	 *
	 * @param int    $event_id       Event ID.
	 * @param int    $application_id Application ID (0 for event-wide actions).
	 * @param string $action         Action key.
	 * @param string $label          Button text.
	 * @param string $aria_label     Accessible label naming the attendee.
	 * @param string $confirm        Confirmation question, or ''.
	 * @param string $class          Button classes.
	 * @return void
	 */
	function remember_cancellation_button( $event_id, $application_id, $action, $label, $aria_label = '', $confirm = '', $class = 'button button-small' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&view=' . (int) $event_id . '#remember-event-cancellation' ) ); ?>" class="remember-cancellation-action">
			<?php wp_nonce_field( 'remember_event_cancellation', 'remember_event_cancellation_nonce' ); ?>
			<input type="hidden" name="remember_cancellation_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) $event_id ); ?>">
			<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) (int) $application_id ); ?>">
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"<?php echo '' !== $aria_label ? ' aria-label="' . esc_attr( $aria_label ) . '"' : ''; ?><?php echo '' !== $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
?>
<div id="remember-event-cancellation" class="remember-event-cancellation">
	<h3>
		<?php esc_html_e( 'Cancellation: refunds and credits', 'remember' ); ?>
		<?php if ( $cancel_progress['total'] > 0 ) : ?>
			<span class="remember-event-cancellation-progress">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: attendees finished, 2: attendees on the list */
						__( '%1$d of %2$d finished', 'remember' ),
						$cancel_progress['done'],
						$cancel_progress['total']
					)
				);
				?>
			</span>
		<?php endif; ?>
	</h3>

	<?php foreach ( (array) $cancellation_notices as $cancel_notice ) : ?>
		<div class="notice notice-<?php echo 'success' === $cancel_notice[0] ? 'success' : 'error'; ?> inline"><p><?php echo esc_html( $cancel_notice[1] ); ?></p></div>
	<?php endforeach; ?>

	<p>
		<?php
		echo esc_html(
			'' !== $cancel_date
				? sprintf(
					/* translators: %s: date */
					__( 'Cancelled on %s.', 'remember' ),
					$cancel_date
				)
				: __( 'No cancellation date is set. Edit the event to add one; it is printed on tickets.', 'remember' )
		);
		?>
		<?php if ( '' !== $cancel_reason ) : ?>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: cancellation reason */
					__( 'Reason on tickets: %s', 'remember' ),
					$cancel_reason
				)
			);
			?>
		<?php endif; ?>
	</p>
	<p class="description remember-event-cancellation-intro">
		<?php esc_html_e( 'Everyone below has a ticket that reads EVENT CANCELLED, which they can print or download as proof. reMember never refunds, credits or voids anything by itself. For each attendee who paid: choose Refund or Credit (it shows as pending), issue it in Xero or QuickBooks using the steps shown, then press "Mark refund issued" or "Mark credit issued". An invoice nobody has paid can be voided with its own button.', 'remember' ); ?>
	</p>

	<?php if ( empty( $cancel_attendees ) ) : ?>
		<p class="description"><?php esc_html_e( 'Nobody is on the checklist yet. Accepted attendees are added when the event is saved as Cancelled; if it was cancelled before this checklist existed, open Edit and save the event once.', 'remember' ); ?></p>
	<?php else : ?>
		<?php if ( $cancel_can_email ) : ?>
			<div class="remember-event-cancellation-email">
				<?php if ( $cancel_not_mailed > 0 ) : ?>
					<?php
					remember_cancellation_button(
						$cancel_event_id,
						0,
						'email_all',
						sprintf(
							/* translators: %d: number of attendees */
							_n( 'Email %d attendee not yet emailed', 'Email %d attendees not yet emailed', $cancel_not_mailed, 'remember' ),
							$cancel_not_mailed
						),
						'',
						sprintf(
							/* translators: %d: number of attendees */
							_n( 'Send the Event Cancelled email to %d attendee now?', 'Send the Event Cancelled email to %d attendees now?', $cancel_not_mailed, 'remember' ),
							$cancel_not_mailed
						),
						'button button-primary'
					);
					?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Everyone on the checklist has been emailed. Use Resend on an attendee to send theirs again.', 'remember' ); ?></p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Uses the Event Cancelled template under Settings → Notifications, with a link to each attendee\'s ticket.', 'remember' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<ul class="remember-cancellation-list<?php echo $cancel_see_money ? '' : ' remember-cancellation-list--no-money'; ?>">
			<?php foreach ( $cancel_attendees as $row ) : ?>
				<?php
				$row_id      = (int) $row->application_id;
				$row_name    = '' !== (string) $row->display_name ? (string) $row->display_name : __( 'Deleted member', 'remember' );
				$row_done    = ! empty( $row->cancellation_done_at );
				$row_choice  = (string) $row->cancellation_choice;
				$row_paid    = Remember_Event_Cancellation::has_paid( $row );
				$row_state   = Remember_Event_Cancellation::state_for( $row );
				$row_tone    = $cancel_see_money ? Remember_Event_Cancellation::state_tone( $row_state ) : ( $row_done ? 'success' : 'warning' );
				$row_steps   = in_array( $row_state, array( 'refund_pending', 'credit_pending' ), true ) ? Remember_Event_Cancellation::steps_for( $row ) : array();
				$row_done_by = ( $row_done && ! empty( $row->cancellation_done_by ) ) ? get_user_by( 'id', (int) $row->cancellation_done_by ) : null;
				$row_done_on = $row_done ? date_i18n( $cancel_date_fmt, strtotime( $row->cancellation_done_at ) ) : '';
				?>
				<li class="remember-cancellation-card is-<?php echo esc_attr( $row_tone ); ?>">
					<div class="remember-cancellation-who">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications&view=' . $row_id ) ); ?>"><strong><?php echo esc_html( $row_name ); ?></strong></a>
						<?php if ( ! empty( $row->user_email ) ) : ?>
							<span class="description"><?php echo esc_html( $row->user_email ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $row->role_name ) ) : ?>
							<span class="description"><?php echo esc_html( $row->role_name ); ?></span>
						<?php endif; ?>
						<?php if ( 'accepted' !== (string) $row->status ) : ?>
							<span class="description"><?php esc_html_e( 'Left after the cancellation; added by staff', 'remember' ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( $cancel_see_money ) : ?>
						<div class="remember-cancellation-money">
							<span class="remember-cancellation-label"><?php esc_html_e( 'Paid', 'remember' ); ?></span>
							<?php if ( empty( $row->payment_id ) ) : ?>
								<span class="description"><?php esc_html_e( 'No invoice', 'remember' ); ?></span>
							<?php else : ?>
								<span>
									<strong><?php echo esc_html( Remember_Ticket_Renderer::money( $row->amount_paid ) ); ?></strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: invoice total */
											__( 'of %s', 'remember' ),
											Remember_Ticket_Renderer::money( $row->total_amount )
										)
									);
									?>
								</span>
								<span class="description"><?php echo esc_html( Remember_Ticket_Renderer::payment_status_label( (string) $row->payment_status ) ); ?></span>
								<?php $row_invoice = Remember_Event_Cancellation::invoice_number_for( $row ); ?>
								<?php if ( '' !== $row_invoice ) : ?>
									<span class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: invoice number */
												__( 'Invoice %s', 'remember' ),
												$row_invoice
											)
										);
										?>
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<div class="remember-cancellation-state">
						<span class="remember-cancellation-label"><?php echo esc_html( $cancel_see_money ? __( 'Refund or credit', 'remember' ) : __( 'Checklist', 'remember' ) ); ?></span>
						<p class="remember-cancellation-status remember-tone-<?php echo esc_attr( $row_tone ); ?>">
							<strong>
								<?php
								if ( $cancel_see_money ) {
									echo esc_html( Remember_Event_Cancellation::state_label( $row_state ) );
								} else {
									echo esc_html( $row_done ? __( 'Finished', 'remember' ) : __( 'Not finished', 'remember' ) );
								}
								?>
							</strong>
						</p>

						<?php if ( $cancel_see_money ) : ?>
							<?php if ( 'choose' === $row_state ) : ?>
								<p class="description"><?php esc_html_e( 'Choose Refund or Credit. The steps for the billing system appear here once you choose.', 'remember' ); ?></p>
							<?php elseif ( $row_steps ) : ?>
								<p class="description"><?php echo esc_html( 'refund_pending' === $row_state ? __( 'To issue the refund:', 'remember' ) : __( 'To issue the credit:', 'remember' ) ); ?></p>
								<ol class="remember-event-cancellation-steps">
									<?php foreach ( $row_steps as $step ) : ?>
										<li><?php echo esc_html( $step ); ?></li>
									<?php endforeach; ?>
								</ol>
							<?php elseif ( 'void_pending' === $row_state ) : ?>
								<p class="description"><?php esc_html_e( 'Void the invoice so the member is not asked to pay, then mark them done. If the button cannot void it, void it in the billing system yourself and mark them done.', 'remember' ); ?></p>
							<?php elseif ( 'voided' === $row_state && ! $row_done ) : ?>
								<p class="description"><?php esc_html_e( 'Nothing is owed. Mark them done.', 'remember' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( $row_done ) : ?>
							<p class="description">
								<?php
								if ( 'refund' === $row_choice ) {
									$row_done_text = $row_done_by
										/* translators: 1: date, 2: staff name */
										? sprintf( __( 'Marked refund issued on %1$s by %2$s.', 'remember' ), $row_done_on, $row_done_by->display_name )
										/* translators: %s: date */
										: sprintf( __( 'Marked refund issued on %s.', 'remember' ), $row_done_on );
								} elseif ( 'credit' === $row_choice ) {
									$row_done_text = $row_done_by
										/* translators: 1: date, 2: staff name */
										? sprintf( __( 'Marked credit issued on %1$s by %2$s.', 'remember' ), $row_done_on, $row_done_by->display_name )
										/* translators: %s: date */
										: sprintf( __( 'Marked credit issued on %s.', 'remember' ), $row_done_on );
								} else {
									$row_done_text = $row_done_by
										/* translators: 1: date, 2: staff name */
										? sprintf( __( 'Marked done on %1$s by %2$s.', 'remember' ), $row_done_on, $row_done_by->display_name )
										/* translators: %s: date */
										: sprintf( __( 'Marked done on %s.', 'remember' ), $row_done_on );
								}
								echo esc_html( $row_done_text );
								?>
							</p>
						<?php endif; ?>

						<?php if ( $cancel_can_bill ) : ?>
							<div class="remember-cancellation-buttons">
								<?php if ( ! $row_done ) : ?>
									<?php if ( $row_paid && 'refund' !== $row_choice ) : ?>
										<?php
										remember_cancellation_button(
											$cancel_event_id,
											$row_id,
											'refund',
											'credit' === $row_choice ? __( 'Switch to refund', 'remember' ) : __( 'Refund', 'remember' ),
											/* translators: %s: member name */
											sprintf( __( 'Choose a refund for %s (pending until marked issued)', 'remember' ), $row_name )
										);
										?>
									<?php endif; ?>
									<?php if ( $row_paid && 'credit' !== $row_choice ) : ?>
										<?php
										remember_cancellation_button(
											$cancel_event_id,
											$row_id,
											'credit',
											'refund' === $row_choice ? __( 'Switch to credit', 'remember' ) : __( 'Credit', 'remember' ),
											/* translators: %s: member name */
											sprintf( __( 'Choose a credit for %s (pending until marked issued)', 'remember' ), $row_name )
										);
										?>
									<?php endif; ?>
									<?php if ( 'void_pending' === $row_state ) : ?>
										<?php
										remember_cancellation_button(
											$cancel_event_id,
											$row_id,
											'void_invoice',
											__( 'Void invoice', 'remember' ),
											/* translators: %s: member name */
											sprintf( __( 'Void the unpaid invoice for %s', 'remember' ), $row_name ),
											/* translators: %s: member name */
											sprintf( __( 'Void %s\'s unpaid invoice in the billing system now?', 'remember' ), $row_name )
										);
										?>
									<?php endif; ?>
									<?php if ( 'choose' !== $row_state ) : ?>
										<?php
										remember_cancellation_button(
											$cancel_event_id,
											$row_id,
											'done',
											Remember_Event_Cancellation::done_button_label( $row_state ),
											/* translators: 1: button text, 2: member name */
											sprintf( __( '%1$s for %2$s', 'remember' ), Remember_Event_Cancellation::done_button_label( $row_state ), $row_name ),
											'',
											'button button-small button-primary'
										);
										?>
									<?php endif; ?>
									<?php if ( '' !== $row_choice ) : ?>
										<?php
										remember_cancellation_button(
											$cancel_event_id,
											$row_id,
											'clear_choice',
											__( 'Clear choice', 'remember' ),
											/* translators: %s: member name */
											sprintf( __( 'Clear the refund or credit choice for %s', 'remember' ), $row_name ),
											'',
											'button-link'
										);
										?>
									<?php endif; ?>
								<?php else : ?>
									<?php
									remember_cancellation_button(
										$cancel_event_id,
										$row_id,
										'undone',
										__( 'Undo', 'remember' ),
										/* translators: %s: member name */
										sprintf( __( 'Undo: put %s back to not finished', 'remember' ), $row_name ),
										'',
										'button-link'
									);
									?>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="remember-cancellation-emailed">
						<span class="remember-cancellation-label"><?php esc_html_e( 'Cancellation email', 'remember' ); ?></span>
						<?php if ( ! empty( $row->cancellation_emailed_at ) ) : ?>
							<span class="remember-tone-success">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: date */
										__( 'Sent %s', 'remember' ),
										date_i18n( $cancel_date_fmt, strtotime( $row->cancellation_emailed_at ) )
									)
								);
								?>
							</span>
						<?php else : ?>
							<span class="remember-tone-warning"><?php esc_html_e( 'Not sent', 'remember' ); ?></span>
						<?php endif; ?>
						<?php if ( $cancel_can_email ) : ?>
							<?php
							remember_cancellation_button(
								$cancel_event_id,
								$row_id,
								'email_one',
								! empty( $row->cancellation_emailed_at ) ? __( 'Resend', 'remember' ) : __( 'Send', 'remember' ),
								/* translators: %s: member name */
								sprintf( __( 'Email the cancellation to %s', 'remember' ), $row_name ),
								'',
								'button-link'
							);
							?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $cancel_leavers ) ) : ?>
		<h4><?php esc_html_e( 'Left on or after the cancellation date', 'remember' ); ?></h4>
		<p class="description remember-event-cancellation-intro"><?php esc_html_e( 'These applications were declined or withdrawn on or after the cancellation date, so they are not on the checklist and their ticket still reads VOID. If one of them held a place when the event was cancelled, add them: their ticket will read EVENT CANCELLED and they join the checklist.', 'remember' ); ?></p>
		<ul class="remember-event-cancellation-leavers">
			<?php foreach ( $cancel_leavers as $leaver ) : ?>
				<?php $leaver_name = '' !== (string) $leaver->display_name ? (string) $leaver->display_name : __( 'Deleted member', 'remember' ); ?>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications&view=' . (int) $leaver->application_id ) ); ?>"><?php echo esc_html( $leaver_name ); ?></a>
					<span class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: Declined or Withdrawn, 2: date */
								__( '%1$s %2$s', 'remember' ),
								'declined' === (string) $leaver->status ? __( 'Declined', 'remember' ) : __( 'Withdrawn', 'remember' ),
								date_i18n( $cancel_date_fmt, strtotime( $leaver->processed_at ) )
							)
						);
						?>
					</span>
					<?php if ( $cancel_can_bill ) : ?>
						<?php
						remember_cancellation_button(
							$cancel_event_id,
							(int) $leaver->application_id,
							'add_late_leaver',
							__( 'Add to checklist', 'remember' ),
							/* translators: %s: member name */
							sprintf( __( 'Add %s to the cancellation checklist', 'remember' ), $leaver_name )
						);
						?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
