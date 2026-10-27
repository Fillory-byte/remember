<?php
/**
 * Events list template
 *
 * @package    reMember
 * @subpackage reMember/public/partials
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-location.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-page-creator.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-vetting-workflow.php';

$location_model = new Remember_Location();
$application_model = new Remember_Application();
$member_model = new Remember_Member();

// Get current user's applications if logged in
$user_applications = array();
$current_member = null;
if ( is_user_logged_in() ) {
	$current_user_id = get_current_user_id();
	$current_member = $member_model->get( $current_user_id );
	if ( $current_member ) {
		$user_applications = $application_model->get_by_member( $current_user_id );
	}
}

// Create a map of event_id => application for quick lookup
$user_applications_by_event = array();
foreach ( $user_applications as $app ) {
	$user_applications_by_event[ $app->event_id ] = $app;
}

// Status labels and colors
$status_labels = array(
	'draft'    => __( 'Draft', 'remember' ),
	'open'     => __( 'Open for Applications', 'remember' ),
	'closed'   => __( 'Closed', 'remember' ),
	'cancelled' => __( 'Cancelled', 'remember' ),
	'completed' => __( 'Completed', 'remember' ),
);

$status_colors = array(
	'draft'    => '#666',
	'open'     => '#46b450',
	'closed'   => '#dc3232',
	'cancelled' => '#dc3232',
	'completed' => '#666',
);
?>

<?php if ( empty( $events ) ) : ?>
	<p class="remember-notice remember-info"><?php esc_html_e( 'No events found.', 'remember' ); ?></p>
<?php else : ?>
	<div class="remember-events-list">
		<?php foreach ( $events as $event ) : 
			$location = null;
			if ( $event->location_id ) {
				$location = $location_model->get( $event->location_id );
			}
			
			$is_multi_day = $event->start_date !== $event->end_date;
			$start_date = date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) );
			$end_date = $is_multi_day ? date_i18n( get_option( 'date_format' ), strtotime( $event->end_date ) ) : '';
		?>
			<article class="remember-event-card">
				<header class="remember-event-header">
					<h3 class="remember-event-title">
						<?php echo esc_html( $event->event_name ); ?>
						<?php if ( $event->is_private ) : ?>
							<span class="remember-event-badge remember-event-private" title="<?php esc_attr_e( 'Private Event', 'remember' ); ?>">
								<?php esc_html_e( 'Private', 'remember' ); ?>
							</span>
						<?php endif; ?>
					</h3>
					<span class="remember-event-status remember-status-<?php echo esc_attr( $event->status ); ?>" style="color: <?php echo esc_attr( $status_colors[ $event->status ] ); ?>;">
						<?php echo esc_html( $status_labels[ $event->status ] ); ?>
					</span>
				</header>

				<div class="remember-event-content">
					<?php if ( ! empty( $event->event_description ) ) : ?>
						<div class="remember-event-description">
							<?php echo wp_kses_post( wpautop( $event->event_description ) ); ?>
						</div>
					<?php endif; ?>

					<div class="remember-event-meta">
						<p class="remember-event-date">
							<strong><?php esc_html_e( 'Dates:', 'remember' ); ?></strong>
							<?php echo esc_html( $start_date ); ?>
							<?php if ( $is_multi_day ) : ?>
								- <?php echo esc_html( $end_date ); ?>
							<?php endif; ?>
						</p>

						<?php $registration_summary = Remember_Event::registration_window_summary( $event ); ?>
						<?php if ( $registration_summary ) : ?>
							<p class="remember-event-registration">
								<?php echo esc_html( $registration_summary ); ?>
							</p>
						<?php endif; ?>

						<?php if ( $location ) : ?>
							<p class="remember-event-location">
								<strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong>
								<?php echo esc_html( $location->location_name ); ?>
							</p>
						<?php endif; ?>
					</div>

					<?php if ( is_user_logged_in() && $current_member ) : 
						// Check if user has an application for this event
						$user_application = isset( $user_applications_by_event[ $event->event_id ] ) ? $user_applications_by_event[ $event->event_id ] : null;
						
						// Application status labels and colors
						$app_status_labels = array(
							'pending'    => __( 'Pending', 'remember' ),
							'accepted'   => __( 'Accepted', 'remember' ),
							'declined'   => __( 'Declined', 'remember' ),
							'waitlisted' => __( 'Waitlisted', 'remember' ),
							'withdrawn'  => __( 'Withdrawn', 'remember' ),
						);
						
						$app_status_colors = array(
							'pending'    => '#f0f6fc',
							'accepted'   => '#dafbe1',
							'declined'   => '#ffebe9',
							'waitlisted' => '#fff8c5',
							'withdrawn'  => '#f0f0f1',
						);
					?>
						<footer class="remember-event-footer">
							<?php if ( $user_application && 'accepted' === $user_application->status ) : ?>
								<?php
								// User is accepted - show link to event detail page
								$created_pages = Remember_Page_Creator::get_created_pages();
								$event_detail_page_id = isset( $created_pages['event_detail'] ) ? $created_pages['event_detail'] : 0;
								
								if ( $event_detail_page_id ) {
									$event_detail_url = add_query_arg( 'event', $event->event_id, get_permalink( $event_detail_page_id ) );
								} else {
									$event_detail_url = add_query_arg( 'event', $event->event_id, home_url( '/event-detail/' ) );
								}
								?>
								<a href="<?php echo esc_url( $event_detail_url ); ?>" class="remember-button remember-button-primary">
									<?php esc_html_e( 'View Event', 'remember' ); ?>
								</a>
							<?php elseif ( $user_application ) : ?>
								<?php
								// User has applied but not accepted - show status
								$status = $user_application->status;
								$status_label = isset( $app_status_labels[ $status ] ) ? $app_status_labels[ $status ] : ucfirst( $status );
								$status_color = isset( $app_status_colors[ $status ] ) ? $app_status_colors[ $status ] : '#f0f0f1';
								?>
								<span class="remember-status-badge" style="background-color: <?php echo esc_attr( $status_color ); ?>; padding: 8px 16px; border-radius: 3px; display: inline-block;">
									<strong><?php esc_html_e( 'Application Status:', 'remember' ); ?></strong> <?php echo esc_html( $status_label ); ?>
								</span>
							<?php elseif ( 'open' === $event->status && Remember_Vetting_Workflow::member_can_apply( $current_member ) && Remember_Event::registration_block_reason( $event ) ) : ?>
								<p class="remember-notice remember-warning" style="margin:0;display:inline-block;"><?php echo esc_html( Remember_Event::registration_block_reason( $event ) ); ?></p>
							<?php elseif ( 'open' === $event->status && Remember_Vetting_Workflow::member_can_apply( $current_member ) ) : ?>
								<?php
								require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-audit.php';
								$apply_url   = Remember_Profile_Audit::get_apply_url( (int) $event->event_id );
								$profile_url = Remember_Profile_Audit::get_profile_edit_url_for_apply( (int) $event->event_id );
								?>
								<a href="<?php echo esc_url( $apply_url ); ?>"
									class="remember-button remember-button-primary remember-apply-gate"
									data-profile-url="<?php echo esc_url( $profile_url ); ?>"
									data-apply-url="<?php echo esc_url( $apply_url ); ?>">
									<?php esc_html_e( 'Apply for Event', 'remember' ); ?>
								</a>
							<?php elseif ( 'open' === $event->status ) : ?>
								<p class="remember-notice remember-warning" style="margin:0;display:inline-block;"><?php echo esc_html( Remember_Vetting_Workflow::apply_block_message( $current_member ) ); ?></p>
							<?php endif; ?>
						</footer>
					<?php elseif ( 'open' === $event->status ) : ?>
						<footer class="remember-event-footer">
							<p class="remember-description">
								<?php esc_html_e( 'Please log in to apply for this event.', 'remember' ); ?>
							</p>
						</footer>
					<?php endif; ?>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
