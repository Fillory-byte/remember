<?php
/**
 * Cap-aware field catalog for staff reports.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Subjects, fields, and join keys the query compiler may use.
 */
class Remember_Report_Catalog {

	/**
	 * Subjects the current user may open.
	 *
	 * @return array<string,string> subject => label
	 */
	public static function subjects_for_current_user() {
		$out = array();
		foreach ( self::subject_caps() as $id => $meta ) {
			if ( self::user_can_open_subject( get_current_user_id(), $id ) ) {
				$out[ $id ] = $meta['label'];
			}
		}
		return $out;
	}

	/**
	 * Subject ids and the read caps that unlock them.
	 *
	 * @return array<string,array{label:string,cap:string[]}>
	 */
	public static function subject_caps() {
		return array(
			'members'      => array(
				'label' => __( 'Members', 'remember' ),
				'cap'   => array( 'remember_read_members', 'remember_read_attendees' ),
			),
			'applications' => array(
				'label' => __( 'Applications', 'remember' ),
				'cap'   => array( 'remember_read_applications' ),
			),
			'payments'     => array(
				'label' => __( 'Payments', 'remember' ),
				'cap'   => array( 'remember_read_billing' ),
			),
			'vetting'      => array(
				'label' => __( 'Vetting', 'remember' ),
				'cap'   => array( 'remember_read_vetting' ),
			),
			'events'       => array(
				'label' => __( 'Events', 'remember' ),
				'cap'   => array( 'remember_read_events' ),
			),
			'surveys'      => array(
				'label' => __( 'Surveys', 'remember' ),
				'cap'   => array( 'remember_read_applications' ),
			),
		);
	}

	/**
	 * Whether a user may open a subject.
	 *
	 * @param int    $user_id User.
	 * @param string $subject Subject.
	 * @return bool
	 */
	public static function user_can_open_subject( $user_id, $subject ) {
		$all = self::subject_caps();
		if ( ! isset( $all[ $subject ] ) ) {
			return false;
		}
		foreach ( $all[ $subject ]['cap'] as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a user can receive a copy of this report as designed.
	 *
	 * @param int    $user_id    Recipient.
	 * @param string $subject    Subject.
	 * @param array  $definition Builder JSON.
	 * @return bool
	 */
	public static function user_can_receive_report( $user_id, $subject, $definition ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! user_can( $user_id, 'remember_view_reports' ) ) {
			return false;
		}
		if ( ! self::user_can_open_subject( $user_id, $subject ) ) {
			return false;
		}
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		$fields = self::raw_fields( $subject );
		$needed = array();
		foreach ( array( 'columns', 'group_by' ) as $key ) {
			if ( empty( $definition[ $key ] ) || ! is_array( $definition[ $key ] ) ) {
				continue;
			}
			foreach ( $definition[ $key ] as $id ) {
				$needed[] = (string) $id;
			}
		}
		if ( empty( $needed ) ) {
			$needed = self::default_columns( $subject );
		}
		if ( ! empty( $definition['filters'] ) && is_array( $definition['filters'] ) ) {
			foreach ( $definition['filters'] as $filter ) {
				if ( is_array( $filter ) && ! empty( $filter['field'] ) ) {
					$needed[] = (string) $filter['field'];
				}
			}
		}
		if ( ! empty( $definition['aggregations'] ) && is_array( $definition['aggregations'] ) ) {
			foreach ( $definition['aggregations'] as $agg ) {
				if ( is_array( $agg ) && ! empty( $agg['field'] ) && '*' !== $agg['field'] ) {
					$needed[] = (string) $agg['field'];
				}
			}
		}
		if ( ! empty( $definition['sort']['field'] ) ) {
			$needed[] = (string) $definition['sort']['field'];
		}
		foreach ( array_unique( $needed ) as $id ) {
			if ( ! isset( $fields[ $id ]['sensitive'] ) ) {
				continue;
			}
			if ( 'emergency' === $fields[ $id ]['sensitive'] && ! user_can( $user_id, 'remember_access_emergency_contact' ) ) {
				return false;
			}
			if ( 'health' === $fields[ $id ]['sensitive'] && ! user_can( $user_id, 'remember_access_health' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Health or emergency topics a report definition would show.
	 *
	 * @param string $subject    Subject.
	 * @param array  $definition Builder JSON.
	 * @return string[] health and/or emergency.
	 */
	public static function sensitive_topics( $subject, $definition ) {
		$fields = self::raw_fields( $subject );
		if ( ! is_array( $definition ) ) {
			$definition = array();
		}
		$needed = array();
		foreach ( array( 'columns', 'group_by' ) as $key ) {
			if ( empty( $definition[ $key ] ) || ! is_array( $definition[ $key ] ) ) {
				continue;
			}
			foreach ( $definition[ $key ] as $id ) {
				$needed[] = (string) $id;
			}
		}
		if ( ! empty( $definition['filters'] ) && is_array( $definition['filters'] ) ) {
			foreach ( $definition['filters'] as $filter ) {
				if ( is_array( $filter ) && ! empty( $filter['field'] ) ) {
					$needed[] = (string) $filter['field'];
				}
			}
		}
		if ( ! empty( $definition['aggregations'] ) && is_array( $definition['aggregations'] ) ) {
			foreach ( $definition['aggregations'] as $agg ) {
				if ( is_array( $agg ) && ! empty( $agg['field'] ) && '*' !== $agg['field'] ) {
					$needed[] = (string) $agg['field'];
				}
			}
		}
		if ( ! empty( $definition['sort']['field'] ) ) {
			$needed[] = (string) $definition['sort']['field'];
		}
		$topics = array();
		foreach ( array_unique( $needed ) as $id ) {
			if ( empty( $fields[ $id ]['sensitive'] ) ) {
				continue;
			}
			$topic = (string) $fields[ $id ]['sensitive'];
			if ( 'health' === $topic || 'emergency' === $topic ) {
				$topics[ $topic ] = true;
			}
		}
		return array_keys( $topics );
	}

	/**
	 * Staff who can receive a copy of this report.
	 *
	 * @param string $subject    Subject.
	 * @param array  $definition Definition.
	 * @param int    $exclude_id User to skip (usually the owner).
	 * @return array<int,array{id:int,label:string}>
	 */
	public static function recipients_for_report( $subject, $definition, $exclude_id = 0 ) {
		global $wpdb;
		$exclude_id = absint( $exclude_id );
		$ids        = array();
		$member_ids = $wpdb->get_col( "SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( is_array( $member_ids ) ) {
			foreach ( $member_ids as $id ) {
				$ids[] = (int) $id;
			}
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		foreach ( $admins as $id ) {
			$ids[] = (int) $id;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			if ( $id === $exclude_id ) {
				continue;
			}
			if ( ! self::user_can_receive_report( $id, $subject, $definition ) ) {
				continue;
			}
			$user = get_userdata( $id );
			if ( ! $user ) {
				continue;
			}
			$label = $user->display_name ? $user->display_name : $user->user_login;
			if ( $user->user_login && $user->user_login !== $label ) {
				$label .= ' (' . $user->user_login . ')';
			}
			$out[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return $out;
	}

	/**
	 * Fields visible to the current user for a subject.
	 *
	 * @param string $subject Subject id.
	 * @return array<string,array<string,mixed>> field_id => field
	 */
	public static function fields_for_current_user( $subject ) {
		$subjects = self::subjects_for_current_user();
		if ( ! isset( $subjects[ $subject ] ) ) {
			return array();
		}
		$fields = self::raw_fields( $subject );
		$out    = array();
		foreach ( $fields as $id => $field ) {
			if ( ! empty( $field['sensitive'] ) && 'emergency' === $field['sensitive'] && ! current_user_can( 'remember_access_emergency_contact' ) ) {
				continue;
			}
			if ( ! empty( $field['sensitive'] ) && 'health' === $field['sensitive'] && ! current_user_can( 'remember_access_health' ) ) {
				continue;
			}
			$out[ $id ] = $field;
		}
		if ( in_array( $subject, array( 'members', 'applications' ), true ) ) {
			$out = array_merge( $out, self::custom_question_fields( $subject ) );
		}
		return $out;
	}

	/**
	 * Catalog payload for the builder UI.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload_for_current_user() {
		$subjects = self::subjects_for_current_user();
		$out      = array(
			'subjects' => array(),
		);
		foreach ( $subjects as $id => $label ) {
			$fields = array();
			foreach ( self::fields_for_current_user( $id ) as $fid => $field ) {
				$row = array(
					'id'      => $fid,
					'label'   => $field['label'],
					'group'   => $field['group'],
					'type'    => $field['type'],
					'options' => isset( $field['options'] ) ? $field['options'] : array(),
					'measure' => ! empty( $field['measure'] ),
					'list'    => ! empty( $field['list'] ),
				);
				if ( ! empty( $field['questions'] ) && is_array( $field['questions'] ) ) {
					$row['questions'] = $field['questions'];
				}
				$fields[] = $row;
			}
			$out['subjects'][] = array(
				'id'      => $id,
				'label'   => $label,
				'fields'  => $fields,
				'default' => self::default_columns( $id ),
			);
		}
		$out['operators'] = array(
			array( 'id' => 'eq', 'label' => __( 'is', 'remember' ) ),
			array( 'id' => 'neq', 'label' => __( 'is not', 'remember' ) ),
			array( 'id' => 'contains', 'label' => __( 'contains', 'remember' ) ),
			array( 'id' => 'in', 'label' => __( 'is one of', 'remember' ) ),
			array( 'id' => 'not_in', 'label' => __( 'is not one of', 'remember' ) ),
			array( 'id' => 'empty', 'label' => __( 'is empty', 'remember' ) ),
			array( 'id' => 'not_empty', 'label' => __( 'is not empty', 'remember' ) ),
			array( 'id' => 'gt', 'label' => __( 'greater than', 'remember' ) ),
			array( 'id' => 'gte', 'label' => __( 'at least', 'remember' ) ),
			array( 'id' => 'lt', 'label' => __( 'less than', 'remember' ) ),
			array( 'id' => 'lte', 'label' => __( 'at most', 'remember' ) ),
			array( 'id' => 'between', 'label' => __( 'between', 'remember' ) ),
		);
		$out['aggregations'] = array(
			array( 'id' => 'count', 'label' => __( 'Count', 'remember' ) ),
			array( 'id' => 'count_distinct', 'label' => __( 'Count distinct', 'remember' ) ),
			array( 'id' => 'sum', 'label' => __( 'Sum', 'remember' ) ),
			array( 'id' => 'min', 'label' => __( 'Min', 'remember' ) ),
			array( 'id' => 'max', 'label' => __( 'Max', 'remember' ) ),
		);
		$out['events'] = self::events_for_picker();
		return $out;
	}

	/**
	 * Events the current user may use as a run-time report scope.
	 *
	 * @return array<int,array{id:int,label:string}>
	 */
	public static function events_for_picker() {
		global $wpdb;
		$p = $wpdb->prefix;
		$sql = "SELECT event_id, event_name, start_date FROM {$p}remember_events";
		$params = array();
		if ( self::is_attendees_only() ) {
			$sql     .= " WHERE event_id IN (SELECT DISTINCT event_id FROM {$p}remember_event_applications WHERE member_id = %d AND status = 'accepted')";
			$params[] = get_current_user_id();
		}
		$sql .= ' ORDER BY start_date DESC, event_name ASC';
		$rows = empty( $params )
			? $wpdb->get_results( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$id = (int) $row->event_id;
			if ( $id < 1 ) {
				continue;
			}
			$label = (string) $row->event_name;
			if ( ! empty( $row->start_date ) ) {
				$ts = strtotime( (string) $row->start_date );
				if ( $ts ) {
					$label .= ' (' . date_i18n( get_option( 'date_format' ), $ts ) . ')';
				}
			}
			$out[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Keep an event id only if the current user may scope to it.
	 *
	 * @param int $event_id Event.
	 * @return int
	 */
	public static function sanitize_event_id( $event_id ) {
		$event_id = absint( $event_id );
		if ( $event_id < 1 ) {
			return 0;
		}
		foreach ( self::events_for_picker() as $event ) {
			if ( (int) $event['id'] === $event_id ) {
				return $event_id;
			}
		}
		return 0;
	}

	/**
	 * Label for a picker event.
	 *
	 * @param int $event_id Event.
	 * @return string
	 */
	public static function event_label( $event_id ) {
		$event_id = absint( $event_id );
		foreach ( self::events_for_picker() as $event ) {
			if ( (int) $event['id'] === $event_id ) {
				return (string) $event['label'];
			}
		}
		return '';
	}

	/**
	 * Default column ids for a subject.
	 *
	 * @param string $subject Subject.
	 * @return string[]
	 */
	public static function default_columns( $subject ) {
		$map = array(
			'members'      => array( 'user.display_name', 'user.user_email', 'member.status', 'profile.member_number' ),
			'applications' => array( 'event.event_name', 'user.display_name', 'role.role_name', 'application.status', 'application.applied_at' ),
			'payments'     => array( 'user.display_name', 'event.event_name', 'payment.total_amount', 'payment.amount_due', 'payment.payment_status' ),
			'vetting'      => array( 'user.display_name', 'vetting.status', 'vetting.decision', 'vetter.display_name', 'vetting.scheduled_at' ),
			'events'       => array( 'event.event_name', 'event.status', 'event.start_date', 'event.end_date', 'location.location_name' ),
			'surveys'      => array( 'event.event_name', 'survey.title', 'role.role_name', 'user.display_name', 'question.label', 'answer.value', 'response.submitted_at' ),
		);
		return isset( $map[ $subject ] ) ? $map[ $subject ] : array();
	}

	/**
	 * Whether the viewer is attendees-only (no full member read).
	 *
	 * @return bool
	 */
	public static function is_attendees_only() {
		return current_user_can( 'remember_read_attendees' ) && ! current_user_can( 'remember_read_members' );
	}

	/**
	 * Built-in fields (before cap strip and custom questions).
	 *
	 * @param string $subject Subject.
	 * @return array<string,array<string,mixed>>
	 */
	public static function raw_fields( $subject ) {
		global $wpdb;
		$p = $wpdb->prefix;

		$member_status = array( 'pending_vetting', 'unvetted', 'in_vetting', 'vetted', 'rejected', 'inactive', 'merged' );
		$app_status    = array( 'pending', 'accepted', 'declined', 'cancelled', 'waitlisted' );
		$pay_status    = array( 'pending', 'partial', 'paid', 'refunded', 'cancelled' );
		$vet_status    = array( 'pending', 'scheduled', 'in_progress', 'completed' );
		$vet_decision  = array( 'pending', 'accepted', 'rejected' );
		$event_status  = array( 'draft', 'open', 'closed', 'completed', 'cancelled' );

		$dietary_sql = "(SELECT GROUP_CONCAT(d.restriction_name ORDER BY d.restriction_name SEPARATOR ', ') FROM {$p}remember_member_dietary_restrictions md INNER JOIN {$p}remember_dietary_restrictions d ON d.restriction_id = md.restriction_id WHERE md.member_id = {MEMBER})";
		$allergy_sql = "(SELECT GROUP_CONCAT(al.allergy_name ORDER BY al.allergy_name SEPARATOR ', ') FROM {$p}remember_member_allergies ma INNER JOIN {$p}remember_allergies al ON al.allergy_id = ma.allergy_id WHERE ma.member_id = {MEMBER})";
		$medical_sql = "(SELECT GROUP_CONCAT(mc.accommodation_name ORDER BY mc.accommodation_name SEPARATOR ', ') FROM {$p}remember_member_medical_accommodations mm INNER JOIN {$p}remember_medical_accommodations mc ON mc.accommodation_id = mm.accommodation_id WHERE mm.member_id = {MEMBER})";
		$roles_sql   = "(SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', ') FROM {$p}remember_member_roles mr INNER JOIN {$p}remember_roles r ON r.role_id = mr.role_id WHERE mr.member_id = {MEMBER})";
		$social_sql  = "(SELECT GROUP_CONCAT(CONCAT(smp.platform_name, ': ', msm.handle) ORDER BY smp.sort_order SEPARATOR ', ') FROM {$p}remember_member_social_media msm LEFT JOIN {$p}remember_social_media_platforms smp ON smp.platform_id = msm.platform_id WHERE msm.member_id = {MEMBER})";

		$dietary_opts = self::health_option_names( 'dietary' );
		$allergy_opts = self::health_option_names( 'allergy' );
		$medical_opts = self::health_option_names( 'medical' );
		$role_opts    = self::role_name_options( false );
		$event_roles  = self::role_name_options( true );
		$im_opts      = self::im_type_options();
		$shirt_opts   = self::clothing_options( 'shirt' );
		$pants_opts   = self::clothing_options( 'pants' );
		$shoe_opts    = self::clothing_options( 'shoe' );
		$loc_opts     = self::location_name_options();

		if ( 'members' === $subject ) {
			$mid = 'm.member_id';
			return self::replace_member_token(
				array(
					'member.member_id'           => self::f( __( 'Member ID', 'remember' ), 'Member', 'number', $mid, null, true ),
					'user.display_name'          => self::f( __( 'Display name', 'remember' ), 'Member', 'string', 'u.display_name', 'user' ),
					'user.user_email'            => self::f( __( 'Email', 'remember' ), 'Member', 'string', 'u.user_email', 'user' ),
					'member.status'              => self::f( __( 'Status', 'remember' ), 'Member', 'enum', 'm.status', null, false, $member_status ),
					'profile.member_number'      => self::f( __( 'Member number', 'remember' ), 'Profile', 'string', 'p.member_number', 'profile' ),
					'profile.legal_first_name'   => self::f( __( 'Legal first name', 'remember' ), 'Profile', 'string', 'p.legal_first_name', 'profile' ),
					'profile.legal_last_name'    => self::f( __( 'Legal last name', 'remember' ), 'Profile', 'string', 'p.legal_last_name', 'profile' ),
					'user.first_name'            => self::f( __( 'First name', 'remember' ), 'Member', 'string', "(SELECT umfn.meta_value FROM {$wpdb->usermeta} umfn WHERE umfn.user_id = u.ID AND umfn.meta_key = 'first_name' LIMIT 1)", 'user' ),
					'user.last_name'             => self::f( __( 'Last name', 'remember' ), 'Member', 'string', "(SELECT umln.meta_value FROM {$wpdb->usermeta} umln WHERE umln.user_id = u.ID AND umln.meta_key = 'last_name' LIMIT 1)", 'user' ),
					'profile.address_city'       => self::f( __( 'City', 'remember' ), 'Location', 'string', 'p.address_city', 'profile' ),
					'profile.address_state'      => self::f( __( 'State', 'remember' ), 'Location', 'string', 'p.address_state', 'profile' ),
					'profile.address_postal'     => self::f( __( 'Postal code', 'remember' ), 'Location', 'string', 'p.address_postal', 'profile' ),
					'profile.address_country'    => self::f( __( 'Country', 'remember' ), 'Location', 'string', 'p.address_country', 'profile' ),
					'profile.cell_phone'         => self::f( __( 'Cell phone', 'remember' ), 'Profile', 'string', 'p.cell_phone', 'profile' ),
					'profile.timezone'           => self::f( __( 'Time zone', 'remember' ), 'Profile', 'string', 'p.timezone', 'profile' ),
					'profile.im_type'            => self::f( __( 'IM type', 'remember' ), 'Profile', 'enum', 'p.im_type', 'profile', false, $im_opts ),
					'profile.im_handle'          => self::f( __( 'IM handle', 'remember' ), 'Profile', 'string', 'p.im_handle', 'profile' ),
					'profile.shirt_size'         => self::f( __( 'Shirt size', 'remember' ), 'Clothing', 'enum', 'p.shirt_size', 'profile', false, $shirt_opts ),
					'profile.pants_size'         => self::f( __( 'Pants size', 'remember' ), 'Clothing', 'enum', 'p.pants_size', 'profile', false, $pants_opts ),
					'profile.shoe_size'          => self::f( __( 'Shoe size', 'remember' ), 'Clothing', 'enum', 'p.shoe_size', 'profile', false, $shoe_opts ),
					'member.roles'               => self::csv_list_field( __( 'Roles', 'remember' ), 'Member', $roles_sql, $role_opts ),
					'member.social'              => self::f( __( 'Social handles', 'remember' ), 'Member', 'string', $social_sql ),
					'member.created_at'          => self::f( __( 'Created', 'remember' ), 'Member', 'datetime', 'm.created_at' ),
					'profile.updated_at'         => self::f( __( 'Profile saved', 'remember' ), 'Profile', 'datetime', 'p.updated_at', 'profile' ),
					'emergency.first'            => self::f( __( 'Emergency contact first', 'remember' ), 'Emergency', 'string', 'p.emergency_contact_first', 'profile', false, array(), 'emergency' ),
					'emergency.last'             => self::f( __( 'Emergency contact last', 'remember' ), 'Emergency', 'string', 'p.emergency_contact_last', 'profile', false, array(), 'emergency' ),
					'emergency.phone'            => self::f( __( 'Emergency contact phone', 'remember' ), 'Emergency', 'string', 'p.emergency_contact_phone', 'profile', false, array(), 'emergency' ),
					'emergency.relationship'     => self::f( __( 'Emergency contact relationship', 'remember' ), 'Emergency', 'string', 'p.emergency_contact_relationship', 'profile', false, array(), 'emergency' ),
					'health.dietary'             => self::csv_list_field( __( 'Dietary restrictions', 'remember' ), 'Health', $dietary_sql, $dietary_opts, 'health' ),
					'health.allergies'           => self::csv_list_field( __( 'Allergies', 'remember' ), 'Health', $allergy_sql, $allergy_opts, 'health' ),
					'health.allergy_reaction'    => self::f( __( 'Allergy reaction', 'remember' ), 'Health', 'string', 'p.allergy_reaction', 'profile', false, array(), 'health' ),
					'health.medical'             => self::csv_list_field( __( 'Medical accommodations', 'remember' ), 'Health', $medical_sql, $medical_opts, 'health' ),
				),
				$mid
			);
		}

		if ( 'applications' === $subject ) {
			$mid = 'a.member_id';
			return self::replace_member_token(
				array(
					'application.application_id' => self::f( __( 'Application ID', 'remember' ), 'Application', 'number', 'a.application_id', null, true ),
					'event.event_name'           => self::f( __( 'Event', 'remember' ), 'Event', 'string', 'e.event_name', 'event' ),
					'event.start_date'           => self::f( __( 'Event start', 'remember' ), 'Event', 'date', 'e.start_date', 'event' ),
					'user.display_name'          => self::f( __( 'Member', 'remember' ), 'Member', 'string', 'u.display_name', 'user' ),
					'user.user_email'            => self::f( __( 'Email', 'remember' ), 'Member', 'string', 'u.user_email', 'user' ),
					'role.role_name'             => self::f( __( 'Event role', 'remember' ), 'Application', 'enum', 'r.role_name', 'role', false, $event_roles ),
					'application.status'         => self::f( __( 'Status', 'remember' ), 'Application', 'enum', 'a.status', null, false, $app_status ),
					'application.applied_at'     => self::f( __( 'Applied', 'remember' ), 'Application', 'datetime', 'a.applied_at' ),
					'application.waitlisted_at'  => self::f( __( 'Waitlisted', 'remember' ), 'Application', 'datetime', 'a.waitlisted_at' ),
					'application.processed_at'   => self::f( __( 'Processed', 'remember' ), 'Application', 'datetime', 'a.processed_at' ),
					'application.ticket_voided'  => self::f( __( 'Ticket voided', 'remember' ), 'Application', 'enum', 'a.ticket_voided', null, false, array( '0', '1' ) ),
					'application.checked_in_at'  => self::f( __( 'Checked in', 'remember' ), 'Application', 'datetime', 'a.checked_in_at' ),
					'application.superseded_at'  => self::f( __( 'Superseded', 'remember' ), 'Application', 'datetime', 'a.superseded_at' ),
					'member.status'              => self::f( __( 'Member status', 'remember' ), 'Member', 'enum', 'm.status', 'member', false, $member_status ),
					'health.dietary'             => self::csv_list_field( __( 'Dietary restrictions', 'remember' ), 'Health', $dietary_sql, $dietary_opts, 'health' ),
					'health.allergies'           => self::csv_list_field( __( 'Allergies', 'remember' ), 'Health', $allergy_sql, $allergy_opts, 'health' ),
					'health.allergy_reaction'    => self::f( __( 'Allergy reaction', 'remember' ), 'Health', 'string', 'p.allergy_reaction', 'profile', false, array(), 'health' ),
					'health.medical'             => self::csv_list_field( __( 'Medical accommodations', 'remember' ), 'Health', $medical_sql, $medical_opts, 'health' ),
				),
				$mid
			);
		}

		if ( 'payments' === $subject ) {
			return array(
				'payment.payment_id'      => self::f( __( 'Payment ID', 'remember' ), 'Payment', 'number', 'pay.payment_id', null, true ),
				'user.display_name'       => self::f( __( 'Member', 'remember' ), 'Member', 'string', 'u.display_name', 'user' ),
				'user.user_email'         => self::f( __( 'Email', 'remember' ), 'Member', 'string', 'u.user_email', 'user' ),
				'event.event_name'        => self::f( __( 'Event', 'remember' ), 'Event', 'string', 'e.event_name', 'event' ),
				'payment.role_cost'       => self::f( __( 'Role cost', 'remember' ), 'Payment', 'number', 'pay.role_cost', null, true ),
				'payment.merchandise_cost'=> self::f( __( 'Merchandise cost', 'remember' ), 'Payment', 'number', 'pay.merchandise_cost', null, true ),
				'payment.total_amount'    => self::f( __( 'Total', 'remember' ), 'Payment', 'number', 'pay.total_amount', null, true ),
				'payment.amount_paid'     => self::f( __( 'Amount paid', 'remember' ), 'Payment', 'number', 'pay.amount_paid', null, true ),
				'payment.amount_due'      => self::f( __( 'Amount due', 'remember' ), 'Payment', 'number', 'pay.amount_due', null, true ),
				'payment.payment_status'  => self::f( __( 'Status', 'remember' ), 'Payment', 'enum', 'pay.payment_status', null, false, $pay_status ),
				'payment.payment_date'    => self::f( __( 'Payment date', 'remember' ), 'Payment', 'datetime', 'pay.payment_date' ),
				'payment.payment_method'  => self::f( __( 'Method', 'remember' ), 'Payment', 'string', 'pay.payment_method' ),
				'payment.xero_invoice_number' => self::f( __( 'Xero invoice #', 'remember' ), 'Payment', 'string', 'pay.xero_invoice_number' ),
				'payment.quickbooks_invoice_number' => self::f( __( 'QuickBooks invoice #', 'remember' ), 'Payment', 'string', 'pay.quickbooks_invoice_number' ),
			);
		}

		if ( 'vetting' === $subject ) {
			return array(
				'vetting.vetting_id'     => self::f( __( 'Case ID', 'remember' ), 'Vetting', 'number', 'v.vetting_id', null, true ),
				'user.display_name'      => self::f( __( 'Member', 'remember' ), 'Member', 'string', 'u.display_name', 'user' ),
				'user.user_email'        => self::f( __( 'Email', 'remember' ), 'Member', 'string', 'u.user_email', 'user' ),
				'member.status'          => self::f( __( 'Member status', 'remember' ), 'Member', 'enum', 'm.status', 'member', false, $member_status ),
				'vetting.status'         => self::f( __( 'Case status', 'remember' ), 'Vetting', 'enum', 'v.status', null, false, $vet_status ),
				'vetting.decision'       => self::f( __( 'Decision', 'remember' ), 'Vetting', 'enum', 'v.decision', null, false, $vet_decision ),
				'vetter.display_name'    => self::f( __( 'Primary vetter', 'remember' ), 'Vetting', 'string', 'vu.display_name', 'vetter' ),
				'vetting.scheduled_at'   => self::f( __( 'Scheduled', 'remember' ), 'Vetting', 'datetime', 'v.scheduled_at' ),
				'vetting.completed_at'   => self::f( __( 'Completed', 'remember' ), 'Vetting', 'datetime', 'v.completed_at' ),
				'vetting.decision_date'  => self::f( __( 'Decision date', 'remember' ), 'Vetting', 'datetime', 'v.decision_date' ),
				'vetting.created_at'     => self::f( __( 'Opened', 'remember' ), 'Vetting', 'datetime', 'v.created_at' ),
			);
		}

		if ( 'surveys' === $subject ) {
			$survey_questions = self::survey_question_catalog();
			$all_roles        = __( 'All roles', 'remember' );
			$question_roles   = $event_roles;
			if ( ! in_array( $all_roles, $question_roles, true ) ) {
				array_unshift( $question_roles, $all_roles );
			}
			$role_field                  = self::csv_list_field( __( "Member's role", 'remember' ), 'Survey', 'member_role_names.role_names', $event_roles );
			$role_field['join']          = 'role';
			$question_role_field         = self::csv_list_field( __( 'Question asked of', 'remember' ), 'Survey', "COALESCE(question_role_names.role_names, '" . esc_sql( $all_roles ) . "')", $question_roles );
			$question_role_field['join'] = 'question_role';
			$answer             = self::f( __( 'Answer', 'remember' ), 'Survey', 'string', 'ans.value_text' );
			$answer['questions'] = $survey_questions['questions'];
			return array(
				'event.event_name'       => self::f( __( 'Event', 'remember' ), 'Event', 'string', 'e.event_name', 'event' ),
				'survey.title'           => self::f( __( 'Survey', 'remember' ), 'Survey', 'string', 'sv.title' ),
				'role.role_name'         => $role_field,
				'survey.placement'       => self::f(
					__( 'Survey kind', 'remember' ),
					'Survey',
					'enum',
					'sv.placement',
					null,
					false,
					array(
						array( 'id' => 'application', 'label' => __( 'Application', 'remember' ) ),
						array( 'id' => 'followup', 'label' => __( 'Follow-on', 'remember' ) ),
					)
				),
				'survey.timing'          => self::f(
					__( 'Survey timing', 'remember' ),
					'Survey',
					'enum',
					'sv.timing',
					null,
					false,
					array(
						array( 'id' => 'before', 'label' => __( 'Before the event', 'remember' ) ),
						array( 'id' => 'after', 'label' => __( 'After the event', 'remember' ) ),
					)
				),
				'user.display_name'      => self::f( __( 'Member', 'remember' ), 'Member', 'string', 'u.display_name', 'user' ),
				'user.user_email'        => self::f( __( 'Email', 'remember' ), 'Member', 'string', 'u.user_email', 'user' ),
				'question.label'         => self::f( __( 'Question', 'remember' ), 'Survey', 'enum', 'sq.label', null, false, $survey_questions['options'] ),
				'question.roles'         => $question_role_field,
				'answer.value'           => $answer,
				'response.submitted_at'  => self::f( __( 'Submitted', 'remember' ), 'Survey', 'date', 'resp.submitted_at' ),
			);
		}

		if ( 'events' === $subject ) {
			return array(
				'event.event_id'         => self::f( __( 'Event ID', 'remember' ), 'Event', 'number', 'e.event_id', null, true ),
				'event.event_name'       => self::f( __( 'Name', 'remember' ), 'Event', 'string', 'e.event_name' ),
				'event.status'           => self::f( __( 'Status', 'remember' ), 'Event', 'enum', 'e.status', null, false, $event_status ),
				'event.start_date'       => self::f( __( 'Start', 'remember' ), 'Event', 'date', 'e.start_date' ),
				'event.end_date'         => self::f( __( 'End', 'remember' ), 'Event', 'date', 'e.end_date' ),
				'event.is_private'       => self::f( __( 'Private', 'remember' ), 'Event', 'enum', 'e.is_private', null, false, array( '0', '1' ) ),
				'location.location_name' => self::f( __( 'Location', 'remember' ), 'Event', 'enum', 'loc.location_name', 'location', false, $loc_opts ),
				'event.created_at'       => self::f( __( 'Created', 'remember' ), 'Event', 'datetime', 'e.created_at' ),
			);
		}

		return array();
	}

	/**
	 * Custom profile questions as report fields.
	 *
	 * @param string $subject members|applications.
	 * @return array<string,array<string,mixed>>
	 */
	public static function custom_question_fields( $subject ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-profile-question.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		$model = new Remember_Profile_Question();
		$rows  = $model->get_all_ordered();
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$member_expr = 'members' === $subject ? 'm.member_id' : 'a.member_id';
		$out         = array();
		foreach ( $rows as $q ) {
			$qid = (int) $q->question_id;
			if ( $qid < 1 ) {
				continue;
			}
			$id      = 'pq_' . $qid;
			$join    = 'pq_' . $qid;
			$type    = 'string';
			$options = array();
			$list    = false;
			$qtype   = isset( $q->field_type ) ? (string) $q->field_type : 'text';
			if ( 'select' === $qtype ) {
				$type    = 'enum';
				$options = self::question_choice_options( $q->options_json );
			} elseif ( 'multiselect' === $qtype ) {
				$type    = 'multiselect';
				$list    = true;
				$options = self::question_choice_options( $q->options_json );
			}
			$out[ $id ] = self::f(
				(string) $q->label,
				__( 'Custom fields', 'remember' ),
				$type,
				$join . '.value_text',
				$join,
				false,
				$options
			);
			$out[ $id ]['question_id'] = $qid;
			$out[ $id ]['member_expr'] = $member_expr;
			$out[ $id ]['list']        = $list;
		}
		return $out;
	}

	/**
	 * Survey questions as filter choices, and the type each label uses for answer filters.
	 *
	 * A shared label keeps one question option. If those questions do not share a type,
	 * the answer filter stays free text.
	 *
	 * @return array{options:array<int,array{id:string,label:string}>,questions:array<int,array<string,mixed>>}
	 */
	private static function survey_question_catalog() {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results(
			"SELECT sq.label, sq.field_type, sq.options_text, sv.title
			FROM {$p}remember_survey_questions sq
			INNER JOIN {$p}remember_surveys sv ON sv.survey_id = sq.survey_id
			ORDER BY sv.title ASC, sq.sort_order ASC, sq.question_id ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
		$grouped = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$label = isset( $row->label ) ? trim( (string) $row->label ) : '';
				if ( '' === $label ) {
					continue;
				}
				if ( ! isset( $grouped[ $label ] ) ) {
					$grouped[ $label ] = array(
						'type'    => '',
						'options' => array(),
						'titles'  => array(),
						'mixed'   => false,
					);
				}
				$title = isset( $row->title ) ? trim( (string) $row->title ) : '';
				if ( '' !== $title && ! in_array( $title, $grouped[ $label ]['titles'], true ) ) {
					$grouped[ $label ]['titles'][] = $title;
				}
				$shaped = self::survey_answer_shape( isset( $row->field_type ) ? (string) $row->field_type : 'text', isset( $row->options_text ) ? $row->options_text : '' );
				if ( '' === $grouped[ $label ]['type'] ) {
					$grouped[ $label ]['type']    = $shaped['type'];
					$grouped[ $label ]['options'] = $shaped['options'];
				} elseif ( $grouped[ $label ]['type'] !== $shaped['type'] || $grouped[ $label ]['options'] !== $shaped['options'] ) {
					$grouped[ $label ]['mixed']   = true;
					$grouped[ $label ]['type']    = 'string';
					$grouped[ $label ]['options'] = array();
				}
			}
		}
		$options   = array();
		$questions = array();
		foreach ( $grouped as $label => $row ) {
			$display = $label;
			if ( count( $row['titles'] ) > 1 ) {
				$display = $label . ' (' . implode( ', ', $row['titles'] ) . ')';
			} elseif ( 1 === count( $row['titles'] ) ) {
				$display = $row['titles'][0] . ' — ' . $label;
			}
			$options[]   = array(
				'id'    => $label,
				'label' => $display,
			);
			$questions[] = array(
				'label'   => $label,
				'type'    => $row['mixed'] ? 'string' : $row['type'],
				'options' => $row['mixed'] ? array() : $row['options'],
			);
		}
		return array(
			'options'   => $options,
			'questions' => $questions,
		);
	}

	/**
	 * Map a survey question type onto the report filter type.
	 *
	 * @param string $field_type   Question type.
	 * @param mixed  $options_text Stored choices.
	 * @return array{type:string,options:array<int,array{id:string,label:string}>}
	 */
	private static function survey_answer_shape( $field_type, $options_text ) {
		$field_type = sanitize_key( $field_type );
		if ( 'boolean' === $field_type ) {
			return array(
				'type'    => 'enum',
				'options' => array(
					array( 'id' => 'yes', 'label' => __( 'Yes', 'remember' ) ),
					array( 'id' => 'no', 'label' => __( 'No', 'remember' ) ),
				),
			);
		}
		if ( 'select' === $field_type ) {
			return array(
				'type'    => 'enum',
				'options' => self::question_choice_options( $options_text ),
			);
		}
		if ( 'multiselect' === $field_type ) {
			return array(
				'type'    => 'multiselect',
				'options' => self::question_choice_options( $options_text ),
			);
		}
		return array(
			'type'    => 'string',
			'options' => array(),
		);
	}

	/**
	 * Answer field shaped like the question a sibling filter has pinned.
	 *
	 * @param array $field   Answer catalog field.
	 * @param array $filters All filters on the report.
	 * @return array<string,mixed>|null
	 */
	public static function answer_filter_field( $field, $filters ) {
		if ( empty( $field['questions'] ) || ! is_array( $field['questions'] ) || ! is_array( $filters ) ) {
			return null;
		}
		$label = '';
		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) || empty( $filter['field'] ) || 'question.label' !== (string) $filter['field'] ) {
				continue;
			}
			$op = isset( $filter['op'] ) ? sanitize_key( $filter['op'] ) : '';
			if ( ! in_array( $op, array( 'eq', 'in' ), true ) ) {
				continue;
			}
			$raw = isset( $filter['value'] ) ? $filter['value'] : '';
			$values = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$values = array_values( array_filter( array_map( 'strval', $values ), 'strlen' ) );
			if ( 1 !== count( $values ) ) {
				continue;
			}
			if ( '' !== $label && $label !== $values[0] ) {
				return null;
			}
			$label = $values[0];
		}
		if ( '' === $label ) {
			return null;
		}
		foreach ( $field['questions'] as $question ) {
			if ( ! is_array( $question ) || ! isset( $question['label'] ) || (string) $question['label'] !== $label ) {
				continue;
			}
			$type = isset( $question['type'] ) ? (string) $question['type'] : 'string';
			if ( 'enum' !== $type && 'multiselect' !== $type ) {
				return null;
			}
			$copy             = $field;
			$copy['type']     = $type;
			$copy['options']  = isset( $question['options'] ) && is_array( $question['options'] ) ? $question['options'] : array();
			$copy['list']     = ( 'multiselect' === $type );
			if ( 'multiselect' === $type ) {
				$copy['list_match'] = 'pipe';
			}
			return $copy;
		}
		return null;
	}

	/**
	 * Custom-question choices as {id,label} for the filter UI.
	 *
	 * @param mixed $json Options JSON.
	 * @return array<int,array{id:string,label:string}>
	 */
	private static function question_choice_options( $json ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		$out = array();
		foreach ( Remember_Profile_Questions::parse_options( $json ) as $row ) {
			if ( empty( $row['key'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $row['key'],
				'label' => ! empty( $row['label'] ) ? (string) $row['label'] : (string) $row['key'],
			);
		}
		return $out;
	}

	/**
	 * Multi-value field stored as comma-separated names (dietary, allergies, roles).
	 *
	 * @param string      $label     Label.
	 * @param string      $group     Group.
	 * @param string      $sql       Expression.
	 * @param array       $options   Choice list.
	 * @param string|null $sensitive emergency|health.
	 * @return array<string,mixed>
	 */
	private static function csv_list_field( $label, $group, $sql, $options, $sensitive = null ) {
		$field               = self::f( $label, $group, 'multiselect', $sql, null, false, $options, $sensitive );
		$field['list']       = true;
		$field['list_match'] = 'csv';
		return $field;
	}

	/**
	 * Active health-catalog names for filter dropdowns.
	 *
	 * @param string $kind dietary|allergy|medical.
	 * @return string[]
	 */
	private static function health_option_names( $kind ) {
		global $wpdb;
		$map = array(
			'dietary' => array( 'remember_dietary_restrictions', 'restriction_name' ),
			'allergy' => array( 'remember_allergies', 'allergy_name' ),
			'medical' => array( 'remember_medical_accommodations', 'accommodation_name' ),
		);
		if ( ! isset( $map[ $kind ] ) ) {
			return array();
		}
		$table = $wpdb->prefix . $map[ $kind ][0];
		$col   = $map[ $kind ][1];
		$names = $wpdb->get_col(
			"SELECT {$col} FROM {$table} WHERE is_active = 1 ORDER BY CASE WHEN {$col} = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, {$col} ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column from a fixed map.
		return is_array( $names ) ? array_values( array_filter( array_map( 'strval', $names ), 'strlen' ) ) : array();
	}

	/**
	 * Role names for filter dropdowns.
	 *
	 * @param bool $event_only Event roles only.
	 * @return string[]
	 */
	private static function role_name_options( $event_only ) {
		global $wpdb;
		$sql = $event_only
			? "SELECT role_name FROM {$wpdb->prefix}remember_roles WHERE is_event_role = 1 ORDER BY role_name ASC"
			: "SELECT role_name FROM {$wpdb->prefix}remember_roles ORDER BY role_name ASC";
		$names = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
		return is_array( $names ) ? array_values( array_filter( array_map( 'strval', $names ), 'strlen' ) ) : array();
	}

	/**
	 * Location names for filter dropdowns.
	 *
	 * @return string[]
	 */
	private static function location_name_options() {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT location_name FROM {$wpdb->prefix}remember_locations ORDER BY location_name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
		return is_array( $names ) ? array_values( array_filter( array_map( 'strval', $names ), 'strlen' ) ) : array();
	}

	/**
	 * IM platform keys and labels.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	private static function im_type_options() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-im-platforms.php';
		$out = array();
		foreach ( Remember_Im_Platforms::get_active() as $row ) {
			$key = isset( $row->platform_key ) ? (string) $row->platform_key : '';
			if ( '' === $key ) {
				continue;
			}
			$label = isset( $row->platform_name ) && $row->platform_name ? (string) $row->platform_name : $key;
			$out[] = array(
				'id'    => $key,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Clothing size codes with available-size labels.
	 *
	 * @param string $category shirt|pants|shoe.
	 * @return array<int,array{id:string,label:string}>
	 */
	private static function clothing_options( $category ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-clothing-sizes.php';
		$out = array();
		foreach ( Remember_Clothing_Sizes::options_for( $category ) as $code ) {
			$code = (string) $code;
			if ( '' === $code ) {
				continue;
			}
			$label = Remember_Clothing_Sizes::format_pair( $category, $code );
			$out[] = array(
				'id'    => $code,
				'label' => $label ? $label : $code,
			);
		}
		return $out;
	}

	/**
	 * Field array helper.
	 *
	 * @param string      $label     Label.
	 * @param string      $group     Group.
	 * @param string      $type      Type.
	 * @param string      $sql       Expression.
	 * @param string|null $join      Join key.
	 * @param bool        $measure   Numeric measure.
	 * @param array       $options   Enum options.
	 * @param string|null $sensitive emergency|health.
	 * @return array<string,mixed>
	 */
	private static function f( $label, $group, $type, $sql, $join = null, $measure = false, $options = array(), $sensitive = null ) {
		$field = array(
			'label'    => $label,
			'group'    => $group,
			'type'     => $type,
			'sql'      => $sql,
			'join'     => $join,
			'measure'  => $measure,
			'options'  => $options,
		);
		if ( $sensitive ) {
			$field['sensitive'] = $sensitive;
		}
		return $field;
	}

	/**
	 * Swap {MEMBER} in subquery SQL for the grain member expression.
	 *
	 * @param array  $fields Fields.
	 * @param string $member Member SQL.
	 * @return array
	 */
	private static function replace_member_token( $fields, $member ) {
		foreach ( $fields as $id => $field ) {
			$fields[ $id ]['sql'] = str_replace( '{MEMBER}', $member, $field['sql'] );
		}
		return $fields;
	}
}
