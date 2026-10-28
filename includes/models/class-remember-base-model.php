<?php
/**
 * Base model class for all reMember models
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Base model class.
 *
 * Provides common database operations for all models.
 *
 * @package    reMember
 * @subpackage reMember/includes/models
 */
abstract class Remember_Base_Model {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	protected $wpdb;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table_name;

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Cached column names for this table.
	 *
	 * @var string[]|null
	 */
	protected $columns_cache = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb   = $wpdb;
		$this->prefix = $wpdb->prefix . 'remember_';
	}

	/**
	 * Get full table name with prefix.
	 *
	 * @return string
	 */
	protected function get_table() {
		return $this->prefix . $this->table_name;
	}

	/**
	 * Real column names for this table, cached per instance.
	 *
	 * @return string[]
	 */
	protected function get_columns() {
		if ( null === $this->columns_cache ) {
			$columns = $this->wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $this->get_table() ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal.
			$this->columns_cache = is_array( $columns ) ? $columns : array();
		}
		return $this->columns_cache;
	}

	/**
	 * Resolve an ORDER BY column to a real column of this table.
	 *
	 * Protects ORDER BY against injection: any value that is not an actual
	 * column name falls back to the primary key. Callers no longer have to
	 * whitelist before passing request input.
	 *
	 * @param string $orderby Requested column.
	 * @return string Safe column name.
	 */
	protected function sanitize_orderby( $orderby ) {
		$orderby = (string) $orderby;
		return in_array( $orderby, $this->get_columns(), true ) ? $orderby : $this->primary_key;
	}

	/**
	 * Coerce a sort direction to ASC or DESC.
	 *
	 * @param string $order Requested direction.
	 * @return string 'ASC' or 'DESC'.
	 */
	protected function sanitize_order( $order ) {
		return ( 'DESC' === strtoupper( (string) $order ) ) ? 'DESC' : 'ASC';
	}

	/**
	 * Get a record by ID.
	 *
	 * @param int $id Record ID.
	 * @return object|null
	 */
	public function get( $id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->get_table()} WHERE {$this->primary_key} = %d",
				$id
			)
		);
	}

	/**
	 * Get all records.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_all( $args = array() ) {
		$defaults = array(
			'limit'  => -1,
			'offset' => 0,
			'orderby' => $this->primary_key,
			'order'   => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );

		$query = "SELECT * FROM {$this->get_table()}";

		if ( ! empty( $args['orderby'] ) ) {
			$orderby = $this->sanitize_orderby( $args['orderby'] );
			$order   = $this->sanitize_order( $args['order'] );
			$query  .= " ORDER BY `{$orderby}` {$order}";
		}

		if ( $args['limit'] > 0 ) {
			$query .= $this->wpdb->prepare( " LIMIT %d OFFSET %d", $args['limit'], $args['offset'] );
		}

		return $this->wpdb->get_results( $query );
	}

	/**
	 * Insert a new record.
	 *
	 * @param array $data Data to insert.
	 * @return int|false The ID of the inserted record, or false on error.
	 */
	public function insert( $data ) {
		$result = $this->wpdb->insert( $this->get_table(), $data );
		if ( $result ) {
			// If primary key was provided in data, return it; otherwise return insert_id
			if ( isset( $data[ $this->primary_key ] ) ) {
				return $data[ $this->primary_key ];
			}
			return $this->wpdb->insert_id;
		}
		// Store error for retrieval (capture immediately before any other DB operations)
		$this->last_error = $this->wpdb->last_error;
		$this->last_query = $this->wpdb->last_query;
		return false;
	}

	/**
	 * Get last database error.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return isset( $this->last_error ) ? $this->last_error : '';
	}

	/**
	 * Get last database query.
	 *
	 * @return string
	 */
	public function get_last_query() {
		return isset( $this->last_query ) ? $this->last_query : '';
	}

	/**
	 * Update a record.
	 *
	 * @param int   $id   Record ID.
	 * @param array $data Data to update.
	 * @return int|false The number of rows updated, or false on error.
	 */
	public function update( $id, $data ) {
		return $this->wpdb->update(
			$this->get_table(),
			$data,
			array( $this->primary_key => $id )
		);
	}

	/**
	 * Delete a record.
	 *
	 * @param int $id Record ID.
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function delete( $id ) {
		return $this->wpdb->delete(
			$this->get_table(),
			array( $this->primary_key => $id )
		);
	}

	/**
	 * Count records.
	 *
	 * @param array $where WHERE conditions.
	 * @return int
	 */
	public function count( $where = array() ) {
		$query = "SELECT COUNT(*) FROM {$this->get_table()}";
		
		if ( ! empty( $where ) ) {
			$conditions = array();
			foreach ( $where as $column => $value ) {
				if ( is_numeric( $value ) ) {
					$conditions[] = $this->wpdb->prepare( "{$column} = %d", $value );
				} else {
					$conditions[] = $this->wpdb->prepare( "{$column} = %s", $value );
				}
			}
			$query .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		return (int) $this->wpdb->get_var( $query );
	}
}
