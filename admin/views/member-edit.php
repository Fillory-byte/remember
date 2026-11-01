<?php
/**
 * Member edit form (included from member-detail.php)
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Variables are set in members.php: $view_member, $view_user, $view_profile, etc.
// Get lookup data for form fields
global $wpdb;

// Get social media platforms
$social_media_platforms = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_social_media_platforms WHERE is_active = 1 ORDER BY sort_order ASC, platform_name ASC"
);

// Load timezone utility
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';

// Get timezone from WP user meta (not from member_profiles)
$selected_timezone = get_user_meta( $view_user->ID, 'timezone_string', true );
if ( empty( $selected_timezone ) ) {
	$selected_timezone = 'America/Los_Angeles'; // Preselected only; saved when the form is saved.
}

// Load countries helper
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-countries.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-image-uploader.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-import-export.php';

list( $legal_first_resolved, $legal_last_resolved ) = Remember_Import_Export::member_resolve_legal_name_parts( $view_profile, (int) $view_member_id );

// Get max image dimensions from settings
$options = get_option( 'remember_options', array() );
$max_image_size = isset( $options['photo_max_dimensions'] ) ? absint( $options['photo_max_dimensions'] ) : 800;
$photo_max_bytes = isset( $options['photo_max_size'] ) ? absint( $options['photo_max_size'] ) : 2097152;
if ( $max_image_size < 1 ) {
	$max_image_size = 800;
}
if ( $photo_max_bytes < 1 ) {
	$photo_max_bytes = 2097152;
}
?>

<form method="post" action="" enctype="multipart/form-data" class="remember-member-edit-form" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
	<?php wp_nonce_field( 'remember_member_action', 'remember_member_nonce' ); ?>
	<input type="hidden" name="remember_member_action" value="update_profile">
	<input type="hidden" name="member_id" value="<?php echo esc_attr( $view_member_id ); ?>">
	
	<h2><?php esc_html_e( 'Edit Member Profile', 'remember' ); ?></h2>
	
	<!-- Profile Photo -->
	<h3><?php esc_html_e( 'Profile Photo', 'remember' ); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="photo_file"><?php esc_html_e( 'Photo', 'remember' ); ?></label></th>
			<td>
				<div class="remember-profile-photo-edit" data-output-size="<?php echo esc_attr( (string) $max_image_size ); ?>">
					<div class="remember-profile-photo-current"<?php echo ( ! empty( $view_member->photo_url ) ) ? '' : ' hidden'; ?>>
						<?php if ( ! empty( $view_member->photo_url ) ) : ?>
							<div class="remember-profile-photo-preview">
								<img src="<?php echo esc_url( $view_member->photo_url ); ?>" alt="<?php echo esc_attr( $view_user->display_name ); ?>">
							</div>
							<label class="remember-checkbox-label remember-profile-photo-delete">
								<input type="checkbox" name="delete_photo" value="1" id="delete_photo">
								<span><?php esc_html_e( 'Delete current photo', 'remember' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Upload a new photo to replace the current one.', 'remember' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="remember-profile-photo-cropper" hidden>
						<div class="remember-profile-photo-cropper-viewport" aria-label="<?php esc_attr_e( 'Photo framing preview', 'remember' ); ?>">
							<img src="" alt="" class="remember-profile-photo-cropper-image" draggable="false">
						</div>
						<div class="remember-profile-photo-cropper-controls">
							<button type="button" class="button remember-photo-zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'remember' ); ?>">−</button>
							<input type="range" class="remember-photo-zoom-range" min="1" max="3" step="0.01" value="1" aria-label="<?php esc_attr_e( 'Zoom', 'remember' ); ?>">
							<button type="button" class="button remember-photo-zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'remember' ); ?>">+</button>
						</div>
						<p class="description"><?php esc_html_e( 'Drag to recenter. Use zoom to frame the photo inside the circle. Framing is applied when you save.', 'remember' ); ?></p>
						<button type="button" class="button remember-photo-clear">
							<?php esc_html_e( 'Clear selected photo', 'remember' ); ?>
						</button>
					</div>

					<input type="file" id="photo_file" name="photo_file" accept="image/jpeg,image/png,image/gif">
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: max px, 2: max MB */
								__( 'Square crop from your framing, max %1$dpx. Maximum file size %2$d MB. JPEG, PNG, or GIF.', 'remember' ),
								$max_image_size,
								(int) max( 1, round( $photo_max_bytes / 1024 / 1024 ) )
							)
						);
						?>
					</p>
				</div>
			</td>
		</tr>
	</table>
	
	<!-- WordPress User Information -->
	<h3><?php esc_html_e( 'WordPress User Information', 'remember' ); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="user_email"><?php esc_html_e( 'Email Address', 'remember' ); ?> <span class="description"><?php esc_html_e( '(required)', 'remember' ); ?></span></label></th>
			<td>
				<input type="email" id="user_email" name="user_email" class="regular-text" value="<?php echo esc_attr( $view_user->user_email ); ?>" required autocomplete="email">
				<p class="description"><?php esc_html_e( 'Login and password-reset address. Saving a different address changes it immediately, updates the linked Xero or QuickBooks contact, and WordPress emails the previous address.', 'remember' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="nickname"><?php esc_html_e( 'Nickname', 'remember' ); ?> <span class="description"><?php esc_html_e( '(required)', 'remember' ); ?></span></label></th>
			<td>
				<input type="text" id="nickname" name="nickname" class="regular-text" value="<?php echo esc_attr( get_user_meta( $view_user->ID, 'nickname', true ) ); ?>" required>
			</td>
		</tr>
		<tr>
			<th><label for="display_name"><?php esc_html_e( 'Display name publicly as', 'remember' ); ?> <span class="description"><?php esc_html_e( '(required)', 'remember' ); ?></span></label></th>
			<td>
				<?php
				// Get first and last name from WordPress user meta (not legal names)
				$wp_first_name = get_user_meta( $view_user->ID, 'first_name', true );
				$wp_last_name = get_user_meta( $view_user->ID, 'last_name', true );
				$nickname = get_user_meta( $view_user->ID, 'nickname', true );
				
				// Generate display name options like WordPress does
				$public_display = array();
				if ( ! empty( $nickname ) ) {
					$public_display['display_nickname'] = $nickname;
				}
				$public_display['display_username'] = $view_user->user_login;
				
				if ( ! empty( $wp_first_name ) ) {
					$public_display['display_firstname'] = $wp_first_name;
				}
				
				if ( ! empty( $wp_last_name ) ) {
					$public_display['display_lastname'] = $wp_last_name;
				}
				
				if ( ! empty( $wp_first_name ) && ! empty( $wp_last_name ) ) {
					$public_display['display_firstlast'] = $wp_first_name . ' ' . $wp_last_name;
					$public_display['display_lastfirst'] = $wp_last_name . ' ' . $wp_first_name;
				}
				
				// Add current display name if not already in the list
				if ( ! in_array( $view_user->display_name, $public_display, true ) ) {
					$public_display = array( 'display_displayname' => $view_user->display_name ) + $public_display;
				}
				
				// Clean up the array
				$public_display = array_map( 'trim', $public_display );
				$public_display = array_unique( $public_display );
				?>
				<select name="display_name" id="display_name" required>
					<?php foreach ( $public_display as $id => $item ) : ?>
						<option <?php selected( $view_user->display_name, $item ); ?>><?php echo esc_html( $item ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>
	
	<!-- Profile Information -->
	<h3><?php esc_html_e( 'Profile Information', 'remember' ); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="legal_first_name"><?php esc_html_e( 'Legal First Name', 'remember' ); ?> <span class="description">(required)</span></label></th>
			<td>
				<input type="text" id="legal_first_name" name="legal_first_name" class="regular-text" value="<?php echo esc_attr( $legal_first_resolved ); ?>" required>
			</td>
		</tr>
		<tr>
			<th><label for="legal_last_name"><?php esc_html_e( 'Legal Last Name', 'remember' ); ?> <span class="description">(required)</span></label></th>
			<td>
				<input type="text" id="legal_last_name" name="legal_last_name" class="regular-text" value="<?php echo esc_attr( $legal_last_resolved ); ?>" required>
			</td>
		</tr>
		<tr>
			<th><label for="member_number"><?php esc_html_e( 'Member Number', 'remember' ); ?></label></th>
			<td>
				<input type="text" id="member_number" name="member_number" class="regular-text" pattern="[A-Za-z0-9]*" maxlength="50" value="<?php echo esc_attr( $view_profile && ! empty( $view_profile->member_number ) ? $view_profile->member_number : '' ); ?>" autocomplete="off">
				<p class="description"><?php esc_html_e( 'Optional. Unique alphanumeric ID assigned by staff. Members can view it but cannot change it.', 'remember' ); ?></p>
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
			<td><span class="description"><?php echo '' !== $remember_created_display ? esc_html( $remember_created_display ) : esc_html__( '—', 'remember' ); ?></span></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Profile updated', 'remember' ); ?></th>
			<td><span class="description"><?php echo '' !== $remember_updated_display ? esc_html( $remember_updated_display ) : esc_html__( '—', 'remember' ); ?></span></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Updated by', 'remember' ); ?></th>
			<td><span class="description"><?php echo '' !== $remember_updated_by_name ? esc_html( $remember_updated_by_name ) : esc_html__( '—', 'remember' ); ?></span></td>
		</tr>
		<tr>
			<th><label for="address_street"><?php esc_html_e( 'Street Address', 'remember' ); ?></label></th>
			<td>
				<input type="text" id="address_street" name="address_street" class="regular-text" value="<?php echo esc_attr( $view_profile ? $view_profile->address_street : '' ); ?>">
			</td>
		</tr>
		<tr>
			<th><label for="address_city"><?php esc_html_e( 'City', 'remember' ); ?></label></th>
			<td>
				<input type="text" id="address_city" name="address_city" class="regular-text" value="<?php echo esc_attr( $view_profile ? $view_profile->address_city : '' ); ?>">
			</td>
		</tr>
		<tr>
			<th><label for="address_state"><?php esc_html_e( 'State/Province', 'remember' ); ?></label></th>
			<td>
				<input type="text" id="address_state" name="address_state" class="regular-text" value="<?php echo esc_attr( $view_profile ? $view_profile->address_state : '' ); ?>">
			</td>
		</tr>
		<tr>
			<th><label for="address_postal"><?php esc_html_e( 'Postal Code', 'remember' ); ?></label></th>
			<td>
				<input type="text" id="address_postal" name="address_postal" class="regular-text" value="<?php echo esc_attr( $view_profile ? $view_profile->address_postal : '' ); ?>">
			</td>
		</tr>
		<tr>
			<th><label for="address_country"><?php esc_html_e( 'Country', 'remember' ); ?></label></th>
			<td>
				<?php 
				$selected_country = $view_profile && $view_profile->address_country ? $view_profile->address_country : 'US';
				echo Remember_Countries::dropdown( 'address_country', $selected_country, array( 'id' => 'address_country', 'class' => 'regular-text' ) );
				?>
			</td>
		</tr>
		<tr>
			<th><label for="cell_phone"><?php esc_html_e( 'Cell Phone', 'remember' ); ?> <span class="description">(required)</span></label></th>
			<td>
				<input type="text" id="cell_phone" name="cell_phone" class="regular-text" value="<?php echo esc_attr( $view_profile ? $view_profile->cell_phone : '' ); ?>" placeholder="<?php esc_attr_e( '+18055551212', 'remember' ); ?>" required>
				<p class="description"><?php esc_html_e( 'Include a leading + and country code. Examples: +18055551212 (USA/Canada), +447700900123 (UK).', 'remember' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="timezone_string"><?php esc_html_e( 'Time Zone', 'remember' ); ?></label></th>
			<td>
				<?php echo Remember_Timezone::dropdown( $selected_timezone, 'timezone_string', 'timezone_string', false ); ?>
				<p class="description"><?php echo esc_html( Remember_Timezone::help_text() ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="im_type"><?php esc_html_e( 'Instant Messenger', 'remember' ); ?></label></th>
			<td>
				<?php
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-platform-handles.php';
				$im_selected = ( $view_profile && ! empty( $view_profile->im_type ) ) ? $view_profile->im_type : Remember_Im_Platforms::default_key();
				$remember_im_row = Remember_Platform_Handles::im_platform( $im_selected );
				?>
				<select id="im_type" name="im_type" style="width: 150px;">
					<?php Remember_Im_Platforms::render_options( $im_selected ); ?>
				</select>
				<input type="text" id="im_handle" name="im_handle" class="regular-text" value="<?php echo esc_attr( $view_profile ? $view_profile->im_handle : '' ); ?>" placeholder="<?php esc_attr_e( 'Name only', 'remember' ); ?>" style="margin-left: 10px;">
				<?php Remember_Platform_Handles::render_help( $remember_im_row && ! empty( $remember_im_row->show_at ), 'description remember-at-help' ); ?>
			</td>
		</tr>
		<?php
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
		$shirt_size = $view_profile && isset( $view_profile->shirt_size ) ? $view_profile->shirt_size : '';
		$pants_size = $view_profile && isset( $view_profile->pants_size ) ? $view_profile->pants_size : '';
		$shoe_size  = $view_profile && isset( $view_profile->shoe_size ) ? $view_profile->shoe_size : '';
		?>
		<tr>
			<th><label for="shirt_size"><?php esc_html_e( 'Shirt Size', 'remember' ); ?></label></th>
			<td><?php echo Remember_Clothing_Sizes::dropdown( 'shirt', $shirt_size, 'shirt_size', 'shirt_size' ); ?></td>
		</tr>
		<tr>
			<th><label for="pants_size"><?php esc_html_e( 'Pants Size', 'remember' ); ?></label></th>
			<td><?php echo Remember_Clothing_Sizes::dropdown( 'pants', $pants_size, 'pants_size', 'pants_size' ); ?></td>
		</tr>
		<tr>
			<th><label for="shoe_size"><?php esc_html_e( 'Shoe Size', 'remember' ); ?></label></th>
			<td>
				<?php echo Remember_Clothing_Sizes::dropdown( 'shoe', $shoe_size, 'shoe_size', 'shoe_size' ); ?>
				<p class="description"><?php echo esc_html( Remember_Clothing_Sizes::section_help() ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="interests"><?php esc_html_e( 'Interests', 'remember' ); ?></label></th>
			<td>
				<?php
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';
				$interests_value = $view_profile && isset( $view_profile->interests ) ? $view_profile->interests : '';
				Remember_Profile_Fields::render_interests_editor( $interests_value, 'interests', 'interests' );
				?>
			</td>
		</tr>
	</table>

	<?php
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
	echo '<table class="form-table">';
	Remember_Profile_Questions::render_fields( (int) $view_member_id, 'admin' );
	echo '</table>';
	?>
	
	<?php
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-sensitive-profile.php';
	echo Remember_Sensitive_Profile::placeholder( (int) $view_member_id, 'emergency', 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
	?>
	
	<!-- Social Media -->
	<?php if ( ! empty( $social_media_platforms ) ) : ?>
		<h3><?php esc_html_e( 'Social Media', 'remember' ); ?></h3>
		<table class="form-table">
			<?php foreach ( $social_media_platforms as $platform ) : 
				$current_handle = '';
				foreach ( $view_social_media as $social ) {
					if ( $social->platform_id == $platform->platform_id ) {
						$current_handle = $social->handle;
						break;
					}
				}
			?>
				<tr>
					<th><label for="social_media_<?php echo esc_attr( $platform->platform_id ); ?>"><?php echo esc_html( $platform->platform_name ); ?></label></th>
					<td>
						<input type="text" id="social_media_<?php echo esc_attr( $platform->platform_id ); ?>" name="social_media[<?php echo esc_attr( $platform->platform_id ); ?>]" class="regular-text" value="<?php echo esc_attr( $current_handle ); ?>" placeholder="<?php esc_attr_e( 'Name only', 'remember' ); ?>">
						<?php
						if ( ! empty( $platform->show_at ) ) {
							Remember_Platform_Handles::render_help( true, 'description remember-at-help' );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endif; ?>
	
	<?php echo Remember_Sensitive_Profile::placeholder( (int) $view_member_id, 'health', 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
	
	<!-- Member Roles (only if user has update_members capability) -->
	<?php if ( current_user_can( 'remember_update_members' ) ) : 
		require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-capabilities.php';
		$role_model = new Remember_Role();
		$all_roles = $role_model->get_all();
		
		// Get current member roles
		global $wpdb;
		$current_role_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT role_id FROM {$wpdb->prefix}remember_member_roles WHERE member_id = %d",
			$view_member_id
		) );
	?>
		<h3><?php esc_html_e( 'Member Roles', 'remember' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Assigned Roles', 'remember' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Select the roles this member should have. You can only assign roles whose capabilities you already hold.', 'remember' ); ?></p>
					<fieldset style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 3px; margin-top: 10px;">
						<?php if ( ! empty( $all_roles ) ) : ?>
							<?php 
							// Group roles by type
							$event_roles = array();
							$system_roles = array();
							foreach ( $all_roles as $role ) {
								if ( $role->role_type === 'event' || $role->is_event_role ) {
									$event_roles[] = $role;
								} else {
									$system_roles[] = $role;
								}
							}
							?>
							<?php if ( ! empty( $system_roles ) ) : ?>
								<h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: bold;"><?php esc_html_e( 'System Roles', 'remember' ); ?></h4>
								<?php foreach ( $system_roles as $role ) : ?>
									<?php
									$is_assigned = in_array( (string) $role->role_id, array_map( 'strval', $current_role_ids ), true )
										|| in_array( (int) $role->role_id, array_map( 'intval', $current_role_ids ), true );
									$can_assign  = Remember_Capabilities::current_user_can_assign_role( $role->role_id );
									?>
									<label style="display: block; margin: 5px 0;">
										<input type="checkbox" name="member_roles[]" value="<?php echo esc_attr( $role->role_id ); ?>" <?php checked( $is_assigned ); ?> <?php disabled( ! $can_assign ); ?>>
										<?php echo esc_html( $role->role_name ); ?>
										<?php if ( ! empty( $role->description ) ) : ?>
											<span class="description" style="margin-left: 5px;">- <?php echo esc_html( $role->description ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							<?php endif; ?>
							
							<?php if ( ! empty( $event_roles ) ) : ?>
								<?php if ( ! empty( $system_roles ) ) : ?>
									<h4 style="margin: 15px 0 10px 0; font-size: 14px; font-weight: bold;"><?php esc_html_e( 'Event Roles', 'remember' ); ?></h4>
								<?php else : ?>
									<h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: bold;"><?php esc_html_e( 'Event Roles', 'remember' ); ?></h4>
								<?php endif; ?>
								<?php foreach ( $event_roles as $role ) : ?>
									<?php
									$is_assigned = in_array( (string) $role->role_id, array_map( 'strval', $current_role_ids ), true )
										|| in_array( (int) $role->role_id, array_map( 'intval', $current_role_ids ), true );
									$can_assign  = Remember_Capabilities::current_user_can_assign_role( $role->role_id );
									?>
									<label style="display: block; margin: 5px 0;">
										<input type="checkbox" name="member_roles[]" value="<?php echo esc_attr( $role->role_id ); ?>" <?php checked( $is_assigned ); ?> <?php disabled( ! $can_assign ); ?>>
										<?php echo esc_html( $role->role_name ); ?>
										<?php if ( ! empty( $role->description ) ) : ?>
											<span class="description" style="margin-left: 5px;">- <?php echo esc_html( $role->description ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							<?php endif; ?>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'No roles available. Create roles in the Roles section first.', 'remember' ); ?></p>
						<?php endif; ?>
					</fieldset>
				</td>
			</tr>
		</table>
	<?php endif; ?>
	
	<!-- Privacy Settings: Share with Event Members -->
	<h3><?php esc_html_e( 'Privacy Settings', 'remember' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Control what contact information is shared with other members when you are accepted into an event. These settings apply globally to all events.', 'remember' ); ?></p>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Share with Event Members', 'remember' ); ?></th>
			<td>
				<fieldset>
					<p>
						<label class="remember-toggle-switch">
							<input type="checkbox" 
								   name="share_photo_with_events" 
								   value="1" 
								   <?php checked( $view_profile && isset( $view_profile->share_photo_with_events ) ? $view_profile->share_photo_with_events : 0, 1 ); ?>>
							<span class="remember-toggle-slider"></span>
						</label>
						<label for="share_photo_with_events" style="margin-left: 10px; cursor: pointer;">
							<strong><?php esc_html_e( 'Profile Photo', 'remember' ); ?></strong>
							<span class="description" style="margin-left: 5px;"><?php esc_html_e( 'Share your profile photo with other members in events you\'re accepted to.', 'remember' ); ?></span>
						</label>
					</p>

					<p>
						<label class="remember-toggle-switch">
							<input type="checkbox" 
								   name="share_email_with_events" 
								   value="1" 
								   <?php checked( $view_profile && isset( $view_profile->share_email_with_events ) ? $view_profile->share_email_with_events : 0, 1 ); ?>>
							<span class="remember-toggle-slider"></span>
						</label>
						<label for="share_email_with_events" style="margin-left: 10px; cursor: pointer;">
							<strong><?php esc_html_e( 'Email Address', 'remember' ); ?></strong>
							<span class="description" style="margin-left: 5px;"><?php esc_html_e( 'Share your email address with other members in events you\'re accepted to.', 'remember' ); ?></span>
						</label>
					</p>
					
					<p>
						<label class="remember-toggle-switch">
							<input type="checkbox" 
								   name="share_phone_with_events" 
								   value="1" 
								   <?php checked( $view_profile && isset( $view_profile->share_phone_with_events ) ? $view_profile->share_phone_with_events : 0, 1 ); ?>>
							<span class="remember-toggle-slider"></span>
						</label>
						<label for="share_phone_with_events" style="margin-left: 10px; cursor: pointer;">
							<strong><?php esc_html_e( 'Phone Number', 'remember' ); ?></strong>
							<span class="description" style="margin-left: 5px;"><?php esc_html_e( 'Share your cell phone number with other members in events you\'re accepted to.', 'remember' ); ?></span>
						</label>
					</p>
					
					<p>
						<label class="remember-toggle-switch">
							<input type="checkbox" 
								   name="share_location_with_events" 
								   value="1" 
								   <?php checked( $view_profile && isset( $view_profile->share_location_with_events ) ? $view_profile->share_location_with_events : 0, 1 ); ?>>
							<span class="remember-toggle-slider"></span>
						</label>
						<label for="share_location_with_events" style="margin-left: 10px; cursor: pointer;">
							<strong><?php esc_html_e( 'City, State, Country', 'remember' ); ?></strong>
							<span class="description" style="margin-left: 5px;"><?php esc_html_e( 'Share your city, state, and country with other members in events you\'re accepted to.', 'remember' ); ?></span>
						</label>
					</p>
					
					<p>
						<label class="remember-toggle-switch">
							<input type="checkbox" 
								   name="share_im_with_events" 
								   value="1" 
								   <?php checked( $view_profile && isset( $view_profile->share_im_with_events ) ? $view_profile->share_im_with_events : 0, 1 ); ?>>
							<span class="remember-toggle-slider"></span>
						</label>
						<label for="share_im_with_events" style="margin-left: 10px; cursor: pointer;">
							<strong><?php esc_html_e( 'Instant Messenger', 'remember' ); ?></strong>
							<span class="description" style="margin-left: 5px;"><?php esc_html_e( 'Share your IM handle and type with other members in events you\'re accepted to.', 'remember' ); ?></span>
						</label>
					</p>
					
					<p>
						<label class="remember-toggle-switch">
							<input type="checkbox" 
								   name="share_interests_with_events" 
								   value="1" 
								   <?php checked( $view_profile && isset( $view_profile->share_interests_with_events ) ? $view_profile->share_interests_with_events : 0, 1 ); ?>>
							<span class="remember-toggle-slider"></span>
						</label>
						<label for="share_interests_with_events" style="margin-left: 10px; cursor: pointer;">
							<strong><?php esc_html_e( 'Interests', 'remember' ); ?></strong>
							<span class="description" style="margin-left: 5px;"><?php esc_html_e( 'Share your interests with other members in events you\'re accepted to.', 'remember' ); ?></span>
						</label>
					</p>
				</fieldset>
			</td>
		</tr>
	</table>
	
	<?php Remember_Platform_Handles::print_toggle_script(); ?>
	<p class="submit">
		<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Profile', 'remember' ); ?>">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&view=' . $view_member_id ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'remember' ); ?></a>
	</p>
</form>
