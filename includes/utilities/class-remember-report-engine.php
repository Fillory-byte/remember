<?php
/**
 * Compile a report definition into parameterized SQL.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Cap-aware report runner.
 */
class Remember_Report_Engine {

	const PAGE_SIZE  = 50;
	const MAX_EXPORT = 10000;

	/**
	 * Run a report definition.
	 *
	 * @param array $definition Builder JSON.
	 * @param int   $page       1-based page.
	 * @param int   $per_page   Page size.
	 * @param bool  $export     Full export (capped).
	 * @param int   $event_id   Optional run-time event scope.
	 * @return array|\WP_Error
	 */
	public static function run( $definition, $page = 1, $per_page = self::PAGE_SIZE, $export = false, $event_id = 0 ) {
		global $wpdb;

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';

		$event_id = Remember_Report_Catalog::sanitize_event_id( $event_id );
		$compiled = self::compile( $definition, $event_id );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}

		$count_sql = $compiled['count_sql'];
		$count     = empty( $compiled['count_params'] )
			? (int) $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$compiled['count_params'] ) );

		$page     = max( 1, absint( $page ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql    = $compiled['sql'];
		$params = $compiled['params'];
		if ( $export ) {
			$sql     .= ' LIMIT %d';
			$params[] = self::MAX_EXPORT;
		} else {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = $offset;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$out_rows = array();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $compiled['select_ids'] as $id ) {
				$key          = self::alias( $id );
				$line[ $id ] = self::format_list_cell( isset( $row[ $key ] ) ? $row[ $key ] : '' );
			}
			$out_rows[] = $line;
		}

		return array(
			'columns' => $compiled['columns'],
			'rows'    => $out_rows,
			'total'   => $count,
			'page'    => $page,
			'pages'   => $export ? 1 : (int) max( 1, ceil( $count / $per_page ) ),
			'mode'    => $compiled['mode'],
			'subject' => $compiled['subject'],
			'event_id'    => $event_id,
			'event_label' => $event_id ? Remember_Report_Catalog::event_label( $event_id ) : '',
		);
	}

	/**
	 * Compile definition to SQL fragments.
	 *
	 * @param array $definition Definition.
	 * @param int   $event_id   Optional run-time event scope.
	 * @return array|\WP_Error
	 */
	public static function compile( $definition, $event_id = 0 ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-report-catalog.php';
		if ( ! is_array( $definition ) ) {
			return new WP_Error( 'invalid', __( 'Invalid report.', 'remember' ) );
		}
		$event_id = absint( $event_id );
		$subject = isset( $definition['subject'] ) ? sanitize_key( $definition['subject'] ) : '';
		$fields  = Remember_Report_Catalog::fields_for_current_user( $subject );
		if ( empty( $fields ) ) {
			return new WP_Error( 'no_subject', __( 'You cannot report on that data.', 'remember' ) );
		}

		$group_by = self::sanitize_id_list( isset( $definition['group_by'] ) ? $definition['group_by'] : array(), $fields );
		$mode     = ( ! empty( $group_by ) || ( isset( $definition['mode'] ) && 'summary' === $definition['mode'] ) ) ? 'summary' : 'detail';

		$aggregations = array();
		if ( isset( $definition['aggregations'] ) && is_array( $definition['aggregations'] ) ) {
			foreach ( $definition['aggregations'] as $agg ) {
				if ( ! is_array( $agg ) ) {
					continue;
				}
				$fn = isset( $agg['fn'] ) ? sanitize_key( $agg['fn'] ) : '';
				if ( ! in_array( $fn, array( 'count', 'count_distinct', 'sum', 'min', 'max' ), true ) ) {
					continue;
				}
				$fid = isset( $agg['field'] ) ? (string) $agg['field'] : '';
				if ( 'count' === $fn && ( '' === $fid || '*' === $fid ) ) {
					$aggregations[] = array(
						'fn'    => 'count',
						'field' => '*',
						'label' => __( 'Count', 'remember' ),
						'sql'   => 'COUNT(*)',
					);
					continue;
				}
				if ( ! isset( $fields[ $fid ] ) ) {
					continue;
				}
				if ( in_array( $fn, array( 'sum' ), true ) && empty( $fields[ $fid ]['measure'] ) ) {
					continue;
				}
				$aggregations[] = array(
					'fn'    => $fn,
					'field' => $fid,
					'label' => self::agg_label( $fn, $fields[ $fid ]['label'] ),
					'sql'   => self::agg_sql( $fn, $fields[ $fid ]['sql'] ),
				);
			}
		}

		if ( 'summary' === $mode && empty( $group_by ) && empty( $aggregations ) ) {
			$aggregations[] = array(
				'fn'    => 'count',
				'field' => '*',
				'label' => __( 'Count', 'remember' ),
				'sql'   => 'COUNT(*)',
			);
		}

		$columns = self::sanitize_id_list( isset( $definition['columns'] ) ? $definition['columns'] : array(), $fields );
		if ( 'summary' === $mode ) {
			$columns = $group_by;
		} elseif ( empty( $columns ) ) {
			$columns = array();
			foreach ( Remember_Report_Catalog::default_columns( $subject ) as $id ) {
				if ( isset( $fields[ $id ] ) ) {
					$columns[] = $id;
				}
			}
		}
		if ( 'detail' === $mode && empty( $columns ) ) {
			return new WP_Error( 'no_columns', __( 'Pick at least one column.', 'remember' ) );
		}

		$filters = isset( $definition['filters'] ) && is_array( $definition['filters'] ) ? $definition['filters'] : array();
		$sort    = isset( $definition['sort'] ) && is_array( $definition['sort'] ) ? $definition['sort'] : array();

		$needed = array_merge( $columns, $group_by );
		foreach ( $aggregations as $agg ) {
			if ( '*' !== $agg['field'] && isset( $fields[ $agg['field'] ] ) ) {
				$needed[] = $agg['field'];
			}
		}
		foreach ( $filters as $filter ) {
			if ( is_array( $filter ) && ! empty( $filter['field'] ) ) {
				$needed[] = (string) $filter['field'];
			}
		}
		if ( ! empty( $sort['field'] ) ) {
			$needed[] = (string) $sort['field'];
		}

		$joins   = self::collect_joins( $subject, $fields, $needed );
		$from    = self::from_sql( $subject );
		$where   = array( '1=1' );
		$params  = array();
		$scope   = self::scope_sql( $subject, $event_id );
		if ( $scope['sql'] ) {
			$where[] = $scope['sql'];
			$params  = array_merge( $params, $scope['params'] );
		}

		$filters = array_values( $filters );
		$logic   = self::filter_logic_tree( $filters );
		if ( is_wp_error( $logic ) ) {
			return $logic;
		}
		$clauses = array();
		foreach ( $filters as $index => $filter ) {
			$clause_fields = $fields;
			if ( is_array( $filter ) && isset( $filter['field'] ) && 'answer.value' === (string) $filter['field'] && isset( $fields['answer.value'] ) ) {
				$shaped = Remember_Report_Catalog::answer_filter_field( $fields['answer.value'], $filters );
				if ( is_array( $shaped ) ) {
					$clause_fields['answer.value'] = $shaped;
				}
			}
			$clause = self::filter_clause( $filter, $clause_fields );
			if ( $clause && ! is_wp_error( $clause ) ) {
				$clauses[ $index ] = $clause;
			}
		}
		$filter_where = $logic ? self::logic_sql( $logic, $clauses ) : null;
		if ( $filter_where ) {
			$where[] = $filter_where['sql'];
			$params  = array_merge( $params, $filter_where['params'] );
		}

		$where_sql = ' WHERE ' . implode( ' AND ', $where );

		$select_ids = array();
		$select_sql = array();
		$headers    = array();
		if ( 'summary' === $mode ) {
			foreach ( $group_by as $id ) {
				$select_ids[] = $id;
				$select_sql[] = $fields[ $id ]['sql'] . ' AS `' . self::alias( $id ) . '`';
				$headers[]    = array( 'id' => $id, 'label' => $fields[ $id ]['label'] );
			}
			$i = 0;
			foreach ( $aggregations as $agg ) {
				++$i;
				$aid          = 'agg_' . $i;
				$select_ids[] = $aid;
				$select_sql[] = $agg['sql'] . ' AS `' . self::alias( $aid ) . '`';
				$headers[]    = array( 'id' => $aid, 'label' => $agg['label'] );
			}
			if ( empty( $select_sql ) ) {
				return new WP_Error( 'no_columns', __( 'Pick a grouping or a count.', 'remember' ) );
			}
		} else {
			foreach ( $columns as $id ) {
				$select_ids[] = $id;
				$select_sql[] = $fields[ $id ]['sql'] . ' AS `' . self::alias( $id ) . '`';
				$headers[]    = array( 'id' => $id, 'label' => $fields[ $id ]['label'] );
			}
		}

		$sql = 'SELECT ' . implode( ', ', $select_sql ) . ' ' . $from . ' ' . $joins . $where_sql;

		if ( 'summary' === $mode && ! empty( $group_by ) ) {
			$gb = array();
			foreach ( $group_by as $id ) {
				$gb[] = $fields[ $id ]['sql'];
			}
			$sql .= ' GROUP BY ' . implode( ', ', $gb );
		}

		$order_id = isset( $sort['field'] ) ? (string) $sort['field'] : '';
		$dir      = ( isset( $sort['dir'] ) && 'desc' === strtolower( (string) $sort['dir'] ) ) ? 'DESC' : 'ASC';
		if ( 'summary' === $mode && isset( $fields[ $order_id ] ) && in_array( $order_id, $group_by, true ) ) {
			$sql .= ' ORDER BY ' . $fields[ $order_id ]['sql'] . ' ' . $dir;
		} elseif ( 'summary' !== $mode && isset( $fields[ $order_id ] ) ) {
			$sql .= ' ORDER BY ' . $fields[ $order_id ]['sql'] . ' ' . $dir;
		} elseif ( 'summary' === $mode && ! empty( $aggregations ) ) {
			$sql .= ' ORDER BY `' . self::alias( 'agg_1' ) . '` DESC';
		} elseif ( ! empty( $select_ids ) ) {
			$sql .= ' ORDER BY `' . self::alias( $select_ids[0] ) . '` ASC';
		}

		$count_sql    = 'SELECT COUNT(*) FROM (SELECT 1 ' . $from . ' ' . $joins . $where_sql;
		$count_params = $params;
		if ( 'summary' === $mode && empty( $group_by ) ) {
			$count_sql    = 'SELECT 1';
			$count_params = array();
		} elseif ( 'summary' === $mode && ! empty( $group_by ) ) {
			$gb = array();
			foreach ( $group_by as $id ) {
				$gb[] = $fields[ $id ]['sql'];
			}
			$count_sql .= ' GROUP BY ' . implode( ', ', $gb );
			$count_sql .= ') remember_report_count';
		} else {
			$count_sql .= ') remember_report_count';
		}

		return array(
			'sql'          => $sql,
			'params'       => $params,
			'count_sql'    => $count_sql,
			'count_params' => $count_params,
			'columns'     => $headers,
			'select_ids'  => $select_ids,
			'mode'        => $mode,
			'subject'     => $subject,
		);
	}

	/**
	 * FROM clause for a subject.
	 *
	 * @param string $subject Subject.
	 * @return string
	 */
	private static function from_sql( $subject ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$map = array(
			'members'      => "FROM {$p}remember_members m",
			'applications' => "FROM {$p}remember_event_applications a",
			'payments'     => "FROM {$p}remember_payments pay",
			'vetting'      => "FROM {$p}remember_vetting v",
			'events'       => "FROM {$p}remember_events e",
			'surveys'      => "FROM {$p}remember_survey_answers ans INNER JOIN {$p}remember_survey_responses resp ON resp.response_id = ans.response_id INNER JOIN {$p}remember_surveys sv ON sv.survey_id = resp.survey_id INNER JOIN {$p}remember_survey_questions sq ON sq.question_id = ans.question_id",
		);
		return isset( $map[ $subject ] ) ? $map[ $subject ] : $map['members'];
	}

	/**
	 * Join SQL for needed keys.
	 *
	 * @param string $subject Subject.
	 * @param array  $fields  Catalog.
	 * @param array  $needed  Field ids.
	 * @return string
	 */
	private static function collect_joins( $subject, $fields, $needed ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$users = $wpdb->users;
		$keys  = array();
		foreach ( array_unique( $needed ) as $id ) {
			if ( isset( $fields[ $id ]['join'] ) && $fields[ $id ]['join'] ) {
				$keys[] = $fields[ $id ]['join'];
			}
		}
		$keys = array_unique( $keys );
		if ( in_array( $subject, array( 'members', 'applications', 'payments', 'vetting' ), true ) ) {
			$keys[] = 'user';
			$keys   = array_unique( $keys );
		}
		$sql  = '';

		$built = array(
			'members'      => array(
				'user'    => "INNER JOIN {$users} u ON u.ID = m.member_id",
				'profile' => "LEFT JOIN {$p}remember_member_profiles p ON p.member_id = m.member_id",
			),
			'applications' => array(
				'user'    => "INNER JOIN {$users} u ON u.ID = a.member_id",
				'member'  => "LEFT JOIN {$p}remember_members m ON m.member_id = a.member_id",
				'event'   => "LEFT JOIN {$p}remember_events e ON e.event_id = a.event_id",
				'role'    => "LEFT JOIN {$p}remember_event_roles er ON er.event_role_id = a.event_role_id LEFT JOIN {$p}remember_roles r ON r.role_id = er.role_id",
				'profile' => "LEFT JOIN {$p}remember_member_profiles p ON p.member_id = a.member_id",
			),
			'payments'     => array(
				'user'  => "INNER JOIN {$users} u ON u.ID = pay.member_id",
				'event' => "LEFT JOIN {$p}remember_event_applications a ON a.application_id = pay.event_application_id LEFT JOIN {$p}remember_events e ON e.event_id = a.event_id",
			),
			'vetting'      => array(
				'user'   => "INNER JOIN {$users} u ON u.ID = v.member_id",
				'member' => "LEFT JOIN {$p}remember_members m ON m.member_id = v.member_id",
				'vetter' => "LEFT JOIN {$users} vu ON vu.ID = v.primary_vetter_id",
			),
			'events'       => array(
				'location' => "LEFT JOIN {$p}remember_locations loc ON loc.location_id = e.location_id",
			),
			'surveys'      => array(
				'user'          => "INNER JOIN {$users} u ON u.ID = resp.member_id",
				'event'         => "LEFT JOIN {$p}remember_events e ON e.event_id = sv.event_id",
				'role'          => "LEFT JOIN (SELECT ra.event_id, ra.member_id, GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS role_names FROM {$p}remember_event_applications ra INNER JOIN {$p}remember_event_roles er ON er.event_role_id = ra.event_role_id INNER JOIN {$p}remember_roles r ON r.role_id = er.role_id WHERE ra.superseded_at IS NULL OR ra.superseded_at = '0000-00-00 00:00:00' GROUP BY ra.event_id, ra.member_id) member_role_names ON member_role_names.event_id = resp.event_id AND member_role_names.member_id = resp.member_id",
				'question_role' => "LEFT JOIN (SELECT qr.question_id, GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS role_names FROM {$p}remember_survey_question_roles qr INNER JOIN {$p}remember_event_roles er ON er.event_role_id = qr.event_role_id INNER JOIN {$p}remember_roles r ON r.role_id = er.role_id GROUP BY qr.question_id) question_role_names ON question_role_names.question_id = sq.question_id",
			),
		);

		$set = isset( $built[ $subject ] ) ? $built[ $subject ] : array();
		foreach ( $keys as $key ) {
			if ( isset( $set[ $key ] ) ) {
				$sql .= ' ' . $set[ $key ];
				continue;
			}
			if ( preg_match( '/^pq_\d+$/', $key ) && isset( $fields[ $key ]['question_id'] ) ) {
				$qid   = (int) $fields[ $key ]['question_id'];
				$mexp  = isset( $fields[ $key ]['member_expr'] ) ? $fields[ $key ]['member_expr'] : 'm.member_id';
				if ( ! in_array( $mexp, array( 'm.member_id', 'a.member_id' ), true ) ) {
					$mexp = 'm.member_id';
				}
				$alias = $key;
				$join  = $wpdb->prepare(
					" LEFT JOIN {$p}remember_profile_question_responses {$alias} ON {$alias}.member_id = {$mexp} AND {$alias}.question_id = %d",
					$qid
				);
				if ( is_string( $join ) && '' !== $join ) {
					$sql .= $join;
				}
			}
		}
		return $sql;
	}

	/**
	 * Row-scope SQL (merged exclusion, attendees-only, optional event).
	 *
	 * @param string $subject  Subject.
	 * @param int    $event_id Event scope.
	 * @return array{sql:string,params:array}
	 */
	private static function scope_sql( $subject, $event_id = 0 ) {
		global $wpdb;
		$p      = $wpdb->prefix;
		$sql    = '';
		$params = array();
		$event_id = absint( $event_id );

		if ( 'members' === $subject ) {
			$sql = "(m.status IS NULL OR m.status != 'merged')";
		}

		if ( $event_id > 0 ) {
			$event_scope = self::event_scope_sql( $subject, $event_id );
			if ( $event_scope['sql'] ) {
				$sql      = $sql ? $sql . ' AND ' . $event_scope['sql'] : $event_scope['sql'];
				$params   = array_merge( $params, $event_scope['params'] );
			}
		}

		if ( ! Remember_Report_Catalog::is_attendees_only() ) {
			return array( 'sql' => $sql, 'params' => $params );
		}

		$uid = get_current_user_id();
		$att = "EXISTS (
			SELECT 1 FROM {$p}remember_event_applications g
			INNER JOIN {$p}remember_event_applications t ON g.event_id = t.event_id
			WHERE g.member_id = %d AND g.status = 'accepted'
			AND t.status = 'accepted' AND t.member_id != %d AND t.member_id = {T}
		)";

		if ( 'members' === $subject ) {
			$extra    = str_replace( '{T}', 'm.member_id', $att );
			$sql      = $sql ? $sql . ' AND ' . $extra : $extra;
			$params[] = $uid;
			$params[] = $uid;
		} elseif ( 'applications' === $subject ) {
			$extra    = str_replace( '{T}', 'a.member_id', $att );
			$sql      = $sql ? $sql . ' AND ' . $extra : $extra;
			$params[] = $uid;
			$params[] = $uid;
		} elseif ( 'payments' === $subject ) {
			$extra    = str_replace( '{T}', 'pay.member_id', $att );
			$sql      = $sql ? $sql . ' AND ' . $extra : $extra;
			$params[] = $uid;
			$params[] = $uid;
		} elseif ( 'vetting' === $subject ) {
			$extra    = str_replace( '{T}', 'v.member_id', $att );
			$sql      = $sql ? $sql . ' AND ' . $extra : $extra;
			$params[] = $uid;
			$params[] = $uid;
		} elseif ( 'events' === $subject ) {
			$sql      = "e.event_id IN (SELECT DISTINCT event_id FROM {$p}remember_event_applications WHERE member_id = %d AND status = 'accepted')";
			$params[] = $uid;
		} elseif ( 'surveys' === $subject ) {
			$extra    = str_replace( '{T}', 'resp.member_id', $att );
			$sql      = $sql ? $sql . ' AND ' . $extra : $extra;
			$params[] = $uid;
			$params[] = $uid;
		}

		return array( 'sql' => $sql, 'params' => $params );
	}

	/**
	 * Event grain for a subject.
	 *
	 * Members and vetting: accepted, current applications.
	 * Applications and payments: that event, any application status.
	 *
	 * @param string $subject  Subject.
	 * @param int    $event_id Event.
	 * @return array{sql:string,params:array}
	 */
	private static function event_scope_sql( $subject, $event_id ) {
		global $wpdb;
		$p        = $wpdb->prefix;
		$event_id = absint( $event_id );
		$accepted = "ea.status = 'accepted' AND (ea.superseded_at IS NULL OR ea.superseded_at = '0000-00-00 00:00:00')";

		if ( 'members' === $subject ) {
			return array(
				'sql'    => "EXISTS (SELECT 1 FROM {$p}remember_event_applications ea WHERE ea.member_id = m.member_id AND ea.event_id = %d AND {$accepted})",
				'params' => array( $event_id ),
			);
		}
		if ( 'vetting' === $subject ) {
			return array(
				'sql'    => "EXISTS (SELECT 1 FROM {$p}remember_event_applications ea WHERE ea.member_id = v.member_id AND ea.event_id = %d AND {$accepted})",
				'params' => array( $event_id ),
			);
		}
		if ( 'applications' === $subject ) {
			return array(
				'sql'    => 'a.event_id = %d',
				'params' => array( $event_id ),
			);
		}
		if ( 'payments' === $subject ) {
			return array(
				'sql'    => "EXISTS (SELECT 1 FROM {$p}remember_event_applications ea WHERE ea.application_id = pay.event_application_id AND ea.event_id = %d)",
				'params' => array( $event_id ),
			);
		}
		if ( 'events' === $subject ) {
			return array(
				'sql'    => 'e.event_id = %d',
				'params' => array( $event_id ),
			);
		}
		if ( 'surveys' === $subject ) {
			return array(
				'sql'    => 'sv.event_id = %d',
				'params' => array( $event_id ),
			);
		}
		return array( 'sql' => '', 'params' => array() );
	}

	/**
	 * Check a definition's filter grouping without running it.
	 *
	 * @param array $definition Report definition.
	 * @return true|\WP_Error
	 */
	public static function validate_filter_logic( $definition ) {
		if ( ! is_array( $definition ) ) {
			return true;
		}
		$filters = isset( $definition['filters'] ) && is_array( $definition['filters'] ) ? array_values( $definition['filters'] ) : array();
		$tree    = self::filter_logic_tree( $filters );
		return is_wp_error( $tree ) ? $tree : true;
	}

	/**
	 * Parse each filter's AND/OR choice and parentheses into a tree of filter positions.
	 *
	 * Filters without those settings are all required. AND and OR may not meet in one
	 * group without parentheses, so the result never depends on which one binds first.
	 *
	 * @param array $filters Filters in order.
	 * @return array|null|\WP_Error Tree, null when there are no filters, or an error.
	 */
	private static function filter_logic_tree( $filters ) {
		if ( empty( $filters ) ) {
			return null;
		}
		$tokens = array();
		foreach ( array_values( $filters ) as $index => $filter ) {
			$filter = is_array( $filter ) ? $filter : array();
			if ( $index > 0 ) {
				$join     = isset( $filter['join'] ) && 'or' === strtolower( (string) $filter['join'] ) ? 'OR' : 'AND';
				$tokens[] = array( 'type' => $join, 'filter' => $index );
			}
			$open = isset( $filter['open'] ) ? min( 3, absint( $filter['open'] ) ) : 0;
			for ( $i = 0; $i < $open; $i++ ) {
				$tokens[] = array( 'type' => '(', 'filter' => $index );
			}
			$tokens[] = array( 'type' => 'leaf', 'filter' => $index );
			$close    = isset( $filter['close'] ) ? min( 3, absint( $filter['close'] ) ) : 0;
			for ( $i = 0; $i < $close; $i++ ) {
				$tokens[] = array( 'type' => ')', 'filter' => $index );
			}
		}
		$pos  = 0;
		$tree = self::parse_logic_expr( $tokens, $pos, 0 );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		if ( $pos < count( $tokens ) ) {
			return new WP_Error(
				'filter_logic',
				/* translators: %d: filter position, counting from 1 at the top */
				sprintf( __( 'Filter %d closes a group that was never opened. Remove a ) or add a ( to an earlier filter.', 'remember' ), $tokens[ $pos ]['filter'] + 1 )
			);
		}
		return $tree;
	}

	/**
	 * One group: terms joined by a single kind of operator.
	 *
	 * @param array $tokens Tokens with type and filter position.
	 * @param int   $pos    Read position, advanced in place.
	 * @param int   $depth  Parenthesis depth.
	 * @return array|\WP_Error
	 */
	private static function parse_logic_expr( $tokens, &$pos, $depth ) {
		if ( $depth > 20 ) {
			return new WP_Error( 'filter_logic', __( 'Filters are nested too deeply.', 'remember' ) );
		}
		$first = self::parse_logic_term( $tokens, $pos, $depth );
		if ( is_wp_error( $first ) ) {
			return $first;
		}
		$items = array( $first );
		$op    = '';
		while ( $pos < count( $tokens ) && in_array( $tokens[ $pos ]['type'], array( 'AND', 'OR' ), true ) ) {
			if ( '' !== $op && $op !== $tokens[ $pos ]['type'] ) {
				return new WP_Error(
					'filter_logic',
					/* translators: %d: filter position, counting from 1 at the top */
					sprintf( __( 'AND and OR meet at filter %d without parentheses. Add ( and ) to show which filters belong together.', 'remember' ), $tokens[ $pos ]['filter'] + 1 )
				);
			}
			$op = $tokens[ $pos ]['type'];
			++$pos;
			$item = self::parse_logic_term( $tokens, $pos, $depth );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$items[] = $item;
		}
		if ( 1 === count( $items ) ) {
			return $first;
		}
		return array(
			'op'    => $op,
			'items' => $items,
		);
	}

	/**
	 * A filter or a parenthesized group.
	 *
	 * @param array $tokens Tokens with type and filter position.
	 * @param int   $pos    Read position, advanced in place.
	 * @param int   $depth  Parenthesis depth.
	 * @return array|\WP_Error
	 */
	private static function parse_logic_term( $tokens, &$pos, $depth ) {
		$token = isset( $tokens[ $pos ] ) ? $tokens[ $pos ] : null;
		if ( $token && '(' === $token['type'] ) {
			++$pos;
			$node = self::parse_logic_expr( $tokens, $pos, $depth + 1 );
			if ( is_wp_error( $node ) ) {
				return $node;
			}
			if ( ! isset( $tokens[ $pos ] ) || ')' !== $tokens[ $pos ]['type'] ) {
				return new WP_Error(
					'filter_logic',
					/* translators: %d: filter position, counting from 1 at the top */
					sprintf( __( 'Filter %d opens a group that is never closed. Add a ) to a later filter or remove the (.', 'remember' ), $token['filter'] + 1 )
				);
			}
			++$pos;
			return $node;
		}
		if ( ! $token || 'leaf' !== $token['type'] ) {
			return new WP_Error( 'filter_logic', __( 'The filter grouping could not be read.', 'remember' ) );
		}
		++$pos;
		return array( 'leaf' => $token['filter'] );
	}

	/**
	 * SQL for a logic tree. Filters left incomplete drop out of their group.
	 *
	 * @param array $node    Tree node.
	 * @param array $clauses Filter SQL by position.
	 * @return array{sql:string,params:array}|null
	 */
	private static function logic_sql( $node, $clauses ) {
		if ( isset( $node['leaf'] ) ) {
			if ( empty( $clauses[ $node['leaf'] ] ) ) {
				return null;
			}
			return array(
				'sql'    => '( ' . $clauses[ $node['leaf'] ]['sql'] . ' )',
				'params' => $clauses[ $node['leaf'] ]['params'],
			);
		}
		$parts  = array();
		$params = array();
		foreach ( $node['items'] as $item ) {
			$part = self::logic_sql( $item, $clauses );
			if ( ! $part ) {
				continue;
			}
			$parts[] = $part['sql'];
			$params  = array_merge( $params, $part['params'] );
		}
		if ( ! $parts ) {
			return null;
		}
		$glue = 'OR' === $node['op'] ? ' OR ' : ' AND ';
		return array(
			'sql'    => 1 === count( $parts ) ? $parts[0] : '( ' . implode( $glue, $parts ) . ' )',
			'params' => $params,
		);
	}

	/**
	 * One filter to SQL.
	 *
	 * @param mixed $filter Filter.
	 * @param array $fields Catalog.
	 * @return array{sql:string,params:array}|null|\WP_Error
	 */
	private static function filter_clause( $filter, $fields ) {
		global $wpdb;
		if ( ! is_array( $filter ) || empty( $filter['field'] ) ) {
			return null;
		}
		$fid = (string) $filter['field'];
		if ( ! isset( $fields[ $fid ] ) ) {
			return null;
		}
		$op  = isset( $filter['op'] ) ? sanitize_key( $filter['op'] ) : 'eq';
		$sql = $fields[ $fid ]['sql'];
		$val = isset( $filter['value'] ) ? $filter['value'] : '';
		$list_field = ! empty( $fields[ $fid ]['list'] );

		if ( 'empty' === $op ) {
			if ( $list_field ) {
				return array( 'sql' => "({$sql} IS NULL OR {$sql} = '' OR {$sql} = '[]')", 'params' => array() );
			}
			return array( 'sql' => "({$sql} IS NULL OR {$sql} = '')", 'params' => array() );
		}
		if ( 'not_empty' === $op ) {
			if ( $list_field ) {
				return array( 'sql' => "({$sql} IS NOT NULL AND {$sql} != '' AND {$sql} != '[]')", 'params' => array() );
			}
			return array( 'sql' => "({$sql} IS NOT NULL AND {$sql} != '')", 'params' => array() );
		}
		if ( $list_field && in_array( $op, array( 'eq', 'neq', 'in', 'not_in' ), true ) ) {
			$keys = self::choice_keys( $val, $fields[ $fid ] );
			if ( empty( $keys ) ) {
				return null;
			}
			$match  = isset( $fields[ $fid ]['list_match'] ) ? (string) $fields[ $fid ]['list_match'] : 'json';
			$ors    = array();
			$params = array();
			if ( 'csv' === $match || 'pipe' === $match ) {
				$csv = 'pipe' === $match
					? "REPLACE({$sql}, ' | ', ',')"
					: "REPLACE({$sql}, ', ', ',')";
				foreach ( $keys as $key ) {
					$ors[]    = "FIND_IN_SET(%s, {$csv}) > 0";
					$params[] = $key;
				}
			} else {
				foreach ( $keys as $key ) {
					$ors[]    = "{$sql} LIKE %s";
					$params[] = '%"' . $wpdb->esc_like( $key ) . '"%';
				}
			}
			$clause = '(' . implode( ' OR ', $ors ) . ')';
			if ( in_array( $op, array( 'neq', 'not_in' ), true ) ) {
				$blank = ( 'csv' === $match || 'pipe' === $match )
					? "({$sql} IS NULL OR {$sql} = '')"
					: "({$sql} IS NULL OR {$sql} = '' OR {$sql} = '[]')";
				$clause = "(NOT {$clause} OR {$blank})";
			}
			return array( 'sql' => $clause, 'params' => $params );
		}
		if ( 'in' === $op ) {
			$list = self::choice_keys( $val, $fields[ $fid ] );
			if ( empty( $list ) ) {
				$list = is_array( $val ) ? $val : explode( ',', (string) $val );
				$list = array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) );
				$list = array_slice( $list, 0, 50 );
			}
			if ( empty( $list ) ) {
				return null;
			}
			$ph = implode( ',', array_fill( 0, count( $list ), '%s' ) );
			return array( 'sql' => "{$sql} IN ({$ph})", 'params' => $list );
		}
		if ( 'contains' === $op ) {
			$like = '%' . $wpdb->esc_like( (string) $val ) . '%';
			return array( 'sql' => "{$sql} LIKE %s", 'params' => array( $like ) );
		}
		if ( 'between' === $op ) {
			$a = is_array( $val ) && isset( $val[0] ) ? (string) $val[0] : ( isset( $filter['value_from'] ) ? (string) $filter['value_from'] : '' );
			$b = is_array( $val ) && isset( $val[1] ) ? (string) $val[1] : ( isset( $filter['value_to'] ) ? (string) $filter['value_to'] : '' );
			if ( '' === $a || '' === $b ) {
				return null;
			}
			return array( 'sql' => "{$sql} BETWEEN %s AND %s", 'params' => array( $a, $b ) );
		}
		$map = array(
			'eq'  => '=',
			'neq' => '!=',
			'gt'  => '>',
			'gte' => '>=',
			'lt'  => '<',
			'lte' => '<=',
		);
		if ( ! isset( $map[ $op ] ) ) {
			return null;
		}
		return array( 'sql' => "{$sql} {$map[ $op ]} %s", 'params' => array( (string) $val ) );
	}

	/**
	 * Keep submitted choice keys that exist on the field.
	 *
	 * @param mixed $val   Raw value.
	 * @param array $field Catalog field.
	 * @return string[]
	 */
	private static function choice_keys( $val, $field ) {
		$list = is_array( $val ) ? $val : explode( ',', (string) $val );
		$list = array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) );
		$list = array_slice( $list, 0, 50 );
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return $list;
		}
		$allowed = array();
		foreach ( $field['options'] as $opt ) {
			if ( is_array( $opt ) ) {
				$id = isset( $opt['id'] ) ? $opt['id'] : ( isset( $opt['key'] ) ? $opt['key'] : '' );
			} elseif ( is_object( $opt ) ) {
				$id = isset( $opt->id ) ? $opt->id : ( isset( $opt->key ) ? $opt->key : '' );
			} else {
				$id = $opt;
			}
			$id = (string) $id;
			if ( '' !== $id ) {
				$allowed[] = $id;
			}
		}
		if ( empty( $allowed ) ) {
			return $list;
		}
		return array_values( array_intersect( $list, $allowed ) );
	}

	/**
	 * Keep field ids that exist in the catalog.
	 *
	 * @param mixed $ids    Raw.
	 * @param array $fields Catalog.
	 * @return string[]
	 */
	private static function sanitize_id_list( $ids, $fields ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( isset( $fields[ $id ] ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Safe SELECT alias.
	 *
	 * @param string $id Field id.
	 * @return string
	 */
	private static function alias( $id ) {
		$alias = preg_replace( '/[^a-zA-Z0-9_]/', '_', $id );
		return is_string( $alias ) && '' !== $alias ? $alias : 'col';
	}

	/**
	 * Aggregation SQL.
	 *
	 * @param string $fn  Function.
	 * @param string $sql Expression.
	 * @return string
	 */
	private static function agg_sql( $fn, $sql ) {
		if ( 'count' === $fn ) {
			return 'COUNT(*)';
		}
		if ( 'count_distinct' === $fn ) {
			return "COUNT(DISTINCT {$sql})";
		}
		if ( 'sum' === $fn ) {
			return "SUM({$sql})";
		}
		if ( 'min' === $fn ) {
			return "MIN({$sql})";
		}
		return "MAX({$sql})";
	}

	/**
	 * Aggregation header.
	 *
	 * @param string $fn    Function.
	 * @param string $label Field label.
	 * @return string
	 */
	private static function agg_label( $fn, $label ) {
		$map = array(
			'count'          => __( 'Count', 'remember' ),
			'count_distinct' => sprintf( __( 'Distinct %s', 'remember' ), $label ),
			'sum'            => sprintf( __( 'Sum of %s', 'remember' ), $label ),
			'min'            => sprintf( __( 'Min %s', 'remember' ), $label ),
			'max'            => sprintf( __( 'Max %s', 'remember' ), $label ),
		);
		return isset( $map[ $fn ] ) ? $map[ $fn ] : $label;
	}

	/**
	 * Turn a JSON list of option keys into a comma-separated cell.
	 *
	 * @param mixed $value Raw cell.
	 * @return string
	 */
	public static function format_list_cell( $value ) {
		$value = (string) $value;
		$trim  = trim( $value );
		if ( '' === $trim || '[' !== substr( $trim, 0, 1 ) ) {
			return $value;
		}
		$decoded = json_decode( $trim, true );
		if ( ! is_array( $decoded ) ) {
			return $value;
		}
		if ( $decoded && array_values( $decoded ) !== $decoded ) {
			return $value;
		}
		$keys = array();
		foreach ( $decoded as $item ) {
			if ( is_array( $item ) || is_object( $item ) ) {
				return $value;
			}
			$keys[] = (string) $item;
		}
		return implode( ',', $keys );
	}

	/**
	 * Formula-safe CSV cell.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		$first = substr( $value, 0, 1 );
		if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
