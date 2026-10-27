<?php
/**
 * Safe zip overwrite / upgrade for reMember.
 *
 * WordPress Plugin_Upgrader::upgrade() (dashboard updates) silently deactivates
 * before replacing files. Upload → “Replace current with uploaded” uses
 * install( overwrite_package ) and does NOT deactivate — which can fatal while
 * files are deleted/replaced under an active plugin. This class mirrors the
 * core deactivate → replace → silent reactivate flow for remember/remember.php.
 *
 * Also hardens Upload → Replace when the destination is a git/dev tree: WordPress
 * refuses to clear destinations with unwritable files (often `.git/objects` mode
 * 444, or `dist/` from local zip builds). We purge non-release paths, chmod the
 * tree writable, and retry the wipe when core’s clear_destination fails.
 *
 * Before any of that, a dashboard update or Upload → Replace checks that the
 * PHP user can replace the installed copy. If a directory is owned by someone
 * else, the update stops while the old version is still in place. WordPress 6.3+
 * otherwise moves the folder into upgrade-temp-backup first and can leave a
 * half-deleted plugin when that move fails partway.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Plugin upgrader safety hooks.
 */
class Remember_Plugin_Upgrader {

	const PLUGIN_BASENAME = 'remember/remember.php';
	const TRANSIENT_KEY   = 'remember_reactivate_after_upgrade';
	const PENDING_KEY     = 'remember_upgrade_pending_replace';

	/**
	 * Whether this request already checked writability and prepared the installed copy.
	 *
	 * @var bool
	 */
	private static $replacement_ready = false;

	/**
	 * Register hooks (call from main plugin bootstrap).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'flag_remember_package' ), 10, 4 );
		// Priority 1: run before Plugin_Upgrader deactivates the plugin at priority 10.
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'prepare_remember_destination' ), 1, 2 );
		add_filter( 'upgrader_clear_destination', array( __CLASS__, 'after_clear_destination' ), 1, 4 );
		add_action( 'upgrader_overwrote_package', array( __CLASS__, 'maybe_reactivate_after_overwrite' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'maybe_reactivate_after_process' ), 10, 2 );
	}

	/**
	 * Paths that must never block a release overwrite (dev / build artifacts).
	 *
	 * @return string[]
	 */
	private static function non_release_basenames() {
		return array(
			'.git',
			'.github',
			'.vscode',
			'.cursor',
			'.idea',
			'dist',
			'bin',
			'.DS_Store',
			'.gitignore',
			'.gitattributes',
		);
	}

	/**
	 * Whether a filesystem destination path is the remember plugin folder.
	 *
	 * @param string $remote_destination Destination path (WP_Filesystem style).
	 * @return bool
	 */
	private static function is_remember_destination( $remote_destination ) {
		$remote_destination = untrailingslashit( str_replace( '\\', '/', (string) $remote_destination ) );
		return ( 'remember' === basename( $remote_destination ) );
	}

	/**
	 * Whether plugin header data belongs to reMember.
	 *
	 * @param array $plugin_data Plugin headers.
	 * @return bool
	 */
	private static function is_remember_plugin_data( $plugin_data ) {
		if ( ! is_array( $plugin_data ) ) {
			return false;
		}
		if ( ! empty( $plugin_data['TextDomain'] ) && 'remember' === $plugin_data['TextDomain'] ) {
			return true;
		}
		if ( ! empty( $plugin_data['Name'] ) && 'reMember' === $plugin_data['Name'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Absolute path to the installed remember plugin directory, if present.
	 *
	 * @return string
	 */
	private static function installed_remember_dir() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}
		$dir = trailingslashit( WP_PLUGIN_DIR ) . 'remember';
		return is_dir( $dir ) ? untrailingslashit( $dir ) : '';
	}

	/**
	 * Mark when the package being installed is reMember.
	 *
	 * Upload → Replace does not put the plugin basename in hook_extra, and this
	 * filter runs before WordPress moves or deletes the installed copy. The
	 * writability check for that path lives here. A dashboard update is checked
	 * earlier, in prepare_remember_destination(), because hook_extra already
	 * names remember/remember.php.
	 *
	 * @param string      $source        Source path.
	 * @param string      $remote_source Remote source.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $args          Extra args.
	 * @return string|WP_Error
	 */
	public static function flag_remember_package( $source, $remote_source, $upgrader, $args = array() ) {
		unset( $remote_source, $upgrader );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$source_path = trailingslashit( (string) $source );
		if ( ! file_exists( $source_path . 'remember.php' ) ) {
			return $source;
		}
		$data = get_plugin_data( $source_path . 'remember.php', false, false );
		if ( ! self::is_remember_plugin_data( $data ) ) {
			return $source;
		}

		set_transient( self::PENDING_KEY, 1, 30 * MINUTE_IN_SECONDS );

		if ( self::hook_targets_remember( $args ) ) {
			return $source;
		}

		$blocked = self::block_if_not_replaceable();
		if ( is_wp_error( $blocked ) ) {
			delete_transient( self::PENDING_KEY );
			return $blocked;
		}
		self::prepare_installed_copy();

		return $source;
	}

	/**
	 * Before install: refuse an unwritable tree, then deactivate and scrub non-release junk.
	 *
	 * Dashboard updates (including bulk) pass remember/remember.php in hook_extra.
	 * That is available here, before WordPress moves the folder aside. Upload → Replace
	 * is handled in flag_remember_package() once the zip is open.
	 *
	 * @param bool|WP_Error $response   Pre-install response.
	 * @param array         $hook_extra Hook extras.
	 * @return bool|WP_Error
	 */
	public static function prepare_remember_destination( $response, $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! self::hook_targets_remember( $hook_extra ) ) {
			return $response;
		}

		$blocked = self::block_if_not_replaceable();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
		self::prepare_installed_copy();

		return $response;
	}

	/**
	 * Whether this upgrader hook is aimed at the installed reMember plugin.
	 *
	 * Bulk updates omit `type` and still set `plugin`.
	 *
	 * @param array $hook_extra Hook extras.
	 * @return bool
	 */
	private static function hook_targets_remember( $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return false;
		}
		if ( ! empty( $hook_extra['plugin'] ) && self::PLUGIN_BASENAME === $hook_extra['plugin'] ) {
			return true;
		}
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( self::PLUGIN_BASENAME, $hook_extra['plugins'], true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Stop the update when the installed copy cannot be replaced safely.
	 *
	 * @return true|WP_Error True when the tree can be replaced.
	 */
	private static function block_if_not_replaceable() {
		if ( self::$replacement_ready ) {
			return true;
		}
		$failures = self::replacement_blockers();
		if ( empty( $failures ) ) {
			return true;
		}
		return self::not_writable_error( $failures );
	}

	/**
	 * Deactivate and clear dev artifacts once the writability check has passed.
	 *
	 * @return void
	 */
	private static function prepare_installed_copy() {
		if ( self::$replacement_ready ) {
			return;
		}
		self::$replacement_ready = true;
		self::deactivate_for_upgrade();

		$dir = self::installed_remember_dir();
		if ( '' !== $dir ) {
			self::purge_non_release_paths( $dir );
			self::ensure_tree_writable( $dir );
		}
	}

	/**
	 * Paths the PHP user cannot replace: the plugin tree, its parent, and the temp backup.
	 *
	 * A file is replaceable when its parent directory is writable. Mode 0444 files
	 * inside a writable directory (git objects) are left for the later chmod pass.
	 * An unwritable directory is reported once; its children are not listed again.
	 *
	 * @return string[] Absolute paths.
	 */
	private static function replacement_blockers() {
		$failures = array();

		if ( defined( 'WP_PLUGIN_DIR' ) && '' !== WP_PLUGIN_DIR && ! is_writable( WP_PLUGIN_DIR ) ) {
			$failures[] = WP_PLUGIN_DIR;
		}

		$plugin = self::installed_remember_dir();
		if ( '' !== $plugin ) {
			$failures = array_merge( $failures, self::unwritable_paths( $plugin ) );
		}

		return array_values( array_unique( array_merge( $failures, self::backup_dir_blockers() ) ) );
	}

	/**
	 * Whether upgrade-temp-backup/plugins can receive the old plugin folder.
	 *
	 * @return string[] Absolute paths that block the move.
	 */
	private static function backup_dir_blockers() {
		if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
			return array();
		}

		$content  = untrailingslashit( WP_CONTENT_DIR );
		$backup   = $content . '/upgrade-temp-backup';
		$plugins  = $backup . '/plugins';
		$existing = $plugins . '/remember';
		$failures = array();

		if ( is_dir( $plugins ) ) {
			if ( ! is_writable( $plugins ) ) {
				$failures[] = $plugins;
			}
		} elseif ( is_dir( $backup ) ) {
			if ( ! is_writable( $backup ) ) {
				$failures[] = $backup;
			}
		} elseif ( ! is_writable( $content ) ) {
			$failures[] = $content;
		}

		if ( is_dir( $existing ) ) {
			$failures = array_merge( $failures, self::unwritable_paths( $existing ) );
		}

		return $failures;
	}

	/**
	 * Directories the PHP user cannot write, which means the files inside cannot be deleted.
	 *
	 * @param string $dir Directory path.
	 * @return string[]
	 */
	private static function unwritable_paths( $dir ) {
		$dir = untrailingslashit( (string) $dir );
		if ( '' === $dir || ! file_exists( $dir ) ) {
			return array();
		}
		if ( ! is_dir( $dir ) ) {
			return is_writable( dirname( $dir ) ) ? array() : array( $dir );
		}
		if ( ! is_readable( $dir ) || ! is_writable( $dir ) ) {
			return array( $dir );
		}

		$failures    = array();
		$blocked_dirs = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $item ) {
				$path = $item->getPathname();
				if ( self::path_under_blocked_dir( $path, $blocked_dirs ) ) {
					continue;
				}
				if ( $item->isDir() && ( ! is_readable( $path ) || ! is_writable( $path ) ) ) {
					$failures[]     = $path;
					$blocked_dirs[] = $path;
				}
			}
		} catch ( Exception $e ) {
			$failures[] = $dir;
		}

		return $failures;
	}

	/**
	 * Whether $path sits inside a directory already reported as unwritable.
	 *
	 * @param string   $path         Path to test.
	 * @param string[] $blocked_dirs Directories already reported.
	 * @return bool
	 */
	private static function path_under_blocked_dir( $path, $blocked_dirs ) {
		$path = str_replace( '\\', '/', (string) $path );
		foreach ( $blocked_dirs as $blocked ) {
			$blocked = str_replace( '\\', '/', (string) $blocked );
			if ( 0 === strpos( $path, $blocked . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Error shown when the update must stop, plus a log of every blocked path.
	 *
	 * @param string[] $failures Absolute paths.
	 * @return WP_Error
	 */
	private static function not_writable_error( $failures ) {
		$php_user = self::process_user();
		$shown    = array_slice( $failures, 0, 5 );
		$bits     = array();
		foreach ( $shown as $path ) {
			$bits[] = self::display_path( $path ) . ' (' . self::path_owner( $path ) . ')';
		}
		$path_list = implode( ', ', $bits );
		$extra     = count( $failures ) - count( $shown );
		if ( $extra > 0 ) {
			$path_list .= ' ' . sprintf(
				/* translators: %d: number of additional paths */
				__( 'and %d more', 'remember' ),
				$extra
			);
		}

		$message = sprintf(
			/* translators: 1: account the web server runs as, 2: paths and their owners */
			__( 'reMember was not updated. The web server runs as %1$s and cannot replace these files, so the installed copy is unchanged: %2$s.', 'remember' ),
			$php_user,
			$path_list
		);

		$chown_user = self::process_user_token();
		if ( '' !== $chown_user ) {
			$message .= ' ' . sprintf(
				/* translators: %s: example chown command */
				__( 'Give that user ownership of the plugin folder, for example: %s', 'remember' ),
				'chown -R ' . $chown_user . ':' . $chown_user . ' wp-content/plugins/remember'
			);
		}

		self::log_upgrade(
			'error',
			'Update stopped because the web server cannot replace the plugin files',
			array(
				'php_user' => $php_user,
				'paths'    => array_values( $failures ),
			)
		);

		return new WP_Error( 'remember_not_writable', $message );
	}

	/**
	 * Account name for a numeric uid.
	 *
	 * @param int $uid User id.
	 * @return string
	 */
	private static function account_name( $uid ) {
		$uid = (int) $uid;
		if ( function_exists( 'posix_getpwuid' ) ) {
			$info = posix_getpwuid( $uid );
			if ( is_array( $info ) && ! empty( $info['name'] ) ) {
				return (string) $info['name'];
			}
		}
		return 'uid ' . $uid;
	}

	/**
	 * Account the current PHP process runs as.
	 *
	 * @return string
	 */
	private static function process_user() {
		if ( function_exists( 'posix_geteuid' ) ) {
			return self::account_name( (int) posix_geteuid() );
		}
		$current = function_exists( 'get_current_user' ) ? get_current_user() : '';
		if ( is_string( $current ) && '' !== $current ) {
			return $current;
		}
		return __( 'the web server', 'remember' );
	}

	/**
	 * Process user when it is safe to place in a chown command.
	 *
	 * @return string Empty when the name is unknown or contains shell-sensitive characters.
	 */
	private static function process_user_token() {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			return '';
		}
		$token = self::process_user();
		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $token ) ) {
			return '';
		}
		return $token;
	}

	/**
	 * Owner name for a path.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function path_owner( $path ) {
		$uid = fileowner( $path );
		if ( false === $uid ) {
			return __( 'unknown owner', 'remember' );
		}
		return self::account_name( (int) $uid );
	}

	/**
	 * Path relative to the WordPress root, for the message shown to staff.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function display_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$root = defined( 'ABSPATH' ) ? untrailingslashit( str_replace( '\\', '/', ABSPATH ) ) : '';
		if ( '' !== $root && 0 === strpos( $path, $root . '/' ) ) {
			return substr( $path, strlen( $root ) + 1 );
		}
		return $path;
	}

	/**
	 * Write an upgrade log line when the logger is available.
	 *
	 * @param string $level   error|warning|debug.
	 * @param string $message Log message.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function log_upgrade( $level, $message, $context = array() ) {
		if ( ! class_exists( 'Remember_Logger', false ) && defined( 'REMEMBER_PLUGIN_DIR' ) ) {
			$logger = REMEMBER_PLUGIN_DIR . 'includes/utilities/class-remember-logger.php';
			if ( is_readable( $logger ) ) {
				require_once $logger;
			}
		}
		if ( ! class_exists( 'Remember_Logger', false ) ) {
			return;
		}
		if ( 'warning' === $level ) {
			Remember_Logger::warning( $message, $context );
			return;
		}
		if ( 'debug' === $level ) {
			Remember_Logger::debug( $message, $context );
			return;
		}
		Remember_Logger::error( $message, $context );
	}

	/**
	 * After core clear_destination: on failure, scrub + chmod + retry wipe.
	 *
	 * @param bool|WP_Error $removed            Clear result.
	 * @param string        $local_destination  Local destination.
	 * @param string        $remote_destination Remote destination being cleared.
	 * @param array         $hook_extra         Upgrader hook extras.
	 * @return bool|WP_Error
	 */
	public static function after_clear_destination( $removed, $local_destination, $remote_destination, $hook_extra ) {
		unset( $local_destination, $hook_extra );
		if ( ! self::is_remember_destination( $remote_destination ) ) {
			return $removed;
		}

		// Deactivate if we somehow skipped pre_install (still set reactivate flag).
		self::deactivate_for_upgrade();

		if ( true === $removed ) {
			return $removed;
		}

		self::purge_non_release_paths( $remote_destination );
		self::ensure_tree_writable( $remote_destination );

		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			if ( ! $wp_filesystem->exists( $remote_destination ) ) {
				return true;
			}
			if ( $wp_filesystem->delete( $remote_destination, true ) ) {
				return true;
			}
		}

		if ( self::force_delete_directory( $remote_destination ) ) {
			return true;
		}

		return $removed;
	}

	/**
	 * Silently deactivate reMember and store a reactivate flag.
	 *
	 * @return void
	 */
	private static function deactivate_for_upgrade() {
		if ( wp_doing_cron() ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'deactivate_plugins' ) ) {
			return;
		}

		$plugin = self::PLUGIN_BASENAME;
		if ( ! is_plugin_active( $plugin ) && ! is_plugin_active_for_network( $plugin ) ) {
			return;
		}

		$network_wide = is_multisite() && is_plugin_active_for_network( $plugin );
		deactivate_plugins( $plugin, true, $network_wide );

		set_transient(
			self::TRANSIENT_KEY,
			array(
				'plugin'       => $plugin,
				'network_wide' => $network_wide ? 1 : 0,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Delete known non-release directories/files under the plugin root.
	 *
	 * @param string $destination Plugin directory path.
	 * @return void
	 */
	private static function purge_non_release_paths( $destination ) {
		$destination = untrailingslashit( str_replace( '\\', '/', (string) $destination ) );
		if ( '' === $destination || 'remember' !== basename( $destination ) ) {
			return;
		}

		global $wp_filesystem;
		foreach ( self::non_release_basenames() as $name ) {
			$path = $destination . '/' . $name;
			if ( $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->chmod( $path, ( $wp_filesystem->is_dir( $path ) ? FS_CHMOD_DIR : FS_CHMOD_FILE ) );
				$wp_filesystem->delete( $path, true );
			}
			if ( file_exists( $path ) || is_dir( $path ) ) {
				self::force_delete_directory( $path );
			}
		}
	}

	/**
	 * Recursively chmod a tree so WP_Filesystem can delete it.
	 *
	 * @param string $destination Directory path.
	 * @return void
	 */
	private static function ensure_tree_writable( $destination ) {
		$destination = untrailingslashit( (string) $destination );
		if ( '' === $destination || ! file_exists( $destination ) ) {
			return;
		}

		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$files = $wp_filesystem->dirlist( $destination, true, true );
			if ( is_array( $files ) ) {
				self::chmod_dirlist( $destination, $files );
			}
		}

		// PHP fallback for read-only git objects (often 0444).
		if ( is_dir( $destination ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $destination, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				$path = $item->getPathname();
				if ( $item->isDir() ) {
					@chmod( $path, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} else {
					@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			@chmod( $destination, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Apply chmod using a WP_Filesystem dirlist tree.
	 *
	 * @param string $base    Base path.
	 * @param array  $dirlist Dirlist array.
	 * @return void
	 */
	private static function chmod_dirlist( $base, $dirlist ) {
		global $wp_filesystem;
		foreach ( $dirlist as $name => $details ) {
			$path = trailingslashit( $base ) . $name;
			$type = isset( $details['type'] ) ? $details['type'] : 'f';
			$wp_filesystem->chmod( $path, ( 'd' === $type ? FS_CHMOD_DIR : FS_CHMOD_FILE ) );
			if ( 'd' === $type && ! empty( $details['files'] ) && is_array( $details['files'] ) ) {
				self::chmod_dirlist( $path, $details['files'] );
			}
		}
	}

	/**
	 * Force-delete a file or directory with PHP when WP_Filesystem fails.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function force_delete_directory( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! is_dir( $path ) ) {
			return false;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				$item_path = $item->getPathname();
				if ( $item->isDir() ) {
					@chmod( $item_path, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@rmdir( $item_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} else {
					@chmod( $item_path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $item_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			@chmod( $path, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Reactivate after a successful upload overwrite of reMember.
	 *
	 * @param string $package      Package file path.
	 * @param array  $plugin_data  New plugin headers.
	 * @param string $package_type 'plugin' or 'theme'.
	 * @return void
	 */
	public static function maybe_reactivate_after_overwrite( $package, $plugin_data, $package_type ) {
		unset( $package );
		delete_transient( self::PENDING_KEY );
		if ( 'plugin' !== $package_type || ! self::is_remember_plugin_data( $plugin_data ) ) {
			return;
		}
		self::reactivate_if_flagged();
	}

	/**
	 * Reactivate after a successful update process that targeted reMember.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Process extras.
	 * @return void
	 */
	public static function maybe_reactivate_after_process( $upgrader, $hook_extra ) {
		unset( $upgrader );
		delete_transient( self::PENDING_KEY );
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		if ( ! empty( $hook_extra['plugin'] ) && self::PLUGIN_BASENAME === $hook_extra['plugin'] ) {
			self::reactivate_if_flagged();
			return;
		}
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( self::PLUGIN_BASENAME, $hook_extra['plugins'], true ) ) {
			self::reactivate_if_flagged();
		}
	}

	/**
	 * Silently reactivate if we deactivated for an upgrade in this request cycle.
	 *
	 * @return void
	 */
	private static function reactivate_if_flagged() {
		$flag = get_transient( self::TRANSIENT_KEY );
		delete_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $flag ) || empty( $flag['plugin'] ) ) {
			return;
		}
		if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'is_plugin_active' ) ) {
			return;
		}

		$plugin       = (string) $flag['plugin'];
		$network_wide = ! empty( $flag['network_wide'] );

		if ( $network_wide ) {
			if ( is_plugin_active_for_network( $plugin ) ) {
				return;
			}
		} elseif ( is_plugin_active( $plugin ) ) {
			return;
		}

		$result = activate_plugin( $plugin, '', $network_wide, true );
		if ( is_wp_error( $result ) && class_exists( 'Remember_Logger', false ) ) {
			Remember_Logger::warning(
				'reMember could not reactivate after upgrade',
				array(
					'error' => $result->get_error_message(),
				)
			);
		}
	}
}
