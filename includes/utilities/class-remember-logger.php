<?php
/**
 * Logger utility class
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Logger utility class.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */
class Remember_Logger {

	/**
	 * Size at which remember.log is rotated to remember.log.1.
	 */
	const MAX_LOG_BYTES = 5242880;

	/**
	 * An external call (Xero, QuickBooks, GitHub, email) slower than this logs a Warning.
	 */
	const SLOW_EXTERNAL_MS = 5000;

	/**
	 * A request slower than this logs one Warning at shutdown.
	 */
	const SLOW_REQUEST_MS = 3000;

	/**
	 * Microtime when activation tracing started (for elapsed_ms).
	 *
	 * @var float|null
	 */
	private static $activation_start_time = null;

	/**
	 * Log file path for this request, once its directory has been checked.
	 *
	 * @var string|null
	 */
	private static $log_file_path = null;

	/**
	 * Whether the slow-request check is registered for this request.
	 *
	 * @var bool
	 */
	private static $slow_request_hooked = false;

	/**
	 * Whether the log folder could not be created or written to in this request.
	 *
	 * @var bool
	 */
	private static $log_dir_failed = false;

	/**
	 * Whether the one-off error_log() fallback notice was already sent in this request.
	 *
	 * @var bool
	 */
	private static $log_dir_notice_sent = false;

	/**
	 * Mark the start of plugin activation (call once at the beginning of Remember_Activator::activate).
	 *
	 * @return void
	 */
	public static function mark_activation_start() {
		self::$activation_start_time = microtime( true );
	}

	/**
	 * Whether activation tracing is running in this request.
	 *
	 * @return bool
	 */
	public static function activation_in_progress() {
		return null !== self::$activation_start_time;
	}

	/**
	 * Start timing an operation.
	 *
	 * @return float Pass to elapsed_ms().
	 */
	public static function start_timer() {
		return microtime( true );
	}

	/**
	 * Milliseconds since start_timer().
	 *
	 * @param float $start Value from start_timer().
	 * @return float
	 */
	public static function elapsed_ms( $start ) {
		return round( ( microtime( true ) - (float) $start ) * 1000, 1 );
	}

	/**
	 * Log a finished external call: Debug always, plus a Warning when it was slow.
	 *
	 * @param string $what    What was called, e.g. "Xero GET Contacts".
	 * @param float  $start   Value from start_timer().
	 * @param array  $context Ids and non-personal details (status, counts).
	 * @return float Elapsed milliseconds.
	 */
	public static function external_call_done( $what, $start, $context = array() ) {
		$elapsed = self::elapsed_ms( $start );
		$context = array_merge( array( 'elapsed_ms' => $elapsed ), $context );
		self::debug( $what . ' finished', $context );
		if ( $elapsed > self::SLOW_EXTERNAL_MS ) {
			self::warning( 'Slow external call: ' . $what, $context );
		}
		return $elapsed;
	}

	/**
	 * Move logs off the public wp-content/debug.log URL and block that filename over HTTP.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		self::protect_public_debug_log();
		self::ensure_protected_log_dir();
		self::maybe_redirect_php_error_log();
		self::retire_public_debug_log();
		if ( ! self::$slow_request_hooked && function_exists( 'add_action' ) ) {
			self::$slow_request_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'maybe_log_slow_request' ), PHP_INT_MAX );
		}
	}

	/**
	 * Log one Warning when this request took longer than SLOW_REQUEST_MS.
	 *
	 * WP-CLI commands are skipped; long imports and backups log their own timing.
	 *
	 * @return void
	 */
	public static function maybe_log_slow_request() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
		if ( $started <= 0 ) {
			return;
		}
		$elapsed = self::elapsed_ms( $started );
		if ( $elapsed < self::SLOW_REQUEST_MS || ! self::should_log( 'WARNING' ) ) {
			return;
		}

		global $wpdb;
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$type = 'cron';
		} elseif ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$type = 'ajax';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$type = 'rest';
		} elseif ( is_admin() ) {
			$type = 'admin';
		} else {
			$type = 'front';
		}

		$context = array(
			'type'           => $type,
			'method'         => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '',
			'path'           => $path,
			'elapsed_ms'     => $elapsed,
			'queries'        => ( $wpdb instanceof wpdb ) ? (int) $wpdb->num_queries : 0,
			'peak_memory_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'user_id'        => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only labels for the log.
		if ( isset( $_GET['page'] ) ) {
			$context['page'] = sanitize_key( wp_unslash( $_GET['page'] ) );
		}
		if ( 'ajax' === $type && isset( $_REQUEST['action'] ) ) {
			$context['action'] = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::warning( 'Slow request', $context );
	}

	/**
	 * Apache/LiteSpeed rules that deny HTTP access to a file or directory.
	 *
	 * @return string
	 */
	private static function deny_all_htaccess() {
		return "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
	}

	/**
	 * Unguessable directory under uploads for log files (nginx ignores .htaccess).
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	public static function protected_log_dir() {
		$key = get_option( 'remember_log_dir_key', '' );
		if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9]{32}$/', $key ) ) {
			$key = wp_generate_password( 32, false, false );
			update_option( 'remember_log_dir_key', $key, true );
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$base    = ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) )
			? $uploads['basedir']
			: WP_CONTENT_DIR . '/uploads';

		return trailingslashit( $base ) . 'remember-logs/' . $key;
	}

	/**
	 * Create the log directory with deny-all .htaccess and index.php.
	 *
	 * @return void
	 */
	public static function ensure_protected_log_dir() {
		$dir     = self::protected_log_dir();
		$parent  = dirname( $dir );
		$silence = "<?php\n// Silence is golden.\n";
		$deny    = self::deny_all_htaccess();

		if ( ! is_dir( $parent ) ) {
			wp_mkdir_p( $parent );
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$files = array(
			$parent . '/.htaccess' => $deny,
			$parent . '/index.php' => $silence,
			$dir . '/.htaccess'    => $deny,
			$dir . '/index.php'    => $silence,
		);
		foreach ( $files as $path => $contents ) {
			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- drop web-deny stubs.
				@file_put_contents( $path, $contents );
			}
		}
	}

	/**
	 * Deny HTTP GET of wp-content/debug.log (Apache / LiteSpeed). Idempotent.
	 *
	 * @return void
	 */
	public static function protect_public_debug_log() {
		$htaccess = WP_CONTENT_DIR . '/.htaccess';
		$marker   = 'reMember debug.log';
		if ( is_readable( $htaccess ) ) {
			$existing = (string) file_get_contents( $htaccess );
			if ( false !== strpos( $existing, '# BEGIN ' . $marker ) ) {
				return;
			}
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$rules = array(
			'<Files "debug.log">',
			"\t<IfModule mod_authz_core.c>",
			"\t\tRequire all denied",
			"\t</IfModule>",
			"\t<IfModule !mod_authz_core.c>",
			"\t\tOrder deny,allow",
			"\t\tDeny from all",
			"\t</IfModule>",
			'</Files>',
		);
		insert_with_markers( $htaccess, $marker, $rules );
	}

	/**
	 * Point PHP's error_log at the protected file when WP_DEBUG_LOG is the boolean default.
	 *
	 * WordPress itself would otherwise keep appending wp-content/debug.log (public URL).
	 *
	 * @return void
	 */
	public static function maybe_redirect_php_error_log() {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		if ( is_string( WP_DEBUG_LOG ) ) {
			return;
		}

		$path = self::get_wp_debug_log_path();
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- relocate debug.log off a public URL.
		ini_set( 'log_errors', '1' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky
		ini_set( 'error_log', $path );
	}

	/**
	 * Copy any leftover public wp-content/debug.log into the protected file, then delete it.
	 *
	 * @return void
	 */
	public static function retire_public_debug_log() {
		$public = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_file( $public ) || ! is_readable( $public ) ) {
			return;
		}

		$protected = self::get_wp_debug_log_path();
		$public_real    = realpath( $public );
		$protected_real = is_file( $protected ) ? realpath( $protected ) : false;
		if ( $public_real && $protected_real && $public_real === $protected_real ) {
			return;
		}

		$size = (int) filesize( $public );
		if ( $size > 0 && $size < 5 * 1024 * 1024 ) {
			$chunk = file_get_contents( $public );
			if ( is_string( $chunk ) && '' !== $chunk ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $protected, $chunk, FILE_APPEND | LOCK_EX );
			}
		}

		if ( is_writable( $public ) ) {
			@unlink( $public );
		}
	}

	/**
	 * Development / troubleshooting line (sync and QuickBooks diagnostics) at the Debug log level.
	 *
	 * @param string $message Short message.
	 * @param array  $context Context data.
	 */
	public static function dev_log( $message, $context = array() ) {
		if ( ! self::should_log( 'DEBUG' ) ) {
			return;
		}

		$timestamp = current_time( 'mysql' );
		$log_entry = sprintf( '[%s] [reMember] [DEV] %s', $timestamp, $message );
		if ( ! empty( $context ) ) {
			$log_entry .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		self::write( $log_entry . "\n" );
	}

	/**
	 * Activation and schema-update progress at the Debug log level.
	 *
	 * @param string $label   Short step name.
	 * @param array  $context Extra context (merged with elapsed_ms, peak_memory_mb).
	 * @return void
	 */
	public static function activation_debug( $label, $context = array() ) {
		if ( ! self::should_log( 'DEBUG' ) ) {
			return;
		}

		if ( self::$activation_start_time === null ) {
			self::$activation_start_time = microtime( true );
		}

		$elapsed_ms = round( ( microtime( true ) - self::$activation_start_time ) * 1000, 1 );
		$peak_mb    = function_exists( 'memory_get_peak_usage' )
			? round( memory_get_peak_usage( true ) / 1048576, 2 )
			: 0.0;

		$ctx = array_merge(
			array(
				'elapsed_ms'     => $elapsed_ms,
				'peak_memory_mb' => $peak_mb,
			),
			$context
		);

		$timestamp = current_time( 'mysql' );
		$log_entry   = sprintf(
			'[%s] [reMember] [ACTIVATION] %s | %s' . "\n",
			$timestamp,
			$label,
			wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		self::write( $log_entry );
	}

	/**
	 * Get the configured log level.
	 *
	 * Independent of WP_DEBUG_LOG; that constant only controls WordPress's own debug log.
	 *
	 * @return string Log level (NONE, ERROR, WARNING, INFO, DEBUG).
	 */
	private static function get_log_level() {
		$options = get_option( 'remember_options', array() );
		return isset( $options['log_level'] ) ? $options['log_level'] : 'ERROR';
	}

	/**
	 * Check if a log level should be logged.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if should log, false otherwise.
	 */
	private static function should_log( $level ) {
		$configured_level = self::get_log_level();
		
		if ( 'NONE' === $configured_level ) {
			return false;
		}
		
		$levels = array( 'NONE' => 0, 'ERROR' => 1, 'WARNING' => 2, 'INFO' => 3, 'DEBUG' => 4 );
		$level_priority = isset( $levels[ $level ] ) ? $levels[ $level ] : 0;
		$configured_priority = isset( $levels[ $configured_level ] ) ? $levels[ $configured_level ] : 0;
		
		return $level_priority <= $configured_priority;
	}

	/**
	 * Absolute path of reMember's own log, in the protected log directory.
	 *
	 * @return string
	 */
	public static function get_log_file_path() {
		if ( null === self::$log_file_path || ( ! self::$log_dir_failed && ! is_dir( dirname( self::$log_file_path ) ) ) ) {
			self::ensure_protected_log_dir();
			self::$log_file_path = trailingslashit( self::protected_log_dir() ) . 'remember.log';
			if ( ! is_dir( dirname( self::$log_file_path ) ) ) {
				// Do not retry mkdir on every log call for the rest of this request.
				self::$log_dir_failed = true;
			}
		}
		return self::$log_file_path;
	}

	/**
	 * Whether reMember's log folder exists and can be written to.
	 *
	 * Writes nothing to the log, so it is safe to call from the Settings page.
	 *
	 * @return bool
	 */
	public static function is_log_dir_writable() {
		$file = self::get_log_file_path();
		$dir  = dirname( $file );
		if ( self::$log_dir_failed || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		// The folder can be fine while an existing log file cannot be appended to.
		if ( file_exists( $file ) && ! is_writable( $file ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Remember that the log folder is unusable and tell PHP's error log once per request.
	 *
	 * @return void
	 */
	private static function note_log_dir_failure() {
		self::$log_dir_failed = true;
		if ( ! self::$log_dir_notice_sent ) {
			self::$log_dir_notice_sent = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort fallback when our own log cannot be written.
			error_log( 'reMember: the plugin log folder is not writable, so log entries are not being saved.' );
		}
	}

	/**
	 * Absolute path of WordPress's PHP debug log.
	 *
	 * Custom WP_DEBUG_LOG string paths are honored. Otherwise it goes under the
	 * protected directory, not the public /wp-content/debug.log URL.
	 *
	 * @return string
	 */
	public static function get_wp_debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && is_string( WP_DEBUG_LOG ) ) {
			return WP_DEBUG_LOG;
		}

		self::ensure_protected_log_dir();
		return trailingslashit( self::protected_log_dir() ) . 'debug.log';
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function debug( $message, $context = array() ) {
		if ( self::should_log( 'DEBUG' ) ) {
			self::log( 'DEBUG', $message, $context );
		}
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function info( $message, $context = array() ) {
		if ( self::should_log( 'INFO' ) ) {
			self::log( 'INFO', $message, $context );
		}
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function warning( $message, $context = array() ) {
		if ( self::should_log( 'WARNING' ) ) {
			self::log( 'WARNING', $message, $context );
		}
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function error( $message, $context = array() ) {
		if ( self::should_log( 'ERROR' ) ) {
			self::log( 'ERROR', $message, $context );
		}
	}

	/**
	 * Write log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	private static function log( $level, $message, $context = array() ) {
		$timestamp = current_time( 'mysql' );
		$log_entry = sprintf(
			'[%s] [reMember] [%s] %s',
			$timestamp,
			$level,
			$message
		);

		if ( ! empty( $context ) ) {
			$log_entry .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		self::write( $log_entry . "\n" );
	}

	/**
	 * Append a line to remember.log, rotating it to remember.log.1 at MAX_LOG_BYTES.
	 *
	 * @param string $line Complete log line, newline included.
	 * @return void
	 */
	private static function write( $line ) {
		$log_file = self::get_log_file_path();

		if ( self::$log_dir_failed ) {
			self::note_log_dir_failure();
			return;
		}

		clearstatcache( true, $log_file );
		if ( is_file( $log_file ) && filesize( $log_file ) >= self::MAX_LOG_BYTES ) {
			self::rotate( $log_file );
		}

		// Append to the log file explicitly. A bare error_log( $msg ) follows PHP's error_log
		// ini setting, which on many hosts (e.g. Laravel Valet) is not this file.
		if ( false === @error_log( $line, 3, $log_file ) ) {
			if ( false === @file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX ) ) {
				self::note_log_dir_failure();
			}
		}
	}

	/**
	 * Rotate remember.log to remember.log.1 under an exclusive lock.
	 *
	 * The size is re-checked once the lock is held so a request that lost the race does not
	 * rotate again and delete the file the winner just rotated. If another request holds the
	 * lock, rotation is skipped this time and logging carries on. If locking is unavailable,
	 * it rotates without the lock, as before.
	 *
	 * @param string $log_file Absolute path of remember.log.
	 * @return void
	 */
	private static function rotate( $log_file ) {
		$lock        = @fopen( $log_file . '.lock', 'c' );
		$locked      = false;
		$would_block = 0;
		if ( false !== $lock ) {
			$locked = @flock( $lock, LOCK_EX | LOCK_NB, $would_block );
		}

		// Another request holds the lock and is rotating: skip. If locking is not available here
		// (no lock file, or flock unsupported), rotate without it so the log still cannot grow forever.
		if ( $locked || ! $would_block ) {
			clearstatcache( true, $log_file );
			if ( is_file( $log_file ) && filesize( $log_file ) >= self::MAX_LOG_BYTES ) {
				$previous = $log_file . '.1';
				if ( is_file( $previous ) ) {
					@unlink( $previous );
				}
				@rename( $log_file, $previous );
			}
		}

		if ( false !== $lock ) {
			if ( $locked ) {
				@flock( $lock, LOCK_UN );
			}
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}
}
