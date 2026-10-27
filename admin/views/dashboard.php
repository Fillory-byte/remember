<?php
/**
 * Dashboard view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-admin-dashboard.php';

Remember_Logger::debug( 'Dashboard page loaded' );

$dashboard = Remember_Admin_Dashboard::collect();

/**
 * One linked row in a dashboard card.
 *
 * @param array{title:string,detail?:string,meta?:string,url:string,tone?:string} $item Row.
 * @return void
 */
$remember_dash_row = function ( $item ) {
	$tone = isset( $item['tone'] ) ? $item['tone'] : 'normal';
	?>
	<a class="remember-dash-row remember-dash-row--<?php echo esc_attr( $tone ); ?>" href="<?php echo esc_url( $item['url'] ); ?>">
		<span class="remember-dash-row__main">
			<span class="remember-dash-row__title"><?php echo esc_html( $item['title'] ); ?></span>
			<?php if ( ! empty( $item['detail'] ) ) : ?>
				<span class="remember-dash-row__detail"><?php echo esc_html( $item['detail'] ); ?></span>
			<?php endif; ?>
		</span>
		<?php if ( ! empty( $item['meta'] ) ) : ?>
			<span class="remember-dash-row__meta"><?php echo esc_html( $item['meta'] ); ?></span>
		<?php endif; ?>
	</a>
	<?php
};
?>

<div class="wrap remember-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php if ( ! empty( $dashboard['summary'] ) ) : ?>
		<p class="remember-dash-summary"><?php echo esc_html( $dashboard['summary'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $dashboard['stats'] ) ) : ?>
		<div class="remember-dash-stats">
			<?php foreach ( $dashboard['stats'] as $stat ) : ?>
				<a class="remember-dash-stat remember-dash-stat--<?php echo esc_attr( $stat['tone'] ); ?>" href="<?php echo esc_url( $stat['url'] ); ?>">
					<span class="remember-dash-stat__count"><?php echo esc_html( $stat['count'] ); ?></span>
					<span class="remember-dash-stat__label"><?php echo esc_html( $stat['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( empty( $dashboard['attention'] ) ) : ?>
		<p class="remember-dash-clear"><?php esc_html_e( 'All clear. Nothing is waiting on you.', 'remember' ); ?></p>
	<?php endif; ?>

	<?php
	$remember_show_people   = ! empty( $dashboard['show_people'] );
	$remember_show_events   = ! empty( $dashboard['show_events'] );
	$remember_has_attention = ! empty( $dashboard['attention'] );
	$remember_pair_columns  = $remember_show_people && ( $remember_has_attention || $remember_show_events );
	$remember_panel_count   = (int) $remember_has_attention + (int) $remember_show_people + (int) $remember_show_events;
	$remember_solo_class    = ( ! $remember_pair_columns && 1 === $remember_panel_count ) ? ' remember-dash-span' : '';
	?>
	<div class="remember-dash-layout">
		<?php if ( $remember_pair_columns ) : ?>
			<div class="remember-dash-stack">
		<?php endif; ?>

		<?php if ( $remember_has_attention ) : ?>
			<section class="remember-dash-panel<?php echo esc_attr( $remember_solo_class ); ?>">
				<h2><?php esc_html_e( 'Needs attention', 'remember' ); ?></h2>
				<div class="remember-dash-rows">
					<?php foreach ( $dashboard['attention'] as $item ) : ?>
						<?php $remember_dash_row( $item ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $remember_show_events && $remember_pair_columns ) : ?>
			<section class="remember-dash-panel">
				<h2><?php esc_html_e( 'Upcoming events', 'remember' ); ?></h2>
				<?php if ( ! empty( $dashboard['upcoming'] ) ) : ?>
					<div class="remember-dash-rows">
						<?php foreach ( $dashboard['upcoming'] as $event ) : ?>
							<?php $remember_dash_row( $event ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="remember-dash-empty">
						<?php esc_html_e( 'No events are scheduled.', 'remember' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events' ) ); ?>"><?php esc_html_e( 'Open events', 'remember' ); ?></a>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $remember_pair_columns ) : ?>
			</div>
		<?php endif; ?>

		<?php if ( $remember_show_people ) : ?>
			<section class="remember-dash-panel<?php echo esc_attr( $remember_solo_class ); ?>">
				<h2><?php esc_html_e( 'Recently Updated Members', 'remember' ); ?></h2>
				<?php if ( ! empty( $dashboard['people'] ) ) : ?>
					<div class="remember-dash-rows">
						<?php foreach ( $dashboard['people'] as $person ) : ?>
							<?php $remember_dash_row( $person ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="remember-dash-empty">
						<?php esc_html_e( 'No members yet.', 'remember' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members' ) ); ?>"><?php esc_html_e( 'Add one', 'remember' ); ?></a>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $remember_show_events && ! $remember_pair_columns ) : ?>
			<section class="remember-dash-panel<?php echo esc_attr( $remember_solo_class ); ?>">
				<h2><?php esc_html_e( 'Upcoming events', 'remember' ); ?></h2>
				<?php if ( ! empty( $dashboard['upcoming'] ) ) : ?>
					<div class="remember-dash-rows">
						<?php foreach ( $dashboard['upcoming'] as $event ) : ?>
							<?php $remember_dash_row( $event ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="remember-dash-empty">
						<?php esc_html_e( 'No events are scheduled.', 'remember' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-events' ) ); ?>"><?php esc_html_e( 'Open events', 'remember' ); ?></a>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $dashboard['activity'] ) ) : ?>
			<section class="remember-dash-panel remember-dash-span">
				<h2><?php esc_html_e( 'Recent activity', 'remember' ); ?></h2>
				<ul class="remember-activity-list">
					<?php foreach ( $dashboard['activity'] as $activity ) : ?>
						<li class="remember-activity-item">
							<span class="remember-activity-time">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: human time difference, such as "3 hours" */
										__( '%s ago', 'remember' ),
										human_time_diff( strtotime( $activity['timestamp'] ), current_time( 'timestamp' ) )
									)
								);
								?>
							</span>
							<span class="remember-activity-message"><?php echo wp_kses_post( $activity['message'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</div>
</div>

<style>
.remember-dashboard .remember-dash-summary {
	margin: -8px 0 16px;
	color: #646970;
	font-size: 14px;
}
.remember-dashboard .remember-dash-stats {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 12px;
	margin: 0 0 16px;
}
.remember-dashboard .remember-dash-stat {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
	padding: 16px 18px 14px;
	background: #fff;
	border: 1px solid #c3c4c7;
	border-top-width: 3px;
	border-radius: 4px;
	box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
	text-decoration: none;
	color: #1d2327;
}
.remember-dashboard .remember-dash-stat--members { border-top-color: #2271b1; }
.remember-dashboard .remember-dash-stat--vetting { border-top-color: #dba617; }
.remember-dashboard .remember-dash-stat--applications { border-top-color: #d63638; }
.remember-dashboard .remember-dash-stat--events { border-top-color: #00a32a; }
.remember-dashboard .remember-dash-stat:hover,
.remember-dashboard .remember-dash-stat:focus {
	border-color: #2271b1;
	color: #1d2327;
	box-shadow: 0 0 0 1px #2271b1;
}
.remember-dashboard .remember-dash-stat--vetting:hover,
.remember-dashboard .remember-dash-stat--vetting:focus { border-top-color: #dba617; }
.remember-dashboard .remember-dash-stat--applications:hover,
.remember-dashboard .remember-dash-stat--applications:focus { border-top-color: #d63638; }
.remember-dashboard .remember-dash-stat--events:hover,
.remember-dashboard .remember-dash-stat--events:focus { border-top-color: #00a32a; }
.remember-dashboard .remember-dash-stat__count {
	display: block;
	font-size: 32px;
	font-weight: 600;
	line-height: 1;
	color: #1d2327;
}
.remember-dashboard .remember-dash-stat__label {
	display: block;
	font-size: 13px;
	line-height: 1.3;
	color: #646970;
}
.remember-dashboard .remember-dash-clear {
	margin: 0 0 16px;
	padding: 10px 14px;
	background: #edfaef;
	border: 1px solid #b8e6bf;
	border-radius: 4px;
	color: #1e4620;
}
.remember-dashboard .remember-dash-layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 16px;
	align-items: start;
}
.remember-dashboard .remember-dash-stack {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 0;
}
.remember-dashboard .remember-dash-span {
	grid-column: 1 / -1;
}
.remember-dashboard .remember-dash-panel {
	min-width: 0;
	margin: 0;
	padding: 4px 16px 8px;
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
}
.remember-dashboard .remember-dash-panel h2 {
	margin: 12px 0;
	padding: 0;
	font-size: 14px;
	font-weight: 600;
}
.remember-dashboard .remember-dash-empty {
	margin: 0 0 14px;
	color: #646970;
}
.remember-dashboard .remember-dash-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin: 0 0 8px;
	padding: 10px 12px;
	background: #fff;
	border: 1px solid #dcdcde;
	border-left-width: 3px;
	border-radius: 4px;
	text-decoration: none;
	color: #1d2327;
}
.remember-dashboard .remember-dash-row--overdue { border-left-color: #d63638; }
.remember-dashboard .remember-dash-row--soon { border-left-color: #dba617; }
.remember-dashboard .remember-dash-row--normal { border-left-color: #2271b1; }
.remember-dashboard .remember-dash-row:hover,
.remember-dashboard .remember-dash-row:focus {
	border-color: #2271b1;
	color: #1d2327;
}
.remember-dashboard .remember-dash-row--overdue:hover,
.remember-dashboard .remember-dash-row--overdue:focus { border-left-color: #d63638; }
.remember-dashboard .remember-dash-row--soon:hover,
.remember-dashboard .remember-dash-row--soon:focus { border-left-color: #dba617; }
.remember-dashboard .remember-dash-row__main { min-width: 0; }
.remember-dashboard .remember-dash-row__title {
	display: block;
	color: #1d2327;
	font-size: 13px;
	font-weight: 600;
	line-height: 1.4;
}
.remember-dashboard .remember-dash-row__detail {
	display: block;
	margin-top: 2px;
	color: #646970;
	font-size: 12px;
	line-height: 1.4;
}
.remember-dashboard .remember-dash-row__meta {
	flex: 0 0 auto;
	padding: 2px 8px;
	border-radius: 999px;
	background: #f0f0f1;
	color: #1d2327;
	font-size: 12px;
	font-weight: 600;
}
.remember-dashboard .remember-dash-row--overdue .remember-dash-row__meta {
	background: #fcf0f1;
	color: #8a2424;
}
.remember-dashboard .remember-activity-list {
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}
.remember-dashboard .remember-activity-item {
	display: grid;
	grid-template-columns: 7.5em minmax(0, 1fr);
	gap: 4px 12px;
	align-items: baseline;
	padding: 8px 0;
	border-bottom: 1px solid #f0f0f1;
}
.remember-dashboard .remember-activity-item:last-child { border-bottom: none; }
.remember-dashboard .remember-activity-time { font-size: 12px; color: #646970; }
.remember-dashboard .remember-activity-message { font-size: 13px; color: #1d2327; line-height: 1.5; }
.remember-dashboard .remember-activity-message a { text-decoration: none; }
@media screen and (max-width: 782px) {
	.remember-dashboard .remember-dash-stats,
	.remember-dashboard .remember-dash-layout {
		grid-template-columns: 1fr;
	}
	.remember-dashboard .remember-activity-item {
		grid-template-columns: 1fr;
	}
}
</style>
