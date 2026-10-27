<?php
/**
 * Printable admission ticket HTML renderer.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-ticket.php';

/**
 * Renders ticket HTML for browser print / standalone view.
 */
class Remember_Ticket_Renderer {

	/**
	 * Output a full HTML document and exit context is caller's responsibility.
	 *
	 * @param int  $application_id Application ID.
	 * @param bool $download       Whether to send Content-Disposition attachment.
	 * @return void
	 */
	public static function output_standalone( $application_id, $download = false ) {
		$data = Remember_Ticket::get_data( $application_id );
		if ( is_wp_error( $data ) ) {
			status_header( 404 );
			wp_die( esc_html( $data->get_error_message() ), esc_html__( 'Ticket', 'remember' ), array( 'response' => 404 ) );
		}

		if ( $download ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header(
				'Content-Disposition: attachment; filename="ticket-' . sanitize_file_name( $data['ticket_id'] ) . '.html"'
			);
		} else {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo self::render_document( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renderer.
	}

	/**
	 * Full HTML document.
	 *
	 * @param array $data Ticket data.
	 * @return string
	 */
	public static function render_document( $data ) {
		$body = self::render( $data, array( 'standalone' => true ) );
		$title = esc_html(
			sprintf(
				/* translators: %s: ticket id */
				__( 'Admission Ticket %s', 'remember' ),
				$data['ticket_id']
			)
		);

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo $title; ?></title>
	<style><?php echo self::get_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS. ?></style>
	<?php if ( ! empty( $data['checkin_code'] ) ) : ?>
	<script src="<?php echo esc_url( REMEMBER_PLUGIN_URL . 'assets/js/vendor/qrcode.js' ); ?>"></script>
	<?php endif; ?>
</head>
<body class="remember-ticket-body">
	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render(). ?>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var btn = document.getElementById('remember-ticket-print');
			if (btn) {
				btn.addEventListener('click', function () { window.print(); });
			}
		});
	</script>
	<?php if ( ! empty( $data['checkin_code'] ) ) : ?>
	<script>
		(function () {
			var slot = document.getElementById('remember-ticket-qr');
			if (!slot || typeof qrcode !== 'function') return;
			var code = slot.getAttribute('data-code') || '';
			if (!code) return;
			var mark = qrcode(0, 'M');
			mark.addData(code);
			mark.make();
			slot.innerHTML = mark.createSvgTag(4, 2);
		})();
	</script>
	<?php endif; ?>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Ticket markup (inner).
	 *
	 * @param array $data Ticket data.
	 * @param array $args Optional args.
	 * @return string
	 */
	public static function render( $data, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'standalone' => false,
			)
		);

		$stamp       = isset( $data['stamp'] ) ? $data['stamp'] : Remember_Ticket::get_stamp( $data );
		$stamp_label = '';
		if ( 'void' === $stamp ) {
			$stamp_label = __( 'VOID', 'remember' );
		} elseif ( 'payment_required' === $stamp ) {
			$stamp_label = __( 'PAYMENT REQUIRED', 'remember' );
		}

		$status_label = self::payment_status_label( $data['payment_status'] );
		$app_label    = self::application_status_label( $data['application_status'] );

		ob_start();
		?>
		<div class="remember-ticket-toolbar no-print">
			<button type="button" id="remember-ticket-print" class="remember-ticket-btn"><?php esc_html_e( 'Print ticket', 'remember' ); ?></button>
			<?php if ( ! empty( $args['standalone'] ) ) : ?>
				<a class="remember-ticket-btn remember-ticket-btn-secondary" href="<?php echo esc_url( add_query_arg( 'download', '1' ) ); ?>"><?php esc_html_e( 'Download', 'remember' ); ?></a>
			<?php endif; ?>
		</div>

		<article class="remember-ticket <?php echo $stamp ? 'remember-ticket--stamped' : ''; ?>">
			<?php if ( $stamp_label ) : ?>
				<div class="remember-ticket-stamp remember-ticket-stamp--<?php echo esc_attr( $stamp ); ?>" aria-hidden="true"><?php echo esc_html( $stamp_label ); ?></div>
			<?php endif; ?>

			<header class="remember-ticket-header">
				<?php if ( ! empty( $data['logo_url'] ) ) : ?>
					<img class="remember-ticket-logo" src="<?php echo esc_url( $data['logo_url'] ); ?>" alt="">
				<?php endif; ?>
				<div class="remember-ticket-vendor">
					<p class="remember-ticket-vendor-label"><?php esc_html_e( 'Vendor information', 'remember' ); ?></p>
					<h1 class="remember-ticket-vendor-name"><?php echo esc_html( $data['vendor_name'] ); ?></h1>
					<?php if ( ! empty( $data['vendor_url'] ) ) : ?>
						<p class="remember-ticket-vendor-url"><?php echo esc_html( preg_replace( '#^https?://#', '', $data['vendor_url'] ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="remember-ticket-id">
					<span><?php esc_html_e( 'Ticket', 'remember' ); ?></span>
					<strong><?php echo esc_html( $data['ticket_id'] ); ?></strong>
				</div>
			</header>

			<section class="remember-ticket-section">
				<h2><?php esc_html_e( 'Attendee', 'remember' ); ?></h2>
				<p class="remember-ticket-attendee-name"><?php echo esc_html( $data['member_name'] ); ?></p>
				<?php if ( ! empty( $data['member_email'] ) ) : ?>
					<p><?php echo esc_html( $data['member_email'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $data['role_name'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Role:', 'remember' ); ?></strong> <?php echo esc_html( $data['role_name'] ); ?></p>
				<?php endif; ?>
			</section>

			<section class="remember-ticket-section">
				<h2><?php esc_html_e( 'Event', 'remember' ); ?></h2>
				<p class="remember-ticket-event-name"><?php echo esc_html( $data['event_name'] ); ?></p>
				<?php if ( ! empty( $data['event_dates'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Dates:', 'remember' ); ?></strong> <?php echo esc_html( $data['event_dates'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $data['location_lines'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Location:', 'remember' ); ?></strong><br>
						<?php echo nl2br( esc_html( implode( "\n", $data['location_lines'] ) ) ); ?>
					</p>
				<?php endif; ?>
				<p>
					<strong><?php esc_html_e( 'Application status:', 'remember' ); ?></strong>
					<?php echo esc_html( $app_label ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Payment status:', 'remember' ); ?></strong>
					<span class="remember-ticket-pay-status remember-ticket-pay-status--<?php echo esc_attr( $data['payment_status'] ? $data['payment_status'] : 'unknown' ); ?>">
						<?php echo esc_html( $status_label ); ?>
						<?php if ( 'payment_required' === $stamp && $data['amount_due'] > 0 ) : ?>
							— <?php echo esc_html( sprintf( __( '%s due', 'remember' ), self::money( $data['amount_due'] ) ) ); ?>
						<?php endif; ?>
					</span>
				</p>
			</section>

			<?php if ( ! empty( $data['checkin_code'] ) ) : ?>
			<section class="remember-ticket-section remember-ticket-checkin">
				<h2><?php esc_html_e( 'Check-in', 'remember' ); ?></h2>
				<div id="remember-ticket-qr" class="remember-ticket-qr" data-code="<?php echo esc_attr( $data['checkin_code'] ); ?>"></div>
				<p class="remember-ticket-checkin-code"><?php echo esc_html( $data['checkin_code'] ); ?></p>
			</section>
			<?php endif; ?>

			<section class="remember-ticket-section">
				<h2><?php esc_html_e( 'Receipt', 'remember' ); ?></h2>
				<?php if ( ! empty( $data['invoice_number'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Invoice #:', 'remember' ); ?></strong> <?php echo esc_html( $data['invoice_number'] ); ?></p>
				<?php endif; ?>
				<table class="remember-ticket-lines">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Item', 'remember' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'remember' ); ?></th>
							<th><?php esc_html_e( 'Unit', 'remember' ); ?></th>
							<th><?php esc_html_e( 'Total', 'remember' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $data['line_items'] ) ) : ?>
							<tr>
								<td colspan="4"><?php esc_html_e( 'No line items.', 'remember' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $data['line_items'] as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $item['description'] ); ?></td>
									<td><?php echo esc_html( (string) $item['quantity'] ); ?></td>
									<td><?php echo esc_html( self::money( $item['unit_cost'] ) ); ?></td>
									<td><?php echo esc_html( self::money( $item['total'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<?php if ( ! empty( $data['discount_amount'] ) && (float) $data['discount_amount'] > 0 ) : ?>
						<tr>
							<th colspan="3">
								<?php
								$discount_percent = isset( $data['discount_percent'] ) ? (float) $data['discount_percent'] : 0;
								if ( $discount_percent > 0 ) {
									$percent_label = ( abs( $discount_percent - round( $discount_percent ) ) < 0.05 )
										? (string) (int) round( $discount_percent )
										: rtrim( rtrim( number_format( $discount_percent, 2, '.', '' ), '0' ), '.' );
									echo esc_html(
										sprintf(
											/* translators: %s: discount percent */
											__( 'Discount (%s%%)', 'remember' ),
											$percent_label
										)
									);
								} else {
									esc_html_e( 'Discount', 'remember' );
								}
								?>
							</th>
							<th><?php echo esc_html( self::money( 0 - (float) $data['discount_amount'] ) ); ?></th>
						</tr>
						<?php endif; ?>
						<tr>
							<th colspan="3"><?php esc_html_e( 'Total', 'remember' ); ?></th>
							<th><?php echo esc_html( self::money( $data['total_amount'] ) ); ?></th>
						</tr>
						<tr>
							<th colspan="3"><?php esc_html_e( 'Paid', 'remember' ); ?></th>
							<th><?php echo esc_html( self::money( $data['amount_paid'] ) ); ?></th>
						</tr>
						<tr>
							<th colspan="3"><?php esc_html_e( 'Amount due', 'remember' ); ?></th>
							<th><?php echo esc_html( self::money( $data['amount_due'] ) ); ?></th>
						</tr>
					</tfoot>
				</table>
			</section>

			<footer class="remember-ticket-footer">
				<p><?php esc_html_e( 'Present this ticket at check-in. Status and amounts reflect the latest billing sync.', 'remember' ); ?></p>
				<p class="remember-ticket-generated">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: datetime */
							__( 'Generated: %s', 'remember' ),
							date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $data['generated_at'] ) )
						)
					);
					?>
				</p>
			</footer>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Ticket page CSS.
	 *
	 * @return string
	 */
	public static function get_styles() {
		return '
			:root {
				--rt-ink: #1a1a1a;
				--rt-muted: #555;
				--rt-line: #ccc;
				--rt-bg: #f7f4ef;
				--rt-paper: #fff;
				--rt-alert: #b00020;
				--rt-warn: #8a5a00;
			}
			* { box-sizing: border-box; }
			body.remember-ticket-body {
				margin: 0;
				padding: 24px;
				font-family: "Iowan Old Style", "Palatino Linotype", Palatino, "Book Antiqua", Georgia, serif;
				background: linear-gradient(160deg, #ebe6de 0%, #f7f4ef 45%, #e4ece8 100%);
				color: var(--rt-ink);
			}
			.remember-ticket-toolbar {
				max-width: 720px;
				margin: 0 auto 16px;
				display: flex;
				gap: 8px;
			}
			.remember-ticket-btn {
				appearance: none;
				border: 1px solid #333;
				background: #1a1a1a;
				color: #fff;
				padding: 8px 14px;
				font: 600 14px/1.2 system-ui, sans-serif;
				cursor: pointer;
				text-decoration: none;
				display: inline-block;
			}
			.remember-ticket-btn-secondary {
				background: transparent;
				color: #1a1a1a;
			}
			.remember-ticket {
				position: relative;
				max-width: 720px;
				margin: 0 auto;
				background: var(--rt-paper);
				border: 1px solid var(--rt-line);
				padding: 28px 32px;
				overflow: hidden;
			}
			.remember-ticket-header {
				display: grid;
				grid-template-columns: auto 1fr auto;
				gap: 16px;
				align-items: center;
				border-bottom: 2px solid var(--rt-ink);
				padding-bottom: 16px;
				margin-bottom: 20px;
			}
			.remember-ticket-logo {
				max-height: 72px;
				max-width: 140px;
				width: auto;
				height: auto;
				object-fit: contain;
			}
			.remember-ticket-vendor-label {
				margin: 0;
				font-size: 11px;
				letter-spacing: 0.08em;
				text-transform: uppercase;
				color: var(--rt-muted);
				font-family: system-ui, sans-serif;
			}
			.remember-ticket-vendor-name {
				margin: 4px 0;
				font-size: 28px;
				line-height: 1.15;
				font-weight: 700;
			}
			.remember-ticket-vendor-url {
				margin: 0;
				font-size: 13px;
				color: var(--rt-muted);
				font-family: system-ui, sans-serif;
			}
			.remember-ticket-id {
				text-align: right;
				font-family: system-ui, sans-serif;
				font-size: 12px;
			}
			.remember-ticket-id strong {
				display: block;
				font-size: 18px;
				letter-spacing: 0.04em;
			}
			.remember-ticket-section {
				margin-bottom: 22px;
			}
			.remember-ticket-section h2 {
				margin: 0 0 8px;
				font-size: 12px;
				letter-spacing: 0.1em;
				text-transform: uppercase;
				font-family: system-ui, sans-serif;
				color: var(--rt-muted);
			}
			.remember-ticket-attendee-name,
			.remember-ticket-event-name {
				margin: 0 0 6px;
				font-size: 22px;
				font-weight: 700;
			}
			.remember-ticket-section p {
				margin: 0 0 6px;
				font-size: 15px;
			}
			.remember-ticket-pay-status--pending,
			.remember-ticket-pay-status--partial,
			.remember-ticket-pay-status--unknown {
				color: var(--rt-warn);
				font-weight: 700;
			}
			.remember-ticket-pay-status--paid {
				color: #1b5e20;
				font-weight: 700;
			}
			.remember-ticket-pay-status--refunded,
			.remember-ticket-pay-status--cancelled {
				color: var(--rt-alert);
				font-weight: 700;
			}
			.remember-ticket-lines {
				width: 100%;
				border-collapse: collapse;
				font-family: system-ui, sans-serif;
				font-size: 13px;
			}
			.remember-ticket-lines th,
			.remember-ticket-lines td {
				border-bottom: 1px solid var(--rt-line);
				padding: 8px 6px;
				text-align: left;
			}
			.remember-ticket-lines th:nth-child(2),
			.remember-ticket-lines td:nth-child(2),
			.remember-ticket-lines th:nth-child(3),
			.remember-ticket-lines td:nth-child(3),
			.remember-ticket-lines th:nth-child(4),
			.remember-ticket-lines td:nth-child(4),
			.remember-ticket-lines tfoot th,
			.remember-ticket-lines tfoot td {
				text-align: right;
			}
			.remember-ticket-qr svg {
				width: 168px;
				height: 168px;
			}
			.remember-ticket-checkin-code {
				font-family: ui-monospace, monospace;
				font-size: 14px;
				letter-spacing: 0.04em;
			}
			.remember-ticket-footer {
				border-top: 1px solid var(--rt-line);
				padding-top: 12px;
				font-size: 12px;
				color: var(--rt-muted);
				font-family: system-ui, sans-serif;
			}
			.remember-ticket-stamp {
				position: absolute;
				top: 42%;
				left: 50%;
				transform: translate(-50%, -50%) rotate(-18deg);
				border: 4px solid;
				padding: 10px 18px;
				font-family: system-ui, sans-serif;
				font-size: 28px;
				font-weight: 800;
				letter-spacing: 0.08em;
				opacity: 0.88;
				pointer-events: none;
				z-index: 2;
				background: rgba(255,255,255,0.55);
			}
			.remember-ticket-stamp--void {
				color: var(--rt-alert);
				border-color: var(--rt-alert);
			}
			.remember-ticket-stamp--payment_required {
				color: var(--rt-warn);
				border-color: var(--rt-warn);
				font-size: 22px;
			}
			@media print {
				body.remember-ticket-body {
					background: #fff;
					padding: 0;
				}
				.no-print { display: none !important; }
				.remember-ticket {
					border: none;
					max-width: none;
				}
			}
			@media (max-width: 640px) {
				.remember-ticket-header {
					grid-template-columns: 1fr;
				}
				.remember-ticket-id { text-align: left; }
			}
		';
	}

	/**
	 * Format money.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		$amount = (float) $amount;
		$formatted = '$' . number_format( abs( $amount ), 2 );
		return $amount < 0 ? '-' . $formatted : $formatted;
	}

	/**
	 * @param string $status Payment status.
	 * @return string
	 */
	private static function payment_status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'remember' ),
			'partial'   => __( 'Partial', 'remember' ),
			'paid'       => __( 'Paid', 'remember' ),
			'refunded'  => __( 'Refunded', 'remember' ),
			'cancelled' => __( 'Cancelled', 'remember' ),
		);
		if ( '' === $status ) {
			return __( 'Not invoiced / unpaid', 'remember' );
		}
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * @param string $status Application status.
	 * @return string
	 */
	private static function application_status_label( $status ) {
		$labels = array(
			'pending'    => __( 'Pending', 'remember' ),
			'accepted'   => __( 'Accepted', 'remember' ),
			'declined'   => __( 'Declined', 'remember' ),
			'cancelled'  => __( 'Cancelled', 'remember' ),
			'waitlisted' => __( 'Waitlisted', 'remember' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
