<?php
/**
 * Sensitive-data access log.
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$staff_filter  = isset( $_GET['staff'] ) ? absint( wp_unslash( $_GET['staff'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$member_filter = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page          = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$result        = Remember_Access_Log::query( $staff_filter, $member_filter, $page );
$pages         = max( 1, (int) ceil( $result['total'] / Remember_Access_Log::PAGE_SIZE ) );
$page          = min( max( 1, $page ), $pages );
$base_args     = array(
	'page'   => 'remember-access-log',
	'staff'  => $staff_filter,
	'member' => $member_filter,
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Sensitive access log', 'remember' ); ?></h1>
	<p><?php esc_html_e( 'Who opened or exported health information or emergency contacts. The log does not store those values.', 'remember' ); ?></p>
	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Retention updated.', 'remember' ); ?></p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin: 1em 0;">
		<input type="hidden" name="page" value="remember-access-log" />
		<label>
			<?php esc_html_e( 'Staff user ID', 'remember' ); ?>
			<input type="number" min="0" name="staff" value="<?php echo esc_attr( (string) $staff_filter ); ?>" class="small-text" />
		</label>
		<label style="margin-left: 12px;">
			<?php esc_html_e( 'Member ID', 'remember' ); ?>
			<input type="number" min="0" name="member" value="<?php echo esc_attr( (string) $member_filter ); ?>" class="small-text" />
		</label>
		<?php submit_button( __( 'Filter', 'remember' ), 'secondary', '', false ); ?>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'remember' ); ?></th>
				<th><?php esc_html_e( 'Staff', 'remember' ); ?></th>
				<th><?php esc_html_e( 'Member', 'remember' ); ?></th>
				<th><?php esc_html_e( 'What', 'remember' ); ?></th>
				<th><?php esc_html_e( 'Where', 'remember' ); ?></th>
				<th><?php esc_html_e( 'IP', 'remember' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['rows'] ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No access recorded.', 'remember' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<?php
					$staff = get_userdata( (int) $row->user_id );
					$staff_name = $staff ? $staff->display_name : sprintf( /* translators: %d: user ID */ __( 'User %d', 'remember' ), (int) $row->user_id );
					if ( (int) $row->member_id > 0 ) {
						$member = get_userdata( (int) $row->member_id );
						$member_name = $member ? $member->display_name : sprintf( /* translators: %d: member ID */ __( 'Member %d', 'remember' ), (int) $row->member_id );
					} elseif ( 'backup' === $row->access_what ) {
						$member_name = (int) $row->row_count > 0
							? sprintf(
								/* translators: %d: number of members in the backup */
								_n( 'Full backup (%d member)', 'Full backup (%d members)', (int) $row->row_count, 'remember' ),
								(int) $row->row_count
							)
							: __( 'Full backup', 'remember' );
					} else {
						$member_name = sprintf(
							/* translators: %d: number of rows exported */
							__( 'Bulk export (%d rows)', 'remember' ),
							(int) $row->row_count
						);
					}
					?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row->created_at ) ); ?></td>
						<td><?php echo esc_html( $staff_name ); ?></td>
						<td><?php echo esc_html( $member_name ); ?></td>
						<td><?php echo esc_html( Remember_Access_Log::label( $row->access_what ) ); ?></td>
						<td><?php echo esc_html( $row->context ); ?></td>
						<td><?php echo esc_html( $row->ip ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<p class="tablenav">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $page - 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'remember' ); ?></a>
			<?php endif; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $page + 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'remember' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=remember-access-log' ) ); ?>" style="margin-top: 2em;">
		<?php wp_nonce_field( 'remember_access_log_retention', 'remember_access_log_nonce' ); ?>
		<input type="hidden" name="remember_access_log_retention" value="1" />
		<label>
			<?php esc_html_e( 'Keep log entries for', 'remember' ); ?>
			<input type="number" min="1" max="120" name="retention_months" value="<?php echo esc_attr( (string) Remember_Access_Log::retention_months() ); ?>" class="small-text" />
			<?php esc_html_e( 'months', 'remember' ); ?>
		</label>
		<?php submit_button( __( 'Save retention', 'remember' ), 'secondary', 'submit', false ); ?>
	</form>
</div>
