<?php
/**
 * Event Application model class
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-remember-base-model.php';

/**
 * Event Application model class.
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */
class Remember_Application extends Remember_Base_Model {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'event_applications';

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'application_id';

	/**
	 * Create a new application.
	 *
	 * @param array $data Application data.
	 * @return int|false Application ID or false on error.
	 */
	public function create( $data ) {
		// Validate event_role_id exists before creating
		if ( isset( $data['event_role_id'] ) && ! empty( $data['event_role_id'] ) ) {
			global $wpdb;
			$event_role_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}remember_event_roles WHERE event_role_id = %d",
				$data['event_role_id']
			) );
			
			if ( ! $event_role_exists ) {
				$this->last_error = sprintf( 
					__( 'Invalid event_role_id: %d. The event role does not exist.', 'remember' ),
					$data['event_role_id']
				);
				return false;
			}
			
			// Also validate that the event_role belongs to the specified event
			if ( isset( $data['event_id'] ) && ! empty( $data['event_id'] ) ) {
				$event_role_belongs_to_event = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}remember_event_roles 
					WHERE event_role_id = %d AND event_id = %d",
					$data['event_role_id'],
					$data['event_id']
				) );
				
				if ( ! $event_role_belongs_to_event ) {
					$this->last_error = sprintf( 
						__( 'Event role %d does not belong to event %d.', 'remember' ),
						$data['event_role_id'],
						$data['event_id']
					);
					return false;
				}
			}
		}
		
		$data['applied_at'] = current_time( 'mysql' );
		return $this->insert( $data );
	}

	/**
	 * Update application status.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $status        New status.
	 * @param int    $processed_by  User ID who processed it.
	 * @return int|false
	 */
	public function update_status( $application_id, $status, $processed_by = null ) {
		global $wpdb;
		$application = $this->get( $application_id );
		if ( ! $application ) {
			return false;
		}

		$previous_status = $application->status;
		$data = array(
			'status'      => $status,
			'processed_at' => current_time( 'mysql' ),
		);
		if ( 'waitlisted' === $status ) {
			$data['waitlisted_at'] = current_time( 'mysql' );
		} elseif ( 'waitlisted' === $previous_status && 'waitlisted' !== $status ) {
			$data['waitlisted_at'] = null;
		}
		if ( $processed_by ) {
			$data['processed_by'] = $processed_by;
		}
		$result = $this->update( $application_id, $data );
		if ( false === $result ) {
			return $result;
		}

		$event_roles_table = $wpdb->prefix . 'remember_event_roles';
		if ( 'accepted' !== $previous_status && 'accepted' === $status ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$event_roles_table} SET current_count = current_count + 1 WHERE event_role_id = %d",
					$application->event_role_id
				)
			);
		} elseif ( 'accepted' === $previous_status && 'accepted' !== $status ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$event_roles_table} SET current_count = GREATEST(current_count - 1, 0) WHERE event_role_id = %d",
					$application->event_role_id
				)
			);
		}

		if ( 'accepted' === $previous_status && in_array( $status, array( 'cancelled', 'declined' ), true ) ) {
			do_action( 'remember_application_spot_freed', (int) $application_id, (int) $application->event_id, (int) $application->event_role_id );
		}

		return $result;
	}

	/**
	 * Get applications by event.
	 *
	 * @param int $event_id Event ID.
	 * @return array
	 */
	public function get_by_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE event_id = %d ORDER BY applied_at DESC",
				$event_id
			)
		);
	}

	/**
	 * Get applications by member.
	 *
	 * @param int $member_id Member ID.
	 * @return array
	 */
	public function get_by_member( $member_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE member_id = %d ORDER BY applied_at DESC",
				$member_id
			)
		);
	}

	/**
	 * Get applications by status.
	 *
	 * @param string $status Application status.
	 * @return array
	 */
	public function get_by_status( $status ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE status = %s ORDER BY applied_at DESC",
				$status
			)
		);
	}

	/**
	 * Paginated, searchable application list for the admin screen.
	 *
	 * The event/status filters, the member/event search, ordering and paging all
	 * run in SQL (joined to wp_users, the profiles table, and the events table)
	 * so only one page of rows is returned regardless of how many applications exist.
	 *
	 * @param array $args {
	 *     @type int    $event_id Event filter, or 0 for all.
	 *     @type string $status   Status filter, or '' for all.
	 *     @type string $search   Member name (display or legal) / email / login / event-name search.
	 *     @type int    $per_page Rows per page.
	 *     @type int    $paged    1-based page number.
	 * }
	 * @return array { @type object[] $items Application rows for this page. @type int $total Total matches. }
	 */
	public function query_for_list( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'event_id' => 0,
			'status'   => '',
			'search'   => '',
			'per_page' => 25,
			'paged'    => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$apps_table   = $this->get_table();
		$events_table = $wpdb->prefix . 'remember_events';

		$from = " FROM {$apps_table} a
			INNER JOIN {$wpdb->users} u ON u.ID = a.member_id
			LEFT JOIN {$events_table} e ON e.event_id = a.event_id
			LEFT JOIN {$wpdb->prefix}remember_member_profiles p ON p.member_id = a.member_id";

		$where  = array();
		$params = array();

		if ( absint( $args['event_id'] ) > 0 ) {
			$where[]  = 'a.event_id = %d';
			$params[] = absint( $args['event_id'] );
		}
		if ( '' !== (string) $args['status'] ) {
			$where[]  = 'a.status = %s';
			$params[] = (string) $args['status'];
		}
		if ( '' !== trim( (string) $args['search'] ) ) {
			$search       = $this->person_search_clauses( $args['search'], false );
			$conditions   = $search['conditions'];
			$conditions[] = 'e.event_name LIKE %s';
			$where[]      = '(' . implode( ' OR ', $conditions ) . ')';
			$params       = array_merge( $params, $search['params'], array( '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%' ) );
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = 'SELECT COUNT(*)' . $from . $where_sql;
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders prepared with $params.

		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$select_sql    = 'SELECT a.*' . $from . $where_sql . ' ORDER BY a.applied_at DESC, a.application_id DESC LIMIT %d OFFSET %d';
		$select_params = array_merge( $params, array( $per_page, $offset ) );
		$items         = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders prepared with $select_params.

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Check if member already has an active (non-superseded) application for event role.
	 *
	 * Superseded rows are kept for history after an admin allows reapply.
	 *
	 * @param int $event_id      Event ID.
	 * @param int $member_id     Member ID.
	 * @param int $event_role_id Event role ID.
	 * @return object|null
	 */
	public function get_existing_application( $event_id, $member_id, $event_role_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()}
				WHERE event_id = %d AND member_id = %d AND event_role_id = %d
				AND superseded_at IS NULL
				ORDER BY applied_at DESC
				LIMIT 1",
				$event_id,
				$member_id,
				$event_role_id
			)
		);
	}

	/**
	 * Mark a closed application as superseded so the member may reapply (admin forgive).
	 *
	 * @param int $application_id Application ID.
	 * @return true|string True on success, or error message.
	 */
	public function allow_reapply( $application_id ) {
		$application_id = absint( $application_id );
		$application    = $this->get( $application_id );
		if ( ! $application ) {
			return __( 'Application not found.', 'remember' );
		}
		if ( ! empty( $application->superseded_at ) ) {
			return __( 'Reapply was already allowed for this application.', 'remember' );
		}
		if ( ! in_array( $application->status, array( 'declined', 'cancelled' ), true ) ) {
			return __( 'Only declined or cancelled applications can be opened for reapply.', 'remember' );
		}
		$result = $this->update(
			$application_id,
			array(
				'superseded_at' => current_time( 'mysql' ),
				'processed_at'  => current_time( 'mysql' ),
				'processed_by'  => get_current_user_id() ? get_current_user_id() : null,
			)
		);
		if ( false === $result ) {
			return __( 'Failed to allow reapply.', 'remember' );
		}
		return true;
	}

	/**
	 * Get application by event and member (any role).
	 *
	 * @param int $event_id  Event ID.
	 * @param int $member_id Member ID.
	 * @return object|null
	 */
	public function get_by_event_and_member( $event_id, $member_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE event_id = %d AND member_id = %d ORDER BY applied_at DESC LIMIT 1",
				$event_id,
				$member_id
			)
		);
	}

	/**
	 * Find and return applications with orphaned event_role_id values.
	 *
	 * @return array Array of application objects with orphaned event_role_id.
	 */
	public function get_orphaned_applications() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT a.* 
			FROM {$this->get_table()} a
			LEFT JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
			WHERE er.event_role_id IS NULL"
		);
	}

	/**
	 * Clean up orphaned applications (delete applications with invalid event_role_id).
	 *
	 * @return int Number of applications deleted.
	 */
	public function cleanup_orphaned_applications() {
		global $wpdb;
		$deleted = $wpdb->query(
			"DELETE a FROM {$this->get_table()} a
			LEFT JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
			WHERE er.event_role_id IS NULL"
		);
		return $deleted;
	}

	/**
	 * Get count of accepted applications for an event role.
	 *
	 * @param int      $event_role_id Event role ID.
	 * @param int|null $exclude_application_id Optional application ID to exclude.
	 * @return int
	 */
	public function get_accepted_count_for_event_role( $event_role_id, $exclude_application_id = null ) {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM {$this->get_table()} WHERE event_role_id = %d AND status = 'accepted'";
		$args = array( $event_role_id );

		if ( ! empty( $exclude_application_id ) ) {
			$sql .= ' AND application_id != %d';
			$args[] = absint( $exclude_application_id );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Get waitlisted applications with related details.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_waitlisted_with_details( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'event_id' => 0,
			'role_id'  => 0,
			'orderby'  => 'waitlisted_at',
			'order'    => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = array( 'waitlisted_at', 'applied_at', 'event_name', 'role_name', 'display_name' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'waitlisted_at';
		$order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$where = "WHERE a.status = 'waitlisted'";
		$query_args = array();

		if ( ! empty( $args['event_id'] ) ) {
			$where .= ' AND a.event_id = %d';
			$query_args[] = absint( $args['event_id'] );
		}
		if ( ! empty( $args['role_id'] ) ) {
			$where .= ' AND er.role_id = %d';
			$query_args[] = absint( $args['role_id'] );
		}

		$sql = "SELECT a.*, e.event_name, r.role_name, r.role_id, u.display_name, u.user_email
			FROM {$this->get_table()} a
			JOIN {$wpdb->prefix}remember_events e ON a.event_id = e.event_id
			JOIN {$wpdb->prefix}remember_event_roles er ON a.event_role_id = er.event_role_id
			JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
			LEFT JOIN {$wpdb->users} u ON a.member_id = u.ID
			{$where}
			ORDER BY {$orderby} {$order}, a.applied_at ASC";

		if ( ! empty( $query_args ) ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * Get events that currently have waitlisted applications.
	 *
	 * @return array
	 */
	public function get_waitlisted_events() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT DISTINCT e.event_id, e.event_name
			FROM {$this->get_table()} a
			JOIN {$wpdb->prefix}remember_events e ON a.event_id = e.event_id
			WHERE a.status = 'waitlisted'
			ORDER BY e.event_name ASC"
		);
	}
}
