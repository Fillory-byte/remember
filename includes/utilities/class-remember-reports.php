<?php
/**
 * Reports AJAX, export, and hooks.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Staff reporting endpoints.
 */
class Remember_Reports {

	const CAP = 'remember_view_reports';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_remember_report_catalog', array( __CLASS__, 'ajax_catalog' ) );
		add_action( 'wp_ajax_remember_report_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_remember_report_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_remember_report_get', array( __CLASS__, 'ajax_get' ) );
		add_action( 'wp_ajax_remember_report_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_remember_report_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_remember_report_recipients', array( __CLASS__, 'ajax_recipients' ) );
		add_action( 'wp_ajax_remember_report_copy', array( __CLASS__, 'ajax_copy' ) );
		add_action( 'wp_ajax_remember_report_schedule_get', array( __CLASS__, 'ajax_schedule_get' ) );
		add_action( 'wp_ajax_remember_report_schedule_save', array( __CLASS__, 'ajax_schedule_save' ) );
		add_action( 'admin_post_remember_report_export', array( __CLASS__, 'handle_export' ) );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-schedule.php';
		Remember_Report_Schedule::init();
	}

	/**
	 * Guard AJAX.
	 *
	 * @return void
	 */
	private static function require_ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot view reports.', 'remember' ) ), 403 );
		}
		check_ajax_referer( 'remember_reports', 'nonce' );
	}

	/**
	 * Catalog JSON.
	 *
	 * @return void
	 */
	public static function ajax_catalog() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		wp_send_json_success( Remember_Report_Catalog::payload_for_current_user() );
	}

	/**
	 * Run preview.
	 *
	 * @return void
	 */
	public static function ajax_run() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-engine.php';
		$definition = self::definition_from_request();
		$page       = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$result   = Remember_Report_Engine::run( $definition, $page, Remember_Report_Engine::PAGE_SIZE, false, $event_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		self::log_sensitive_report( $definition, __( 'Report preview', 'remember' ), count( $result['rows'] ) );
		wp_send_json_success( $result );
	}

	/**
	 * List saved reports.
	 *
	 * @return void
	 */
	public static function ajax_list() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		$rows = Remember_Saved_Report::list_for_owner( get_current_user_id() );
		$list = array();
		foreach ( $rows as $row ) {
			$list[] = array(
				'report_id'  => (int) $row->report_id,
				'name'       => (string) $row->name,
				'subject'    => (string) $row->subject,
				'updated_at' => (string) $row->updated_at,
			);
		}
		wp_send_json_success( array( 'reports' => $list ) );
	}

	/**
	 * Load one saved report.
	 *
	 * @return void
	 */
	public static function ajax_get() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		$id  = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		$row = Remember_Saved_Report::get_owned( $id, get_current_user_id() );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'That report was not found.', 'remember' ) ), 404 );
		}
		$definition = json_decode( (string) $row->definition, true );
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		wp_send_json_success(
			array(
				'report_id'  => (int) $row->report_id,
				'name'       => (string) $row->name,
				'subject'    => (string) $row->subject,
				'definition' => $definition,
			)
		);
	}

	/**
	 * Save report.
	 *
	 * @return void
	 */
	public static function ajax_save() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-engine.php';
		$definition = self::definition_from_request();
		$logic      = Remember_Report_Engine::validate_filter_logic( $definition );
		if ( is_wp_error( $logic ) ) {
			wp_send_json_error( array( 'message' => $logic->get_error_message() ), 400 );
		}
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$subject    = isset( $definition['subject'] ) ? sanitize_key( $definition['subject'] ) : '';
		$report_id  = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		$result     = Remember_Saved_Report::save( get_current_user_id(), $name, $subject, $definition, $report_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'report_id' => (int) $result ) );
	}

	/**
	 * Delete report.
	 *
	 * @return void
	 */
	public static function ajax_delete() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		$id = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		if ( ! Remember_Saved_Report::delete_owned( $id, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete that report.', 'remember' ) ), 400 );
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-schedule.php';
		Remember_Report_Schedule::delete_for_report( $id );
		wp_send_json_success();
	}

	/**
	 * Schedule and the staff who can receive this saved report.
	 *
	 * @return void
	 */
	public static function ajax_schedule_get() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-schedule.php';
		$row = self::owned_from_request();
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Save this report before scheduling it.', 'remember' ) ), 404 );
		}
		$definition = self::definition_from_row( $row );
		wp_send_json_success(
			array(
				'schedule'   => Remember_Report_Schedule::for_report( $row->report_id ),
				'sensitive'  => ! empty( Remember_Report_Catalog::sensitive_topics( $row->subject, $definition ) ),
				'recipients' => Remember_Report_Catalog::recipients_for_report( $row->subject, $definition, 0 ),
				'timezone'   => wp_timezone_string(),
			)
		);
	}

	/**
	 * Save the email schedule for an owned report.
	 *
	 * @return void
	 */
	public static function ajax_schedule_save() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-schedule.php';
		$row = self::owned_from_request();
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Save this report before scheduling it.', 'remember' ) ), 404 );
		}
		$ids = array();
		if ( isset( $_POST['recipient_ids'] ) ) {
			$raw = wp_unslash( $_POST['recipient_ids'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_array( $raw ) ) {
				$raw = array( $raw );
			}
			foreach ( $raw as $id ) {
				$ids[] = absint( $id );
			}
		}
		$result = Remember_Report_Schedule::save_for_report(
			$row,
			array(
				'enabled'          => ! empty( $_POST['enabled'] ),
				'frequency'        => isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'weekly',
				'weekday'          => isset( $_POST['weekday'] ) ? absint( wp_unslash( $_POST['weekday'] ) ) : 1,
				'monthday'         => isset( $_POST['monthday'] ) ? absint( wp_unslash( $_POST['monthday'] ) ) : 1,
				'send_time'        => isset( $_POST['send_time'] ) ? sanitize_text_field( wp_unslash( $_POST['send_time'] ) ) : '08:00',
				'event_id'         => isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0,
				'skip_empty'       => ! empty( $_POST['skip_empty'] ),
				'sensitive_opt_in' => ! empty( $_POST['sensitive_opt_in'] ),
				'recipient_ids'    => $ids,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'schedule' => $result ) );
	}

	/**
	 * Staff who can receive a copy of the current saved report.
	 *
	 * @return void
	 */
	public static function ajax_recipients() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		$row = self::owned_from_request();
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Save this report before copying it.', 'remember' ) ), 404 );
		}
		$definition = self::definition_from_row( $row );
		wp_send_json_success(
			array(
				'recipients' => Remember_Report_Catalog::recipients_for_report( $row->subject, $definition, get_current_user_id() ),
			)
		);
	}

	/**
	 * Copy a saved report into another user's library.
	 *
	 * @return void
	 */
	public static function ajax_copy() {
		self::require_ajax();
		require_once plugin_dir_path( __FILE__ ) . '../models/class-saved-report.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		$row = self::owned_from_request();
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Save this report before copying it.', 'remember' ) ), 404 );
		}
		$recipient_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$definition   = self::definition_from_row( $row );
		if ( ! Remember_Report_Catalog::user_can_receive_report( $recipient_id, $row->subject, $definition ) ) {
			wp_send_json_error( array( 'message' => __( 'That person cannot run this report.', 'remember' ) ), 403 );
		}
		$result = Remember_Saved_Report::copy_to_owner( (int) $row->report_id, get_current_user_id(), $recipient_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		$user  = get_userdata( $recipient_id );
		$label = $user && $user->display_name ? $user->display_name : ( $user ? $user->user_login : (string) $recipient_id );
		wp_send_json_success(
			array(
				'report_id' => (int) $result,
				'message'   => sprintf(
					/* translators: %s: recipient display name */
					__( 'Copied to %s.', 'remember' ),
					$label
				),
			)
		);
	}

	/**
	 * Stream CSV.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You cannot view reports.', 'remember' ), esc_html__( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		check_admin_referer( 'remember_reports', 'nonce' );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-engine.php';
		$raw = isset( $_POST['definition'] ) ? wp_unslash( $_POST['definition'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$definition = is_string( $raw ) ? json_decode( $raw, true ) : array();
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$result   = Remember_Report_Engine::run( $definition, 1, Remember_Report_Engine::PAGE_SIZE, true, $event_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		self::log_sensitive_report( $definition, __( 'Report CSV', 'remember' ), count( $result['rows'] ) );
		$filename = 'remember-report-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		$out = fopen( 'php://output', 'w' );
		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		$headers = array();
		foreach ( $result['columns'] as $col ) {
			$headers[] = Remember_Report_Engine::csv_cell( $col['label'] );
		}
		fputcsv( $out, $headers );
		foreach ( $result['rows'] as $row ) {
			$line = array();
			foreach ( $result['columns'] as $col ) {
				$id     = $col['id'];
				$line[] = Remember_Report_Engine::csv_cell( isset( $row[ $id ] ) ? $row[ $id ] : '' );
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Record a report run that includes health or emergency columns.
	 *
	 * @param array  $definition Builder JSON.
	 * @param string $context    Preview or CSV.
	 * @param int    $row_count  Rows returned.
	 * @return void
	 */
	private static function log_sensitive_report( $definition, $context, $row_count ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';
		$subject = isset( $definition['subject'] ) ? (string) $definition['subject'] : '';
		$what    = Remember_Access_Log::what_from_topics( Remember_Report_Catalog::sensitive_topics( $subject, $definition ) );
		if ( '' === $what ) {
			return;
		}
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' !== $name ) {
			$context = $name . ' — ' . $context;
		}
		Remember_Access_Log::record( 0, $what, $context, $row_count );
	}

	/**
	 * Definition from POST.
	 *
	 * @return array
	 */
	private static function definition_from_request() {
		$raw = isset( $_POST['definition'] ) ? wp_unslash( $_POST['definition'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_array( $raw ) ) {
			unset( $raw['event_id'] );
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		unset( $decoded['event_id'] );
		return $decoded;
	}

	/**
	 * Owned saved report from POST.
	 *
	 * @return object|null
	 */
	private static function owned_from_request() {
		$id = isset( $_POST['report_id'] ) ? absint( wp_unslash( $_POST['report_id'] ) ) : 0;
		if ( $id < 1 ) {
			return null;
		}
		return Remember_Saved_Report::get_owned( $id, get_current_user_id() );
	}

	/**
	 * Definition JSON from a saved row.
	 *
	 * @param object $row Saved report.
	 * @return array
	 */
	private static function definition_from_row( $row ) {
		$definition = json_decode( (string) $row->definition, true );
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		unset( $definition['event_id'] );
		return $definition;
	}
}
