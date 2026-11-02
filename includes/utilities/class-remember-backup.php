<?php
/**
 * Full plugin JSON backup (tables + options). Existing WordPress users are
 * never deleted. Users created for a restore that then fails are removed.
 * The sensitive-access log is included. It records who looked, not the health values.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Disaster-recovery dump of reMember data. Omits billing secrets, OAuth
 * tokens, encryption keys, and downloaded invoice ledgers. Keeps invoice and
 * customer IDs so a restored site can reconnect and rematch.
 */
class Remember_Backup {

	const FORMAT = 'remember-backup';

	const FORMAT_VERSION = 1;

	const CHUNK = 500;

	/**
	 * Open stream for the JSON writer. Null means echo.
	 *
	 * @var resource|null
	 */
	private static $stream = null;

	/**
	 * Whether a fwrite to the backup stream failed.
	 *
	 * @var bool
	 */
	private static $stream_failed = false;

	/**
	 * Whether the current user may download or restore a full backup.
	 *
	 * @return bool
	 */
	public static function current_user_can_backup() {
		return current_user_can( 'remember_access_settings' ) && current_user_can( 'remember_import_export' );
	}

	/**
	 * Restore from an uploaded remember-backup JSON file.
	 *
	 * Replaces all reMember tables and plugin options (except site-specific
	 * keys). WordPress users are matched by email, then login; missing users
	 * are created with a random password. Existing WP users are never deleted.
	 * Users created for this restore are removed if the table restore rolls back.
	 *
	 * @param string $path Absolute path to the JSON file.
	 * @return array{users_matched:int,users_created:int,tables:int,rows:int}|\WP_Error
	 */
	public static function restore_from_file( $path ) {
		if ( ! self::current_user_can_backup() ) {
			return new WP_Error( 'cap', __( 'You cannot restore a full reMember backup.', 'remember' ) );
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'file', __( 'Could not read that backup file.', 'remember' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new WP_Error( 'file', __( 'That backup file is empty.', 'remember' ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( isset( $data['format'] ) ? $data['format'] : '' ) ) {
			return new WP_Error( 'format', __( 'That file is not a reMember backup.', 'remember' ) );
		}
		$version = isset( $data['format_version'] ) ? (int) $data['format_version'] : 0;
		if ( $version < 1 || $version > self::FORMAT_VERSION ) {
			return new WP_Error( 'format', __( 'That backup format is not supported by this plugin version.', 'remember' ) );
		}

		$compat = self::restore_version_error( $data );
		if ( is_wp_error( $compat ) ) {
			return $compat;
		}

		$tables = isset( $data['tables'] ) && is_array( $data['tables'] ) ? $data['tables'] : array();
		$users  = isset( $data['users'] ) && is_array( $data['users'] ) ? $data['users'] : array();
		$options = isset( $data['options'] ) && is_array( $data['options'] ) ? $data['options'] : array();

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-capabilities.php';

		$privilege_guard = null;
		if ( ! current_user_can( 'manage_options' ) ) {
			$privilege_guard = self::snapshot_privilege_guard();
		}

		$map = self::reconcile_users( $users );
		if ( is_wp_error( $map ) ) {
			return $map;
		}

		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$wpdb->query( 'START TRANSACTION' );

		$local_tables = self::table_names();
		foreach ( $local_tables as $table ) {
			$wpdb->query( 'DELETE FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from SHOW TABLES.
		}

		$rows_in = 0;
		$tables_in = 0;
		$prefix = self::table_prefix();
		foreach ( $tables as $suffix => $rows ) {
			$suffix = sanitize_key( (string) $suffix );
			if ( '' === $suffix || ! is_array( $rows ) ) {
				continue;
			}
			$table = $prefix . $suffix;
			if ( ! in_array( $table, $local_tables, true ) ) {
				continue;
			}
			$columns = self::table_columns( $table );
			if ( empty( $columns ) ) {
				continue;
			}
			$inserted = false;
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$prepared = self::prepare_row( $row, $columns, $map['ids'], $suffix );
				if ( empty( $prepared ) ) {
					continue;
				}
				if ( false === self::insert_row( $table, $prepared ) ) {
					$wpdb->query( 'ROLLBACK' );
					$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
					self::delete_created_users( isset( $map['created_ids'] ) ? $map['created_ids'] : array() );
					return new WP_Error(
						'insert',
						sprintf(
							/* translators: %s: table suffix */
							__( 'Could not restore table %s.', 'remember' ),
							$suffix
						)
					);
				}
				$rows_in++;
				$inserted = true;
			}
			if ( $inserted ) {
				$tables_in++;
			}
		}

		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

		self::restore_options( $options );
		self::restore_billing_customer_ids( $users, $map['ids'] );

		if ( is_array( $privilege_guard ) ) {
			self::apply_privilege_guard( $privilege_guard );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		foreach ( array_unique( array_values( $map['ids'] ) ) as $user_id ) {
			Remember_Capabilities::sync_user_capabilities_from_roles( (int) $user_id );
		}

		update_option( 'remember_activation_needs_rewrite_flush', '1' );

		Remember_Logger::info(
			'Full plugin backup restored',
			array(
				'user_id'       => get_current_user_id(),
				'users_matched' => $map['matched'],
				'users_created' => $map['created'],
				'tables'        => $tables_in,
				'rows'          => $rows_in,
			)
		);

		return array(
			'users_matched' => $map['matched'],
			'users_created' => $map['created'],
			'tables'        => $tables_in,
			'rows'          => $rows_in,
		);
	}

	/**
	 * Column names that store a WordPress user ID.
	 *
	 * @return string[]
	 */
	public static function user_id_column_names() {
		return array(
			'member_id',
			'updated_by',
			'created_by',
			'processed_by',
			'primary_vetter_id',
			'invited_by',
			'author_id',
			'reviewed_by',
			'survivor_id',
			'locked_id',
			'member_a_id',
			'member_b_id',
			'owner_id',
		);
	}

	/**
	 * Roles and assignments on this site, used so a non-admin restore cannot widen access.
	 *
	 * @return array{caps_by_name:array<string,string[]>,held:array<int,array<string,bool>>}
	 */
	private static function snapshot_privilege_guard() {
		global $wpdb;
		$roles_table = $wpdb->prefix . 'remember_roles';
		$caps_table  = $wpdb->prefix . 'remember_role_capabilities';
		$join_table  = $wpdb->prefix . 'remember_member_roles';

		$caps_by_id = array();
		$cap_rows   = $wpdb->get_results( "SELECT role_id, capability FROM `{$caps_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed.
		if ( is_array( $cap_rows ) ) {
			foreach ( $cap_rows as $row ) {
				$caps_by_id[ (int) $row->role_id ][] = (string) $row->capability;
			}
		}

		$caps_by_name = array();
		$roles        = $wpdb->get_results( "SELECT role_id, role_name FROM `{$roles_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $roles ) ) {
			foreach ( $roles as $role ) {
				$role_id = (int) $role->role_id;
				$caps_by_name[ (string) $role->role_name ] = isset( $caps_by_id[ $role_id ] ) ? $caps_by_id[ $role_id ] : array();
			}
		}

		$held    = array();
		$assigns = $wpdb->get_results(
			"SELECT mr.member_id, r.role_name FROM `{$join_table}` mr INNER JOIN `{$roles_table}` r ON r.role_id = mr.role_id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( is_array( $assigns ) ) {
			foreach ( $assigns as $row ) {
				$held[ (int) $row->member_id ][ (string) $row->role_name ] = true;
			}
		}

		return array(
			'caps_by_name' => $caps_by_name,
			'held'         => $held,
		);
	}

	/**
	 * After a non-admin restore, drop capabilities and new role assignments that person cannot grant.
	 *
	 * Caps already on a role stay. A role assignment that already existed stays. WordPress
	 * administrators never reach this method.
	 *
	 * @param array $guard Snapshot from snapshot_privilege_guard().
	 * @return void
	 */
	private static function apply_privilege_guard( $guard ) {
		global $wpdb;
		$roles_table = $wpdb->prefix . 'remember_roles';
		$caps_table  = $wpdb->prefix . 'remember_role_capabilities';
		$join_table  = $wpdb->prefix . 'remember_member_roles';
		$existing    = isset( $guard['caps_by_name'] ) && is_array( $guard['caps_by_name'] ) ? $guard['caps_by_name'] : array();
		$held        = isset( $guard['held'] ) && is_array( $guard['held'] ) ? $guard['held'] : array();

		$dropped_caps = 0;
		$roles        = $wpdb->get_results( "SELECT role_id, role_name FROM `{$roles_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $roles ) ) {
			foreach ( $roles as $role ) {
				$role_id = (int) $role->role_id;
				$name    = (string) $role->role_name;
				$posted  = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT capability FROM `{$caps_table}` WHERE role_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$role_id
					)
				);
				$posted  = is_array( $posted ) ? $posted : array();
				$before  = isset( $existing[ $name ] ) ? $existing[ $name ] : array();
				$merged  = Remember_Capabilities::merge_role_capabilities( $before, $posted );
				$posted_sorted = $posted;
				$merged_sorted = $merged;
				sort( $posted_sorted );
				sort( $merged_sorted );
				if ( $posted_sorted === $merged_sorted ) {
					continue;
				}
				$wpdb->delete( $caps_table, array( 'role_id' => $role_id ), array( '%d' ) );
				$now = current_time( 'mysql' );
				foreach ( $merged as $cap ) {
					$wpdb->insert(
						$caps_table,
						array(
							'role_id'    => $role_id,
							'capability' => $cap,
							'created_at' => $now,
						),
						array( '%d', '%s', '%s' )
					);
				}
				$dropped_caps += count( array_diff( $posted, $merged ) );
			}
		}

		$dropped_roles = 0;
		$rows          = $wpdb->get_results(
			"SELECT mr.member_role_id, mr.member_id, mr.role_id, r.role_name FROM `{$join_table}` mr INNER JOIN `{$roles_table}` r ON r.role_id = mr.role_id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$member_id = (int) $row->member_id;
				$name      = (string) $row->role_name;
				if ( isset( $held[ $member_id ][ $name ] ) ) {
					continue;
				}
				if ( Remember_Capabilities::current_user_can_assign_role( (int) $row->role_id ) ) {
					continue;
				}
				$wpdb->delete( $join_table, array( 'member_role_id' => (int) $row->member_role_id ), array( '%d' ) );
				$dropped_roles++;
			}
		}

		if ( $dropped_caps > 0 || $dropped_roles > 0 ) {
			Remember_Logger::info(
				'Backup restore kept capabilities the restorer cannot grant',
				array(
					'user_id'                   => get_current_user_id(),
					'capabilities_removed'      => $dropped_caps,
					'role_assignments_removed'  => $dropped_roles,
				)
			);
		}
	}

	/**
	 * Refuse a backup that is newer than the files or schema on this site.
	 *
	 * @param array $data Decoded backup.
	 * @return \WP_Error|null
	 */
	private static function restore_version_error( $data ) {
		$backup_plugin = self::normalize_version( isset( $data['plugin_version'] ) ? $data['plugin_version'] : '' );
		$backup_db     = self::normalize_version( isset( $data['db_version'] ) ? $data['db_version'] : '' );
		if ( '' === $backup_plugin || '' === $backup_db ) {
			return new WP_Error( 'version', __( 'That backup does not include version information and cannot be restored.', 'remember' ) );
		}

		$installed_plugin = self::normalize_version( defined( 'REMEMBER_VERSION' ) ? REMEMBER_VERSION : '' );
		$installed_db     = self::normalize_version( (string) get_option( 'remember_db_version', '' ) );
		if ( '' === $installed_plugin ) {
			return new WP_Error( 'version', __( 'This site’s plugin version could not be determined. Restore cancelled.', 'remember' ) );
		}

		if ( version_compare( $installed_plugin, $backup_plugin, '<' ) ) {
			return new WP_Error(
				'version',
				sprintf(
					/* translators: 1: installed plugin version, 2: backup plugin version */
					__( 'This site is reMember %1$s. The backup is from %2$s. Update the plugin to %2$s or newer before restoring.', 'remember' ),
					$installed_plugin,
					$backup_plugin
				)
			);
		}

		if ( '' === $installed_db ) {
			return new WP_Error( 'version', __( 'This site’s database version could not be determined. Restore cancelled.', 'remember' ) );
		}

		if ( version_compare( $installed_db, $backup_db, '<' ) ) {
			return new WP_Error(
				'version',
				sprintf(
					/* translators: 1: installed db version, 2: backup db version */
					__( 'This site’s database is %1$s. The backup needs %2$s. Update reMember and load wp-admin once so the schema can catch up, then restore.', 'remember' ),
					$installed_db,
					$backup_db
				)
			);
		}

		return null;
	}

	/**
	 * Strip a leading v and keep a comparable version string.
	 *
	 * @param mixed $version Raw version.
	 * @return string
	 */
	private static function normalize_version( $version ) {
		$version = strtolower( trim( (string) $version ) );
		if ( '' === $version ) {
			return '';
		}
		if ( 0 === strpos( $version, 'v' ) ) {
			$version = substr( $version, 1 );
		}
		return preg_match( '/^[0-9]+(\.[0-9]+){0,3}$/', $version ) ? $version : '';
	}

	/**
	 * Stream a JSON backup download.
	 *
	 * @return void
	 */
	public static function download() {
		if ( ! self::current_user_can_backup() ) {
			wp_die( esc_html__( 'You cannot download a full reMember backup.', 'remember' ), esc_html__( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';
		Remember_Logger::info( 'Full plugin backup downloaded', array( 'user_id' => get_current_user_id() ) );
		Remember_Access_Log::record( 0, 'backup', __( 'Full backup', 'remember' ) );

		$filename = 'remember-backup-' . gmdate( 'Y-m-d-H-i-s' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		self::stream_document( $out );
		if ( is_resource( $out ) ) {
			fclose( $out );
		}
		exit;
	}

	/**
	 * Write a full backup to a path outside the request.
	 *
	 * @param string $path Absolute file path.
	 * @return true|\WP_Error
	 */
	public static function write_to_path( $path ) {
		if ( ! self::current_user_can_backup() ) {
			return new WP_Error( 'cap', __( 'You cannot download a full reMember backup.', 'remember' ) );
		}
		$path = (string) $path;
		if ( '' === $path || ! wp_is_writable( dirname( $path ) ) ) {
			return new WP_Error( 'file', __( 'Could not write that backup file.', 'remember' ) );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';

		$out = fopen( $path, 'w' );
		if ( ! is_resource( $out ) ) {
			return new WP_Error( 'file', __( 'Could not write that backup file.', 'remember' ) );
		}
		$ok = self::stream_document( $out );
		fclose( $out );
		if ( ! $ok ) {
			wp_delete_file( $path );
			return new WP_Error( 'file', __( 'Could not write that backup file.', 'remember' ) );
		}

		Remember_Logger::info( 'Full plugin backup downloaded', array( 'user_id' => get_current_user_id() ) );
		Remember_Access_Log::record( 0, 'backup', __( 'Full backup', 'remember' ) );
		return true;
	}

	/**
	 * Write the backup document to an open stream.
	 *
	 * @param resource $out Destination.
	 * @return bool
	 */
	private static function stream_document( $out ) {
		self::$stream        = $out;
		self::$stream_failed = false;

		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}

		self::put( '{' );
		self::emit_key( 'format', self::FORMAT, $flags, true );
		self::emit_key( 'format_version', self::FORMAT_VERSION, $flags );
		self::emit_key( 'plugin_version', defined( 'REMEMBER_VERSION' ) ? REMEMBER_VERSION : '', $flags );
		self::emit_key( 'db_version', (string) get_option( 'remember_db_version', '' ), $flags );
		self::emit_key( 'exported_at', gmdate( 'c' ), $flags );
		self::emit_key( 'site_url', home_url( '/' ), $flags );
		self::emit_key( 'prefix', self::table_prefix(), $flags );

		self::put( ',"users":' );
		self::put( wp_json_encode( self::user_index(), $flags ) );
		self::put( ',"options":' );
		self::put( wp_json_encode( self::plugin_options(), $flags ) );
		self::put( ',"tables":{' );
		self::stream_tables( $flags );
		self::put( '}}' );

		$ok            = ! self::$stream_failed;
		self::$stream  = null;
		return $ok;
	}

	/**
	 * Write one chunk of the backup document.
	 *
	 * @param string $text Chunk.
	 * @return void
	 */
	private static function put( $text ) {
		if ( ! is_resource( self::$stream ) ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON backup stream.
			return;
		}
		if ( false === fwrite( self::$stream, $text ) ) {
			self::$stream_failed = true;
		}
	}

	/**
	 * Plugin table prefix (wp_remember_).
	 *
	 * @return string
	 */
	public static function table_prefix() {
		global $wpdb;
		return $wpdb->prefix . 'remember_';
	}

	/**
	 * Existing reMember tables.
	 *
	 * @return string[] Full table names.
	 */
	public static function table_names() {
		global $wpdb;
		$like = $wpdb->esc_like( self::table_prefix() ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * Login/email index for WordPress user IDs referenced by plugin data.
	 *
	 * @return array<int,array{id:int,login:string,email:string,display_name:string,qb_customer_id?:string,xero_contact_id?:string}>
	 */
	private static function user_index() {
		$out = array();
		foreach ( self::referenced_user_ids() as $id ) {
			$user = get_userdata( $id );
			if ( ! $user ) {
				$out[] = array(
					'id'           => $id,
					'login'        => '',
					'email'        => '',
					'display_name' => '',
					'missing'      => true,
				);
				continue;
			}
			$entry = array(
				'id'           => $id,
				'login'        => (string) $user->user_login,
				'email'        => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
			);
			foreach ( self::billing_customer_index_keys() as $field => $meta_key ) {
				$val = get_user_meta( $id, $meta_key, true );
				if ( is_scalar( $val ) && '' !== trim( (string) $val ) ) {
					$entry[ $field ] = trim( (string) $val );
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Distinct WP user IDs stored in plugin tables.
	 *
	 * @return int[]
	 */
	private static function referenced_user_ids() {
		global $wpdb;
		$ids  = array();
		$cols = self::user_id_column_names();
		foreach ( self::table_names() as $table ) {
			$fields = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! in_array( $field, $cols, true ) ) {
					continue;
				}
				$found = $wpdb->get_col( "SELECT DISTINCT `{$field}` FROM `{$table}` WHERE `{$field}` IS NOT NULL AND `{$field}` > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column from SHOW TABLES/COLUMNS.
				if ( ! is_array( $found ) ) {
					continue;
				}
				foreach ( $found as $id ) {
					$ids[] = (int) $id;
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * All wp_options whose names start with remember_.
	 *
	 * @return array<string,mixed>
	 */
	private static function plugin_options() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( 'remember_' ) . '%'
			)
		);
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		$skip = self::skip_option_names();
		foreach ( $rows as $row ) {
			$name = (string) $row->option_name;
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			$out[ $name ] = maybe_unserialize( $row->option_value );
		}
		return $out;
	}

	/**
	 * Stream each table as a JSON array of row objects.
	 *
	 * @param int $flags json_encode flags.
	 * @return void
	 */
	private static function stream_tables( $flags ) {
		global $wpdb;
		$prefix = self::table_prefix();
		$first  = true;
		foreach ( self::table_names() as $table ) {
			if ( 0 !== strpos( $table, $prefix ) ) {
				continue;
			}
			$suffix = substr( $table, strlen( $prefix ) );
			if ( '' === $suffix || ! preg_match( '/^[a-z0-9_]+$/', $suffix ) ) {
				continue;
			}
			if ( ! $first ) {
				self::put( ',' );
			}
			$first = false;
			self::put( wp_json_encode( $suffix, $flags ) );
			self::put( ':' );
			self::stream_table_rows( $table, $suffix, $flags );
		}
	}

	/**
	 * Stream rows for one table.
	 *
	 * @param string $table  Full table name.
	 * @param string $suffix Table suffix (remember_*).
	 * @param int    $flags  json_encode flags.
	 * @return void
	 */
	private static function stream_table_rows( $table, $suffix, $flags ) {
		global $wpdb;
		self::put( '[' );
		$offset = 0;
		$first  = true;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated.
					self::CHUNK,
					$offset
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				if ( ! $first ) {
					self::put( ',' );
				}
				$first = false;
				self::put( wp_json_encode( self::sanitize_table_row( $suffix, $row ), $flags ) );
			}
			if ( count( $rows ) < self::CHUNK ) {
				break;
			}
			$offset += self::CHUNK;
		}
		self::put( ']' );
	}

	/**
	 * Emit a JSON object key.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $flags json_encode flags.
	 * @param bool   $first First key (no leading comma).
	 * @return void
	 */
	private static function emit_key( $key, $value, $flags, $first = false ) {
		if ( ! $first ) {
			self::put( ',' );
		}
		self::put( wp_json_encode( (string) $key, $flags ) );
		self::put( ':' );
		self::put( wp_json_encode( $value, $flags ) );
	}

	/**
	 * Match backup users to this site, creating WordPress users when needed.
	 *
	 * @param array $users Backup user index.
	 * @return array{ids:array<int,int>,matched:int,created:int,created_ids:int[]}|\WP_Error
	 */
	private static function reconcile_users( $users ) {
		$map         = array();
		$matched     = 0;
		$created     = 0;
		$created_ids = array();
		$used_emails = array();

		foreach ( $users as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$old_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( $old_id < 1 ) {
				continue;
			}
			$email = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';
			$login = isset( $row['login'] ) ? sanitize_user( (string) $row['login'], true ) : '';
			$name  = isset( $row['display_name'] ) ? sanitize_text_field( $row['display_name'] ) : '';
			if ( ! empty( $row['missing'] ) && '' === $email && '' === $login ) {
				continue;
			}

			$existing = null;
			if ( is_email( $email ) ) {
				$existing = get_user_by( 'email', $email );
			}
			if ( ! $existing && '' !== $login ) {
				$existing = get_user_by( 'login', $login );
			}

			if ( $existing && $existing->ID ) {
				$new_id = (int) $existing->ID;
				if ( $name && $name !== $existing->display_name ) {
					if ( Remember_Capabilities::current_user_can_administer_account( $new_id ) ) {
						wp_update_user(
							array(
								'ID'           => $new_id,
								'display_name' => $name,
							)
						);
					} else {
						Remember_Logger::warning(
							'Restore kept the display name of a privileged WordPress account',
							array(
								'user_id'    => $new_id,
								'by_user_id' => get_current_user_id(),
							)
						);
					}
				}
				$map[ $old_id ] = $new_id;
				$used_emails[ strtolower( $existing->user_email ) ] = $new_id;
				$matched++;
				continue;
			}

			if ( ! is_email( $email ) ) {
				continue;
			}
			$email_key = strtolower( $email );
			if ( isset( $used_emails[ $email_key ] ) ) {
				$local = explode( '@', $email );
				$email = $local[0] . '+' . $old_id . '@' . ( isset( $local[1] ) ? $local[1] : 'example.invalid' );
				$email_key = strtolower( $email );
			}

			$email = self::unique_email( $email );
			if ( ! is_email( $email ) ) {
				continue;
			}
			$email_key = strtolower( $email );

			if ( '' === $login ) {
				$login = sanitize_user( current( explode( '@', $email ) ), true );
			}
			if ( '' === $login ) {
				$login = 'remember-' . $old_id;
			}
			$login = self::unique_login( $login );

			$new_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'display_name' => $name ? $name : $login,
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $new_id ) ) {
				self::delete_created_users( $created_ids );
				return new WP_Error(
					'user',
					sprintf(
						/* translators: 1: login, 2: error */
						__( 'Could not create WordPress user %1$s: %2$s', 'remember' ),
						$login,
						$new_id->get_error_message()
					)
				);
			}
			$map[ $old_id ] = (int) $new_id;
			$used_emails[ $email_key ] = (int) $new_id;
			$created_ids[] = (int) $new_id;
			$created++;
		}

		return array(
			'ids'         => $map,
			'matched'     => $matched,
			'created'     => $created,
			'created_ids' => $created_ids,
		);
	}

	/**
	 * Remove WordPress users this restore created after the restore fails.
	 *
	 * @param int[] $user_ids User IDs created by reconcile_users.
	 * @return void
	 */
	private static function delete_created_users( $user_ids ) {
		if ( ! is_array( $user_ids ) || empty( $user_ids ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$removed = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id < 1 || $user_id === (int) get_current_user_id() ) {
				continue;
			}
			if ( wp_delete_user( $user_id ) ) {
				$removed[] = $user_id;
			} else {
				Remember_Logger::warning(
					'Failed restore could not remove a WordPress user it created',
					array( 'user_id' => $user_id )
				);
			}
		}
		if ( ! empty( $removed ) ) {
			Remember_Logger::info(
				'Failed restore removed WordPress users it had created',
				array(
					'user_id' => get_current_user_id(),
					'removed' => $removed,
				)
			);
		}
	}

	/**
	 * Unique user_login.
	 *
	 * @param string $login Desired login.
	 * @return string
	 */
	private static function unique_login( $login ) {
		$base = $login;
		$i    = 2;
		while ( username_exists( $login ) ) {
			$login = $base . '-' . $i;
			$i++;
			if ( $i > 1000 ) {
				return $base . '-' . wp_generate_password( 6, false, false );
			}
		}
		return $login;
	}

	/**
	 * Unique user_email.
	 *
	 * @param string $email Desired email.
	 * @return string
	 */
	private static function unique_email( $email ) {
		$parts = explode( '@', $email );
		$local = $parts[0];
		$domain = isset( $parts[1] ) ? $parts[1] : 'example.invalid';
		$i      = 2;
		while ( email_exists( $email ) ) {
			$email = $local . '+' . $i . '@' . $domain;
			$i++;
			if ( $i > 1000 ) {
				return $local . '+' . wp_generate_password( 6, false, false ) . '@' . $domain;
			}
		}
		return $email;
	}

	/**
	 * Column names for a table.
	 *
	 * @param string $table Full table name.
	 * @return array<string,array{null:string}>
	 */
	private static function table_columns( $table ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$out[ $row->Field ] = array(
				'null' => ( 'YES' === $row->Null ),
			);
		}
		return $out;
	}

	/**
	 * Keep known columns and remap user IDs.
	 *
	 * @param array  $row     Backup row.
	 * @param array  $columns Target columns.
	 * @param array  $map     old user id => new user id.
	 * @param string $suffix  Table suffix.
	 * @return array
	 */
	private static function prepare_row( $row, $columns, $map, $suffix = '' ) {
		$row      = self::sanitize_table_row( $suffix, $row );
		$out      = array();
		$usercols = self::user_id_column_names();
		$fallback = get_current_user_id();
		foreach ( $row as $col => $val ) {
			$col = (string) $col;
			if ( ! isset( $columns[ $col ] ) ) {
				continue;
			}
			if ( in_array( $col, $usercols, true ) ) {
				$val = self::remap_user_id( $val, $map, $columns[ $col ]['null'], $fallback );
				if ( 'member_id' === $col && ( null === $val || (int) $val < 1 ) ) {
					return array();
				}
			}
			$out[ $col ] = $val;
		}
		return $out;
	}

	/**
	 * Map a stored WP user ID onto this site.
	 *
	 * @param mixed $val      Raw value.
	 * @param array $map      old => new.
	 * @param bool  $nullable Column allows NULL.
	 * @param int   $fallback Required-column fallback.
	 * @return int|null
	 */
	private static function remap_user_id( $val, $map, $nullable, $fallback ) {
		if ( null === $val || '' === $val ) {
			return $nullable ? null : ( $fallback > 0 ? $fallback : 0 );
		}
		$old = (int) $val;
		if ( $old < 1 ) {
			return $nullable ? null : ( $fallback > 0 ? $fallback : 0 );
		}
		if ( isset( $map[ $old ] ) ) {
			return (int) $map[ $old ];
		}
		if ( get_userdata( $old ) ) {
			return $old;
		}
		return $nullable ? null : ( $fallback > 0 ? $fallback : $old );
	}

	/**
	 * Insert one row, preserving SQL NULL.
	 *
	 * @param string $table Table.
	 * @param array  $row   Column => value.
	 * @return bool
	 */
	private static function insert_row( $table, $row ) {
		global $wpdb;
		$sets   = array();
		$params = array();
		foreach ( $row as $col => $val ) {
			if ( ! preg_match( '/^[a-z0-9_]+$/', $col ) ) {
				continue;
			}
			if ( null === $val ) {
				$sets[] = '`' . $col . '` = NULL';
			} else {
				$sets[]   = '`' . $col . '` = %s';
				$params[] = $val;
			}
		}
		if ( empty( $sets ) ) {
			return false;
		}
		$sql = 'INSERT INTO `' . esc_sql( $table ) . '` SET ' . implode( ', ', $sets );
		if ( empty( $params ) ) {
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return false !== $result;
	}

	/**
	 * Option names that stay with this WordPress site.
	 *
	 * @return string[]
	 */
	private static function skip_option_names() {
		return array(
			'remember_created_pages',
			'remember_log_dir_key',
			'remember_activation_needs_rewrite_flush',
			'remember_version',
			'remember_db_version',
			'remember_qb_encryption_key',
			'remember_xero_encryption_key',
			'remember_xero_last_oauth',
			'remember_registration_captcha_secret',
		);
	}

	/**
	 * User-index fields that map members onto billing-provider accounts.
	 *
	 * @return array<string,string> Backup field => user meta key.
	 */
	private static function billing_customer_index_keys() {
		return array(
			'qb_customer_id'  => 'remember_qb_customer_id',
			'xero_contact_id' => 'remember_xero_contact_id',
		);
	}

	/**
	 * Processor settings keys that are safe to migrate (no secrets or tokens).
	 *
	 * @return string[]
	 */
	private static function processor_settings_keep_keys() {
		return array(
			'client_id',
			'environment',
			'realm_id',
			'tenant_id',
			'tenant_name',
		);
	}

	/**
	 * Strip secrets and live billing ledgers from a table row.
	 *
	 * Keeps invoice IDs and processor org identifiers so a restored site can
	 * reconnect and redownload balances.
	 *
	 * @param string $suffix Table suffix.
	 * @param array  $row    Associative row.
	 * @return array
	 */
	private static function sanitize_table_row( $suffix, $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		if ( 'payment_processors' === $suffix ) {
			if ( array_key_exists( 'settings', $row ) ) {
				$row['settings'] = self::sanitize_processor_settings( $row['settings'] );
			}
			if ( array_key_exists( 'last_sync_at', $row ) ) {
				$row['last_sync_at'] = null;
			}
			return $row;
		}
		if ( 'payments' === $suffix ) {
			$total = isset( $row['total_amount'] ) ? $row['total_amount'] : '0.00';
			$row['amount_paid']     = '0.00';
			$row['amount_due']      = $total;
			$row['payment_status']  = 'pending';
			$row['payment_date']    = null;
			$row['payment_method']  = null;
			$row['transaction_id']  = null;
			foreach ( array(
				'quickbooks_invoice_sort_ts',
				'quickbooks_payment_lines',
				'quickbooks_refund_lines',
				'xero_online_invoice_url',
				'xero_invoice_sort_ts',
				'xero_payment_lines',
				'xero_refund_lines',
			) as $col ) {
				if ( array_key_exists( $col, $row ) ) {
					$row[ $col ] = null;
				}
			}
		}
		return $row;
	}

	/**
	 * Keep only reconnect identifiers from a processor settings JSON blob.
	 *
	 * @param mixed $raw JSON string or array.
	 * @return string JSON object.
	 */
	private static function sanitize_processor_settings( $raw ) {
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = array();
		}
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		$out = array();
		foreach ( self::processor_settings_keep_keys() as $key ) {
			if ( ! array_key_exists( $key, $decoded ) ) {
				continue;
			}
			$val = $decoded[ $key ];
			if ( null === $val || is_scalar( $val ) ) {
				$out[ $key ] = $val;
			}
		}
		$json = wp_json_encode( $out );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Write QuickBooks/Xero account IDs onto restored WordPress users.
	 *
	 * @param array $users Backup user index.
	 * @param array $map   old user id => new user id.
	 * @return void
	 */
	private static function restore_billing_customer_ids( $users, $map ) {
		foreach ( $users as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$old_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( $old_id < 1 || ! isset( $map[ $old_id ] ) ) {
				continue;
			}
			$new_id = (int) $map[ $old_id ];
			if ( $new_id < 1 ) {
				continue;
			}
			foreach ( self::billing_customer_index_keys() as $field => $meta_key ) {
				$val = isset( $row[ $field ] ) ? sanitize_text_field( (string) $row[ $field ] ) : '';
				if ( '' === $val ) {
					delete_user_meta( $new_id, $meta_key );
				} else {
					update_user_meta( $new_id, $meta_key, $val );
				}
			}
		}
	}

	/**
	 * Replace plugin options from the backup.
	 *
	 * @param array $options Name => value.
	 * @return void
	 */
	private static function restore_options( $options ) {
		global $wpdb;
		$skip = self::skip_option_names();
		$keep = array();
		foreach ( $skip as $name ) {
			$keep[ $name ] = get_option( $name, null );
		}

		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'remember_' ) . '%'
			)
		);
		if ( is_array( $existing ) ) {
			foreach ( $existing as $name ) {
				if ( in_array( $name, $skip, true ) ) {
					continue;
				}
				delete_option( $name );
			}
		}

		foreach ( $options as $name => $value ) {
			$name = (string) $name;
			if ( 0 !== strpos( $name, 'remember_' ) || in_array( $name, $skip, true ) ) {
				continue;
			}
			update_option( $name, $value, false );
		}

		foreach ( $keep as $name => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}
			update_option( $name, $value, false );
		}
	}
}
