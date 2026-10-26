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
	 * Microtime when activation tracing started (for elapsed_ms).
	 *
	 * @var float|null
	 */
	private static $activation_start_time = null;

	/**
	 * Mark the start of plugin activation (call once at the beginning of Remember_Activator::activate).
	 *
	 * @return void
	 */
	public static function mark_activation_start() {
		self::$activation_start_time = microtime( true );
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
		self::ensure_protected_log_dir();
		return trailingslashit( self::protected_log_dir() ) . 'remember.log';
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

		clearstatcache( true, $log_file );
		if ( is_file( $log_file ) && filesize( $log_file ) >= self::MAX_LOG_BYTES ) {
			$previous = $log_file . '.1';
			if ( is_file( $previous ) ) {
				@unlink( $previous );
			}
			@rename( $log_file, $previous );
		}

		// Append to the log file explicitly. A bare error_log( $msg ) follows PHP's error_log
		// ini setting, which on many hosts (e.g. Laravel Valet) is not this file.
		if ( false === @error_log( $line, 3, $log_file ) ) {
			@file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
		}
	}
}
