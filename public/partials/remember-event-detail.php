<?php
/**
 * Event detail template
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
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-location.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-event-invites.php';

$event_model = new Remember_Event();
$application_model = new Remember_Application();
$location_model = new Remember_Location();
$member_model = new Remember_Member();

$event = $event_model->get( $event_id );

if ( ! $event || ! Remember_Event_Invites::member_can_see( $event, get_current_user_id() ) ) {
	echo '<p class="remember-notice remember-error">' . esc_html__( 'Event not found.', 'remember' ) . '</p>';
	return;
}

// Get current user's member_id (applications use member_id, not user_id)
$current_user_id = get_current_user_id();
$current_member = $member_model->get( $current_user_id );

// Check if user is accepted to this event
$is_accepted = false;
if ( $current_member ) {
	$user_applications = $application_model->get_by_member( $current_user_id );
	foreach ( $user_applications as $app ) {
		// Use loose comparison for event_id in case of type mismatch (string vs int)
		if ( absint( $app->event_id ) === absint( $event->event_id ) && 'accepted' === $app->status ) {
			$is_accepted = true;
			break;
		}
	}
}

// Get location if available
$location = $event->location_id ? $location_model->get( $event->location_id ) : null;

// Status labels
$status_labels = array(
	'draft'     => __( 'Draft', 'remember' ),
	'open'      => __( 'Open', 'remember' ),
	'closed'    => __( 'Closed', 'remember' ),
	'cancelled' => __( 'Cancelled', 'remember' ),
	'completed' => __( 'Completed', 'remember' ),
);

$status_colors = array(
	'draft'     => '#666',
	'open'      => '#46b450',
	'closed'    => '#dc3232',
	'cancelled' => '#dc3232',
	'completed' => '#666',
);

// Format dates
$start_date = date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) );
$end_date = date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) );
$is_multi_day = ( $event->start_date !== $event->end_date );
?>

<div class="remember-event-detail">
	<div class="remember-event-detail-header">
		<h2><?php echo esc_html( $event->event_name ); ?></h2>
		<?php if ( ! empty( $event->event_description ) ) : ?>
			<div class="remember-event-description">
				<?php echo wp_kses_post( wpautop( $event->event_description ) ); ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $is_accepted && ! empty( $event->attendee_details ) ) : ?>
		<div class="remember-event-attendee-details">
			<h3><?php esc_html_e( 'Attendee details', 'remember' ); ?></h3>
			<p class="remember-form-help"><?php esc_html_e( 'Visible only to accepted attendees for this event.', 'remember' ); ?></p>
			<div class="remember-richtext">
				<?php echo wp_kses_post( wpautop( $event->attendee_details ) ); ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="remember-event-detail-info">
		<div class="remember-event-info-grid">
			<div class="remember-event-info-item">
				<strong><?php esc_html_e( 'Dates:', 'remember' ); ?></strong><br>
				<?php if ( $is_multi_day ) : ?>
					<?php echo esc_html( $start_date . ' - ' . $end_date ); ?>
				<?php else : ?>
					<?php echo esc_html( $start_date ); ?>
				<?php endif; ?>
			</div>

			<?php if ( $location ) : ?>
				<div class="remember-event-info-item">
					<strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong><br>
					<?php echo esc_html( $location->location_name ); ?>
					<?php if ( ! empty( $location->address_city ) || ! empty( $location->address_state ) ) : ?>
						<br><small>
							<?php 
							$address_parts = array_filter( array( $location->address_city, $location->address_state ) );
							echo esc_html( implode( ', ', $address_parts ) );
							?>
						</small>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="remember-event-info-item">
				<strong><?php esc_html_e( 'Status:', 'remember' ); ?></strong><br>
				<span style="color: <?php echo esc_attr( $status_colors[ $event->status ] ?? '#666' ); ?>;">
					<?php echo esc_html( $status_labels[ $event->status ] ?? $event->status ); ?>
				</span>
			</div>

			<?php if ( $event->is_private ) : ?>
				<div class="remember-event-info-item">
					<strong><?php esc_html_e( 'Type:', 'remember' ); ?></strong><br>
					<?php esc_html_e( 'Private Event', 'remember' ); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $current_member && ! Remember_Member::is_vetted_member( $current_member ) ) : ?>
		<div class="remember-event-notice">
			<p class="remember-notice remember-warning">
				<?php esc_html_e( 'Member is not yet vetted.', 'remember' ); ?>
			</p>
		</div>
	<?php elseif ( $is_accepted ) : ?>
		<div class="remember-event-attendees">
			<h3><?php esc_html_e( 'Event Attendees', 'remember' ); ?></h3>
			<?php
			// Use the event directory shortcode
			echo do_shortcode( '[remember_event_directory event_id="' . esc_attr( $event_id ) . '"]' );
			?>
		</div>
	<?php else : ?>
		<div class="remember-event-notice">
			<p class="remember-notice remember-info">
				<?php esc_html_e( 'You must be accepted to this event to view the attendee directory.', 'remember' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>
