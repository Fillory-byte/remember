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

$dashboard = Remember_Admin_Dashboard::collect();
$attention = array_slice( $dashboard['attention'], 0, 3 );
$more      = count( $dashboard['attention'] ) - count( $attention );
?>

<div class="remember-dashboard-widget">
	<?php if ( ! empty( $dashboard['stats'] ) ) : ?>
		<div class="remember-widget-stats">
			<?php foreach ( $dashboard['stats'] as $stat ) : ?>
				<a class="remember-widget-stat remember-widget-stat--<?php echo esc_attr( $stat['tone'] ); ?>" href="<?php echo esc_url( $stat['url'] ); ?>">
					<span class="remember-widget-stat__count"><?php echo esc_html( $stat['count'] ); ?></span>
					<span class="remember-widget-stat__label"><?php echo esc_html( $stat['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $dashboard['attention'] ) ) : ?>
		<p class="remember-widget-clear"><?php esc_html_e( 'All clear. Nothing is waiting on you.', 'remember' ); ?></p>
	<?php else : ?>
		<div class="remember-widget-rows">
			<?php foreach ( $attention as $item ) : ?>
				<?php $tone = isset( $item['tone'] ) ? $item['tone'] : 'normal'; ?>
				<a class="remember-widget-row remember-widget-row--<?php echo esc_attr( $tone ); ?>" href="<?php echo esc_url( $item['url'] ); ?>">
					<span class="remember-widget-row__main">
						<span class="remember-widget-row__title"><?php echo esc_html( $item['title'] ); ?></span>
						<?php if ( ! empty( $item['detail'] ) ) : ?>
							<span class="remember-widget-row__detail"><?php echo esc_html( $item['detail'] ); ?></span>
						<?php endif; ?>
					</span>
					<?php if ( ! empty( $item['meta'] ) ) : ?>
						<span class="remember-widget-row__meta"><?php echo esc_html( $item['meta'] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php if ( $more > 0 ) : ?>
			<p class="remember-widget-more">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: attention items not shown in the widget */
						_n( '%d more on the dashboard', '%d more on the dashboard', $more, 'remember' ),
						$more
					)
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<p class="remember-widget-foot">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember' ) ); ?>"><?php esc_html_e( 'Open reMember', 'remember' ); ?></a>
	</p>
</div>

<style>
.remember-dashboard-widget {
	margin: 0;
}

.remember-widget-stats {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px;
	margin: 0 0 10px;
}

.remember-widget-stat {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	padding: 10px 12px 9px;
	background: #fff;
	border: 1px solid #dcdcde;
	border-top-width: 3px;
	border-radius: 4px;
	text-decoration: none;
	color: #1d2327;
}

.remember-widget-stat--members { border-top-color: #2271b1; }
.remember-widget-stat--vetting { border-top-color: #dba617; }
.remember-widget-stat--applications { border-top-color: #d63638; }
.remember-widget-stat--events { border-top-color: #00a32a; }

.remember-widget-stat:hover,
.remember-widget-stat:focus {
	border-color: #2271b1;
	color: #1d2327;
	box-shadow: 0 0 0 1px #2271b1;
}

.remember-widget-stat--vetting:hover,
.remember-widget-stat--vetting:focus { border-top-color: #dba617; }
.remember-widget-stat--applications:hover,
.remember-widget-stat--applications:focus { border-top-color: #d63638; }
.remember-widget-stat--events:hover,
.remember-widget-stat--events:focus { border-top-color: #00a32a; }

.remember-widget-stat__count {
	display: block;
	font-size: 22px;
	font-weight: 600;
	line-height: 1.1;
	color: #1d2327;
}

.remember-widget-stat__label {
	display: block;
	font-size: 12px;
	line-height: 1.3;
	color: #646970;
}

.remember-widget-clear {
	margin: 0 0 10px;
	padding: 8px 10px;
	background: #edfaef;
	border: 1px solid #b8e6bf;
	border-radius: 4px;
	color: #1e4620;
	font-size: 13px;
}

.remember-widget-rows {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0 0 8px;
}

.remember-widget-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 10px;
	background: #fff;
	border: 1px solid #dcdcde;
	border-left-width: 3px;
	border-radius: 4px;
	text-decoration: none;
	color: #1d2327;
}

.remember-widget-row--overdue { border-left-color: #d63638; }
.remember-widget-row--soon { border-left-color: #dba617; }
.remember-widget-row--normal { border-left-color: #2271b1; }

.remember-widget-row:hover,
.remember-widget-row:focus {
	border-color: #2271b1;
	color: #1d2327;
}

.remember-widget-row--overdue:hover,
.remember-widget-row--overdue:focus { border-left-color: #d63638; }
.remember-widget-row--soon:hover,
.remember-widget-row--soon:focus { border-left-color: #dba617; }

.remember-widget-row__main { min-width: 0; }

.remember-widget-row__title {
	display: block;
	color: #1d2327;
	font-size: 13px;
	font-weight: 600;
	line-height: 1.3;
}

.remember-widget-row__detail {
	display: block;
	margin-top: 1px;
	color: #646970;
	font-size: 12px;
	line-height: 1.3;
}

.remember-widget-row__meta {
	flex: 0 0 auto;
	padding: 2px 8px;
	border-radius: 999px;
	background: #f0f0f1;
	color: #1d2327;
	font-size: 11px;
	font-weight: 600;
}

.remember-widget-row--overdue .remember-widget-row__meta {
	background: #fcf0f1;
	color: #8a2424;
}

.remember-widget-more {
	margin: 0 0 8px;
	color: #646970;
	font-size: 12px;
}

.remember-widget-foot {
	margin: 0;
	font-size: 13px;
}

.remember-widget-foot a {
	text-decoration: none;
}
</style>
