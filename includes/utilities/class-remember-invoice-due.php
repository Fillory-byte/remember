<?php
/**
 * Per-event invoice due dates, and re-dating unpaid invoices when an event's setting changes.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';

/**
 * Event invoice due dates.
 *
 * An event may set a number of days after the invoice date, a latest due date,
 * or both (whichever comes first). With neither, the billing provider's own
 * payment terms apply. Changing the setting re-dates the event's unpaid
 * invoices in the background and emails each member the updated invoice.
 */
class Remember_Invoice_Due {

	const CRON_HOOK         = 'remember_invoice_due_update';
	const JOB_OPTION_PREFIX = 'remember_invoice_due_job_';
	const MAX_DAYS          = 365;

	/**
	 * Invoices handled per run. Xero allows 60 calls a minute: one batch read, one batch
	 * update and one email per invoice leaves room for staff working at the same time.
	 */
	const XERO_CHUNK = 40;
	const QB_CHUNK   = 200;

	/**
	 * Seconds between runs, so each Xero run starts in a fresh rate-limit minute.
	 */
	const RUN_GAP = 65;

	/**
	 * Failed runs in a row before a job gives up. Rate-limit pauses do not count.
	 */
	const MAX_FAILED_RUNS = 3;

	/**
	 * Register the background job.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_job' ) );
	}

	/**
	 * Validate the event form's due-date fields.
	 *
	 * @param string $days_raw Days after the invoice date, or blank.
	 * @param string $by_raw   Latest due date (Y-m-d), or blank.
	 * @return array|WP_Error Keys invoice_due_days and invoice_due_by, each null when blank.
	 */
	public static function fields_from_input( $days_raw, $by_raw ) {
		$days_raw = trim( (string) $days_raw );
		$by_raw   = trim( (string) $by_raw );
		$days     = null;
		$by       = null;

		if ( '' !== $days_raw ) {
			if ( ! preg_match( '/^\d+$/', $days_raw ) || (int) $days_raw > self::MAX_DAYS ) {
				return new WP_Error(
					'remember_invoice_due_days',
					sprintf(
						/* translators: %d: maximum number of days */
						__( 'Invoice due after must be a whole number of days from 0 to %d, or left blank.', 'remember' ),
						self::MAX_DAYS
					)
				);
			}
			$days = (int) $days_raw;
		}

		if ( '' !== $by_raw ) {
			$dt = date_create_immutable_from_format( '!Y-m-d', $by_raw, wp_timezone() );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $by_raw ) {
				return new WP_Error( 'remember_invoice_due_by', __( 'Invoice due no later than must be a valid date, or left blank.', 'remember' ) );
			}
			$by = $by_raw;
		}

		return array(
			'invoice_due_days' => $days,
			'invoice_due_by'   => $by,
		);
	}

	/**
	 * Days setting, or null.
	 *
	 * @param object|null $event Event row.
	 * @return int|null
	 */
	private static function days( $event ) {
		if ( ! is_object( $event ) || ! isset( $event->invoice_due_days ) || '' === (string) $event->invoice_due_days ) {
			return null;
		}
		return (int) $event->invoice_due_days;
	}

	/**
	 * Latest due date setting (Y-m-d), or null.
	 *
	 * @param object|null $event Event row.
	 * @return string|null
	 */
	private static function latest( $event ) {
		if ( ! is_object( $event ) || empty( $event->invoice_due_by ) || '0000-00-00' === substr( (string) $event->invoice_due_by, 0, 10 ) ) {
			return null;
		}
		return substr( (string) $event->invoice_due_by, 0, 10 );
	}

	/**
	 * Whether the event sets its own due date.
	 *
	 * @param object|null $event Event row.
	 * @return bool
	 */
	public static function has_setting( $event ) {
		return null !== self::days( $event ) || null !== self::latest( $event );
	}

	/**
	 * Whether two event rows differ in their due-date setting.
	 *
	 * @param object|null $before Event before the save.
	 * @param object|null $after  Event after the save.
	 * @return bool
	 */
	public static function setting_changed( $before, $after ) {
		return self::days( $before ) !== self::days( $after ) || self::latest( $before ) !== self::latest( $after );
	}

	/**
	 * Due date for an invoice dated $invoice_date. Empty when the event sets none.
	 *
	 * Days and latest date together give whichever comes first. A due date never
	 * falls before the invoice date, so an invoice created after the latest due
	 * date is due on its own date.
	 *
	 * @param object|null $event        Event row.
	 * @param string      $invoice_date Invoice date (Y-m-d).
	 * @return string Y-m-d or ''.
	 */
	public static function due_date_for( $event, $invoice_date ) {
		if ( ! self::has_setting( $event ) ) {
			return '';
		}
		$invoice = date_create_immutable_from_format( '!Y-m-d', substr( (string) $invoice_date, 0, 10 ), wp_timezone() );
		if ( ! $invoice ) {
			$invoice = date_create_immutable_from_format( '!Y-m-d', current_time( 'Y-m-d' ), wp_timezone() );
		}
		$days   = self::days( $event );
		$latest = self::latest( $event );

		$candidates = array();
		if ( null !== $days ) {
			$candidates[] = $invoice->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		}
		if ( null !== $latest ) {
			$candidates[] = $latest;
		}
		return max( min( $candidates ), $invoice->format( 'Y-m-d' ) );
	}

	/**
	 * Staff-facing sentence for the event's own setting. Empty when there is none.
	 *
	 * @param object|null $event Event row.
	 * @return string
	 */
	public static function describe_setting( $event ) {
		$days   = self::days( $event );
		$latest = self::latest( $event );
		if ( null === $days && null === $latest ) {
			return '';
		}
		$format       = get_option( 'date_format' );
		$latest_label = null !== $latest ? date_i18n( $format, strtotime( $latest . ' 12:00:00' ) ) : '';
		$example      = date_i18n( $format, strtotime( self::due_date_for( $event, current_time( 'Y-m-d' ) ) . ' 12:00:00' ) );

		if ( null !== $days && null !== $latest ) {
			$rule = sprintf(
				/* translators: 1: number of days, 2: latest due date */
				_n( 'Invoices are due %1$d day after the invoice date, and never later than %2$s.', 'Invoices are due %1$d days after the invoice date, and never later than %2$s.', $days, 'remember' ),
				$days,
				$latest_label
			);
		} elseif ( null !== $days ) {
			$rule = sprintf(
				/* translators: %d: number of days */
				_n( 'Invoices are due %d day after the invoice date.', 'Invoices are due %d days after the invoice date.', $days, 'remember' ),
				$days
			);
		} else {
			$rule = sprintf(
				/* translators: %s: latest due date */
				__( 'Invoices are due on %s, or on the invoice date if they are created after it.', 'remember' ),
				$latest_label
			);
		}

		return $rule . ' ' . sprintf(
			/* translators: %s: example due date */
			__( 'An invoice created today would be due %s.', 'remember' ),
			$example
		);
	}

	/**
	 * Staff-facing sentence for what applies when the event sets no due date.
	 *
	 * Reads the provider's default terms, cached by the provider class.
	 *
	 * @return string
	 */
	public static function provider_default_text() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-provider.php';

		if ( Remember_Billing_Provider::is_xero() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-api.php';
			if ( ! Remember_Xero_OAuth::is_connected() ) {
				return __( 'Xero is not connected, so no invoices are created.', 'remember' );
			}
			$terms = Remember_Xero_API::get_organisation_sales_terms();
			if ( is_wp_error( $terms ) ) {
				return sprintf(
					/* translators: %s: error message */
					__( 'Could not read the payment terms from Xero (%s). Left blank, invoices follow the contact\'s or organisation\'s payment terms in Xero, or are due 30 days after the invoice date if Xero has none.', 'remember' ),
					$terms->get_error_message()
				);
			}
			if ( ! empty( $terms ) ) {
				return sprintf(
					/* translators: %s: payment terms, e.g. "14 days after the invoice date" */
					__( 'Left blank, Xero\'s default payment terms apply: due %s. A contact\'s own payment terms in Xero take priority.', 'remember' ),
					Remember_Xero_API::describe_sales_terms( $terms )
				);
			}
			return __( 'Left blank, invoices are due 30 days after the invoice date, because Xero has no default payment terms set up. A contact\'s own payment terms in Xero take priority.', 'remember' );
		}

		if ( Remember_Billing_Provider::is_quickbooks() ) {
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-api.php';
			$settings = Remember_QuickBooks_OAuth::get_settings();
			if ( ! $settings || empty( $settings['access_token'] ) || empty( $settings['realm_id'] ) ) {
				return __( 'QuickBooks is not connected, so no invoices are created.', 'remember' );
			}
			$terms = Remember_QuickBooks_API::get_default_terms();
			if ( is_wp_error( $terms ) ) {
				return sprintf(
					/* translators: %s: error message */
					__( 'Could not read the default terms from QuickBooks (%s). Left blank, invoices follow the customer\'s or company\'s terms in QuickBooks.', 'remember' ),
					$terms->get_error_message()
				);
			}
			if ( ! empty( $terms ) ) {
				return sprintf(
					/* translators: %s: terms, e.g. "Net 30 (due 30 days after the invoice date)" */
					__( 'Left blank, QuickBooks\' default terms apply: %s. A customer\'s own terms in QuickBooks take priority.', 'remember' ),
					Remember_QuickBooks_API::describe_terms( $terms )
				);
			}
			return __( 'Left blank, invoices follow the customer\'s terms in QuickBooks. The company has no default terms set up.', 'remember' );
		}

		return __( 'No billing provider is set up, so no invoices are created.', 'remember' );
	}

	/**
	 * Unpaid invoices for an event under a provider.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $provider xero|quickbooks.
	 * @return array<int,string> Invoice ID keyed by payment ID.
	 */
	public static function unpaid_invoices( $event_id, $provider ) {
		global $wpdb;
		$column = 'xero' === $provider ? 'xero_invoice_id' : 'quickbooks_invoice_id';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column chosen from two literals above.
				"SELECT p.payment_id, p.{$column} AS invoice_id
				FROM {$wpdb->prefix}remember_payments p
				INNER JOIN {$wpdb->prefix}remember_event_applications a ON a.application_id = p.event_application_id
				WHERE a.event_id = %d
				AND p.{$column} IS NOT NULL AND p.{$column} != ''
				AND p.payment_status IN ('pending', 'partial')
				AND p.amount_due > 0.001
				ORDER BY p.payment_id ASC",
				absint( $event_id )
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->payment_id ] = (string) $row->invoice_id;
		}
		return $out;
	}

	/**
	 * Option name for an event's job.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private static function job_option( $event_id ) {
		return self::JOB_OPTION_PREFIX . absint( $event_id );
	}

	/**
	 * Current or last job for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array|null
	 */
	public static function job_status( $event_id ) {
		$job = get_option( self::job_option( $event_id ), null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Staff-facing sentence for an event's current or last job. Empty when there is none.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	public static function describe_job( $event_id ) {
		$job = self::job_status( $event_id );
		if ( ! $job ) {
			return '';
		}
		$total = (int) $job['total'];
		if ( empty( $job['finished_at'] ) ) {
			$done = $total - count( $job['pending'] );
			return sprintf(
				/* translators: 1: invoices checked so far, 2: invoices in total, 3: invoices updated, 4: emails sent */
				__( 'Updating unpaid invoices to the new due date: %1$d of %2$d checked, %3$d updated, %4$d emailed.', 'remember' ),
				$done,
				$total,
				(int) $job['updated'],
				(int) $job['emailed']
			);
		}
		$text = sprintf(
			/* translators: 1: date and time, 2: invoices updated, 3: emails sent, 4: invoices already correct, 5: invoices already settled or voided */
			__( 'Last due-date update (%1$s): %2$d invoices updated, %3$d emailed, %4$d already had the right date, %5$d were already paid or voided.', 'remember' ),
			mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $job['finished_at'] ),
			(int) $job['updated'],
			(int) $job['emailed'],
			(int) $job['unchanged'],
			(int) $job['skipped']
		);
		if ( (int) $job['failed'] > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: 1: number of failures, 2: last error message */
				_n( '%1$d failed; see the log. Last error: %2$s', '%1$d failed; see the log. Last error: %2$s', (int) $job['failed'], 'remember' ),
				(int) $job['failed'],
				(string) $job['last_error']
			);
		}
		return $text;
	}

	/**
	 * Queue re-dating of the event's unpaid invoices. Replaces any job still running for it.
	 *
	 * @param int $event_id Event ID.
	 * @return int Invoices queued.
	 */
	public static function start_job( $event_id ) {
		$event_id = absint( $event_id );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-provider.php';
		$provider = Remember_Billing_Provider::get();
		if ( ! in_array( $provider, array( 'xero', 'quickbooks' ), true ) ) {
			Remember_Logger::debug( 'Invoice due-date update not queued: no billing provider', array( 'event_id' => $event_id ) );
			return 0;
		}

		// Invoices a replaced job already re-dated still need their email.
		$previous   = self::job_status( $event_id );
		$owed_email = array();
		if ( $previous && empty( $previous['finished_at'] ) && $previous['provider'] === $provider && ! empty( $previous['email_pending'] ) ) {
			$owed_email = (array) $previous['email_pending'];
			Remember_Logger::info(
				'Invoice due-date update replaced a running one; its owed emails carry over',
				array(
					'event_id'   => $event_id,
					'owed_email' => count( $owed_email ),
				)
			);
		}

		$invoices = self::unpaid_invoices( $event_id, $provider );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $event_id ) );
		if ( empty( $invoices ) && empty( $owed_email ) ) {
			delete_option( self::job_option( $event_id ) );
			Remember_Logger::info( 'Invoice due-date update not needed: no unpaid invoices', array( 'event_id' => $event_id, 'provider' => $provider ) );
			return 0;
		}

		$job = array(
			'provider'      => $provider,
			'pending'       => array_keys( $invoices ),
			'email_pending' => $owed_email,
			'total'         => count( $invoices ),
			'updated'       => 0,
			'emailed'       => 0,
			'unchanged'     => 0,
			'skipped'       => 0,
			'failed'        => 0,
			'failed_runs'   => 0,
			'last_error'    => '',
			'started_at'    => current_time( 'mysql' ),
			'started_by'    => get_current_user_id(),
			'finished_at'   => '',
		);
		update_option( self::job_option( $event_id ), $job, false );
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $event_id ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		Remember_Logger::info(
			'Invoice due-date update queued',
			array(
				'event_id' => $event_id,
				'provider' => $provider,
				'invoices' => count( $invoices ),
				'user_id'  => get_current_user_id(),
			)
		);
		return count( $invoices );
	}

	/**
	 * Run one chunk of an event's job, then schedule the next if any remain.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	public static function run_job( $event_id ) {
		$event_id = absint( $event_id );
		$job      = self::job_status( $event_id );
		if ( ! $job || ! empty( $job['finished_at'] ) ) {
			Remember_Logger::debug( 'Invoice due-date job has nothing to do', array( 'event_id' => $event_id ) );
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-billing-provider.php';
		$event = ( new Remember_Event() )->get( $event_id );
		if ( ! $event || ! self::has_setting( $event ) || Remember_Billing_Provider::get() !== $job['provider'] ) {
			$job['last_error']  = __( 'Stopped: the event, its due-date setting or the billing provider changed.', 'remember' );
			$job['failed']     += count( $job['email_pending'] );
			$job['finished_at'] = current_time( 'mysql' );
			update_option( self::job_option( $event_id ), $job, false );
			Remember_Logger::warning(
				'Invoice due-date job stopped: event, setting or provider changed',
				array(
					'event_id'      => $event_id,
					'not_checked'   => count( $job['pending'] ),
					'emails_unsent' => count( $job['email_pending'] ),
				)
			);
			return;
		}

		Remember_Logger::debug(
			'Invoice due-date job run started',
			array(
				'event_id'      => $event_id,
				'provider'      => $job['provider'],
				'pending'       => count( $job['pending'] ),
				'email_pending' => count( $job['email_pending'] ),
			)
		);

		$stop  = 'xero' === $job['provider'] ? self::run_xero_chunk( $event, $job ) : self::run_quickbooks_chunk( $event, $job );
		$delay = self::RUN_GAP;

		if ( is_wp_error( $stop ) ) {
			$job['last_error'] = $stop->get_error_message();
			$data              = $stop->get_error_data();
			if ( 'xero_rate_limited' === $stop->get_error_code() ) {
				$delay = max( self::RUN_GAP, ( is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : 0 ) + 5 );
				Remember_Logger::info(
					'Invoice due-date job paused for the Xero rate limit',
					array(
						'event_id'      => $event_id,
						'resume_in_sec' => $delay,
					)
				);
			} else {
				++$job['failed_runs'];
				Remember_Logger::warning(
					'Invoice due-date job run stopped early',
					array(
						'event_id'    => $event_id,
						'failed_runs' => $job['failed_runs'],
						'error'       => $job['last_error'],
					)
				);
			}
		} else {
			$job['failed_runs'] = 0;
		}

		$remaining = count( $job['pending'] ) + count( $job['email_pending'] );
		if ( 0 === $remaining || $job['failed_runs'] >= self::MAX_FAILED_RUNS ) {
			if ( $remaining > 0 ) {
				$job['failed']       += $remaining;
				$job['pending']       = array();
				$job['email_pending'] = array();
			}
			$job['finished_at'] = current_time( 'mysql' );
			update_option( self::job_option( $event_id ), $job, false );
			$summary = array(
				'event_id'  => $event_id,
				'provider'  => $job['provider'],
				'total'     => $job['total'],
				'updated'   => $job['updated'],
				'emailed'   => $job['emailed'],
				'unchanged' => $job['unchanged'],
				'skipped'   => $job['skipped'],
				'failed'    => $job['failed'],
			);
			if ( $job['failed'] > 0 ) {
				$summary['last_error'] = $job['last_error'];
				Remember_Logger::warning( 'Invoice due-date update finished with failures', $summary );
			} else {
				Remember_Logger::info( 'Invoice due-date update finished', $summary );
			}
			return;
		}

		update_option( self::job_option( $event_id ), $job, false );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $event_id ) );
		Remember_Logger::debug(
			'Invoice due-date job continues in the next run',
			array(
				'event_id'      => $event_id,
				'remaining'     => $remaining,
				'resume_in_sec' => $delay,
			)
		);
	}

	/**
	 * One Xero chunk. Updates $job in place.
	 *
	 * @param object $event Event row.
	 * @param array  $job   Job state.
	 * @return WP_Error|null Error that stopped the run early.
	 */
	private static function run_xero_chunk( $event, &$job ) {
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-api.php';
		$send          = array( 'Remember_Xero_API', 'email_invoice' );
		$is_rate_limit = array( 'Remember_Xero_API', 'is_rate_limit_error' );

		$stop = self::send_owed_emails( $job, $send, $is_rate_limit );
		if ( $stop ) {
			return $stop;
		}

		$room  = max( 0, self::XERO_CHUNK - count( $job['email_pending'] ) );
		$batch = array_slice( $job['pending'], 0, $room );
		if ( empty( $batch ) ) {
			return null;
		}
		$invoice_ids = self::invoice_ids_for( $batch, 'xero_invoice_id' );
		$invoices    = Remember_Xero_API::get_invoices_by_ids( array_values( $invoice_ids ) );
		if ( is_wp_error( $invoices ) ) {
			return $invoices;
		}

		$changes = array();
		$owner   = array();
		foreach ( $batch as $payment_id ) {
			$invoice_id = isset( $invoice_ids[ $payment_id ] ) ? $invoice_ids[ $payment_id ] : '';
			$key        = strtolower( $invoice_id );
			$invoice    = '' !== $key && isset( $invoices[ $key ] ) ? $invoices[ $key ] : null;
			$state      = $invoice && isset( $invoice['Status'] ) ? strtoupper( (string) $invoice['Status'] ) : '';
			$amount_due = $invoice && isset( $invoice['AmountDue'] ) ? (float) $invoice['AmountDue'] : 0.0;
			if ( ! $invoice || ! in_array( $state, array( 'AUTHORISED', 'SUBMITTED', 'DRAFT' ), true ) || $amount_due <= 0.001 ) {
				++$job['skipped'];
				Remember_Logger::debug(
					'Invoice due-date update skipped an invoice that is gone, settled or voided in Xero',
					array(
						'payment_id' => $payment_id,
						'status'     => $state,
						'amount_due' => $amount_due,
					)
				);
				continue;
			}
			$new_due = self::due_date_for( $event, Remember_Xero_API::normalize_xero_date( isset( $invoice['Date'] ) ? $invoice['Date'] : '' ) );
			$old_due = Remember_Xero_API::normalize_xero_date( isset( $invoice['DueDate'] ) ? $invoice['DueDate'] : '' );
			if ( $new_due === $old_due ) {
				++$job['unchanged'];
				continue;
			}
			$changes[ $invoice_id ] = $new_due;
			$owner[ $key ]          = array(
				'payment_id' => $payment_id,
				'invoice_id' => $invoice_id,
				'state'      => $state,
				'old'        => $old_due,
				'new'        => $new_due,
			);
		}

		$results = empty( $changes ) ? array() : Remember_Xero_API::update_invoice_due_dates( $changes );
		$retry   = array();
		$stop    = null;
		foreach ( $owner as $key => $info ) {
			$result = isset( $results[ $key ] ) ? $results[ $key ] : new WP_Error( 'xero_due_update_missing', __( 'Xero did not report on this invoice.', 'remember' ) );
			if ( Remember_Xero_API::is_rate_limit_error( $result ) ) {
				$retry[] = $info['payment_id'];
				$stop    = $result;
				continue;
			}
			if ( is_wp_error( $result ) ) {
				++$job['failed'];
				$job['last_error'] = $result->get_error_message();
				Remember_Logger::warning(
					'Xero refused the new due date for an invoice',
					array(
						'payment_id' => $info['payment_id'],
						'invoice_id' => $info['invoice_id'],
						'new_due'    => $info['new'],
						'error'      => $result->get_error_message(),
					)
				);
				continue;
			}
			++$job['updated'];
			Remember_Logger::info(
				'Invoice due date updated in Xero',
				array(
					'event_id'   => (int) $event->event_id,
					'payment_id' => $info['payment_id'],
					'old_due'    => $info['old'],
					'new_due'    => $info['new'],
				)
			);
			if ( 'AUTHORISED' === $info['state'] ) {
				$job['email_pending'][ $info['payment_id'] ] = $info['invoice_id'];
			} else {
				Remember_Logger::debug(
					'Updated invoice not emailed: it is not approved in Xero',
					array(
						'payment_id' => $info['payment_id'],
						'status'     => $info['state'],
					)
				);
			}
		}
		$job['pending'] = array_values( array_diff( $job['pending'], array_diff( $batch, $retry ) ) );

		if ( $stop ) {
			return $stop;
		}
		return self::send_owed_emails( $job, $send, $is_rate_limit );
	}

	/**
	 * One QuickBooks chunk. Updates $job in place.
	 *
	 * @param object $event Event row.
	 * @param array  $job   Job state.
	 * @return WP_Error|null Error that stopped the run early.
	 */
	private static function run_quickbooks_chunk( $event, &$job ) {
		require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-api.php';
		$send          = array( 'Remember_QuickBooks_API', 'email_invoice' );
		$is_rate_limit = static function ( $error ) {
			$data = is_wp_error( $error ) ? $error->get_error_data() : null;
			return is_array( $data ) && isset( $data['status'] ) && 429 === (int) $data['status'];
		};

		$stop = self::send_owed_emails( $job, $send, $is_rate_limit );
		if ( $stop ) {
			return $stop;
		}

		$batch = array_slice( $job['pending'], 0, self::QB_CHUNK );
		if ( empty( $batch ) ) {
			return null;
		}
		$invoice_ids = self::invoice_ids_for( $batch, 'quickbooks_invoice_id' );
		$invoices    = Remember_QuickBooks_API::get_invoices_by_ids( array_values( $invoice_ids ) );
		if ( is_wp_error( $invoices ) ) {
			return $invoices;
		}

		$changes = array();
		$owner   = array();
		foreach ( $batch as $payment_id ) {
			$invoice_id = isset( $invoice_ids[ $payment_id ] ) ? $invoice_ids[ $payment_id ] : '';
			$invoice    = '' !== $invoice_id && isset( $invoices[ $invoice_id ] ) ? $invoices[ $invoice_id ] : null;
			$balance    = $invoice && isset( $invoice['Balance'] ) ? (float) $invoice['Balance'] : 0.0;
			if ( ! $invoice || Remember_QuickBooks_API::is_invoice_voided( $invoice ) || $balance <= 0.001 ) {
				++$job['skipped'];
				Remember_Logger::debug(
					'Invoice due-date update skipped an invoice that is gone, settled or voided in QuickBooks',
					array(
						'payment_id' => $payment_id,
						'balance'    => $balance,
					)
				);
				continue;
			}
			$new_due = self::due_date_for( $event, isset( $invoice['TxnDate'] ) ? (string) $invoice['TxnDate'] : '' );
			$old_due = isset( $invoice['DueDate'] ) ? substr( (string) $invoice['DueDate'], 0, 10 ) : '';
			if ( $new_due === $old_due ) {
				++$job['unchanged'];
				continue;
			}
			$changes[ $invoice_id ] = array(
				'sync_token' => isset( $invoice['SyncToken'] ) ? (string) $invoice['SyncToken'] : '0',
				'due_date'   => $new_due,
			);
			$owner[ $invoice_id ]   = array(
				'payment_id' => $payment_id,
				'old'        => $old_due,
				'new'        => $new_due,
			);
		}

		$results = empty( $changes ) ? array() : Remember_QuickBooks_API::update_invoice_due_dates( $changes );
		$retry   = array();
		$stop    = null;
		foreach ( $owner as $invoice_id => $info ) {
			$result = isset( $results[ $invoice_id ] ) ? $results[ $invoice_id ] : new WP_Error( 'qb_due_update_missing', __( 'QuickBooks did not report on this invoice.', 'remember' ) );
			if ( call_user_func( $is_rate_limit, $result ) ) {
				$retry[] = $info['payment_id'];
				$stop    = $result;
				continue;
			}
			if ( is_wp_error( $result ) ) {
				++$job['failed'];
				$job['last_error'] = $result->get_error_message();
				Remember_Logger::warning(
					'QuickBooks refused the new due date for an invoice',
					array(
						'payment_id' => $info['payment_id'],
						'invoice_id' => $invoice_id,
						'new_due'    => $info['new'],
						'error'      => $result->get_error_message(),
					)
				);
				continue;
			}
			++$job['updated'];
			Remember_Logger::info(
				'Invoice due date updated in QuickBooks',
				array(
					'event_id'   => (int) $event->event_id,
					'payment_id' => $info['payment_id'],
					'old_due'    => $info['old'],
					'new_due'    => $info['new'],
				)
			);
			$job['email_pending'][ $info['payment_id'] ] = (string) $invoice_id;
		}
		$job['pending'] = array_values( array_diff( $job['pending'], array_diff( $batch, $retry ) ) );

		if ( $stop ) {
			return $stop;
		}
		return self::send_owed_emails( $job, $send, $is_rate_limit );
	}

	/**
	 * Email updated invoices still owed an email. Updates $job in place.
	 *
	 * @param array    $job           Job state.
	 * @param callable $send          Sends one invoice email by provider invoice ID.
	 * @param callable $is_rate_limit Whether an error is a rate limit.
	 * @return WP_Error|null Rate-limit error that stopped sending.
	 */
	private static function send_owed_emails( &$job, $send, $is_rate_limit ) {
		foreach ( $job['email_pending'] as $payment_id => $invoice_id ) {
			$result = call_user_func( $send, $invoice_id );
			if ( is_wp_error( $result ) && call_user_func( $is_rate_limit, $result ) ) {
				return $result;
			}
			unset( $job['email_pending'][ $payment_id ] );
			if ( is_wp_error( $result ) ) {
				++$job['failed'];
				$job['last_error'] = $result->get_error_message();
				Remember_Logger::warning(
					'Updated invoice could not be emailed to the member',
					array(
						'payment_id' => $payment_id,
						'invoice_id' => $invoice_id,
						'error'      => $result->get_error_message(),
					)
				);
				continue;
			}
			++$job['emailed'];
			Remember_Logger::debug(
				'Updated invoice emailed to the member',
				array(
					'payment_id' => $payment_id,
					'invoice_id' => $invoice_id,
				)
			);
		}
		return null;
	}

	/**
	 * Provider invoice IDs for payment rows.
	 *
	 * @param int[]  $payment_ids Payment IDs.
	 * @param string $column      xero_invoice_id or quickbooks_invoice_id.
	 * @return array<int,string> Invoice ID keyed by payment ID.
	 */
	private static function invoice_ids_for( $payment_ids, $column ) {
		global $wpdb;
		$payment_ids = array_values( array_filter( array_map( 'absint', (array) $payment_ids ) ) );
		if ( empty( $payment_ids ) || ! in_array( $column, array( 'xero_invoice_id', 'quickbooks_invoice_id' ), true ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $payment_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column is whitelisted; placeholders built from count.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT payment_id, {$column} AS invoice_id FROM {$wpdb->prefix}remember_payments WHERE payment_id IN ({$placeholders})", $payment_ids ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( '' !== (string) $row->invoice_id ) {
				$out[ (int) $row->payment_id ] = (string) $row->invoice_id;
			}
		}
		return $out;
	}
}
