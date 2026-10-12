<?php
/**
 * Public member registration form (full profile).
 *
 * @package    reMember
 * @subpackage reMember/public/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Variables: $remember_register_success, $remember_register_error_message (optional).
$remember_register_success       = isset( $remember_register_success ) ? (bool) $remember_register_success : false;
$remember_register_error_message = isset( $remember_register_error_message ) ? $remember_register_error_message : '';

$remember_pw_min = (int) apply_filters( 'remember_registration_password_min_length', 8 );
if ( $remember_pw_min < 1 ) {
	$remember_pw_min = 8;
}

$remember_options     = get_option( 'remember_options', array() );
$photo_max_dimensions = isset( $remember_options['photo_max_dimensions'] ) ? absint( $remember_options['photo_max_dimensions'] ) : 800;
$photo_max_bytes      = isset( $remember_options['photo_max_size'] ) ? absint( $remember_options['photo_max_size'] ) : 2097152;
if ( $photo_max_dimensions < 1 ) {
	$photo_max_dimensions = 800;
}
if ( $photo_max_bytes < 1 ) {
	$photo_max_bytes = 2097152;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-countries.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';

/**
 * Sticky text value from registration POST (same request only).
 *
 * @param string $key     remember_reg_* key without prefix, or full key.
 * @param string $default Default.
 * @return string
 */
$remember_reg_val = static function ( $key, $default = '' ) {
	$name = ( 0 === strpos( $key, 'remember_reg_' ) ) ? $key : 'remember_reg_' . $key;
	if ( ! isset( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
		return $default;
	}
	return sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
};

global $wpdb;
$social_platforms = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_social_media_platforms WHERE is_active = 1 ORDER BY sort_order ASC, platform_name ASC"
);
$dietary_restrictions = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_dietary_restrictions WHERE is_active = 1 ORDER BY CASE WHEN restriction_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, restriction_name ASC"
);
$medical_accommodations = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_medical_accommodations WHERE is_active = 1 ORDER BY CASE WHEN accommodation_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, accommodation_name ASC"
);
$allergies = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_allergies WHERE is_active = 1 ORDER BY CASE WHEN allergy_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, allergy_name ASC"
);

$remember_reg_timezone = $remember_reg_val( 'timezone', '' );
$remember_reg_country  = $remember_reg_val( 'address_country', 'US' );
$remember_reg_im_type  = $remember_reg_val( 'im_type', '' );
$remember_reg_dietary_ids = Remember_Profile_Fields::catalog_ids_from_request( 'dietary_restrictions' );
$remember_reg_medical_ids = Remember_Profile_Fields::catalog_ids_from_request( 'medical_accommodations' );
$remember_reg_social      = Remember_Profile_Fields::social_slots_from_request();
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
if ( '' === $remember_reg_im_type ) {
	$remember_reg_im_type = Remember_Im_Platforms::default_key();
}

?>
<div class="remember-register remember-register-form">
	<?php if ( ! $remember_register_success ) : ?>
		<?php if ( $remember_register_error_message ) : ?>
			<div class="remember-notice remember-error" role="alert">
				<p><?php echo esc_html( $remember_register_error_message ); ?></p>
			</div>
		<?php endif; ?>

		<form class="remember-register-form__inner" method="post" action="" autocomplete="on" enctype="multipart/form-data">
			<?php wp_nonce_field( 'remember_register_member', 'remember_register_nonce' ); ?>
			<input type="hidden" name="remember_register_submit" value="1" />

			<div class="remember-register-hp" aria-hidden="true">
				<input type="text" name="remember_hp" value="" tabindex="-1" autocomplete="off" />
			</div>

			<p class="remember-register-intro"><?php esc_html_e( 'Fields marked * are required. Optional sections can be completed later in your profile.', 'remember' ); ?></p>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Account', 'remember' ); ?></h3>

			<div class="remember-register-row">
				<label for="remember_reg_username"><?php esc_html_e( 'Username', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_username" id="remember_reg_username" required autocomplete="username" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'username' ) ); ?>" />
				<p class="remember-register-help"><?php esc_html_e( 'Used to log into the website. Usernames cannot be changed later.', 'remember' ); ?></p>
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_email"><?php esc_html_e( 'Email Address', 'remember' ); ?> <span class="required">*</span></label>
				<input type="email" name="remember_reg_email" id="remember_reg_email" required autocomplete="email" class="remember-register-input" value="<?php echo esc_attr( isset( $_POST['remember_reg_email'] ) ? sanitize_email( wp_unslash( $_POST['remember_reg_email'] ) ) : '' ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_password"><?php esc_html_e( 'Password', 'remember' ); ?> <span class="required">*</span></label>
				<input type="password" name="remember_reg_password" id="remember_reg_password" required autocomplete="new-password" class="remember-register-input" minlength="<?php echo esc_attr( (string) $remember_pw_min ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_password_confirm"><?php esc_html_e( 'Confirm Password', 'remember' ); ?> <span class="required">*</span></label>
				<input type="password" name="remember_reg_password_confirm" id="remember_reg_password_confirm" required autocomplete="new-password" class="remember-register-input" minlength="<?php echo esc_attr( (string) $remember_pw_min ); ?>" />
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Public Identity', 'remember' ); ?></h3>

			<div class="remember-register-row">
				<label for="remember_reg_display_name"><?php esc_html_e( 'Nickname', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_display_name" id="remember_reg_display_name" required autocomplete="nickname" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'display_name' ) ); ?>" />
				<p class="remember-register-help"><?php esc_html_e( 'How you appear to other members. Never taken from your legal name unless you choose that later.', 'remember' ); ?></p>
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Legal Name', 'remember' ); ?></h3>

			<div class="remember-register-row">
				<label for="remember_reg_first_name"><?php esc_html_e( 'Legal First Name', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_first_name" id="remember_reg_first_name" required autocomplete="given-name" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'first_name' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_last_name"><?php esc_html_e( 'Legal Last Name', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_last_name" id="remember_reg_last_name" required autocomplete="family-name" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'last_name' ) ); ?>" />
				<p class="remember-register-help"><?php esc_html_e( 'Used for admin and vetting only — not shown to other members.', 'remember' ); ?></p>
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Contact', 'remember' ); ?></h3>

			<div class="remember-register-row">
				<label for="remember_reg_cell_phone"><?php esc_html_e( 'Cell Phone', 'remember' ); ?> <span class="required">*</span></label>
				<input type="tel" name="remember_reg_cell_phone" id="remember_reg_cell_phone" required autocomplete="tel" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'cell_phone' ) ); ?>" placeholder="<?php esc_attr_e( '+18055551212', 'remember' ); ?>" />
				<p class="remember-register-help"><?php esc_html_e( 'Include a leading + and country code. Examples: +18055551212 (USA/Canada), +447700900123 (UK).', 'remember' ); ?></p>
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_timezone"><?php esc_html_e( 'Time Zone', 'remember' ); ?> <span class="required">*</span></label>
				<?php echo Remember_Timezone::dropdown( $remember_reg_timezone, 'remember_reg_timezone', 'remember_reg_timezone', true, 'remember-register-input' ); ?>
				<p class="remember-register-help"><?php echo esc_html( Remember_Timezone::help_text() ); ?></p>
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_im_type"><?php esc_html_e( 'Instant Messenger', 'remember' ); ?> <span class="required">*</span></label>
				<div class="remember-register-im">
					<select id="remember_reg_im_type" name="remember_reg_im_type" class="remember-register-input remember-register-input--im-type" required>
						<?php Remember_Im_Platforms::render_options( $remember_reg_im_type ); ?>
					</select>
					<input type="text" name="remember_reg_im_handle" id="remember_reg_im_handle" required class="remember-register-input remember-register-input--im-handle" value="<?php echo esc_attr( $remember_reg_val( 'im_handle' ) ); ?>" placeholder="<?php esc_attr_e( 'Handle / username', 'remember' ); ?>" />
				</div>
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Address', 'remember' ); ?></h3>

			<div class="remember-register-row">
				<label for="remember_reg_address_street"><?php esc_html_e( 'Street Address', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_address_street" id="remember_reg_address_street" required autocomplete="street-address" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'address_street' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_address_city"><?php esc_html_e( 'City', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_address_city" id="remember_reg_address_city" required autocomplete="address-level2" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'address_city' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_address_state"><?php esc_html_e( 'State/Province', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_address_state" id="remember_reg_address_state" required autocomplete="address-level1" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'address_state' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_address_postal"><?php esc_html_e( 'Postal Code', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_address_postal" id="remember_reg_address_postal" required autocomplete="postal-code" class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'address_postal' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_address_country"><?php esc_html_e( 'Country', 'remember' ); ?> <span class="required">*</span></label>
				<?php
				echo Remember_Countries::dropdown(
					'remember_reg_address_country',
					$remember_reg_country,
					array(
						'id'       => 'remember_reg_address_country',
						'class'    => 'remember-register-input',
						'required' => true,
					)
				);
				?>
			</div>

			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
			Remember_Profile_Questions::render_fields(
				0,
				'register',
				isset( $_POST['remember_register_submit'] ) ? Remember_Profile_Questions::collect_from_request() : null // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only.
			);
			?>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Emergency Contact', 'remember' ); ?></h3>

			<div class="remember-register-row">
				<label for="remember_reg_emergency_contact_first"><?php esc_html_e( 'First Name', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_emergency_contact_first" id="remember_reg_emergency_contact_first" required class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'emergency_contact_first' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_emergency_contact_last"><?php esc_html_e( 'Last Name', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_emergency_contact_last" id="remember_reg_emergency_contact_last" required class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'emergency_contact_last' ) ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_emergency_contact_phone"><?php esc_html_e( 'Phone', 'remember' ); ?> <span class="required">*</span></label>
				<input type="tel" name="remember_reg_emergency_contact_phone" id="remember_reg_emergency_contact_phone" required class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'emergency_contact_phone' ) ); ?>" placeholder="<?php esc_attr_e( '+18055551212', 'remember' ); ?>" />
			</div>

			<div class="remember-register-row">
				<label for="remember_reg_emergency_contact_relationship"><?php esc_html_e( 'Relationship', 'remember' ); ?> <span class="required">*</span></label>
				<input type="text" name="remember_reg_emergency_contact_relationship" id="remember_reg_emergency_contact_relationship" required class="remember-register-input" value="<?php echo esc_attr( $remember_reg_val( 'emergency_contact_relationship' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g., Spouse, Parent, Friend', 'remember' ); ?>" />
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Profile Photo', 'remember' ); ?> <span class="required">*</span></h3>

			<div class="remember-register-row remember-register-row--photo">
				<span class="remember-register-label"><?php esc_html_e( 'Photo', 'remember' ); ?> <span class="required">*</span></span>
				<div class="remember-profile-photo-edit" data-output-size="<?php echo esc_attr( (string) $photo_max_dimensions ); ?>">
					<p class="remember-register-help"><?php esc_html_e( 'Required. Drag to recenter and use zoom to frame the photo.', 'remember' ); ?></p>
					<div class="remember-profile-photo-cropper" hidden>
						<div class="remember-profile-photo-cropper-viewport" aria-label="<?php esc_attr_e( 'Photo framing preview', 'remember' ); ?>">
							<img src="" alt="" class="remember-profile-photo-cropper-image" draggable="false">
						</div>
						<div class="remember-profile-photo-cropper-controls">
							<button type="button" class="remember-button remember-button-secondary remember-photo-zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'remember' ); ?>">−</button>
							<input type="range" class="remember-photo-zoom-range" min="1" max="3" step="0.01" value="1" aria-label="<?php esc_attr_e( 'Zoom', 'remember' ); ?>">
							<button type="button" class="remember-button remember-button-secondary remember-photo-zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'remember' ); ?>">+</button>
						</div>
						<button type="button" class="remember-button remember-button-secondary remember-photo-clear">
							<?php esc_html_e( 'Clear selected photo', 'remember' ); ?>
						</button>
					</div>
					<label for="remember_reg_photo_file" class="remember-form-label"><?php esc_html_e( 'Upload photo', 'remember' ); ?> <span class="required">*</span></label>
					<input type="file" id="remember_reg_photo_file" name="photo_file" class="remember-form-control remember-form-control-file remember-register-input" accept="image/jpeg,image/png,image/gif" required>
					<p class="remember-register-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: max px, 2: max MB */
								__( 'Square crop from your framing, max %1$dpx. Maximum file size %2$d MB. JPEG, PNG, or GIF.', 'remember' ),
								$photo_max_dimensions,
								(int) max( 1, round( $photo_max_bytes / 1024 / 1024 ) )
							)
						);
						?>
					</p>
				</div>
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Clothing Sizes', 'remember' ); ?></h3>
			<p class="remember-register-section-help"><?php echo esc_html( Remember_Clothing_Sizes::section_help() ); ?></p>

			<div class="remember-register-row">
				<label for="remember_reg_shirt_size"><?php esc_html_e( 'Shirt', 'remember' ); ?></label>
				<?php echo Remember_Clothing_Sizes::dropdown( 'shirt', $remember_reg_val( 'shirt_size' ), 'remember_reg_shirt_size', 'remember_reg_shirt_size', false, 'remember-register-input' ); ?>
			</div>
			<div class="remember-register-row">
				<label for="remember_reg_pants_size"><?php esc_html_e( 'Pants', 'remember' ); ?></label>
				<?php echo Remember_Clothing_Sizes::dropdown( 'pants', $remember_reg_val( 'pants_size' ), 'remember_reg_pants_size', 'remember_reg_pants_size', false, 'remember-register-input' ); ?>
			</div>
			<div class="remember-register-row">
				<label for="remember_reg_shoe_size"><?php esc_html_e( 'Shoes', 'remember' ); ?></label>
				<?php echo Remember_Clothing_Sizes::dropdown( 'shoe', $remember_reg_val( 'shoe_size' ), 'remember_reg_shoe_size', 'remember_reg_shoe_size', false, 'remember-register-input' ); ?>
			</div>

			<?php if ( ! empty( $social_platforms ) ) : ?>
				<h3 class="remember-register-section-title"><?php esc_html_e( 'Social Media', 'remember' ); ?></h3>
				<p class="remember-register-section-help"><?php esc_html_e( 'Optional. Up to 3 profiles.', 'remember' ); ?></p>
				<?php for ( $i = 0; $i < 3; $i++ ) : ?>
					<div class="remember-register-row">
						<label for="remember_reg_social_platform_<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( sprintf( /* translators: %d: slot number */ __( 'Platform %d', 'remember' ), $i + 1 ) ); ?></label>
						<div class="remember-register-im">
							<select id="remember_reg_social_platform_<?php echo esc_attr( (string) $i ); ?>" name="social_media[<?php echo esc_attr( (string) $i ); ?>][platform_id]" class="remember-register-input remember-register-input--im-type">
								<option value=""><?php esc_html_e( '— Select —', 'remember' ); ?></option>
								<?php foreach ( $social_platforms as $platform ) : ?>
									<option value="<?php echo esc_attr( $platform->platform_id ); ?>" <?php selected( isset( $remember_reg_social[ $i ] ) ? (int) $remember_reg_social[ $i ]->platform_id : 0, (int) $platform->platform_id ); ?>><?php echo esc_html( $platform->platform_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" name="social_media[<?php echo esc_attr( (string) $i ); ?>][handle]" class="remember-register-input remember-register-input--im-handle" placeholder="<?php esc_attr_e( '@handle', 'remember' ); ?>" value="<?php echo esc_attr( isset( $remember_reg_social[ $i ] ) ? $remember_reg_social[ $i ]->handle : '' ); ?>" />
						</div>
					</div>
				<?php endfor; ?>
			<?php endif; ?>

			<?php if ( ! empty( $dietary_restrictions ) ) : ?>
				<h3 class="remember-register-section-title"><?php esc_html_e( 'Dietary Restrictions', 'remember' ); ?> <span class="required">*</span></h3>
				<p class="remember-register-section-help"><?php esc_html_e( 'Required. Select at least one — choose None if none apply. For event organizers — not shown to other participants.', 'remember' ); ?></p>
				<div class="remember-register-checkboxes" data-remember-require-one="1">
					<?php foreach ( $dietary_restrictions as $restriction ) : ?>
						<label class="remember-checkbox-label">
							<input type="checkbox" name="dietary_restrictions[]" value="<?php echo esc_attr( $restriction->restriction_id ); ?>" <?php checked( in_array( (int) $restriction->restriction_id, $remember_reg_dietary_ids, true ) ); ?><?php echo ( 'None' === $restriction->restriction_name ) ? ' data-remember-none="1"' : ''; ?>>
							<span><?php echo esc_html( $restriction->restriction_name ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $medical_accommodations ) ) : ?>
				<h3 class="remember-register-section-title"><?php esc_html_e( 'Medical Accommodations', 'remember' ); ?> <span class="required">*</span></h3>
				<p class="remember-register-section-help"><?php esc_html_e( 'Required. Select at least one — choose None if none apply. For event organizers — not shown to other participants.', 'remember' ); ?></p>
				<div class="remember-register-checkboxes" data-remember-require-one="1">
					<?php foreach ( $medical_accommodations as $accommodation ) : ?>
						<label class="remember-checkbox-label">
							<input type="checkbox" name="medical_accommodations[]" value="<?php echo esc_attr( $accommodation->accommodation_id ); ?>" <?php checked( in_array( (int) $accommodation->accommodation_id, $remember_reg_medical_ids, true ) ); ?><?php echo ( 'None' === $accommodation->accommodation_name ) ? ' data-remember-none="1"' : ''; ?>>
							<span><?php echo esc_html( $accommodation->accommodation_name ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $allergies ) ) : ?>
				<?php
				$remember_reg_allergy_ids = array();
				if ( isset( $_POST['allergies'] ) && is_array( $_POST['allergies'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only.
					$remember_reg_allergy_ids = array_map( 'absint', wp_unslash( $_POST['allergies'] ) );
				}
				$remember_reg_reaction_needed = false;
				foreach ( $allergies as $allergy ) {
					if ( 'None' !== $allergy->allergy_name && in_array( (int) $allergy->allergy_id, $remember_reg_allergy_ids, true ) ) {
						$remember_reg_reaction_needed = true;
						break;
					}
				}
				$remember_reg_reaction = isset( $_POST['allergy_reaction'] ) ? sanitize_textarea_field( wp_unslash( $_POST['allergy_reaction'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only.
				?>
				<div data-remember-allergy-group="1">
					<h3 class="remember-register-section-title"><?php esc_html_e( 'Known Allergies', 'remember' ); ?> <span class="required">*</span></h3>
					<p class="remember-register-section-help"><?php esc_html_e( 'Required. Select at least one — choose None if none apply. For event organizers — not shown to other participants.', 'remember' ); ?></p>
					<div class="remember-register-checkboxes" data-remember-require-one="1">
						<?php foreach ( $allergies as $allergy ) : ?>
							<label class="remember-checkbox-label">
								<input type="checkbox" name="allergies[]" value="<?php echo esc_attr( $allergy->allergy_id ); ?>" <?php checked( in_array( (int) $allergy->allergy_id, $remember_reg_allergy_ids, true ) ); ?><?php echo ( 'None' === $allergy->allergy_name ) ? ' data-remember-none="1"' : ''; ?>>
								<span><?php echo esc_html( $allergy->allergy_name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="remember-register-row remember-register-row--stack remember-allergy-reaction" data-remember-allergy-reaction="1"<?php echo $remember_reg_reaction_needed ? '' : ' hidden'; ?>>
						<label for="remember_reg_allergy_reaction"><?php echo esc_html( Remember_Profile_Fields::allergy_reaction_prompt() ); ?> <span class="required">*</span></label>
						<textarea name="allergy_reaction" id="remember_reg_allergy_reaction" rows="5" class="remember-register-input"<?php echo $remember_reg_reaction_needed ? ' required' : ''; ?>><?php echo esc_textarea( $remember_reg_reaction ); ?></textarea>
					</div>
				</div>
			<?php endif; ?>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Interests', 'remember' ); ?></h3>
			<p class="remember-register-section-help"><?php esc_html_e( 'What are you trying to get out of this event? Optional.', 'remember' ); ?></p>
			<div class="remember-register-editor">
				<?php
				$interests_value = isset( $_POST['remember_reg_interests'] ) ? Remember_Profile_Fields::sanitize_interests( wp_unslash( $_POST['remember_reg_interests'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only.
				Remember_Profile_Fields::render_interests_editor(
					$interests_value,
					'remember_reg_interests',
					'remember_reg_interests',
					'remember-register-input'
				);
				?>
			</div>

			<h3 class="remember-register-section-title"><?php esc_html_e( 'Privacy', 'remember' ); ?></h3>
			<p class="remember-register-section-help"><?php esc_html_e( 'Optional. Consider allowing other event attendees to see at least your photo and IM so that you can begin networking with them. Use these controls to do that.', 'remember' ); ?></p>
			<div class="remember-register-checkboxes">
				<label class="remember-checkbox-label">
					<input type="checkbox" name="share_photo_with_events" value="1" <?php checked( ! empty( $_POST['share_photo_with_events'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only. ?>>
					<span><?php esc_html_e( 'Share Profile Photo', 'remember' ); ?></span>
				</label>
				<label class="remember-checkbox-label">
					<input type="checkbox" name="share_email_with_events" value="1" <?php checked( ! empty( $_POST['share_email_with_events'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only. ?>>
					<span><?php esc_html_e( 'Share Email', 'remember' ); ?></span>
				</label>
				<label class="remember-checkbox-label">
					<input type="checkbox" name="share_phone_with_events" value="1" <?php checked( ! empty( $_POST['share_phone_with_events'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only. ?>>
					<span><?php esc_html_e( 'Share Cell Phone', 'remember' ); ?></span>
				</label>
				<label class="remember-checkbox-label">
					<input type="checkbox" name="share_location_with_events" value="1" <?php checked( ! empty( $_POST['share_location_with_events'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only. ?>>
					<span><?php esc_html_e( 'Share Location', 'remember' ); ?></span>
				</label>
				<label class="remember-checkbox-label">
					<input type="checkbox" name="share_im_with_events" value="1" <?php checked( ! empty( $_POST['share_im_with_events'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only. ?>>
					<span><?php esc_html_e( 'Share Instant Messenger', 'remember' ); ?></span>
				</label>
				<label class="remember-checkbox-label">
					<input type="checkbox" name="share_interests_with_events" value="1" <?php checked( ! empty( $_POST['share_interests_with_events'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sticky display only. ?>>
					<span><?php esc_html_e( 'Share Interests', 'remember' ); ?></span>
				</label>
			</div>

			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-registration-guard.php';
			echo Remember_Registration_Guard::widget_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- site key is escaped in the helper.
			?>

			<div class="remember-register-row remember-register-row--actions">
				<span class="remember-register-row__spacer" aria-hidden="true"></span>
				<div class="remember-register-actions">
					<button type="submit" class="button button-primary remember-register-button"><?php esc_html_e( 'Create member account', 'remember' ); ?></button>
				</div>
			</div>
		</form>
	<?php endif; ?>
</div>
