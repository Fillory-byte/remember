<?php
/**
 * Event member directory template
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
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-fields.php';

$event_id = isset( $atts['event_id'] ) ? absint( $atts['event_id'] ) : 0;

$event_model = new Remember_Event();
$application_model = new Remember_Application();
$member_model = new Remember_Member();

$event = $event_model->get( $event_id );

if ( ! $event ) {
	echo '<p class="remember-notice remember-error">' . esc_html__( 'Event not found.', 'remember' ) . '</p>';
	return;
}

// Get current user's member record
$current_user_id = get_current_user_id();
$current_member = $member_model->get( $current_user_id );

if ( ! $current_member ) {
	echo '<p class="remember-notice remember-error">' . esc_html__( 'You must be registered as a member to view this directory.', 'remember' ) . '</p>';
	return;
}

if ( ! Remember_Member::is_vetted_member( $current_member ) ) {
	echo '<p class="remember-notice remember-warning">' . esc_html__( 'Member is not yet vetted.', 'remember' ) . '</p>';
	return;
}

// Check if current user is accepted to this event
$applications = $application_model->get_by_event( $event_id );
$current_user_application = null;
foreach ( $applications as $app ) {
	if ( absint( $app->member_id ) === absint( $current_user_id ) && 'accepted' === $app->status ) {
		$current_user_application = $app;
		break;
	}
}

if ( ! $current_user_application ) {
	echo '<p class="remember-notice remember-error">' . esc_html__( 'You must be accepted to this event to view other members.', 'remember' ) . '</p>';
	return;
}

// Get all accepted members for this event
$accepted_applications = array();
foreach ( $applications as $app ) {
	if ( 'accepted' === $app->status ) {
		$accepted_applications[] = $app;
	}
}

if ( empty( $accepted_applications ) ) {
	echo '<p class="remember-notice remember-info">' . esc_html__( 'No other members found for this event.', 'remember' ) . '</p>';
	return;
}

// Get event roles
global $wpdb;
$event_roles = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT er.*, r.role_name 
		FROM {$wpdb->prefix}remember_event_roles er 
		JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
		WHERE er.event_id = %d 
		ORDER BY r.role_name ASC",
		$event_id
	)
);

$role_map = array();
foreach ( $event_roles as $event_role ) {
	$role_map[ $event_role->event_role_id ] = $event_role->role_name;
}
?>

<div class="remember-event-directory">
	<p class="remember-description"><?php esc_html_e( 'Members accepted to this event. Contact information is shown only if members have chosen to share it.', 'remember' ); ?></p>

	<div class="remember-member-directory">
		<?php foreach ( $accepted_applications as $app ) : 
			// Skip current user
			if ( absint( $app->member_id ) === absint( $current_user_id ) ) {
				continue;
			}

			$member = $member_model->get( $app->member_id );
			if ( ! $member ) {
				continue;
			}

			$user = get_user_by( 'ID', $app->member_id );
			if ( ! $user ) {
				continue;
			}

			// Get member profile
			$profile = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
					$app->member_id
				)
			);

			$role_name = isset( $role_map[ $app->event_role_id ] ) ? $role_map[ $app->event_role_id ] : __( 'Unknown Role', 'remember' );
			
			// Get member photo or use avatar — only show uploaded photo when sharing is enabled.
			$member = $member_model->get( $app->member_id );
			$share_photo = $profile && ! empty( $profile->share_photo_with_events );
			$photo_url = ( $share_photo && $member && ! empty( $member->photo_url ) ) ? $member->photo_url : null;
		?>
			<article class="remember-member-card">
				<div class="remember-member-card-header">
					<?php if ( $photo_url ) : ?>
						<div class="remember-member-avatar">
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;">
						</div>
					<?php else : ?>
						<div class="remember-member-avatar">
							<?php echo get_avatar( $app->member_id, 80, '', '', array( 'class' => 'remember-avatar', 'style' => 'width: 80px; height: 80px; border-radius: 50%;' ) ); ?>
						</div>
					<?php endif; ?>
					<div class="remember-member-name-role">
						<h4><?php echo esc_html( $user->display_name ); ?></h4>
						<p class="remember-member-role"><?php echo esc_html( $role_name ); ?></p>
					</div>
				</div>

				<div class="remember-member-info">
					<?php if ( $profile ) : ?>
						<?php if ( ! empty( $profile->share_email_with_events ) && ! empty( $user->user_email ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'Email:', 'remember' ); ?></strong><br>
								<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
									<?php echo esc_html( $user->user_email ); ?>
								</a>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $profile->share_phone_with_events ) && ! empty( $profile->cell_phone ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'Phone:', 'remember' ); ?></strong><br>
								<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $profile->cell_phone ) ); ?>">
									<?php echo esc_html( $profile->cell_phone ); ?>
								</a>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $profile->share_location_with_events ) ) : ?>
							<?php
							$location_parts = array_filter( array(
								$profile->address_city,
								$profile->address_state,
								$profile->address_country
							) );
							if ( ! empty( $location_parts ) ) :
							?>
								<p>
									<strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong><br>
									<?php echo esc_html( implode( ', ', $location_parts ) ); ?>
								</p>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( ! empty( $profile->share_im_with_events ) && ! empty( $profile->im_handle ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'IM:', 'remember' ); ?></strong><br>
								<?php
								require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-im-platforms.php';
								require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-platform-handles.php';
								echo esc_html( Remember_Im_Platforms::get_label( $profile->im_type ) );
								?>: <?php echo Remember_Platform_Handles::im_html( $profile->im_type, $profile->im_handle ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- im_html() escapes the name and address. ?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $profile->share_interests_with_events ) && ! empty( $profile->interests ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'Interests:', 'remember' ); ?></strong><br>
								<?php echo Remember_Profile_Fields::interests_html( $profile->interests ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- interests_html() keeps only b, i, em, u, ul, ol, li, p, and br. ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>

					<?php
					$has_shared_contact = $profile && (
						! empty( $profile->share_email_with_events ) ||
						! empty( $profile->share_phone_with_events ) ||
						! empty( $profile->share_location_with_events ) ||
						! empty( $profile->share_im_with_events ) ||
						! empty( $profile->share_interests_with_events )
					);
					if ( ! $has_shared_contact ) :
					?>
						<p class="remember-description"><?php esc_html_e( 'No contact information shared.', 'remember' ); ?></p>
					<?php endif; ?>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</div>
