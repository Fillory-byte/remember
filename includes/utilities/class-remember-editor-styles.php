<?php
/**
 * Content styles for reMember's rich-text editors.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Keep list bullets and numbers visible inside reMember's TinyMCE editors.
 *
 * Themes load their stylesheet into classic editors with add_editor_style().
 * Block themes such as Ollie reset list padding there and restore it with
 * theme.json spacing variables that the classic editor never defines, so the
 * markers end up outside the editing area.
 */
class Remember_Editor_Styles {

	/**
	 * Front-end editors reMember renders outside wp-admin.
	 */
	const FRONT_END_EDITOR_IDS = array( 'interests', 'remember_reg_interests' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'tiny_mce_before_init', array( __CLASS__, 'add_list_styles' ), 20, 2 );
		add_filter( 'teeny_mce_before_init', array( __CLASS__, 'add_list_styles' ), 20, 2 );
	}

	/**
	 * Append list styles to a reMember editor's TinyMCE settings.
	 *
	 * @param array  $init      TinyMCE init settings.
	 * @param string $editor_id Editor id.
	 * @return array
	 */
	public static function add_list_styles( $init, $editor_id ) {
		if ( ! self::is_remember_editor( $editor_id ) ) {
			return $init;
		}

		$existing              = isset( $init['content_style'] ) ? trim( (string) $init['content_style'] ) : '';
		$init['content_style'] = trim( $existing . ' ' . self::list_css() );
		return $init;
	}

	/**
	 * Whether this editor belongs to reMember.
	 *
	 * @param string $editor_id Editor id.
	 * @return bool
	 */
	private static function is_remember_editor( $editor_id ) {
		if ( is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			return 0 === strpos( $page, 'remember' );
		}
		return in_array( (string) $editor_id, self::FRONT_END_EDITOR_IDS, true );
	}

	/**
	 * List CSS for the editor iframe.
	 *
	 * WordPress prints TinyMCE settings inside double quotes without escaping,
	 * so this must stay on one line with no double quotes.
	 *
	 * @return string
	 */
	private static function list_css() {
		$body = 'body.mce-content-body';
		return $body . ' ul,' . $body . ' ol{padding-inline-start:2em !important;margin-inline-start:0 !important;list-style-position:outside !important}'
			. $body . ' li{display:list-item !important}'
			. $body . ' ul{list-style-type:disc !important}'
			. $body . ' ol{list-style-type:decimal !important}'
			. $body . ' ul ul{list-style-type:circle !important}'
			. $body . ' ul ul ul{list-style-type:square !important}';
	}
}
