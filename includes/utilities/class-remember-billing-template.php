<?php
/**
 * Shared markup for payment / billing tables (admin and member-facing).
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Billing table rendering helpers.
 */
class Remember_Billing_Template {

	/**
	 * Payment status labels (translation-ready keys).
	 *
	 * @return array<string, string>
	 */
	public static function get_payment_status_labels() {
		return array(
			'pending'   => __( 'Pending', 'remember' ),
			'partial'   => __( 'Partial', 'remember' ),
			'paid'      => __( 'Paid', 'remember' ),
			'refunded'  => __( 'Refunded', 'remember' ),
			'cancelled' => __( 'Voided', 'remember' ),
		);
	}

	/**
	 * Hex colors for payment status (admin and front end).
	 *
	 * @return array<string, string>
	 */
	public static function get_payment_status_colors() {
		return array(
			'pending'   => '#f0b849',
			'partial'   => '#00a0d2',
			'paid'      => '#46b450',
			'refunded'  => '#dc3232',
			'cancelled' => '#72777c',
		);
	}

	/**
	 * Sum of credit-note / refund-line amounts synced onto a payment row.
	 *
	 * @param object $payment Payment row.
	 * @return float
	 */
	public static function get_credited_amount( $payment ) {
		if ( ! is_object( $payment ) ) {
			return 0.0;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-provider.php';
		$json = '';
		if ( Remember_Billing_Provider::is_xero() ) {
			if ( ! empty( $payment->xero_refund_lines ) ) {
				$json = $payment->xero_refund_lines;
			} elseif ( ! empty( $payment->quickbooks_refund_lines ) ) {
				$json = $payment->quickbooks_refund_lines;
			}
		} elseif ( ! empty( $payment->quickbooks_refund_lines ) ) {
			$json = $payment->quickbooks_refund_lines;
		} elseif ( ! empty( $payment->xero_refund_lines ) ) {
			$json = $payment->xero_refund_lines;
		}

		if ( '' === $json || null === $json ) {
			return 0.0;
		}

		$lines = json_decode( (string) $json, true );
		if ( ! is_array( $lines ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && isset( $line['amount'] ) ) {
				$total += floatval( $line['amount'] );
			}
		}
		return max( 0.0, $total );
	}

	/**
	 * Cash paid plus provider credits applied (for Payment/Credit column).
	 *
	 * @param object $payment Payment row.
	 * @return float
	 */
	public static function get_payment_and_credit_total( $payment ) {
		$paid = is_object( $payment ) && isset( $payment->amount_paid ) ? floatval( $payment->amount_paid ) : 0.0;
		return max( 0.0, $paid + self::get_credited_amount( $payment ) );
	}

	/**
	 * Resolve display invoice number and provider link URL for a payment row.
	 *
	 * Prefers Xero when xero_invoice_id is present; otherwise QuickBooks.
	 *
	 * @param object     $payment   Payment row.
	 * @param bool|string $link_mode false = number only; true|'staff' = accounting UI;
	 *                               'customer' = payee-facing online invoice (Xero).
	 * @return array{number:string,url:string,has_external:bool,provider:string}
	 */
	public static function get_payment_invoice_link_data( $payment, $link_mode = true ) {
		$out = array(
			'number'       => '',
			'url'          => '',
			'has_external' => false,
			'provider'     => '',
		);
		if ( ! is_object( $payment ) ) {
			return $out;
		}

		if ( true === $link_mode ) {
			$link_mode = 'staff';
		} elseif ( false !== $link_mode && 'staff' !== $link_mode && 'customer' !== $link_mode ) {
			$link_mode = false;
		}

		if ( ! empty( $payment->xero_invoice_id ) ) {
			$out['has_external'] = true;
			$out['provider']     = 'xero';
			$out['number']       = ! empty( $payment->xero_invoice_number )
				? (string) $payment->xero_invoice_number
				: '';
			if ( false !== $link_mode ) {
				require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-api.php';
				if ( 'customer' === $link_mode ) {
					// Member front end: only use the stored customer URL (populated on create/sync).
					// Never live-call Xero here — auth blips must not affect dashboard render.
					if ( ! empty( $payment->xero_online_invoice_url ) ) {
						$out['url'] = (string) $payment->xero_online_invoice_url;
					}
				} else {
					$out['url'] = Remember_Xero_API::get_invoice_app_url( $payment->xero_invoice_id );
				}
			}
			return $out;
		}

		if ( ! empty( $payment->quickbooks_invoice_id ) ) {
			$out['has_external'] = true;
			$out['provider']     = 'quickbooks';
			$out['number']       = ! empty( $payment->quickbooks_invoice_number )
				? (string) $payment->quickbooks_invoice_number
				: '';
			// Staff deep-link only; QBO has no simple public online-invoice URL like Xero.
			if ( 'staff' === $link_mode ) {
				require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-api.php';
				$out['url'] = Remember_QuickBooks_API::get_invoice_app_url( $payment->quickbooks_invoice_id );
			}
			return $out;
		}

		return $out;
	}

	/**
	 * Echo invoice # cell contents (linked when a provider URL is available).
	 *
	 * @param object      $payment      Payment row.
	 * @param string      $pending_text Message when external id exists but number not synced yet.
	 * @param string      $empty_class  Class for empty / pending spans.
	 * @param bool|string $link_mode    false | true/'staff' | 'customer'.
	 */
	public static function render_invoice_number_cell( $payment, $pending_text = '', $empty_class = 'description', $link_mode = true ) {
		$data = self::get_payment_invoice_link_data( $payment, $link_mode );
		if ( '' === $pending_text ) {
			$pending_text = __( 'Sync payments to load invoice #', 'remember' );
		}

		if ( '' !== $data['number'] ) {
			if ( '' !== $data['url'] ) {
				if ( 'customer' === $link_mode ) {
					$label = __( 'View invoice online', 'remember' );
				} elseif ( 'xero' === $data['provider'] ) {
					$label = __( 'Open invoice in Xero', 'remember' );
				} else {
					$label = __( 'Open invoice in QuickBooks', 'remember' );
				}
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s"><strong>%3$s</strong></a>',
					esc_url( $data['url'] ),
					esc_attr( $label ),
					esc_html( $data['number'] )
				);
				return;
			}
			echo '<strong>' . esc_html( $data['number'] ) . '</strong>';
			return;
		}

		if ( $data['has_external'] ) {
			printf( '<span class="%1$s">%2$s</span>', esc_attr( $empty_class ), esc_html( $pending_text ) );
			return;
		}

		printf( '<span class="%1$s">—</span>', esc_attr( $empty_class ) );
	}

	/**
	 * Output a payments table.
	 *
	 * @param array $args {
	 *     @type array               $payments             List of payment row objects.
	 *     @type string              $context              'admin' or 'member'.
	 *     @type Remember_Member|null $member_model       Required when context is admin (for name/email column).
	 *     @type array<int, string>  $payment_event_names Optional. payment_id => event name for member context.
	 *     @type string              $table_class          Extra class on <table>.
	 * }
	 */
	public static function render_payments_table( $args ) {
		$defaults = array(
			'payments'             => array(),
			'context'              => 'admin',
			'member_model'         => null,
			'payment_event_names'  => array(),
			'table_class'          => '',
			'open_credits'         => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$payments            = $args['payments'];
		$context             = 'member' === $args['context'] ? 'member' : 'admin';
		$member_model        = $args['member_model'];
		$payment_event_names = is_array( $args['payment_event_names'] ) ? $args['payment_event_names'] : array();
		$table_extra         = trim( $args['table_class'] );

		$status_labels = self::get_payment_status_labels();
		$status_colors = self::get_payment_status_colors();

		// Front end: many themes set table { display: block } — use a div + CSS Grid instead of <table>.
		if ( 'member' === $context ) {
			self::render_member_billing_sheet( $payments, $payment_event_names, $status_labels, $status_colors, $args['open_credits'] );
			return;
		}

		$table_classes = array( 'remember-billing-payments-table', 'wp-list-table', 'widefat', 'striped', 'remember-responsive-table', 'remember-list-cards' );
		if ( '' !== $table_extra ) {
			$table_classes[] = $table_extra;
		}

		?>
		<div class="remember-table-scroll">
		<table class="<?php echo esc_attr( implode( ' ', $table_classes ) ); ?>">
			<thead>
				<tr>
					<th class="column-member"><?php esc_html_e( 'Member', 'remember' ); ?></th>
					<th class="column-qb-invoice"><?php esc_html_e( 'Invoice #', 'remember' ); ?></th>
					<th class="column-amount remember-num"><?php esc_html_e( 'Subtotal Amount', 'remember' ); ?></th>
					<th class="column-paid remember-num"><?php esc_html_e( 'Payment/Credit', 'remember' ); ?></th>
					<th class="column-due remember-num"><?php esc_html_e( 'Amount Due', 'remember' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'remember' ); ?></th>
					<th class="column-method"><?php esc_html_e( 'Method', 'remember' ); ?></th>
					<th class="column-date"><?php esc_html_e( 'Date', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $payments as $payment ) : ?>
					<tr>
						<td class="column-member" data-label="<?php echo esc_attr__( 'Member', 'remember' ); ?>">
							<?php
							$user = null;
							if ( $member_model && isset( $payment->member_id ) ) {
								$member = $member_model->get( $payment->member_id );
								$user   = $member ? get_user_by( 'ID', $payment->member_id ) : null;
							}
							if ( $user ) :
								$member_detail_url = admin_url( 'admin.php?page=remember-members&view=' . absint( $payment->member_id ) );
								?>
								<a href="<?php echo esc_url( $member_detail_url ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br class="remember-list-break">
								<span class="description"><?php echo esc_html( $user->user_email ); ?></span>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Member not found', 'remember' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="column-qb-invoice" data-label="<?php echo esc_attr__( 'Invoice #', 'remember' ); ?>">
							<?php self::render_invoice_number_cell( $payment ); ?>
						</td>
						<td class="column-amount remember-num" data-label="<?php echo esc_attr__( 'Subtotal Amount', 'remember' ); ?>">
							<span class="remember-list-meta-label"><?php esc_html_e( 'Subtotal:', 'remember' ); ?></span>
							<strong>$<?php echo esc_html( number_format( (float) $payment->total_amount, 2 ) ); ?></strong>
						</td>
						<td class="column-paid remember-num" data-label="<?php echo esc_attr__( 'Payment/Credit', 'remember' ); ?>">
							<span class="remember-list-meta-label"><?php esc_html_e( 'Paid:', 'remember' ); ?></span>
							$<?php echo esc_html( number_format( self::get_payment_and_credit_total( $payment ), 2 ) ); ?>
						</td>
						<td class="column-due remember-num" data-label="<?php echo esc_attr__( 'Amount Due', 'remember' ); ?>">
							<span class="remember-list-meta-label"><?php esc_html_e( 'Due:', 'remember' ); ?></span>
							<strong style="color: <?php echo (float) $payment->amount_due > 0 ? '#dc3232' : '#46b450'; ?>;">
								$<?php echo esc_html( number_format( (float) $payment->amount_due, 2 ) ); ?>
							</strong>
						</td>
						<td class="column-status" data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
							<?php
							$ps = isset( $payment->payment_status ) ? (string) $payment->payment_status : '';
							$sl = isset( $status_labels[ $ps ] ) ? $status_labels[ $ps ] : $ps;
							$sc = isset( $status_colors[ $ps ] ) ? $status_colors[ $ps ] : '#646970';
							?>
							<span style="color: <?php echo esc_attr( $sc ); ?>; font-weight: bold;"><?php echo esc_html( $sl ); ?></span>
						</td>
						<td class="column-method" data-label="<?php echo esc_attr__( 'Method', 'remember' ); ?>">
							<?php echo esc_html( ucfirst( $payment->payment_method ? $payment->payment_method : 'manual' ) ); ?>
						</td>
						<td class="column-date" data-label="<?php echo esc_attr__( 'Date', 'remember' ); ?>">
							<?php if ( ! empty( $payment->payment_date ) ) : ?>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $payment->payment_date ) ) ); ?>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Member dashboard billing: real <table> plus scoped inline CSS so themes cannot force display:block on tables.
	 *
	 * @param array<int, object>      $payments            Payment rows.
	 * @param array<int, string>      $payment_event_names payment_id => event name.
	 * @param array<string, string>   $status_labels       Status labels.
	 * @param array<string, string>   $status_colors       Status hex colors.
	 * @param array<int, array>      $open_credits        Unallocated credit notes.
	 */
	private static function render_member_billing_sheet( $payments, $payment_event_names, $status_labels, $status_colors, $open_credits = array() ) {
		static $member_billing_css_printed = false;
		if ( ! $member_billing_css_printed ) {
			$member_billing_css_printed = true;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS string only.
			echo self::get_member_billing_inline_css();
		}
		?>
		<table class="remember-billing-mt" aria-label="<?php echo esc_attr( __( 'Payment records', 'remember' ) ); ?>">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Event', 'remember' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Invoice #', 'remember' ); ?></th>
					<th scope="col" class="remember-billing-mt-num"><?php esc_html_e( 'Subtotal Amount', 'remember' ); ?></th>
					<th scope="col" class="remember-billing-mt-num"><?php esc_html_e( 'Payment/Credit', 'remember' ); ?></th>
					<th scope="col" class="remember-billing-mt-num"><?php esc_html_e( 'Amount Due', 'remember' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'remember' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'remember' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				// Each row is Xero RemainingCredit only. After the note is allocated, that
				// remainder drops and the invoice row carries the applied amount instead.
				foreach ( (array) $open_credits as $credit_note ) :
					?>
					<?php
					$credit_number = isset( $credit_note['number'] ) ? (string) $credit_note['number'] : '';
					if ( '' === $credit_number ) {
						$credit_number = __( 'Credit note', 'remember' );
					}
					$credit_remaining = isset( $credit_note['remaining'] ) ? (float) $credit_note['remaining'] : 0.0;
					$credit_date      = isset( $credit_note['date'] ) ? (string) $credit_note['date'] : '';
					?>
					<tr>
						<td><?php esc_html_e( 'Open credit', 'remember' ); ?></td>
						<td><?php echo esc_html( $credit_number ); ?></td>
						<td class="remember-billing-mt-num"><span class="remember-billing-mt-muted">&mdash;</span></td>
						<td class="remember-billing-mt-num">$<?php echo esc_html( number_format( $credit_remaining, 2 ) ); ?></td>
						<td class="remember-billing-mt-num"><span class="remember-billing-mt-muted">&mdash;</span></td>
						<td><strong style="color: #46b450;"><?php esc_html_e( 'Available', 'remember' ); ?></strong></td>
						<td><?php esc_html_e( 'Credit note', 'remember' ); ?></td>
						<td>
							<?php if ( '' !== $credit_date ) : ?>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $credit_date ) ) ); ?>
							<?php else : ?>
								<span class="remember-billing-mt-muted">&mdash;</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $payments as $payment ) : ?>
					<?php
					$ename = isset( $payment_event_names[ $payment->payment_id ] ) ? $payment_event_names[ $payment->payment_id ] : '—';
					$ps    = isset( $payment->payment_status ) ? (string) $payment->payment_status : '';
					$sl    = isset( $status_labels[ $ps ] ) ? $status_labels[ $ps ] : $ps;
					$sc    = isset( $status_colors[ $ps ] ) ? $status_colors[ $ps ] : '#646970';
					?>
					<tr>
						<td><?php echo esc_html( $ename ); ?></td>
						<td>
							<?php
							self::render_invoice_number_cell(
								$payment,
								__( 'Invoice # pending sync', 'remember' ),
								'remember-billing-mt-muted',
								'customer'
							);
							?>
						</td>
						<td class="remember-billing-mt-num"><strong>$<?php echo esc_html( number_format( (float) $payment->total_amount, 2 ) ); ?></strong></td>
						<td class="remember-billing-mt-num">$<?php echo esc_html( number_format( self::get_payment_and_credit_total( $payment ), 2 ) ); ?></td>
						<td class="remember-billing-mt-num">
							<strong style="color: <?php echo (float) $payment->amount_due > 0 ? '#dc3232' : '#46b450'; ?>;">
								$<?php echo esc_html( number_format( (float) $payment->amount_due, 2 ) ); ?>
							</strong>
						</td>
						<td><strong style="color: <?php echo esc_attr( $sc ); ?>;"><?php echo esc_html( $sl ); ?></strong></td>
						<td><?php echo esc_html( ucfirst( $payment->payment_method ? $payment->payment_method : 'manual' ) ); ?></td>
						<td>
							<?php if ( ! empty( $payment->payment_date ) ) : ?>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $payment->payment_date ) ) ); ?>
							<?php else : ?>
								<span class="remember-billing-mt-muted">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Critical CSS for member billing table (must override theme responsive-table rules). Scoped to #remember-member-billing.
	 *
	 * @return string
	 */
	private static function get_member_billing_inline_css() {
		return '<style type="text/css" id="remember-member-billing-css">
#remember-member-billing table.remember-billing-mt{display:table!important;width:100%!important;max-width:100%!important;border-collapse:collapse!important;border-spacing:0!important;margin:0!important;font-size:14px!important;line-height:1.5!important;color:#2c3338!important;background:#fff!important;table-layout:auto!important}
#remember-member-billing table.remember-billing-mt thead{display:table-header-group!important}
#remember-member-billing table.remember-billing-mt tbody{display:table-row-group!important}
#remember-member-billing table.remember-billing-mt tr{display:table-row!important}
#remember-member-billing table.remember-billing-mt colgroup{display:table-column-group!important}
#remember-member-billing table.remember-billing-mt th,#remember-member-billing table.remember-billing-mt td{display:table-cell!important;float:none!important;vertical-align:middle!important;padding:10px 12px!important;width:auto!important;max-width:none!important;clear:none!important;position:static!important;border:none!important;border-top:1px solid #c3c4c7!important;text-align:left!important}
#remember-member-billing table.remember-billing-mt thead th{background:#f6f7f7!important;font-weight:600!important;color:#1d2327!important;border-top:none!important;border-bottom:1px solid #c3c4c7!important}
#remember-member-billing table.remember-billing-mt tbody tr:first-child td{border-top:none!important}
#remember-member-billing table.remember-billing-mt tbody tr:nth-child(odd) td{background:#f6f7f7!important}
#remember-member-billing table.remember-billing-mt tbody tr:nth-child(even) td{background:#fff!important}
#remember-member-billing table.remember-billing-mt .remember-billing-mt-num{text-align:right!important;font-variant-numeric:tabular-nums!important}
#remember-member-billing table.remember-billing-mt thead th.remember-billing-mt-num{text-align:right!important}
#remember-member-billing table.remember-billing-mt .remember-billing-mt-muted{color:#646970!important;font-size:13px!important}
</style>';
	}
}
