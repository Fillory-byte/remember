<?php
/**
 * Xero OAuth integration class
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Xero OAuth 2.0 authentication (parallel to Remember_QuickBooks_OAuth).
 *
 * Stores credentials and tokens in remember_payment_processors (processor_type = xero).
 * Secrets are encrypted at rest. New values use libsodium. Older values still open with the previous key.
 *
 * @package    reMember
 * @subpackage reMember/includes/integrations
 */
class Remember_Xero_OAuth {

	/**
	 * Xero Identity endpoints.
	 */
	const AUTH_URL   = 'https://login.xero.com/identity/connect/authorize';
	const TOKEN_URL  = 'https://identity.xero.com/connect/token';
	const REVOKE_URL = 'https://identity.xero.com/connect/revocation';
	const CONNECTIONS_URL = 'https://api.xero.com/connections';

	/**
	 * User-Agent for Xero Identity and API calls.
	 *
	 * Akamai in front of identity.xero.com / api.xero.com returns HTTP 403 HTML
	 * when the default WordPress user-agent is missing or overwritten.
	 *
	 * @return string
	 */
	private static function http_user_agent() {
		return 'reMember-WordPress/' . ( defined( 'REMEMBER_VERSION' ) ? REMEMBER_VERSION : '1.1.0' );
	}

	/**
	 * Default OAuth scopes for contacts, invoices/payments, and items/settings.
	 *
	 * New Xero apps (created on/after 2026-03-02) require granular scopes —
	 * the broad `accounting.transactions` scope returns invalid_scope.
	 *
	 * @return string Space-separated scopes.
	 * @link https://developer.xero.com/faq/granular-scopes
	 */
	public static function get_default_scopes() {
		return 'openid profile email offline_access accounting.contacts accounting.invoices accounting.payments accounting.settings';
	}

	/**
	 * OAuth redirect URI registered with Xero and sent on authorize/token exchange.
	 *
	 * @return string Redirect URI for this site's admin settings Xero tab.
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php?page=remember-settings&tab=xero&xero_oauth_callback=1' );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $redirect_uri  Redirect URI.
	 * @param string $scope         OAuth scopes (space-separated).
	 * @return string Authorization URL.
	 */
	public static function get_authorization_url( $client_id, $redirect_uri, $scope = '', $extra = array() ) {
		if ( '' === $scope ) {
			$scope = self::get_default_scopes();
		}

		$params = array(
			'response_type' => 'code',
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'scope'         => $scope,
			'state'         => ! empty( $extra['state'] ) ? (string) $extra['state'] : wp_generate_password( 32, false, false ),
			'prompt'        => 'consent',
		);

		if ( ! empty( $extra['code_challenge'] ) ) {
			$params['code_challenge']        = (string) $extra['code_challenge'];
			$params['code_challenge_method'] = 'S256';
		}

		return self::AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Transient prefix for an in-flight authorization; the user ID is appended.
	 */
	const PENDING_TRANSIENT = 'remember_xero_oauth_pending_';

	/**
	 * Start an OAuth attempt for the current user: store state, redirect URI, and PKCE verifier for the callback.
	 *
	 * @return array{state:string,redirect_uri:string,code_verifier:string,user_id:int}
	 */
	public static function create_pending_oauth() {
		$pending = array(
			'state'         => wp_generate_password( 32, false, false ),
			'redirect_uri'  => self::get_redirect_uri(),
			'code_verifier' => wp_generate_password( 64, false, false ),
			'user_id'       => get_current_user_id(),
		);
		set_transient( self::PENDING_TRANSIENT . $pending['user_id'], $pending, 15 * MINUTE_IN_SECONDS );
		return $pending;
	}

	/**
	 * In-flight authorization attempt started by the current user.
	 *
	 * @return array|false Pending payload, or false when none (expired, never started, or another user's).
	 */
	public static function get_pending_oauth() {
		$user_id = get_current_user_id();
		$pending = get_transient( self::PENDING_TRANSIENT . $user_id );
		if ( ! is_array( $pending ) || empty( $pending['state'] ) || ! isset( $pending['user_id'] ) || (int) $pending['user_id'] !== $user_id ) {
			return false;
		}
		return $pending;
	}

	/**
	 * Drop the current user's in-flight OAuth attempt.
	 */
	public static function clear_pending_oauth() {
		delete_transient( self::PENDING_TRANSIENT . get_current_user_id() );
	}

	/**
	 * S256 code challenge for a PKCE verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function pkce_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Persist the last OAuth result for the Xero settings tab (survives the 60s flash notice).
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	public static function set_last_oauth_result( $type, $message ) {
		$payload = array(
			'type'    => $type,
			'message' => $message,
			'at'      => time(),
		);
		update_option( 'remember_xero_last_oauth', $payload, false );
		$uid = get_current_user_id();
		if ( $uid ) {
			set_transient(
				'remember_xero_oauth_notice_' . $uid,
				array(
					'type'    => $type,
					'message' => $message,
				),
				15 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * @return array{type?:string,message?:string,at?:int}
	 */
	public static function get_last_oauth_result() {
		$saved = get_option( 'remember_xero_last_oauth' );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Clear a successful (or dismissed) OAuth result.
	 */
	public static function clear_last_oauth_result() {
		delete_option( 'remember_xero_last_oauth' );
	}

	/**
	 * Exchange authorization code for access token.
	 *
	 * @param string $code          Authorization code.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $redirect_uri  Redirect URI.
	 * @return array|WP_Error Token data or error.
	 */
	public static function exchange_code_for_token( $code, $client_id, $client_secret, $redirect_uri, $code_verifier = '' ) {
		$body = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirect_uri,
		);
		if ( '' !== $code_verifier ) {
			$body['code_verifier'] = $code_verifier;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers'     => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				),
				'body'        => $body,
				'user-agent'  => self::http_user_agent(),
				'timeout'     => 45,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$message = sprintf(
				/* translators: %d: HTTP status */
				__( 'Failed to exchange authorization code for token (HTTP %d).', 'remember' ),
				$status_code
			);
			if ( is_array( $body ) ) {
				if ( ! empty( $body['error_description'] ) ) {
					$message = (string) $body['error_description'];
				} elseif ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
					$message = $body['error'];
				}
			} elseif ( 403 === $status_code ) {
				$message = __( 'Xero blocked the token request (HTTP 403). The identity host often rejects WordPress’s default user-agent.', 'remember' );
			}
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';
			Remember_Logger::error(
				'Xero authorization code exchange failed',
				array(
					'status' => $status_code,
					'error'  => is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : '',
				)
			);
			return new WP_Error( 'xero_oauth_error', $message, $body );
		}

		return is_array( $body ) ? $body : new WP_Error( 'xero_oauth_error', __( 'Invalid token response from Xero.', 'remember' ) );
	}

	/**
	 * Refresh access token.
	 *
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @return array|WP_Error Token data or error.
	 */
	public static function refresh_token( $refresh_token, $client_id, $client_secret ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'headers'     => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				),
				'body'        => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				),
				'user-agent'  => self::http_user_agent(),
				'timeout'     => 45,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$message = __( 'Failed to refresh Xero access token. Reconnect Xero in Settings.', 'remember' );
			if ( is_array( $body ) ) {
				if ( ! empty( $body['error_description'] ) ) {
					$message = (string) $body['error_description'];
				} elseif ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
					$message = $body['error'];
				}
			} elseif ( 403 === (int) $status_code ) {
				$message = __( 'Xero blocked the token refresh (HTTP 403). The identity host often rejects WordPress’s default user-agent.', 'remember' );
			}
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';
			Remember_Logger::error(
				'Xero token refresh failed',
				array(
					'status' => (int) $status_code,
					'error'  => is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : '',
				)
			);
			return new WP_Error( 'xero_refresh_error', $message, $body );
		}

		return is_array( $body ) ? $body : new WP_Error( 'xero_refresh_error', __( 'Invalid refresh response from Xero.', 'remember' ) );
	}

	/**
	 * Revoke a refresh token (preferred) or access token.
	 *
	 * @param string $token         Token to revoke.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public static function revoke_token( $token, $client_id, $client_secret ) {
		$response = wp_remote_post(
			self::REVOKE_URL,
			array(
				'headers'     => array(
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				),
				'body'        => array(
					'token' => $token,
				),
				'user-agent'  => self::http_user_agent(),
				'timeout'     => 45,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		return ( $status_code >= 200 && $status_code < 300 );
	}

	/**
	 * List Xero organisation connections for the current access token.
	 *
	 * @param string $access_token Access token.
	 * @return array|WP_Error List of connection arrays or error.
	 */
	public static function get_connections( $access_token ) {
		$access_token = is_string( $access_token ) ? trim( $access_token ) : '';
		if ( '' === $access_token ) {
			return new WP_Error( 'xero_connections_error', __( 'Missing Xero access token when loading connections.', 'remember' ) );
		}

		/*
		 * Do not overwrite CURLOPT_HTTPHEADER wholesale — that can strip User-Agent and
		 * trigger Akamai "Access Denied" (HTTP 403 HTML) in front of api.xero.com.
		 */
		$response = wp_remote_get(
			self::CONNECTIONS_URL,
			array(
				'headers'     => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'user-agent'  => self::http_user_agent(),
				'timeout'     => 45,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'xero_connections_error',
				sprintf(
					/* translators: %s: transport error */
					__( 'Xero connections request failed: %s', 'remember' ),
					$response->get_error_message()
				),
				array( 'transport' => $response->get_error_code() )
			);
		}

		$raw_body    = wp_remote_retrieve_body( $response );
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( $raw_body, true );

		if ( 200 !== $status_code || ! is_array( $body ) ) {
			$detail = '';
			if ( is_array( $body ) ) {
				if ( ! empty( $body['Detail'] ) ) {
					$detail = (string) $body['Detail'];
				} elseif ( ! empty( $body['Title'] ) ) {
					$detail = (string) $body['Title'];
				} elseif ( ! empty( $body['error'] ) ) {
					$detail = is_string( $body['error'] ) ? $body['error'] : wp_json_encode( $body['error'] );
				}
			} elseif ( is_string( $raw_body ) && '' !== $raw_body ) {
				$detail = substr( wp_strip_all_tags( $raw_body ), 0, 240 );
			}

			$message = sprintf(
				/* translators: %d: HTTP status */
				__( 'Failed to load Xero organisation connections (HTTP %d).', 'remember' ),
				$status_code
			);
			if ( $detail ) {
				$message .= ' ' . $detail;
			}

			return new WP_Error(
				'xero_connections_error',
				$message,
				array(
					'status' => $status_code,
					'body'   => is_array( $body ) ? $body : $raw_body,
				)
			);
		}

		return $body;
	}

	/**
	 * Whether Xero appears connected (token + tenant).
	 *
	 * @param array|false $settings Settings from get_settings(), or null to load.
	 * @return bool
	 */
	public static function is_connected( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}
		return is_array( $settings )
			&& ! empty( $settings['access_token'] )
			&& ! empty( $settings['tenant_id'] );
	}

	/**
	 * Get stored OAuth settings (decrypts secrets).
	 *
	 * @return array|false Settings array or false if processor row missing.
	 */
	public static function get_settings() {
		global $wpdb;
		$processor = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}remember_payment_processors WHERE processor_type = %s",
				'xero'
			)
		);

		if ( ! $processor ) {
			return false;
		}

		$settings = ! empty( $processor->settings ) ? json_decode( $processor->settings, true ) : array();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! empty( $settings['client_secret_encrypted'] ) ) {
			$settings['client_secret'] = self::decrypt( $settings['client_secret_encrypted'] );
		}
		if ( ! empty( $settings['access_token_encrypted'] ) ) {
			$settings['access_token'] = self::decrypt( $settings['access_token_encrypted'] );
		}
		if ( ! empty( $settings['refresh_token_encrypted'] ) ) {
			$settings['refresh_token'] = self::decrypt( $settings['refresh_token_encrypted'] );
		}

		foreach ( array( 'client_secret', 'access_token', 'refresh_token' ) as $k ) {
			if ( isset( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				$settings[ $k ] = trim( $settings[ $k ] );
			}
		}

		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
		Remember_Billing_Crypto::migrate_processor( 'xero', $settings );

		return $settings;
	}

	/**
	 * Save OAuth settings (encrypts secrets). Merges with existing when possible.
	 *
	 * @param array $settings Settings to save.
	 * @return bool True on success.
	 */
	public static function save_settings( $settings ) {
		global $wpdb;

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

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

		$settings_json = wp_json_encode( $settings );

		$result = $wpdb->update(
			$wpdb->prefix . 'remember_payment_processors',
			array(
				'settings'   => $settings_json,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'processor_type' => 'xero' ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Encrypt sensitive data.
	 *
	 * @param string $data Data to encrypt.
	 * @return string Encrypted data.
	 */
	private static function encrypt( $data ) {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
		return Remember_Billing_Crypto::seal( $data, 'remember_xero_encryption_key' );
	}

	/**
	 * Decrypt sensitive data.
	 *
	 * @param string $data Encrypted data.
	 * @return string|false Decrypted data.
	 */
	private static function decrypt( $data ) {
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
		return Remember_Billing_Crypto::open( $data, 'remember_xero_encryption_key' );
	}
}
