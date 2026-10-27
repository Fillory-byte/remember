<?php
/**
 * Dashboard widget view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-admin-dashboard.php';

$dashboard        = Remember_Admin_Dashboard::collect();
$attention_count  = count( $dashboard['attention'] );
?>

<div class="remember-dashboard-widget">
	<?php if ( ! empty( $dashboard['stats'] ) ) : ?>
		<div class="remember-widget-stats">
			<?php foreach ( $dashboard['stats'] as $stat ) : ?>
				<a class="remember-widget-stat" href="<?php echo esc_url( $stat['url'] ); ?>">
					<span class="remember-widget-stat-number"><?php echo esc_html( $stat['count'] ); ?></span>
					<span class="remember-widget-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="remember-widget-actions">
		<?php if ( $attention_count > 0 ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember' ) ); ?>" class="button button-secondary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of dashboard items that need attention */
						_n( '%d item needs attention', '%d items need attention', $attention_count, 'remember' ),
						$attention_count
					)
				);
				?>
			</a>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Open reMember', 'remember' ); ?>
		</a>
	</div>
</div>

<style>
.remember-dashboard-widget {
	margin: -6px -12px -12px;
}

.remember-widget-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
	gap: 8px;
	margin-bottom: 12px;
	padding: 0 12px;
}

.remember-widget-stat {
	display: block;
	text-align: center;
	text-decoration: none;
	padding: 8px 4px;
	border-radius: 6px;
}

.remember-widget-stat:hover,
.remember-widget-stat:focus {
	background: #f6f7f7;
}

.remember-widget-stat-number {
	display: block;
	font-size: 26px;
	font-weight: 600;
	color: #1d2327;
	line-height: 1.2;
	margin-bottom: 2px;
}

.remember-widget-stat-label {
	display: block;
	font-size: 12px;
	color: #646970;
}

.remember-widget-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 12px;
	border-top: 1px solid #dcdcde;
}

.remember-widget-actions .button {
	flex: 1 1 auto;
	min-width: 120px;
	text-align: center;
}
</style>
