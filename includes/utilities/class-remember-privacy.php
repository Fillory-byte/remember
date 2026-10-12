<?php
/**
 * WordPress personal-data export and erase for reMember records.
 *
 * Vetting notes and profile notes are counted in an export. Their text is
 * staff commentary and is not included. Erase deletes that text. Payment
 * amounts, status, and invoice ids stay so the books still add up. The photo
 * file is deleted.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Tools → Export Personal Data and Erase Personal Data.
 */
class Remember_Privacy {

	const PAGE_SIZE = 50;

	/**
	 * Register exporters, erasers, and suggested privacy-policy text.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['remember'] = array(
			'exporter_friendly_name' => __( 'reMember', 'remember' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['remember'] = array(
			'eraser_friendly_name' => __( 'reMember', 'remember' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Suggested text on Settings → Privacy.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'When you have a member profile, we store your legal name, address, phone, instant-message handle, clothing sizes, interests, profile photo, emergency contact, dietary restrictions, allergies, medical accommodations, custom profile answers, event applications, private-event invitations, and survey answers.', 'remember' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Staff notes about you are kept for membership review. A copy of your data includes how many notes exist. It does not include the note text.', 'remember' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If you ask us to erase your data, we remove those personal fields and your photo. We keep payment amounts, status, and invoice identifiers so the financial record stays complete. We also keep the fact that you accepted an event agreement, without your typed name or IP address.', 'remember' ) . '</p>';
		wp_add_privacy_policy_content( 'reMember', wp_kses_post( $content ) );
	}

	/**
	 * Export one page of this member's reMember data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email, $page = 1 ) {
		$page      = max( 1, (int) $page );
		$member_id = self::member_id_for_email( $email );
		if ( $member_id < 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data = array();
		if ( 1 === $page ) {
			$data = array_merge( $data, self::profile_groups( $member_id ) );
		}
		$applications = self::application_group( $member_id, $page );
		$payments     = self::payment_group( $member_id, $page );
		$data         = array_merge( $data, $applications['items'], $payments['items'] );

		return array(
			'data' => $data,
			'done' => $applications['done'] && $payments['done'],
		);
	}

	/**
	 * Remove personal reMember fields for this email. Payment totals stay.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page. Unused; one pass finishes the member.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$member_id = self::member_id_for_email( $email );
		if ( $member_id < 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed  = false;
		$retained = false;
		$messages = array();

		if ( self::erase_photo( $member_id ) ) {
			$removed = true;
		}
		if ( self::blank_profile( $member_id ) ) {
			$removed = true;
		}
		if ( self::delete_member_rows( $member_id, array( 'remember_member_dietary_restrictions', 'remember_member_allergies', 'remember_member_medical_accommodations', 'remember_member_social_media', 'remember_profile_question_responses', 'remember_profile_notes', 'remember_vetting_notes', 'remember_sensitive_access_log', 'remember_event_invites' ) ) ) {
			$removed = true;
		}
		if ( self::delete_survey_answers( $member_id ) ) {
			$removed = true;
		}
		if ( self::blank_application_notes( $member_id ) ) {
			$removed = true;
		}
		$agreements = self::blank_agreement_identity( $member_id );
		if ( $agreements['removed'] ) {
			$removed = true;
		}
		if ( $agreements['retained'] ) {
			$retained   = true;
			$messages[] = __( 'Event agreement acceptances were kept. The typed name and IP address were removed.', 'remember' );
		}
		$payments = self::blank_payment_personal_fields( $member_id );
		if ( $payments['removed'] ) {
			$removed = true;
		}
		if ( $payments['retained'] ) {
			$retained   = true;
			$messages[] = __( 'Payment amounts, status, and invoice identifiers were kept. Notes and invoice links were removed.', 'remember' );
		}
		$duplicates = self::blank_duplicate_snapshots( $member_id );
		if ( $duplicates['removed'] ) {
			$removed = true;
		}
		if ( $duplicates['retained'] ) {
			$retained   = true;
			$messages[] = __( 'Duplicate-profile review records were kept. Stored copies of the profile were removed.', 'remember' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Member id for an email, or 0 when this person has no reMember row.
	 *
	 * @param string $email Email address.
	 * @return int
	 */
	private static function member_id_for_email( $email ) {
		$user = get_user_by( 'email', sanitize_email( (string) $email ) );
		if ( ! $user ) {
			return 0;
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT member_id FROM ' . self::table( 'remember_members' ) . ' WHERE member_id = %d', (int) $user->ID ) );
		return $found ? (int) $found : 0;
	}

	/**
	 * Profile, health, emergency contact, notes counts, and survey answers.
	 *
	 * @param int $member_id Member ID.
	 * @return array<int,array>
	 */
	private static function profile_groups( $member_id ) {
		global $wpdb;
		$groups = array();
		$profile = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'remember_member_profiles' ) . ' WHERE member_id = %d', $member_id ), ARRAY_A );
		$member  = $wpdb->get_row( $wpdb->prepare( 'SELECT photo_url, status FROM ' . self::table( 'remember_members' ) . ' WHERE member_id = %d', $member_id ), ARRAY_A );

		$profile_fields = array(
			'legal_first_name' => __( 'Legal first name', 'remember' ),
			'legal_last_name'  => __( 'Legal last name', 'remember' ),
			'address_street'   => __( 'Street', 'remember' ),
			'address_city'     => __( 'City', 'remember' ),
			'address_state'    => __( 'State', 'remember' ),
			'address_postal'   => __( 'Postal code', 'remember' ),
			'address_country'  => __( 'Country', 'remember' ),
			'cell_phone'       => __( 'Cell phone', 'remember' ),
			'im_handle'        => __( 'Instant message handle', 'remember' ),
			'im_type'          => __( 'Instant message type', 'remember' ),
			'interests'        => __( 'Interests', 'remember' ),
			'shirt_size'       => __( 'Shirt size', 'remember' ),
			'pants_size'       => __( 'Pants size', 'remember' ),
			'shoe_size'        => __( 'Shoe size', 'remember' ),
			'member_number'    => __( 'Member number', 'remember' ),
		);
		$profile_data = array();
		if ( is_array( $profile ) ) {
			foreach ( $profile_fields as $key => $label ) {
				self::add_value( $profile_data, $label, isset( $profile[ $key ] ) ? $profile[ $key ] : '' );
			}
		}
		if ( is_array( $member ) ) {
			self::add_value( $profile_data, __( 'Member status', 'remember' ), isset( $member['status'] ) ? $member['status'] : '' );
			self::add_value( $profile_data, __( 'Profile photo URL', 'remember' ), isset( $member['photo_url'] ) ? $member['photo_url'] : '' );
		}
		$handles = $wpdb->get_results( $wpdb->prepare( 'SELECT p.platform_name, m.handle FROM ' . self::table( 'remember_member_social_media' ) . ' m LEFT JOIN ' . self::table( 'remember_social_media_platforms' ) . ' p ON p.platform_id = m.platform_id WHERE m.member_id = %d', $member_id ) );
		if ( is_array( $handles ) ) {
			foreach ( $handles as $handle ) {
				self::add_value( $profile_data, $handle->platform_name ? $handle->platform_name : __( 'Social handle', 'remember' ), $handle->handle );
			}
		}
		$groups[] = self::group( 'remember-profile', __( 'reMember profile', 'remember' ), 'profile-' . $member_id, $profile_data );

		$health = array();
		self::add_value( $health, __( 'Dietary restrictions', 'remember' ), self::name_list( $member_id, 'remember_member_dietary_restrictions', 'remember_dietary_restrictions', 'restriction_id', 'restriction_name' ) );
		self::add_value( $health, __( 'Allergies', 'remember' ), self::name_list( $member_id, 'remember_member_allergies', 'remember_allergies', 'allergy_id', 'allergy_name' ) );
		self::add_value( $health, __( 'Allergy reaction', 'remember' ), is_array( $profile ) && isset( $profile['allergy_reaction'] ) ? $profile['allergy_reaction'] : '' );
		self::add_value( $health, __( 'Medical accommodations', 'remember' ), self::name_list( $member_id, 'remember_member_medical_accommodations', 'remember_medical_accommodations', 'accommodation_id', 'accommodation_name' ) );
		if ( ! empty( $health ) ) {
			$groups[] = self::group( 'remember-health', __( 'reMember health', 'remember' ), 'health-' . $member_id, $health );
		}

		$emergency = array();
		if ( is_array( $profile ) ) {
			self::add_value( $emergency, __( 'Emergency contact first name', 'remember' ), $profile['emergency_contact_first'] );
			self::add_value( $emergency, __( 'Emergency contact last name', 'remember' ), $profile['emergency_contact_last'] );
			self::add_value( $emergency, __( 'Emergency contact phone', 'remember' ), $profile['emergency_contact_phone'] );
			self::add_value( $emergency, __( 'Emergency contact relationship', 'remember' ), $profile['emergency_contact_relationship'] );
		}
		if ( ! empty( $emergency ) ) {
			$groups[] = self::group( 'remember-emergency', __( 'reMember emergency contact', 'remember' ), 'emergency-' . $member_id, $emergency );
		}

		$answers = $wpdb->get_results( $wpdb->prepare( 'SELECT q.label, r.value_text FROM ' . self::table( 'remember_profile_question_responses' ) . ' r INNER JOIN ' . self::table( 'remember_profile_questions' ) . ' q ON q.question_id = r.question_id WHERE r.member_id = %d', $member_id ) );
		$custom  = array();
		if ( is_array( $answers ) ) {
			foreach ( $answers as $answer ) {
				self::add_value( $custom, $answer->label, $answer->value_text );
			}
		}
		if ( ! empty( $custom ) ) {
			$groups[] = self::group( 'remember-custom', __( 'reMember custom answers', 'remember' ), 'custom-' . $member_id, $custom );
		}

		$vetting = $wpdb->get_row( $wpdb->prepare( 'SELECT status, decision, decision_date FROM ' . self::table( 'remember_vetting' ) . ' WHERE member_id = %d ORDER BY vetting_id DESC LIMIT 1', $member_id ), ARRAY_A );
		$vetting_data = array();
		if ( is_array( $vetting ) ) {
			self::add_value( $vetting_data, __( 'Vetting status', 'remember' ), $vetting['status'] );
			self::add_value( $vetting_data, __( 'Vetting decision', 'remember' ), $vetting['decision'] );
			self::add_value( $vetting_data, __( 'Vetting decision date', 'remember' ), $vetting['decision_date'] );
		}
		$note_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'remember_vetting_notes' ) . ' WHERE member_id = %d', $member_id ) );
		if ( $note_count > 0 ) {
			self::add_value( $vetting_data, __( 'Vetting notes', 'remember' ), sprintf( /* translators: %d: number of notes */ _n( '%d staff note. The text is not included.', '%d staff notes. The text is not included.', $note_count, 'remember' ), $note_count ) );
		}
		if ( ! empty( $vetting_data ) ) {
			$groups[] = self::group( 'remember-vetting', __( 'reMember vetting', 'remember' ), 'vetting-' . $member_id, $vetting_data );
		}

		$profile_notes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'remember_profile_notes' ) . ' WHERE member_id = %d', $member_id ) );
		if ( $profile_notes > 0 ) {
			$groups[] = self::group(
				'remember-notes',
				__( 'reMember profile notes', 'remember' ),
				'notes-' . $member_id,
				array(
					array(
						'name'  => __( 'Profile notes', 'remember' ),
						'value' => sprintf( /* translators: %d: number of notes */ _n( '%d note. The text is not included.', '%d notes. The text is not included.', $profile_notes, 'remember' ), $profile_notes ),
					),
				)
			);
		}

		$surveys = $wpdb->get_results( $wpdb->prepare( 'SELECT s.title, q.label, a.value_text FROM ' . self::table( 'remember_survey_answers' ) . ' a INNER JOIN ' . self::table( 'remember_survey_responses' ) . ' r ON r.response_id = a.response_id INNER JOIN ' . self::table( 'remember_survey_questions' ) . ' q ON q.question_id = a.question_id INNER JOIN ' . self::table( 'remember_surveys' ) . ' s ON s.survey_id = r.survey_id WHERE r.member_id = %d', $member_id ) );
		$survey_data = array();
		if ( is_array( $surveys ) ) {
			foreach ( $surveys as $row ) {
				self::add_value( $survey_data, $row->title . ' — ' . $row->label, $row->value_text );
			}
		}
		if ( ! empty( $survey_data ) ) {
			$groups[] = self::group( 'remember-surveys', __( 'reMember survey answers', 'remember' ), 'surveys-' . $member_id, $survey_data );
		}

		$invites_table = self::table( 'remember_event_invites' );
		$invites       = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $invites_table ) ) === $invites_table ) {
			$invites = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT e.event_name, i.invited_at, i.invited_emailed_at FROM ' . $invites_table . ' i LEFT JOIN ' . self::table( 'remember_events' ) . ' e ON e.event_id = i.event_id WHERE i.member_id = %d ORDER BY i.invite_id ASC',
					$member_id
				)
			);
		}
		$invite_data = array();
		if ( is_array( $invites ) ) {
			foreach ( $invites as $invite ) {
				$when = $invite->invited_emailed_at ? $invite->invited_emailed_at : $invite->invited_at;
				self::add_value( $invite_data, $invite->event_name ? $invite->event_name : __( 'Private event', 'remember' ), $when );
			}
		}
		if ( ! empty( $invite_data ) ) {
			$groups[] = self::group( 'remember-event-invites', __( 'reMember event invitations', 'remember' ), 'invites-' . $member_id, $invite_data );
		}

		return $groups;
	}

	/**
	 * @param int $member_id Member ID.
	 * @param int $page      Page.
	 * @return array{items:array,done:bool}
	 */
	private static function application_group( $member_id, $page ) {
		global $wpdb;
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.application_id, a.status, a.applied_at, a.notes, e.event_name, r.role_name FROM ' . self::table( 'remember_event_applications' ) . ' a LEFT JOIN ' . self::table( 'remember_events' ) . ' e ON e.event_id = a.event_id LEFT JOIN ' . self::table( 'remember_event_roles' ) . ' er ON er.event_role_id = a.event_role_id LEFT JOIN ' . self::table( 'remember_roles' ) . ' r ON r.role_id = er.role_id WHERE a.member_id = %d ORDER BY a.application_id ASC LIMIT %d OFFSET %d',
				$member_id,
				self::PAGE_SIZE,
				$offset
			)
		);
		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data = array();
				self::add_value( $data, __( 'Event', 'remember' ), $row->event_name );
				self::add_value( $data, __( 'Role', 'remember' ), $row->role_name );
				self::add_value( $data, __( 'Status', 'remember' ), $row->status );
				self::add_value( $data, __( 'Applied', 'remember' ), $row->applied_at );
				self::add_value( $data, __( 'Notes', 'remember' ), $row->notes );
				$items[] = self::group( 'remember-applications', __( 'reMember applications', 'remember' ), 'application-' . $row->application_id, $data );
			}
		}
		return array(
			'items' => $items,
			'done'  => ! is_array( $rows ) || count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * @param int $member_id Member ID.
	 * @param int $page      Page.
	 * @return array{items:array,done:bool}
	 */
	private static function payment_group( $member_id, $page ) {
		global $wpdb;
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT payment_id, total_amount, amount_paid, amount_due, payment_status, payment_date, payment_method, quickbooks_invoice_number, xero_invoice_number FROM ' . self::table( 'remember_payments' ) . ' WHERE member_id = %d ORDER BY payment_id ASC LIMIT %d OFFSET %d',
				$member_id,
				self::PAGE_SIZE,
				$offset
			)
		);
		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data = array();
				self::add_value( $data, __( 'Total', 'remember' ), $row->total_amount );
				self::add_value( $data, __( 'Paid', 'remember' ), $row->amount_paid );
				self::add_value( $data, __( 'Due', 'remember' ), $row->amount_due );
				self::add_value( $data, __( 'Status', 'remember' ), $row->payment_status );
				self::add_value( $data, __( 'Date', 'remember' ), $row->payment_date );
				self::add_value( $data, __( 'Method', 'remember' ), $row->payment_method );
				self::add_value( $data, __( 'QuickBooks invoice', 'remember' ), $row->quickbooks_invoice_number );
				self::add_value( $data, __( 'Xero invoice', 'remember' ), $row->xero_invoice_number );
				$items[] = self::group( 'remember-payments', __( 'reMember payments', 'remember' ), 'payment-' . $row->payment_id, $data );
			}
		}
		return array(
			'items' => $items,
			'done'  => ! is_array( $rows ) || count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	private static function erase_photo( $member_id ) {
		global $wpdb;
		$url = $wpdb->get_var( $wpdb->prepare( 'SELECT photo_url FROM ' . self::table( 'remember_members' ) . ' WHERE member_id = %d', $member_id ) );
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-image-uploader.php';
		Remember_Image_Uploader::delete_image( $url );
		$wpdb->update( self::table( 'remember_members' ), array( 'photo_url' => '' ), array( 'member_id' => $member_id ), array( '%s' ), array( '%d' ) );
		return true;
	}

	/**
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	private static function blank_profile( $member_id ) {
		global $wpdb;
		$result = $wpdb->update(
			self::table( 'remember_member_profiles' ),
			array(
				'legal_first_name'               => '',
				'legal_last_name'                => '',
				'address_street'                 => '',
				'address_city'                   => '',
				'address_state'                  => '',
				'address_postal'                 => '',
				'address_country'                => '',
				'cell_phone'                     => '',
				'im_handle'                      => '',
				'interests'                      => '',
				'allergy_reaction'               => '',
				'shirt_size'                     => '',
				'pants_size'                     => '',
				'shoe_size'                      => '',
				'emergency_contact_first'        => '',
				'emergency_contact_last'         => '',
				'emergency_contact_phone'        => '',
				'emergency_contact_relationship' => '',
				'updated_at'                     => current_time( 'mysql' ),
			),
			array( 'member_id' => $member_id )
		);
		$cleared_number = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'remember_member_profiles' ) . ' SET member_number = NULL WHERE member_id = %d AND member_number IS NOT NULL', $member_id ) );
		return ( false !== $result && $result > 0 ) || ( false !== $cleared_number && $cleared_number > 0 );
	}

	/**
	 * @param int      $member_id Member ID.
	 * @param string[] $suffixes  Table suffixes including the remember_ prefix.
	 * @return bool
	 */
	private static function delete_member_rows( $member_id, $suffixes ) {
		global $wpdb;
		$removed = false;
		foreach ( $suffixes as $suffix ) {
			$deleted = $wpdb->delete( self::table( $suffix ), array( 'member_id' => $member_id ), array( '%d' ) );
			if ( $deleted ) {
				$removed = true;
			}
		}
		return $removed;
	}

	/**
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	private static function delete_survey_answers( $member_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT response_id FROM ' . self::table( 'remember_survey_responses' ) . ' WHERE member_id = %d', $member_id ) );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return false;
		}
		foreach ( $ids as $response_id ) {
			$wpdb->delete( self::table( 'remember_survey_answers' ), array( 'response_id' => (int) $response_id ), array( '%d' ) );
		}
		$wpdb->delete( self::table( 'remember_survey_responses' ), array( 'member_id' => $member_id ), array( '%d' ) );
		return true;
	}

	/**
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	private static function blank_application_notes( $member_id ) {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'remember_event_applications' ) . ' SET notes = %s WHERE member_id = %d AND notes IS NOT NULL AND notes <> %s', '', $member_id, '' ) );
		return false !== $result && $result > 0;
	}

	/**
	 * @param int $member_id Member ID.
	 * @return array{removed:bool,retained:bool}
	 */
	private static function blank_agreement_identity( $member_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table( 'remember_agreement_acceptances' ) . ' acc INNER JOIN ' . self::table( 'remember_event_applications' ) . ' a ON a.application_id = acc.application_id WHERE a.member_id = %d',
				$member_id
			)
		);
		if ( $count < 1 ) {
			return array(
				'removed'  => false,
				'retained' => false,
			);
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'remember_agreement_acceptances' ) . ' acc INNER JOIN ' . self::table( 'remember_event_applications' ) . ' a ON a.application_id = acc.application_id SET acc.typed_legal_name = %s, acc.ip_address = NULL, acc.user_agent = NULL WHERE a.member_id = %d',
				'',
				$member_id
			)
		);
		return array(
			'removed'  => false !== $result && $result > 0,
			'retained' => true,
		);
	}

	/**
	 * @param int $member_id Member ID.
	 * @return array{removed:bool,retained:bool}
	 */
	private static function blank_payment_personal_fields( $member_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'remember_payments' ) . ' WHERE member_id = %d', $member_id ) );
		if ( $count < 1 ) {
			return array(
				'removed'  => false,
				'retained' => false,
			);
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'remember_payments' ) . ' SET notes = %s, xero_online_invoice_url = %s, quickbooks_payment_lines = NULL, quickbooks_refund_lines = NULL, xero_payment_lines = NULL, xero_refund_lines = NULL WHERE member_id = %d',
				'',
				'',
				$member_id
			)
		);
		return array(
			'removed'  => false !== $result && $result > 0,
			'retained' => true,
		);
	}

	/**
	 * @param int $member_id Member ID.
	 * @return array{removed:bool,retained:bool}
	 */
	private static function blank_duplicate_snapshots( $member_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'remember_profile_duplicate_hits' ) . ' WHERE member_a_id = %d OR member_b_id = %d', $member_id, $member_id ) );
		if ( $count < 1 ) {
			return array(
				'removed'  => false,
				'retained' => false,
			);
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'remember_profile_duplicate_hits' ) . ' SET undo_snapshot = NULL, match_reasons = NULL WHERE (member_a_id = %d OR member_b_id = %d) AND (undo_snapshot IS NOT NULL OR match_reasons IS NOT NULL)',
				$member_id,
				$member_id
			)
		);
		return array(
			'removed'  => false !== $result && $result > 0,
			'retained' => true,
		);
	}

	/**
	 * @param int    $member_id       Member ID.
	 * @param string $junction_suffix Junction table.
	 * @param string $catalog_suffix  Catalog table.
	 * @param string $id_column       Catalog id column.
	 * @param string $name_column     Catalog name column.
	 * @return string
	 */
	private static function name_list( $member_id, $junction_suffix, $catalog_suffix, $id_column, $name_column ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9_]+$/', $id_column . $name_column ) ) {
			return '';
		}
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT c.' . $name_column . ' FROM ' . self::table( $junction_suffix ) . ' j INNER JOIN ' . self::table( $catalog_suffix ) . ' c ON c.' . $id_column . ' = j.' . $id_column . ' WHERE j.member_id = %d ORDER BY c.' . $name_column . ' ASC',
				$member_id
			)
		);
		if ( ! is_array( $names ) ) {
			return '';
		}
		return implode( ', ', $names );
	}

	/**
	 * @param array  $data  Field list, appended in place.
	 * @param string $name  Label.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function add_value( &$data, $name, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return;
		}
		$data[] = array(
			'name'  => (string) $name,
			'value' => $value,
		);
	}

	/**
	 * @param string $group_id    Group id.
	 * @param string $group_label Group label.
	 * @param string $item_id     Item id.
	 * @param array  $data        Name/value pairs.
	 * @return array
	 */
	private static function group( $group_id, $group_label, $item_id, $data ) {
		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}

	/**
	 * @param string $suffix Table name without the WordPress prefix.
	 * @return string
	 */
	private static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}
}
