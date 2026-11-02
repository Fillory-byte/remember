<?php
/**
 * Setup wizard page view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-page-creator.php';

// Form processing is now handled in display_setup_wizard() method before this view is included
$default_pages = Remember_Page_Creator::get_default_pages();
$created_pages = Remember_Page_Creator::get_created_pages();

// Get all existing pages for dropdowns
$all_pages = get_pages( array( 'sort_column' => 'post_title' ) );
?>

<div class="wrap remember-setup-wizard">
	<h1><?php esc_html_e( 'Welcome to reMember', 'remember' ); ?></h1>

	<div class="remember-setup-content" style="max-width: 900px; margin: 20px 0;">
		<?php
		$remember_gs_context = 'wizard';
		require plugin_dir_path( __FILE__ ) . 'partials/getting-started-static-data.php';
		?>

		<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 30px; margin-bottom: 20px;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Setup Front-End Pages', 'remember' ); ?></h2>
			<p>
				<?php esc_html_e( 'reMember uses shortcodes to display member-facing content. For each shortcode below, you can select an existing page, create a new page, or skip.', 'remember' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'remember_setup_wizard', 'remember_setup_nonce' ); ?>
				<input type="hidden" name="remember_setup_action" value="setup_pages">
				
				<table class="wp-list-table widefat fixed striped" style="margin: 20px 0;">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Shortcode', 'remember' ); ?></th>
							<th style="width: 35%;"><?php esc_html_e( 'Description', 'remember' ); ?></th>
							<th style="width: 40%;"><?php esc_html_e( 'Page Selection', 'remember' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $default_pages as $key => $page_data ) : 
							$current_page_id = isset( $created_pages[ $key ] ) ? $created_pages[ $key ] : 0;
							$current_page = $current_page_id ? get_post( $current_page_id ) : null;
						?>
							<tr>
								<td>
									<code style="font-size: 0.875em; word-break: break-all;"><?php echo esc_html( $page_data['shortcode'] ); ?></code>
								</td>
								<td>
									<strong><?php echo esc_html( $page_data['title'] ); ?></strong>
									<p style="margin: 5px 0 0 0; color: #646970; font-size: 0.875em;">
										<?php echo esc_html( $page_data['description'] ); ?>
									</p>
									<?php if ( $current_page ) : ?>
										<p class="remember-tone-success" style="margin: 5px 0 0 0; font-size: 0.875em;">
											✓ <?php esc_html_e( 'Currently linked to:', 'remember' ); ?> 
											<a href="<?php echo esc_url( get_edit_post_link( $current_page_id ) ); ?>" target="_blank">
												<?php echo esc_html( $current_page->post_title ); ?>
											</a>
										</p>
									<?php endif; ?>
								</td>
								<td>
									<select name="page_<?php echo esc_attr( $key ); ?>" id="page_<?php echo esc_attr( $key ); ?>" class="regular-text" style="width: 100%;">
										<option value="skip"><?php esc_html_e( '— Skip —', 'remember' ); ?></option>
										<option value="create" <?php selected( false, $current_page_id > 0 ); ?>>
											<?php esc_html_e( '+ Create New Page', 'remember' ); ?>
										</option>
										<?php if ( ! empty( $all_pages ) ) : ?>
											<optgroup label="<?php esc_attr_e( 'Use Existing Page', 'remember' ); ?>">
												<?php foreach ( $all_pages as $page ) : 
													$selected = ( $current_page_id === $page->ID );
												?>
													<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $selected ); ?>>
														<?php echo esc_html( $page->post_title ); ?>
													</option>
												<?php endforeach; ?>
											</optgroup>
										<?php endif; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Settings', 'remember' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-settings' ) ); ?>" class="button button-large" style="margin-left: 10px;">
						<?php esc_html_e( 'Skip for Now', 'remember' ); ?>
					</a>
				</p>
			</form>
		</div>

		<div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
			<h3><?php esc_html_e( 'Need Help?', 'remember' ); ?></h3>
			<p>
				<?php esc_html_e( 'You can manually create pages and add shortcodes anytime. Visit the Settings page for complete shortcode documentation.', 'remember' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-getting-started' ) ); ?>" class="button">
					<?php esc_html_e( 'Getting Started (full guide)', 'remember' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-settings#shortcodes' ) ); ?>" class="button" style="margin-left: 8px;">
					<?php esc_html_e( 'View Shortcode Documentation', 'remember' ); ?>
				</a>
			</p>
		</div>
	</div>
</div>
