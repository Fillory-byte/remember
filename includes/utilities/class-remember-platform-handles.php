<?php
/**
 * Profile addresses for social and instant-messenger platforms.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Turns a member's platform name into a link, and refuses a typed @.
 */
class Remember_Platform_Handles {

	/**
	 * Message shown when a save includes @.
	 *
	 * @return string
	 */
	public static function at_message() {
		return __( 'Do not type @. Type only the name.', 'remember' );
	}

	/**
	 * Help shown beside a name field when the platform shows @.
	 *
	 * @return string
	 */
	public static function at_help() {
		return __( 'Do not type @. Type only the name.', 'remember' );
	}

	/**
	 * Whether the catalog tables have the profile-URL columns.
	 *
	 * @return bool
	 */
	public static function columns_ready() {
		static $ready = null;
		if ( null !== $ready ) {
			return $ready;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'remember_im_platforms';
		$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$ready = is_array( $cols ) && in_array( 'url_template', $cols, true ) && in_array( 'show_at', $cols, true );
		return $ready;
	}

	/**
	 * Keep an empty template, or an http(s) address that contains {handle} once.
	 *
	 * @param string $raw Posted template.
	 * @return string Empty when the value is blank or not allowed.
	 */
	public static function sanitize_template( $raw ) {
		$template = trim( sanitize_text_field( (string) $raw ) );
		if ( '' === $template ) {
			return '';
		}
		if ( 1 !== substr_count( $template, '{handle}' ) ) {
			return '';
		}
		if ( ! preg_match( '#^https://#i', $template ) && ! preg_match( '#^http://#i', $template ) ) {
			return '';
		}
		return $template;
	}

	/**
	 * Add the profile-URL columns, fill known addresses, and drop a leading @ already stored for Telegram.
	 *
	 * @return bool
	 */
	public static function install() {
		global $wpdb;
		$tables = array(
			$wpdb->prefix . 'remember_im_platforms',
			$wpdb->prefix . 'remember_social_media_platforms',
		);
		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			if ( ! is_array( $cols ) ) {
				return false;
			}
			if ( ! in_array( 'url_template', $cols, true ) ) {
				$ok = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN url_template VARCHAR(255) DEFAULT NULL AFTER platform_name" );
				if ( false === $ok ) {
					return false;
				}
			}
			if ( ! in_array( 'show_at', $cols, true ) ) {
				$ok = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN show_at TINYINT(1) DEFAULT 0 AFTER url_template" );
				if ( false === $ok ) {
					return false;
				}
			}
		}

		$im = $wpdb->prefix . 'remember_im_platforms';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$im} SET url_template = %s, show_at = 1 WHERE platform_key = %s AND (url_template IS NULL OR url_template = '')",
				'https://t.me/{handle}',
				'telegram'
			)
		);

		$social = $wpdb->prefix . 'remember_social_media_platforms';
		foreach ( self::social_templates() as $name => $template ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$social} SET url_template = %s WHERE platform_name = %s AND (url_template IS NULL OR url_template = '')",
					$template,
					$name
				)
			);
		}

		$profiles = $wpdb->prefix . 'remember_member_profiles';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$profiles} SET im_handle = TRIM(LEADING '@' FROM im_handle) WHERE im_type = %s AND im_handle LIKE %s",
				'telegram',
				'@%'
			)
		);

		return true;
	}

	/**
	 * Social platforms whose public profile address is one obvious pattern.
	 *
	 * @return array<string, string> Platform name => template.
	 */
	public static function social_templates() {
		return array(
			'Instagram'   => 'https://instagram.com/{handle}',
			'X (Twitter)' => 'https://x.com/{handle}',
			'Facebook'    => 'https://www.facebook.com/{handle}',
			'TikTok'      => 'https://www.tiktok.com/@{handle}',
			'Snapchat'    => 'https://www.snapchat.com/add/{handle}',
			'Bluesky'     => 'https://bsky.app/profile/{handle}',
			'LinkedIn'    => 'https://www.linkedin.com/in/{handle}',
		);
	}

	/**
	 * Instant-messenger row for a stored key.
	 *
	 * @param string $key platform_key.
	 * @return object|null
	 */
	public static function im_platform( $key ) {
		global $wpdb;
		$key = sanitize_key( (string) $key );
		if ( '' === $key || ! self::columns_ready() ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT platform_key, platform_name, url_template, show_at FROM {$wpdb->prefix}remember_im_platforms WHERE platform_key = %s LIMIT 1",
				$key
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Social row for a platform id.
	 *
	 * @param int $platform_id Platform id.
	 * @return object|null
	 */
	public static function social_platform( $platform_id ) {
		global $wpdb;
		$platform_id = absint( $platform_id );
		if ( $platform_id < 1 || ! self::columns_ready() ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT platform_id, platform_name, url_template, show_at FROM {$wpdb->prefix}remember_social_media_platforms WHERE platform_id = %d LIMIT 1",
				$platform_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Field errors for an @ typed on a platform that shows one.
	 *
	 * @return array<string, string> Field key => message.
	 */
	public static function at_errors_from_request() {
		$errors  = array();
		$im_type = '';
		$handle  = '';
		if ( isset( $_POST['im_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
			$im_type = sanitize_key( wp_unslash( $_POST['im_type'] ) );
		} elseif ( isset( $_POST['remember_reg_im_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$im_type = sanitize_key( wp_unslash( $_POST['remember_reg_im_type'] ) );
		}
		if ( isset( $_POST['im_handle'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$handle = sanitize_text_field( wp_unslash( $_POST['im_handle'] ) );
		} elseif ( isset( $_POST['remember_reg_im_handle'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$handle = sanitize_text_field( wp_unslash( $_POST['remember_reg_im_handle'] ) );
		}
		$im = self::im_platform( $im_type );
		if ( $im && ! empty( $im->show_at ) && false !== strpos( $handle, '@' ) ) {
			$errors['im_handle'] = self::at_message();
		}
		foreach ( self::posted_social_rows() as $row ) {
			$social = self::social_platform( $row['platform_id'] );
			if ( $social && ! empty( $social->show_at ) && '' !== $row['handle'] && false !== strpos( $row['handle'], '@' ) ) {
				$errors[ $row['field'] ] = self::at_message();
			}
		}
		return $errors;
	}

	/**
	 * Social rows posted from the profile form or the wp-admin member screen.
	 *
	 * @return array<int, array{platform_id:int, handle:string, field:string}>
	 */
	public static function posted_social_rows() {
		if ( ! isset( $_POST['social_media'] ) || ! is_array( $_POST['social_media'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}
		$rows = array();
		foreach ( $_POST['social_media'] as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( is_array( $value ) ) {
				$platform_id = isset( $value['platform_id'] ) ? absint( $value['platform_id'] ) : 0;
				$handle      = isset( $value['handle'] ) ? sanitize_text_field( wp_unslash( $value['handle'] ) ) : '';
				$field       = 'social_handle_' . absint( $key );
			} else {
				$platform_id = absint( $key );
				$handle      = sanitize_text_field( wp_unslash( $value ) );
				$field       = 'social_handle_' . $platform_id;
			}
			$rows[] = array(
				'platform_id' => $platform_id,
				'handle'      => $handle,
				'field'       => $field,
			);
		}
		return $rows;
	}

	/**
	 * Store a pasted profile address as the name. A typed @ is left in place so the save can refuse it.
	 *
	 * @param string $handle   Posted name or address.
	 * @param string $template Platform template, or empty.
	 * @param bool   $show_at  Whether this platform refuses @.
	 * @return string
	 */
	public static function handle_for_storage( $handle, $template, $show_at ) {
		$handle = trim( (string) $handle );
		if ( $show_at && false !== strpos( $handle, '@' ) ) {
			return $handle;
		}
		$extracted = self::extract_handle( $template, $handle );
		return null !== $extracted ? $extracted : $handle;
	}

	/**
	 * Name to store for an instant-messenger platform.
	 *
	 * @param string $key    platform_key.
	 * @param string $handle Posted name.
	 * @return string
	 */
	public static function im_handle_for_storage( $key, $handle ) {
		$row = self::im_platform( $key );
		if ( ! $row ) {
			return trim( (string) $handle );
		}
		return self::handle_for_storage( $handle, (string) $row->url_template, ! empty( $row->show_at ) );
	}

	/**
	 * Name to store for a social platform.
	 *
	 * @param int    $platform_id Platform id.
	 * @param string $handle      Posted name.
	 * @return string
	 */
	public static function social_handle_for_storage( $platform_id, $handle ) {
		$row = self::social_platform( $platform_id );
		if ( ! $row ) {
			return trim( (string) $handle );
		}
		return self::handle_for_storage( $handle, (string) $row->url_template, ! empty( $row->show_at ) );
	}

	/**
	 * Pull {handle} out of an address that matches the template.
	 *
	 * @param string $template Platform template.
	 * @param string $value    Posted value.
	 * @return string|null Name, or null when the value is not that address.
	 */
	public static function extract_handle( $template, $value ) {
		$template = trim( (string) $template );
		$value    = trim( (string) $value );
		if ( '' === $template || '' === $value || 1 !== substr_count( $template, '{handle}' ) ) {
			return null;
		}
		$parts = explode( '{handle}', $template, 2 );
		$pattern = '#^' . preg_quote( $parts[0], '#' ) . '(.+?)' . preg_quote( $parts[1], '#' ) . '/?$#i';
		if ( ! preg_match( $pattern, $value, $match ) ) {
			return null;
		}
		$name = rawurldecode( $match[1] );
		$name = trim( $name, "/ \t" );
		if ( '' === $name || false !== strpos( $name, '/' ) ) {
			return null;
		}
		return $name;
	}

	/**
	 * Visible name. Platforms that show @ get one in front, even if an old row still stored it.
	 *
	 * @param string $handle  Stored name.
	 * @param bool   $show_at Whether to show a leading @.
	 * @return string
	 */
	public static function display_name( $handle, $show_at ) {
		$name = ltrim( trim( (string) $handle ), '@' );
		if ( '' === $name ) {
			return '';
		}
		return $show_at ? '@' . $name : $name;
	}

	/**
	 * Profile address for a stored name, or empty when this platform has no template.
	 *
	 * @param string $template Platform template.
	 * @param string $handle   Stored name.
	 * @return string
	 */
	public static function href( $template, $handle ) {
		$template = trim( (string) $template );
		$name     = ltrim( trim( (string) $handle ), '@' );
		if ( '' === $template || '' === $name || 1 !== substr_count( $template, '{handle}' ) ) {
			return '';
		}
		if ( preg_match( '/[\s<>"\']/', $name ) || preg_match( '#https?://#i', $name ) ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $template ) ) {
			return '';
		}
		$url = str_replace( '{handle}', rawurlencode( $name ), $template );
		return (string) esc_url_raw( $url );
	}

	/**
	 * Linked name for an instant-messenger handle, or the plain name.
	 *
	 * @param string $key    platform_key.
	 * @param string $handle Stored name.
	 * @return string HTML.
	 */
	public static function im_html( $key, $handle ) {
		$row     = self::im_platform( $key );
		$show_at = $row && ! empty( $row->show_at );
		$template = $row ? (string) $row->url_template : '';
		return self::link_html( $template, $handle, $show_at );
	}

	/**
	 * Linked name for a social handle, or the plain name.
	 *
	 * @param int    $platform_id Platform id.
	 * @param string $handle      Stored name.
	 * @return string HTML.
	 */
	public static function social_html( $platform_id, $handle ) {
		$row      = self::social_platform( $platform_id );
		$show_at  = $row && ! empty( $row->show_at );
		$template = $row ? (string) $row->url_template : '';
		return self::link_html( $template, $handle, $show_at );
	}

	/**
	 * Anchor for a platform name, or escaped text when there is no address.
	 *
	 * @param string $template Platform template.
	 * @param string $handle   Stored name.
	 * @param bool   $show_at  Whether to show a leading @.
	 * @return string HTML.
	 */
	public static function link_html( $template, $handle, $show_at ) {
		$text = self::display_name( $handle, $show_at );
		if ( '' === $text ) {
			$text = trim( (string) $handle );
		}
		$href = self::href( $template, $handle );
		if ( '' === $href ) {
			return esc_html( $text );
		}
		return '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Help under a name field. Hidden until the chosen platform shows @.
	 *
	 * @param bool   $visible Whether the chosen platform shows @.
	 * @param string $class   Paragraph classes.
	 * @return void
	 */
	public static function render_help( $visible, $class ) {
		echo '<p class="' . esc_attr( $class ) . '"' . ( $visible ? '' : ' hidden' ) . '>' . esc_html( self::at_help() ) . '</p>';
	}

	/**
	 * Show or hide the @ help when the platform menu changes.
	 *
	 * @return void
	 */
	public static function print_toggle_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		echo '<script>
		(function () {
			function rememberSyncAtHelp(select) {
				var option = select.options[select.selectedIndex];
				var show = false;
				if (option) {
					show = option.getAttribute("data-remember-show-at") === "1";
				}
				var scope = select.closest(".remember-form-row, .remember-register-row, .remember-social-media-row, tr");
				if (!scope) {
					return;
				}
				var helps = scope.querySelectorAll(".remember-at-help");
				for (var i = 0; i < helps.length; i++) {
					helps[i].hidden = !show;
				}
			}
			function rememberSyncAtHelps() {
				var selects = document.querySelectorAll("select");
				for (var i = 0; i < selects.length; i++) {
					if (selects[i].querySelector("[data-remember-show-at]")) {
						rememberSyncAtHelp(selects[i]);
					}
				}
			}
			document.addEventListener("change", function (event) {
				var select = event.target;
				if (!select) {
					return;
				}
				if (select.tagName !== "SELECT") {
					return;
				}
				if (select.querySelector("[data-remember-show-at]")) {
					rememberSyncAtHelp(select);
				}
			});
			if (document.readyState === "loading") {
				document.addEventListener("DOMContentLoaded", rememberSyncAtHelps);
			} else {
				rememberSyncAtHelps();
			}
		}());
		</script>';
	}
}
