<?php
/**
 * Baseline browser security headers.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Send HSTS (HTTPS sites only), frame/nosniff/referrer, and a WordPress-safe CSP.
 *
 * Does not replace headers the host already set. Tighten or disable via
 * remember_security_headers.
 */
class Remember_Security_Headers {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'send' ) );
		add_action( 'admin_init', array( __CLASS__, 'send' ), 1 );
		add_action( 'login_init', array( __CLASS__, 'send' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_rest_response' ), 10, 1 );
	}

	/**
	 * Header name => value.
	 *
	 * @return array<string,string>
	 */
	public static function headers() {
		$headers = array(
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'SAMEORIGIN',
			'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; form-action 'self' https:; frame-ancestors 'self'; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; font-src 'self' data: https:; connect-src 'self' https:; frame-src 'self' https:",
		);

		if ( self::site_uses_https() ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}

		/**
		 * Filter security headers (return an empty array to send none).
		 *
		 * @param array<string,string> $headers Name => value.
		 */
		$filtered = apply_filters( 'remember_security_headers', $headers );
		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * Whether visitors reach the site over HTTPS.
	 *
	 * is_ssl() is false behind Cloudflare or another proxy that talks to the
	 * server over plain HTTP, so the configured site address also counts.
	 * Browsers ignore HSTS received over plain HTTP, so an HTTP-only site is
	 * never locked out.
	 *
	 * @return bool
	 */
	private static function site_uses_https() {
		return is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	/**
	 * Send headers on front, wp-admin, and wp-login.php.
	 *
	 * @return void
	 */
	public static function send() {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::headers() as $name => $value ) {
			if ( '' === $name || '' === $value || self::already_sent( $name ) ) {
				continue;
			}
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Attach the same headers to REST responses.
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public static function filter_rest_response( $response ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$existing = $response->get_headers();
		$existing = is_array( $existing ) ? $existing : array();

		foreach ( self::headers() as $name => $value ) {
			if ( '' === $name || '' === $value ) {
				continue;
			}
			if ( isset( $existing[ strtolower( $name ) ] ) ) {
				continue;
			}
			$response->header( $name, $value, false );
		}

		return $response;
	}

	/**
	 * Whether PHP has already queued this header.
	 *
	 * @param string $name Header name.
	 * @return bool
	 */
	private static function already_sent( $name ) {
		$prefix = strtolower( $name ) . ':';
		foreach ( headers_list() as $line ) {
			if ( 0 === strpos( strtolower( $line ), $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
