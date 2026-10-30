<?php
/**
 * Event model class
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
 * Event model class.
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */
class Remember_Event extends Remember_Base_Model {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'events';

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'event_id';

	/**
	 * Create a new event.
	 *
	 * @param array $data Event data.
	 * @return int|false Event ID or false on error.
	 */
	public function create( $data ) {
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = current_time( 'mysql' );
		return $this->insert( $data );
	}

	/**
	 * Update an event.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $data     Event data.
	 * @return int|false
	 */
	public function update( $event_id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return parent::update( $event_id, $data );
	}

	/**
	 * Get events by status.
	 *
	 * @param string $status Event status.
	 * @return array
	 */
	public function get_by_status( $status ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE status = %s ORDER BY start_date ASC",
				$status
			)
		);
	}

	/**
	 * Get open events.
	 *
	 * @return array
	 */
	public function get_open() {
		return $this->get_by_status( 'open' );
	}

	/**
	 * Get upcoming events.
	 *
	 * @return array
	 */
	public function get_upcoming() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->get_table()} WHERE start_date >= CURDATE() AND status IN ('open', 'draft') ORDER BY start_date ASC"
		);
	}

	/**
	 * Get historical events by location (past events, sorted reverse chronological).
	 *
	 * @param int $location_id Location ID.
	 * @return array
	 */
	public function get_historical_by_location( $location_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table()} 
				WHERE location_id = %d 
				AND end_date < CURDATE() 
				AND status IN ('completed', 'closed', 'cancelled')
				ORDER BY end_date DESC, start_date DESC",
				$location_id
			)
		);
	}

	/**
	 * Get event roles for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array Array of event role objects with role information.
	 */
	public function get_event_roles( $event_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT er.*, r.role_name, r.show_in_frontend 
				FROM {$wpdb->prefix}remember_event_roles er 
				JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id 
				WHERE er.event_id = %d 
				ORDER BY r.role_name ASC",
				$event_id
			)
		);
	}

	/**
	 * Event roles this member may choose on the public apply form.
	 *
	 * The member holds the role, it is an event role, and it is shown on the front end.
	 * Staff loading every role for wp-admin use get_event_roles() instead.
	 *
	 * @param int $event_id  Event ID.
	 * @param int $member_id Member / WordPress user ID.
	 * @return array
	 */
	public function get_offered_event_roles( $event_id, $member_id ) {
		global $wpdb;

		$event_id  = absint( $event_id );
		$member_id = absint( $member_id );
		if ( $event_id < 1 || $member_id < 1 ) {
			return array();
		}

		$columns              = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}remember_roles" );
		$has_show_in_frontend = in_array( 'show_in_frontend', $columns, true );
		$show_sql             = $has_show_in_frontend ? 'AND COALESCE(r.show_in_frontend, 1) != 0' : '';
		$show_select          = $has_show_in_frontend ? ', r.show_in_frontend' : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT er.*, r.role_name{$show_select}
				FROM {$wpdb->prefix}remember_event_roles er
				JOIN {$wpdb->prefix}remember_roles r ON er.role_id = r.role_id
				JOIN {$wpdb->prefix}remember_member_roles mr ON r.role_id = mr.role_id
				WHERE er.event_id = %d
				AND mr.member_id = %d
				AND r.role_type = 'event'
				{$show_sql}
				ORDER BY r.role_name ASC",
				$event_id,
				$member_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether the public apply form would offer this event role to the member.
	 *
	 * @param int $event_id       Event ID.
	 * @param int $member_id      Member / WordPress user ID.
	 * @param int $event_role_id  Event role row ID.
	 * @return bool
	 */
	public function member_offered_event_role( $event_id, $member_id, $event_role_id ) {
		$event_role_id = absint( $event_role_id );
		if ( $event_role_id < 1 ) {
			return false;
		}
		foreach ( $this->get_offered_event_roles( $event_id, $member_id ) as $role ) {
			if ( isset( $role->event_role_id ) && (int) $role->event_role_id === $event_role_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Set event roles for an event (replaces existing).
	 *
	 * @param int   $event_id Event ID.
	 * @param array $role_ids Array of role IDs to assign to the event.
	 * @return bool True on success, false on failure.
	 */
	public function set_event_roles( $event_id, $role_ids ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_event_roles';
		
		// Delete existing event roles
		$wpdb->delete( $table_name, array( 'event_id' => $event_id ), array( '%d' ) );
		
		// Insert new event roles
		if ( ! empty( $role_ids ) ) {
			foreach ( $role_ids as $role_id ) {
				$role_id = absint( $role_id );
				if ( $role_id > 0 ) {
					$wpdb->insert(
						$table_name,
						array(
							'event_id'    => $event_id,
							'role_id'     => $role_id,
							'cost'        => 0.00,
							'is_active'   => 1,
							'created_at'  => current_time( 'mysql' ),
						),
						array( '%d', '%d', '%f', '%d', '%s' )
					);
				}
			}
		}
		
		return true;
	}

	/**
	 * Sync event role configuration for an event.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $role_configs Role config keyed by role ID.
	 * @return bool
	 */
	public function sync_event_role_configs( $event_id, $role_configs ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'remember_event_roles';

		$incoming_ids = array();
		foreach ( $role_configs as $role_id => $config ) {
			$role_id = absint( $role_id );
			if ( $role_id <= 0 || empty( $config['enabled'] ) ) {
				continue;
			}

			$incoming_ids[] = $role_id;
			$cost = isset( $config['cost'] ) ? floatval( $config['cost'] ) : 0;
			$max_participants = null;
			if ( isset( $config['max_participants'] ) && '' !== $config['max_participants'] ) {
				$max_participants = absint( $config['max_participants'] );
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT event_role_id FROM {$table_name} WHERE event_id = %d AND role_id = %d LIMIT 1",
					$event_id,
					$role_id
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table_name,
					array(
						'cost'             => $cost,
						'max_participants' => $max_participants,
						'is_active'        => 1,
					),
					array(
						'event_id' => $event_id,
						'role_id'  => $role_id,
					),
					array( '%f', '%d', '%d' ),
					array( '%d', '%d' )
				);
			} else {
				$wpdb->insert(
					$table_name,
					array(
						'event_id'          => $event_id,
						'role_id'           => $role_id,
						'cost'              => $cost,
						'max_participants'  => $max_participants,
						'current_count'     => 0,
						'is_active'         => 1,
						'created_at'        => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%f', '%d', '%d', '%d', '%s' )
				);
			}
		}

		$existing_role_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT role_id FROM {$table_name} WHERE event_id = %d",
				$event_id
			)
		);

		foreach ( $existing_role_ids as $existing_role_id ) {
			$existing_role_id = absint( $existing_role_id );
			if ( ! in_array( $existing_role_id, $incoming_ids, true ) ) {
				$wpdb->delete(
					$table_name,
					array(
						'event_id' => $event_id,
						'role_id'  => $existing_role_id,
					),
					array( '%d', '%d' )
				);
			}
		}

		return true;
	}

	/**
	 * Whether a stored registration datetime is unset.
	 *
	 * @param mixed $value Datetime string.
	 * @return bool
	 */
	public static function is_blank_registration_datetime( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' === $value || '0000-00-00 00:00:00' === $value;
	}

	/**
	 * Parse an optional registration datetime from the event form.
	 *
	 * Blank is unlimited. A value is a site-timezone wall clock, stored as
	 * Y-m-d H:i:s so it compares with current_time( 'mysql' ).
	 *
	 * @param string $raw Posted value.
	 * @return string|null|false Mysql datetime, null when blank, false when invalid.
	 */
	public static function parse_registration_datetime( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}

		$raw = str_replace( 'T', ' ', $raw );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw ) ) {
			$raw .= ':00';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw ) ) {
			return false;
		}

		$dt = date_create_immutable( $raw, wp_timezone() );
		if ( ! $dt || $dt->format( 'Y-m-d H:i:s' ) !== $raw ) {
			return false;
		}

		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Validate the optional registration window from the event form.
	 *
	 * @param string $opens_raw  Open field.
	 * @param string $closes_raw Close field.
	 * @return array|WP_Error Keys registration_opens_at and registration_closes_at.
	 */
	public static function registration_bounds_from_input( $opens_raw, $closes_raw ) {
		$opens  = self::parse_registration_datetime( $opens_raw );
		$closes = self::parse_registration_datetime( $closes_raw );
		if ( false === $opens || false === $closes ) {
			return new WP_Error(
				'remember_registration_datetime',
				__( 'Registration open and close must be valid dates and times, or left blank.', 'remember' )
			);
		}
		if ( null !== $opens && null !== $closes && $closes <= $opens ) {
			return new WP_Error(
				'remember_registration_order',
				__( 'Registration close must be after registration open.', 'remember' )
			);
		}

		return array(
			'registration_opens_at'  => $opens,
			'registration_closes_at' => $closes,
		);
	}

	/**
	 * Value for a datetime-local input.
	 *
	 * @param mixed $value Stored datetime.
	 * @return string
	 */
	public static function registration_datetime_input_value( $value ) {
		if ( self::is_blank_registration_datetime( $value ) ) {
			return '';
		}
		$dt = date_create_immutable( $value, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}
		return $dt->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Site-timezone label for a stored registration datetime.
	 *
	 * @param mixed $value Stored datetime.
	 * @return string
	 */
	public static function format_registration_datetime( $value ) {
		if ( self::is_blank_registration_datetime( $value ) ) {
			return '';
		}
		$dt = date_create_immutable( $value, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}
		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$dt->getTimestamp()
		);
	}

	/**
	 * Member-facing sentence for the optional window. Empty when unset.
	 *
	 * @param object $event Event row.
	 * @return string
	 */
	public static function registration_window_summary( $event ) {
		$opens  = self::format_registration_datetime( isset( $event->registration_opens_at ) ? $event->registration_opens_at : '' );
		$closes = self::format_registration_datetime( isset( $event->registration_closes_at ) ? $event->registration_closes_at : '' );
		if ( $opens && $closes ) {
			return sprintf(
				/* translators: 1: registration open datetime, 2: registration close datetime */
				__( 'Registration: %1$s – %2$s', 'remember' ),
				$opens,
				$closes
			);
		}
		if ( $opens ) {
			return sprintf(
				/* translators: %s: registration open datetime */
				__( 'Registration opens %s.', 'remember' ),
				$opens
			);
		}
		if ( $closes ) {
			return sprintf(
				/* translators: %s: registration close datetime */
				__( 'Registration closes %s.', 'remember' ),
				$closes
			);
		}
		return '';
	}

	/**
	 * Why a member cannot apply right now. Empty when the event is open and the window allows it.
	 *
	 * The event status must be Open. Blank open or close means that side is unlimited.
	 *
	 * @param object $event Event row.
	 * @return string
	 */
	public static function registration_block_reason( $event ) {
		if ( ! is_object( $event ) ) {
			return '';
		}

		if ( ! isset( $event->status ) || 'open' !== (string) $event->status ) {
			return __( 'This event is not open for applications.', 'remember' );
		}

		$now    = current_time( 'mysql' );
		$opens  = isset( $event->registration_opens_at ) ? $event->registration_opens_at : '';
		$closes = isset( $event->registration_closes_at ) ? $event->registration_closes_at : '';

		if ( ! self::is_blank_registration_datetime( $opens ) && $now < $opens ) {
			return sprintf(
				/* translators: %s: registration open datetime */
				__( 'Registration opens %s.', 'remember' ),
				self::format_registration_datetime( $opens )
			);
		}
		if ( ! self::is_blank_registration_datetime( $closes ) && $now > $closes ) {
			return sprintf(
				/* translators: %s: registration close datetime */
				__( 'Registration closed %s.', 'remember' ),
				self::format_registration_datetime( $closes )
			);
		}

		return '';
	}
}
