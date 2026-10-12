<?php
/**
 * Shared member profile field policy (required vs optional) and collectors.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Profile field requirements and POST collectors.
 */
class Remember_Profile_Fields {

	/**
	 * Profile-table keys that are required (confirmed 1.2.0).
	 *
	 * Nickname and timezone live in WP user meta (see required_meta_keys()).
	 *
	 * @return string[]
	 */
	public static function required_profile_keys() {
		return array(
			'legal_first_name',
			'legal_last_name',
			'address_street',
			'address_city',
			'address_state',
			'address_postal',
			'address_country',
			'cell_phone',
			'im_handle',
			'emergency_contact_first',
			'emergency_contact_last',
			'emergency_contact_phone',
			'emergency_contact_relationship',
		);
	}

	/**
	 * Required WP user-meta / identity keys.
	 *
	 * @return string[]
	 */
	public static function required_meta_keys() {
		return array(
			'nickname',
			'timezone_string',
		);
	}

	/**
	 * Human labels for required keys (error messages).
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'nickname'                       => __( 'Nickname', 'remember' ),
			'display_name'                   => __( 'Display name', 'remember' ),
			'timezone_string'                => __( 'Time Zone', 'remember' ),
			'legal_first_name'               => __( 'Legal First Name', 'remember' ),
			'legal_last_name'                => __( 'Legal Last Name', 'remember' ),
			'address_street'                 => __( 'Street Address', 'remember' ),
			'address_city'                   => __( 'City', 'remember' ),
			'address_state'                  => __( 'State/Province', 'remember' ),
			'address_postal'                 => __( 'Postal Code', 'remember' ),
			'address_country'                => __( 'Country', 'remember' ),
			'cell_phone'                     => __( 'Cell Phone', 'remember' ),
			'im_handle'                      => __( 'Instant Messenger', 'remember' ),
			'emergency_contact_first'        => __( 'Emergency Contact First Name', 'remember' ),
			'emergency_contact_last'         => __( 'Emergency Contact Last Name', 'remember' ),
			'emergency_contact_phone'        => __( 'Emergency Contact Phone', 'remember' ),
			'emergency_contact_relationship' => __( 'Emergency Contact Relationship', 'remember' ),
			'dietary_restrictions'           => __( 'Dietary Restrictions', 'remember' ),
			'medical_accommodations'         => __( 'Medical Accommodations', 'remember' ),
			'allergies'                      => __( 'Known Allergies', 'remember' ),
			'allergy_reaction'               => __( 'Allergy reaction', 'remember' ),
			'username'                       => __( 'Username', 'remember' ),
			'user_email'                     => __( 'Email Address', 'remember' ),
			'password'                       => __( 'Password', 'remember' ),
			'password_confirm'               => __( 'Confirm Password', 'remember' ),
			'current_password'               => __( 'Current password', 'remember' ),
			'new_password'                   => __( 'New password', 'remember' ),
			'new_password_confirm'           => __( 'Confirm new password', 'remember' ),
			'interests'                      => __( 'Interests', 'remember' ),
			'photo_file'                     => __( 'Profile Photo', 'remember' ),
		);
	}

	/**
	 * Required profile keys for a form context.
	 *
	 * Admin member edit stays lean so staff can save incomplete records.
	 *
	 * @param string $context front|register|admin.
	 * @return string[]
	 */
	public static function required_profile_keys_for_context( $context = 'front' ) {
		if ( 'admin' === $context ) {
			return array(
				'legal_first_name',
				'legal_last_name',
				'cell_phone',
			);
		}
		return self::required_profile_keys();
	}

	/**
	 * Required meta keys for a form context.
	 *
	 * @param string $context front|register|admin.
	 * @return string[]
	 */
	public static function required_meta_keys_for_context( $context = 'front' ) {
		if ( 'admin' === $context ) {
			return array(
				'nickname',
				'display_name',
			);
		}
		return self::required_meta_keys();
	}

	/**
	 * Whether a field key is required.
	 *
	 * @param string $key     Field key.
	 * @param string $context front|register|admin.
	 * @return bool
	 */
	public static function is_required( $key, $context = 'front' ) {
		$key = (string) $key;
		return in_array( $key, self::required_profile_keys_for_context( $context ), true )
			|| in_array( $key, self::required_meta_keys_for_context( $context ), true );
	}

	/**
	 * Read a text field from POST, trying preferred names in order.
	 *
	 * @param string[] $names Candidate POST keys.
	 * @return string
	 */
	private static function post_text( array $names ) {
		foreach ( $names as $name ) {
			if ( isset( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
				return sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
			}
		}
		return '';
	}

	/**
	 * Collect member_profiles columns from the current request.
	 *
	 * Accepts standard profile names and/or remember_reg_* registration names.
	 *
	 * @return array<string,mixed>
	 */
	public static function collect_profile_data_from_request() {
		self::ensure_allergy_reaction_column();
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-clothing-sizes.php';

		$im_type = self::post_text( array( 'im_type', 'remember_reg_im_type' ) );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-im-platforms.php';
		$im_type = Remember_Im_Platforms::sanitize_key_value( $im_type );

		$country = self::post_text( array( 'address_country', 'remember_reg_address_country' ) );
		if ( '' === $country ) {
			$country = 'US';
		}

		$interests = '';
		if ( isset( $_POST['interests'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$interests = self::sanitize_interests( wp_unslash( $_POST['interests'] ) );
		} elseif ( isset( $_POST['remember_reg_interests'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$interests = self::sanitize_interests( wp_unslash( $_POST['remember_reg_interests'] ) );
		}

		return array(
			'legal_first_name'               => self::post_text( array( 'legal_first_name', 'remember_reg_first_name' ) ),
			'legal_last_name'                => self::post_text( array( 'legal_last_name', 'remember_reg_last_name' ) ),
			'address_street'                 => self::post_text( array( 'address_street', 'remember_reg_address_street' ) ),
			'address_city'                   => self::post_text( array( 'address_city', 'remember_reg_address_city' ) ),
			'address_state'                  => self::post_text( array( 'address_state', 'remember_reg_address_state' ) ),
			'address_postal'                 => self::post_text( array( 'address_postal', 'remember_reg_address_postal' ) ),
			'address_country'                => $country,
			'cell_phone'                     => self::post_text( array( 'cell_phone', 'remember_reg_cell_phone' ) ),
			'im_handle'                      => self::post_text( array( 'im_handle', 'remember_reg_im_handle' ) ),
			'im_type'                        => $im_type,
			'interests'                      => $interests,
			'allergy_reaction'               => self::allergy_reaction_for_storage(),
			'shirt_size'                     => Remember_Clothing_Sizes::sanitize( 'shirt', self::post_text( array( 'shirt_size', 'remember_reg_shirt_size' ) ) ),
			'pants_size'                     => Remember_Clothing_Sizes::sanitize( 'pants', self::post_text( array( 'pants_size', 'remember_reg_pants_size' ) ) ),
			'shoe_size'                      => Remember_Clothing_Sizes::sanitize( 'shoe', self::post_text( array( 'shoe_size', 'remember_reg_shoe_size' ) ) ),
			'emergency_contact_first'        => self::post_text( array( 'emergency_contact_first', 'remember_reg_emergency_contact_first' ) ),
			'emergency_contact_last'         => self::post_text( array( 'emergency_contact_last', 'remember_reg_emergency_contact_last' ) ),
			'emergency_contact_phone'        => self::post_text( array( 'emergency_contact_phone', 'remember_reg_emergency_contact_phone' ) ),
			'emergency_contact_relationship' => self::post_text( array( 'emergency_contact_relationship', 'remember_reg_emergency_contact_relationship' ) ),
			'share_email_with_events'        => ( isset( $_POST['share_email_with_events'] ) || isset( $_POST['remember_reg_share_email_with_events'] ) ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'share_phone_with_events'        => ( isset( $_POST['share_phone_with_events'] ) || isset( $_POST['remember_reg_share_phone_with_events'] ) ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'share_location_with_events'     => ( isset( $_POST['share_location_with_events'] ) || isset( $_POST['remember_reg_share_location_with_events'] ) ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'share_im_with_events'           => ( isset( $_POST['share_im_with_events'] ) || isset( $_POST['remember_reg_share_im_with_events'] ) ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'share_interests_with_events'    => ( isset( $_POST['share_interests_with_events'] ) || isset( $_POST['remember_reg_share_interests_with_events'] ) ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'share_photo_with_events'        => ( isset( $_POST['share_photo_with_events'] ) || isset( $_POST['remember_reg_share_photo_with_events'] ) ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	/**
	 * Collect nickname + timezone (+ display_name when posted) from the request.
	 *
	 * @return array<string,string>
	 */
	public static function collect_meta_from_request() {
		$nickname = self::post_text( array( 'nickname', 'remember_reg_display_name', 'remember_reg_nickname' ) );
		$timezone = self::post_text( array( 'timezone_string', 'remember_reg_timezone' ) );
		return array(
			'nickname'        => $nickname,
			'timezone_string' => $timezone,
			'display_name'    => self::post_text( array( 'display_name' ) ),
		);
	}

	/**
	 * Copy posted profile columns onto a profile row for a sticky re-render.
	 *
	 * @param object|null         $profile      Existing row, or null.
	 * @param array<string,mixed> $profile_data From collect_profile_data_from_request().
	 * @return object
	 */
	public static function apply_profile_data_to_row( $profile, array $profile_data ) {
		if ( ! is_object( $profile ) ) {
			$profile = new stdClass();
		}
		foreach ( $profile_data as $key => $value ) {
			$profile->{$key} = $value;
		}
		return $profile;
	}

	/**
	 * Posted checkbox catalog IDs.
	 *
	 * @param string $post_key POST array key.
	 * @return int[]
	 */
	public static function catalog_ids_from_request( $post_key ) {
		$post_key = (string) $post_key;
		if ( '' === $post_key || ! isset( $_POST[ $post_key ] ) || ! is_array( $_POST[ $post_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
			return array();
		}
		$ids = array();
		foreach ( wp_unslash( $_POST[ $post_key ] ) as $raw_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id = absint( $raw_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Posted social-media slots (up to three) for a sticky re-render.
	 *
	 * @return array<int,object>
	 */
	public static function social_slots_from_request() {
		$slots = array();
		$raw   = ( isset( $_POST['social_media'] ) && is_array( $_POST['social_media'] ) ) ? $_POST['social_media'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
		$i     = 0;
		foreach ( $raw as $row ) {
			if ( $i >= 3 ) {
				break;
			}
			$slot              = new stdClass();
			$slot->platform_id = 0;
			$slot->handle      = '';
			if ( is_array( $row ) ) {
				$slot->platform_id = isset( $row['platform_id'] ) ? absint( $row['platform_id'] ) : 0;
				$slot->handle      = isset( $row['handle'] ) ? sanitize_text_field( wp_unslash( $row['handle'] ) ) : '';
			}
			$slots[ $i ] = $slot;
			++$i;
		}
		while ( $i < 3 ) {
			$slot              = new stdClass();
			$slot->platform_id = 0;
			$slot->handle      = '';
			$slots[ $i ]       = $slot;
			++$i;
		}
		return $slots;
	}

	/**
	 * Missing required keys among profile + meta.
	 *
	 * @param array<string,mixed>  $profile_data Profile columns.
	 * @param array<string,string> $meta_data    Meta keys from collect_meta_from_request().
	 * @param string               $context      front|register|admin.
	 * @return string[]
	 */
	public static function missing_required( array $profile_data, array $meta_data, $context = 'front' ) {
		$missing = array();
		foreach ( self::required_meta_keys_for_context( $context ) as $key ) {
			if ( ! isset( $meta_data[ $key ] ) || '' === trim( (string) $meta_data[ $key ] ) ) {
				$missing[] = $key;
			}
		}
		foreach ( self::required_profile_keys_for_context( $context ) as $key ) {
			if ( ! isset( $profile_data[ $key ] ) || '' === trim( (string) $profile_data[ $key ] ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * First missing required key among profile + meta, or empty string if complete.
	 *
	 * @param array<string,mixed>  $profile_data Profile columns.
	 * @param array<string,string> $meta_data    Meta keys from collect_meta_from_request().
	 * @param string               $context      front|register|admin.
	 * @return string
	 */
	public static function first_missing_required( array $profile_data, array $meta_data, $context = 'front' ) {
		$missing = self::missing_required( $profile_data, $meta_data, $context );
		return empty( $missing ) ? '' : (string) $missing[0];
	}

	/**
	 * Human labels for a list of field keys.
	 *
	 * @param string[] $keys Field keys.
	 * @return string[]
	 */
	public static function labels_for( array $keys ) {
		$all  = self::labels();
		$out  = array();
		$seen = array();
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( '' === $key || isset( $seen[ $key ] ) || ! isset( $all[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $all[ $key ];
		}
		return $out;
	}

	/**
	 * Join labels for a banner (A, B, and C).
	 *
	 * @param string[] $items Labels.
	 * @return string
	 */
	public static function join_list( array $items ) {
		$items = array_values( array_filter( array_map( 'strval', $items ) ) );
		$n     = count( $items );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $items[0];
		}
		if ( 2 === $n ) {
			return sprintf(
				/* translators: 1: first field, 2: second field */
				__( '%1$s and %2$s', 'remember' ),
				$items[0],
				$items[1]
			);
		}
		$last = array_pop( $items );
		return sprintf(
			/* translators: 1: comma-separated fields, 2: last field */
			__( '%1$s, and %2$s', 'remember' ),
			implode( ', ', $items ),
			$last
		);
	}

	/**
	 * Banner copy listing missing fields plus extra sentences.
	 *
	 * @param string[] $field_labels Missing field labels.
	 * @param string[] $extras       Additional sentences (email, interests, timezone).
	 * @return string
	 */
	public static function banner_message( array $field_labels, array $extras = array() ) {
		$field_labels = array_values( array_unique( array_filter( array_map( 'strval', $field_labels ) ) ) );
		$parts        = array();
		$n            = count( $field_labels );
		if ( 1 === $n ) {
			$parts[] = sprintf(
				/* translators: %s: field label */
				__( '%s is required.', 'remember' ),
				$field_labels[0]
			);
		} elseif ( $n > 1 ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated field names */
				__( 'Please complete: %s.', 'remember' ),
				self::join_list( $field_labels )
			);
		}
		foreach ( $extras as $sentence ) {
			$sentence = trim( (string) $sentence );
			if ( '' !== $sentence ) {
				$parts[] = $sentence;
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * CSS class list with remember-field-invalid when the key failed.
	 *
	 * @param string   $key          Field key.
	 * @param string[] $invalid_keys Keys that failed.
	 * @param string   $base         Existing classes.
	 * @return string
	 */
	public static function control_class( $key, array $invalid_keys, $base = '' ) {
		$class = trim( (string) $base );
		if ( in_array( (string) $key, $invalid_keys, true ) ) {
			$class = trim( $class . ' remember-field-invalid' );
		}
		return $class;
	}

	/**
	 * aria-invalid attribute when the key failed.
	 *
	 * @param string   $key          Field key.
	 * @param string[] $invalid_keys Keys that failed.
	 * @return string
	 */
	public static function control_invalid_attr( $key, array $invalid_keys ) {
		return in_array( (string) $key, $invalid_keys, true ) ? ' aria-invalid="true"' : '';
	}

	/**
	 * Group wrapper class when a checkbox catalog failed.
	 *
	 * @param string   $key          Field key.
	 * @param string[] $invalid_keys Keys that failed.
	 * @param string   $base         Existing classes.
	 * @return string
	 */
	public static function group_class( $key, array $invalid_keys, $base = '' ) {
		$class = trim( (string) $base );
		if ( in_array( (string) $key, $invalid_keys, true ) ) {
			$class = trim( $class . ' remember-field-invalid-group' );
		}
		return $class;
	}

	/**
	 * Short note under a failing field.
	 *
	 * @param string               $key          Field key.
	 * @param string[]             $invalid_keys Keys that failed.
	 * @param array<string,string> $messages     Optional per-key copy.
	 * @return void
	 */
	public static function render_field_error( $key, array $invalid_keys, array $messages = array() ) {
		$key = (string) $key;
		if ( ! in_array( $key, $invalid_keys, true ) ) {
			return;
		}
		$text = isset( $messages[ $key ] ) ? (string) $messages[ $key ] : __( 'Required.', 'remember' );
		echo '<p class="remember-field-error">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Whether at least one ID was posted for a checkbox catalog field.
	 *
	 * @param string $post_key POST array key (e.g. dietary_restrictions).
	 * @return bool
	 */
	private static function request_has_catalog_selection( $post_key ) {
		if ( ! isset( $_POST[ $post_key ] ) || ! is_array( $_POST[ $post_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		foreach ( $_POST[ $post_key ] as $raw_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( absint( $raw_id ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Missing required health catalogs (dietary / medical / allergies).
	 *
	 * Skips a catalog when it has no active options (nothing to choose).
	 *
	 * @return string[] Field keys: dietary_restrictions, medical_accommodations, allergies.
	 */
	public static function missing_required_health_catalogs() {
		global $wpdb;

		$checks = array(
			'dietary_restrictions'   => $wpdb->prefix . 'remember_dietary_restrictions',
			'medical_accommodations' => $wpdb->prefix . 'remember_medical_accommodations',
			'allergies'              => $wpdb->prefix . 'remember_allergies',
		);

		$missing = array();
		foreach ( $checks as $post_key => $table ) {
			$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from fixed map.
			if ( $active < 1 ) {
				continue;
			}
			if ( ! self::request_has_catalog_selection( $post_key ) ) {
				$missing[] = $post_key;
			}
		}

		return $missing;
	}

	/**
	 * First missing required health catalog (dietary / medical / allergies), or empty string.
	 *
	 * Skips a catalog when it has no active options (nothing to choose).
	 *
	 * @return string Field key: dietary_restrictions|medical_accommodations|allergies|''.
	 */
	public static function first_missing_required_health_catalog() {
		$missing = self::missing_required_health_catalogs();
		return empty( $missing ) ? '' : (string) $missing[0];
	}

	/**
	 * Add allergy_reaction when an existing profile table does not have it yet.
	 *
	 * Schema 2.2.3 does the same alter. This also covers a save that happens
	 * before an administrator has loaded wp-admin.
	 *
	 * @return bool
	 */
	public static function ensure_allergy_reaction_column() {
		static $ready = false;
		if ( $ready ) {
			return true;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'remember_member_profiles';
		$column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'allergy_reaction'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
		if ( 'allergy_reaction' === $column ) {
			$ready = true;
			return true;
		}

		$added = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN allergy_reaction TEXT NULL DEFAULT NULL AFTER interests" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $added ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::error(
				'Failed to add allergy_reaction column',
				array( 'error' => $wpdb->last_error )
			);
			return false;
		}

		$ready = true;
		return true;
	}

	/**
	 * True when the posted allergy list includes something other than None.
	 *
	 * @return bool
	 */
	public static function request_has_non_none_allergy() {
		global $wpdb;

		if ( ! isset( $_POST['allergies'] ) || ! is_array( $_POST['allergies'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
			return false;
		}

		$none_id = (int) $wpdb->get_var(
			"SELECT allergy_id FROM {$wpdb->prefix}remember_allergies WHERE allergy_name = 'None' LIMIT 1"
		);

		foreach ( $_POST['allergies'] as $raw_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id = absint( $raw_id );
			if ( $id > 0 && $id !== $none_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Posted explanation of an allergic reaction.
	 *
	 * @return string
	 */
	public static function allergy_reaction_from_request() {
		foreach ( array( 'allergy_reaction', 'remember_reg_allergy_reaction' ) as $name ) {
			if ( isset( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies.
				return sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) );
			}
		}
		return '';
	}

	/**
	 * Value to store. Cleared when every selected allergy is None.
	 *
	 * @return string
	 */
	public static function allergy_reaction_for_storage() {
		if ( ! self::request_has_non_none_allergy() ) {
			return '';
		}
		return self::allergy_reaction_from_request();
	}

	/**
	 * True when a non-None allergy is selected and the explanation is blank.
	 *
	 * @return bool
	 */
	public static function allergy_reaction_is_missing() {
		if ( ! self::request_has_non_none_allergy() ) {
			return false;
		}
		return '' === trim( self::allergy_reaction_from_request() );
	}

	/**
	 * Prompt shown beside the allergy reaction field.
	 *
	 * @return string
	 */
	public static function allergy_reaction_prompt() {
		return __( 'Explain the nature and severity of your reaction to any allergen you selected.', 'remember' );
	}

	/**
	 * Why a proposed account email cannot be saved, or an empty string when it can.
	 *
	 * An unchanged address is allowed. WordPress requires user_email to be unique.
	 *
	 * @param int    $user_id   Member / WordPress user ID.
	 * @param string $raw_email Proposed address.
	 * @return string invalid_email, email_exists, or empty.
	 */
	public static function user_email_change_error( $user_id, $raw_email ) {
		$email = sanitize_email( is_string( $raw_email ) ? $raw_email : '' );
		if ( ! is_email( $email ) ) {
			return 'invalid_email';
		}
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== (int) $user_id ) {
			return 'email_exists';
		}
		return '';
	}

	/**
	 * Message for a user_email_change_error() code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function user_email_change_message( $code ) {
		if ( 'email_exists' === $code ) {
			return __( 'That email address is already used by another account.', 'remember' );
		}
		return __( 'Please enter a valid email address.', 'remember' );
	}

	/**
	 * Replace social / dietary / medical / allergy junctions for a member from POST.
	 *
	 * @param int $member_id Member user ID.
	 * @return void
	 */
	public static function save_junctions_from_request( $member_id ) {
		global $wpdb;
		$member_id = absint( $member_id );
		if ( $member_id < 1 ) {
			return;
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_social_media', array( 'member_id' => $member_id ), array( '%d' ) );
		if ( isset( $_POST['social_media'] ) && is_array( $_POST['social_media'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$social_count = 0;
			foreach ( $_POST['social_media'] as $social_data ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( $social_count >= 3 ) {
					break;
				}
				$platform_id = isset( $social_data['platform_id'] ) ? absint( $social_data['platform_id'] ) : 0;
				$handle      = isset( $social_data['handle'] ) ? sanitize_text_field( wp_unslash( $social_data['handle'] ) ) : '';
				if ( $platform_id > 0 && '' !== $handle ) {
					$wpdb->insert(
						$wpdb->prefix . 'remember_member_social_media',
						array(
							'member_id'   => $member_id,
							'platform_id' => $platform_id,
							'handle'      => $handle,
							'created_at'  => current_time( 'mysql' ),
						),
						array( '%d', '%d', '%s', '%s' )
					);
					$social_count++;
				}
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_dietary_restrictions', array( 'member_id' => $member_id ), array( '%d' ) );
		if ( isset( $_POST['dietary_restrictions'] ) && is_array( $_POST['dietary_restrictions'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( $_POST['dietary_restrictions'] as $restriction_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$restriction_id = absint( $restriction_id );
				if ( $restriction_id > 0 ) {
					$wpdb->insert(
						$wpdb->prefix . 'remember_member_dietary_restrictions',
						array(
							'member_id'      => $member_id,
							'restriction_id' => $restriction_id,
						),
						array( '%d', '%d' )
					);
				}
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_medical_accommodations', array( 'member_id' => $member_id ), array( '%d' ) );
		if ( isset( $_POST['medical_accommodations'] ) && is_array( $_POST['medical_accommodations'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( $_POST['medical_accommodations'] as $accommodation_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$accommodation_id = absint( $accommodation_id );
				if ( $accommodation_id > 0 ) {
					$wpdb->insert(
						$wpdb->prefix . 'remember_member_medical_accommodations',
						array(
							'member_id'        => $member_id,
							'accommodation_id' => $accommodation_id,
						),
						array( '%d', '%d' )
					);
				}
			}
		}

		$wpdb->delete( $wpdb->prefix . 'remember_member_allergies', array( 'member_id' => $member_id ), array( '%d' ) );
		if ( isset( $_POST['allergies'] ) && is_array( $_POST['allergies'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( $_POST['allergies'] as $allergy_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$allergy_id = absint( $allergy_id );
				if ( $allergy_id > 0 ) {
					$wpdb->insert(
						$wpdb->prefix . 'remember_member_allergies',
						array(
							'member_id'  => $member_id,
							'allergy_id' => $allergy_id,
						),
						array( '%d', '%d' )
					);
				}
			}
		}
	}

	/**
	 * Maximum plain-text length for Interests (HTML tags do not count).
	 *
	 * @return int
	 */
	public static function interests_max_length() {
		$max = (int) apply_filters( 'remember_interests_max_length', 2000 );
		return $max > 0 ? $max : 2000;
	}

	/**
	 * Visible text of an Interests HTML value.
	 *
	 * @param string $html Rich text.
	 * @return string
	 */
	public static function interests_plain_text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Character count of Interests visible text.
	 *
	 * @param string $html Rich text.
	 * @return int
	 */
	public static function interests_char_count( $html ) {
		$text = self::interests_plain_text( $html );
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}
		return strlen( $text );
	}

	/**
	 * Whether Interests exceeds the plain-text limit.
	 *
	 * @param string $html Rich text.
	 * @return bool
	 */
	public static function interests_is_over_limit( $html ) {
		return self::interests_char_count( $html ) > self::interests_max_length();
	}

	/**
	 * Error copy when Interests is too long.
	 *
	 * @return string
	 */
	public static function interests_too_long_message() {
		return sprintf(
			/* translators: %s: maximum character count */
			__( 'Interests must be %s characters or fewer.', 'remember' ),
			number_format_i18n( self::interests_max_length() )
		);
	}

	/**
	 * Tags Interests may store and display. No attributes.
	 *
	 * @return array
	 */
	public static function interests_allowed_html() {
		return array(
			'p'  => array(),
			'br' => array(),
			'b'  => array(),
			'i'  => array(),
			'em' => array(),
			'u'  => array(),
			'ul' => array(),
			'ol' => array(),
			'li' => array(),
		);
	}

	/**
	 * Interests HTML using only b, i, em, u, ul, ol, li, p, and br.
	 *
	 * The browser posts %%rmb:%% markers with no angle brackets. PHP turns those back into tags.
	 * Also accepts legacy HTML or plain text.
	 *
	 * @param string $input Raw post, stored value, or clipboard HTML.
	 * @return string
	 */
	public static function interests_to_html( $input ) {
		$input = (string) $input;
		if ( false !== strpos( $input, '%%rmb:' ) && ! preg_match( '/<[a-z]/i', $input ) ) {
			return self::interests_kses( self::markers_to_tags( $input ) );
		}
		$input = self::strip_interests_clipboard_wrappers( $input );
		if ( ! preg_match( '/<\/?\s*[a-z]/i', $input ) ) {
			return self::interests_kses( self::plain_blocks_html( $input ) );
		}
		$html = class_exists( 'DOMDocument' ) ? self::interests_dom_to_html( $input ) : self::plain_blocks_html( wp_strip_all_tags( $input ) );
		return self::interests_kses( $html );
	}

	/**
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function interests_kses( $html ) {
		$html = wp_kses( (string) $html, self::interests_allowed_html() );
		$previous = '';
		while ( $html !== $previous ) {
			$previous = $html;
			$html     = preg_replace( '/<(b|i|em|u|p|li|ul|ol)>\s*<\/\1>/i', '', $html );
		}
		return trim( (string) $html );
	}

	/**
	 * Turn the browser's marker payload into allowlisted tags.
	 *
	 * @param string $text Marker text.
	 * @return string
	 */
	private static function markers_to_tags( $text ) {
		$replace = array(
			'%%rmb:br%%'  => '<br>',
			'%%rmb:b%%'   => '<b>',
			'%%rmb:/b%%'  => '</b>',
			'%%rmb:em%%'  => '<em>',
			'%%rmb:/em%%' => '</em>',
			'%%rmb:u%%'   => '<u>',
			'%%rmb:/u%%'  => '</u>',
			'%%rmb:ul%%'  => '<ul>',
			'%%rmb:/ul%%' => '</ul>',
			'%%rmb:ol%%'  => '<ol>',
			'%%rmb:/ol%%' => '</ol>',
			'%%rmb:li%%'  => '<li>',
			'%%rmb:/li%%' => '</li>',
			'%%rmb:p%%'   => '<p>',
			'%%rmb:/p%%'  => '</p>',
		);
		$html = str_replace( array_keys( $replace ), array_values( $replace ), (string) $text );
		$html = str_replace( '%%rmb:esc%%', "\0", $html );
		$html = str_replace(
			array( '%%rmb:lt%%', '%%rmb:gt%%', '%%rmb:amp%%' ),
			array( '&lt;', '&gt;', '&amp;' ),
			$html
		);
		return str_replace( "\0", '%%', $html );
	}

	/**
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function plain_blocks_html( $text ) {
		$text = str_replace( array( "\0", "\r\n", "\r" ), array( '', "\n", "\n" ), (string) $text );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$parts = preg_split( "/\n{2,}/", $text );
		$out   = '';
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			$lines = explode( "\n", $part );
			$inner = '';
			foreach ( $lines as $index => $line ) {
				if ( $index > 0 ) {
					$inner .= '<br>';
				}
				$inner .= esc_html( $line );
			}
			$out .= '<p>' . $inner . '</p>';
		}
		return $out;
	}

	/**
	 * Remove clipboard chrome before HTML is parsed. Mirrors the browser stripper.
	 *
	 * @param string $html Raw clipboard or editor HTML.
	 * @return string
	 */
	private static function strip_interests_clipboard_wrappers( $html ) {
		$html = str_replace( "\0", '', (string) $html );
		$html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );
		$html = preg_replace( '/<!\[if[\s\S]*?<!\[endif\]>/i', '', (string) $html );
		$html = preg_replace( '/<!\[if[^\]]*\]>/i', '', (string) $html );
		$html = preg_replace( '/<!\[endif\]>/i', '', (string) $html );
		$html = preg_replace( '/<\?[\s\S]*?\?>/', '', (string) $html );
		$html = preg_replace( '/<!DOCTYPE[^>]*>/i', '', (string) $html );
		$html = preg_replace( '/<\/?o:p\b[^>]*>/i', '', (string) $html );
		$html = preg_replace( '/<(script|style|xml|noscript)\b[^>]*>[\s\S]*?<\/\1>/i', '', (string) $html );
		$html = preg_replace( '/<(script|style|xml|noscript)\b[^>]*>[\s\S]*?(?=<\/head\b|<body\b|<p\b|<div\b|$)/i', '', (string) $html );
		$html = preg_replace( '/<\/?(meta|link|title|base|head)\b[^>]*>/i', '', (string) $html );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Safe HTML for display and for loading the Interests editor.
	 *
	 * @param string $stored Plain text or legacy HTML.
	 * @return string
	 */
	public static function interests_html( $stored ) {
		return self::interests_to_html( $stored );
	}

	/**
	 * DOM conversion of Interests HTML to the allowlisted tags.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function interests_dom_to_html( $html ) {
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $loaded ) {
			return self::plain_blocks_html( wp_strip_all_tags( $html ) );
		}
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return self::plain_blocks_html( wp_strip_all_tags( $html ) );
		}
		return self::interests_children_html( $body );
	}

	/**
	 * @param DOMNode $node Parent.
	 * @return string
	 */
	private static function interests_children_html( $node ) {
		$parts = '';
		$list  = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && self::interests_is_word_list( $child ) ) {
				$item = self::interests_children_html( $child );
				if ( '' !== trim( wp_strip_all_tags( $item ) ) ) {
					$list .= '<li>' . $item . '</li>';
				}
				continue;
			}
			if ( '' !== $list ) {
				$parts .= '<ul>' . $list . '</ul>';
				$list   = '';
			}
			$parts .= self::interests_node_html( $child );
		}
		if ( '' !== $list ) {
			$parts .= '<ul>' . $list . '</ul>';
		}
		return $parts;
	}

	/**
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private static function interests_is_word_list( $node ) {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}
		$style = $node->getAttribute( 'style' );
		$class = $node->getAttribute( 'class' );
		return (bool) preg_match( '/mso-list\s*:/i', $style ) || false !== stripos( $class, 'MsoListParagraph' );
	}

	/**
	 * @param DOMElement $node Element.
	 * @return string[]
	 */
	private static function interests_style_tags( $node ) {
		$style = strtolower( $node->getAttribute( 'style' ) );
		$tags  = array();
		if ( preg_match( '/font-weight\s*:\s*(bold|[6-9]00)\b/', $style ) ) {
			$tags[] = 'b';
		}
		if ( preg_match( '/font-style\s*:\s*italic\b/', $style ) ) {
			$tags[] = 'em';
		}
		return $tags;
	}

	/**
	 * @param string $inner Markup.
	 * @param string[] $tags Tags to wrap, outer last.
	 * @param string[] $skip Tags already applied.
	 * @return string
	 */
	private static function interests_wrap_styles( $inner, $tags, $skip ) {
		foreach ( array_reverse( $tags ) as $tag ) {
			if ( in_array( $tag, $skip, true ) ) {
				continue;
			}
			if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
				continue;
			}
			$inner = '<' . $tag . '>' . $inner . '</' . $tag . '>';
		}
		return $inner;
	}

	/**
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function interests_node_html( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = str_replace( "\xc2\xa0", ' ', (string) $node->nodeValue );
			$text = preg_replace( '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{200E}\x{200F}]/u', '', $text );
			return esc_html( (string) $text );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$tag = strtolower( $node->nodeName );
		if ( false !== strpos( $tag, ':' ) ) {
			return '';
		}
		if ( 'img' === $tag ) {
			return esc_html( $node->getAttribute( 'alt' ) );
		}
		$drop = array( 'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'form', 'head', 'meta', 'link', 'title', 'textarea', 'input', 'button', 'select', 'video', 'audio', 'source', 'canvas', 'template', 'frame', 'frameset', 'applet', 'base' );
		if ( in_array( $tag, $drop, true ) ) {
			return '';
		}
		if ( 'br' === $tag ) {
			if ( false !== strpos( $node->getAttribute( 'class' ), 'Apple-interchange-newline' ) ) {
				return '';
			}
			return '<br>';
		}
		if ( 'ul' === $tag || 'ol' === $tag ) {
			$items = '';
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === strtolower( $child->nodeName ) ) {
					$li = self::interests_children_html( $child );
				} else {
					$li = self::interests_node_html( $child );
				}
				if ( '' !== trim( wp_strip_all_tags( $li ) ) ) {
					$items .= '<li>' . $li . '</li>';
				}
			}
			return '' === $items ? '' : '<' . $tag . '>' . $items . '</' . $tag . '>';
		}
		$inner = self::interests_children_html( $node );
		if ( 'a' === $tag ) {
			$href = $node->getAttribute( 'href' );
			if ( preg_match( '/[?&]q=([^&]+)/i', $href, $google_q ) && preg_match( '#google\.[^/]+/url#i', $href ) ) {
				$href = rawurldecode( $google_q[1] );
			}
			if ( preg_match( '#^(https?:|mailto:)#i', $href ) && false === strpos( $inner, $href ) ) {
				$inner .= esc_html( ' (' . $href . ')' );
			}
		}
		if ( 'td' === $tag || 'th' === $tag ) {
			return rtrim( $inner ) . ' ';
		}
		$inline = array(
			'strong' => 'b',
			'b'      => 'b',
			'em'     => 'em',
			'i'      => 'i',
			'u'      => 'u',
		);
		if ( isset( $inline[ $tag ] ) ) {
			$style_attr     = strtolower( $node->getAttribute( 'style' ) );
			$neutral_bold   = ( 'b' === $tag || 'strong' === $tag ) && preg_match( '/font-weight\s*:\s*(normal|400)\b/', $style_attr );
			$neutral_italic = ( 'i' === $tag || 'em' === $tag ) && preg_match( '/font-style\s*:\s*normal\b/', $style_attr );
			if ( ( $neutral_bold || $neutral_italic ) && preg_match( '/<(p|ul|ol)\b/i', $inner ) ) {
				return $inner;
			}
			if ( $neutral_bold || $neutral_italic ) {
				return self::interests_wrap_styles( $inner, self::interests_style_tags( $node ), array( $inline[ $tag ] ) );
			}
			$inner = '<' . $inline[ $tag ] . '>' . $inner . '</' . $inline[ $tag ] . '>';
			return self::interests_wrap_styles( $inner, self::interests_style_tags( $node ), array( $inline[ $tag ] ) );
		}
		if ( 'li' === $tag ) {
			return '' === trim( wp_strip_all_tags( $inner ) ) ? '' : '<li>' . $inner . '</li>';
		}
		$blocks = array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'section', 'article', 'header', 'footer', 'tr', 'span', 'font' );
		if ( in_array( $tag, $blocks, true ) ) {
			$inner = self::interests_wrap_styles( $inner, self::interests_style_tags( $node ), array() );
			if ( 'span' === $tag || 'font' === $tag ) {
				return $inner;
			}
			if ( preg_match( '/<(p|ul|ol)\b/i', $inner ) ) {
				return $inner;
			}
			return '' === trim( wp_strip_all_tags( $inner ) ) ? '' : '<p>' . $inner . '</p>';
		}
		return self::interests_wrap_styles( $inner, self::interests_style_tags( $node ), array() );
	}


	/**
	 * Sanitize Interests to the allowlisted tags (does not truncate).
	 *
	 * Marker payloads from the browser are converted here, after the request has reached PHP.
	 *
	 * @param string $html Marker payload, HTML, or plain text.
	 * @return string
	 */
	public static function sanitize_interests( $html ) {
		return self::interests_to_html( $html );
	}

	/**
	 * Sanitize Interests and clamp visible text to the limit (CSV import).
	 *
	 * @param string $html Raw HTML or plain text.
	 * @return string
	 */
	public static function clamp_interests( $html ) {
		$html = self::sanitize_interests( $html );
		$max  = self::interests_max_length();
		if ( self::interests_char_count( $html ) <= $max ) {
			return $html;
		}
		$text = self::interests_plain_text( $html );
		$cut  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
		return $cut;
	}

	/**
	 * Attach live Interests counting to this TinyMCE instance (called from editor setup).
	 *
	 * @param array  $init      TinyMCE init array.
	 * @param string $editor_id Editor id.
	 * @return array
	 */
	public static function tinymce_interests_setup( $init, $editor_id ) {
		if ( 'interests' !== $editor_id && 'remember_reg_interests' !== $editor_id ) {
			return $init;
		}

		$call = 'if(window.rememberInitInterestsLimitEditor){window.rememberInitInterestsLimitEditor(editor);}';
		$prev = isset( $init['setup'] ) ? trim( (string) $init['setup'] ) : '';
		if ( $prev && preg_match( '/^\(?function\s*\(/', $prev ) ) {
			$init['setup'] = 'function(editor){(' . rtrim( $prev, ';' ) . ')(editor);' . $call . '}';
		} else {
			$init['setup'] = 'function(editor){' . $call . '}';
		}

		// b/strong and i/em keep the Bold and Italic buttons, which insert strong and em.
		$init['valid_elements']          = 'p,br,b/strong,i/em,u,ul,ol,li';
		$init['extended_valid_elements'] = '';

		return $init;
	}

	/**
	 * Render the Interests wp_editor plus a live character counter.
	 *
	 * @param string $content        Current HTML.
	 * @param string $editor_id      TinyMCE / textarea id.
	 * @param string $textarea_name  POST name.
	 * @param string $editor_class   Optional editor_class.
	 * @return void
	 */
	public static function render_interests_editor( $content, $editor_id, $textarea_name, $editor_class = '' ) {
		if ( ! wp_script_is( 'editor', 'enqueued' ) && ! wp_script_is( 'editor', 'done' ) ) {
			wp_enqueue_editor();
		}

		if ( ! has_filter( 'tiny_mce_before_init', array( __CLASS__, 'tinymce_interests_setup' ) ) ) {
			add_filter( 'tiny_mce_before_init', array( __CLASS__, 'tinymce_interests_setup' ), 99, 2 );
		}

		$settings = array(
			'textarea_name' => $textarea_name,
			'textarea_rows' => 6,
			'media_buttons' => false,
			'teeny'         => false,
			'quicktags'     => false,
			'tinymce'       => array(
				'toolbar1'      => 'bold,italic,underline,bullist,numlist,undo,redo',
				'toolbar2'      => '',
				'block_formats' => 'Paragraph=p',
			),
		);
		if ( '' !== $editor_class ) {
			$settings['editor_class'] = $editor_class;
		}

		$html  = self::interests_to_html( $content );
		wp_editor( $html, $editor_id, $settings );

		$max   = self::interests_max_length();
		$count = self::interests_char_count( $html );
		/* translators: 1: current character count, 2: maximum */
		$count_template = __( '%1$s / %2$s characters', 'remember' );
		printf(
			'<p class="description remember-interests-count" data-remember-interests-editor="%1$s" data-remember-interests-max="%2$s" data-remember-interests-template="%3$s">%4$s</p>',
			esc_attr( $editor_id ),
			esc_attr( (string) $max ),
			esc_attr( $count_template ),
			esc_html( sprintf( $count_template, number_format_i18n( $count ), number_format_i18n( $max ) ) )
		);
	}

	/**
	 * Sanitize a member number: empty string, alphanumeric string, or null if invalid.
	 *
	 * @param mixed $raw Raw input.
	 * @return string|null Empty string when cleared, alphanumeric value, or null when invalid.
	 */
	public static function sanitize_member_number( $raw ) {
		$value = sanitize_text_field( (string) $raw );
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9]+$/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Whether another member already has this member number.
	 *
	 * @param string $member_number      Candidate number (non-empty).
	 * @param int    $exclude_member_id  Member ID to ignore (current member).
	 * @return bool
	 */
	public static function is_member_number_taken( $member_number, $exclude_member_id = 0 ) {
		global $wpdb;
		$member_number = (string) $member_number;
		if ( '' === $member_number ) {
			return false;
		}
		$exclude_member_id = absint( $exclude_member_id );
		$sql               = "SELECT member_id FROM {$wpdb->prefix}remember_member_profiles WHERE member_number = %s";
		$params            = array( $member_number );
		if ( $exclude_member_id > 0 ) {
			$sql     .= ' AND member_id <> %d';
			$params[] = $exclude_member_id;
		}
		$sql .= ' LIMIT 1';
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		return ! empty( $found );
	}
}
