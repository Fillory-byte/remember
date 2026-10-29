<?php
/**
 * Member notification email sender.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sends templated emails using notification_settings rows.
 */
class Remember_Notifications {

	/**
	 * Send a notification by type with placeholder context.
	 *
	 * @param string $type    Notification type key.
	 * @param array  $context Placeholder values (without braces).
	 * @param string $to      Recipient email.
	 * @return bool|WP_Error True on success, false if disabled, WP_Error on failure.
	 */
	public static function send( $type, $context, $to ) {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-notification-setting.php';
		$model  = new Remember_Notification_Setting();
		$setting = $model->get_by_type( $type );

		$enabled = true;
		$subject = Remember_Notification_Setting::get_default_subject( $type );
		$body    = Remember_Notification_Setting::get_default_body( $type );

		if ( $setting ) {
			$enabled = ! empty( $setting->is_enabled );
			if ( ! empty( $setting->subject_template ) ) {
				$subject = $setting->subject_template;
			}
			if ( ! empty( $setting->body_template ) ) {
				$body = $setting->body_template;
			}
		}

		if ( ! $enabled ) {
			return false;
		}

		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'remember_notify_bad_email', __( 'Invalid recipient email.', 'remember' ) );
		}

		$context = self::normalize_context( $context );
		$subject = self::replace_placeholders( $subject, $context );
		$body    = self::unwrap_prefixed_url_placeholders( $body );
		$body    = self::replace_placeholders( $body, self::html_context( $context ) );
		$body    = self::repair_doubled_url_schemes( $body );
		$body    = self::to_html_email( $body );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $body, $headers );

		if ( ! $sent ) {
			return new WP_Error( 'remember_notify_send_failed', __( 'Failed to send notification email.', 'remember' ) );
		}

		return true;
	}

	/**
	 * Placeholder keys whose values are absolute URLs.
	 *
	 * @return string[]
	 */
	private static function url_placeholder_keys() {
		return array( 'ticket_url', 'profile_url', 'review_url', 'vetting_url', 'survey_url', 'apply_url' );
	}

	/**
	 * Remove a scheme an editor glued onto a URL placeholder.
	 *
	 * The rich-text link tool stores href="http://{profile_url}". After the
	 * placeholder is replaced that becomes http://https://…, which mail clients
	 * follow as http://https//… .
	 *
	 * @param string $text Template body before placeholder replacement.
	 * @return string
	 */
	private static function unwrap_prefixed_url_placeholders( $text ) {
		$keys = implode( '|', self::url_placeholder_keys() );
		return preg_replace( '#https?://(\{(?:' . $keys . ')\})#i', '$1', (string) $text );
	}

	/**
	 * Collapse a scheme that was prefixed onto an absolute URL.
	 *
	 * Covers both http://https://host and the colon-dropped http://https//host.
	 *
	 * @param string $text Body after placeholder replacement.
	 * @return string
	 */
	private static function repair_doubled_url_schemes( $text ) {
		$text = preg_replace( '#https?://(https?://)#i', '$1', (string) $text );
		return preg_replace( '#https?://(https?)//#i', '$1://', $text );
	}

	/**
	 * Escape placeholder values for HTML bodies. URL keys stay usable in href.
	 *
	 * @param array $context Placeholder map.
	 * @return array
	 */
	private static function html_context( $context ) {
		$url_keys = self::url_placeholder_keys();
		$safe     = array();
		foreach ( (array) $context as $key => $value ) {
			$value = (string) $value;
			if ( in_array( $key, $url_keys, true ) ) {
				$safe[ $key ] = esc_url( $value );
			} else {
				$safe[ $key ] = esc_html( $value );
			}
		}
		return $safe;
	}

	/**
	 * Wrap a template body as an HTML email. Plain-text templates become paragraphs.
	 *
	 * @param string $body Body after placeholder replacement.
	 * @return string
	 */
	private static function to_html_email( $body ) {
		$body = (string) $body;
		if ( false === strpos( $body, '<' ) ) {
			$body = wpautop( $body );
		}
		$body = make_clickable( $body );
		$body = wp_kses_post( $body );

		return '<!DOCTYPE html><html><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1d2327;">' . $body . '</body></html>';
	}

	/**
	 * Emails for reMember System Administrators (not WordPress administrators).
	 *
	 * Falls back to WordPress Administrator users when no System Administrator
	 * member has a valid email, so a new registration is not silently dropped.
	 *
	 * @return string[]
	 */
	public static function admin_emails() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT mr.member_id
				FROM {$wpdb->prefix}remember_member_roles mr
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
				WHERE r.role_name = %s",
				'System Administrator'
			)
		);
		$emails = array();
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$user = get_userdata( (int) $id );
				if ( $user && is_email( $user->user_email ) ) {
					$emails[] = $user->user_email;
				}
			}
		}
		if ( empty( $emails ) ) {
			$wp_admins = get_users(
				array(
					'role'   => 'administrator',
					'fields' => array( 'ID', 'user_email' ),
					'number' => 50,
				)
			);
			foreach ( (array) $wp_admins as $user ) {
				if ( ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
					$emails[] = $user->user_email;
				}
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Saved WordPress and reMember roles that are emailed about a new member.
	 *
	 * Null means the setting has never been saved. Callers then use admin_emails().
	 *
	 * @return string[]|null Tokens wp:{role} and rm:{role_id}, or null when unset.
	 */
	public static function member_registered_notify_roles() {
		$options = get_option( 'remember_options', array() );
		if ( ! is_array( $options ) || ! isset( $options['member_registered_notify_roles'] ) || ! is_array( $options['member_registered_notify_roles'] ) ) {
			return null;
		}
		return array_values( $options['member_registered_notify_roles'] );
	}

	/**
	 * Keep only role tokens that exist.
	 *
	 * @param array $posted Raw checkbox values.
	 * @return string[]
	 */
	public static function sanitize_member_registered_notify_roles( $posted ) {
		global $wpdb;

		$wp_allowed = array();
		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();
			if ( $wp_roles && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
				$wp_allowed = array_keys( $wp_roles->roles );
			}
		}

		$rm_allowed = $wpdb->get_col( "SELECT role_id FROM {$wpdb->prefix}remember_roles" );
		$rm_allowed = array_map( 'absint', is_array( $rm_allowed ) ? $rm_allowed : array() );

		$out = array();
		foreach ( (array) $posted as $raw ) {
			$token = sanitize_text_field( wp_unslash( $raw ) );
			if ( preg_match( '/^wp:([a-z0-9_-]+)$/', $token, $match ) && in_array( $match[1], $wp_allowed, true ) ) {
				$out[] = 'wp:' . $match[1];
				continue;
			}
			if ( preg_match( '/^rm:(\d+)$/', $token, $match ) ) {
				$role_id = absint( $match[1] );
				if ( $role_id > 0 && in_array( $role_id, $rm_allowed, true ) ) {
					$out[] = 'rm:' . $role_id;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Staff emails whose recipients are a role list on the template.
	 *
	 * @return string[]
	 */
	public static function admin_broadcast_types() {
		return self::admin_notification_types();
	}

	/**
	 * Staff emails that keep an older recipient rule until the role boxes are changed.
	 *
	 * @return string[]
	 */
	public static function legacy_recipient_types() {
		return array(
			'vetting_assigned',
			'vetting_case_waiting',
			'vetting_scheduled',
			'vetting_completed',
			'vetting_collaborator_invited',
			'waitlist_spot_opened',
		);
	}

	/**
	 * Staff emails a person can turn off for themselves.
	 *
	 * @return string[]
	 */
	public static function admin_notification_types() {
		return array(
			'member_registered',
			'application_received',
			'vetting_case_failed',
			'duplicate_hit_admin',
			'vetting_assigned',
			'vetting_case_waiting',
			'vetting_scheduled',
			'vetting_completed',
			'vetting_collaborator_invited',
			'waitlist_spot_opened',
		);
	}

	/**
	 * Saved role tokens for one staff email.
	 *
	 * Null means the choice has never been saved. member_registered keeps the
	 * option the General screen already writes.
	 *
	 * @param string $type Notification type.
	 * @return string[]|null
	 */
	public static function configured_admin_notify_roles( $type ) {
		if ( 'member_registered' === $type ) {
			return self::member_registered_notify_roles();
		}
		$options = get_option( 'remember_options', array() );
		if ( ! is_array( $options ) || ! isset( $options['admin_notify_roles'] ) || ! is_array( $options['admin_notify_roles'] ) ) {
			return null;
		}
		if ( ! array_key_exists( $type, $options['admin_notify_roles'] ) || ! is_array( $options['admin_notify_roles'][ $type ] ) ) {
			return null;
		}
		return array_values( $options['admin_notify_roles'][ $type ] );
	}

	/**
	 * Role id of the reMember System Administrator role, or 0.
	 *
	 * @return int
	 */
	public static function system_administrator_role_id() {
		return self::role_id_by_name( 'System Administrator' );
	}

	/**
	 * reMember role id for an exact role name, or 0.
	 *
	 * @param string $name Role name.
	 * @return int
	 */
	private static function role_id_by_name( $name ) {
		foreach ( self::member_registered_role_choices() as $row ) {
			if ( isset( $row->role_name ) && $name === $row->role_name ) {
				return (int) $row->role_id;
			}
		}
		return 0;
	}

	/**
	 * Role checked on a template whose recipient list has never been saved.
	 *
	 * @param string $type Notification type.
	 * @return int
	 */
	private static function unsaved_default_role_id( $type ) {
		if ( in_array( $type, array( 'vetting_assigned', 'vetting_case_waiting', 'vetting_scheduled', 'vetting_completed', 'vetting_collaborator_invited' ), true ) ) {
			return self::role_id_by_name( 'Vetting' );
		}
		if ( 'waitlist_spot_opened' === $type ) {
			return self::role_id_by_name( 'Event Administrator' );
		}
		return self::system_administrator_role_id();
	}

	/**
	 * Addresses for a staff email.
	 *
	 * A saved role list uses only those roles. An unsaved list uses System
	 * Administrators, except vetting case mail and the waitlist spot email,
	 * which keep the recipient rule they already had. The excluded user and
	 * anyone who turned this email off are left out.
	 *
	 * @param string $type Notification type.
	 * @param array  $args {
	 *     @type int $exclude_user_id User who should not receive the staff copy.
	 *     @type int $vetting_id      Vetting case, for the older vetting rule.
	 *     @type int $event_id        Event, for the older waitlist rule.
	 * }
	 * @return string[]
	 */
	public static function emails_for_staff_type( $type, $args = array() ) {
		$args  = is_array( $args ) ? $args : array();
		$roles = self::configured_admin_notify_roles( $type );
		if ( is_array( $roles ) ) {
			$emails = self::emails_for_notify_role_tokens( $roles );
		} elseif ( 'vetting_case_waiting' === $type ) {
			$emails = self::vetting_team_emails();
		} elseif ( in_array( $type, array( 'vetting_assigned', 'vetting_scheduled', 'vetting_completed', 'vetting_collaborator_invited' ), true ) ) {
			$emails = self::vetting_case_recipient_emails( isset( $args['vetting_id'] ) ? (int) $args['vetting_id'] : 0 );
		} elseif ( 'waitlist_spot_opened' === $type ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-waitlist.php';
			$emails = Remember_Waitlist::accepted_event_administrator_emails( isset( $args['event_id'] ) ? (int) $args['event_id'] : 0 );
		} else {
			$emails = self::admin_emails();
		}
		$exclude = isset( $args['exclude_user_id'] ) ? (int) $args['exclude_user_id'] : 0;
		return self::without_opt_outs( self::exclude_user_from_emails( $emails, $exclude ), $type );
	}

	/**
	 * Addresses for a staff email that uses a role list.
	 *
	 * @param string $type            Notification type.
	 * @param int    $exclude_user_id User who should not receive the staff copy.
	 * @return string[]
	 */
	public static function admin_broadcast_emails( $type, $exclude_user_id = 0 ) {
		return self::emails_for_staff_type(
			$type,
			array(
				'exclude_user_id' => $exclude_user_id,
			)
		);
	}

	/**
	 * Whether a notification save should record this template's role list.
	 *
	 * Vetting case mail and the waitlist spot email keep their older recipient
	 * rule until the boxes change. A save that still matches the shown default
	 * leaves that rule in place.
	 *
	 * @param string   $type            Notification type.
	 * @param string[] $clean_roles     Sanitized role tokens.
	 * @param bool     $touched         True when the boxes on this template were changed.
	 * @param bool     $already_stored  True when a role list is already saved.
	 * @return bool
	 */
	public static function should_store_notify_roles( $type, $clean_roles, $touched, $already_stored ) {
		if ( $already_stored || $touched ) {
			return true;
		}
		if ( ! in_array( $type, self::legacy_recipient_types(), true ) ) {
			return true;
		}
		$default_id = self::unsaved_default_role_id( $type );
		$expected   = $default_id > 0 ? array( 'rm:' . $default_id ) : array();
		return array_values( (array) $clean_roles ) !== $expected;
	}

	/**
	 * Drop one user's address from a recipient list.
	 *
	 * @param string[] $emails          Addresses.
	 * @param int      $exclude_user_id User ID.
	 * @return string[]
	 */
	private static function exclude_user_from_emails( $emails, $exclude_user_id ) {
		$exclude_user_id = absint( $exclude_user_id );
		if ( $exclude_user_id < 1 ) {
			return array_values( $emails );
		}
		$user = get_userdata( $exclude_user_id );
		$own  = ( $user && is_email( $user->user_email ) ) ? strtolower( $user->user_email ) : '';
		if ( '' === $own ) {
			return array_values( $emails );
		}
		$kept = array();
		foreach ( (array) $emails as $email ) {
			if ( strtolower( $email ) !== $own ) {
				$kept[] = $email;
			}
		}
		return $kept;
	}

	/**
	 * Notification types this user has turned off.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function user_opted_out_types( $user_id ) {
		$stored = get_user_meta( absint( $user_id ), 'remember_admin_notify_opt_out', true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$allowed = self::admin_notification_types();
		$out     = array();
		foreach ( $stored as $type ) {
			$type = sanitize_key( $type );
			if ( in_array( $type, $allowed, true ) ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Opt-outs posted from the profile form.
	 *
	 * A type left unchecked is off. Types that are not staff emails are ignored.
	 *
	 * @return string[]
	 */
	public static function staff_email_opt_outs_from_request() {
		$wanted = ( isset( $_POST['remember_notify_receive'] ) && is_array( $_POST['remember_notify_receive'] ) ) ? wp_unslash( $_POST['remember_notify_receive'] ) : array();
		$wanted = array_map( 'sanitize_key', $wanted );
		$opt_out = array();
		foreach ( self::admin_notification_types() as $notify_type ) {
			if ( ! in_array( $notify_type, $wanted, true ) ) {
				$opt_out[] = $notify_type;
			}
		}
		return $opt_out;
	}

	/**
	 * Save staff-email opt-outs for a member who is allowed to set them.
	 *
	 * The profile form includes remember_notify_prefs_set only for those members.
	 * Anyone else posting the fields is ignored.
	 *
	 * @param int $user_id Member user ID.
	 * @return void
	 */
	public static function save_staff_email_prefs_from_request( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! isset( $_POST['remember_notify_prefs_set'] ) || ! self::user_manages_staff_email_prefs( $user_id ) ) {
			return;
		}
		$opt_out = self::staff_email_opt_outs_from_request();
		if ( empty( $opt_out ) ) {
			delete_user_meta( $user_id, 'remember_admin_notify_opt_out' );
			return;
		}
		update_user_meta( $user_id, 'remember_admin_notify_opt_out', $opt_out );
	}

	/**
	 * Whether this user should see the staff-email choices on their profile.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_manages_staff_email_prefs( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		foreach ( (array) $user->allcaps as $cap => $granted ) {
			if ( $granted && 0 === strpos( (string) $cap, 'remember_' ) ) {
				return true;
			}
		}
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}remember_member_roles mr
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = mr.role_id
				WHERE mr.member_id = %d
				AND (r.is_event_role = 0 OR r.role_name = %s)",
				$user_id,
				'Event Administrator'
			)
		);
		return (int) $count > 0;
	}

	/**
	 * Remove addresses whose user turned this staff email off.
	 *
	 * @param string[] $emails Addresses.
	 * @param string   $type   Notification type.
	 * @return string[]
	 */
	public static function without_opt_outs( $emails, $type ) {
		$type = sanitize_key( $type );
		$kept = array();
		foreach ( (array) $emails as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user && in_array( $type, self::user_opted_out_types( (int) $user->ID ), true ) ) {
				continue;
			}
			$kept[] = $email;
		}
		return $kept;
	}

	/**
	 * Compact role checkboxes. WordPress roles and reMember roles sit side by side.
	 *
	 * @param string   $input_name      Checkbox name. Use a trailing [] for a list.
	 * @param string[] $selected        Saved tokens.
	 * @param int      $default_role_id reMember role checked when nothing is saved yet.
	 * @return void
	 */
	public static function render_role_choices( $input_name, $selected, $default_role_id = 0 ) {
		$selected        = is_array( $selected ) ? $selected : array();
		$default_role_id = absint( $default_role_id );
		?>
		<div class="remember-notify-roles__groups">
			<div>
				<span class="remember-notify-roles__label"><?php esc_html_e( 'WordPress', 'remember' ); ?></span>
				<div class="remember-notify-roles__list">
					<?php
					$wp_roles = function_exists( 'wp_roles' ) ? wp_roles() : null;
					if ( $wp_roles && ! empty( $wp_roles->roles ) ) {
						foreach ( $wp_roles->roles as $wp_key => $wp_role ) {
							$token = 'wp:' . $wp_key;
							$label = isset( $wp_role['name'] ) ? translate_user_role( $wp_role['name'] ) : $wp_key;
							?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $token ); ?>" <?php checked( in_array( $token, $selected, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
							<?php
						}
					}
					?>
				</div>
			</div>
			<div>
				<span class="remember-notify-roles__label"><?php esc_html_e( 'reMember', 'remember' ); ?></span>
				<div class="remember-notify-roles__list">
					<?php
					foreach ( self::member_registered_role_choices() as $role_row ) {
						$role_id = isset( $role_row->role_id ) ? (int) $role_row->role_id : 0;
						if ( $role_id < 1 ) {
							continue;
						}
						$token   = 'rm:' . $role_id;
						$checked = in_array( $token, $selected, true ) || ( $default_role_id > 0 && $role_id === $default_role_id );
						$label   = isset( $role_row->role_name ) ? (string) $role_row->role_name : $token;
						if ( ! empty( $role_row->is_event_role ) ) {
							$label .= ' ' . __( '(event)', 'remember' );
						}
						?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $token ); ?>" <?php checked( $checked ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
						<?php
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Role checkboxes for one staff email template.
	 *
	 * @param string $type Notification type.
	 * @return void
	 */
	public static function render_admin_notify_roles( $type ) {
		$saved          = self::configured_admin_notify_roles( $type );
		$selected       = is_array( $saved ) ? $saved : array();
		$keeps_legacy   = ( null === $saved && in_array( $type, self::legacy_recipient_types(), true ) );
		$default        = ( null === $saved ) ? self::unsaved_default_role_id( $type ) : 0;
		$fieldset_class = 'remember-notify-roles' . ( $keeps_legacy ? ' remember-notify-roles--keep' : '' );
		?>
		<input type="hidden" name="admin_notify_roles_set[<?php echo esc_attr( $type ); ?>]" value="1">
		<fieldset class="<?php echo esc_attr( $fieldset_class ); ?>">
			<?php if ( $keeps_legacy ) : ?>
				<input type="hidden" class="remember-notify-roles-touched" name="admin_notify_roles_touched[<?php echo esc_attr( $type ); ?>]" value="0">
			<?php endif; ?>
			<legend class="screen-reader-text"><?php esc_html_e( 'Roles that receive this email', 'remember' ); ?></legend>
			<?php self::render_role_choices( 'admin_notify_roles[' . $type . '][]', $selected, $default ); ?>
			<p class="description"><?php echo esc_html( self::role_choice_help( $type, $keeps_legacy ) ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * One line under a template's role list.
	 *
	 * @param string $type          Notification type.
	 * @param bool   $keeps_legacy  True when this template still uses its older recipient rule.
	 * @return string
	 */
	private static function role_choice_help( $type, $keeps_legacy ) {
		if ( ! $keeps_legacy ) {
			return __( 'Checked roles receive this email. None checked turns it off.', 'remember' );
		}
		if ( 'waitlist_spot_opened' === $type ) {
			return __( 'Until you change these, this still goes to Event Administrators accepted to that event. After you change them and save, only the checked roles receive it. None checked turns it off.', 'remember' );
		}
		if ( 'vetting_case_waiting' === $type ) {
			return __( 'Until you change these, this still goes to everyone with the Vetting role. After you change them and save, only the checked roles receive it. None checked turns it off.', 'remember' );
		}
		return __( 'Until you change these, this still follows Vetting email recipients on the General screen. After you change them and save, only the checked roles receive it. None checked turns it off.', 'remember' );
	}

	/**
	 * reMember role rows for the new-member notification setting.
	 *
	 * @return object[]
	 */
	public static function member_registered_role_choices() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT role_id, role_name, is_event_role
			FROM {$wpdb->prefix}remember_roles
			ORDER BY is_event_role ASC, role_name ASC"
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Addresses emailed when a new member profile is created.
	 *
	 * An unsaved setting uses System Administrators, then WordPress administrators
	 * if that role has no address. A saved setting uses only the chosen roles.
	 * The new member is left off the list.
	 *
	 * @param int $exclude_user_id Member who was just created.
	 * @return string[]
	 */
	public static function member_registered_recipient_emails( $exclude_user_id = 0 ) {
		$roles = self::member_registered_notify_roles();
		if ( null === $roles ) {
			$emails = self::admin_emails();
		} else {
			$emails = self::emails_for_notify_role_tokens( $roles );
		}

		return self::without_opt_outs( self::exclude_user_from_emails( $emails, $exclude_user_id ), 'member_registered' );
	}

	/**
	 * Addresses for wp:{role} and rm:{role_id} tokens.
	 *
	 * @param string[] $tokens Saved role tokens.
	 * @return string[]
	 */
	private static function emails_for_notify_role_tokens( $tokens ) {
		global $wpdb;

		$emails   = array();
		$rm_ids   = array();
		$seen     = array();
		foreach ( (array) $tokens as $token ) {
			$token = (string) $token;
			if ( 0 === strpos( $token, 'wp:' ) ) {
				$role = substr( $token, 3 );
				if ( '' === $role ) {
					continue;
				}
				$users = get_users(
					array(
						'role'   => $role,
						'fields' => array( 'user_email' ),
						'number' => -1,
					)
				);
				foreach ( (array) $users as $user ) {
					if ( ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
						self::push_unique_email( $emails, $seen, $user->user_email );
					}
				}
				continue;
			}
			if ( preg_match( '/^rm:(\d+)$/', $token, $match ) ) {
				$role_id = absint( $match[1] );
				if ( $role_id > 0 ) {
					$rm_ids[] = $role_id;
				}
			}
		}

		$rm_ids = array_values( array_unique( $rm_ids ) );
		if ( ! empty( $rm_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $rm_ids ), '%d' ) );
			$member_ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id IN ({$placeholders})",
					...$rm_ids
				)
			);
			foreach ( self::emails_for_user_ids( is_array( $member_ids ) ? $member_ids : array() ) as $email ) {
				self::push_unique_email( $emails, $seen, $email );
			}
		}

		return $emails;
	}

	/**
	 * Append an address once, ignoring case.
	 *
	 * @param string[] $emails Collected addresses.
	 * @param array    $seen   Lowercase addresses already kept.
	 * @param string   $email  Candidate.
	 * @return void
	 */
	private static function push_unique_email( array &$emails, array &$seen, $email ) {
		$email = (string) $email;
		$key   = strtolower( $email );
		if ( '' === $key || isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		$emails[]     = $email;
	}

	/**
	 * Who receives staff vetting emails.
	 *
	 * `team` is everyone with the Vetting role. `assigned` is the primary
	 * vetter and collaborators on that case. The member's own result email
	 * does not use this.
	 *
	 * @return string team|assigned
	 */
	public static function vetting_notify_scope() {
		$options = get_option( 'remember_options', array() );
		$scope   = isset( $options['vetting_notify_targets'] ) ? $options['vetting_notify_targets'] : 'team';
		return 'assigned' === $scope ? 'assigned' : 'team';
	}

	/**
	 * Emails for members who hold the Vetting role.
	 *
	 * @return string[]
	 */
	public static function vetting_team_emails() {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-vetting-workflow.php';
		return self::emails_for_user_ids( Remember_Vetting_Workflow::vetting_team_ids() );
	}

	/**
	 * Staff recipients for one vetting case, following the notification setting.
	 *
	 * @param int $vetting_id Vetting case ID.
	 * @return string[]
	 */
	public static function vetting_case_recipient_emails( $vetting_id ) {
		if ( 'team' === self::vetting_notify_scope() ) {
			return self::vetting_team_emails();
		}

		$vetting_id = absint( $vetting_id );
		if ( $vetting_id < 1 ) {
			return array();
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
		$case = ( new Remember_Vetting() )->get( $vetting_id );
		if ( ! $case ) {
			return array();
		}

		$ids = array();
		if ( ! empty( $case->primary_vetter_id ) ) {
			$ids[] = (int) $case->primary_vetter_id;
		}
		foreach ( ( new Remember_Vetting() )->get_collaborators( $vetting_id ) as $collaborator ) {
			if ( ! empty( $collaborator->member_id ) ) {
				$ids[] = (int) $collaborator->member_id;
			}
		}

		return self::emails_for_user_ids( $ids );
	}

	/**
	 * Valid email addresses for a list of user IDs.
	 *
	 * @param array $user_ids User IDs.
	 * @return string[]
	 */
	private static function emails_for_user_ids( $user_ids ) {
		$emails = array();
		foreach ( (array) $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * Email staff about a vetting case, using the recipient setting.
	 *
	 * A waiting case whose roles have not been changed still goes to the whole vetting team.
	 *
	 * @param string $type       vetting_assigned, vetting_case_waiting, vetting_scheduled, vetting_completed, or vetting_collaborator_invited.
	 * @param int    $vetting_id Vetting case ID.
	 * @return void
	 */
	public static function notify_vetting_staff( $type, $vetting_id ) {
		$allowed = array(
			'vetting_assigned',
			'vetting_case_waiting',
			'vetting_scheduled',
			'vetting_completed',
			'vetting_collaborator_invited',
		);
		if ( ! in_array( $type, $allowed, true ) ) {
			return;
		}

		$vetting_id = absint( $vetting_id );
		require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
		$case = ( new Remember_Vetting() )->get( $vetting_id );
		if ( ! $case || empty( $case->member_id ) ) {
			return;
		}

		$emails = self::emails_for_staff_type(
			$type,
			array(
				'vetting_id' => $vetting_id,
			)
		);
		if ( empty( $emails ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Vetting email had no recipients',
				array(
					'type'       => $type,
					'vetting_id' => $vetting_id,
					'scope'      => self::vetting_notify_scope(),
				)
			);
			return;
		}

		$vetter = ! empty( $case->primary_vetter_id ) ? get_userdata( (int) $case->primary_vetter_id ) : false;
		$extra  = array(
			'vetting_id'  => $vetting_id,
			'vetter_name' => $vetter ? $vetter->display_name : '',
		);
		if ( 'vetting_scheduled' === $type && ! empty( $case->scheduled_at ) ) {
			$extra['date'] = date_i18n(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $case->scheduled_at )
			);
		}

		$context = self::context_for_member( (int) $case->member_id, $extra );
		foreach ( $emails as $email ) {
			$result = self::send( $type, $context, $email );
			if ( is_wp_error( $result ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
				Remember_Logger::warning(
					'Vetting staff email failed',
					array(
						'type'       => $type,
						'vetting_id' => $vetting_id,
						'to'         => $email,
						'error'      => $result->get_error_message(),
					)
				);
			}
		}
	}

	/**
	 * Placeholder context for a member (registration and vetting result mail).
	 *
	 * @param int   $member_id Member / WordPress user ID.
	 * @param array $extra     Extra placeholders (vetting_id, decision, etc.).
	 * @return array
	 */
	public static function context_for_member( $member_id, $extra = array() ) {
		$member_id = absint( $member_id );
		$user      = get_user_by( 'ID', $member_id );
		$status    = '';

		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member = ( new Remember_Member() )->get( $member_id );
		if ( $member && isset( $member->status ) ) {
			$status = (string) $member->status;
		}

		$vetting_id  = isset( $extra['vetting_id'] ) ? absint( $extra['vetting_id'] ) : 0;
		if ( $vetting_id < 1 && $member_id > 0 ) {
			require_once plugin_dir_path( __FILE__ ) . '../models/class-vetting.php';
			$latest = ( new Remember_Vetting() )->get_by_member( $member_id );
			if ( $latest && ! empty( $latest->vetting_id ) ) {
				$vetting_id = absint( $latest->vetting_id );
			}
		}
		$vetting_url = admin_url( 'admin.php?page=remember-vetting' );
		if ( $vetting_id > 0 ) {
			$vetting_url = admin_url( 'admin.php?page=remember-vetting&view=' . $vetting_id );
		}

		$base = array(
			'member_name'  => $user ? $user->display_name : '',
			'member_email' => ( $user && is_email( $user->user_email ) ) ? $user->user_email : '',
			'username'     => $user ? $user->user_login : '',
			'member_id'    => (string) $member_id,
			'status'       => $status,
			'date'         => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'profile_url'  => admin_url( 'admin.php?page=remember-members&view=' . $member_id ),
			'vetting_id'   => $vetting_id > 0 ? (string) $vetting_id : '',
			'vetting_url'  => $vetting_url,
			'decision'     => '',
		);

		return wp_parse_args( $extra, $base );
	}

	/**
	 * Email the configured roles that a new member profile was created.
	 *
	 * Public registration, a member added in wp-admin, and a WordPress user
	 * converted into a member all use this. The new member is not a recipient.
	 * Public registration sends that member member_profile_saved on its own.
	 *
	 * @param int $member_id Member / WordPress user ID.
	 * @return void
	 */
	public static function notify_member_registered( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id < 1 ) {
			return;
		}

		$emails = self::member_registered_recipient_emails( $member_id );
		if ( empty( $emails ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'New member registered but no email recipients',
				array(
					'member_id' => $member_id,
					'roles'     => self::member_registered_notify_roles(),
				)
			);
			return;
		}

		$context = self::context_for_member( $member_id );
		foreach ( $emails as $email ) {
			$result = self::send( 'member_registered', $context, $email );
			if ( is_wp_error( $result ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
				Remember_Logger::warning(
					'Member registration admin email failed',
					array(
						'member_id' => $member_id,
						'to'        => $email,
						'error'     => $result->get_error_message(),
					)
				);
			}
		}
	}

	/**
	 * Email the member that the public registration form saved their profile.
	 *
	 * The message says the profile was saved and that vetting or event invitation emails may follow. Later edits do not send it.
	 *
	 * @param int $member_id Member / WordPress user ID.
	 * @return void
	 */
	public static function notify_member_profile_saved( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id < 1 ) {
			return;
		}

		$user = get_userdata( $member_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Profile saved email had no recipient',
				array( 'member_id' => $member_id )
			);
			return;
		}

		$result = self::send( 'member_profile_saved', self::context_for_member( $member_id ), $user->user_email );
		if ( is_wp_error( $result ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Profile saved email failed',
				array(
					'member_id' => $member_id,
					'error'     => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Email the member and staff that an event application was created.
	 *
	 * The member gets Application Submitted. Staff get Application Received.
	 * The applicant is left off the staff list.
	 *
	 * @param int $application_id Application ID.
	 * @return void
	 */
	public static function notify_application_submitted( $application_id ) {
		$application_id = absint( $application_id );
		if ( $application_id < 1 ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			Remember_Logger::warning(
				'Application email had no member context',
				array(
					'application_id' => $application_id,
					'error'          => $context->get_error_message(),
				)
			);
			return;
		}

		$member_result = self::send( 'event_application_submitted', $context['placeholders'], $context['email'] );
		if ( is_wp_error( $member_result ) ) {
			Remember_Logger::warning(
				'Application submitted email failed',
				array(
					'application_id' => $application_id,
					'error'          => $member_result->get_error_message(),
				)
			);
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
		$application = ( new Remember_Application() )->get( $application_id );
		$member_id   = ( $application && ! empty( $application->member_id ) ) ? (int) $application->member_id : 0;
		$emails      = self::admin_broadcast_emails( 'application_received', $member_id );
		if ( empty( $emails ) ) {
			Remember_Logger::warning(
				'Application received but no staff email recipients',
				array(
					'application_id' => $application_id,
					'roles'          => self::configured_admin_notify_roles( 'application_received' ),
				)
			);
			return;
		}

		foreach ( $emails as $email ) {
			$result = self::send( 'application_received', $context['placeholders'], $email );
			if ( is_wp_error( $result ) ) {
				Remember_Logger::warning(
					'Application received email failed',
					array(
						'application_id' => $application_id,
						'error'          => $result->get_error_message(),
					)
				);
			}
		}
	}

	/**
	 * Email System Administrators that a vetting case could not be opened automatically.
	 *
	 * The database error stays in the log; the email links to the Create
	 * Vetting Case form with this member already selected.
	 *
	 * @param int $member_id Member / WordPress user ID.
	 * @return void
	 */
	public static function notify_vetting_case_failed( $member_id ) {
		$member_id = absint( $member_id );
		if ( $member_id < 1 ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		$emails = self::admin_broadcast_emails( 'vetting_case_failed' );
		if ( empty( $emails ) ) {
			Remember_Logger::error(
				'Vetting case failure had no admin email recipients',
				array( 'member_id' => $member_id )
			);
			return;
		}

		$context = self::context_for_member(
			$member_id,
			array(
				'vetting_id'  => '',
				'vetting_url' => admin_url( 'admin.php?page=remember-vetting&member_id=' . $member_id ),
			)
		);
		$sent = 0;
		foreach ( $emails as $email ) {
			$result = self::send( 'vetting_case_failed', $context, $email );
			if ( true === $result ) {
				++$sent;
			} elseif ( is_wp_error( $result ) ) {
				Remember_Logger::error(
					'Vetting case failure admin email failed',
					array(
						'member_id' => $member_id,
						'to'        => $email,
						'error'     => $result->get_error_message(),
					)
				);
			}
		}
		if ( $sent > 0 ) {
			Remember_Logger::info(
				'Vetting case failure emailed to admins',
				array(
					'member_id' => $member_id,
					'sent'      => $sent,
				)
			);
		}
	}

	/**
	 * Email the member the vetting decision (accepted or rejected).
	 *
	 * @param int    $member_id  Member / WordPress user ID.
	 * @param string $decision   accepted or rejected.
	 * @param int    $vetting_id Vetting case ID.
	 * @return bool|WP_Error True on success, false if disabled, WP_Error on failure.
	 */
	public static function send_vetting_result( $member_id, $decision, $vetting_id = 0 ) {
		$member_id = absint( $member_id );
		$decision  = sanitize_key( $decision );
		if ( $member_id < 1 || ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			return false;
		}

		$user = get_user_by( 'ID', $member_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new WP_Error( 'remember_notify_no_email', __( 'Member has no valid email.', 'remember' ) );
		}

		$type = ( 'accepted' === $decision ) ? 'member_vetted' : 'member_rejected';
		$context = self::context_for_member(
			$member_id,
			array(
				'vetting_id' => absint( $vetting_id ),
				'decision'   => $decision,
			)
		);

		$result = self::send( $type, $context, $user->user_email );
		if ( is_wp_error( $result ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Vetting result email failed',
				array(
					'member_id'  => $member_id,
					'vetting_id' => absint( $vetting_id ),
					'decision'   => $decision,
					'error'      => $result->get_error_message(),
				)
			);
		}

		return $result;
	}

	/**
	 * Email ticket-ready / accepted notice after accept.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_ticket_ready( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$result = self::send( 'event_application_accepted', $context['placeholders'], $context['email'] );
		if ( true === $result ) {
			self::mark_ticket_ready_emailed( $application_id );
		}
		return $result;
	}

	/**
	 * Email paid ticket notice.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_ticket_paid( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$result = self::send( 'event_ticket_paid', $context['placeholders'], $context['email'] );
		if ( true === $result ) {
			self::mark_ticket_paid_emailed( $application_id );
		}
		return $result;
	}

	/**
	 * Email the current ticket (admin one-click).
	 *
	 * Uses paid template when payment_status is paid; otherwise ticket-ready / accept template.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_current_ticket( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$payment_status = isset( $context['placeholders']['payment_status'] ) ? $context['placeholders']['payment_status'] : '';
		if ( 'paid' === $payment_status ) {
			return self::send_ticket_paid( $application_id );
		}

		return self::send_ticket_ready( $application_id );
	}

	/**
	 * Balance-due / payment reminder for one application.
	 *
	 * @param int $application_id Application ID.
	 * @return bool|WP_Error
	 */
	public static function send_balance_due( $application_id ) {
		$context = self::context_for_application( $application_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$payment_status = $context['placeholders']['payment_status'] ?? '';
		if ( 'paid' === $payment_status ) {
			return new WP_Error( 'remember_notify_already_paid', __( 'Application is already paid in full.', 'remember' ) );
		}

		return self::send( 'payment_due_reminder', $context['placeholders'], $context['email'] );
	}

	/**
	 * Blast balance-due emails to accepted applicants who still owe.
	 *
	 * @param array $args {
	 *     @type int $event_id Optional event filter.
	 * }
	 * @return array{sent:int,skipped:int,errors:int}
	 */
	public static function blast_balance_due( $args = array() ) {
		global $wpdb;
		$args     = wp_parse_args( $args, array( 'event_id' => 0 ) );
		$event_id = absint( $args['event_id'] );

		$sql = "SELECT a.application_id
			FROM {$wpdb->prefix}remember_event_applications a
			LEFT JOIN {$wpdb->prefix}remember_payments p ON p.event_application_id = a.application_id
			WHERE a.status = 'accepted'
			AND (a.ticket_voided IS NULL OR a.ticket_voided = 0)
			AND (
				p.payment_id IS NULL
				OR p.payment_status IN ('pending', 'partial')
			)";
		$query_args = array();
		if ( $event_id > 0 ) {
			$sql         .= ' AND a.event_id = %d';
			$query_args[] = $event_id;
		}

		if ( ! empty( $query_args ) ) {
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $query_args ) );
		} else {
			$rows = $wpdb->get_col( $sql );
		}

		$stats = array(
			'sent'    => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		foreach ( (array) $rows as $application_id ) {
			$result = self::send_balance_due( absint( $application_id ) );
			if ( true === $result ) {
				$stats['sent']++;
			} elseif ( false === $result ) {
				$stats['skipped']++;
			} else {
				$stats['errors']++;
			}
		}

		return $stats;
	}

	/**
	 * Handle payment status transitions for paid ticket email.
	 *
	 * @param int    $payment_id  Payment ID.
	 * @param string $old_status  Previous status.
	 * @param string $new_status  New status.
	 * @param object $payment     Payment row after update.
	 * @return void
	 */
	public static function maybe_send_on_payment_status_change( $payment_id, $old_status, $new_status, $payment ) {
		if ( 'paid' !== $new_status || 'paid' === $old_status ) {
			return;
		}
		if ( empty( $payment->event_application_id ) ) {
			return;
		}
		$result = self::send_ticket_paid( (int) $payment->event_application_id );
		if ( is_wp_error( $result ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
			Remember_Logger::warning(
				'Ticket paid email failed',
				array(
					'application_id' => (int) $payment->event_application_id,
					'error'          => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Build placeholder context for an application.
	 *
	 * @param int $application_id Application ID.
	 * @return array|WP_Error
	 */
	private static function context_for_application( $application_id ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-ticket.php';
		$data = Remember_Ticket::get_data( $application_id );
		if ( is_wp_error( $data ) ) {
			// Accepted-or-voided required for ticket data; fall back for edge cases.
			require_once plugin_dir_path( __FILE__ ) . '../models/class-application.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-event.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-payment.php';
			$application_model = new Remember_Application();
			$application       = $application_model->get( $application_id );
			if ( ! $application ) {
				return $data;
			}
			$user  = get_user_by( 'ID', $application->member_id );
			$event = ( new Remember_Event() )->get( $application->event_id );
			$payment = ( new Remember_Payment() )->get_by_application( $application_id );
			$placeholders = array(
				'member_name'     => $user ? $user->display_name : '',
				'event_name'      => $event ? $event->event_name : '',
				'application_id'  => (string) $application_id,
				'ticket_id'       => 'RM-' . (int) $application_id,
				'ticket_url'      => Remember_Ticket::get_ticket_url( $application_id ),
				'amount'          => $payment ? number_format( (float) $payment->amount_paid, 2 ) : '0.00',
				'amount_due'      => $payment ? number_format( (float) $payment->amount_due, 2 ) : '0.00',
				'payment_status'  => $payment ? (string) $payment->payment_status : '',
				'event_location'  => '',
				'event_dates'     => '',
				'date'            => date_i18n( get_option( 'date_format' ) ),
			);
			$email = $user ? $user->user_email : '';
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'remember_notify_no_email', __( 'Member has no valid email.', 'remember' ) );
			}
			return array(
				'email'        => $email,
				'placeholders' => $placeholders,
			);
		}

		$email = $data['member_email'];
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'remember_notify_no_email', __( 'Member has no valid email.', 'remember' ) );
		}

		$placeholders = array(
			'member_name'    => $data['member_name'],
			'event_name'     => $data['event_name'],
			'application_id' => (string) $data['application_id'],
			'ticket_id'      => $data['ticket_id'],
			'ticket_url'     => Remember_Ticket::get_ticket_url( $data['application_id'] ),
			'amount'         => number_format( (float) $data['amount_paid'], 2 ),
			'amount_due'     => number_format( (float) $data['amount_due'], 2 ),
			'payment_status' => $data['payment_status'],
			'event_location' => ! empty( $data['location_lines'] ) ? implode( ', ', $data['location_lines'] ) : '',
			'event_dates'    => $data['event_dates'],
			'date'           => date_i18n( get_option( 'date_format' ) ),
		);

		return array(
			'email'        => $email,
			'placeholders' => $placeholders,
		);
	}

	/**
	 * @param array $context Context.
	 * @return array
	 */
	private static function normalize_context( $context ) {
		$defaults = array(
			'member_name'    => '',
			'event_name'     => '',
			'application_id' => '',
			'ticket_id'      => '',
			'ticket_url'     => '',
			'amount'         => '',
			'amount_due'     => '',
			'payment_status' => '',
			'event_location' => '',
			'event_dates'    => '',
			'date'           => date_i18n( get_option( 'date_format' ) ),
			'vetting_id'     => '',
			'review_url'     => '',
			'match_fields'   => '',
			'member_a_id'    => '',
			'member_b_id'    => '',
			'member_email'   => '',
			'username'       => '',
			'member_id'      => '',
			'status'         => '',
			'profile_url'    => '',
			'vetting_url'    => '',
			'decision'       => '',
			'survey_title'   => '',
			'survey_url'     => '',
			'role_name'      => '',
			'waitlist_count' => '',
			'apply_url'      => '',
		);
		return wp_parse_args( $context, $defaults );
	}

	/**
	 * @param string $text    Template text.
	 * @param array  $context Placeholders.
	 * @return string
	 */
	private static function replace_placeholders( $text, $context ) {
		foreach ( $context as $key => $value ) {
			$text = str_replace( '{' . $key . '}', (string) $value, $text );
		}
		return $text;
	}

	/**
	 * @param int $application_id Application ID.
	 * @return void
	 */
	private static function mark_ticket_ready_emailed( $application_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'remember_event_applications',
			array( 'ticket_ready_emailed_at' => current_time( 'mysql' ) ),
			array( 'application_id' => absint( $application_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $application_id Application ID.
	 * @return void
	 */
	private static function mark_ticket_paid_emailed( $application_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'remember_payments',
			array( 'ticket_paid_emailed_at' => current_time( 'mysql' ) ),
			array( 'event_application_id' => absint( $application_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
