<?php
/**
 * Waitlist view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-event.php';

$application_model = new Remember_Application();
$event_model       = new Remember_Event();

if ( ! function_exists( 'remember_notify_event_admins' ) ) {
	/**
	 * Notify event administrators about waitlist updates.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $subject  Email subject.
	 * @param string $message  Email body.
	 * @return void
	 */
	function remember_notify_event_admins( $event_id, $subject, $message ) {
		global $wpdb;
		$emails = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT u.user_email
				FROM {$wpdb->prefix}remember_event_applications a
				JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
				JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
				JOIN {$wpdb->users} u ON a.member_id = u.ID
				WHERE a.event_id = %d
				AND a.status = 'accepted'
				AND r.role_name = %s
				AND u.user_email IS NOT NULL
				AND u.user_email != ''",
				$event_id,
				'Event Administrator'
			)
		);

		if ( empty( $emails ) ) {
			return;
		}

		foreach ( $emails as $email ) {
			wp_mail( $email, $subject, $message );
		}
	}
}

if ( isset( $_POST['remember_waitlist_action'] ) && check_admin_referer( 'remember_waitlist_action', 'remember_waitlist_nonce' ) ) {
	if ( ! current_user_can( 'remember_update_applications' ) ) {
		wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
	}

	$action = sanitize_text_field( wp_unslash( $_POST['remember_waitlist_action'] ) );
	$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;

	if ( $application_id > 0 ) {
		$application = $application_model->get( $application_id );
		$event = $application ? $event_model->get( $application->event_id ) : null;
		$event_name = $event ? $event->event_name : __( 'Unknown Event', 'remember' );

		if ( in_array( $action, array( 'promote_to_pending', 'decline_waitlisted' ), true ) && ( ! $application || 'waitlisted' !== $application->status ) ) {
			Remember_Logger::warning(
				'Waitlist action refused for an application that is not waitlisted',
				array(
					'application_id' => $application_id,
					'action'         => $action,
					'status'         => $application ? $application->status : '',
					'user_id'        => get_current_user_id(),
				)
			);
			$message = $application
				/* translators: %s: current application status, e.g. accepted. */
				? sprintf( __( 'That application is no longer on the waitlist (it is now %s), so nothing was changed. Refresh the page to see the current waitlist.', 'remember' ), str_replace( '_', ' ', $application->status ) )
				: __( 'That application was not found, so nothing was changed.', 'remember' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( 'promote_to_pending' === $action ) {
			$result = $application_model->update_status( $application_id, 'pending', get_current_user_id() );
			if ( false !== $result && $application ) {
				remember_notify_event_admins(
					$application->event_id,
					sprintf( __( 'Waitlist promotion for %s', 'remember' ), $event_name ),
					sprintf( __( 'An application was manually promoted from waitlist to pending review.%1$sEvent: %2$s', 'remember' ), "\n", $event_name )
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Application moved from waitlist to pending.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to move application to pending.', 'remember' ) . '</p></div>';
			}
		} elseif ( 'decline_waitlisted' === $action ) {
			$result = $application_model->update_status( $application_id, 'declined', get_current_user_id() );
			if ( false !== $result && $application ) {
				remember_notify_event_admins(
					$application->event_id,
					sprintf( __( 'Waitlist update for %s', 'remember' ), $event_name ),
					sprintf( __( 'A waitlisted application was declined.%1$sEvent: %2$s', 'remember' ), "\n", $event_name )
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Waitlisted application declined.', 'remember' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to decline waitlisted application.', 'remember' ) . '</p></div>';
			}
		}
	}
}

$waitlist_events = $application_model->get_waitlisted_events();
$default_event_id = ! empty( $waitlist_events ) ? absint( $waitlist_events[0]->event_id ) : 0;

$filter_event = isset( $_GET['filter_event'] ) ? absint( $_GET['filter_event'] ) : $default_event_id;
$filter_role = isset( $_GET['filter_role'] ) ? absint( $_GET['filter_role'] ) : 0;
$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'waitlisted_at';
$order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'ASC';

$event_roles_for_filter = array();
if ( $filter_event > 0 ) {
	$event_roles_for_filter = $event_model->get_event_roles( $filter_event );
}

$waitlisted_applications = $application_model->get_waitlisted_with_details(
	array(
		'event_id' => $filter_event,
		'role_id'  => $filter_role,
		'orderby'  => $orderby,
		'order'    => $order,
	)
);

/**
 * Build sortable header links.
 *
 * @param string $column_name Column name.
 * @param string $current_orderby Current order by.
 * @param string $current_order Current order.
 * @return string
 */
function remember_waitlist_sort_link( $column_name, $current_orderby, $current_order ) {
	$next_order = ( $current_orderby === $column_name && 'ASC' === strtoupper( $current_order ) ) ? 'DESC' : 'ASC';
	return esc_url(
		add_query_arg(
			array(
				'orderby' => $column_name,
				'order'   => $next_order,
			)
		)
	);
}
?>

<div class="wrap remember-waitlist">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Waitlist', 'remember' ); ?></h1>
	<hr class="wp-header-end">

	<div class="remember-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<form method="get" action="">
			<input type="hidden" name="page" value="remember-waitlist">

			<label for="filter_event"><?php esc_html_e( 'Event:', 'remember' ); ?></label>
			<select id="filter_event" name="filter_event" style="margin-right: 20px;">
				<option value="0"><?php esc_html_e( 'All Waitlisted Events', 'remember' ); ?></option>
				<?php foreach ( $waitlist_events as $event ) : ?>
					<option value="<?php echo esc_attr( $event->event_id ); ?>" <?php selected( $filter_event, $event->event_id ); ?>>
						<?php echo esc_html( $event->event_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="filter_role"><?php esc_html_e( 'Role:', 'remember' ); ?></label>
			<select id="filter_role" name="filter_role" style="margin-right: 20px;">
				<option value="0"><?php esc_html_e( 'All Roles', 'remember' ); ?></option>
				<?php foreach ( $event_roles_for_filter as $event_role ) : ?>
					<option value="<?php echo esc_attr( $event_role->role_id ); ?>" <?php selected( $filter_role, $event_role->role_id ); ?>>
						<?php echo esc_html( $event_role->role_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'remember' ); ?>">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-waitlist' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'remember' ); ?></a>
		</form>
	</div>

	<?php if ( ! empty( $waitlisted_applications ) ) : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Member', 'remember' ); ?></th>
					<th><a href="<?php echo remember_waitlist_sort_link( 'event_name', $orderby, $order ); ?>"><?php esc_html_e( 'Event', 'remember' ); ?></a></th>
					<th><a href="<?php echo remember_waitlist_sort_link( 'role_name', $orderby, $order ); ?>"><?php esc_html_e( 'Role', 'remember' ); ?></a></th>
					<th><a href="<?php echo remember_waitlist_sort_link( 'applied_at', $orderby, $order ); ?>"><?php esc_html_e( 'Application Date', 'remember' ); ?></a></th>
					<th><a href="<?php echo remember_waitlist_sort_link( 'waitlisted_at', $orderby, $order ); ?>"><?php esc_html_e( 'Waitlist Date', 'remember' ); ?></a></th>
					<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $waitlisted_applications as $application ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $application->display_name ? $application->display_name : __( 'Unknown Member', 'remember' ) ); ?></strong><br>
							<span class="description"><?php echo esc_html( $application->user_email ); ?></span>
						</td>
						<td><?php echo esc_html( $application->event_name ); ?></td>
						<td><?php echo esc_html( $application->role_name ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $application->applied_at ) ) ); ?></td>
						<td>
							<?php
							if ( ! empty( $application->waitlisted_at ) ) {
								echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $application->waitlisted_at ) ) );
							} else {
								echo '<span class="description">' . esc_html__( 'Not recorded', 'remember' ) . '</span>';
							}
							?>
						</td>
						<td>
							<form method="post" action="" style="display:inline;">
								<?php wp_nonce_field( 'remember_waitlist_action', 'remember_waitlist_nonce' ); ?>
								<input type="hidden" name="remember_waitlist_action" value="promote_to_pending">
								<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
								<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Move to Pending', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Move this application from waitlist to pending?', 'remember' ); ?>');">
							</form>
							<form method="post" action="" style="display:inline;">
								<?php wp_nonce_field( 'remember_waitlist_action', 'remember_waitlist_nonce' ); ?>
								<input type="hidden" name="remember_waitlist_action" value="decline_waitlisted">
								<input type="hidden" name="application_id" value="<?php echo esc_attr( $application->application_id ); ?>">
								<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Decline', 'remember' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Decline this waitlisted application?', 'remember' ); ?>');">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'No waitlisted applications found for the selected filters.', 'remember' ); ?></p>
	<?php endif; ?>
</div>
