<?php
/**
 * Vetting case detail view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="remember-vetting-detail">
	<!-- Case Header -->
	<div class="remember-member-detail-card">
		<div class="remember-member-detail-header">
			<div class="remember-member-detail-header__meta">
				<div class="remember-vetting-detail-title">
					<h2>
						<?php esc_html_e( 'Vetting Case #', 'remember' ); echo esc_html( $viewing_vetting->vetting_id ); ?>
					</h2>
					<span class="remember-vetting-detail-badge" style="color: <?php echo esc_attr( $status_colors[ $viewing_vetting->status ] ); ?>; background: <?php echo esc_attr( $status_colors[ $viewing_vetting->status ] ); ?>20;">
						<?php echo esc_html( $status_labels[ $viewing_vetting->status ] ); ?>
					</span>
				</div>
				<div class="remember-vetting-detail-summary">
					<?php if ( $viewing_user ) : ?>
						<p class="remember-member-detail-contact">
							<span class="remember-member-detail-contact__item">
								<strong><?php esc_html_e( 'Member:', 'remember' ); ?></strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $viewing_vetting->member_id ) ); ?>">
									<?php echo esc_html( $viewing_user->display_name ); ?>
								</a>
							</span>
							<?php if ( ! empty( $viewing_user->user_email ) ) : ?>
								<span class="remember-member-detail-contact__item">
									<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
									<a href="mailto:<?php echo esc_attr( $viewing_user->user_email ); ?>"><?php echo esc_html( $viewing_user->user_email ); ?></a>
								</span>
							<?php endif; ?>
							<?php if ( $viewing_profile && ! empty( $viewing_profile->cell_phone ) ) : ?>
								<span class="remember-member-detail-contact__item">
									<span class="dashicons dashicons-phone" aria-hidden="true"></span>
									<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $viewing_profile->cell_phone ) ); ?>"><?php echo esc_html( $viewing_profile->cell_phone ); ?></a>
								</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<div class="remember-vetting-detail-meta">
						<?php if ( $viewing_vetter ) : ?>
							<span><strong><?php esc_html_e( 'Vetter:', 'remember' ); ?></strong> <?php echo esc_html( $viewing_vetter->display_name ); ?></span>
						<?php endif; ?>
						<span><strong><?php esc_html_e( 'Created:', 'remember' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $viewing_vetting->created_at ) ) ); ?></span>
						<?php if ( $viewing_vetting->scheduled_at ) :
							require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';
							$scheduled_display = Remember_Timezone::format_with_your_time( $viewing_vetting->scheduled_at, get_current_user_id(), true );
						?>
							<span><strong><?php esc_html_e( 'Scheduled:', 'remember' ); ?></strong> <?php echo esc_html( $scheduled_display ); ?></span>
						<?php endif; ?>
						<?php if ( 'completed' === $viewing_vetting->status ) : ?>
							<span>
								<strong><?php esc_html_e( 'Decision:', 'remember' ); ?></strong>
								<span style="color: <?php echo 'accepted' === $viewing_vetting->decision ? '#46b450' : '#dc3232'; ?>; font-weight: bold;">
									<?php echo esc_html( $decision_labels[ $viewing_vetting->decision ] ); ?>
								</span>
								<?php if ( $viewing_vetting->decision_date ) : ?>
									<span class="description">(<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $viewing_vetting->decision_date ) ) ); ?>)</span>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="remember-member-detail-header__actions remember-vetting-detail-status">
				<?php if ( 'completed' !== $viewing_vetting->status && $can_work_case ) : ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
						<input type="hidden" name="remember_vetting_action" value="update_status">
						<input type="hidden" name="vetting_id" value="<?php echo esc_attr( $viewing_vetting_id ); ?>">
						<label class="remember-vetting-detail-status__label" for="remember_vetting_case_status">
							<?php esc_html_e( 'Case Status', 'remember' ); ?>
						</label>
						<select id="remember_vetting_case_status" name="status" class="regular-text remember-vetting-detail-status__select" aria-describedby="remember_vetting_case_status_help">
							<?php foreach ( Remember_Vetting::manual_statuses() as $status ) : ?>
								<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $viewing_vetting->status, $status ); ?>>
									<?php echo esc_html( $status_labels[ $status ] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p id="remember_vetting_case_status_help" class="description"><?php esc_html_e( 'To complete the case, record a decision under Quick Actions.', 'remember' ); ?></p>
						<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Update', 'remember' ); ?>">
					</form>
				<?php else : ?>
					<div class="remember-vetting-detail-status__readonly">
						<div class="remember-vetting-detail-status__label"><?php esc_html_e( 'Case Status', 'remember' ); ?></div>
						<div style="color: <?php echo esc_attr( $status_colors[ $viewing_vetting->status ] ); ?>; font-weight: bold;">
							<?php echo esc_html( $status_labels[ $viewing_vetting->status ] ); ?>
						</div>
						<?php if ( 'completed' === $viewing_vetting->status ) : ?>
							<div class="description"><?php esc_html_e( 'Case completed', 'remember' ); ?></div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Quick Actions -->
	<div class="remember-member-detail-card">
		<h3 class="remember-vetting-detail-section-title"><?php esc_html_e( 'Quick Actions', 'remember' ); ?></h3>
		<?php if ( 'completed' !== $viewing_vetting->status && ! $can_work_case ) : ?>
			<p class="description">
				<?php
				if ( empty( $viewing_vetting->primary_vetter_id ) ) {
					esc_html_e( 'This case has no primary vetter yet. Once one is assigned, only the primary vetter, collaborators, and users with Access Settings can work it. Any vetter can add notes.', 'remember' );
				} else {
					esc_html_e( 'Only the primary vetter, collaborators, and users with Access Settings can work this case. Any vetter can add notes.', 'remember' );
				}
				?>
			</p>
		<?php endif; ?>
		<div class="remember-vetting-quick-actions">

			<?php if ( ( empty( $viewing_vetting->primary_vetter_id ) || 'pending' === $viewing_vetting->status ) && 'completed' !== $viewing_vetting->status && $can_assign_case ) : ?>
				<div class="remember-vetting-quick-actions__group">
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
						<input type="hidden" name="remember_vetting_action" value="assign">
						<input type="hidden" name="vetting_id" value="<?php echo esc_attr( $viewing_vetting_id ); ?>">
						<div class="remember-vetting-quick-actions__row">
							<select name="primary_vetter_id" class="remember-vetting-quick-actions__select" required>
								<option value=""><?php esc_html_e( '-- Assign Vetter --', 'remember' ); ?></option>
								<?php foreach ( $vetters as $v ) : ?>
									<option value="<?php echo esc_attr( $v->ID ); ?>" <?php selected( $viewing_vetting->primary_vetter_id, $v->ID ); ?>>
										<?php echo esc_html( $v->display_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Assign', 'remember' ); ?>">
						</div>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $can_work_case && ( 'pending' === $viewing_vetting->status || 'scheduled' === $viewing_vetting->status ) ) :
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';
				$org_tz_name = Remember_Timezone::get_organization_timezone_name();
				$current_user_id = get_current_user_id();
				$offset_hours = Remember_Timezone::get_timezone_offset_hours( $current_user_id );
				$example_msg = Remember_Timezone::get_example_conversion( $current_user_id );
			?>
				<div class="remember-vetting-quick-actions__group">
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
						<input type="hidden" name="remember_vetting_action" value="schedule">
						<input type="hidden" name="vetting_id" value="<?php echo esc_attr( $viewing_vetting_id ); ?>">
						<div class="remember-vetting-quick-actions__row">
							<input type="date" name="scheduled_date" value="<?php echo $viewing_vetting->scheduled_at ? esc_attr( date( 'Y-m-d', strtotime( $viewing_vetting->scheduled_at ) ) ) : ''; ?>" required>
							<select name="scheduled_hour" class="remember-vetting-quick-actions__time" required>
								<?php for ( $h = 1; $h <= 12; $h++ ) :
									$selected_hour = $viewing_vetting->scheduled_at ? (int) date( 'g', strtotime( $viewing_vetting->scheduled_at ) ) : '';
								?>
									<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $selected_hour, $h ); ?>>
										<?php echo esc_html( $h ); ?>
									</option>
								<?php endfor; ?>
							</select>
							<select name="scheduled_minute" class="remember-vetting-quick-actions__time" required>
								<?php
								$selected_minute = $viewing_vetting->scheduled_at ? (int) date( 'i', strtotime( $viewing_vetting->scheduled_at ) ) : 0;
								foreach ( array( 0, 15, 30, 45 ) as $min ) :
								?>
									<option value="<?php echo esc_attr( $min ); ?>" <?php selected( $selected_minute, $min ); ?>>
										<?php echo esc_html( str_pad( $min, 2, '0', STR_PAD_LEFT ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<select name="scheduled_ampm" class="remember-vetting-quick-actions__time" required>
								<?php $selected_ampm = $viewing_vetting->scheduled_at ? date( 'A', strtotime( $viewing_vetting->scheduled_at ) ) : 'AM'; ?>
								<option value="AM" <?php selected( $selected_ampm, 'AM' ); ?>>AM</option>
								<option value="PM" <?php selected( $selected_ampm, 'PM' ); ?>>PM</option>
							</select>
							<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Schedule', 'remember' ); ?>">
						</div>
						<div class="remember-vetting-quick-actions__help">
							<p>
								<strong><?php esc_html_e( 'System timezone:', 'remember' ); ?></strong> <?php echo esc_html( $org_tz_name ); ?>
								<?php if ( abs( $offset_hours ) >= 0.01 ) :
									$offset_sign = $offset_hours > 0 ? '+' : '';
									$offset_display = sprintf( '%s%.1f', $offset_sign, $offset_hours );
								?>
									<span>(<?php printf( esc_html__( 'Your timezone is %s hours', 'remember' ), esc_html( $offset_display ) ); ?>)</span>
								<?php endif; ?>
							</p>
							<p><em><?php echo esc_html( $example_msg ); ?></em></p>
						</div>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $can_work_case && ( 'pending' === $viewing_vetting->status || 'scheduled' === $viewing_vetting->status || 'in_progress' === $viewing_vetting->status ) ) : ?>
				<div class="remember-vetting-quick-actions__group">
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
						<input type="hidden" name="remember_vetting_action" value="complete">
						<input type="hidden" name="vetting_id" value="<?php echo esc_attr( $viewing_vetting_id ); ?>">
						<div class="remember-vetting-quick-actions__row">
							<select name="decision" class="remember-vetting-quick-actions__select" required>
								<option value=""><?php esc_html_e( '-- Decision --', 'remember' ); ?></option>
								<option value="accepted"><?php esc_html_e( 'Accepted', 'remember' ); ?></option>
								<option value="rejected"><?php esc_html_e( 'Rejected', 'remember' ); ?></option>
							</select>
							<input type="submit" class="button button-small button-primary" value="<?php esc_attr_e( 'Complete', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to complete this vetting case?', 'remember' ); ?>');">
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Member Applications -->
	<?php if ( ! empty( $viewing_applications ) ) : ?>
		<div class="remember-member-detail-card">
			<h3 class="remember-vetting-detail-section-title"><?php esc_html_e( 'Member Applications', 'remember' ); ?></h3>
			<div class="remember-table-scroll">
			<table class="wp-list-table widefat striped remember-responsive-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Role', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Status', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Applied', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $viewing_applications as $app ) : 
						$event = $event_model->get( $app->event_id );
						global $wpdb;
						$event_role = $wpdb->get_row( $wpdb->prepare(
							"SELECT er.*, r.role_name FROM {$wpdb->prefix}remember_event_roles er 
							JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
							WHERE er.event_role_id = %d",
							$app->event_role_id
						) );
						$app_status_labels = array(
							'pending'   => __( 'Pending', 'remember' ),
							'accepted'  => __( 'Accepted', 'remember' ),
							'declined'  => __( 'Declined', 'remember' ),
							'cancelled' => __( 'Cancelled', 'remember' ),
							'waitlisted' => __( 'Waitlisted', 'remember' ),
						);
						$app_status_colors = array(
							'pending'   => '#f0b849',
							'accepted'  => '#46b450',
							'declined'  => '#dc3232',
							'cancelled' => '#72777c',
							'waitlisted' => '#00a0d2',
						);
					?>
						<tr>
							<td data-label="<?php echo esc_attr__( 'Event', 'remember' ); ?>">
								<?php if ( $event ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events&view=' . $event->event_id ) ); ?>">
										<strong><?php echo esc_html( $event->event_name ); ?></strong>
									</a>
								<?php else : ?>
									<span class="description">—</span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr__( 'Role', 'remember' ); ?>"><?php echo $event_role ? esc_html( $event_role->role_name ) : '<span class="description">—</span>'; ?></td>
							<td data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
								<span style="color: <?php echo esc_attr( $app_status_colors[ $app->status ] ); ?>; font-weight: bold;">
									<?php echo esc_html( $app_status_labels[ $app->status ] ); ?>
								</span>
							</td>
							<td data-label="<?php echo esc_attr__( 'Applied', 'remember' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $app->applied_at ) ) ); ?></td>
							<td data-label="<?php echo esc_attr__( 'Actions', 'remember' ); ?>">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-applications&view=' . $app->application_id ) ); ?>">
									<?php esc_html_e( 'View', 'remember' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
	<?php endif; ?>

	<!-- Notes and Timeline -->
	<div class="remember-vetting-detail-grid">
		<!-- Notes Section -->
		<div class="remember-member-detail-card">
			<h3 class="remember-vetting-detail-section-title"><?php esc_html_e( 'Case Notes', 'remember' ); ?></h3>
			
			<!-- Add Note Form -->
			<?php if ( 'completed' !== $viewing_vetting->status && current_user_can( 'remember_update_vetting' ) ) : ?>
				<div style="margin-bottom: 15px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Add Note', 'remember' ); ?></h4>
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
						<input type="hidden" name="remember_vetting_action" value="add_note">
						<input type="hidden" name="vetting_id" value="<?php echo esc_attr( $viewing_vetting_id ); ?>">
						
					<textarea name="note_content" class="large-text" rows="3" required placeholder="<?php esc_attr_e( 'Enter your note...', 'remember' ); ?>" style="margin-bottom: 8px;"></textarea>
					<div style="display: flex; justify-content: space-between; align-items: center;">
						<label style="font-size: 12px;">
							<input type="checkbox" name="is_admin_only" value="1">
							<?php esc_html_e( 'Admin only', 'remember' ); ?>
						</label>
						<input type="submit" class="button button-small button-primary" value="<?php esc_attr_e( 'Add Note', 'remember' ); ?>">
					</div>
					</form>
				</div>
			<?php endif; ?>
			
			<!-- Notes Timeline -->
			<?php if ( ! empty( $viewing_notes ) ) : ?>
				<div style="border-top: 1px solid #ddd; padding-top: 12px;">
					<?php foreach ( $viewing_notes as $note ) : 
						$note_author = get_user_by( 'ID', $note->member_id );
					?>
						<div style="margin-bottom: 12px; padding: 10px; background: #f9f9f9; border-left: 3px solid #2271b1; border-radius: 2px;">
							<div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
								<strong style="font-size: 13px;"><?php echo $note_author ? esc_html( $note_author->display_name ) : esc_html__( 'Unknown', 'remember' ); ?></strong>
								<span class="description" style="font-size: 11px; color: #666;">
									<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $note->created_at ) ) ); ?>
									<?php if ( $note->is_admin_only ) : ?>
										<span style="color: #dc3232;">(<?php esc_html_e( 'Admin', 'remember' ); ?>)</span>
									<?php endif; ?>
								</span>
							</div>
							<div style="color: #333; font-size: 13px; line-height: 1.5;">
								<?php echo wp_kses_post( nl2br( esc_html( $note->note_content ) ) ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="description" style="font-size: 12px; margin-top: 10px;"><?php esc_html_e( 'No notes yet.', 'remember' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Collaborators Section -->
		<div class="remember-member-detail-card">
			<h3 class="remember-vetting-detail-section-title"><?php esc_html_e( 'Collaborators', 'remember' ); ?></h3>
			
			<!-- Add Collaborator Form -->
			<?php if ( 'completed' !== $viewing_vetting->status && $can_work_case ) : ?>
				<div style="margin-bottom: 15px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Add Collaborator', 'remember' ); ?></h4>
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_vetting_action', 'remember_vetting_nonce' ); ?>
						<input type="hidden" name="remember_vetting_action" value="add_collaborator">
						<input type="hidden" name="vetting_id" value="<?php echo esc_attr( $viewing_vetting_id ); ?>">
						
						<div class="remember-vetting-collaborator-add">
							<select name="collaborator_id" required>
								<option value=""><?php esc_html_e( '-- Select --', 'remember' ); ?></option>
								<?php foreach ( $vetters as $v ) : 
									// Check if already a collaborator
									$is_collaborator = false;
									foreach ( $viewing_collaborators as $collab ) {
										if ( $collab->member_id == $v->ID ) {
											$is_collaborator = true;
											break;
										}
									}
									if ( ! $is_collaborator && $v->ID != $viewing_vetting->primary_vetter_id ) :
								?>
									<option value="<?php echo esc_attr( $v->ID ); ?>">
										<?php echo esc_html( $v->display_name ); ?>
									</option>
								<?php 
									endif;
								endforeach; ?>
							</select>
							<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Add', 'remember' ); ?>">
						</div>
					</form>
				</div>
			<?php endif; ?>
			
			<!-- Collaborators List -->
			<?php if ( ! empty( $viewing_collaborators ) ) : ?>
				<ul style="list-style: none; padding: 0; margin: 0;">
					<?php foreach ( $viewing_collaborators as $collab ) : 
						$collab_user = get_user_by( 'ID', $collab->member_id );
						$inviter = get_user_by( 'ID', $collab->invited_by );
						$collab_status_labels = array(
							'pending'  => __( 'Pending', 'remember' ),
							'accepted' => __( 'Accepted', 'remember' ),
							'declined' => __( 'Declined', 'remember' ),
						);
					?>
						<li style="padding: 8px; margin-bottom: 6px; background: #f9f9f9; border-radius: 3px; font-size: 12px;">
							<strong style="font-size: 13px;"><?php echo $collab_user ? esc_html( $collab_user->display_name ) : esc_html__( 'Unknown', 'remember' ); ?></strong>
							<div style="color: #666; margin-top: 3px;">
								<?php echo esc_html( $collab_status_labels[ $collab->status ] ); ?>
								<?php if ( $inviter ) : ?>
									<span style="color: #999;"> • <?php echo esc_html( $inviter->display_name ); ?></span>
								<?php endif; ?>
								<br>
								<span style="font-size: 11px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $collab->invited_at ) ) ); ?></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="description" style="font-size: 12px; margin-top: 10px;"><?php esc_html_e( 'No collaborators yet.', 'remember' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
