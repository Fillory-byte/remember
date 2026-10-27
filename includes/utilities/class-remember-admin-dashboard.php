<?php
/**
 * Data for the reMember admin dashboard.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Counts, attention items, upcoming events, and recent activity for staff.
 */
class Remember_Admin_Dashboard {

	/**
	 * Everything the dashboard and the WordPress widget need, limited by capability.
	 *
	 * @return array{stats:array,attention:array,upcoming:array,activity:array,show_events:bool,show_activity:bool}
	 */
	public static function collect() {
		global $wpdb;

		$can_members      = current_user_can( 'remember_read_members' ) || current_user_can( 'remember_read_attendees' );
		$can_vetting      = current_user_can( 'remember_read_vetting' );
		$can_applications = current_user_can( 'remember_read_applications' );
		$can_events       = current_user_can( 'remember_read_events' );
		$can_duplicates   = current_user_can( 'remember_merge_profiles' );

		$members_table = $wpdb->prefix . 'remember_members';
		$events_table  = $wpdb->prefix . 'remember_events';
		$apps_table    = $wpdb->prefix . 'remember_event_applications';
		$vetting_table = $wpdb->prefix . 'remember_vetting';

		$stats = array();

		if ( $can_members ) {
			$member_count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$members_table} m
				INNER JOIN {$wpdb->users} u ON m.member_id = u.ID
				WHERE m.status IS NULL OR m.status != 'merged'"
			);
			$stats[] = self::stat( $member_count, __( 'Members', 'remember' ), admin_url( 'admin.php?page=remember-members' ), 'members' );
		}

		if ( $can_vetting ) {
			$open_vetting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vetting_table} WHERE status != 'completed'" );
			$stats[]      = self::stat( $open_vetting, __( 'Open vetting', 'remember' ), admin_url( 'admin.php?page=remember-vetting' ), 'vetting' );
		}

		if ( $can_applications ) {
			$pending_apps = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$apps_table} WHERE status = 'pending'" );
			$stats[]      = self::stat( $pending_apps, __( 'Pending applications', 'remember' ), admin_url( 'admin.php?page=remember-applications' ), 'applications' );
		}

		if ( $can_events ) {
			$open_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} WHERE status = 'open'" );
			$stats[]     = self::stat( $open_events, __( 'Open events', 'remember' ), admin_url( 'admin.php?page=remember-events' ), 'events' );
		}

		$attention = array();
		if ( $can_vetting ) {
			$attention = array_merge( $attention, self::your_vetting_tasks() );
			$unassigned = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$vetting_table}
				WHERE status IN ('pending', 'scheduled', 'in_progress')
				AND (primary_vetter_id IS NULL OR primary_vetter_id = 0)"
			);
			if ( $unassigned > 0 ) {
				$attention[] = array(
					'tone'   => 'soon',
					'title'  => sprintf(
						/* translators: %d: number of vetting cases */
						_n( '%d vetting case has no vetter', '%d vetting cases have no vetter', $unassigned, 'remember' ),
						$unassigned
					),
					'detail' => __( 'The vetting team has not been given these yet.', 'remember' ),
					'meta'   => number_format_i18n( $unassigned ),
					'url'    => admin_url( 'admin.php?page=remember-vetting' ),
				);
			}
		}

		if ( $can_applications ) {
			$pending_apps = isset( $pending_apps ) ? $pending_apps : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$apps_table} WHERE status = 'pending'" );
			if ( $pending_apps > 0 ) {
				$oldest = $wpdb->get_var( "SELECT MIN(applied_at) FROM {$apps_table} WHERE status = 'pending'" );
				$detail = '';
				if ( is_string( $oldest ) && '' !== $oldest ) {
					$detail = sprintf(
						/* translators: %s: human time difference, such as "3 days" */
						__( 'Oldest applied %s ago', 'remember' ),
						human_time_diff( strtotime( $oldest ), current_time( 'timestamp' ) )
					);
				}
				$attention[] = array(
					'tone'   => 'soon',
					'title'  => sprintf(
						/* translators: %d: number of applications */
						_n( '%d application is waiting for review', '%d applications are waiting for review', $pending_apps, 'remember' ),
						$pending_apps
					),
					'detail' => $detail,
					'meta'   => number_format_i18n( $pending_apps ),
					'url'    => admin_url( 'admin.php?page=remember-applications' ),
				);
			}
		}

		if ( $can_duplicates ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-duplicates.php';
			$duplicates = Remember_Profile_Duplicates::pending_count();
			if ( $duplicates > 0 ) {
				$attention[] = array(
					'tone'   => 'soon',
					'title'  => sprintf(
						/* translators: %d: number of duplicate reviews */
						_n( '%d possible duplicate profile', '%d possible duplicate profiles', $duplicates, 'remember' ),
						$duplicates
					),
					'detail' => __( 'Review them side by side, then merge or dismiss.', 'remember' ),
					'meta'   => number_format_i18n( $duplicates ),
					'url'    => admin_url( 'admin.php?page=remember-duplicates' ),
				);
			}
		}

		if ( $can_events ) {
			$attention = array_merge( $attention, self::registration_closing_soon( $events_table ) );
		}

		$summary = '';
		if ( $can_members ) {
			$summary = sprintf(
				/* translators: %s: member count */
				_n( '%s member on file.', '%s members on file.', $member_count, 'remember' ),
				number_format_i18n( $member_count )
			);
		}

		return array(
			'stats'       => $stats,
			'summary'     => $summary,
			'attention'   => $attention,
			'people'      => $can_members ? self::recent_people() : array(),
			'upcoming'    => $can_events ? self::upcoming_events() : array(),
			'activity'    => self::recent_activity( $can_vetting, $can_applications ),
			'show_people' => $can_members,
			'show_events' => $can_events,
		);
	}

	/**
	 * One stat card.
	 *
	 * @param int    $count Count.
	 * @param string $label Label.
	 * @param string $url   Destination.
	 * @param string $tone  members|vetting|applications|events.
	 * @return array{count:string,label:string,url:string,tone:string}
	 */
	private static function stat( $count, $label, $url, $tone ) {
		return array(
			'count' => number_format_i18n( (int) $count ),
			'label' => $label,
			'url'   => $url,
			'tone'  => $tone,
		);
	}

	/**
	 * Newest members, with their roles, for the people card.
	 *
	 * @return array<int,array{title:string,detail:string,meta:string,url:string}>
	 */
	private static function recent_people() {
		global $wpdb;

		$members_table = $wpdb->prefix . 'remember_members';
		$rows          = $wpdb->get_results(
			"SELECT m.member_id, m.status, u.display_name
			FROM {$members_table} m
			INNER JOIN {$wpdb->users} u ON u.ID = m.member_id
			WHERE m.status IS NULL OR m.status != 'merged'
			ORDER BY m.updated_at DESC
			LIMIT 6"
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = absint( $row->member_id );
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$role_rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT mr.member_id, r.role_name
				FROM {$wpdb->prefix}remember_member_roles mr
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
				WHERE mr.member_id IN ($placeholders)
				ORDER BY r.role_name ASC",
				$ids
			)
		);
		$roles_by_member = array();
		foreach ( (array) $role_rows as $role_row ) {
			$roles_by_member[ (int) $role_row->member_id ][] = $role_row->role_name;
		}

		$statuses = array(
			'pending_vetting' => __( 'Pending vetting', 'remember' ),
			'unvetted'        => __( 'Unvetted', 'remember' ),
			'in_vetting'      => __( 'In vetting', 'remember' ),
			'vetted'          => __( 'Vetted', 'remember' ),
			'rejected'        => __( 'Rejected', 'remember' ),
			'inactive'        => __( 'Inactive', 'remember' ),
		);

		$people = array();
		foreach ( $rows as $row ) {
			$member_id = (int) $row->member_id;
			$status    = isset( $statuses[ $row->status ] ) ? $statuses[ $row->status ] : $row->status;
			$roles     = isset( $roles_by_member[ $member_id ] ) ? $roles_by_member[ $member_id ] : array();
			$people[]  = array(
				'title'  => $row->display_name ? $row->display_name : __( 'Unknown Member', 'remember' ),
				'detail' => implode( ', ', $roles ),
				'meta'   => $status,
				'url'    => admin_url( 'admin.php?page=remember-members&view=' . $member_id ),
			);
		}
		return $people;
	}

	/**
	 * Open vetting assigned to the current user, or where they are a collaborator.
	 *
	 * Overdue scheduled cases sort first. At most five are returned.
	 *
	 * @return array<int,array{tone:string,title:string,detail:string,meta:string,url:string}>
	 */
	private static function your_vetting_tasks() {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-timezone.php';

		global $wpdb;
		$vetting_model = new Remember_Vetting();
		$user_id       = get_current_user_id();
		$tasks         = array();
		$seen          = array();

		$add = function ( $vetting, $title ) use ( &$tasks, &$seen, $user_id ) {
			$vetting_id = absint( $vetting->vetting_id );
			if ( isset( $seen[ $vetting_id ] ) || ! in_array( $vetting->status, array( 'pending', 'scheduled', 'in_progress' ), true ) ) {
				return;
			}
			$seen[ $vetting_id ] = true;

			$detail     = '';
			$is_overdue = false;
			if ( 'scheduled' === $vetting->status && ! empty( $vetting->scheduled_at ) ) {
				$detail        = Remember_Timezone::format_with_your_time( $vetting->scheduled_at, $user_id, false );
				$user_tz       = Remember_Timezone::get_user_timezone( $user_id );
				$scheduled_dt  = Remember_Timezone::convert_to_user_timezone( $vetting->scheduled_at, $user_id );
				$is_overdue    = $scheduled_dt < new DateTime( 'now', $user_tz );
			} elseif ( 'in_progress' === $vetting->status ) {
				$detail = __( 'In progress', 'remember' );
			} elseif ( 'pending' === $vetting->status ) {
				$detail = __( 'Not scheduled yet', 'remember' );
			}

			$sort = ! empty( $vetting->scheduled_at ) ? $vetting->scheduled_at : $vetting->created_at;
			$tasks[] = array(
				'tone'   => $is_overdue ? 'overdue' : 'normal',
				'title'  => $title,
				'detail' => $detail,
				'meta'   => $is_overdue ? __( 'Overdue', 'remember' ) : '',
				'url'    => admin_url( 'admin.php?page=remember-vetting&view=' . $vetting_id ),
				'sort'   => $sort ? $sort : '9999-12-31 23:59:59',
			);
		};

		foreach ( $vetting_model->get_by_vetter( $user_id ) as $vetting ) {
			$member_user = get_user_by( 'id', $vetting->member_id );
			$name        = $member_user ? $member_user->display_name : __( 'Unknown Member', 'remember' );
			$add( $vetting, sprintf( __( 'Review vetting: %s', 'remember' ), $name ) );
		}

		$collaborator_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT v.vetting_id
				FROM {$wpdb->prefix}remember_vetting v
				INNER JOIN {$wpdb->prefix}remember_vetting_collaborators vc ON v.vetting_id = vc.vetting_id
				WHERE vc.member_id = %d
				AND vc.status = 'accepted'
				AND v.status IN ('pending', 'scheduled', 'in_progress')",
				$user_id
			)
		);
		foreach ( $collaborator_ids as $vetting_id ) {
			$vetting = $vetting_model->get( $vetting_id );
			if ( ! $vetting ) {
				continue;
			}
			$member_user = get_user_by( 'id', $vetting->member_id );
			$name        = $member_user ? $member_user->display_name : __( 'Unknown Member', 'remember' );
			$add( $vetting, sprintf( __( 'Collaborate on vetting: %s', 'remember' ), $name ) );
		}

		usort(
			$tasks,
			function ( $a, $b ) {
				return strcmp( (string) $a['sort'], (string) $b['sort'] );
			}
		);
		$tasks = array_slice( $tasks, 0, 5 );
		foreach ( $tasks as &$task ) {
			unset( $task['sort'] );
		}
		unset( $task );

		return $tasks;
	}

	/**
	 * Open events whose registration closes within seven days.
	 *
	 * @param string $events_table Events table name.
	 * @return array<int,array{tone:string,title:string,detail:string,meta:string,url:string}>
	 */
	private static function registration_closing_soon( $events_table ) {
		global $wpdb;

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$until = $now->modify( '+7 days' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, event_name, registration_closes_at
				FROM {$events_table}
				WHERE status = 'open'
				AND registration_closes_at IS NOT NULL
				AND registration_closes_at >= %s
				AND registration_closes_at <= %s
				ORDER BY registration_closes_at ASC
				LIMIT 3",
				$now->format( 'Y-m-d H:i:s' ),
				$until->format( 'Y-m-d H:i:s' )
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
		$items = array();
		foreach ( $rows as $row ) {
			$when    = Remember_Event::format_registration_datetime( $row->registration_closes_at );
			$items[] = array(
				'tone'   => 'soon',
				'title'  => $row->event_name,
				'detail' => sprintf(
					/* translators: %s: date and time registration closes */
					__( 'Registration closes %s', 'remember' ),
					$when
				),
				'meta'   => __( 'Closing soon', 'remember' ),
				'url'    => admin_url( 'admin.php?page=remember-events&view=' . absint( $row->event_id ) ),
			);
		}
		return $items;
	}

	/**
	 * The next five events that have not started, with application counts.
	 *
	 * @return array<int,array{title:string,detail:string,meta:string,url:string,tone:string}>
	 */
	private static function upcoming_events() {
		global $wpdb;

		$events_table = $wpdb->prefix . 'remember_events';
		$apps_table   = $wpdb->prefix . 'remember_event_applications';
		$locs_table   = $wpdb->prefix . 'remember_locations';
		$today        = current_time( 'Y-m-d' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_id, e.event_name, e.start_date, e.end_date, e.status, l.location_name,
					COALESCE(SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
					COALESCE(SUM(CASE WHEN a.status = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted_count
				FROM {$events_table} e
				LEFT JOIN {$locs_table} l ON l.location_id = e.location_id
				LEFT JOIN {$apps_table} a ON a.event_id = e.event_id
				WHERE e.start_date >= %s AND e.status IN ('open', 'draft')
				GROUP BY e.event_id, e.event_name, e.start_date, e.end_date, e.status, l.location_name
				ORDER BY e.start_date ASC
				LIMIT 5",
				$today
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$status_labels = array(
			'draft' => __( 'Draft', 'remember' ),
			'open'  => __( 'Open', 'remember' ),
		);
		$items = array();
		foreach ( $rows as $row ) {
			$start = date_i18n( get_option( 'date_format' ), strtotime( $row->start_date ) );
			$end   = date_i18n( get_option( 'date_format' ), strtotime( $row->end_date ) );
			$when  = ( $row->start_date === $row->end_date ) ? $start : $start . ' – ' . $end;
			$bits  = array( $when );
			if ( ! empty( $row->location_name ) ) {
				$bits[] = $row->location_name;
			}
			if ( isset( $status_labels[ $row->status ] ) ) {
				$bits[] = $status_labels[ $row->status ];
			}

			$pending  = (int) $row->pending_count;
			$accepted = (int) $row->accepted_count;
			$counts   = array();
			if ( $pending > 0 ) {
				$counts[] = sprintf(
					/* translators: %d: pending applications */
					_n( '%d pending', '%d pending', $pending, 'remember' ),
					$pending
				);
			}
			if ( $accepted > 0 ) {
				$counts[] = sprintf(
					/* translators: %d: accepted applications */
					_n( '%d accepted', '%d accepted', $accepted, 'remember' ),
					$accepted
				);
			}

			$items[] = array(
				'tone'   => 'normal',
				'title'  => $row->event_name,
				'detail' => implode( ' · ', $bits ),
				'meta'   => implode( ' · ', $counts ),
				'url'    => admin_url( 'admin.php?page=remember-events&view=' . absint( $row->event_id ) ),
			);
		}
		return $items;
	}

	/**
	 * Latest vetting and application events the current user is allowed to see.
	 *
	 * @param bool $can_vetting      Whether vetting rows may be included.
	 * @param bool $can_applications Whether application rows may be included.
	 * @return array<int,array{type:string,timestamp:string,message:string}>
	 */
	private static function recent_activity( $can_vetting, $can_applications ) {
		global $wpdb;
		$activity = array();

		if ( $can_vetting ) {
			$vetting_table = $wpdb->prefix . 'remember_vetting';
			$opened        = $wpdb->get_results(
				"SELECT v.vetting_id, v.created_at, v.member_id, u.display_name
				FROM {$vetting_table} v
				LEFT JOIN {$wpdb->users} u ON v.member_id = u.ID
				ORDER BY v.created_at DESC
				LIMIT 8"
			);
			foreach ( (array) $opened as $row ) {
				$url        = admin_url( 'admin.php?page=remember-vetting&view=' . absint( $row->vetting_id ) );
				$name       = $row->display_name ? $row->display_name : __( 'Unknown Member', 'remember' );
				$activity[] = array(
					'type'      => 'vetting',
					'timestamp' => $row->created_at,
					'message'   => sprintf(
						/* translators: 1: linked case number, 2: member name */
						__( 'Vetting case %1$s opened for %2$s', 'remember' ),
						'<a href="' . esc_url( $url ) . '">#' . absint( $row->vetting_id ) . '</a>',
						esc_html( $name )
					),
				);
			}

			$completed = $wpdb->get_results(
				"SELECT v.vetting_id, v.completed_at, u.display_name
				FROM {$vetting_table} v
				LEFT JOIN {$wpdb->users} u ON v.member_id = u.ID
				WHERE v.completed_at IS NOT NULL AND v.status = 'completed'
				ORDER BY v.completed_at DESC
				LIMIT 8"
			);
			foreach ( (array) $completed as $row ) {
				$url        = admin_url( 'admin.php?page=remember-vetting&view=' . absint( $row->vetting_id ) );
				$name       = $row->display_name ? $row->display_name : __( 'Unknown Member', 'remember' );
				$activity[] = array(
					'type'      => 'vetting',
					'timestamp' => $row->completed_at,
					'message'   => sprintf(
						/* translators: 1: linked case number, 2: member name */
						__( 'Vetting case %1$s was completed for %2$s', 'remember' ),
						'<a href="' . esc_url( $url ) . '">#' . absint( $row->vetting_id ) . '</a>',
						esc_html( $name )
					),
				);
			}
		}

		if ( $can_applications ) {
			$apps_table   = $wpdb->prefix . 'remember_event_applications';
			$events_table = $wpdb->prefix . 'remember_events';
			$applied      = $wpdb->get_results(
				"SELECT a.application_id, a.applied_at, a.processed_at, a.status, u.display_name, e.event_name
				FROM {$apps_table} a
				LEFT JOIN {$wpdb->users} u ON a.member_id = u.ID
				LEFT JOIN {$events_table} e ON a.event_id = e.event_id
				ORDER BY a.applied_at DESC
				LIMIT 8"
			);
			foreach ( (array) $applied as $row ) {
				$activity[] = array(
					'type'      => 'application',
					'timestamp' => $row->applied_at,
					'message'   => self::application_activity_message( $row, 'applied' ),
				);
				if ( ! empty( $row->processed_at ) && in_array( $row->status, array( 'accepted', 'declined', 'waitlisted' ), true ) ) {
					$activity[] = array(
						'type'      => 'application',
						'timestamp' => $row->processed_at,
						'message'   => self::application_activity_message( $row, $row->status ),
					);
				}
			}
		}

		usort(
			$activity,
			function ( $a, $b ) {
				return strcmp( (string) $b['timestamp'], (string) $a['timestamp'] );
			}
		);
		return array_slice( $activity, 0, 8 );
	}

	/**
	 * One application activity sentence, with the event name linked.
	 *
	 * @param object $row  Application row with display_name and event_name.
	 * @param string $kind applied|accepted|declined|waitlisted.
	 * @return string HTML safe for wp_kses_post.
	 */
	private static function application_activity_message( $row, $kind ) {
		$url   = admin_url( 'admin.php?page=remember-applications&view=' . absint( $row->application_id ) );
		$name  = $row->display_name ? $row->display_name : __( 'Unknown Member', 'remember' );
		$event = $row->event_name ? $row->event_name : __( 'Unknown Event', 'remember' );
		$link  = '<a href="' . esc_url( $url ) . '">' . esc_html( $event ) . '</a>';

		if ( 'accepted' === $kind ) {
			return sprintf(
				/* translators: 1: member name, 2: linked event name */
				__( '%1$s was accepted for %2$s', 'remember' ),
				esc_html( $name ),
				$link
			);
		}
		if ( 'declined' === $kind ) {
			return sprintf(
				/* translators: 1: member name, 2: linked event name */
				__( '%1$s was declined for %2$s', 'remember' ),
				esc_html( $name ),
				$link
			);
		}
		if ( 'waitlisted' === $kind ) {
			return sprintf(
				/* translators: 1: member name, 2: linked event name */
				__( '%1$s was waitlisted for %2$s', 'remember' ),
				esc_html( $name ),
				$link
			);
		}
		return sprintf(
			/* translators: 1: member name, 2: linked event name */
			__( '%1$s applied to %2$s', 'remember' ),
			esc_html( $name ),
			$link
		);
	}
}
