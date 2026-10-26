<?php
/**
 * Billing unwind when an application is declined or cancelled.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Void / refund / leave invoice handling for application exit paths.
 */
class Remember_Billing_Unwind {

	/**
	 * What the last apply() did to the invoice; see last_outcome().
	 *
	 * @var string
	 */
	private static $last_outcome = '';

	/**
	 * Allowed billing actions.
	 *
	 * @return string[]
	 */
	public static function allowed_actions() {
		return array( 'void', 'refund', 'leave' );
	}

	/**
	 * Sanitize billing action from request.
	 *
	 * @param string $action Raw action.
	 * @return string void|refund|leave
	 */
	public static function sanitize_action( $action ) {
		$action = sanitize_key( (string) $action );
		return in_array( $action, self::allowed_actions(), true ) ? $action : 'leave';
	}

	/**
	 * Whether the application has a provider invoice to act on.
	 *
	 * @param int $application_id Application ID.
	 * @return bool
	 */
	public static function has_invoice( $application_id ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
		$payment = ( new Remember_Payment() )->get_by_application( absint( $application_id ) );
		if ( ! $payment ) {
			return false;
		}
		return ! empty( $payment->xero_invoice_id ) || ! empty( $payment->quickbooks_invoice_id );
	}

	/**
	 * Apply billing action after status change.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $billing_action void|refund|leave.
	 * @param string $reason         Short reason for provider reference.
	 * @return true|WP_Error True on success / leave / no invoice; WP_Error on provider failure.
	 */
	public static function apply( $application_id, $billing_action, $reason = '' ) {
		$billing_action     = self::sanitize_action( $billing_action );
		$application_id     = absint( $application_id );
		self::$last_outcome = '';

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-ticket.php';
		Remember_Ticket::set_voided( $application_id, true );

		if ( 'leave' === $billing_action ) {
			return true;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-provider.php';
		$payment = ( new Remember_Payment() )->get_by_application( $application_id );
		if ( ! $payment ) {
			return true;
		}

		if ( Remember_Billing_Provider::is_xero() && ! empty( $payment->xero_invoice_id ) ) {
			return self::apply_xero( $payment, $billing_action, $reason );
		}
		if ( Remember_Billing_Provider::is_quickbooks() && ! empty( $payment->quickbooks_invoice_id ) ) {
			return self::apply_quickbooks( $payment, $billing_action, $reason );
		}

		if ( ! empty( $payment->xero_invoice_id ) || ! empty( $payment->quickbooks_invoice_id ) ) {
			self::$last_outcome = 'skipped_inactive_provider';
			Remember_Logger::warning(
				'Billing action skipped; invoice belongs to a provider that is not active',
				array(
					'payment_id'     => $payment->payment_id,
					'application_id' => $application_id,
					'billing_action' => $billing_action,
					'active'         => Remember_Billing_Provider::get(),
				)
			);
		}
		return true;
	}

	/**
	 * Render radio group for void / refund / leave.
	 *
	 * @param string $field_name Input name.
	 * @param bool   $has_invoice Whether an invoice exists.
	 * @return string HTML.
	 */
	public static function render_action_radios( $field_name = 'billing_action', $has_invoice = true ) {
		ob_start();
		?>
		<fieldset class="remember-billing-unwind" style="margin: 10px 0; padding: 10px; border: 1px solid #ccd0d4; background: #f6f7f7;">
			<legend style="padding: 0 6px;"><strong><?php esc_html_e( 'Invoice / billing', 'remember' ); ?></strong></legend>
			<?php if ( ! $has_invoice ) : ?>
				<p class="description" style="margin: 0 0 8px;">
					<?php esc_html_e( 'No billing-provider invoice is linked. Billing choice will be ignored.', 'remember' ); ?>
				</p>
			<?php endif; ?>
			<label style="display:block; margin-bottom:6px;">
				<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="void" <?php checked( $has_invoice ); ?> <?php disabled( ! $has_invoice ); ?>>
				<?php esc_html_e( 'Void the invoice', 'remember' ); ?>
			</label>
			<label style="display:block; margin-bottom:6px;">
				<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="refund" <?php disabled( ! $has_invoice ); ?>>
				<?php esc_html_e( 'Refund (voids the invoice if nothing was paid; otherwise lists the refund steps for billing staff)', 'remember' ); ?>
			</label>
			<label style="display:block; margin-bottom:0;">
				<input type="radio" name="<?php echo esc_attr( $field_name ); ?>" value="leave" <?php checked( ! $has_invoice ); ?>>
				<?php esc_html_e( 'Leave the invoice unaltered', 'remember' ); ?>
			</label>
		</fieldset>
		<?php
		return ob_get_clean();
	}

	/**
	 * What the last apply() did to the invoice.
	 *
	 * @return string '' (nothing), 'voided', 'voided_unpaid' (Refund chosen, nothing had been paid),
	 *                or 'skipped_inactive_provider'.
	 */
	public static function last_outcome() {
		return self::$last_outcome;
	}

	/**
	 * Admin notice HTML describing an apply() result.
	 *
	 * @param true|WP_Error $unwind         Result of apply().
	 * @param string        $billing_action void|refund|leave.
	 * @return string Notice HTML; empty when the invoice was left alone without error.
	 */
	public static function admin_notice_html( $unwind, $billing_action ) {
		if ( is_wp_error( $unwind ) ) {
			$data  = $unwind->get_error_data();
			$steps = ( is_array( $data ) && ! empty( $data['steps'] ) ) ? (array) $data['steps'] : array();
			$html  = '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $unwind->get_error_message() ) . '</p>';
			if ( ! empty( $steps ) ) {
				$html .= '<ol>';
				foreach ( $steps as $step ) {
					$html .= '<li>' . esc_html( $step ) . '</li>';
				}
				$html .= '</ol>';
			}
			return $html . '</div>';
		}

		if ( 'voided_unpaid' === self::$last_outcome ) {
			return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Nothing had been paid on this invoice, so it was voided instead of refunded.', 'remember' ) . '</p></div>';
		}
		if ( 'voided' === self::$last_outcome ) {
			return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The invoice was voided in the connected provider.', 'remember' ) . '</p></div>';
		}
		if ( 'skipped_inactive_provider' === self::$last_outcome ) {
			return '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The invoice was not changed: it belongs to a billing provider that is no longer active. Update it in that provider directly.', 'remember' ) . '</p></div>';
		}
		return '';
	}

	/**
	 * Void the Xero invoice, or hand a paid one to billing staff for a manual refund.
	 *
	 * @param object $payment        Payment row.
	 * @param string $billing_action void|refund.
	 * @param string $reason         Reason, for the log.
	 * @return true|WP_Error
	 */
	private static function apply_xero( $payment, $billing_action, $reason ) {
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-api.php';
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-sync.php';

		if ( ! Remember_Xero_OAuth::is_connected() ) {
			return new WP_Error( 'xero_not_connected', __( 'Xero is not connected; could not update the invoice.', 'remember' ) );
		}

		$invoice = Remember_Xero_API::get_invoice( $payment->xero_invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$received = isset( $invoice['AmountPaid'] ) ? floatval( $invoice['AmountPaid'] ) : 0.0;
		if ( 'refund' === $billing_action && ( $received > 0.001 || ! empty( $invoice['Payments'] ) ) ) {
			Remember_Xero_Sync::sync_payment_status( $payment->payment_id );
			return self::manual_refund(
				$payment,
				'xero',
				isset( $invoice['InvoiceNumber'] ) ? (string) $invoice['InvoiceNumber'] : '',
				$received,
				isset( $invoice['AmountDue'] ) ? floatval( $invoice['AmountDue'] ) : 0.0,
				$reason
			);
		}

		$result = Remember_Xero_API::void_invoice( $payment->xero_invoice_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::mark_cancelled( $payment, 'xero', $billing_action, $reason );
		return true;
	}

	/**
	 * Void the QuickBooks invoice, or hand a paid one to billing staff for a manual refund.
	 *
	 * @param object $payment        Payment row.
	 * @param string $billing_action void|refund.
	 * @param string $reason         Reason, for the log.
	 * @return true|WP_Error
	 */
	private static function apply_quickbooks( $payment, $billing_action, $reason ) {
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-api.php';
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-sync.php';

		$qb_settings = Remember_QuickBooks_OAuth::get_settings();
		if ( empty( $qb_settings['access_token'] ) || empty( $qb_settings['realm_id'] ) ) {
			return new WP_Error( 'qb_not_connected', __( 'QuickBooks is not connected; could not update the invoice.', 'remember' ) );
		}

		$invoice = Remember_QuickBooks_API::get_invoice( $payment->quickbooks_invoice_id );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( Remember_QuickBooks_API::is_invoice_voided( $invoice, $payment->total_amount ) ) {
			self::mark_cancelled( $payment, 'quickbooks', $billing_action, $reason );
			return true;
		}

		$total   = isset( $invoice['TotalAmt'] ) ? floatval( $invoice['TotalAmt'] ) : 0.0;
		$balance = isset( $invoice['Balance'] ) ? floatval( $invoice['Balance'] ) : 0.0;
		$applied = max( 0.0, $total - $balance );
		if ( $applied > 0.001 ) {
			if ( 'refund' === $billing_action ) {
				// Balance alone can't separate cash from applied Credit Memos; the synced row can.
				$received = $applied;
				$synced   = Remember_QuickBooks_Sync::sync_payment_status( $payment->payment_id );
				if ( true === $synced ) {
					require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
					$fresh = ( new Remember_Payment() )->get( $payment->payment_id );
					if ( $fresh && floatval( $fresh->amount_paid ) > 0.001 ) {
						$received = min( $applied, floatval( $fresh->amount_paid ) );
					}
				}
				return self::manual_refund(
					$payment,
					'quickbooks',
					isset( $invoice['DocNumber'] ) ? (string) $invoice['DocNumber'] : '',
					$received,
					$balance,
					$reason
				);
			}
			return new WP_Error(
				'qb_invoice_has_payment',
				__( 'reMember did not void this invoice because a payment or credit is applied to it in QuickBooks. Remove that payment in QuickBooks, or choose Refund instead of Void.', 'remember' )
			);
		}

		$result = Remember_QuickBooks_API::void_invoice( $payment->quickbooks_invoice_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::mark_cancelled( $payment, 'quickbooks', $billing_action, $reason );
		return true;
	}

	/**
	 * Mark the local invoice row cancelled after a void.
	 *
	 * @param object $payment        Payment row.
	 * @param string $provider       xero|quickbooks.
	 * @param string $billing_action void|refund.
	 * @param string $reason         Reason, for the log.
	 */
	private static function mark_cancelled( $payment, $provider, $billing_action, $reason ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
		$prefix = 'xero' === $provider ? 'xero' : 'quickbooks';
		( new Remember_Payment() )->update(
			$payment->payment_id,
			array(
				'payment_status'           => 'cancelled',
				'amount_paid'              => 0,
				'amount_due'               => 0,
				$prefix . '_payment_lines' => null,
				$prefix . '_refund_lines'  => null,
			)
		);

		self::$last_outcome = 'refund' === $billing_action ? 'voided_unpaid' : 'voided';
		Remember_Logger::info(
			'Invoice voided for application exit',
			array(
				'payment_id'     => $payment->payment_id,
				'application_id' => $payment->event_application_id ?? null,
				'provider'       => $provider,
				'billing_action' => $billing_action,
				'reason'         => $reason,
			)
		);
	}

	/**
	 * Leave a paid invoice alone and return the refund steps for billing staff.
	 *
	 * Money goes back through whatever the member paid with (Stripe, bank, cheque),
	 * which reMember is not connected to, so the refund cannot be issued from here.
	 *
	 * @param object $payment        Payment row.
	 * @param string $provider       xero|quickbooks.
	 * @param string $invoice_number Provider invoice number (may be empty).
	 * @param float  $received       Amount paid or credited against the invoice.
	 * @param float  $balance        Amount still owing.
	 * @param string $reason         Reason, for the log.
	 * @return WP_Error Code refund_manual, with the steps in data['steps'].
	 */
	private static function manual_refund( $payment, $provider, $invoice_number, $received, $balance, $reason ) {
		$money   = static function ( $amount ) {
			return '$' . number_format( (float) $amount, 2 );
		};
		$invoice = '' !== $invoice_number ? $invoice_number : __( '(no number)', 'remember' );
		$steps   = array();

		if ( 'xero' === $provider ) {
			$steps[] = sprintf(
				/* translators: %s: amount */
				__( 'Return %s to the member the way they paid (Stripe, bank transfer, cheque).', 'remember' ),
				$money( $received )
			);
			$steps[] = sprintf(
				/* translators: 1: Xero invoice number, 2: amount */
				__( 'In Xero, create a credit note for invoice %1$s for %2$s.', 'remember' ),
				$invoice,
				$money( $received + $balance )
			);
			if ( $balance > 0.001 ) {
				$steps[] = sprintf(
					/* translators: %s: amount */
					__( 'Allocate %s of the credit note to the invoice to clear the unpaid balance.', 'remember' ),
					$money( $balance )
				);
			}
			$steps[] = sprintf(
				/* translators: %s: amount */
				__( 'Record a cash refund of %s on the credit note, from the account the money left.', 'remember' ),
				$money( $received )
			);
			$label = __( 'Xero', 'remember' );
		} else {
			$steps[] = sprintf(
				/* translators: %s: amount */
				__( 'In QuickBooks, create a Refund Receipt for this customer for %s. If they paid through QuickBooks Payments, the Refund Receipt can send the money back; otherwise return it the way they paid (Stripe, bank transfer, cheque).', 'remember' ),
				$money( $received )
			);
			if ( $balance > 0.001 ) {
				$steps[] = sprintf(
					/* translators: 1: amount, 2: QuickBooks invoice number */
					__( 'Create a Credit Memo for the unpaid %1$s and apply it to invoice %2$s.', 'remember' ),
					$money( $balance ),
					$invoice
				);
			}
			$label = __( 'QuickBooks', 'remember' );
		}
		$steps[] = __( 'Run a billing sync in reMember, or wait for the scheduled one, to update the ledger.', 'remember' );

		Remember_Logger::info(
			'Refund left for billing staff; money already received on invoice',
			array(
				'payment_id'     => $payment->payment_id,
				'application_id' => $payment->event_application_id ?? null,
				'provider'       => $provider,
				'invoice'        => $invoice_number,
				'received'       => round( (float) $received, 2 ),
				'balance'        => round( (float) $balance, 2 ),
				'reason'         => $reason,
			)
		);

		return new WP_Error(
			'refund_manual',
			sprintf(
				/* translators: 1: invoice number, 2: amount received, 3: provider name */
				__( 'Invoice %1$s was not changed because %2$s has already been received. To refund it in %3$s:', 'remember' ),
				$invoice,
				$money( $received ),
				$label
			),
			array( 'steps' => $steps )
		);
	}
}
