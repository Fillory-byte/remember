<?php
/**
 * Payments view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-payment.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-application.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-member.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-messaging.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-template.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-billing-provider.php';

Remember_Logger::debug( 'Payments page loaded' );

$payment_model = new Remember_Payment();
$application_model = new Remember_Application();
$member_model = new Remember_Member();
$subtotal_disclaimer = Remember_Billing_Messaging::get_subtotal_disclaimer();
$status_labels       = Remember_Billing_Template::get_payment_status_labels();

// Get filter parameters
$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : '';

// Get payments
if ( ! empty( $filter_status ) ) {
	$payments = $payment_model->get_by_status( $filter_status );
} else {
	$payments = $payment_model->get_all();
}

?>

<div class="wrap remember-billing">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<hr class="wp-header-end">

	<div class="notice notice-info" style="margin: 15px 0;">
		<p>
			<strong><?php esc_html_e( 'Billing note:', 'remember' ); ?></strong>
			<?php echo esc_html( $subtotal_disclaimer ); ?>
		</p>
	</div>

	<!-- Filters -->
	<div class="remember-filters">
		<form method="get" action="">
			<input type="hidden" name="page" value="remember-billing">
			
			<label for="filter_status"><?php esc_html_e( 'Filter by Status:', 'remember' ); ?></label>
			<select id="filter_status" name="filter_status">
				<option value=""><?php esc_html_e( 'All Statuses', 'remember' ); ?></option>
				<?php foreach ( $status_labels as $status => $label ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'remember' ); ?>">
			<?php if ( ! empty( $filter_status ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-billing' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'remember' ); ?></a>
			<?php endif; ?>
		</form>
	</div>

	<!-- Payments List -->
	<?php if ( ! empty( $payments ) ) : ?>
		<?php
		Remember_Billing_Template::render_payments_table(
			array(
				'payments'     => $payments,
				'context'      => 'admin',
				'member_model' => $member_model,
			)
		);
		?>
		
		<p class="description" style="margin-top: 15px;">
			<?php echo esc_html( sprintf( __( 'Showing %d payment(s)', 'remember' ), count( $payments ) ) ); ?>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'No payments found.', 'remember' ); ?></p>
	<?php endif; ?>
</div>
