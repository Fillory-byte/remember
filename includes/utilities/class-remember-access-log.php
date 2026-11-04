<?php
/**
 * Who viewed or exported health and emergency-contact data.
 *
 * The log stores staff user, member (or a bulk export), what was opened,
 * the screen or export name, a row count, the time, and the IP. It does not
 * store the health or emergency values themselves. The table is a normal
 * reMember table, so a full backup includes it and a restore keeps the trail.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sensitive-data access log.
 */
class Remember_Access_Log {

	const CRON_HOOK     = 'remember_prune_sensitive_access_log';
	const OPTION_MONTHS = 'remember_sensitive_access_retention_months';
	const PAGE_SIZE     = 50;

	/**
	 * Register the daily prune.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the prune once.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Collapse health and emergency into the stored label.
	 *
	 * @param string[] $topics health and/or emergency.
	 * @return string health|emergency|both|empty
	 */
	public static function what_from_topics( $topics ) {
		$topics = array_values( array_unique( array_filter( (array) $topics ) ) );
		$has_health    = in_array( 'health', $topics, true );
		$has_emergency = in_array( 'emergency', $topics, true );
		if ( $has_health && $has_emergency ) {
			return 'both';
		}
		if ( $has_health ) {
			return 'health';
		}
		if ( $has_emergency ) {
			return 'emergency';
		}
		return '';
	}

	/**
	 * Topics the current user is allowed to see, when a screen always includes them.
	 *
	 * @return string[]
	 */
	public static function topics_for_current_user() {
		$topics = array();
		if ( current_user_can( 'remember_access_health' ) ) {
			$topics[] = 'health';
		}
		if ( current_user_can( 'remember_access_emergency_contact' ) ) {
			$topics[] = 'emergency';
		}
		return $topics;
	}

	/**
	 * Record one view or export. Member ID 0 is a bulk export.
	 *
	 * @param int    $member_id Member ID, or 0 for a bulk export.
	 * @param string $what      health|emergency|both|backup.
	 * @param string $context   Screen or export name.
	 * @param int    $row_count Rows in a bulk export, or members in a full backup. 0 when this is one member.
	 * @return void
	 */
	public static function record( $member_id, $what, $context, $row_count = 0 ) {
		$what = sanitize_key( (string) $what );
		if ( ! in_array( $what, array( 'health', 'emergency', 'both', 'backup' ), true ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'user_id'     => $user_id,
				'member_id'   => absint( $member_id ),
				'access_what' => $what,
				'context'     => substr( sanitize_text_field( (string) $context ), 0, 191 ),
				'row_count'   => absint( $row_count ),
				'ip'          => self::client_ip(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Most recent log row for one member, before the current page records itself.
	 *
	 * @param int $member_id Member ID.
	 * @return object|null
	 */
	public static function last_for_member( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id < 1 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT user_id, access_what, context, created_at FROM ' . self::table() . ' WHERE member_id = %d ORDER BY log_id DESC LIMIT 1',
				$member_id
			)
		);
		return is_object( $row ) ? $row : null;
	}

	/**
	 * Page of log rows, newest first.
	 *
	 * @param int $staff_id  Filter to this staff user. 0 skips the filter.
	 * @param int $member_id Filter to this member. 0 skips the filter.
	 * @param int $page      1-based page.
	 * @return array{rows:array<int,object>,total:int}
	 */
	public static function query( $staff_id, $member_id, $page ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		$staff_id  = absint( $staff_id );
		$member_id = absint( $member_id );
		if ( $staff_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $staff_id;
		}
		if ( $member_id > 0 ) {
			$where[]  = 'member_id = %d';
			$params[] = $member_id;
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( empty( $params ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$page      = max( 1, absint( $page ) );
		$offset    = ( $page - 1 ) * self::PAGE_SIZE;
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY log_id DESC LIMIT %d OFFSET %d";
		$list_args = array_merge( $params, array( self::PAGE_SIZE, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Human label for a stored access type.
	 *
	 * @param string $what Stored value.
	 * @return string
	 */
	public static function label( $what ) {
		$labels = array(
			'health'    => __( 'Health', 'remember' ),
			'emergency' => __( 'Emergency contact', 'remember' ),
			'both'      => __( 'Health and emergency contact', 'remember' ),
			'backup'    => __( 'Full backup', 'remember' ),
		);
		return isset( $labels[ $what ] ) ? $labels[ $what ] : (string) $what;
	}

	/**
	 * Months to keep. Default 12.
	 *
	 * @return int
	 */
	public static function retention_months() {
		$months = absint( get_option( self::OPTION_MONTHS, 12 ) );
		if ( $months < 1 ) {
			return 12;
		}
		return min( 120, $months );
	}

	/**
	 * Save the retention window.
	 *
	 * @param int $months Months, from 1 to 120.
	 * @return void
	 */
	public static function set_retention_months( $months ) {
		$months = absint( $months );
		if ( $months < 1 ) {
			$months = 1;
		}
		update_option( self::OPTION_MONTHS, min( 120, $months ) );
	}

	/**
	 * Delete rows older than the retention window.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . self::retention_months() . ' months' )->format( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created_at < %s',
				$cutoff
			)
		);
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_sensitive_access_log';
	}

	/**
	 * Client IP, or an empty string when it is not a real address.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return substr( $ip, 0, 45 );
	}
}
