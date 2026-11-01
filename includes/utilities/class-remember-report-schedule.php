<?php
/**
 * Email a saved report on a schedule.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * One schedule per saved report. The hourly cron sends due reports as the owner.
 */
class Remember_Report_Schedule {

	const CRON_HOOK = 'remember_send_scheduled_reports';

	/**
	 * Register the hourly sender.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the sender once.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'remember_report_schedules';
	}

	/**
	 * Stored schedule, or the off defaults.
	 *
	 * @param int $report_id Report ID.
	 * @return array
	 */
	public static function for_report( $report_id ) {
		$row = self::row_for_report( $report_id );
		if ( ! $row ) {
			return self::defaults();
		}
		return self::to_array( $row );
	}

	/**
	 * Save a schedule for a report the current user owns.
	 *
	 * @param object $report Saved report row.
	 * @param array  $input  Request fields.
	 * @return array|\WP_Error
	 */
	public static function save_for_report( $report, $input ) {
		global $wpdb;
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		$definition = self::definition_of( $report );
		$clean      = self::sanitize_input( $input, $report->subject, $definition );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'enabled'           => $clean['enabled'],
			'frequency'         => $clean['frequency'],
			'weekday'           => $clean['weekday'],
			'monthday'          => $clean['monthday'],
			'send_time'         => $clean['send_time'],
			'event_id'          => $clean['event_id'],
			'skip_empty'        => $clean['skip_empty'],
			'sensitive_opt_in'  => $clean['sensitive_opt_in'],
			'recipient_ids'     => wp_json_encode( $clean['recipient_ids'] ),
			'updated_at'        => $now,
		);
		$formats = array( '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' );
		$existing = self::row_for_report( $report->report_id );
		if ( $existing ) {
			$updated = $wpdb->update(
				self::table_name(),
				$data,
				array( 'report_id' => (int) $report->report_id ),
				$formats,
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'save', __( 'Could not save that schedule.', 'remember' ) );
			}
		} else {
			$data['report_id']  = (int) $report->report_id;
			$data['created_at'] = $now;
			$inserted           = $wpdb->insert(
				self::table_name(),
				$data,
				array( '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
			);
			if ( ! $inserted ) {
				return new WP_Error( 'save', __( 'Could not save that schedule.', 'remember' ) );
			}
		}
		Remember_Logger::info( 'Report schedule saved', array( 'report_id' => (int) $report->report_id, 'enabled' => (int) $clean['enabled'] ) );
		return self::for_report( $report->report_id );
	}

	/**
	 * Remove a schedule when its report is deleted.
	 *
	 * @param int $report_id Report ID.
	 * @return void
	 */
	public static function delete_for_report( $report_id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'report_id' => absint( $report_id ) ), array( '%d' ) );
	}

	/**
	 * Send every schedule whose slot has passed.
	 *
	 * @return void
	 */
	public static function dispatch() {
		global $wpdb;
		self::delete_stale_files();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		$table = self::table_name();
		$reports = Remember_Saved_Report::table_name();
		$rows  = $wpdb->get_results(
			"SELECT s.*, r.owner_id, r.name, r.subject, r.definition
			FROM {$table} s
			INNER JOIN {$reports} r ON r.report_id = s.report_id
			WHERE s.enabled = 1"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return;
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		foreach ( $rows as $row ) {
			if ( ! self::is_due( $row, $now ) ) {
				continue;
			}
			self::deliver( $row, $now );
		}
	}

	/**
	 * Whether the latest slot is after the last send.
	 *
	 * @param object             $row Schedule row.
	 * @param DateTimeImmutable $now Now in the site timezone.
	 * @return bool
	 */
	public static function is_due( $row, $now = null ) {
		if ( empty( $row->enabled ) ) {
			return false;
		}
		if ( ! $now instanceof DateTimeImmutable ) {
			$now = new DateTimeImmutable( 'now', wp_timezone() );
		}
		$slot = self::latest_slot( $row, $now );
		if ( $slot > $now ) {
			return false;
		}
		if ( empty( $row->last_sent_at ) ) {
			return true;
		}
		$sent = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row->last_sent_at, wp_timezone() );
		if ( ! $sent ) {
			return true;
		}
		return $sent < $slot;
	}

	/**
	 * Build and mail one due report.
	 *
	 * @param object             $row Schedule joined to its report.
	 * @param DateTimeImmutable $now Now.
	 * @return void
	 */
	private static function deliver( $row, $now ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-engine.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';

		$owner = get_userdata( (int) $row->owner_id );
		if ( ! $owner ) {
			Remember_Logger::warning( 'Scheduled report owner is missing', array( 'report_id' => (int) $row->report_id ) );
			self::mark_sent( $row->report_id );
			return;
		}

		$definition = json_decode( (string) $row->definition, true );
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		$definition['subject'] = (string) $row->subject;
		$topics = Remember_Report_Catalog::sensitive_topics( $row->subject, $definition );
		if ( $topics && empty( $row->sensitive_opt_in ) ) {
			Remember_Logger::warning(
				'Scheduled report was not sent because health or emergency fields were not confirmed',
				array( 'report_id' => (int) $row->report_id )
			);
			self::mark_sent( $row->report_id );
			return;
		}

		$previous = get_current_user_id();
		wp_set_current_user( (int) $row->owner_id );
		$result = Remember_Report_Engine::run( $definition, 1, Remember_Report_Engine::PAGE_SIZE, true, (int) $row->event_id );
		if ( ! is_wp_error( $result ) ) {
			$what = Remember_Access_Log::what_from_topics( $topics );
			if ( '' !== $what ) {
				Remember_Access_Log::record(
					0,
					$what,
					sprintf(
						/* translators: %s: report name */
						__( 'Scheduled report: %s', 'remember' ),
						(string) $row->name
					),
					count( $result['rows'] )
				);
			}
		}
		wp_set_current_user( $previous );

		if ( is_wp_error( $result ) ) {
			Remember_Logger::warning(
				'Scheduled report did not run',
				array(
					'report_id' => (int) $row->report_id,
					'code'      => $result->get_error_code(),
				)
			);
			return;
		}

		if ( ! empty( $row->skip_empty ) && (int) $result['total'] < 1 ) {
			Remember_Logger::info( 'Scheduled report skipped with no rows', array( 'report_id' => (int) $row->report_id ) );
			self::mark_sent( $row->report_id );
			return;
		}

		$path = self::write_csv( $result, (int) $row->report_id );
		if ( ! $path ) {
			Remember_Logger::error( 'Scheduled report CSV was not written', array( 'report_id' => (int) $row->report_id ) );
			return;
		}

		$sent     = 0;
		$eligible = 0;
		$ids      = self::recipient_ids_from_json( $row->recipient_ids );
		try {
			foreach ( $ids as $user_id ) {
				if ( ! Remember_Report_Catalog::user_can_receive_report( $user_id, $row->subject, $definition ) ) {
					continue;
				}
				$user = get_userdata( $user_id );
				if ( ! $user || ! is_email( $user->user_email ) ) {
					continue;
				}
				++$eligible;
				$ok = wp_mail(
					$user->user_email,
					sprintf(
						/* translators: %s: report name */
						__( 'Report: %s', 'remember' ),
						(string) $row->name
					),
					self::message( $row, $result ),
					array( 'Content-Type: text/plain; charset=UTF-8' ),
					array( $path )
				);
				if ( $ok ) {
					++$sent;
				}
			}
		} finally {
			wp_delete_file( $path );
		}

		if ( $eligible > 0 && $sent < 1 ) {
			Remember_Logger::error( 'Scheduled report email was not accepted', array( 'report_id' => (int) $row->report_id ) );
			return;
		}
		if ( $sent < 1 ) {
			Remember_Logger::warning( 'Scheduled report had no recipient who can still run it', array( 'report_id' => (int) $row->report_id ) );
		} else {
			Remember_Logger::info(
				'Scheduled report emailed',
				array(
					'report_id'  => (int) $row->report_id,
					'recipients' => $sent,
					'rows'       => (int) $result['total'],
				)
			);
		}
		self::mark_sent( $row->report_id );
	}

	/**
	 * Plain-text body. The rows themselves stay in the attachment.
	 *
	 * @param object $row    Schedule row.
	 * @param array  $result Engine result.
	 * @return string
	 */
	private static function message( $row, $result ) {
		$lines = array(
			(string) $row->name,
			'',
			sprintf(
				/* translators: %d: row count */
				_n( '%d row.', '%d rows.', (int) $result['total'], 'remember' ),
				(int) $result['total']
			),
		);
		if ( ! empty( $result['event_label'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: event name */
				__( 'Limited to %s.', 'remember' ),
				(string) $result['event_label']
			);
		}
		$lines[] = '';
		$lines[] = __( 'Open Reports in reMember:', 'remember' );
		$lines[] = admin_url( 'admin.php?page=remember-reports' );
		return implode( "\n", $lines );
	}

	/**
	 * Write the export outside the web root.
	 *
	 * @param array $result    Engine result.
	 * @param int   $report_id Report ID.
	 * @return string|\false
	 */
	private static function write_csv( $result, $report_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp = wp_tempnam( 'remember-report-' . absint( $report_id ) );
		if ( ! $temp ) {
			return false;
		}
		$path = $temp . '.csv';
		wp_delete_file( $temp );
		$out = fopen( $path, 'x' );
		if ( ! $out ) {
			return false;
		}
		if ( ! chmod( $path, 0600 ) ) {
			fclose( $out );
			wp_delete_file( $path );
			Remember_Logger::error( 'Scheduled report CSV could not be made owner-only, so it was not sent', array( 'report_id' => absint( $report_id ) ) );
			return false;
		}
		$written = false;
		try {
			fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
			$headers = array();
			foreach ( $result['columns'] as $col ) {
				$headers[] = Remember_Report_Engine::csv_cell( $col['label'] );
			}
			fputcsv( $out, $headers );
			foreach ( $result['rows'] as $line ) {
				$cells = array();
				foreach ( $result['columns'] as $col ) {
					$id      = $col['id'];
					$cells[] = Remember_Report_Engine::csv_cell( isset( $line[ $id ] ) ? $line[ $id ] : '' );
				}
				fputcsv( $out, $cells );
			}
			$written = true;
		} finally {
			fclose( $out );
			if ( ! $written ) {
				wp_delete_file( $path );
			}
		}
		return $path;
	}

	/**
	 * Delete report CSVs a killed or crashed send left in the temp folder.
	 *
	 * @return void
	 */
	public static function delete_stale_files() {
		$files = glob( trailingslashit( get_temp_dir() ) . 'remember-report-*.csv' );
		if ( ! is_array( $files ) || ! $files ) {
			return;
		}
		$cutoff  = time() - DAY_IN_SECONDS;
		$removed = 0;
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) || is_link( $file ) ) {
				continue;
			}
			$modified = filemtime( $file );
			if ( false === $modified || $modified >= $cutoff ) {
				continue;
			}
			wp_delete_file( $file );
			if ( file_exists( $file ) ) {
				Remember_Logger::warning( 'A leftover scheduled report file could not be deleted', array( 'file' => basename( $file ) ) );
			} else {
				++$removed;
			}
		}
		if ( $removed > 0 ) {
			Remember_Logger::info( 'Deleted leftover scheduled report files', array( 'count' => $removed ) );
		}
	}

	/**
	 * Record that this slot was handled.
	 *
	 * @param int $report_id Report ID.
	 * @return void
	 */
	private static function mark_sent( $report_id ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'last_sent_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'report_id' => absint( $report_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Most recent scheduled instant at or before now.
	 *
	 * @param object             $row Schedule row.
	 * @param DateTimeImmutable $now Now.
	 * @return DateTimeImmutable
	 */
	private static function latest_slot( $row, DateTimeImmutable $now ) {
		$parts = self::time_parts( isset( $row->send_time ) ? $row->send_time : '08:00' );
		$clock = $now->setTime( $parts[0], $parts[1], 0 );
		if ( 'daily' === (string) $row->frequency ) {
			return ( $clock > $now ) ? $clock->modify( '-1 day' ) : $clock;
		}
		if ( 'monthly' === (string) $row->frequency ) {
			$day  = min( 31, max( 1, (int) $row->monthday ) );
			$slot = self::month_slot( $now, $day, $parts );
			if ( $slot > $now ) {
				$slot = self::month_slot( $now->modify( 'first day of last month' ), $day, $parts );
			}
			return $slot;
		}
		$want  = min( 7, max( 1, (int) $row->weekday ) );
		$delta = (int) $now->format( 'N' ) - $want;
		if ( $delta < 0 ) {
			$delta += 7;
		}
		$slot = $clock->modify( '-' . $delta . ' days' );
		if ( $slot > $now ) {
			$slot = $slot->modify( '-7 days' );
		}
		return $slot;
	}

	/**
	 * A clock time on a day of one month, clamped to that month's length.
	 *
	 * @param DateTimeImmutable $basis Any instant in the month.
	 * @param int                $day   1-31.
	 * @param int[]              $parts Hour and minute.
	 * @return DateTimeImmutable
	 */
	private static function month_slot( DateTimeImmutable $basis, $day, $parts ) {
		$last = (int) $basis->format( 't' );
		$use  = min( (int) $day, $last );
		return $basis->setDate( (int) $basis->format( 'Y' ), (int) $basis->format( 'n' ), $use )->setTime( $parts[0], $parts[1], 0 );
	}

	/**
	 * Validate a schedule before it is stored.
	 *
	 * @param array  $input      Request.
	 * @param string $subject    Report subject.
	 * @param array  $definition Definition.
	 * @return array|\WP_Error
	 */
	private static function sanitize_input( $input, $subject, $definition ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$frequency = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : 'weekly';
		if ( ! in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$frequency = 'weekly';
		}
		$enabled = ! empty( $input['enabled'] ) ? 1 : 0;
		$ids     = array();
		$raw_ids = isset( $input['recipient_ids'] ) ? $input['recipient_ids'] : array();
		if ( ! is_array( $raw_ids ) ) {
			$raw_ids = array( $raw_ids );
		}
		foreach ( $raw_ids as $id ) {
			$id = absint( $id );
			if ( $id < 1 || ! Remember_Report_Catalog::user_can_receive_report( $id, $subject, $definition ) ) {
				continue;
			}
			$ids[] = $id;
		}
		$ids = array_values( array_unique( $ids ) );
		if ( $enabled && ! $ids ) {
			return new WP_Error( 'recipients', __( 'Choose at least one person who can run this report.', 'remember' ) );
		}
		$topics = Remember_Report_Catalog::sensitive_topics( $subject, $definition );
		$opt_in = ! empty( $input['sensitive_opt_in'] ) ? 1 : 0;
		if ( $enabled && $topics && ! $opt_in ) {
			return new WP_Error( 'sensitive', __( 'Confirm that this email may include health or emergency contact fields.', 'remember' ) );
		}
		$event_id = Remember_Report_Catalog::sanitize_event_id( isset( $input['event_id'] ) ? $input['event_id'] : 0 );
		return array(
			'enabled'          => $enabled,
			'frequency'        => $frequency,
			'weekday'          => min( 7, max( 1, isset( $input['weekday'] ) ? absint( $input['weekday'] ) : 1 ) ),
			'monthday'         => min( 31, max( 1, isset( $input['monthday'] ) ? absint( $input['monthday'] ) : 1 ) ),
			'send_time'        => self::valid_time( isset( $input['send_time'] ) ? $input['send_time'] : '08:00' ),
			'event_id'         => $event_id,
			'skip_empty'       => ! empty( $input['skip_empty'] ) ? 1 : 0,
			'sensitive_opt_in' => $opt_in,
			'recipient_ids'    => $ids,
		);
	}

	/**
	 * HH:MM in the site clock, or 08:00.
	 *
	 * @param mixed $value Raw time.
	 * @return string
	 */
	private static function valid_time( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return $value;
		}
		return '08:00';
	}

	/**
	 * Hour and minute.
	 *
	 * @param string $value HH:MM.
	 * @return int[]
	 */
	private static function time_parts( $value ) {
		$value = self::valid_time( $value );
		$bits  = explode( ':', $value );
		return array( (int) $bits[0], (int) $bits[1] );
	}

	/**
	 * @param object $report Saved report.
	 * @return array
	 */
	private static function definition_of( $report ) {
		$definition = json_decode( (string) $report->definition, true );
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		$definition['subject'] = (string) $report->subject;
		return $definition;
	}

	/**
	 * @param int $report_id Report ID.
	 * @return object|null
	 */
	private static function row_for_report( $report_id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE report_id = %d",
				absint( $report_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * @return array
	 */
	private static function defaults() {
		return array(
			'enabled'          => 0,
			'frequency'        => 'weekly',
			'weekday'          => 1,
			'monthday'         => 1,
			'send_time'        => '08:00',
			'event_id'         => 0,
			'skip_empty'       => 1,
			'sensitive_opt_in' => 0,
			'recipient_ids'    => array(),
			'last_sent_at'     => '',
		);
	}

	/**
	 * @param object $row Schedule row.
	 * @return array
	 */
	private static function to_array( $row ) {
		$when = '';
		if ( ! empty( $row->last_sent_at ) ) {
			$ts = strtotime( (string) $row->last_sent_at );
			if ( $ts ) {
				$when = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
			}
		}
		return array(
			'enabled'          => (int) $row->enabled,
			'frequency'        => (string) $row->frequency,
			'weekday'          => (int) $row->weekday,
			'monthday'         => (int) $row->monthday,
			'send_time'        => self::valid_time( $row->send_time ),
			'event_id'         => (int) $row->event_id,
			'skip_empty'       => (int) $row->skip_empty,
			'sensitive_opt_in' => (int) $row->sensitive_opt_in,
			'recipient_ids'    => self::recipient_ids_from_json( $row->recipient_ids ),
			'last_sent_at'     => $when,
		);
	}

	/**
	 * @param string $json Stored ids.
	 * @return int[]
	 */
	private static function recipient_ids_from_json( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$ids = array();
		foreach ( $decoded as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
