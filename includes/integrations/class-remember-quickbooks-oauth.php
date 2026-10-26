<?php
/**
 * QuickBooks OAuth integration class
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';

/**
 * QuickBooks OAuth integration class.
 *
 * Handles OAuth 2.0 authentication flow with QuickBooks Online.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */
class Remember_QuickBooks_OAuth {

	/**
	 * QuickBooks OAuth endpoints.
	 */
	const QB_AUTH_URL = 'https://appcenter.intuit.com/connect/oauth2';
	const QB_TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
	const QB_REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

	/**
	 * Transient prefix for the in-flight authorization attempt (suffixed with the user ID).
	 */
	const PENDING_TRANSIENT = 'remember_qb_oauth_pending_';

	/**
	 * OAuth redirect URI registered with Intuit and sent on authorize/token exchange.
	 *
	 * @return string Redirect URI for this site’s admin settings tab.
	 */
	public static function get_redirect_uri(): string {
		return admin_url( 'admin.php?page=remember-settings&tab=quickbooks&qb_oauth_callback=1' );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $state        One-time state from create_pending_oauth().
	 * @param string $scope        OAuth scope (default: accounting).
	 * @return string Authorization URL.
	 */
	public static function get_authorization_url( $client_id, $redirect_uri, $state, $scope = 'com.intuit.quickbooks.accounting' ) {
		$params = array(
			'client_id'     => $client_id,
			'response_type' => 'code',
			'scope'         => $scope,
			'redirect_uri'  => $redirect_uri,
			'state'         => $state,
		);

		return self::QB_AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Start an authorization attempt for the current user: a random one-time state plus the
	 * environment chosen on the settings tab, checked and consumed by the callback.
	 *
	 * @param string $environment sandbox|production.
	 * @return array{state:string,environment:string,user_id:int}
	 */
	public static function create_pending_oauth( $environment ) {
		$pending = array(
			'state'       => wp_generate_password( 32, false, false ),
			'environment' => in_array( $environment, array( 'sandbox', 'production' ), true ) ? $environment : 'sandbox',
			'user_id'     => get_current_user_id(),
		);
		set_transient( self::PENDING_TRANSIENT . $pending['user_id'], $pending, 15 * MINUTE_IN_SECONDS );
		return $pending;
	}

	/**
	 * In-flight authorization attempt for the current user.
	 *
	 * @return array|false Pending payload, or false when none (expired or never started).
	 */
	public static function get_pending_oauth() {
		$pending = get_transient( self::PENDING_TRANSIENT . get_current_user_id() );
		return is_array( $pending ) && ! empty( $pending['state'] ) ? $pending : false;
	}

	/**
	 * Drop the current user's in-flight authorization attempt.
	 */
	public static function clear_pending_oauth() {
		delete_transient( self::PENDING_TRANSIENT . get_current_user_id() );
	}

	/**
	 * Shared wp_remote_post() arguments for Intuit OAuth endpoints.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param array  $body          Form body.
	 * @return array
	 */
	private static function token_request_args( $client_id, $client_secret, array $body ) {
		return array(
			'headers'     => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
			),
			'body'        => $body,
			'timeout'     => 45,
			'redirection' => 0,
			'sslverify'   => true,
		);
	}

	/**
	 * Validate a token endpoint success body.
	 *
	 * @param mixed $body            Decoded JSON.
	 * @param bool  $require_refresh Whether a refresh token must be present.
	 * @return bool
	 */
	private static function is_valid_token_body( $body, $require_refresh = true ) {
		if ( ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			return false;
		}
		return ! $require_refresh || ( ! empty( $body['refresh_token'] ) && is_string( $body['refresh_token'] ) );
	}

	/**
	 * Exchange authorization code for access token.
	 *
	 * @param string $code Authorization code.
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $redirect_uri Redirect URI.
	 * @return array|WP_Error Token data or error.
	 */
	public static function exchange_code_for_token( $code, $client_id, $client_secret, $redirect_uri ) {
		$response = wp_remote_post(
			self::QB_TOKEN_URL,
			self::token_request_args(
				$client_id,
				$client_secret,
				array(
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'redirect_uri' => $redirect_uri,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			Remember_Logger::error(
				'QuickBooks authorization code exchange could not be sent',
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			Remember_Logger::error(
				'QuickBooks authorization code exchange failed',
				array(
					'status' => $status_code,
					'error'  => is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : '',
				)
			);
			return new WP_Error(
				'qb_oauth_error',
				is_array( $body ) && ! empty( $body['error_description'] ) ? (string) $body['error_description'] : __( 'Failed to exchange authorization code for token.', 'remember' ),
				$body
			);
		}

		if ( ! self::is_valid_token_body( $body ) ) {
			Remember_Logger::error( 'QuickBooks authorization code exchange returned no usable tokens', array( 'status' => $status_code ) );
			return new WP_Error( 'qb_oauth_error', __( 'QuickBooks returned an incomplete sign-in response. Please try connecting again.', 'remember' ) );
		}

		return $body;
	}

	/**
	 * Refresh access token.
	 *
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @return array|WP_Error Token data or error.
	 */
	public static function refresh_token( $refresh_token, $client_id, $client_secret ) {
		$response = wp_remote_post(
			self::QB_TOKEN_URL,
			self::token_request_args(
				$client_id,
				$client_secret,
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'qb_refresh_error',
				is_array( $body ) && ! empty( $body['error_description'] ) ? (string) $body['error_description'] : __( 'Failed to refresh access token.', 'remember' ),
				$body
			);
		}

		if ( ! self::is_valid_token_body( $body, false ) ) {
			return new WP_Error( 'qb_refresh_error', __( 'QuickBooks returned an incomplete token refresh response.', 'remember' ) );
		}

		return $body;
	}

	/**
	 * Revoke access token.
	 *
	 * @param string $token Refresh token (preferred: it ends the whole grant) or access token.
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public static function revoke_token( $token, $client_id, $client_secret ) {
		$response = wp_remote_post(
			self::QB_REVOKE_URL,
			self::token_request_args( $client_id, $client_secret, array( 'token' => $token ) )
		);

		if ( is_wp_error( $response ) ) {
			Remember_Logger::warning(
				'QuickBooks token revoke could not be sent',
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			Remember_Logger::warning( 'QuickBooks token revoke failed', array( 'status' => $status_code ) );
			return false;
		}

		return true;
	}

	/**
	 * Get stored OAuth settings.
	 *
	 * @return array|false Settings array or false if not found.
	 */
	public static function get_settings() {
		global $wpdb;
		$processor = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}remember_payment_processors WHERE processor_type = %s",
				'quickbooks'
			)
		);

		if ( ! $processor ) {
			return false;
		}

		$settings = ! empty( $processor->settings ) ? json_decode( $processor->settings, true ) : array();
		if ( ! is_array( $settings ) ) {
			Remember_Logger::error( 'QuickBooks settings could not be decoded; treating as not configured' );
			$settings = array();
		}

		// Decrypt sensitive data if encrypted
		if ( ! empty( $settings['client_secret_encrypted'] ) ) {
			$settings['client_secret'] = self::decrypt( $settings['client_secret_encrypted'] );
		}
		if ( ! empty( $settings['access_token_encrypted'] ) ) {
			$settings['access_token'] = self::decrypt( $settings['access_token_encrypted'] );
		}
		if ( ! empty( $settings['refresh_token_encrypted'] ) ) {
			$settings['refresh_token'] = self::decrypt( $settings['refresh_token_encrypted'] );
		}

		// Trim whitespace/newlines that break the Authorization header or OAuth body.
		foreach ( array( 'client_secret', 'access_token', 'refresh_token' ) as $k ) {
			if ( isset( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				$settings[ $k ] = trim( $settings[ $k ] );
			}
		}

		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
		Remember_Billing_Crypto::migrate_processor( 'quickbooks', $settings );

		return $settings;
	}

	/**
	 * Save OAuth settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public static function save_settings( $settings ) {
		global $wpdb;

		// Encrypt sensitive data
		if ( ! empty( $settings['client_secret'] ) ) {
			$settings['client_secret_encrypted'] = self::encrypt( $settings['client_secret'] );
			unset( $settings['client_secret'] );
		}
		if ( ! empty( $settings['access_token'] ) ) {
			$settings['access_token_encrypted'] = self::encrypt( $settings['access_token'] );
			unset( $settings['access_token'] );
		}
		if ( ! empty( $settings['refresh_token'] ) ) {
			$settings['refresh_token_encrypted'] = self::encrypt( $settings['refresh_token'] );
			unset( $settings['refresh_token'] );
		}

		$settings_json = json_encode( $settings );

		$result = $wpdb->update(
			$wpdb->prefix . 'remember_payment_processors',
			array(
				'settings'   => $settings_json,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'processor_type' => 'quickbooks' ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return $result !== false;
	}

	/**
	 * Encrypt sensitive data.
	 *
	 * @param string $data Data to encrypt.
	 * @return string Encrypted data.
	 */
	private static function encrypt( $data ) {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
		return Remember_Billing_Crypto::seal( $data, 'remember_qb_encryption_key' );
	}

	/**
	 * Decrypt sensitive data.
	 *
	 * @param string $data Encrypted data.
	 * @return string|false Decrypted data.
	 */
	private static function decrypt( $data ) {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
		return Remember_Billing_Crypto::open( $data, 'remember_qb_encryption_key' );
	}
}
