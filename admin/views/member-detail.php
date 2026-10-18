<?php
/**
 * Member detail view (included from members.php)
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Variables are set in members.php: $view_member, $view_user, $view_profile, $view_roles, $view_payments, $billing_register
// Also: $view_social_media. Health and emergency data load through Remember_Sensitive_Profile.
// Check if editing
$is_editing = isset( $_GET['edit'] ) && $_GET['edit'] === '1';
$is_merged  = isset( $view_member->status ) && 'merged' === $view_member->status;
if ( $is_merged ) {
	$is_editing = false;
}
if ( $is_editing && ! current_user_can( 'remember_update_members' ) ) {
	wp_die( __( 'You do not have sufficient permissions to edit members.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
}
?>

<?php
$remember_print_stamp = date_i18n( get_option( 'date_format' ) );
$remember_print_name  = $view_user ? (string) $view_user->display_name : '';
$remember_member_number = ( $view_profile && ! empty( $view_profile->member_number ) )
	? (string) $view_profile->member_number
	: '';
$remember_can_print_confidential = current_user_can( 'remember_print_confidential' );
$remember_can_print_event        = current_user_can( 'remember_print_event_card' );
$remember_can_print_any          = $remember_can_print_confidential || $remember_can_print_event;
// Prefer the staff sheet when both are allowed; otherwise the one they hold.
$remember_default_print_mode = $remember_can_print_confidential
	? 'confidential'
	: ( $remember_can_print_event ? 'event' : 'denied' );
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-sensitive-profile.php';
// A confidential print loads health and emergency and is logged before the print dialog opens.
$remember_print_sensitive = ! empty( Remember_Sensitive_Profile::print_topics() );
?>
<div
	class="remember-member-detail"
	data-print-mode="<?php echo esc_attr( $remember_default_print_mode ); ?>"
	data-print-display-name="<?php echo esc_attr( $remember_print_name ); ?>"
	data-print-can-confidential="<?php echo $remember_can_print_confidential ? '1' : '0'; ?>"
	data-print-can-event="<?php echo $remember_can_print_event ? '1' : '0'; ?>"
	data-print-sensitive="<?php echo $remember_print_sensitive ? '1' : '0'; ?>"
	data-member-id="<?php echo esc_attr( (string) (int) $view_member_id ); ?>"
	<?php if ( $remember_print_sensitive ) : ?>
	data-print-nonce="<?php echo esc_attr( wp_create_nonce( Remember_Sensitive_Profile::NONCE ) ); ?>"
	data-print-error="<?php esc_attr_e( 'The confidential print could not be logged, so it was not opened. Reload the page and try again.', 'remember' ); ?>"
	<?php endif; ?>
>
<?php
/*
 * Print frame. A table's <thead> and <tfoot> are the only reliable way to repeat
 * a banner on every printed page — position: fixed is inconsistent across
 * browsers, and browsers silently drop repeats that are too tall, so the banners
 * are kept to a single line. On screen the frame is display:block and the banners
 * are hidden, so this wrapper changes nothing in the admin view. The frame is left
 * unindented on purpose: it brackets the whole view without re-indenting it.
 */
?>
<table class="remember-print-frame"><thead><tr><td>
	<div class="remember-print-banner remember-print-banner--top">
		<span class="remember-print-banner__mark"><?php esc_html_e( 'Confidential', 'remember' ); ?></span>
		<span class="remember-print-banner__note"><?php esc_html_e( 'Member record — do not post or redistribute', 'remember' ); ?></span>
	</div>
</td></tr></thead><tbody><tr><td>
	<p class="remember-print-denied-note" hidden>
		<?php esc_html_e( 'You do not have permission to print this member profile.', 'remember' ); ?>
	</p>
	<?php if ( $is_merged ) : ?>
		<?php
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-duplicates.php';
		$merged_into = absint( get_user_meta( $view_member_id, Remember_Profile_Duplicates::MERGED_META, true ) );
		?>
		<div class="notice notice-warning remember-no-print" style="margin: 0 0 16px;">
			<p>
				<?php esc_html_e( 'This profile was merged and can no longer log in.', 'remember' ); ?>
				<?php if ( $merged_into > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $merged_into ) ); ?>">
						<?php echo esc_html( sprintf( __( 'Open remaining profile (ID %d)', 'remember' ), $merged_into ) ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Member Header -->
	<div class="remember-member-detail-card">
		<div class="remember-member-detail-header">
			<div class="remember-member-detail-header__identity">
				<?php if ( $view_member->photo_url ) : ?>
					<img class="remember-member-detail-avatar" src="<?php echo esc_url( $view_member->photo_url ); ?>" alt="<?php echo esc_attr( $view_user->display_name ); ?>">
				<?php else : ?>
					<div class="remember-member-detail-avatar remember-member-detail-avatar--placeholder">
						<?php echo esc_html( strtoupper( substr( $view_user->display_name, 0, 1 ) ) ); ?>
					</div>
				<?php endif; ?>
				<div class="remember-member-detail-header__meta">
					<h2>
						<?php echo esc_html( $view_user->display_name ); ?>
						<span class="remember-member-detail-status" style="color: <?php echo esc_attr( $status_colors[ $view_member->status ] ); ?>;">
							<?php echo esc_html( $status_labels[ $view_member->status ] ); ?>
						</span>
					</h2>
					<?php if ( '' !== $remember_member_number ) : ?>
						<p class="remember-member-detail-number">
							<?php
							printf(
								/* translators: %s: assigned member number */
								esc_html__( 'Member #%s', 'remember' ),
								esc_html( $remember_member_number )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<?php
						require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-access-log.php';
						$remember_last_access = Remember_Access_Log::last_for_member( (int) $view_member_id );
						?>
						<p class="description remember-no-print">
							<?php
							if ( $remember_last_access ) {
								$remember_last_user = get_userdata( (int) $remember_last_access->user_id );
								$remember_last_name = $remember_last_user ? $remember_last_user->display_name : sprintf( /* translators: %d: user ID */ __( 'User %d', 'remember' ), (int) $remember_last_access->user_id );
								echo esc_html(
									sprintf(
										/* translators: 1: staff name, 2: date and time, 3: what was opened */
										__( 'Last sensitive access: %1$s, %2$s, %3$s.', 'remember' ),
										$remember_last_name,
										mysql2date( 'Y-m-d H:i', $remember_last_access->created_at ),
										Remember_Access_Log::label( $remember_last_access->access_what )
									)
								);
							} else {
								esc_html_e( 'No sensitive access recorded yet.', 'remember' );
							}
							?>
						</p>
					<?php endif; ?>
					<p class="remember-member-detail-contact">
						<?php if ( ! empty( $view_user->user_email ) ) : ?>
							<span class="remember-member-detail-contact__item">
								<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
								<a href="mailto:<?php echo esc_attr( $view_user->user_email ); ?>"><?php echo esc_html( $view_user->user_email ); ?></a>
							</span>
						<?php endif; ?>
						<?php if ( $view_profile && ! empty( $view_profile->cell_phone ) ) : ?>
							<span class="remember-member-detail-contact__item">
								<span class="dashicons dashicons-phone" aria-hidden="true"></span>
								<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $view_profile->cell_phone ) ); ?>"><?php echo esc_html( $view_profile->cell_phone ); ?></a>
							</span>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $view_roles ) ) : ?>
						<div class="remember-member-detail-roles">
							<?php foreach ( $view_roles as $member_role ) : ?>
								<span class="remember-member-detail-role <?php echo $member_role->is_event_role ? 'is-event' : 'is-system'; ?>">
									<?php echo esc_html( $member_role->role_name ); ?>
									<span class="remember-member-detail-role__scope">
										(<?php echo $member_role->is_event_role ? esc_html__( 'Event', 'remember' ) : esc_html__( 'System', 'remember' ); ?>)
									</span>
								</span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<div class="remember-print-event-only">
						<?php Remember_Profile_Questions::render_event_sheet_hero_rows( (int) $view_member_id ); ?>
					</div>
				</div>
			</div>
			<div class="remember-member-detail-header__actions remember-no-print">
				<?php if ( ! $is_editing && $remember_can_print_any ) : ?>
					<div class="remember-print-menu">
						<?php if ( $remember_can_print_confidential && $remember_can_print_event ) : ?>
							<button type="button" class="button remember-print-menu__toggle" aria-expanded="false" aria-haspopup="true" aria-controls="remember-print-menu-list">
								<span class="dashicons dashicons-printer" aria-hidden="true"></span>
								<?php esc_html_e( 'Print', 'remember' ); ?>
							</button>
							<ul class="remember-print-menu__list" id="remember-print-menu-list" hidden>
								<li>
									<button type="button" data-print-mode="confidential">
										<span class="remember-print-menu__label"><?php esc_html_e( 'Confidential profile', 'remember' ); ?></span>
										<span class="remember-print-menu__hint">
											<?php
											if ( current_user_can( 'remember_access_emergency_contact' ) ) {
												esc_html_e( 'Staff record, including emergency contact', 'remember' );
											} else {
												esc_html_e( 'Staff record of what you are allowed to see', 'remember' );
											}
											?>
										</span>
									</button>
								</li>
								<li>
									<button type="button" data-print-mode="event">
										<span class="remember-print-menu__label"><?php esc_html_e( 'Event card', 'remember' ); ?></span>
										<span class="remember-print-menu__hint"><?php esc_html_e( 'Photo, name, member number, opted-in custom fields, and interests — safe to post', 'remember' ); ?></span>
									</button>
								</li>
							</ul>
						<?php elseif ( $remember_can_print_confidential ) : ?>
							<button type="button" class="button remember-print-menu__direct" data-print-mode="confidential">
								<span class="dashicons dashicons-printer" aria-hidden="true"></span>
								<?php esc_html_e( 'Print confidential profile', 'remember' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button remember-print-menu__direct" data-print-mode="event">
								<span class="dashicons dashicons-printer" aria-hidden="true"></span>
								<?php esc_html_e( 'Print event card', 'remember' ); ?>
							</button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( ! $is_editing ) : ?>
					<?php if ( ! $is_merged && current_user_can( 'remember_update_members' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $view_member_id . '&edit=1' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Edit Profile', 'remember' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $remember_qb_show_sync_customer ) ) : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'remember_member_action', 'remember_member_nonce' ); ?>
							<input type="hidden" name="remember_member_action" value="sync_qb_customer" />
							<input type="hidden" name="member_id" value="<?php echo esc_attr( $view_member_id ); ?>" />
							<button type="submit" class="button" title="<?php esc_attr_e( 'Update the linked QuickBooks customer with this member’s name, email, phone, and billing address.', 'remember' ); ?>">
								<?php esc_html_e( 'Sync to QuickBooks', 'remember' ); ?>
							</button>
						</form>
					<?php endif; ?>
					<?php if ( ! empty( $remember_xero_show_sync_contact ) ) : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'remember_member_action', 'remember_member_nonce' ); ?>
							<input type="hidden" name="remember_member_action" value="sync_xero_contact" />
							<input type="hidden" name="member_id" value="<?php echo esc_attr( $view_member_id ); ?>" />
							<button type="submit" class="button" title="<?php esc_attr_e( 'Update the linked Xero contact with this member’s name, email, phone, and billing address.', 'remember' ); ?>">
								<?php esc_html_e( 'Sync to Xero', 'remember' ); ?>
							</button>
						</form>
					<?php endif; ?>
				<?php else : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $view_member_id ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'remember' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php Remember_Profile_Questions::render_event_sheet_continuation_rows( (int) $view_member_id ); ?>

	<?php
	// Health and emergency load only when staff open them; Remember_Sensitive_Profile logs each open.
	$remember_show_emergency = Remember_Sensitive_Profile::can_open( 'emergency' );
	$remember_show_health    = Remember_Sensitive_Profile::can_open( 'health' );
	// Emergency and health are capability-gated, so the row can hold one, two, or
	// three cards. Track the count and let the remaining cards claim the full width.
	$remember_detail_columns = 1 + ( $remember_show_emergency ? 1 : 0 ) + ( $remember_show_health ? 1 : 0 );
	?>
	<?php if ( $is_editing ) : ?>
		<!-- Edit Form -->
		<?php include 'member-edit.php'; ?>
	<?php else : ?>
		<!-- View Mode: Profile | Emergency | Dietary / Allergies / Medical, sized to whatever the viewer may see.
		     Interests and custom fields follow below so print keeps page 1 to the core profile. -->
		<div class="remember-member-detail-grid remember-member-detail-grid--three-cols remember-member-detail-grid--cols-<?php echo esc_attr( $remember_detail_columns ); ?>">
			<!-- Profile Information -->
			<div class="remember-member-detail-section remember-member-detail-profile-card">
				<h3><?php esc_html_e( 'Profile Information', 'remember' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Legal Name', 'remember' ); ?></th>
						<td>
							<?php
							$full_legal_name = trim( Remember_Import_Export::member_list_legal_name_line( $view_profile, (int) $view_member_id ) );
							if ( ! empty( $full_legal_name ) ) :
								echo esc_html( $full_legal_name );
							else :
								?>
								<span class="description"><?php esc_html_e( 'Not provided', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Member Number', 'remember' ); ?></th>
						<td>
							<?php if ( $view_profile && ! empty( $view_profile->member_number ) ) : ?>
								<?php echo esc_html( $view_profile->member_number ); ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Not assigned', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php
					require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
					$remember_created_display = Remember_Profile_Audit::format_datetime( $view_profile && isset( $view_profile->created_at ) ? $view_profile->created_at : '', get_current_user_id() );
					$remember_updated_display = Remember_Profile_Audit::format_datetime( $view_profile && isset( $view_profile->updated_at ) ? $view_profile->updated_at : '', get_current_user_id() );
					$remember_updated_by_name = Remember_Profile_Audit::format_updated_by_name( $view_profile && isset( $view_profile->updated_by ) ? $view_profile->updated_by : 0 );
					?>
					<tr>
						<th><?php esc_html_e( 'Profile created', 'remember' ); ?></th>
						<td>
							<?php echo '' !== $remember_created_display ? esc_html( $remember_created_display ) : '<span class="description">' . esc_html__( '—', 'remember' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Profile updated', 'remember' ); ?></th>
						<td>
							<?php echo '' !== $remember_updated_display ? esc_html( $remember_updated_display ) : '<span class="description">' . esc_html__( '—', 'remember' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Updated by', 'remember' ); ?></th>
						<td>
							<?php echo '' !== $remember_updated_by_name ? esc_html( $remember_updated_by_name ) : '<span class="description">' . esc_html__( '—', 'remember' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Address', 'remember' ); ?></th>
						<td>
							<?php if ( $view_profile && $view_profile->address_street ) : ?>
								<?php
								$address_parts = array_filter( array(
									$view_profile->address_street,
									$view_profile->address_city,
									$view_profile->address_state,
									$view_profile->address_postal,
								) );
								echo esc_html( implode( ', ', $address_parts ) );
								if ( $view_profile->address_country ) {
									echo ', ' . esc_html( $view_profile->address_country );
								}
								?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Not provided', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Cell Phone', 'remember' ); ?></th>
						<td>
							<?php echo $view_profile && $view_profile->cell_phone ? esc_html( $view_profile->cell_phone ) : '<span class="description">' . esc_html__( 'Not provided', 'remember' ) . '</span>'; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Time Zone', 'remember' ); ?></th>
						<td>
							<?php
							require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';
							$view_timezone = $view_user ? get_user_meta( $view_user->ID, 'timezone_string', true ) : '';
							echo ! empty( $view_timezone )
								? esc_html( Remember_Timezone::label( $view_timezone ) )
								: '<span class="description">' . esc_html__( 'Not provided', 'remember' ) . '</span>';
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Instant Messenger', 'remember' ); ?></th>
						<td>
							<?php if ( $view_profile && $view_profile->im_handle ) : ?>
								<?php
								require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
								echo esc_html( Remember_Im_Platforms::get_label( $view_profile->im_type ?: Remember_Im_Platforms::default_key() ) );
								?>: <?php echo esc_html( $view_profile->im_handle ); ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Not provided', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Clothing Sizes', 'remember' ); ?></th>
						<td>
							<?php
							require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
							$size_bits = array();
							if ( $view_profile && ! empty( $view_profile->shirt_size ) ) {
								$size_bits[] = sprintf( /* translators: %s: shirt size with available mapping */ __( 'Shirt: %s', 'remember' ), Remember_Clothing_Sizes::format_pair( 'shirt', $view_profile->shirt_size ) );
							}
							if ( $view_profile && ! empty( $view_profile->pants_size ) ) {
								$size_bits[] = sprintf( /* translators: %s: pants size with available mapping */ __( 'Pants: %s', 'remember' ), Remember_Clothing_Sizes::format_pair( 'pants', $view_profile->pants_size ) );
							}
							if ( $view_profile && ! empty( $view_profile->shoe_size ) ) {
								$size_bits[] = sprintf( /* translators: %s: shoe size with available mapping */ __( 'Shoes: %s', 'remember' ), Remember_Clothing_Sizes::format_pair( 'shoe', $view_profile->shoe_size ) );
							}
							echo $size_bits
								? esc_html( implode( ' · ', $size_bits ) )
								: '<span class="description">' . esc_html__( 'Not provided', 'remember' ) . '</span>';
							?>
						</td>
					</tr>
				</table>
			</div>

			<?php if ( $remember_show_emergency || $remember_show_health ) : ?>
			<!-- On screen this wrapper is display:contents, so emergency and health stay
			     independent grid columns. The confidential printout turns it into a flex
			     column so both stack tightly beside the profile card. -->
			<div class="remember-member-detail-sensitive-column">
			<?php endif; ?>

			<?php echo Remember_Sensitive_Profile::placeholder( (int) $view_member_id, 'emergency', 'view' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
			<?php echo Remember_Sensitive_Profile::placeholder( (int) $view_member_id, 'health', 'view' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>

			<?php if ( $remember_show_emergency || $remember_show_health ) : ?>
			</div><!-- /.remember-member-detail-sensitive-column -->
			<?php endif; ?>
		</div>

		<?php
		ob_start();
		$remember_has_custom_fields  = Remember_Profile_Questions::render_detail_rows( (int) $view_member_id );
		$remember_custom_fields_html = ob_get_clean();
		$remember_has_interests      = $view_profile && ! empty( $view_profile->interests );
		// With nothing to say here, keep the block off the printout rather than spending a page on it.
		$remember_secondary_classes  = ( $remember_has_interests || $remember_has_custom_fields ) ? '' : ' remember-no-print';
		// Event sheet prints remaining opted-in fields under the photo; this block is confidential-only.
		$remember_custom_fields_class = ' remember-print-confidential-only';
		?>
		<!-- Narrative and custom fields: full width on screen, page two of the printout. -->
		<div class="remember-member-detail-grid remember-member-detail-grid--secondary<?php echo esc_attr( $remember_secondary_classes ); ?>">
			<div class="remember-member-detail-section">
				<h3><?php esc_html_e( 'Interests', 'remember' ); ?></h3>
				<?php
				if ( $remember_has_interests ) {
					echo '<div class="remember-richtext">' . Remember_Profile_Fields::interests_html( $view_profile->interests ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- interests_html() keeps only b, i, em, u, ul, ol, li, p, and br.
				} else {
					echo '<p class="remember-member-detail-none">' . esc_html__( 'Not provided', 'remember' ) . '</p>';
				}
				?>
			</div>
			<?php if ( $remember_has_custom_fields ) : ?>
				<div class="remember-member-detail-section<?php echo esc_attr( $remember_custom_fields_class ); ?>">
					<h3><?php esc_html_e( 'Custom Fields', 'remember' ); ?></h3>
					<?php echo $remember_custom_fields_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_detail_rows() ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Profile notes (not vetting case notes). Confidential print only; stay off the event card. -->
		<div class="remember-member-detail-section remember-member-detail-section--full remember-print-confidential-only">
			<div class="remember-section-heading">
				<h3><?php esc_html_e( 'Profile Notes', 'remember' ); ?></h3>
			</div>
			<p class="description"><?php esc_html_e( 'These notes belong to the profile, not a vetting case. Unchecked notes are visible to the member.', 'remember' ); ?></p>
			<?php if ( current_user_can( 'remember_update_members' ) ) : ?>
				<div class="remember-no-print" style="margin: 12px 0 16px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<h4 style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Add Note', 'remember' ); ?></h4>
					<form method="post" action="">
						<?php wp_nonce_field( 'remember_member_action', 'remember_member_nonce' ); ?>
						<input type="hidden" name="remember_member_action" value="add_profile_note">
						<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $view_member_id ); ?>">
						<textarea name="note_content" class="large-text" rows="3" required placeholder="<?php esc_attr_e( 'Enter a profile note…', 'remember' ); ?>" style="margin-bottom: 8px;"></textarea>
						<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
							<label style="font-size: 12px;">
								<input type="checkbox" name="is_admin_only" value="1">
								<?php esc_html_e( 'Private to admin', 'remember' ); ?>
							</label>
							<input type="submit" class="button button-small button-primary" value="<?php esc_attr_e( 'Add Note', 'remember' ); ?>">
						</div>
					</form>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $view_profile_notes ) ) : ?>
				<?php foreach ( $view_profile_notes as $p_note ) : ?>
					<?php $p_note_author = get_user_by( 'ID', $p_note->author_id ); ?>
					<div style="margin-bottom: 12px; padding: 10px; background: #f9f9f9; border-left: 3px solid <?php echo ! empty( $p_note->is_admin_only ) ? '#dc3232' : '#2271b1'; ?>; border-radius: 2px;">
						<div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px; gap: 8px; flex-wrap: wrap;">
							<strong style="font-size: 13px;"><?php echo $p_note_author ? esc_html( $p_note_author->display_name ) : esc_html__( 'Unknown', 'remember' ); ?></strong>
							<span class="description" style="font-size: 11px; color: #666;">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $p_note->created_at ) ) ); ?>
								<?php if ( ! empty( $p_note->is_admin_only ) ) : ?>
									<span style="color: #dc3232;">(<?php esc_html_e( 'Private to admin', 'remember' ); ?>)</span>
								<?php else : ?>
									<span>(<?php esc_html_e( 'Visible to member', 'remember' ); ?>)</span>
								<?php endif; ?>
							</span>
						</div>
						<div style="color: #333; font-size: 13px; line-height: 1.5;">
							<?php echo wp_kses_post( nl2br( esc_html( $p_note->note_content ) ) ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No profile notes yet.', 'remember' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Vetting Cases (Full Width) -->
		<?php if ( isset( $view_vetting_cases ) && current_user_can( 'remember_read_vetting' ) ) : ?>
			<div class="remember-member-detail-section remember-member-detail-section--full remember-no-print">
				<div class="remember-section-heading">
					<h3><?php esc_html_e( 'Vetting Cases', 'remember' ); ?></h3>
					<?php if ( current_user_can( 'remember_create_vetting' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting&member_id=' . $view_member_id ) ); ?>" class="button button-small">
							<?php esc_html_e( 'Create Vetting Case', 'remember' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $view_vetting_cases ) ) : ?>
					<div class="remember-table-scroll">
					<table class="wp-list-table widefat striped remember-responsive-table">
						<thead>
							<tr>
								<th style="width: 120px;"><?php esc_html_e( 'Case Start', 'remember' ); ?></th>
								<th style="width: 100px;"><?php esc_html_e( 'Status', 'remember' ); ?></th>
								<th style="width: 120px;"><?php esc_html_e( 'Decision', 'remember' ); ?></th>
								<th style="width: 120px;"><?php esc_html_e( 'Decision Date', 'remember' ); ?></th>
								<th><?php esc_html_e( 'Decider', 'remember' ); ?></th>
								<th style="width: 100px;"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php 
							$vetting_status_labels = array(
								'pending'     => __( 'Pending', 'remember' ),
								'scheduled'   => __( 'Scheduled', 'remember' ),
								'in_progress' => __( 'In Progress', 'remember' ),
								'completed'   => __( 'Completed', 'remember' ),
							);
							$vetting_status_colors = array(
								'pending'     => '#f0b849',
								'scheduled'   => '#00a0d2',
								'in_progress' => '#2271b1',
								'completed'   => '#46b450',
							);
							$vetting_decision_labels = array(
								'pending'  => __( 'Pending', 'remember' ),
								'accepted' => __( 'Accepted', 'remember' ),
								'rejected' => __( 'Rejected', 'remember' ),
							);
							foreach ( $view_vetting_cases as $case ) : 
								$decider = ! empty( $case->primary_vetter_id ) ? get_user_by( 'ID', $case->primary_vetter_id ) : null;
							?>
								<tr>
									<td data-label="<?php echo esc_attr__( 'Case Start', 'remember' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $case->created_at ) ) ); ?></td>
									<td data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
										<span style="color: <?php echo esc_attr( $vetting_status_colors[ $case->status ] ); ?>; font-weight: bold;">
											<?php echo esc_html( $vetting_status_labels[ $case->status ] ); ?>
										</span>
									</td>
									<td data-label="<?php echo esc_attr__( 'Decision', 'remember' ); ?>">
										<?php if ( 'completed' === $case->status && ! empty( $case->decision ) && 'pending' !== $case->decision ) : ?>
											<span style="color: <?php echo 'accepted' === $case->decision ? '#46b450' : '#dc3232'; ?>; font-weight: bold;">
												<?php echo esc_html( $vetting_decision_labels[ $case->decision ] ); ?>
											</span>
										<?php else : ?>
											<span class="description">—</span>
										<?php endif; ?>
									</td>
									<td data-label="<?php echo esc_attr__( 'Decision Date', 'remember' ); ?>">
										<?php if ( ! empty( $case->decision_date ) ) : ?>
											<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $case->decision_date ) ) ); ?>
										<?php else : ?>
											<span class="description">—</span>
										<?php endif; ?>
									</td>
									<td data-label="<?php echo esc_attr__( 'Decider', 'remember' ); ?>">
										<?php echo $decider ? esc_html( $decider->display_name ) : '<span class="description">—</span>'; ?>
									</td>
									<td data-label="<?php echo esc_attr__( 'Actions', 'remember' ); ?>">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-vetting&view=' . $case->vetting_id ) ); ?>" class="button button-small">
											<?php esc_html_e( 'View', 'remember' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No vetting cases found.', 'remember' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- Billing Register (Full Width) -->
		<?php if ( current_user_can( 'remember_read_billing' ) ) : ?>
		<div class="remember-member-detail-section remember-member-detail-section--full remember-no-print">
			<h3><?php esc_html_e( 'Billing Register', 'remember' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Chronological accounting register of invoices, payments, and refunds.', 'remember' ); ?></p>
			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/integrations/class-remember-xero-sync.php';
			$remember_open_credit = Remember_Xero_Sync::member_open_credit( isset( $view_member->member_id ) ? (int) $view_member->member_id : 0 );
			if ( is_array( $remember_open_credit ) ) :
				?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: unallocated credit amount */
							__( 'Unallocated Xero credit: %s. With auto-apply on, this is used on the next invoice before it is emailed.', 'remember' ),
							number_format_i18n( $remember_open_credit['total'], 2 )
						)
					);
					?>
				</p>
			<?php endif; ?>
			
			<?php if ( ! empty( $billing_register ) ) : ?>
				<div class="remember-table-scroll">
				<table class="wp-list-table widefat striped remember-responsive-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'remember' ); ?></th>
							<th><?php esc_html_e( 'Type', 'remember' ); ?></th>
							<th><?php esc_html_e( 'Description', 'remember' ); ?></th>
							<th class="remember-num"><?php esc_html_e( 'Debit', 'remember' ); ?></th>
							<th class="remember-num"><?php esc_html_e( 'Credit', 'remember' ); ?></th>
							<th class="remember-num"><?php esc_html_e( 'Balance', 'remember' ); ?></th>
							<th><?php esc_html_e( 'Status', 'remember' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $billing_register as $entry ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr__( 'Date', 'remember' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $entry['date'] ) ) ); ?></td>
								<td data-label="<?php echo esc_attr__( 'Type', 'remember' ); ?>">
									<?php if ( 'invoice' === $entry['type'] ) : ?>
										<span style="color: #d63638;"><?php esc_html_e( 'Invoice', 'remember' ); ?></span>
									<?php elseif ( 'refund' === $entry['type'] ) : ?>
										<span style="color: #b32d2e;"><?php esc_html_e( 'Refund', 'remember' ); ?></span>
									<?php elseif ( 'credit' === $entry['type'] ) : ?>
										<span style="color: #00a32a;"><?php esc_html_e( 'Credit', 'remember' ); ?></span>
									<?php else : ?>
										<span style="color: #00a32a;"><?php esc_html_e( 'Payment', 'remember' ); ?></span>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr__( 'Description', 'remember' ); ?>">
									<?php if ( 'invoice' === $entry['type'] && ! empty( $entry['invoice_url'] ) && ! empty( $entry['invoice_number'] ) ) : ?>
										<?php
										$desc   = (string) $entry['description'];
										$num    = (string) $entry['invoice_number'];
										$needle = sprintf( __( '(Invoice #%s)', 'remember' ), $num );
										$pos    = strpos( $desc, $needle );
										if ( false !== $pos ) {
											echo esc_html( substr( $desc, 0, $pos ) );
											printf(
												'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
												esc_url( $entry['invoice_url'] ),
												esc_html( $needle )
											);
											echo esc_html( substr( $desc, $pos + strlen( $needle ) ) );
										} else {
											echo esc_html( $desc );
										}
										?>
									<?php else : ?>
										<?php echo esc_html( $entry['description'] ); ?>
									<?php endif; ?>
								</td>
								<td class="remember-num" data-label="<?php echo esc_attr__( 'Debit', 'remember' ); ?>">
									<?php if ( $entry['debit'] > 0 ) : ?>
										<?php echo esc_html( number_format( $entry['debit'], 2 ) ); ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td class="remember-num" data-label="<?php echo esc_attr__( 'Credit', 'remember' ); ?>">
									<?php if ( $entry['credit'] > 0 ) : ?>
										<?php echo esc_html( number_format( $entry['credit'], 2 ) ); ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td class="remember-num" data-label="<?php echo esc_attr__( 'Balance', 'remember' ); ?>" style="font-weight: <?php echo $entry['balance'] > 0 ? 'bold' : 'normal'; ?>;">
									<?php
									$balance_color = $entry['balance'] > 0 ? '#d63638' : ( $entry['balance'] < 0 ? '#00a32a' : '#666' );
									?>
									<span style="color: <?php echo esc_attr( $balance_color ); ?>;">
										<?php echo esc_html( number_format( $entry['balance'], 2 ) ); ?>
									</span>
								</td>
								<td data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
									<?php
									$status_labels_billing = array(
										'pending' => __( 'Pending', 'remember' ),
										'partial' => __( 'Partial', 'remember' ),
										'paid' => __( 'Paid', 'remember' ),
										'refunded' => __( 'Refunded', 'remember' ),
										'cancelled' => __( 'Voided', 'remember' ),
										'available' => __( 'Available', 'remember' ),
									);
									$status_colors_billing = array(
										'pending' => '#f0b849',
										'partial' => '#00a0d2',
										'paid' => '#46b450',
										'refunded' => '#72777c',
										'cancelled' => '#dc3232',
										'available' => '#46b450',
									);
									$status_label = isset( $status_labels_billing[ $entry['status'] ] ) ? $status_labels_billing[ $entry['status'] ] : $entry['status'];
									$status_color = isset( $status_colors_billing[ $entry['status'] ] ) ? $status_colors_billing[ $entry['status'] ] : '#666';
									?>
									<span style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: bold;">
										<?php echo esc_html( $status_label ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="7" data-label="<?php echo esc_attr__( 'Current Balance', 'remember' ); ?>">
								<strong><?php esc_html_e( 'Current Balance:', 'remember' ); ?></strong>
								<?php
								$current_balance = $running_balance;
								$balance_color = $current_balance > 0 ? '#d63638' : ( $current_balance < 0 ? '#00a32a' : '#666' );
								?>
								<span style="color: <?php echo esc_attr( $balance_color ); ?>; font-size: 16px; font-weight: bold; margin-left: 8px;">
									<?php echo esc_html( number_format( $current_balance, 2 ) ); ?>
								</span>
							</td>
						</tr>
					</tfoot>
				</table>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No billing history found.', 'remember' ); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	<?php endif; ?>

</td></tr></tbody><tfoot><tr><td>
	<div class="remember-print-banner remember-print-banner--bottom">
		<span class="remember-print-banner__mark"><?php esc_html_e( 'Confidential', 'remember' ); ?></span>
		<span class="remember-print-banner__note">
			<?php
			if ( '' !== $remember_member_number ) {
				printf(
					/* translators: 1: member display name, 2: member number, 3: date the sheet was printed */
					esc_html__( '%1$s · #%2$s · printed %3$s', 'remember' ),
					esc_html( $view_user->display_name ),
					esc_html( $remember_member_number ),
					esc_html( $remember_print_stamp )
				);
			} else {
				printf(
					/* translators: 1: member display name, 2: date the sheet was printed */
					esc_html__( '%1$s · printed %2$s', 'remember' ),
					esc_html( $view_user->display_name ),
					esc_html( $remember_print_stamp )
				);
			}
			?>
		</span>
	</div>
</td></tr></tfoot></table>
</div>
