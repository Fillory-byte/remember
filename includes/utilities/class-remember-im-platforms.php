<?php
/**
 * Instant messenger platform catalog.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Admin-managed IM platforms (keys stored on member_profiles.im_type).
 */
class Remember_Im_Platforms {

	/**
	 * Default platforms (platform_key => label).
	 *
	 * @return array<string, string>
	 */
	public static function defaults() {
		return array(
			'telegram' => __( 'Telegram', 'remember' ),
			'discord'  => __( 'Discord', 'remember' ),
			'signal'   => __( 'Signal', 'remember' ),
			'whatsapp' => __( 'WhatsApp', 'remember' ),
			'other'    => __( 'Other', 'remember' ),
		);
	}

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_im_platforms';
	}

	/**
	 * Whether the catalog table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * All platforms ordered for admin.
	 *
	 * @return array<int, object>
	 */
	public static function get_all() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array();
		}
		return $wpdb->get_results(
			'SELECT * FROM ' . self::table() . ' ORDER BY sort_order ASC, platform_name ASC'
		);
	}

	/**
	 * Active platforms for form dropdowns.
	 *
	 * @return array<int, object>
	 */
	public static function get_active() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			$out = array();
			$i   = 0;
			foreach ( self::defaults() as $key => $name ) {
				$out[] = (object) array(
					'platform_id'   => $i++,
					'platform_key'  => $key,
					'platform_name' => $name,
					'url_template'  => ( 'telegram' === $key ) ? 'https://t.me/{handle}' : '',
					'show_at'       => ( 'telegram' === $key ) ? 1 : 0,
					'is_active'     => 1,
					'sort_order'    => $i,
				);
			}
			return $out;
		}
		return $wpdb->get_results(
			'SELECT * FROM ' . self::table() . ' WHERE is_active = 1 ORDER BY sort_order ASC, platform_name ASC'
		);
	}

	/**
	 * Default platform_key for empty selections.
	 *
	 * @return string
	 */
	public static function default_key() {
		$active = self::get_active();
		if ( ! empty( $active[0]->platform_key ) ) {
			return (string) $active[0]->platform_key;
		}
		return 'telegram';
	}

	/**
	 * Display label for a stored key (works for inactive/orphan keys).
	 *
	 * @param string $key platform_key / im_type.
	 * @return string
	 */
	public static function get_label( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return '';
		}
		global $wpdb;
		if ( self::table_exists() ) {
			$name = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT platform_name FROM ' . self::table() . ' WHERE platform_key = %s LIMIT 1',
					$key
				)
			);
			if ( $name ) {
				return (string) $name;
			}
		}
		$defaults = self::defaults();
		if ( isset( $defaults[ $key ] ) ) {
			return $defaults[ $key ];
		}
		return ucfirst( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Sanitize an IM type to a known key when possible; otherwise sanitize_key.
	 *
	 * @param string $key Raw value.
	 * @return string
	 */
	public static function sanitize_key_value( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return self::default_key();
		}
		return $key;
	}

	/**
	 * Render <option> list for a select.
	 *
	 * @param string $selected Selected platform_key.
	 * @return void
	 */
	public static function render_options( $selected = '' ) {
		$selected = self::sanitize_key_value( $selected );
		$found    = false;
		foreach ( self::get_active() as $platform ) {
			$key = (string) $platform->platform_key;
			if ( $key === $selected ) {
				$found = true;
			}
			printf(
				'<option value="%s" %s data-remember-show-at="%d">%s</option>',
				esc_attr( $key ),
				selected( $selected, $key, false ),
				empty( $platform->show_at ) ? 0 : 1,
				esc_html( (string) $platform->platform_name )
			);
		}
		// Keep showing an inactive/orphan selection so edits do not wipe it.
		if ( '' !== $selected && ! $found ) {
			printf(
				'<option value="%s" selected>%s</option>',
				esc_attr( $selected ),
				esc_html( self::get_label( $selected ) )
			);
		}
	}
}
