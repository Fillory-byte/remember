<?php
/**
 * Member profile template
 *
 * @package    reMember
 * @subpackage reMember/public/partials
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-countries.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-import-export.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-image-uploader.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-clothing-sizes.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';

$user = wp_get_current_user();
$member_model = new Remember_Member();
$member = $member_model->get( $user->ID );

$remember_options     = get_option( 'remember_options', array() );
$photo_max_dimensions = isset( $remember_options['photo_max_dimensions'] ) ? absint( $remember_options['photo_max_dimensions'] ) : 800;
$photo_max_bytes      = isset( $remember_options['photo_max_size'] ) ? absint( $remember_options['photo_max_size'] ) : 2097152;
if ( $photo_max_dimensions < 1 ) {
	$photo_max_dimensions = 800;
}
if ( $photo_max_bytes < 1 ) {
	$photo_max_bytes = 2097152;
}

// Get profile
global $wpdb;
$profile = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
		$user->ID
	)
);

// $is_edit should be set by shortcode handler
if ( ! isset( $is_edit ) ) {
	$is_edit = isset( $_GET['edit'] ) && $_GET['edit'];
}

// Handle form submission
if ( isset( $_POST['remember_profile_action'] ) && check_admin_referer( 'remember_profile_action', 'remember_profile_nonce' ) ) {
	// Keep the submitted values briefly so a validation failure (which redirects and
	// re-renders from the database) can repopulate the form instead of discarding
	// everything the member typed. Cleared once the save succeeds (below).
	set_transient( 'remember_profile_draft_' . get_current_user_id(), wp_unslash( $_POST ), 10 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored verbatim, re-sanitized by the collectors on reuse.
	require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
	$profile_data = Remember_Profile_Fields::collect_profile_data_from_request();
	$meta_data    = Remember_Profile_Fields::collect_meta_from_request();
	$pq_answers   = Remember_Profile_Questions::collect_from_request();
	$missing      = Remember_Profile_Fields::first_missing_required( $profile_data, $meta_data );
	if ( '' !== $missing ) {
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => $missing ) ) ) );
		exit;
	}
	$missing_pq = Remember_Profile_Questions::first_missing_required( $pq_answers );
	if ( null !== $missing_pq ) {
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => 'custom_field' ) ) ) );
		exit;
	}
	$missing_health = Remember_Profile_Fields::first_missing_required_health_catalog();
	if ( '' !== $missing_health ) {
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => $missing_health ) ) ) );
		exit;
	}
	if ( Remember_Profile_Fields::allergy_reaction_is_missing() ) {
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => 'allergy_reaction' ) ) ) );
		exit;
	}
	$remember_new_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
	$remember_email_error = Remember_Profile_Fields::user_email_change_error( $user->ID, $remember_new_email );
	if ( '' !== $remember_email_error ) {
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => $remember_email_error ) ) ) );
		exit;
	}
	if ( Remember_Profile_Fields::interests_is_over_limit( $profile_data['interests'] ) ) {
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => 'interests_too_long' ) ) ) );
		exit;
	}
	if ( isset( $_POST['timezone_string'] ) ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';
		$remember_posted_timezone = sanitize_text_field( wp_unslash( $_POST['timezone_string'] ) );
		if ( '' !== $remember_posted_timezone && ! Remember_Timezone::is_valid_timezone( $remember_posted_timezone ) ) {
			wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_profile_error' => 'invalid_timezone' ) ) ) );
			exit;
		}
	}

	$current_password = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
	$new_password     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
	$confirm_password = isset( $_POST['new_password_confirm'] ) ? (string) wp_unslash( $_POST['new_password_confirm'] ) : '';
	$change_password  = ( '' !== $current_password || '' !== $new_password || '' !== $confirm_password );
	if ( $change_password ) {
		$password_error = '';
		if ( '' === $current_password || '' === $new_password || '' === $confirm_password ) {
			$password_error = 'password_missing';
		} elseif ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
			$password_error = 'password_wrong';
		} elseif ( $new_password !== $confirm_password ) {
			$password_error = 'password_mismatch';
		} else {
			$min_len = (int) apply_filters( 'remember_registration_password_min_length', 8 );
			if ( $min_len > 0 && strlen( $new_password ) < $min_len ) {
				$password_error = 'weak_password';
			}
		}
		if ( '' !== $password_error ) {
			wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( array( 'edit' => '1', 'remember_password_error' => $password_error ), remove_query_arg( array( 'remember_password_updated', 'remember_profile_error' ) ) ) ) );
			exit;
		}
	}

	$photo_error   = '';
	$has_new_photo = ! empty( $_FILES['photo_file']['name'] );

	// Upload / replace photo (takes precedence over delete).
	if ( $has_new_photo ) {
		$file_size = isset( $_FILES['photo_file']['size'] ) ? absint( $_FILES['photo_file']['size'] ) : 0;
		if ( $file_size > 0 && $file_size > $photo_max_bytes ) {
			$photo_error = sprintf(
				/* translators: %d: max size in MB */
				__( 'Photo is too large. Maximum size is %d MB.', 'remember' ),
				(int) max( 1, round( $photo_max_bytes / 1024 / 1024 ) )
			);
		} else {
			$current_member = $member_model->get( $user->ID );

			$upload_result = Remember_Image_Uploader::upload_square_image( $_FILES['photo_file'], $photo_max_dimensions );
			if ( is_wp_error( $upload_result ) ) {
				$photo_error = $upload_result->get_error_message();
			} else {
				$member_model->update_photo( $user->ID, $upload_result['url'] );
				// Delete the old photo only once the new one is saved.
				if ( $current_member && ! empty( $current_member->photo_url ) && $current_member->photo_url !== $upload_result['url'] ) {
					Remember_Image_Uploader::delete_image( $current_member->photo_url );
				}
			}
		}
	} elseif ( isset( $_POST['delete_photo'] ) && '1' === (string) wp_unslash( $_POST['delete_photo'] ) ) {
		$current_member = $member_model->get( $user->ID );
		if ( $current_member && ! empty( $current_member->photo_url ) ) {
			Remember_Image_Uploader::delete_image( $current_member->photo_url );
			$member_model->update_photo( $user->ID, null );
		}
	}

	$profile_data['updated_at'] = current_time( 'mysql' );
	$profile_data['updated_by'] = get_current_user_id();

	if ( $profile ) {
		$wpdb->update(
			$wpdb->prefix . 'remember_member_profiles',
			$profile_data,
			array( 'member_id' => $user->ID )
		);
	} else {
		$profile_data['member_id'] = $user->ID;
		$profile_data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'remember_member_profiles', $profile_data );
	}

	// Update WordPress name meta (legal names feed the public display-name choices).
	if ( ! empty( $profile_data['legal_first_name'] ) ) {
		update_user_meta( $user->ID, 'first_name', $profile_data['legal_first_name'] );
	}
	if ( ! empty( $profile_data['legal_last_name'] ) ) {
		update_user_meta( $user->ID, 'last_name', $profile_data['legal_last_name'] );
	}

	// Nickname (WP user meta) + public display_name — never auto-derived from legal name.
	$nickname = isset( $_POST['nickname'] ) ? sanitize_text_field( wp_unslash( $_POST['nickname'] ) ) : '';
	if ( '' === $nickname ) {
		$nickname = ! empty( $user->user_login ) ? $user->user_login : $user->display_name;
	}
	update_user_meta( $user->ID, 'nickname', $nickname );

	// Refresh user object so display-name choices include updated first/last/nickname.
	$user = new WP_User( $user->ID );
	$user->first_name = $profile_data['legal_first_name'];
	$user->last_name  = $profile_data['legal_last_name'];

	$requested_display = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
	$safe_display      = Remember_Member::resolve_public_display_name( $user, $nickname, $requested_display );

	$remember_user_update = array(
		'ID'           => $user->ID,
		'display_name' => $safe_display,
		'nickname'     => $nickname,
	);
	$remember_email_changed = strtolower( (string) $user->user_email ) !== strtolower( $remember_new_email );
	if ( $remember_email_changed ) {
		$remember_user_update['user_email'] = $remember_new_email;
	}
	$remember_user_result = wp_update_user( $remember_user_update );
	if ( $remember_email_changed && ! is_wp_error( $remember_user_result ) ) {
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
		Remember_Logger::info( 'Member account email updated', array( 'user_id' => $user->ID ) );
		Remember_Logger::debug(
			'Member account email updated',
			array(
				'user_id' => $user->ID,
				'email'   => $remember_new_email,
			)
		);
	}
	
	// Save timezone to WP user meta (not member_profiles)
	if ( isset( $_POST['timezone_string'] ) ) {
		$timezone_string = sanitize_text_field( wp_unslash( $_POST['timezone_string'] ) );
		if ( ! empty( $timezone_string ) ) {
			update_user_meta( $user->ID, 'timezone_string', $timezone_string );
		} else {
			// Default if empty
			update_user_meta( $user->ID, 'timezone_string', 'America/Los_Angeles' );
		}
	}
	
	Remember_Profile_Fields::save_junctions_from_request( $user->ID );
	Remember_Profile_Questions::save_for_member( $user->ID, $pq_answers );

	do_action( 'remember_member_profile_saved', $user->ID );

	if ( $change_password ) {
		wp_set_password( $new_password, $user->ID );
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-duplicates.php';
		Remember_Profile_Duplicates::stamp_password( $user->ID );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
	}

	// Save succeeded — drop the repopulation draft so a later reload shows saved data.
	delete_transient( 'remember_profile_draft_' . get_current_user_id() );

	// Redirect: stay on edit if photo failed so the member can retry.
	if ( ! empty( $photo_error ) ) {
		set_transient( 'remember_profile_photo_error_' . $user->ID, $photo_error, MINUTE_IN_SECONDS );
		wp_safe_redirect( Remember_Profile_Audit::with_return_args( add_query_arg( 'edit', '1' ) ) );
		exit;
	}

	$return_url = Remember_Profile_Audit::consume_return_url();
	if ( '' !== $return_url ) {
		wp_safe_redirect( $return_url );
		exit;
	}

	$redirect_url = remove_query_arg( array( 'edit', 'remember_photo_error', 'remember_password_error', 'remember_profile_error', 'remember_return_event', 'remember_return_apply' ) );
	if ( $change_password ) {
		$redirect_url = add_query_arg( 'remember_password_updated', '1', $redirect_url );
	} else {
		$redirect_url = remove_query_arg( 'remember_password_updated', $redirect_url );
	}
	wp_safe_redirect( $redirect_url );
	exit;
}

// Load timezone utility
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-timezone.php';

// Get timezone from WP user meta (not from member_profiles)
$selected_timezone = get_user_meta( $user->ID, 'timezone_string', true );
if ( empty( $selected_timezone ) ) {
	$selected_timezone = 'America/Los_Angeles'; // Default
	// Auto-assign default timezone
	update_user_meta( $user->ID, 'timezone_string', $selected_timezone );
}

// Get social media platforms
$social_platforms = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_social_media_platforms WHERE is_active = 1 ORDER BY sort_order ASC, platform_name ASC"
);

// Get existing social media profiles for this member
$existing_social = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sm.*, p.platform_name 
		FROM {$wpdb->prefix}remember_member_social_media sm
		JOIN {$wpdb->prefix}remember_social_media_platforms p ON sm.platform_id = p.platform_id
		WHERE sm.member_id = %d
		ORDER BY sm.created_at ASC
		LIMIT 3",
		$user->ID
	)
);
// Initialize with empty slots if less than 3
$social_profiles = array();
for ( $i = 0; $i < 3; $i++ ) {
	$social_profiles[ $i ] = isset( $existing_social[ $i ] ) ? $existing_social[ $i ] : null;
}

// Public identity fields (WordPress nickname + display_name).
$user            = new WP_User( $user->ID );
$nickname_value  = get_user_meta( $user->ID, 'nickname', true );
if ( '' === (string) $nickname_value ) {
	$nickname_value = $user->user_login;
}
$display_choices = Remember_Member::get_public_display_name_choices( $user, $nickname_value );
$current_display = $user->display_name;
if ( $current_display && ! in_array( $current_display, $display_choices, true ) ) {
	$display_choices[] = $current_display;
}

// Health / accommodation lookup lists for edit + view.
$dietary_restrictions = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_dietary_restrictions WHERE is_active = 1 ORDER BY CASE WHEN restriction_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, restriction_name ASC"
);
$medical_accommodations = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_medical_accommodations WHERE is_active = 1 ORDER BY CASE WHEN accommodation_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, accommodation_name ASC"
);
$allergies = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}remember_allergies WHERE is_active = 1 ORDER BY CASE WHEN allergy_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, allergy_name ASC"
);

$selected_dietary_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT restriction_id FROM {$wpdb->prefix}remember_member_dietary_restrictions WHERE member_id = %d",
		$user->ID
	)
);
$selected_medical_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT accommodation_id FROM {$wpdb->prefix}remember_member_medical_accommodations WHERE member_id = %d",
		$user->ID
	)
);
$selected_allergy_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT allergy_id FROM {$wpdb->prefix}remember_member_allergies WHERE member_id = %d",
		$user->ID
	)
);

$selected_dietary_names = array();
$selected_medical_names = array();
$selected_allergy_names = array();
if ( ! empty( $selected_dietary_ids ) ) {
	$selected_dietary_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT dr.restriction_name
			FROM {$wpdb->prefix}remember_dietary_restrictions dr
			INNER JOIN {$wpdb->prefix}remember_member_dietary_restrictions mdr ON dr.restriction_id = mdr.restriction_id
			WHERE mdr.member_id = %d
			ORDER BY dr.sort_order ASC, dr.restriction_name ASC",
			$user->ID
		)
	);
}
if ( ! empty( $selected_medical_ids ) ) {
	$selected_medical_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ma.accommodation_name
			FROM {$wpdb->prefix}remember_medical_accommodations ma
			INNER JOIN {$wpdb->prefix}remember_member_medical_accommodations mma ON ma.accommodation_id = mma.accommodation_id
			WHERE mma.member_id = %d
			ORDER BY ma.sort_order ASC, ma.accommodation_name ASC",
			$user->ID
		)
	);
}
if ( ! empty( $selected_allergy_ids ) ) {
	$selected_allergy_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT a.allergy_name
			FROM {$wpdb->prefix}remember_allergies a
			INNER JOIN {$wpdb->prefix}remember_member_allergies ma ON a.allergy_id = ma.allergy_id
			WHERE ma.member_id = %d
			ORDER BY a.sort_order ASC, a.allergy_name ASC",
			$user->ID
		)
	);
}

/*
 * Sticky repopulation: after a failed save the page is reloaded (redirect) with
 * ?remember_profile_error=..., and the form would otherwise render from the
 * database, discarding everything the member typed. If a draft of the submitted
 * values was stored (above), overlay it onto the render variables so the member
 * keeps their entries — including when JavaScript never ran to block the submit.
 */
$remember_pq_overrides = null;
if ( isset( $_GET['remember_profile_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- repopulation only; the failed POST was nonce-checked.
	$remember_draft = get_transient( 'remember_profile_draft_' . $user->ID );
	if ( is_array( $remember_draft ) ) {
		delete_transient( 'remember_profile_draft_' . $user->ID );
		require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
		$remember_saved_post = $_POST;
		$_POST               = $remember_draft;
		$draft_profile         = Remember_Profile_Fields::collect_profile_data_from_request();
		$draft_meta            = Remember_Profile_Fields::collect_meta_from_request();
		$remember_pq_overrides = Remember_Profile_Questions::collect_from_request();
		$_POST                 = $remember_saved_post;

		if ( ! is_object( $profile ) ) {
			$profile = (object) array();
		}
		foreach ( $draft_profile as $remember_k => $remember_v ) {
			$profile->$remember_k = $remember_v;
		}
		if ( '' !== (string) $draft_meta['nickname'] ) {
			$nickname_value = $draft_meta['nickname'];
		}
		if ( '' !== (string) $draft_meta['timezone_string'] ) {
			$selected_timezone = $draft_meta['timezone_string'];
		}
		if ( isset( $remember_draft['display_name'] ) ) {
			$current_display = sanitize_text_field( wp_unslash( $remember_draft['display_name'] ) );
			if ( '' !== $current_display && ! in_array( $current_display, $display_choices, true ) ) {
				$display_choices[] = $current_display;
			}
		}
		$selected_dietary_ids = isset( $remember_draft['dietary_restrictions'] ) ? array_map( 'absint', (array) $remember_draft['dietary_restrictions'] ) : $selected_dietary_ids;
		$selected_medical_ids = isset( $remember_draft['medical_accommodations'] ) ? array_map( 'absint', (array) $remember_draft['medical_accommodations'] ) : $selected_medical_ids;
		$selected_allergy_ids = isset( $remember_draft['allergies'] ) ? array_map( 'absint', (array) $remember_draft['allergies'] ) : $selected_allergy_ids;
	}
}
?>

<div class="remember-profile">
	<?php if ( $is_edit ) : ?>
		<?php
		$photo_error_notice = get_transient( 'remember_profile_photo_error_' . $user->ID );
		if ( $photo_error_notice ) {
			delete_transient( 'remember_profile_photo_error_' . $user->ID );
		}
		$profile_error  = isset( $_GET['remember_profile_error'] ) ? sanitize_text_field( wp_unslash( $_GET['remember_profile_error'] ) ) : '';
		$password_error = isset( $_GET['remember_password_error'] ) ? sanitize_key( wp_unslash( $_GET['remember_password_error'] ) ) : '';
		// Refresh member so photo preview reflects latest save.
		$member = $member_model->get( $user->ID );
		?>
		<div class="remember-profile-edit-header">
			<h2><?php esc_html_e( 'Edit Profile', 'remember' ); ?></h2>
			<?php if ( Remember_Profile_Audit::requested_return_event_id() > 0 || Remember_Profile_Audit::requested_return_apply() ) : ?>
				<p class="remember-form-help" style="margin: 0.5em 0 0;">
					<?php esc_html_e( 'Save this profile to continue your event application. You can save even if nothing changed — that still confirms it is current.', 'remember' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php if ( $profile_error ) : ?>
			<div class="remember-notice remember-error" role="alert">
				<p>
					<?php
					$labels = Remember_Profile_Fields::labels();
					if ( 'interests_too_long' === $profile_error ) {
						echo esc_html( Remember_Profile_Fields::interests_too_long_message() );
					} elseif ( 'invalid_timezone' === $profile_error ) {
						esc_html_e( 'Please select a valid time zone.', 'remember' );
					} elseif ( in_array( $profile_error, array( 'invalid_email', 'email_exists' ), true ) ) {
						echo esc_html( Remember_Profile_Fields::user_email_change_message( $profile_error ) );
					} elseif ( isset( $labels[ $profile_error ] ) ) {
						echo esc_html(
							sprintf(
								/* translators: %s: field label */
								__( '%s is required.', 'remember' ),
								$labels[ $profile_error ]
							)
						);
					} else {
						esc_html_e( 'Please fill in all required fields.', 'remember' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( $password_error ) : ?>
			<div class="remember-notice remember-error" role="alert">
				<p>
					<?php
					if ( 'password_wrong' === $password_error ) {
						esc_html_e( 'That current password is not correct.', 'remember' );
					} elseif ( 'password_mismatch' === $password_error ) {
						esc_html_e( 'Password and confirm password do not match.', 'remember' );
					} elseif ( 'weak_password' === $password_error ) {
						esc_html_e( 'Please choose a stronger password (at least eight characters).', 'remember' );
					} else {
						esc_html_e( 'Please enter your current password, a new password, and the confirmation.', 'remember' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( $photo_error_notice ) : ?>
			<div class="remember-notice remember-error" role="alert">
				<p><?php echo esc_html( $photo_error_notice ); ?></p>
			</div>
		<?php endif; ?>
		<form method="post" action="" class="remember-profile-form-modern" enctype="multipart/form-data">
			<?php wp_nonce_field( 'remember_profile_action', 'remember_profile_nonce' ); ?>
			<input type="hidden" name="remember_profile_action" value="update">
			<?php
			$remember_return_event = Remember_Profile_Audit::requested_return_event_id();
			if ( $remember_return_event > 0 ) :
				?>
				<input type="hidden" name="remember_return_event" value="<?php echo esc_attr( (string) $remember_return_event ); ?>">
			<?php elseif ( Remember_Profile_Audit::requested_return_apply() ) : ?>
				<input type="hidden" name="remember_return_apply" value="1">
			<?php endif; ?>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Profile Photo', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'Shown to other event participants only if you enable Share Profile Photo under Privacy Settings.', 'remember' ); ?></p>
				<div class="remember-profile-photo-edit" data-output-size="<?php echo esc_attr( (string) $photo_max_dimensions ); ?>">
					<div class="remember-profile-photo-current"<?php echo ( $member && ! empty( $member->photo_url ) ) ? '' : ' hidden'; ?>>
						<?php if ( $member && ! empty( $member->photo_url ) ) : ?>
							<div class="remember-profile-photo-preview">
								<img src="<?php echo esc_url( $member->photo_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
							</div>
							<label class="remember-checkbox-label remember-profile-photo-delete">
								<input type="checkbox" name="delete_photo" value="1" id="delete_photo">
								<span><?php esc_html_e( 'Delete current photo', 'remember' ); ?></span>
							</label>
							<p class="remember-form-help"><?php esc_html_e( 'Upload a new photo to replace the current one.', 'remember' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="remember-profile-photo-cropper" hidden>
						<div class="remember-profile-photo-cropper-viewport" aria-label="<?php esc_attr_e( 'Photo framing preview', 'remember' ); ?>">
							<img src="" alt="" class="remember-profile-photo-cropper-image" draggable="false">
						</div>
						<div class="remember-profile-photo-cropper-controls">
							<button type="button" class="remember-button remember-button-secondary remember-photo-zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'remember' ); ?>">−</button>
							<input type="range" class="remember-photo-zoom-range" min="1" max="3" step="0.01" value="1" aria-label="<?php esc_attr_e( 'Zoom', 'remember' ); ?>">
							<button type="button" class="remember-button remember-button-secondary remember-photo-zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'remember' ); ?>">+</button>
						</div>
						<p class="remember-form-help"><?php esc_html_e( 'Drag to recenter. Use zoom to frame the photo inside the circle. Your framing is saved when you click Save Profile.', 'remember' ); ?></p>
						<button type="button" class="remember-button remember-button-secondary remember-photo-clear">
							<?php esc_html_e( 'Clear selected photo', 'remember' ); ?>
						</button>
					</div>

					<label for="photo_file" class="remember-form-label"><?php esc_html_e( 'Upload photo', 'remember' ); ?></label>
					<input type="file" id="photo_file" name="photo_file" class="remember-form-control remember-form-control-file" accept="image/jpeg,image/png,image/gif">
					<p class="remember-form-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: max dimension in px, 2: max file size in MB */
								__( 'Square crop from your framing, max %1$dpx. Maximum file size %2$d MB. JPEG, PNG, or GIF.', 'remember' ),
								$photo_max_dimensions,
								(int) max( 1, round( $photo_max_bytes / 1024 / 1024 ) )
							)
						);
						?>
					</p>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Public Identity', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'These fields control how you appear to other members. Your legal name is never used here unless you choose it.', 'remember' ); ?></p>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="nickname" class="remember-form-label">
							<?php esc_html_e( 'Nickname', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<input type="text" id="nickname" name="nickname" class="remember-form-control" required
							value="<?php echo esc_attr( $nickname_value ); ?>">
						<p class="remember-form-help"><?php esc_html_e( 'WordPress nickname — available as a public display option.', 'remember' ); ?></p>
					</div>
					<div class="remember-form-col">
						<label for="display_name" class="remember-form-label">
							<?php esc_html_e( 'Display name publicly as', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<select id="display_name" name="display_name" class="remember-form-control" required>
							<?php foreach ( $display_choices as $choice ) : ?>
								<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $current_display, $choice ); ?>>
									<?php echo esc_html( $choice ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="remember-form-help"><?php esc_html_e( 'Shown in directories, dashboards, and other member-facing views.', 'remember' ); ?></p>
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Basic Information', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'Legal name is for admin and vetting only — not shown to other members.', 'remember' ); ?></p>
				<div class="remember-form-row">
					<div class="remember-form-col remember-form-col-full">
						<span class="remember-form-label"><?php esc_html_e( 'Member Number', 'remember' ); ?></span>
						<p class="remember-profile-member-number">
							<?php
							if ( $profile && ! empty( $profile->member_number ) ) {
								echo esc_html( $profile->member_number );
							} else {
								esc_html_e( 'Not assigned', 'remember' );
							}
							?>
						</p>
						<p class="remember-form-help"><?php esc_html_e( 'Assigned by staff. You can view it here but cannot change it.', 'remember' ); ?></p>
					</div>
				</div>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="legal_first_name" class="remember-form-label">
							<?php esc_html_e( 'Legal First Name', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<input type="text" id="legal_first_name" name="legal_first_name" class="remember-form-control" 
							value="<?php echo esc_attr( $profile ? $profile->legal_first_name : get_user_meta( $user->ID, 'first_name', true ) ); ?>" required>
					</div>
					<div class="remember-form-col">
						<label for="legal_last_name" class="remember-form-label">
							<?php esc_html_e( 'Legal Last Name', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<input type="text" id="legal_last_name" name="legal_last_name" class="remember-form-control" 
							value="<?php echo esc_attr( $profile ? $profile->legal_last_name : get_user_meta( $user->ID, 'last_name', true ) ); ?>" required>
					</div>
				</div>
				<?php
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
				$remember_created_display = Remember_Profile_Audit::format_datetime( $profile && isset( $profile->created_at ) ? $profile->created_at : '', (int) $user->ID );
				$remember_updated_display = Remember_Profile_Audit::format_datetime( $profile && isset( $profile->updated_at ) ? $profile->updated_at : '', (int) $user->ID );
				$remember_updated_by_name = Remember_Profile_Audit::format_updated_by_name( $profile && isset( $profile->updated_by ) ? $profile->updated_by : 0 );
				$remember_audit_bits      = array();
				if ( '' !== $remember_created_display ) {
					$remember_audit_bits[] = sprintf(
						/* translators: %s: formatted datetime */
						__( 'Created %s', 'remember' ),
						$remember_created_display
					);
				}
				if ( '' !== $remember_updated_display ) {
					$remember_audit_bits[] = sprintf(
						/* translators: %s: formatted datetime */
						__( 'Updated %s', 'remember' ),
						$remember_updated_display
					);
				}
				if ( '' !== $remember_updated_by_name ) {
					$remember_audit_bits[] = sprintf(
						/* translators: %s: display name */
						__( 'by %s', 'remember' ),
						$remember_updated_by_name
					);
				}
				if ( ! empty( $remember_audit_bits ) ) :
					?>
					<p class="remember-profile-audit-meta"><?php echo esc_html( implode( ' · ', $remember_audit_bits ) ); ?></p>
				<?php endif; ?>
			</div>

			<div class="remember-form-section" id="remember-change-password">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Change Password', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'Leave these blank to keep your current password. A new password must be at least eight characters. Save Profile applies the change; you stay logged in.', 'remember' ); ?></p>
				<div class="remember-form-row">
					<div class="remember-form-col remember-form-col-full">
						<label for="current_password" class="remember-form-label"><?php esc_html_e( 'Current password', 'remember' ); ?></label>
						<input type="password" id="current_password" name="current_password" class="remember-form-control" autocomplete="current-password" value="">
					</div>
				</div>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="new_password" class="remember-form-label"><?php esc_html_e( 'New password', 'remember' ); ?></label>
						<input type="password" id="new_password" name="new_password" class="remember-form-control" autocomplete="new-password" minlength="8" value="">
					</div>
					<div class="remember-form-col">
						<label for="new_password_confirm" class="remember-form-label"><?php esc_html_e( 'Confirm new password', 'remember' ); ?></label>
						<input type="password" id="new_password_confirm" name="new_password_confirm" class="remember-form-control" autocomplete="new-password" minlength="8" value="">
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Contact Information', 'remember' ); ?></h3>
				<div class="remember-form-row">
					<div class="remember-form-col remember-form-col-full">
						<label for="user_email" class="remember-form-label">
							<?php esc_html_e( 'Email Address', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<input type="email" id="user_email" name="user_email" class="remember-form-control" required autocomplete="email"
							value="<?php echo esc_attr( $user->user_email ); ?>">
						<p class="remember-form-help"><?php esc_html_e( 'This is your login and password-reset address. Saving a different address changes it immediately, updates the linked Xero or QuickBooks contact, and WordPress emails the previous address.', 'remember' ); ?></p>
					</div>
				</div>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="cell_phone" class="remember-form-label">
							<?php esc_html_e( 'Cell Phone', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<input type="text" id="cell_phone" name="cell_phone" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->cell_phone : '' ); ?>" placeholder="<?php esc_attr_e( '+18055551212', 'remember' ); ?>">
						<p class="remember-form-help"><?php esc_html_e( 'Include a leading + and country code. Examples: +18055551212 (USA/Canada), +447700900123 (UK).', 'remember' ); ?></p>
					</div>
					<div class="remember-form-col">
						<label for="timezone_string" class="remember-form-label">
							<?php esc_html_e( 'Time Zone', 'remember' ); ?>
							<span class="remember-required">*</span>
						</label>
						<?php echo Remember_Timezone::dropdown( $selected_timezone, 'timezone_string', 'timezone_string', true ); ?>
						<p class="remember-form-help"><?php echo esc_html( Remember_Timezone::help_text() ); ?></p>
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Address', 'remember' ); ?></h3>
				<div class="remember-form-row">
					<div class="remember-form-col remember-form-col-full">
						<label for="address_street" class="remember-form-label"><?php esc_html_e( 'Street Address', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="address_street" name="address_street" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->address_street : '' ); ?>">
					</div>
				</div>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="address_city" class="remember-form-label"><?php esc_html_e( 'City', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="address_city" name="address_city" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->address_city : '' ); ?>">
					</div>
					<div class="remember-form-col">
						<label for="address_state" class="remember-form-label"><?php esc_html_e( 'State/Province', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="address_state" name="address_state" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->address_state : '' ); ?>">
					</div>
					<div class="remember-form-col">
						<label for="address_postal" class="remember-form-label"><?php esc_html_e( 'Postal Code', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="address_postal" name="address_postal" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->address_postal : '' ); ?>">
					</div>
					<div class="remember-form-col">
						<label for="address_country" class="remember-form-label"><?php esc_html_e( 'Country', 'remember' ); ?> <span class="remember-required">*</span></label>
						<?php
						$selected_country = $profile && $profile->address_country ? $profile->address_country : 'US';
						echo Remember_Countries::dropdown(
							'address_country',
							$selected_country,
							array(
								'id'       => 'address_country',
								'class'    => 'remember-form-control',
								'required' => true,
							)
						);
						?>
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Social Media Profiles', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'Link up to 3 social media profiles.', 'remember' ); ?></p>
				<?php for ( $i = 0; $i < 3; $i++ ) : ?>
					<div class="remember-form-row remember-social-media-row">
						<div class="remember-form-col">
							<label for="social_platform_<?php echo esc_attr( $i ); ?>" class="remember-form-label">
								<?php esc_html_e( 'Platform', 'remember' ); ?>
							</label>
							<select id="social_platform_<?php echo esc_attr( $i ); ?>" name="social_media[<?php echo esc_attr( $i ); ?>][platform_id]" class="remember-form-control">
								<option value=""><?php esc_html_e( '-- Select Platform --', 'remember' ); ?></option>
								<?php foreach ( $social_platforms as $platform ) : 
									$selected = $social_profiles[ $i ] && $social_profiles[ $i ]->platform_id == $platform->platform_id;
								?>
									<option value="<?php echo esc_attr( $platform->platform_id ); ?>" <?php selected( $selected ); ?>>
										<?php echo esc_html( $platform->platform_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="remember-form-col">
							<label for="social_handle_<?php echo esc_attr( $i ); ?>" class="remember-form-label">
								<?php esc_html_e( 'Handle/Username', 'remember' ); ?>
							</label>
							<input type="text" id="social_handle_<?php echo esc_attr( $i ); ?>" name="social_media[<?php echo esc_attr( $i ); ?>][handle]" 
								class="remember-form-control" 
								value="<?php echo esc_attr( $social_profiles[ $i ] ? $social_profiles[ $i ]->handle : '' ); ?>" 
								placeholder="<?php esc_attr_e( 'e.g., @username', 'remember' ); ?>">
						</div>
					</div>
				<?php endfor; ?>
			</div>

			<?php if ( ! empty( $dietary_restrictions ) ) : ?>
				<div class="remember-form-section">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Dietary Restrictions', 'remember' ); ?> <span class="remember-required">*</span></h3>
					<p class="remember-form-help"><?php esc_html_e( 'Required. Select at least one — choose None if none apply. Used by event organizers — not shown to other participants.', 'remember' ); ?></p>
					<div class="remember-checkbox-grid" data-remember-require-one="1">
						<?php foreach ( $dietary_restrictions as $restriction ) : ?>
							<label class="remember-checkbox-label">
								<input type="checkbox" name="dietary_restrictions[]" value="<?php echo esc_attr( $restriction->restriction_id ); ?>" <?php checked( in_array( (string) $restriction->restriction_id, array_map( 'strval', (array) $selected_dietary_ids ), true ) ); ?><?php echo ( 'None' === $restriction->restriction_name ) ? ' data-remember-none="1"' : ''; ?>>
								<span><?php echo esc_html( $restriction->restriction_name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $medical_accommodations ) ) : ?>
				<div class="remember-form-section">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Medical Accommodations', 'remember' ); ?> <span class="remember-required">*</span></h3>
					<p class="remember-form-help"><?php esc_html_e( 'Required. Select at least one — choose None if none apply. Used by event organizers — not shown to other participants.', 'remember' ); ?></p>
					<div class="remember-checkbox-grid" data-remember-require-one="1">
						<?php foreach ( $medical_accommodations as $accommodation ) : ?>
							<label class="remember-checkbox-label">
								<input type="checkbox" name="medical_accommodations[]" value="<?php echo esc_attr( $accommodation->accommodation_id ); ?>" <?php checked( in_array( (string) $accommodation->accommodation_id, array_map( 'strval', (array) $selected_medical_ids ), true ) ); ?><?php echo ( 'None' === $accommodation->accommodation_name ) ? ' data-remember-none="1"' : ''; ?>>
								<span>
									<?php echo esc_html( $accommodation->accommodation_name ); ?>
									<?php if ( ! empty( $accommodation->description ) ) : ?>
										<span class="remember-form-help" style="display:inline; margin-left:0.35em;">— <?php echo esc_html( $accommodation->description ); ?></span>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $allergies ) ) : ?>
				<?php
				$remember_reaction_needed = false;
				foreach ( $allergies as $allergy ) {
					if ( 'None' !== $allergy->allergy_name && in_array( (string) $allergy->allergy_id, array_map( 'strval', (array) $selected_allergy_ids ), true ) ) {
						$remember_reaction_needed = true;
						break;
					}
				}
				$remember_reaction_text = ( $profile && isset( $profile->allergy_reaction ) ) ? (string) $profile->allergy_reaction : '';
				?>
				<div class="remember-form-section" data-remember-allergy-group="1">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Known Allergies', 'remember' ); ?> <span class="remember-required">*</span></h3>
					<p class="remember-form-help"><?php esc_html_e( 'Required. Select at least one — choose None if none apply. Used by event organizers — not shown to other participants.', 'remember' ); ?></p>
					<div class="remember-checkbox-grid" data-remember-require-one="1">
						<?php foreach ( $allergies as $allergy ) : ?>
							<label class="remember-checkbox-label">
								<input type="checkbox" name="allergies[]" value="<?php echo esc_attr( $allergy->allergy_id ); ?>" <?php checked( in_array( (string) $allergy->allergy_id, array_map( 'strval', (array) $selected_allergy_ids ), true ) ); ?><?php echo ( 'None' === $allergy->allergy_name ) ? ' data-remember-none="1"' : ''; ?>>
								<span><?php echo esc_html( $allergy->allergy_name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="remember-allergy-reaction" data-remember-allergy-reaction="1"<?php echo $remember_reaction_needed ? '' : ' hidden'; ?>>
						<div class="remember-form-row">
							<div class="remember-form-col remember-form-col-full">
								<label for="remember_allergy_reaction" class="remember-form-label"><?php echo esc_html( Remember_Profile_Fields::allergy_reaction_prompt() ); ?> <span class="remember-required">*</span></label>
								<textarea name="allergy_reaction" id="remember_allergy_reaction" rows="5" class="remember-form-control"<?php echo $remember_reaction_needed ? ' required' : ''; ?>><?php echo esc_textarea( $remember_reaction_text ); ?></textarea>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Instant Messenger', 'remember' ); ?> <span class="remember-required">*</span></h3>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="im_type" class="remember-form-label"><?php esc_html_e( 'IM Type', 'remember' ); ?></label>
						<?php
						require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
						$im_selected = ( $profile && ! empty( $profile->im_type ) ) ? $profile->im_type : Remember_Im_Platforms::default_key();
						?>
						<select id="im_type" name="im_type" class="remember-form-control" required>
							<?php Remember_Im_Platforms::render_options( $im_selected ); ?>
						</select>
					</div>
					<div class="remember-form-col">
						<label for="im_handle" class="remember-form-label"><?php esc_html_e( 'Handle', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="im_handle" name="im_handle" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->im_handle : '' ); ?>" placeholder="<?php esc_attr_e( 'Handle', 'remember' ); ?>">
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Clothing Sizes', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php echo esc_html( Remember_Clothing_Sizes::section_help() ); ?></p>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="shirt_size" class="remember-form-label"><?php esc_html_e( 'Shirt', 'remember' ); ?></label>
						<?php
						echo Remember_Clothing_Sizes::dropdown(
							'shirt',
							$profile && isset( $profile->shirt_size ) ? $profile->shirt_size : '',
							'shirt_size',
							'shirt_size',
							false,
							'remember-form-control'
						);
						?>
					</div>
					<div class="remember-form-col">
						<label for="pants_size" class="remember-form-label"><?php esc_html_e( 'Pants', 'remember' ); ?></label>
						<?php
						echo Remember_Clothing_Sizes::dropdown(
							'pants',
							$profile && isset( $profile->pants_size ) ? $profile->pants_size : '',
							'pants_size',
							'pants_size',
							false,
							'remember-form-control'
						);
						?>
					</div>
				</div>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="shoe_size" class="remember-form-label"><?php esc_html_e( 'Shoes', 'remember' ); ?></label>
						<?php
						echo Remember_Clothing_Sizes::dropdown(
							'shoe',
							$profile && isset( $profile->shoe_size ) ? $profile->shoe_size : '',
							'shoe_size',
							'shoe_size',
							false,
							'remember-form-control'
						);
						?>
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Interests', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'What are you trying to get out of this event?', 'remember' ); ?></p>
				<div class="remember-form-row">
					<div class="remember-form-col remember-form-col-full">
						<label for="interests" class="remember-form-label"><?php esc_html_e( 'Interests', 'remember' ); ?></label>
						<?php
						Remember_Profile_Fields::render_interests_editor(
							$profile && isset( $profile->interests ) ? $profile->interests : '',
							'interests',
							'interests',
							'remember-form-control'
						);
						?>
					</div>
				</div>
			</div>

			<?php
			require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
			Remember_Profile_Questions::render_fields( (int) $user->ID, 'front', isset( $remember_pq_overrides ) ? $remember_pq_overrides : null );
			?>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Emergency Contact', 'remember' ); ?></h3>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="emergency_contact_first" class="remember-form-label"><?php esc_html_e( 'First Name', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="emergency_contact_first" name="emergency_contact_first" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->emergency_contact_first : '' ); ?>">
					</div>
					<div class="remember-form-col">
						<label for="emergency_contact_last" class="remember-form-label"><?php esc_html_e( 'Last Name', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="emergency_contact_last" name="emergency_contact_last" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->emergency_contact_last : '' ); ?>">
					</div>
				</div>
				<div class="remember-form-row">
					<div class="remember-form-col">
						<label for="emergency_contact_phone" class="remember-form-label"><?php esc_html_e( 'Phone', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="emergency_contact_phone" name="emergency_contact_phone" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->emergency_contact_phone : '' ); ?>" placeholder="<?php esc_attr_e( '+18055551212', 'remember' ); ?>">
					</div>
					<div class="remember-form-col">
						<label for="emergency_contact_relationship" class="remember-form-label"><?php esc_html_e( 'Relationship', 'remember' ); ?> <span class="remember-required">*</span></label>
						<input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" class="remember-form-control" required
							value="<?php echo esc_attr( $profile ? $profile->emergency_contact_relationship : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g., Spouse, Parent, Friend', 'remember' ); ?>">
					</div>
				</div>
			</div>

			<div class="remember-form-section">
				<h3 class="remember-form-section-title"><?php esc_html_e( 'Privacy Settings', 'remember' ); ?></h3>
				<p class="remember-form-help"><?php esc_html_e( 'Optional. Consider allowing other event attendees to see at least your photo and IM so that you can begin networking with them. Use these controls to do that.', 'remember' ); ?></p>
				<div class="remember-privacy-checkboxes">
					<label class="remember-checkbox-label">
						<input type="checkbox" name="share_photo_with_events" value="1" <?php checked( $profile && isset( $profile->share_photo_with_events ) ? $profile->share_photo_with_events : 0, 1 ); ?>>
						<span><?php esc_html_e( 'Share Profile Photo', 'remember' ); ?></span>
					</label>
					<label class="remember-checkbox-label">
						<input type="checkbox" name="share_email_with_events" value="1" <?php checked( $profile && isset( $profile->share_email_with_events ) ? $profile->share_email_with_events : 0, 1 ); ?>>
						<span><?php esc_html_e( 'Share Email Address', 'remember' ); ?></span>
					</label>
					<label class="remember-checkbox-label">
						<input type="checkbox" name="share_phone_with_events" value="1" <?php checked( $profile && isset( $profile->share_phone_with_events ) ? $profile->share_phone_with_events : 0, 1 ); ?>>
						<span><?php esc_html_e( 'Share Phone Number', 'remember' ); ?></span>
					</label>
					<label class="remember-checkbox-label">
						<input type="checkbox" name="share_location_with_events" value="1" <?php checked( $profile && isset( $profile->share_location_with_events ) ? $profile->share_location_with_events : 0, 1 ); ?>>
						<span><?php esc_html_e( 'Share City, State, Country', 'remember' ); ?></span>
					</label>
					<label class="remember-checkbox-label">
						<input type="checkbox" name="share_im_with_events" value="1" <?php checked( $profile && isset( $profile->share_im_with_events ) ? $profile->share_im_with_events : 0, 1 ); ?>>
						<span><?php esc_html_e( 'Share Instant Messenger', 'remember' ); ?></span>
					</label>
					<label class="remember-checkbox-label">
						<input type="checkbox" name="share_interests_with_events" value="1" <?php checked( $profile && isset( $profile->share_interests_with_events ) ? $profile->share_interests_with_events : 0, 1 ); ?>>
						<span><?php esc_html_e( 'Share Interests', 'remember' ); ?></span>
					</label>
				</div>
			</div>

			<div class="remember-form-actions">
				<button type="submit" class="remember-button remember-button-primary">
					<?php esc_html_e( 'Save Profile', 'remember' ); ?>
				</button>
				<a href="<?php echo esc_url( remove_query_arg( 'edit' ) ); ?>" class="remember-button remember-button-secondary">
					<?php esc_html_e( 'Cancel', 'remember' ); ?>
				</a>
			</div>
		</form>
	<?php else : ?>
		<?php
		$member      = $member_model->get( $user->ID );
		$password_ok = isset( $_GET['remember_password_updated'] ) && '1' === (string) wp_unslash( $_GET['remember_password_updated'] );
		?>
		<?php if ( $password_ok ) : ?>
			<div class="remember-notice remember-success" role="status">
				<p><?php esc_html_e( 'Your password has been updated.', 'remember' ); ?></p>
			</div>
		<?php endif; ?>
		<div class="remember-profile-edit-header">
			<?php if ( $member && ! empty( $member->photo_url ) ) : ?>
				<div class="remember-profile-photo-preview remember-profile-photo-preview--header">
					<img src="<?php echo esc_url( $member->photo_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
				</div>
			<?php endif; ?>
			<h2><?php echo esc_html( $user->display_name ); ?></h2>
			<a href="<?php echo esc_url( add_query_arg( 'edit', '1' ) ); ?>" class="remember-button remember-button-primary">
				<?php esc_html_e( 'Edit Profile', 'remember' ); ?>
			</a>
		</div>

		<?php if ( $profile ) : ?>
			<div class="remember-profile-view-modern">
				<div class="remember-form-section">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Basic Information', 'remember' ); ?></h3>
					<div class="remember-profile-view-grid">
						<div class="remember-profile-view-item">
							<strong class="remember-profile-view-label"><?php esc_html_e( 'Nickname', 'remember' ); ?></strong>
							<span class="remember-profile-view-value"><?php echo esc_html( $nickname_value ); ?></span>
						</div>
						<div class="remember-profile-view-item">
							<strong class="remember-profile-view-label"><?php esc_html_e( 'Display Name', 'remember' ); ?></strong>
							<span class="remember-profile-view-value"><?php echo esc_html( $user->display_name ); ?></span>
						</div>
						<div class="remember-profile-view-item">
							<strong class="remember-profile-view-label"><?php esc_html_e( 'Member Number', 'remember' ); ?></strong>
							<span class="remember-profile-view-value">
								<?php
								if ( ! empty( $profile->member_number ) ) {
									echo esc_html( $profile->member_number );
								} else {
									esc_html_e( 'Not assigned', 'remember' );
								}
								?>
							</span>
						</div>
						<?php
						$remember_legal_name_line = trim( Remember_Import_Export::member_list_legal_name_line( $profile, (int) $user->ID ) );
						if ( ! empty( $remember_legal_name_line ) ) :
							?>
							<div class="remember-profile-view-item">
								<strong class="remember-profile-view-label"><?php esc_html_e( 'Legal Name (private)', 'remember' ); ?></strong>
								<span class="remember-profile-view-value"><?php echo esc_html( $remember_legal_name_line ); ?></span>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $user->user_email ) ) : ?>
							<div class="remember-profile-view-item">
								<strong class="remember-profile-view-label"><?php esc_html_e( 'Email Address', 'remember' ); ?></strong>
								<span class="remember-profile-view-value">
									<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
								</span>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $profile->cell_phone ) ) : ?>
							<div class="remember-profile-view-item">
								<strong class="remember-profile-view-label"><?php esc_html_e( 'Cell Phone', 'remember' ); ?></strong>
								<span class="remember-profile-view-value">
									<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $profile->cell_phone ) ); ?>">
										<?php echo esc_html( $profile->cell_phone ); ?>
									</a>
								</span>
							</div>
						<?php endif; ?>
						<?php
						$selected_timezone = get_user_meta( $user->ID, 'timezone_string', true );
						if ( ! empty( $selected_timezone ) ) :
						?>
							<div class="remember-profile-view-item">
								<strong class="remember-profile-view-label"><?php esc_html_e( 'Time Zone', 'remember' ); ?></strong>
								<span class="remember-profile-view-value"><?php echo esc_html( Remember_Timezone::label( $selected_timezone ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
					<?php
					require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
					$remember_created_display = Remember_Profile_Audit::format_datetime( isset( $profile->created_at ) ? $profile->created_at : '', (int) $user->ID );
					$remember_updated_display = Remember_Profile_Audit::format_datetime( isset( $profile->updated_at ) ? $profile->updated_at : '', (int) $user->ID );
					$remember_updated_by_name = Remember_Profile_Audit::format_updated_by_name( isset( $profile->updated_by ) ? $profile->updated_by : 0 );
					$remember_audit_bits      = array();
					if ( '' !== $remember_created_display ) {
						$remember_audit_bits[] = sprintf(
							/* translators: %s: formatted datetime */
							__( 'Created %s', 'remember' ),
							$remember_created_display
						);
					}
					if ( '' !== $remember_updated_display ) {
						$remember_audit_bits[] = sprintf(
							/* translators: %s: formatted datetime */
							__( 'Updated %s', 'remember' ),
							$remember_updated_display
						);
					}
					if ( '' !== $remember_updated_by_name ) {
						$remember_audit_bits[] = sprintf(
							/* translators: %s: display name */
							__( 'by %s', 'remember' ),
							$remember_updated_by_name
						);
					}
					if ( ! empty( $remember_audit_bits ) ) :
						?>
						<p class="remember-profile-audit-meta"><?php echo esc_html( implode( ' · ', $remember_audit_bits ) ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $profile->address_street ) || ! empty( $profile->address_city ) || ! empty( $profile->address_state ) || ! empty( $profile->address_postal ) || ! empty( $profile->address_country ) ) : ?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Address', 'remember' ); ?></h3>
						<div class="remember-profile-view-grid">
							<?php if ( ! empty( $profile->address_street ) ) : ?>
								<div class="remember-profile-view-item remember-profile-view-item-full">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Street Address', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $profile->address_street ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->address_city ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'City', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $profile->address_city ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->address_state ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'State/Province', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $profile->address_state ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->address_postal ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Postal Code', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $profile->address_postal ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->address_country ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Country', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $profile->address_country ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php
				// Get social media profiles
				$social_profiles = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT sm.*, p.platform_name 
						FROM {$wpdb->prefix}remember_member_social_media sm
						JOIN {$wpdb->prefix}remember_social_media_platforms p ON sm.platform_id = p.platform_id
						WHERE sm.member_id = %d
						ORDER BY sm.created_at ASC",
						$user->ID
					)
				);
				if ( ! empty( $social_profiles ) ) :
				?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Social Media Profiles', 'remember' ); ?></h3>
						<div class="remember-profile-view-grid">
							<?php foreach ( $social_profiles as $social ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php echo esc_html( $social->platform_name ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $social->handle ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="remember-form-section">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Dietary Restrictions', 'remember' ); ?></h3>
					<div class="remember-profile-view-item remember-profile-view-item-full">
						<span class="remember-profile-view-value"><?php echo esc_html( ! empty( $selected_dietary_names ) ? implode( ', ', $selected_dietary_names ) : __( 'None Selected', 'remember' ) ); ?></span>
					</div>
				</div>

				<div class="remember-form-section">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Medical Accommodations', 'remember' ); ?></h3>
					<div class="remember-profile-view-item remember-profile-view-item-full">
						<span class="remember-profile-view-value"><?php echo esc_html( ! empty( $selected_medical_names ) ? implode( ', ', $selected_medical_names ) : __( 'None Selected', 'remember' ) ); ?></span>
					</div>
				</div>

				<div class="remember-form-section">
					<h3 class="remember-form-section-title"><?php esc_html_e( 'Known Allergies', 'remember' ); ?></h3>
					<div class="remember-profile-view-item remember-profile-view-item-full">
						<span class="remember-profile-view-value"><?php echo esc_html( ! empty( $selected_allergy_names ) ? implode( ', ', $selected_allergy_names ) : __( 'None Selected', 'remember' ) ); ?></span>
					</div>
					<?php if ( $profile && ! empty( $profile->allergy_reaction ) ) : ?>
						<div class="remember-profile-view-item remember-profile-view-item-full">
							<strong class="remember-profile-view-label"><?php echo esc_html( Remember_Profile_Fields::allergy_reaction_prompt() ); ?></strong>
							<span class="remember-profile-view-value"><?php echo nl2br( esc_html( (string) $profile->allergy_reaction ) ); ?></span>
						</div>
					<?php endif; ?>
				</div>

				<?php
				$has_sizes = ( ! empty( $profile->shirt_size ) || ! empty( $profile->pants_size ) || ! empty( $profile->shoe_size ) );
				if ( $has_sizes ) :
					?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Clothing Sizes', 'remember' ); ?></h3>
						<div class="remember-profile-view-grid">
							<?php if ( ! empty( $profile->shirt_size ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Shirt', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( Remember_Clothing_Sizes::format_pair( 'shirt', $profile->shirt_size ) ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->pants_size ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Pants', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( Remember_Clothing_Sizes::format_pair( 'pants', $profile->pants_size ) ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->shoe_size ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Shoes', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( Remember_Clothing_Sizes::format_pair( 'shoe', $profile->shoe_size ) ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $profile->im_handle ) ) : ?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Instant Messenger', 'remember' ); ?></h3>
						<div class="remember-profile-view-grid">
							<div class="remember-profile-view-item">
								<strong class="remember-profile-view-label"><?php esc_html_e( 'IM Type', 'remember' ); ?></strong>
								<span class="remember-profile-view-value"><?php
									require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
									echo esc_html( Remember_Im_Platforms::get_label( $profile->im_type ) );
								?></span>
							</div>
							<div class="remember-profile-view-item">
								<strong class="remember-profile-view-label"><?php esc_html_e( 'Handle', 'remember' ); ?></strong>
								<span class="remember-profile-view-value"><?php echo esc_html( $profile->im_handle ); ?></span>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $profile->interests ) ) : ?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Interests', 'remember' ); ?></h3>
						<div class="remember-profile-view-item remember-profile-view-item-full">
							<span class="remember-profile-view-value remember-richtext"><?php echo Remember_Profile_Fields::interests_html( $profile->interests ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- interests_html() keeps only b, i, em, u, ul, ol, li, p, and br. ?></span>
						</div>
					</div>
				<?php endif; ?>

				<?php
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-profile-question.php';
				require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
				$pq_map    = Remember_Profile_Questions::get_responses_map( (int) $user->ID );
				$pq_active = array();
				foreach ( ( new Remember_Profile_Question() )->get_active() as $pq ) {
					$qid = (int) $pq->question_id;
					$val = isset( $pq_map[ $qid ] ) ? $pq_map[ $qid ] : '';
					if ( '' === $val ) {
						continue;
					}
					$pq_active[] = array( $pq, $val );
				}
				if ( ! empty( $pq_active ) ) :
					?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Additional questions', 'remember' ); ?></h3>
						<div class="remember-profile-view-grid">
							<?php foreach ( $pq_active as $pair ) : ?>
								<?php
								$pq  = $pair[0];
								$val = $pair[1];
								?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php echo esc_html( $pq->label ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( Remember_Profile_Questions::display_value( $pq, $val ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $profile->emergency_contact_first ) || ! empty( $profile->emergency_contact_last ) || ! empty( $profile->emergency_contact_phone ) || ! empty( $profile->emergency_contact_relationship ) ) : ?>
					<div class="remember-form-section">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Emergency Contact', 'remember' ); ?></h3>
						<div class="remember-profile-view-grid">
							<?php if ( ! empty( $profile->emergency_contact_first ) || ! empty( $profile->emergency_contact_last ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Name', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( trim( ( $profile->emergency_contact_first ?? '' ) . ' ' . ( $profile->emergency_contact_last ?? '' ) ) ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->emergency_contact_phone ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Phone', 'remember' ); ?></strong>
									<span class="remember-profile-view-value">
										<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $profile->emergency_contact_phone ) ); ?>">
											<?php echo esc_html( $profile->emergency_contact_phone ); ?>
										</a>
									</span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $profile->emergency_contact_relationship ) ) : ?>
								<div class="remember-profile-view-item">
									<strong class="remember-profile-view-label"><?php esc_html_e( 'Relationship', 'remember' ); ?></strong>
									<span class="remember-profile-view-value"><?php echo esc_html( $profile->emergency_contact_relationship ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php
				require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-profile-note.php';
				$member_profile_notes = ( new Remember_Profile_Note() )->get_for_member( (int) $user->ID, false );
				if ( ! empty( $member_profile_notes ) ) :
					?>
					<div class="remember-form-section remember-profile-notes">
						<h3 class="remember-form-section-title"><?php esc_html_e( 'Notes from staff', 'remember' ); ?></h3>
						<ul class="remember-profile-notes-list">
							<?php foreach ( $member_profile_notes as $p_note ) : ?>
								<?php $p_note_author = get_user_by( 'ID', $p_note->author_id ); ?>
								<li>
									<div class="remember-profile-notes-meta">
										<strong><?php echo $p_note_author ? esc_html( $p_note_author->display_name ) : esc_html__( 'Staff', 'remember' ); ?></strong>
										<span class="remember-note-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p_note->created_at ) ) ); ?></span>
									</div>
									<div class="remember-profile-notes-body"><?php echo wp_kses_post( nl2br( esc_html( $p_note->note_content ) ) ); ?></div>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<p class="remember-description"><?php esc_html_e( 'No profile information yet. Edit your profile to get started.', 'remember' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
