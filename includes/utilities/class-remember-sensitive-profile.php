<?php
/**
 * Health and emergency-contact sections on the admin member screen.
 *
 * Both sections start closed. Staff open one with a button, which loads it
 * over AJAX and writes one access-log entry. A profile save writes a section
 * only when it was opened on the edit screen, so a closed section is never
 * cleared, and that save is logged too. A confidential print loads every
 * section the user may see and is logged before the print dialog opens.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Opens, renders, and tracks the sensitive profile sections.
 */
class Remember_Sensitive_Profile {

	const AJAX_ACTION  = 'remember_reveal_sensitive';
	const PRINT_ACTION = 'remember_print_confidential';
	const NONCE        = 'remember_reveal_sensitive';
	const POST_FLAG    = 'remember_sensitive_loaded';

	/**
	 * Register the AJAX handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_reveal' ) );
		add_action( 'wp_ajax_' . self::PRINT_ACTION, array( __CLASS__, 'ajax_print' ) );
	}

	/**
	 * Sections a confidential print includes for the current user. Empty without the print cap.
	 *
	 * @return string[]
	 */
	public static function print_topics() {
		if ( ! current_user_can( 'remember_print_confidential' ) ) {
			return array();
		}
		return array_values( array_filter( array( 'emergency', 'health' ), array( __CLASS__, 'can_open' ) ) );
	}

	/**
	 * Whether the current user may open this section at all.
	 *
	 * @param string $topic health|emergency.
	 * @return bool
	 */
	public static function can_open( $topic ) {
		if ( 'health' === $topic ) {
			return current_user_can( 'remember_access_health' );
		}
		if ( 'emergency' === $topic ) {
			return current_user_can( 'remember_access_emergency_contact' );
		}
		return false;
	}

	/**
	 * Closed section with a button that opens it.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $topic     health|emergency.
	 * @param string $mode      view|edit.
	 * @return string
	 */
	public static function placeholder( $member_id, $topic, $mode ) {
		if ( ! self::can_open( $topic ) ) {
			return '';
		}
		$mode   = 'edit' === $mode ? 'edit' : 'view';
		$title  = 'health' === $topic ? __( 'Health Information', 'remember' ) : __( 'Emergency Contact', 'remember' );
		$button = 'health' === $topic ? __( 'Show health information', 'remember' ) : __( 'Show emergency contact', 'remember' );
		$attrs  = ' data-remember-sensitive="' . esc_attr( $topic ) . '"'
			. ' data-mode="' . esc_attr( $mode ) . '"'
			. ' data-member-id="' . esc_attr( (string) absint( $member_id ) ) . '"'
			. ' data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"'
			. ' data-error="' . esc_attr__( 'Could not load this section. Reload the page and try again.', 'remember' ) . '"';

		$inner  = '<h3>' . esc_html( $title ) . '</h3>';
		$inner .= '<p class="description">' . esc_html__( 'Hidden until you open it. Opening it is recorded in the access log.', 'remember' ) . '</p>';
		$inner .= '<p><button type="button" class="button remember-sensitive-open">' . esc_html( $button ) . '</button></p>';
		$inner .= '<p class="remember-sensitive-error" role="alert" hidden></p>';

		if ( 'edit' === $mode ) {
			return '<div class="remember-sensitive-placeholder"' . $attrs . '>' . $inner . '</div>';
		}
		if ( 'health' === $topic ) {
			return '<div class="remember-member-detail-health-column remember-no-print"' . $attrs . '>'
				. '<div class="remember-member-detail-section remember-member-detail-health-card">' . $inner . '</div>'
				. '</div>';
		}
		return '<div class="remember-member-detail-section remember-member-detail-emergency-card remember-no-print"' . $attrs . '>' . $inner . '</div>';
	}

	/**
	 * Topics whose fields were opened on the edit screen and may be saved.
	 *
	 * @return string[]
	 */
	public static function posted_topics() {
		$flags  = isset( $_POST[ self::POST_FLAG ] ) && is_array( $_POST[ self::POST_FLAG ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the profile save checks its nonce first.
			? wp_unslash( $_POST[ self::POST_FLAG ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only checked for presence.
			: array();
		$topics = array();
		foreach ( array( 'health', 'emergency' ) as $topic ) {
			if ( ! empty( $flags[ $topic ] ) && self::can_open( $topic ) ) {
				$topics[] = $topic;
			}
		}
		return $topics;
	}

	/**
	 * AJAX: open one section and log it.
	 *
	 * @return void
	 */
	public static function ajax_reveal() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$topic     = isset( $_POST['topic'] ) ? sanitize_key( wp_unslash( $_POST['topic'] ) ) : '';
		$mode      = ( isset( $_POST['mode'] ) && 'edit' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ) ? 'edit' : 'view';

		if ( $member_id < 1 || ! self::can_open( $topic ) || ! self::can_view_member( $member_id ) ) {
			Remember_Logger::warning(
				'Sensitive profile section refused',
				array(
					'member_id' => $member_id,
					'topic'     => $topic,
					'user_id'   => get_current_user_id(),
				)
			);
			wp_send_json_error( array( 'message' => __( 'You do not have permission to open this section.', 'remember' ) ), 403 );
		}
		if ( 'edit' === $mode && ! current_user_can( 'remember_update_members' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit members.', 'remember' ) ), 403 );
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member_model = new Remember_Member();
		if ( ! $member_model->get( $member_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Member not found.', 'remember' ) ), 404 );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-fields.php';
		Remember_Profile_Fields::ensure_allergy_reaction_column();

		if ( 'edit' === $mode ) {
			$html = 'health' === $topic ? self::render_health_edit( $member_id ) : self::render_emergency_edit( $member_id );
		} else {
			$html = 'health' === $topic ? self::render_health_view( $member_id ) : self::render_emergency_view( $member_id );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';
		Remember_Access_Log::record( $member_id, $topic, 'edit' === $mode ? __( 'Member edit', 'remember' ) : __( 'Member detail', 'remember' ) );
		Remember_Logger::info(
			'Sensitive profile section opened',
			array(
				'member_id' => $member_id,
				'topic'     => $topic,
				'mode'      => $mode,
				'user_id'   => get_current_user_id(),
			)
		);

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: load every section the confidential print includes and log the print before it opens.
	 *
	 * @return void
	 */
	public static function ajax_print() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$topics    = self::print_topics();
		if ( $member_id < 1 || empty( $topics ) || ! self::can_view_member( $member_id ) ) {
			Remember_Logger::warning(
				'Confidential print refused',
				array(
					'member_id' => $member_id,
					'user_id'   => get_current_user_id(),
				)
			);
			wp_send_json_error( array( 'message' => __( 'You do not have permission to print this confidential profile.', 'remember' ) ), 403 );
		}

		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member_model = new Remember_Member();
		if ( ! $member_model->get( $member_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Member not found.', 'remember' ) ), 404 );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-fields.php';
		Remember_Profile_Fields::ensure_allergy_reaction_column();

		$sections = array();
		foreach ( $topics as $topic ) {
			$sections[ $topic ] = 'health' === $topic ? self::render_health_view( $member_id ) : self::render_emergency_view( $member_id );
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-remember-access-log.php';
		Remember_Access_Log::record( $member_id, Remember_Access_Log::what_from_topics( $topics ), __( 'Confidential print', 'remember' ) );
		Remember_Logger::info(
			'Confidential profile printed',
			array(
				'member_id' => $member_id,
				'topics'    => $topics,
				'user_id'   => get_current_user_id(),
			)
		);

		wp_send_json_success( array( 'sections' => $sections ) );
	}

	/**
	 * Same rule as the Members screen: full member access, or an attendee on the viewer's events.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	private static function can_view_member( $member_id ) {
		if ( current_user_can( 'remember_read_members' ) ) {
			return true;
		}
		if ( ! current_user_can( 'remember_read_attendees' ) ) {
			return false;
		}
		require_once plugin_dir_path( __FILE__ ) . '../models/class-member.php';
		$member_model = new Remember_Member();
		return (bool) $member_model->guard_can_view_attendee( get_current_user_id(), $member_id );
	}

	/**
	 * Profile row columns used by these sections.
	 *
	 * @param int $member_id Member ID.
	 * @return object|null
	 */
	private static function profile( $member_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT emergency_contact_first, emergency_contact_last, emergency_contact_phone, emergency_contact_relationship, allergy_reaction
				FROM {$wpdb->prefix}remember_member_profiles WHERE member_id = %d",
				$member_id
			)
		);
	}

	/**
	 * Emergency contact card for the detail screen.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function render_emergency_view( $member_id ) {
		$profile      = self::profile( $member_id );
		$not_provided = '<span class="description">' . esc_html__( 'Not provided', 'remember' ) . '</span>';

		ob_start();
		?>
		<!-- Emergency Contact — confidential printout only; excluded from the event card. -->
		<div class="remember-member-detail-section remember-member-detail-emergency-card">
			<h3><?php esc_html_e( 'Emergency Contact', 'remember' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Name', 'remember' ); ?></th>
					<td>
						<?php if ( $profile ) : ?>
							<?php echo esc_html( trim( $profile->emergency_contact_first . ' ' . $profile->emergency_contact_last ) ); ?>
						<?php else : ?>
							<?php echo $not_provided; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html__() above. ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Phone', 'remember' ); ?></th>
					<td>
						<?php echo $profile && $profile->emergency_contact_phone ? esc_html( $profile->emergency_contact_phone ) : $not_provided; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here or above. ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Relationship', 'remember' ); ?></th>
					<td>
						<?php echo $profile && $profile->emergency_contact_relationship ? esc_html( $profile->emergency_contact_relationship ) : $not_provided; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here or above. ?>
					</td>
				</tr>
			</table>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Dietary, allergy, and medical cards for the detail screen.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function render_health_view( $member_id ) {
		global $wpdb;
		$profile = self::profile( $member_id );

		$dietary = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT dr.restriction_name
				FROM {$wpdb->prefix}remember_dietary_restrictions dr
				JOIN {$wpdb->prefix}remember_member_dietary_restrictions mdr ON dr.restriction_id = mdr.restriction_id
				WHERE mdr.member_id = %d
				ORDER BY dr.sort_order ASC, dr.restriction_name ASC",
				$member_id
			)
		);
		$medical = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ma.accommodation_name
				FROM {$wpdb->prefix}remember_medical_accommodations ma
				JOIN {$wpdb->prefix}remember_member_medical_accommodations mma ON ma.accommodation_id = mma.accommodation_id
				WHERE mma.member_id = %d
				ORDER BY ma.sort_order ASC, ma.accommodation_name ASC",
				$member_id
			)
		);
		$allergies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.allergy_name
				FROM {$wpdb->prefix}remember_allergies a
				JOIN {$wpdb->prefix}remember_member_allergies ma ON a.allergy_id = ma.allergy_id
				WHERE ma.member_id = %d
				ORDER BY a.sort_order ASC, a.allergy_name ASC",
				$member_id
			)
		);

		$reaction_text = ( $profile && isset( $profile->allergy_reaction ) ) ? trim( (string) $profile->allergy_reaction ) : '';
		$show_reaction = ( '' !== $reaction_text );
		if ( ! $show_reaction ) {
			foreach ( $allergies as $allergy_name ) {
				if ( 'None' !== $allergy_name ) {
					$show_reaction = true;
					break;
				}
			}
		}

		ob_start();
		?>
		<!-- Dietary, Allergies, Medical: stacked in the last column; always show all three cards -->
		<div class="remember-member-detail-health-column">
			<div class="remember-member-detail-section remember-member-detail-health-card">
				<h3><?php esc_html_e( 'Dietary Restrictions', 'remember' ); ?></h3>
				<?php if ( ! empty( $dietary ) ) : ?>
					<div class="remember-member-detail-pill-row">
						<?php foreach ( $dietary as $restriction ) : ?>
							<span class="remember-member-detail-pill remember-member-detail-pill--dietary"><?php echo esc_html( $restriction ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="remember-member-detail-none"><?php esc_html_e( 'None', 'remember' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="remember-member-detail-section remember-member-detail-health-card">
				<h3><?php esc_html_e( 'Known Allergies', 'remember' ); ?></h3>
				<?php if ( ! empty( $allergies ) ) : ?>
					<div class="remember-member-detail-pill-row">
						<?php foreach ( $allergies as $allergy ) : ?>
							<span class="remember-member-detail-pill remember-member-detail-pill--allergy"><?php echo esc_html( $allergy ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="remember-member-detail-none"><?php esc_html_e( 'None', 'remember' ); ?></p>
				<?php endif; ?>
				<?php if ( $show_reaction ) : ?>
					<div class="remember-member-detail-reaction">
						<strong class="remember-member-detail-reaction__label"><?php echo esc_html( Remember_Profile_Fields::allergy_reaction_prompt() ); ?></strong>
						<?php if ( '' !== $reaction_text ) : ?>
							<p><?php echo nl2br( esc_html( $reaction_text ) ); ?></p>
						<?php else : ?>
							<p class="remember-member-detail-none"><?php esc_html_e( 'Not provided', 'remember' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="remember-member-detail-section remember-member-detail-health-card">
				<h3><?php esc_html_e( 'Medical Accommodations', 'remember' ); ?></h3>
				<?php if ( ! empty( $medical ) ) : ?>
					<div class="remember-member-detail-pill-row">
						<?php foreach ( $medical as $accommodation ) : ?>
							<span class="remember-member-detail-pill remember-member-detail-pill--medical"><?php echo esc_html( $accommodation ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="remember-member-detail-none"><?php esc_html_e( 'None', 'remember' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Emergency contact fields for the edit form.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function render_emergency_edit( $member_id ) {
		$profile = self::profile( $member_id );

		ob_start();
		?>
		<div class="remember-sensitive-section" data-remember-sensitive-open="emergency">
			<input type="hidden" name="<?php echo esc_attr( self::POST_FLAG ); ?>[emergency]" value="1">
			<!-- Emergency Contact -->
			<h3><?php esc_html_e( 'Emergency Contact', 'remember' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="emergency_contact_first"><?php esc_html_e( 'First Name', 'remember' ); ?></label></th>
					<td>
						<input type="text" id="emergency_contact_first" name="emergency_contact_first" class="regular-text" value="<?php echo esc_attr( $profile ? $profile->emergency_contact_first : '' ); ?>">
					</td>
				</tr>
				<tr>
					<th><label for="emergency_contact_last"><?php esc_html_e( 'Last Name', 'remember' ); ?></label></th>
					<td>
						<input type="text" id="emergency_contact_last" name="emergency_contact_last" class="regular-text" value="<?php echo esc_attr( $profile ? $profile->emergency_contact_last : '' ); ?>">
					</td>
				</tr>
				<tr>
					<th><label for="emergency_contact_phone"><?php esc_html_e( 'Phone', 'remember' ); ?></label></th>
					<td>
						<input type="text" id="emergency_contact_phone" name="emergency_contact_phone" class="regular-text" value="<?php echo esc_attr( $profile ? $profile->emergency_contact_phone : '' ); ?>" placeholder="<?php esc_attr_e( '+18055551212', 'remember' ); ?>">
					</td>
				</tr>
				<tr>
					<th><label for="emergency_contact_relationship"><?php esc_html_e( 'Relationship', 'remember' ); ?></label></th>
					<td>
						<input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" class="regular-text" value="<?php echo esc_attr( $profile ? $profile->emergency_contact_relationship : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g., Spouse, Parent, Friend', 'remember' ); ?>">
					</td>
				</tr>
			</table>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Dietary, medical, and allergy fields for the edit form.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private static function render_health_edit( $member_id ) {
		global $wpdb;
		$profile = self::profile( $member_id );

		$dietary_restrictions   = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}remember_dietary_restrictions WHERE is_active = 1 ORDER BY CASE WHEN restriction_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, restriction_name ASC"
		);
		$medical_accommodations = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}remember_medical_accommodations WHERE is_active = 1 ORDER BY CASE WHEN accommodation_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, accommodation_name ASC"
		);
		$allergies              = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}remember_allergies WHERE is_active = 1 ORDER BY CASE WHEN allergy_name = 'None' THEN 0 ELSE 1 END ASC, sort_order ASC, allergy_name ASC"
		);
		$selected_dietary_ids   = $wpdb->get_col( $wpdb->prepare( "SELECT restriction_id FROM {$wpdb->prefix}remember_member_dietary_restrictions WHERE member_id = %d", $member_id ) );
		$selected_medical_ids   = $wpdb->get_col( $wpdb->prepare( "SELECT accommodation_id FROM {$wpdb->prefix}remember_member_medical_accommodations WHERE member_id = %d", $member_id ) );
		$selected_allergy_ids   = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT allergy_id FROM {$wpdb->prefix}remember_member_allergies WHERE member_id = %d", $member_id ) ) );

		$reaction_needed = false;
		foreach ( (array) $allergies as $allergy ) {
			if ( 'None' !== $allergy->allergy_name && in_array( (string) $allergy->allergy_id, $selected_allergy_ids, true ) ) {
				$reaction_needed = true;
				break;
			}
		}
		$reaction_text = ( $profile && isset( $profile->allergy_reaction ) ) ? (string) $profile->allergy_reaction : '';

		ob_start();
		?>
		<div class="remember-sensitive-section" data-remember-sensitive-open="health">
			<input type="hidden" name="<?php echo esc_attr( self::POST_FLAG ); ?>[health]" value="1">
			<!-- Dietary Restrictions -->
			<?php if ( ! empty( $dietary_restrictions ) ) : ?>
				<h3><?php esc_html_e( 'Dietary Restrictions', 'remember' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Select Restrictions', 'remember' ); ?></th>
						<td>
							<fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 3px;">
								<?php foreach ( $dietary_restrictions as $restriction ) : ?>
									<label style="display: block; margin: 5px 0;">
										<input type="checkbox" name="dietary_restrictions[]" value="<?php echo esc_attr( $restriction->restriction_id ); ?>" <?php checked( in_array( $restriction->restriction_id, $selected_dietary_ids ) ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- ids come back as strings. ?>>
										<?php echo esc_html( $restriction->restriction_name ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<!-- Medical Accommodations -->
			<?php if ( ! empty( $medical_accommodations ) ) : ?>
				<h3><?php esc_html_e( 'Medical Accommodations', 'remember' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Select Accommodations', 'remember' ); ?></th>
						<td>
							<fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 3px;">
								<?php foreach ( $medical_accommodations as $accommodation ) : ?>
									<label style="display: block; margin: 5px 0;">
										<input type="checkbox" name="medical_accommodations[]" value="<?php echo esc_attr( $accommodation->accommodation_id ); ?>" <?php checked( in_array( $accommodation->accommodation_id, $selected_medical_ids ) ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- ids come back as strings. ?>>
										<?php echo esc_html( $accommodation->accommodation_name ); ?>
										<?php if ( ! empty( $accommodation->description ) ) : ?>
											<span class="description" style="margin-left: 5px;">- <?php echo esc_html( $accommodation->description ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<!-- Allergies -->
			<?php if ( ! empty( $allergies ) ) : ?>
				<div data-remember-allergy-group="1">
					<h3><?php esc_html_e( 'Known Allergies', 'remember' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Select Allergies', 'remember' ); ?></th>
							<td>
								<fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 3px;">
									<?php foreach ( $allergies as $allergy ) : ?>
										<label style="display: block; margin: 5px 0;">
											<input type="checkbox" name="allergies[]" value="<?php echo esc_attr( $allergy->allergy_id ); ?>" <?php checked( in_array( (string) $allergy->allergy_id, $selected_allergy_ids, true ) ); ?><?php echo ( 'None' === $allergy->allergy_name ) ? ' data-remember-none="1"' : ''; ?>>
											<?php echo esc_html( $allergy->allergy_name ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<div class="remember-allergy-reaction" data-remember-allergy-reaction="1"<?php echo $reaction_needed ? '' : ' hidden'; ?>>
									<label for="remember_allergy_reaction"><?php echo esc_html( Remember_Profile_Fields::allergy_reaction_prompt() ); ?></label>
									<textarea name="allergy_reaction" id="remember_allergy_reaction" rows="6" class="large-text"<?php echo $reaction_needed ? ' required' : ''; ?>><?php echo esc_textarea( $reaction_text ); ?></textarea>
								</div>
							</td>
						</tr>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
