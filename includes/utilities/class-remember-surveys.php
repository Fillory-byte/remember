<?php
/**
 * Event surveys: one optional survey on the application, and follow-ups issued later.
 * Each question names the roles that answer it; a question with no roles is for every role.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Survey storage, application rendering, and follow-up issuance.
 */
class Remember_Surveys {

	const PLACEMENT_APPLICATION = 'application';
	const PLACEMENT_FOLLOWUP    = 'followup';

	/**
	 * Field types a question may use.
	 *
	 * @return string[]
	 */
	public static function field_types() {
		return array( 'text', 'textarea', 'select', 'multiselect', 'boolean' );
	}

	/**
	 * Role ids that answer one question. Empty means every role on the event.
	 *
	 * @param int $question_id Question.
	 * @return int[]
	 */
	public static function question_role_ids( $question_id ) {
		$map = self::question_roles_map( array( $question_id ) );
		return isset( $map[ absint( $question_id ) ] ) ? $map[ absint( $question_id ) ] : array();
	}

	/**
	 * Role ids for several questions at once.
	 *
	 * @param int[] $question_ids Questions.
	 * @return array<int,int[]> Question id => event role ids. Questions for every role are left out.
	 */
	public static function question_roles_map( $question_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $question_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT question_id, event_role_id FROM ' . self::question_roles_table() . " WHERE question_id IN ({$in}) ORDER BY event_role_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row->question_id ][] = (int) $row->event_role_id;
		}
		return $out;
	}

	/**
	 * Replace the roles that answer a question. An empty list means every role.
	 *
	 * @param int   $question_id Question.
	 * @param int[] $role_ids    Event role ids.
	 * @return void
	 */
	public static function set_question_roles( $question_id, $role_ids ) {
		global $wpdb;
		$question_id = absint( $question_id );
		$wpdb->delete( self::question_roles_table(), array( 'question_id' => $question_id ), array( '%d' ) );
		foreach ( array_unique( array_map( 'absint', (array) $role_ids ) ) as $role_id ) {
			if ( $role_id < 1 ) {
				continue;
			}
			$wpdb->insert(
				self::question_roles_table(),
				array(
					'question_id'   => $question_id,
					'event_role_id' => $role_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Questions asked of any of these roles, in sort order. Questions with no roles are asked of everyone.
	 *
	 * @param object[] $questions      Questions.
	 * @param int[]    $event_role_ids The member's roles on the event.
	 * @return object[]
	 */
	public static function questions_for_roles( $questions, $event_role_ids ) {
		$event_role_ids = array_map( 'absint', (array) $event_role_ids );
		$map            = self::question_roles_map( wp_list_pluck( $questions, 'question_id' ) );
		$out            = array();
		foreach ( $questions as $question ) {
			$qid = (int) $question->question_id;
			if ( empty( $map[ $qid ] ) || array_intersect( $map[ $qid ], $event_role_ids ) ) {
				$out[] = $question;
			}
		}
		return $out;
	}

	/**
	 * The event's survey on the application, if it has one.
	 *
	 * @param int $event_id Event.
	 * @return object|null
	 */
	public static function application_survey( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::surveys_table() . ' WHERE event_id = %d AND placement = %s ORDER BY survey_id ASC LIMIT 1',
				$event_id,
				self::PLACEMENT_APPLICATION
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Whether this event role belongs to the event. 0 is the every-role audience.
	 *
	 * @param int $event_id      Event.
	 * @param int $event_role_id Event role.
	 * @return bool
	 */
	public static function role_belongs_to_event( $event_id, $event_role_id ) {
		global $wpdb;
		$event_role_id = absint( $event_role_id );
		if ( $event_role_id < 1 ) {
			return true;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT event_role_id FROM {$wpdb->prefix}remember_event_roles WHERE event_role_id = %d AND event_id = %d",
				$event_role_id,
				absint( $event_id )
			)
		);
		return (bool) $found;
	}

	/**
	 * Roles on one event.
	 *
	 * @param int $event_id Event.
	 * @return object[]
	 */
	public static function roles_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT er.event_role_id, er.event_id, r.role_name
				FROM {$wpdb->prefix}remember_event_roles er
				INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = er.role_id
				WHERE er.event_id = %d
				ORDER BY r.role_name ASC, er.event_role_id ASC",
				$event_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Event id => survey id of the survey already on each event's application, for the survey editor.
	 *
	 * @return array<int,int>
	 */
	public static function application_survey_ids_by_event() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_id, MIN(survey_id) AS survey_id FROM ' . self::surveys_table() . ' WHERE placement = %s GROUP BY event_id',
				self::PLACEMENT_APPLICATION
			)
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row->event_id ] = (int) $row->survey_id;
		}
		return $out;
	}

	/**
	 * Roles the member was accepted for on an event.
	 *
	 * @param int $event_id  Event.
	 * @param int $member_id Member.
	 * @return int[]
	 */
	public static function accepted_role_ids( $event_id, $member_id ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT event_role_id FROM {$wpdb->prefix}remember_event_applications WHERE event_id = %d AND member_id = %d AND status = 'accepted'",
				absint( $event_id ),
				absint( $member_id )
			)
		);
		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Survey row.
	 *
	 * @param int $survey_id Survey.
	 * @return object|null
	 */
	public static function get( $survey_id ) {
		global $wpdb;
		$survey_id = absint( $survey_id );
		if ( $survey_id < 1 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::surveys_table() . ' WHERE survey_id = %d', $survey_id ) );
		return $row ? $row : null;
	}

	/**
	 * Follow-up surveys for an event.
	 *
	 * @param int $event_id Event.
	 * @return object[]
	 */
	public static function followups_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::surveys_table() . ' WHERE event_id = %d AND placement = %s ORDER BY survey_id ASC',
				$event_id,
				self::PLACEMENT_FOLLOWUP
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every survey, with its event name, question count, and the roles its questions are asked of, for the admin list.
	 *
	 * role_names is empty when at least one question is asked of every role.
	 *
	 * @param int $event_id Limit to one event, or 0 for all.
	 * @return object[]
	 */
	public static function list_for_admin( $event_id = 0 ) {
		global $wpdb;
		$surveys   = self::surveys_table();
		$questions = self::questions_table();
		$qroles    = self::question_roles_table();
		$events    = $wpdb->prefix . 'remember_events';
		$event_id  = absint( $event_id );
		$where     = $event_id > 0 ? $wpdb->prepare( 'WHERE s.event_id = %d', $event_id ) : '';
		$roles     = $wpdb->prefix . 'remember_roles';
		$eroles    = $wpdb->prefix . 'remember_event_roles';
		$rows      = $wpdb->get_results(
			"SELECT s.survey_id, s.event_id, s.title, s.placement, s.timing, s.status, e.event_name,
				(SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
					FROM {$questions} q
					INNER JOIN {$qroles} qr ON qr.question_id = q.question_id
					INNER JOIN {$eroles} er ON er.event_role_id = qr.event_role_id
					INNER JOIN {$roles} r ON r.role_id = er.role_id
					WHERE q.survey_id = s.survey_id) AS role_names,
				(SELECT COUNT(*) FROM {$questions} q WHERE q.survey_id = s.survey_id AND NOT EXISTS (SELECT 1 FROM {$qroles} qr WHERE qr.question_id = q.question_id)) AS every_role_questions,
				(SELECT COUNT(*) FROM {$questions} q WHERE q.survey_id = s.survey_id) AS question_count
			FROM {$surveys} s
			LEFT JOIN {$events} e ON e.event_id = s.event_id
			{$where}
			ORDER BY e.start_date DESC, e.event_name ASC, s.title ASC, s.survey_id ASC"
		);
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $row ) {
			if ( (int) $row->every_role_questions > 0 ) {
				$row->role_names = '';
			}
		}
		return $rows;
	}

	/**
	 * Questions in sort order.
	 *
	 * @param int $survey_id Survey.
	 * @return object[]
	 */
	public static function questions( $survey_id ) {
		global $wpdb;
		$survey_id = absint( $survey_id );
		if ( $survey_id < 1 ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::questions_table() . ' WHERE survey_id = %d ORDER BY sort_order ASC, question_id ASC',
				$survey_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * What the application form asks a member applying for one role.
	 *
	 * Questions for that role that the member already answered (for example when applying for a second role)
	 * are not asked again. Their stored answers still decide whether a "required when" question applies.
	 *
	 * @param int $event_id      Event.
	 * @param int $event_role_id Event role being applied for.
	 * @param int $member_id     Member, or 0 to ask every question for the role.
	 * @return array{survey:object|null,questions:object[],known:array<string,mixed>}
	 */
	public static function apply_questions( $event_id, $event_role_id, $member_id = 0 ) {
		$empty = array(
			'survey'    => null,
			'questions' => array(),
			'known'     => array(),
		);
		if ( absint( $event_role_id ) < 1 || ! self::role_belongs_to_event( $event_id, $event_role_id ) ) {
			return $empty;
		}
		$survey = self::application_survey( $event_id );
		if ( ! $survey ) {
			return $empty;
		}
		$all       = self::questions( $survey->survey_id );
		$questions = self::questions_for_roles( $all, array( $event_role_id ) );
		$known     = array();
		$existing  = absint( $member_id ) > 0 ? self::response_for_member( $survey->survey_id, $member_id ) : null;
		if ( $existing ) {
			$stored = self::answers_for_response( $existing->response_id );
			$ask    = array();
			foreach ( $questions as $question ) {
				$qid = (int) $question->question_id;
				if ( isset( $stored[ $qid ] ) && '' !== trim( $stored[ $qid ] ) ) {
					continue;
				}
				$ask[] = $question;
			}
			$questions = $ask;
			$asked     = array_flip( wp_list_pluck( $questions, 'question_id' ) );
			foreach ( $all as $question ) {
				$qid = (int) $question->question_id;
				if ( ! isset( $asked[ $qid ] ) && isset( $stored[ $qid ] ) && '' !== trim( $stored[ $qid ] ) ) {
					$value = $stored[ $qid ];
					$known[ self::question_field_key( $question ) ] = 'multiselect' === $question->field_type ? self::decode_list( $value ) : $value;
				}
			}
		}
		return array(
			'survey'    => $survey,
			'questions' => $questions,
			'known'     => $known,
		);
	}

	/**
	 * Markup for the apply form, or an empty string when there is nothing for this role to answer.
	 *
	 * @param int  $event_id      Event.
	 * @param int  $event_role_id Event role.
	 * @param bool $use_posted    Fill the fields from the posted answers. The caller must have checked the nonce.
	 * @param int  $member_id     Member applying, so questions they already answered are skipped.
	 * @return string
	 */
	public static function render_apply_html( $event_id, $event_role_id = 0, $use_posted = false, $member_id = 0 ) {
		$set = self::apply_questions( $event_id, $event_role_id, $member_id );
		if ( ! $set['survey'] || empty( $set['questions'] ) ) {
			return '';
		}
		return self::render_fields( $set['survey'], $set['questions'], false, null, $use_posted ? self::posted_answers_map( $set['questions'] ) : null, $set['known'] );
	}

	/**
	 * Error when the posted application survey is incomplete. Empty when there is nothing to answer.
	 *
	 * @param int $event_id      Event.
	 * @param int $event_role_id Event role.
	 * @param int $member_id     Member applying.
	 * @return string
	 */
	public static function validate_apply( $event_id, $event_role_id = 0, $member_id = 0 ) {
		$set = self::apply_questions( $event_id, $event_role_id, $member_id );
		if ( ! $set['survey'] || empty( $set['questions'] ) ) {
			return '';
		}
		return self::validate_posted( $set['questions'], $set['known'] );
	}

	/**
	 * Store the application survey once the application row exists. Earlier answers are kept.
	 *
	 * @param int $application_id Application.
	 * @param int $event_id       Event.
	 * @param int $member_id      Member.
	 * @param int $event_role_id  Event role.
	 * @return void
	 */
	public static function save_apply_response( $application_id, $event_id, $member_id, $event_role_id = 0 ) {
		$set = self::apply_questions( $event_id, $event_role_id, $member_id );
		if ( ! $set['survey'] || empty( $set['questions'] ) ) {
			return;
		}
		self::save_response( $set['survey'], $member_id, $application_id, $set['questions'], $set['known'] );
	}

	/**
	 * Whether this member was accepted to the event.
	 *
	 * @param int $event_id      Event.
	 * @param int $member_id     Member.
	 * @param int $event_role_id Event role, or 0 for any accepted role.
	 * @return bool
	 */
	public static function member_is_accepted( $event_id, $member_id, $event_role_id = 0 ) {
		global $wpdb;
		$event_role_id = absint( $event_role_id );
		if ( $event_role_id > 0 ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT application_id FROM {$wpdb->prefix}remember_event_applications WHERE event_id = %d AND member_id = %d AND event_role_id = %d AND status = 'accepted' LIMIT 1",
					absint( $event_id ),
					absint( $member_id ),
					$event_role_id
				)
			);
		} else {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT application_id FROM {$wpdb->prefix}remember_event_applications WHERE event_id = %d AND member_id = %d AND status = 'accepted' LIMIT 1",
					absint( $event_id ),
					absint( $member_id )
				)
			);
		}
		return (bool) $found;
	}

	/**
	 * Follow-up questions for a member: those asked of any role they were accepted for.
	 *
	 * @param object $survey    Survey.
	 * @param int    $member_id Member.
	 * @return object[] Empty when the member was not accepted or no question is for their roles.
	 */
	public static function followup_questions_for_member( $survey, $member_id ) {
		$role_ids = self::accepted_role_ids( $survey->event_id, $member_id );
		if ( empty( $role_ids ) ) {
			return array();
		}
		return self::questions_for_roles( self::questions( $survey->survey_id ), $role_ids );
	}

	/**
	 * Whether this member may answer the survey: accepted, with at least one question for their roles.
	 *
	 * @param object $survey    Survey.
	 * @param int    $member_id Member.
	 * @return bool
	 */
	public static function member_can_answer( $survey, $member_id ) {
		return ! empty( self::followup_questions_for_member( $survey, $member_id ) );
	}

	/**
	 * Follow-up response page for a logged-in accepted member.
	 *
	 * @param int $survey_id Survey.
	 * @param int $member_id Member.
	 * @return string
	 */
	public static function render_followup_page( $survey_id, $member_id ) {
		$survey = self::get( $survey_id );
		if ( ! $survey || self::PLACEMENT_FOLLOWUP !== $survey->placement || 'issued' !== $survey->status ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'That survey is not open.', 'remember' ) . '</p>';
		}
		if ( ! self::member_is_accepted( $survey->event_id, $member_id ) ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'This survey is for accepted participants of the event.', 'remember' ) . '</p>';
		}
		$questions = self::followup_questions_for_member( $survey, $member_id );
		if ( empty( $questions ) ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'This survey has no questions for your role.', 'remember' ) . '</p>';
		}

		$notice = '';
		$sticky = null;
		if ( isset( $_POST['remember_survey_response'] ) && check_admin_referer( 'remember_survey_response_' . $survey->survey_id, 'remember_survey_response_nonce' ) ) {
			$error = self::validate_posted( $questions );
			if ( $error ) {
				$notice = '<p class="remember-notice remember-error">' . esc_html( $error ) . '</p>';
				// Keep what the member typed so a missed required question does not wipe the form.
				$sticky = self::posted_answers_map( $questions );
			} else {
				self::save_response( $survey, $member_id, 0, $questions );
				$notice = '<p class="remember-notice remember-success">' . esc_html__( 'Your response was saved.', 'remember' ) . '</p>';
			}
		}

		$existing = self::response_for_member( $survey->survey_id, $member_id );
		$html     = $notice;
		$html    .= '<form class="remember-form" method="post">';
		$html    .= wp_nonce_field( 'remember_survey_response_' . $survey->survey_id, 'remember_survey_response_nonce', true, false );
		$html    .= '<input type="hidden" name="remember_survey_response" value="1">';
		$html    .= self::render_fields( $survey, $questions, true, $existing, $sticky );
		$html    .= '<p><button type="submit" class="remember-button remember-button-primary">' . esc_html__( 'Submit survey', 'remember' ) . '</button></p>';
		$html    .= '</form>';
		return $html;
	}

	/**
	 * Save the survey posted from the admin editor.
	 *
	 * @param int $event_id Event.
	 * @return int|WP_Error Survey id.
	 */
	public static function save_from_admin( $event_id ) {
		$event_id  = absint( $event_id );
		$placement = isset( $_POST['survey_placement'] ) ? sanitize_key( wp_unslash( $_POST['survey_placement'] ) ) : self::PLACEMENT_FOLLOWUP;
		if ( ! in_array( $placement, array( self::PLACEMENT_APPLICATION, self::PLACEMENT_FOLLOWUP ), true ) ) {
			$placement = self::PLACEMENT_FOLLOWUP;
		}
		$survey_id = isset( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : 0;
		$title     = isset( $_POST['survey_title'] ) ? sanitize_text_field( wp_unslash( $_POST['survey_title'] ) ) : '';
		$timing    = isset( $_POST['survey_timing'] ) ? sanitize_key( wp_unslash( $_POST['survey_timing'] ) ) : '';
		if ( ! in_array( $timing, array( 'before', 'after' ), true ) ) {
			$timing = '';
		}
		if ( self::PLACEMENT_APPLICATION === $placement ) {
			$timing   = '';
			$existing = self::application_survey( $event_id );
			if ( $existing && (int) $existing->survey_id !== $survey_id ) {
				return new WP_Error( 'remember_survey_application', self::application_taken_message() );
			}
			if ( '' === $title ) {
				$title = __( 'Application survey', 'remember' );
			}
		}
		$rows = self::posted_question_rows();
		if ( empty( $rows ) ) {
			if ( self::PLACEMENT_APPLICATION === $placement ) {
				$existing = self::application_survey( $event_id );
				if ( ! $existing ) {
					return 0;
				}
				$removed = self::delete_survey( (int) $existing->survey_id );
				if ( is_wp_error( $removed ) ) {
					return $removed;
				}
				require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
				Remember_Logger::info( 'Application survey removed', array( 'event_id' => $event_id, 'survey_id' => (int) $existing->survey_id ) );
				return 0;
			}
			return new WP_Error( 'remember_survey_empty', __( 'Add at least one question.', 'remember' ) );
		}
		if ( '' === $title ) {
			return new WP_Error( 'remember_survey_title', __( 'A survey needs a title.', 'remember' ) );
		}

		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'event_id'   => $event_id,
			'title'      => $title,
			'placement'  => $placement,
			'timing'     => self::PLACEMENT_FOLLOWUP === $placement ? ( $timing ? $timing : 'after' ) : '',
			'updated_at' => $now,
		);
		if ( $survey_id > 0 ) {
			$current = self::get( $survey_id );
			if ( ! $current || (int) $current->event_id !== $event_id ) {
				return new WP_Error( 'remember_survey_missing', __( 'Survey not found for this event.', 'remember' ) );
			}
			if ( 'issued' === $current->status && self::response_count( $survey_id ) > 0 ) {
				return new WP_Error( 'remember_survey_locked', __( 'This survey already has responses, so its questions stay as they were issued.', 'remember' ) );
			}
			$wpdb->update( self::surveys_table(), $data, array( 'survey_id' => $survey_id ) );
		} else {
			$data['status']     = self::PLACEMENT_APPLICATION === $placement ? 'open' : 'draft';
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $now;
			$wpdb->insert( self::surveys_table(), $data );
			$survey_id = (int) $wpdb->insert_id;
		}
		if ( $survey_id < 1 ) {
			return new WP_Error( 'remember_survey_save', __( 'The survey could not be saved.', 'remember' ) );
		}
		self::sync_questions( $survey_id, $rows );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::info(
			'Survey saved',
			array(
				'survey_id'  => $survey_id,
				'event_id'   => $event_id,
				'placement'  => $placement,
			)
		);
		Remember_Logger::debug(
			'Survey saved',
			array(
				'survey_id'      => $survey_id,
				'event_id'       => $event_id,
				'question_count' => count( $rows ),
			)
		);
		return $survey_id;
	}

	/**
	 * Email accepted participants and open a follow-up survey.
	 *
	 * @param int $survey_id Survey.
	 * @return true|WP_Error
	 */
	public static function issue( $survey_id ) {
		$survey = self::get( $survey_id );
		if ( ! $survey || self::PLACEMENT_FOLLOWUP !== $survey->placement ) {
			return new WP_Error( 'remember_survey_issue', __( 'Only a follow-on survey can be issued.', 'remember' ) );
		}
		$questions = self::questions( $survey_id );
		if ( empty( $questions ) ) {
			return new WP_Error( 'remember_survey_empty', __( 'Add at least one question before issuing the survey.', 'remember' ) );
		}
		global $wpdb;
		$role_ids = array();
		$roles    = self::question_roles_map( wp_list_pluck( $questions, 'question_id' ) );
		foreach ( $questions as $question ) {
			$qid = (int) $question->question_id;
			if ( empty( $roles[ $qid ] ) ) {
				$role_ids = array();
				break;
			}
			$role_ids = array_merge( $role_ids, $roles[ $qid ] );
		}
		$role_ids = array_values( array_unique( $role_ids ) );
		sort( $role_ids );
		if ( $role_ids ) {
			$in         = implode( ',', array_map( 'absint', $role_ids ) );
			$member_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_event_applications WHERE event_id = %d AND status = 'accepted' AND event_role_id IN ({$in})",
					(int) $survey->event_id
				)
			);
		} else {
			$member_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_event_applications WHERE event_id = %d AND status = 'accepted'",
					(int) $survey->event_id
				)
			);
		}
		if ( ! is_array( $member_ids ) ) {
			$member_ids = array();
		}
		$wpdb->update(
			self::surveys_table(),
			array(
				'status'     => 'issued',
				'issued_at'  => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'survey_id' => (int) $survey->survey_id )
		);
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-page-creator.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-notifications.php';
		Remember_Page_Creator::create_pages( array( 'survey' ) );
		if ( empty( $member_ids ) ) {
			Remember_Logger::warning( 'Survey issued with no accepted participants', array( 'survey_id' => (int) $survey->survey_id, 'event_id' => (int) $survey->event_id, 'event_role_ids' => $role_ids ) );
			return true;
		}
		$event_name = $wpdb->get_var( $wpdb->prepare( "SELECT event_name FROM {$wpdb->prefix}remember_events WHERE event_id = %d", (int) $survey->event_id ) );
		$url        = self::response_url( $survey->survey_id );
		$sent       = 0;
		foreach ( $member_ids as $member_id ) {
			$user = get_user_by( 'ID', absint( $member_id ) );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				continue;
			}
			$result = Remember_Notifications::send(
				'survey_issued',
				array(
					'member_name'  => $user->display_name,
					'event_name'   => (string) $event_name,
					'survey_title' => (string) $survey->title,
					'survey_url'   => $url,
				),
				$user->user_email
			);
			if ( true === $result ) {
				++$sent;
			} elseif ( is_wp_error( $result ) ) {
				Remember_Logger::warning(
					'Survey email failed',
					array(
						'survey_id' => (int) $survey->survey_id,
						'user_id'   => (int) $user->ID,
						'error'     => $result->get_error_message(),
					)
				);
			}
		}
		Remember_Logger::info(
			'Survey issued',
			array(
				'survey_id'      => (int) $survey->survey_id,
				'event_id'       => (int) $survey->event_id,
				'event_role_ids' => $role_ids,
				'recipients'     => $sent,
			)
		);
		return true;
	}

	/**
	 * Public URL for a follow-up survey.
	 *
	 * @param int $survey_id Survey.
	 * @return string
	 */
	public static function response_url( $survey_id ) {
		$pages = get_option( 'remember_created_pages', array() );
		$page_id = isset( $pages['survey'] ) ? absint( $pages['survey'] ) : 0;
		$url     = $page_id > 0 ? get_permalink( $page_id ) : '';
		if ( ! $url ) {
			$page = get_page_by_path( 'survey' );
			$url  = $page ? get_permalink( $page ) : home_url( '/survey/' );
		}
		return add_query_arg( 'survey_id', absint( $survey_id ), $url );
	}

	/**
	 * Delete a survey that has no responses.
	 *
	 * @param int $survey_id Survey.
	 * @return true|WP_Error
	 */
	public static function delete_survey( $survey_id ) {
		$survey_id = absint( $survey_id );
		if ( self::response_count( $survey_id ) > 0 ) {
			return new WP_Error( 'remember_survey_locked', __( 'This survey has responses and cannot be deleted.', 'remember' ) );
		}
		global $wpdb;
		$question_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT question_id FROM ' . self::questions_table() . ' WHERE survey_id = %d', $survey_id ) );
		if ( $question_ids ) {
			$in = implode( ',', array_map( 'absint', $question_ids ) );
			$wpdb->query( "DELETE FROM " . self::answers_table() . " WHERE question_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . self::question_roles_table() . " WHERE question_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		}
		$wpdb->delete( self::questions_table(), array( 'survey_id' => $survey_id ), array( '%d' ) );
		$wpdb->delete( self::surveys_table(), array( 'survey_id' => $survey_id ), array( '%d' ) );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::info( 'Survey deleted', array( 'survey_id' => $survey_id ) );
		return true;
	}

	/**
	 * Copy a survey and its questions to an event. Responses are never copied.
	 *
	 * Each question's roles move to the same roles on the target event; roles the target does not
	 * have are left off, and a question left with none is asked of every role. An application survey
	 * stays on the application when the target has none there, and otherwise becomes a follow-up
	 * draft so it never displaces the target's survey.
	 *
	 * @param int $survey_id       Survey to copy.
	 * @param int $target_event_id Event to copy into (may be the same event).
	 * @return array{survey_id:int,notes:string[]}|WP_Error
	 */
	public static function copy_survey( $survey_id, $target_event_id ) {
		global $wpdb;
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';

		$source          = self::get( $survey_id );
		$target_event_id = absint( $target_event_id );
		if ( ! $source ) {
			return new WP_Error( 'remember_survey_missing', __( 'Survey not found.', 'remember' ) );
		}
		$target_name = $wpdb->get_var( $wpdb->prepare( "SELECT event_name FROM {$wpdb->prefix}remember_events WHERE event_id = %d", $target_event_id ) );
		if ( null === $target_name ) {
			return new WP_Error( 'remember_survey_event', __( 'Choose the event to copy the survey to.', 'remember' ) );
		}
		$questions = self::questions( $source->survey_id );
		if ( empty( $questions ) ) {
			return new WP_Error( 'remember_survey_empty', __( 'This survey has no questions to copy.', 'remember' ) );
		}

		$notes        = array();
		$source_roles = self::question_roles_map( wp_list_pluck( $questions, 'question_id' ) );
		$role_map     = self::map_roles_to_event( array_unique( array_merge( array(), ...array_values( $source_roles ) ) ), $target_event_id );
		$dropped      = array_keys(
			array_filter(
				$role_map,
				static function ( $mapped ) {
					return 0 === $mapped;
				}
			)
		);
		$target_roles = array();
		$widened      = array();
		foreach ( $questions as $question ) {
			$qid    = (int) $question->question_id;
			$mapped = array();
			foreach ( isset( $source_roles[ $qid ] ) ? $source_roles[ $qid ] : array() as $role_id ) {
				if ( ! empty( $role_map[ $role_id ] ) ) {
					$mapped[] = $role_map[ $role_id ];
				}
			}
			$target_roles[ $qid ] = array_values( array_unique( $mapped ) );
			if ( ! empty( $source_roles[ $qid ] ) && empty( $mapped ) ) {
				$widened[] = (string) $question->label;
			}
		}
		if ( $dropped ) {
			$notes[] = sprintf(
				/* translators: 1: comma-separated role names, 2: event name */
				__( 'These roles are not on %2$s, so the copied questions leave them out: %1$s.', 'remember' ),
				implode( ', ', self::role_names( $dropped ) ),
				$target_name
			);
		}
		if ( $widened ) {
			$notes[] = sprintf(
				/* translators: %s: question labels separated by semicolons */
				__( 'None of the roles for these questions are on that event, so the copy asks them of every role. Choose their roles before using the survey: %s', 'remember' ),
				implode( '; ', $widened )
			);
		}

		$placement = (string) $source->placement;
		if ( self::PLACEMENT_APPLICATION === $placement ) {
			if ( self::application_survey( $target_event_id ) ) {
				$placement = self::PLACEMENT_FOLLOWUP;
				$notes[]   = sprintf(
					/* translators: %s: event name */
					__( '%s already has a survey on the application, so the copy was saved as a follow-up draft and the existing survey was left in place.', 'remember' ),
					$target_name
				);
			} else {
				$notes[] = sprintf(
					/* translators: %s: event name */
					__( 'The copy is now on the application form for %s.', 'remember' ),
					$target_name
				);
			}
		}

		$title = (string) $source->title;
		if ( (int) $source->event_id === $target_event_id ) {
			/* translators: %s: survey title */
			$title = sprintf( __( '%s (copy)', 'remember' ), $title );
		}
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			self::surveys_table(),
			array(
				'event_id'     => $target_event_id,
				'title'        => mb_substr( $title, 0, 255 ),
				'instructions' => isset( $source->instructions ) ? $source->instructions : null,
				'placement'    => $placement,
				'timing'       => self::PLACEMENT_FOLLOWUP === $placement ? ( 'before' === $source->timing ? 'before' : 'after' ) : '',
				'status'       => self::PLACEMENT_APPLICATION === $placement ? 'open' : 'draft',
				'created_by'   => get_current_user_id(),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$copy_id = $ok ? (int) $wpdb->insert_id : 0;
		if ( $copy_id < 1 ) {
			Remember_Logger::error( 'Survey copy could not be created', array( 'source_survey_id' => (int) $source->survey_id, 'event_id' => $target_event_id, 'db_error' => $wpdb->last_error ) );
			return new WP_Error( 'remember_survey_save', __( 'The survey could not be copied.', 'remember' ) );
		}

		$id_map = array();
		foreach ( $questions as $question ) {
			$row = array(
				'survey_id'           => $copy_id,
				'sort_order'          => (int) $question->sort_order,
				'label'               => $question->label,
				'field_key'           => $question->field_key,
				'field_type'          => $question->field_type,
				'options_text'        => $question->options_text,
				'is_required'         => (int) $question->is_required,
				'required_when_json'  => $question->required_when_json,
				'show_if_question_id' => 0,
				'show_if_value'       => (string) $question->show_if_value,
			);
			if ( ! $wpdb->insert( self::questions_table(), $row ) ) {
				Remember_Logger::error( 'Survey copy failed while copying questions', array( 'source_survey_id' => (int) $source->survey_id, 'survey_id' => $copy_id, 'db_error' => $wpdb->last_error ) );
				self::delete_survey( $copy_id );
				return new WP_Error( 'remember_survey_save', __( 'The survey could not be copied.', 'remember' ) );
			}
			$id_map[ (int) $question->question_id ] = (int) $wpdb->insert_id;
			self::set_question_roles( (int) $wpdb->insert_id, $target_roles[ (int) $question->question_id ] );
		}
		foreach ( $questions as $question ) {
			$parent = (int) $question->show_if_question_id;
			if ( $parent > 0 && isset( $id_map[ $parent ] ) ) {
				$wpdb->update(
					self::questions_table(),
					array( 'show_if_question_id' => $id_map[ $parent ] ),
					array( 'question_id' => $id_map[ (int) $question->question_id ] ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}

		Remember_Logger::info(
			'Survey copied',
			array(
				'source_survey_id'       => (int) $source->survey_id,
				'survey_id'              => $copy_id,
				'source_event_id'        => (int) $source->event_id,
				'event_id'               => $target_event_id,
				'placement'              => $placement,
				'dropped_event_role_ids' => $dropped,
				'widened_questions'      => count( $widened ),
				'question_count'         => count( $id_map ),
			)
		);

		return array(
			'survey_id' => $copy_id,
			'notes'     => $notes,
		);
	}

	/**
	 * Map event roles to the event role with the same underlying role on another event.
	 *
	 * @param int[] $event_role_ids  Source event role ids.
	 * @param int   $target_event_id Event to map onto.
	 * @return array<int,int> Source event role id => target event role id, or 0 when the target lacks that role.
	 */
	private static function map_roles_to_event( $event_role_ids, $target_event_id ) {
		global $wpdb;
		$out = array();
		foreach ( $event_role_ids as $event_role_id ) {
			$event_role_id = absint( $event_role_id );
			$mapped        = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT target.event_role_id
					FROM {$wpdb->prefix}remember_event_roles source
					INNER JOIN {$wpdb->prefix}remember_event_roles target ON target.role_id = source.role_id AND target.event_id = %d
					WHERE source.event_role_id = %d
					ORDER BY target.event_role_id ASC
					LIMIT 1",
					absint( $target_event_id ),
					$event_role_id
				)
			);
			$out[ $event_role_id ] = absint( $mapped );
		}
		return $out;
	}

	/**
	 * Role names for event role ids.
	 *
	 * @param int[] $event_role_ids Event role ids.
	 * @return string[]
	 */
	private static function role_names( $event_role_ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $event_role_ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$in    = implode( ',', $ids );
		$names = $wpdb->get_col(
			"SELECT r.role_name FROM {$wpdb->prefix}remember_event_roles er INNER JOIN {$wpdb->prefix}remember_roles r ON r.role_id = er.role_id WHERE er.event_role_id IN ({$in}) ORDER BY r.role_name ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		);
		return is_array( $names ) ? $names : array();
	}

	/**
	 * One question row.
	 *
	 * @param int $question_id Question.
	 * @return object|null
	 */
	public static function get_question( $question_id ) {
		global $wpdb;
		$question_id = absint( $question_id );
		if ( $question_id < 1 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::questions_table() . ' WHERE question_id = %d', $question_id ) );
		return $row ? $row : null;
	}

	/**
	 * Create or update one question, and the survey title it belongs to.
	 *
	 * @param int $event_id Event.
	 * @return int|WP_Error Question id.
	 */
	public static function save_question_from_admin( $event_id ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';

		$event_id = absint( $event_id );
		$survey   = self::existing_survey_for_question( $event_id );
		if ( is_wp_error( $survey ) ) {
			return $survey;
		}

		$question_id = isset( $_POST['question_id'] ) ? absint( $_POST['question_id'] ) : 0;
		$current     = $question_id > 0 ? self::get_question( $question_id ) : null;
		if ( $question_id > 0 && ( ! $current || (int) $current->survey_id !== (int) $survey->survey_id ) ) {
			return new WP_Error( 'remember_survey_question', __( 'That question is not on this survey.', 'remember' ) );
		}

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$type  = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : 'text';
		if ( ! in_array( $type, self::field_types(), true ) ) {
			$type = 'text';
		}
		$field_key = isset( $_POST['field_key'] ) ? Remember_Profile_Questions::sanitize_field_key( wp_unslash( $_POST['field_key'] ) ) : '';
		if ( '' === $field_key && '' !== $label ) {
			$field_key = Remember_Profile_Questions::suggest_field_key_from_label( $label );
		}
		$req_mode = isset( $_POST['required_mode'] ) ? sanitize_key( wp_unslash( $_POST['required_mode'] ) ) : 'optional';
		if ( ! in_array( $req_mode, array( 'optional', 'always', 'when' ), true ) ) {
			$req_mode = 'optional';
		}
		$sort_order = isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0;

		$option_pairs = array();
		if ( self::type_uses_options( $type ) && isset( $_POST['option_label'] ) && is_array( $_POST['option_label'] ) ) {
			$labels_in = wp_unslash( $_POST['option_label'] );
			$keys_in   = isset( $_POST['option_key'] ) && is_array( $_POST['option_key'] ) ? wp_unslash( $_POST['option_key'] ) : array();
			foreach ( $labels_in as $i => $opt_label ) {
				$opt_label = sanitize_text_field( (string) $opt_label );
				$opt_key   = isset( $keys_in[ $i ] ) ? Remember_Profile_Questions::sanitize_field_key( (string) $keys_in[ $i ] ) : '';
				if ( '' === $opt_label && '' === $opt_key ) {
					continue;
				}
				if ( '' === $opt_key ) {
					$opt_key = Remember_Profile_Questions::sanitize_field_key( $opt_label );
				}
				if ( '' === $opt_key ) {
					continue;
				}
				if ( '' === $opt_label ) {
					$opt_label = $opt_key;
				}
				$option_pairs[] = array(
					'key'   => $opt_key,
					'label' => $opt_label,
				);
			}
		}

		$required_when_json = null;
		if ( 'when' === $req_mode ) {
			$when_key = isset( $_POST['required_when_field'] ) ? Remember_Profile_Questions::sanitize_field_key( wp_unslash( $_POST['required_when_field'] ) ) : '';
			$when_raw = isset( $_POST['required_when_values'] ) && is_array( $_POST['required_when_values'] ) ? wp_unslash( $_POST['required_when_values'] ) : array();
			$when_vals = array();
			foreach ( $when_raw as $value ) {
				$when_vals[] = Remember_Profile_Questions::sanitize_field_key( (string) $value );
			}
			$required_when_json = Remember_Profile_Questions::encode_required_when( $when_key, $when_vals );
		}

		$error = '';
		if ( '' === $label ) {
			$error = __( 'Please enter the question members will see.', 'remember' );
		} elseif ( '' === $field_key ) {
			$error = __( 'Please enter a short name (for example ice_cream_flavor).', 'remember' );
		} elseif ( self::field_key_exists( $survey->survey_id, $field_key, $question_id ) ) {
			$error = __( 'That short name is already used by another question on this survey.', 'remember' );
		} elseif ( self::type_uses_options( $type ) && empty( $option_pairs ) ) {
			$error = __( 'Add at least one choice with a label and a key (for example Vanilla / vanilla).', 'remember' );
		} elseif ( 'when' === $req_mode && null === $required_when_json ) {
			$error = __( 'Choose an earlier pick-one or pick-several question and at least one choice that makes this question required.', 'remember' );
		}

		$role_ids = self::posted_role_ids();
		if ( '' === $error ) {
			foreach ( $role_ids as $role_id ) {
				if ( ! self::role_belongs_to_event( $survey->event_id, $role_id ) ) {
					$error = __( 'Choose roles on this event.', 'remember' );
					break;
				}
			}
		}

		if ( '' === $error && 'when' === $req_mode ) {
			$rule = Remember_Profile_Questions::parse_required_when( $required_when_json );
			$gate = $rule ? self::question_by_field_key( $survey->survey_id, $rule['field_key'] ) : null;
			if ( ! $gate || (int) $gate->question_id === $question_id ) {
				$error = __( 'The “required when” question must be a different question on this survey.', 'remember' );
			} elseif ( ! self::type_can_gate( $gate->field_type ) ) {
				$error = __( 'The “required when” question must be a pick-one, pick-several, or yes/no question.', 'remember' );
			} elseif ( ! self::roles_cover( self::question_role_ids( $gate->question_id ), $role_ids ) ) {
				$error = __( 'The “required when” question is not asked of every role this one is. Tick the same roles on both, or fewer on this one.', 'remember' );
			} else {
				$allowed = wp_list_pluck( self::option_pairs( $gate ), 'key' );
				foreach ( $rule['values'] as $value ) {
					if ( ! in_array( $value, $allowed, true ) ) {
						$error = __( 'One of the “required when” choices is not valid for that question.', 'remember' );
						break;
					}
				}
			}
		}

		if ( '' === $error && $current ) {
			foreach ( self::questions( $survey->survey_id ) as $dependent ) {
				$rule = self::when_rule( $dependent );
				if ( $rule && $rule['field_key'] === (string) $current->field_key && (int) $dependent->question_id !== $question_id
					&& ! self::roles_cover( $role_ids, self::question_role_ids( $dependent->question_id ) ) ) {
					$error = sprintf(
						/* translators: %s: label of the question that depends on this one */
						__( '“%s” is required depending on this question, and is asked of roles this one would not be. Tick the same roles on both, or fewer on that one.', 'remember' ),
						$dependent->label
					);
					break;
				}
			}
		}

		if ( '' !== $error ) {
			return new WP_Error( 'remember_survey_question', $error );
		}

		global $wpdb;
		$data = array(
			'survey_id'           => (int) $survey->survey_id,
			'sort_order'          => $sort_order,
			'label'               => $label,
			'field_key'           => $field_key,
			'field_type'          => $type,
			'options_text'        => self::type_uses_options( $type ) ? Remember_Profile_Questions::encode_options( $option_pairs ) : '',
			'is_required'         => ( 'always' === $req_mode ) ? 1 : 0,
			'required_when_json'  => $required_when_json,
			'show_if_question_id' => 0,
			'show_if_value'       => '',
		);
		if ( $question_id > 0 ) {
			$wpdb->update( self::questions_table(), $data, array( 'question_id' => $question_id ) );
		} else {
			$wpdb->insert( self::questions_table(), $data );
			$question_id = (int) $wpdb->insert_id;
		}
		if ( $question_id < 1 ) {
			return new WP_Error( 'remember_survey_question', __( 'The question could not be saved.', 'remember' ) );
		}
		self::set_question_roles( $question_id, $role_ids );
		Remember_Logger::info(
			'Survey question saved',
			array(
				'survey_id'      => (int) $survey->survey_id,
				'question_id'    => $question_id,
				'event_id'       => $event_id,
				'event_role_ids' => $role_ids,
			)
		);
		Remember_Logger::debug(
			'Survey question saved',
			array(
				'survey_id'   => (int) $survey->survey_id,
				'question_id' => $question_id,
				'field_key'   => $field_key,
				'field_type'  => $type,
			)
		);
		return $question_id;
	}

	/**
	 * Delete one question and its answers.
	 *
	 * @param int $question_id Question.
	 * @return true|WP_Error
	 */
	public static function delete_question( $question_id ) {
		$question = self::get_question( $question_id );
		if ( ! $question ) {
			return new WP_Error( 'remember_survey_question', __( 'Question not found.', 'remember' ) );
		}
		global $wpdb;
		$wpdb->delete( self::answers_table(), array( 'question_id' => (int) $question->question_id ), array( '%d' ) );
		$wpdb->delete( self::question_roles_table(), array( 'question_id' => (int) $question->question_id ), array( '%d' ) );
		$wpdb->delete( self::questions_table(), array( 'question_id' => (int) $question->question_id ), array( '%d' ) );
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::info(
			'Survey question deleted',
			array(
				'survey_id'   => (int) $question->survey_id,
				'question_id' => (int) $question->question_id,
			)
		);
		return true;
	}

	/**
	 * @param object   $survey    Survey row.
	 * @param object[] $questions Questions.
	 * @param bool     $standalone Kept for callers. The block is the same on the apply form and the follow-up page.
	 * @param object|null $existing Response row.
	 * @param array|null  $answers_override Question id => value, used instead of stored answers.
	 * @param array       $known    Field key => earlier answer for questions not shown again.
	 * @return string
	 */
	private static function render_fields( $survey, $questions, $standalone, $existing = null, $answers_override = null, $known = array() ) {
		// $answers_override (qid => stored-value map, from posted_answers_map()) repopulates
		// the form after a failed submit so the member's answers are not discarded.
		$answers = is_array( $answers_override )
			? $answers_override
			: ( $existing ? self::answers_for_response( $existing->response_id ) : array() );
		$shown   = array();
		foreach ( $questions as $question ) {
			$shown[ self::question_field_key( $question ) ] = true;
		}
		$html         = '<div class="remember-survey" data-remember-survey="1">';
		$html        .= '<div class="remember-form-label">' . esc_html( $survey->title ) . '</div>';
		$instructions = isset( $survey->instructions ) ? trim( (string) $survey->instructions ) : '';
		if ( '' !== $instructions ) {
			$html .= '<p class="remember-form-help">' . nl2br( esc_html( $instructions ) ) . '</p>';
		}
		foreach ( $questions as $question ) {
			$qid      = (int) $question->question_id;
			$key      = self::question_field_key( $question );
			$rule     = self::when_rule( $question );
			$required = ! $rule && ! empty( $question->is_required );
			if ( $rule && ! isset( $shown[ $rule['field_key'] ] ) ) {
				// The deciding question is not on this form. An earlier answer settles it; otherwise this one never applies.
				if ( ! isset( $known[ $rule['field_key'] ] ) || ! self::posted_matches_values( $known[ $rule['field_key'] ], $rule['values'] ) ) {
					continue;
				}
				$rule     = null;
				$required = true;
			}
			$html    .= '<div class="remember-form-group remember-survey-question" data-remember-survey-question="1" data-question-id="' . esc_attr( (string) $qid ) . '" data-field-type="' . esc_attr( $question->field_type ) . '" data-remember-pq-key="' . esc_attr( $key ) . '"';
			if ( $rule ) {
				$html .= ' data-remember-pq-when="' . esc_attr( wp_json_encode( $rule ) ) . '" hidden';
			}
			$html .= '>';
			$html .= '<label class="remember-form-label" for="remember_survey_' . esc_attr( (string) $qid ) . '">' . esc_html( $question->label );
			$html .= ' <span class="remember-required"' . ( $required ? '' : ' hidden' ) . '>*</span></label>';
			$html .= self::render_input( $question, isset( $answers[ $qid ] ) ? $answers[ $qid ] : '', $required );
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * @param object $question Question.
	 * @param string $value    Stored value.
	 * @return string
	 */
	private static function render_input( $question, $value, $required = false ) {
		$qid   = (int) $question->question_id;
		$name  = 'remember_survey[' . $qid . ']';
		$id    = 'remember_survey_' . $qid;
		$type    = (string) $question->field_type;
		$options = self::option_pairs( $question );
		// Only non-conditional required questions get a static HTML5 guard; conditional
		// ones stay server-validated (and the posted values are kept on failure).
		$req_attr = $required ? ' required' : '';
		if ( 'textarea' === $type ) {
			return '<textarea class="remember-form-control" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" rows="4"' . $req_attr . '>' . esc_textarea( $value ) . '</textarea>';
		}
		if ( 'select' === $type ) {
			$html = '<select class="remember-form-control" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"' . $req_attr . '><option value="">' . esc_html__( '— Select —', 'remember' ) . '</option>';
			foreach ( $options as $option ) {
				$html .= '<option value="' . esc_attr( $option['key'] ) . '"' . selected( $value, $option['key'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
			}
			return $html . '</select>';
		}
		if ( 'multiselect' === $type ) {
			$selected = self::decode_list( $value );
			$html     = '<div class="remember-pq-checkboxes" id="' . esc_attr( $id ) . '">';
			foreach ( $options as $option ) {
				$html .= '<label class="remember-checkbox-label"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $option['key'] ) . '"' . checked( in_array( $option['key'], $selected, true ), true, false ) . '> ' . esc_html( $option['label'] ) . '</label>';
			}
			return $html . '</div>';
		}
		if ( 'boolean' === $type ) {
			return '<label><input type="radio" name="' . esc_attr( $name ) . '" value="yes"' . checked( $value, 'yes', false ) . '> ' . esc_html__( 'Yes', 'remember' ) . '</label> '
				. '<label><input type="radio" name="' . esc_attr( $name ) . '" value="no"' . checked( $value, 'no', false ) . '> ' . esc_html__( 'No', 'remember' ) . '</label>';
		}
		return '<input class="remember-form-control" type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . '>';
	}

	/**
	 * @param object[] $questions Questions on the form.
	 * @param array    $known     Field key => earlier answer for questions not on the form.
	 * @return string
	 */
	private static function validate_posted( $questions, $known = array() ) {
		$posted  = isset( $_POST['remember_survey'] ) && is_array( $_POST['remember_survey'] ) ? wp_unslash( $_POST['remember_survey'] ) : array();
		$visible = self::visibility_map( $questions, $posted, $known );
		foreach ( $questions as $question ) {
			$qid = (int) $question->question_id;
			if ( empty( $visible[ $qid ] ) ) {
				continue;
			}
			$raw = isset( $posted[ $qid ] ) ? $posted[ $qid ] : '';
			if ( self::question_is_required( $question, $visible ) && self::posted_is_empty( $question, $raw ) ) {
				return sprintf(
					/* translators: %s: question label */
					__( 'Please answer: %s', 'remember' ),
					$question->label
				);
			}
		}
		return '';
	}

	/**
	 * Save the answers to the questions that were on the form. Answers to other questions are left as they were.
	 *
	 * @param object   $survey         Survey.
	 * @param int      $member_id      Member.
	 * @param int      $application_id Application, or 0.
	 * @param object[] $questions      Questions that were on the form.
	 * @param array    $known          Field key => earlier answer for questions not on the form.
	 * @return void
	 */
	private static function save_response( $survey, $member_id, $application_id, $questions, $known = array() ) {
		global $wpdb;
		$posted   = isset( $_POST['remember_survey'] ) && is_array( $_POST['remember_survey'] ) ? wp_unslash( $_POST['remember_survey'] ) : array();
		$existing = self::response_for_member( $survey->survey_id, $member_id );
		$now      = current_time( 'mysql' );
		if ( $existing ) {
			$response_id = (int) $existing->response_id;
			$wpdb->update(
				self::responses_table(),
				array(
					'submitted_at'   => $now,
					'application_id' => $application_id > 0 ? $application_id : $existing->application_id,
				),
				array( 'response_id' => $response_id )
			);
		} else {
			$wpdb->insert(
				self::responses_table(),
				array(
					'survey_id'      => (int) $survey->survey_id,
					'event_id'       => (int) $survey->event_id,
					'member_id'      => absint( $member_id ),
					'application_id' => $application_id > 0 ? absint( $application_id ) : null,
					'submitted_at'   => $now,
				)
			);
			$response_id = (int) $wpdb->insert_id;
		}
		if ( $response_id < 1 ) {
			return;
		}
		$visible = self::visibility_map( $questions, $posted, $known );
		foreach ( $questions as $question ) {
			$qid   = (int) $question->question_id;
			$show  = ! empty( $visible[ $qid ] );
			$value = $show ? self::sanitize_answer( $question, isset( $posted[ $qid ] ) ? $posted[ $qid ] : '' ) : '';
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . self::answers_table() . ' (response_id, question_id, value_text) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)',
					$response_id,
					$qid,
					$value
				)
			);
		}
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		Remember_Logger::info(
			'Survey response saved',
			array(
				'survey_id'      => (int) $survey->survey_id,
				'event_id'       => (int) $survey->event_id,
				'member_id'      => absint( $member_id ),
				'application_id' => absint( $application_id ),
			)
		);
	}

	/**
	 * @return array<int,array{label:string,field_type:string,options:string,show_if_row:int,show_if_value:string,question_id:int}>
	 */
	private static function posted_question_rows() {
		if ( empty( $_POST['survey_questions'] ) || ! is_array( $_POST['survey_questions'] ) ) {
			return array();
		}
		$rows = array();
		foreach ( wp_unslash( $_POST['survey_questions'] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$type = isset( $row['field_type'] ) ? sanitize_key( $row['field_type'] ) : 'text';
			if ( ! in_array( $type, self::field_types(), true ) ) {
				$type = 'text';
			}
			$show_row = isset( $row['show_if_row'] ) ? (int) $row['show_if_row'] : -1;
			$rows[]   = array(
				'question_id'   => isset( $row['question_id'] ) ? absint( $row['question_id'] ) : 0,
				'label'         => $label,
				'field_type'    => $type,
				'options'       => isset( $row['options'] ) ? sanitize_textarea_field( $row['options'] ) : '',
				'show_if_row'   => $show_row,
				'show_if_value' => isset( $row['show_if_value'] ) ? sanitize_text_field( $row['show_if_value'] ) : '',
			);
		}
		return $rows;
	}

	/**
	 * @param int   $survey_id Survey.
	 * @param array $rows      Posted questions.
	 * @return void
	 */
	private static function sync_questions( $survey_id, $rows ) {
		global $wpdb;
		$table    = self::questions_table();
		$existing = self::questions( $survey_id );
		$owned    = array();
		foreach ( $existing as $question ) {
			$owned[ (int) $question->question_id ] = true;
		}
		$ids_by_row = array();
		$keep       = array();
		foreach ( $rows as $index => $row ) {
			$data = array(
				'survey_id'    => $survey_id,
				'sort_order'   => $index,
				'label'        => $row['label'],
				'field_type'   => $row['field_type'],
				'options_text' => $row['options'],
			);
			$qid = (int) $row['question_id'];
			if ( $qid > 0 && isset( $owned[ $qid ] ) ) {
				$wpdb->update( $table, $data, array( 'question_id' => $qid ) );
			} else {
				$wpdb->insert( $table, $data );
				$qid = (int) $wpdb->insert_id;
			}
			$ids_by_row[ $index ] = $qid;
			$keep[]               = $qid;
		}
		foreach ( $rows as $index => $row ) {
			$parent_row = (int) $row['show_if_row'];
			$parent_id  = ( $parent_row >= 0 && $parent_row < $index && isset( $ids_by_row[ $parent_row ] ) ) ? $ids_by_row[ $parent_row ] : 0;
			$value      = $parent_id > 0 ? $row['show_if_value'] : '';
			$wpdb->update(
				$table,
				array(
					'show_if_question_id' => $parent_id > 0 ? $parent_id : 0,
					'show_if_value'       => $value,
				),
				array( 'question_id' => $ids_by_row[ $index ] )
			);
		}
		foreach ( $existing as $question ) {
			$qid = (int) $question->question_id;
			if ( in_array( $qid, $keep, true ) ) {
				continue;
			}
			$wpdb->delete( self::answers_table(), array( 'question_id' => $qid ), array( '%d' ) );
			$wpdb->delete( $table, array( 'question_id' => $qid ), array( '%d' ) );
		}
	}

	/**
	 * @param object|null  $question Parent question.
	 * @param mixed        $raw      Posted parent value.
	 * @param string       $expected Expected value.
	 * @return bool
	 */
	private static function answer_matches( $question, $raw, $expected ) {
		$expected = strtolower( trim( (string) $expected ) );
		if ( '' === $expected || ! $question ) {
			return false;
		}
		if ( 'multiselect' === $question->field_type ) {
			$list = is_array( $raw ) ? $raw : array();
			foreach ( $list as $item ) {
				if ( strtolower( trim( sanitize_text_field( (string) $item ) ) ) === $expected ) {
					return true;
				}
			}
			return false;
		}
		return strtolower( trim( sanitize_text_field( is_array( $raw ) ? '' : (string) $raw ) ) ) === $expected;
	}

	/**
	 * @param object $question Question.
	 * @param mixed  $raw      Posted value.
	 * @return bool
	 */
	private static function posted_is_empty( $question, $raw ) {
		if ( 'multiselect' === $question->field_type ) {
			return ! is_array( $raw ) || empty( $raw );
		}
		return '' === trim( is_array( $raw ) ? '' : (string) $raw );
	}

	/**
	 * Build a qid => stored-value map from the posted answers, in the same format
	 * render_input() reads, so a failed submit can be shown back to the member.
	 *
	 * @param object[] $questions Questions.
	 * @return array<int,string>
	 */
	private static function posted_answers_map( $questions ) {
		$posted = isset( $_POST['remember_survey'] ) && is_array( $_POST['remember_survey'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller checked the nonce.
			? wp_unslash( $_POST['remember_survey'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by sanitize_answer().
			: array();
		$out = array();
		foreach ( $questions as $question ) {
			$qid         = (int) $question->question_id;
			$out[ $qid ] = self::sanitize_answer( $question, isset( $posted[ $qid ] ) ? $posted[ $qid ] : '' );
		}
		return $out;
	}

	/**
	 * @param object $question Question.
	 * @param mixed  $raw      Posted value.
	 * @return string
	 */
	private static function sanitize_answer( $question, $raw ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		if ( 'multiselect' === $question->field_type ) {
			$clean   = array();
			$allowed = wp_list_pluck( self::option_pairs( $question ), 'key' );
			foreach ( (array) $raw as $item ) {
				$item = Remember_Profile_Questions::sanitize_field_key( (string) $item );
				if ( in_array( $item, $allowed, true ) ) {
					$clean[] = $item;
				}
			}
			return implode( ' | ', array_values( $clean ) );
		}
		if ( 'boolean' === $question->field_type ) {
			$value = sanitize_key( is_array( $raw ) ? '' : (string) $raw );
			return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
		}
		if ( 'select' === $question->field_type || 'boolean' === $question->field_type ) {
			$value   = Remember_Profile_Questions::sanitize_field_key( is_array( $raw ) ? '' : (string) $raw );
			$allowed = wp_list_pluck( self::option_pairs( $question ), 'key' );
			return in_array( $value, $allowed, true ) ? $value : '';
		}
		if ( 'textarea' === $question->field_type ) {
			return sanitize_textarea_field( is_array( $raw ) ? '' : (string) $raw );
		}
		return sanitize_text_field( is_array( $raw ) ? '' : (string) $raw );
	}

	/**
	 * Save the survey title, instructions, and follow-up timing. Questions are saved separately.
	 *
	 * @param int $event_id Event.
	 * @return object|WP_Error Saved survey.
	 */
	public static function save_header_from_admin( $event_id ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-logger.php';
		$event_id  = absint( $event_id );
		$placement = ! empty( $_POST['on_application'] ) ? self::PLACEMENT_APPLICATION : self::PLACEMENT_FOLLOWUP;
		$survey_id    = isset( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : 0;
		$title        = isset( $_POST['survey_title'] ) ? sanitize_text_field( wp_unslash( $_POST['survey_title'] ) ) : '';
		$instructions = isset( $_POST['survey_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['survey_instructions'] ) ) : '';
		$timing    = isset( $_POST['survey_timing'] ) ? sanitize_key( wp_unslash( $_POST['survey_timing'] ) ) : '';
		if ( ! in_array( $timing, array( 'before', 'after' ), true ) ) {
			$timing = '';
		}
		if ( $event_id < 1 ) {
			return new WP_Error( 'remember_survey_event', __( 'Choose an event.', 'remember' ) );
		}
		if ( '' === $title ) {
			return new WP_Error( 'remember_survey_title', __( 'A survey needs a title.', 'remember' ) );
		}
		if ( self::PLACEMENT_APPLICATION === $placement ) {
			$existing = self::application_survey( $event_id );
			if ( $existing && (int) $existing->survey_id !== $survey_id ) {
				return new WP_Error( 'remember_survey_application', self::application_taken_message() );
			}
		}
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'event_id'     => $event_id,
			'title'        => $title,
			'instructions' => $instructions,
			'placement'    => $placement,
			'timing'       => self::PLACEMENT_FOLLOWUP === $placement ? ( $timing ? $timing : 'after' ) : '',
			'updated_at'   => $now,
		);
		if ( $survey_id > 0 ) {
			$current = self::get( $survey_id );
			if ( ! $current || (int) $current->event_id !== $event_id ) {
				return new WP_Error( 'remember_survey_missing', __( 'Survey not found for this event.', 'remember' ) );
			}
			if ( self::PLACEMENT_APPLICATION === $placement ) {
				$data['status'] = 'open';
			} elseif ( 'open' === $current->status ) {
				$data['status'] = 'draft';
			}
			$wpdb->update( self::surveys_table(), $data, array( 'survey_id' => $survey_id ) );
		} else {
			$data['status']     = self::PLACEMENT_APPLICATION === $placement ? 'open' : 'draft';
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $now;
			$wpdb->insert( self::surveys_table(), $data );
			$survey_id = (int) $wpdb->insert_id;
		}
		if ( $survey_id < 1 ) {
			return new WP_Error( 'remember_survey_save', __( 'The survey could not be saved.', 'remember' ) );
		}
		Remember_Logger::info(
			'Survey saved',
			array(
				'survey_id' => $survey_id,
				'event_id'  => $event_id,
				'placement' => $placement,
			)
		);
		$survey = self::get( $survey_id );
		return $survey ? $survey : new WP_Error( 'remember_survey_save', __( 'The survey could not be saved.', 'remember' ) );
	}

	/**
	 * Survey a question save belongs to. The header is saved on its own.
	 *
	 * @param int $event_id Event.
	 * @return object|WP_Error
	 */
	private static function existing_survey_for_question( $event_id ) {
		$survey = self::get( isset( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : 0 );
		if ( ! $survey || (int) $survey->event_id !== absint( $event_id ) ) {
			return new WP_Error( 'remember_survey_missing', __( 'Save the survey title before adding questions.', 'remember' ) );
		}
		return $survey;
	}

	/**
	 * @param int    $survey_id   Survey.
	 * @param string $field_key   Short name.
	 * @param int    $except_id   Question to ignore.
	 * @return bool
	 */
	private static function field_key_exists( $survey_id, $field_key, $except_id ) {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT question_id FROM ' . self::questions_table() . ' WHERE survey_id = %d AND field_key = %s AND question_id != %d LIMIT 1',
				absint( $survey_id ),
				$field_key,
				absint( $except_id )
			)
		);
		return (bool) $found;
	}

	/**
	 * @param int    $survey_id Survey.
	 * @param string $field_key Short name.
	 * @return object|null
	 */
	private static function question_by_field_key( $survey_id, $field_key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::questions_table() . ' WHERE survey_id = %d AND field_key = %s LIMIT 1',
				absint( $survey_id ),
				$field_key
			)
		);
		return $row ? $row : null;
	}

	/**
	 * @param string $field_type Type.
	 * @return bool
	 */
	private static function type_uses_options( $field_type ) {
		return in_array( (string) $field_type, array( 'select', 'multiselect' ), true );
	}

	/**
	 * @param string $field_type Type.
	 * @return bool
	 */
	private static function type_can_gate( $field_type ) {
		return in_array( (string) $field_type, array( 'select', 'multiselect', 'boolean' ), true );
	}

	/**
	 * @param object $question Question.
	 * @return string
	 */
	private static function question_field_key( $question ) {
		$key = isset( $question->field_key ) ? (string) $question->field_key : '';
		if ( '' === $key ) {
			$key = 'q' . (int) $question->question_id;
		}
		return $key;
	}

	/**
	 * @param object $question Question.
	 * @return array{field_key:string,values:array<int,string>}|null
	 */
	private static function when_rule( $question ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		if ( empty( $question->required_when_json ) ) {
			return null;
		}
		return Remember_Profile_Questions::parse_required_when( $question->required_when_json );
	}

	/**
	 * @param object[] $questions Questions in sort order.
	 * @param array    $posted    question_id => raw posted value.
	 * @param array    $known     Field key => earlier answer for questions not on the form.
	 * @return array<int,bool>
	 */
	private static function visibility_map( $questions, $posted, $known = array() ) {
		$by_key  = array();
		$visible = array();
		foreach ( $questions as $question ) {
			$by_key[ self::question_field_key( $question ) ] = $question;
		}
		for ( $pass = 0; $pass < 2; $pass++ ) {
			foreach ( $questions as $question ) {
				$qid  = (int) $question->question_id;
				$rule = self::when_rule( $question );
				if ( ! $rule ) {
					$visible[ $qid ] = true;
					continue;
				}
				if ( ! isset( $by_key[ $rule['field_key'] ] ) && isset( $known[ $rule['field_key'] ] ) ) {
					$visible[ $qid ] = self::posted_matches_values( $known[ $rule['field_key'] ], $rule['values'] );
					continue;
				}
				$gate    = isset( $by_key[ $rule['field_key'] ] ) ? $by_key[ $rule['field_key'] ] : null;
				$gate_id = $gate ? (int) $gate->question_id : 0;
				$gate_on = $gate_id > 0 && ( ! isset( $visible[ $gate_id ] ) || ! empty( $visible[ $gate_id ] ) );
				$raw     = ( $gate_id && isset( $posted[ $gate_id ] ) ) ? $posted[ $gate_id ] : '';
				$visible[ $qid ] = $gate_on && self::posted_matches_values( $raw, $rule['values'] );
			}
		}
		return $visible;
	}

	/**
	 * @param object        $question Question.
	 * @param array<int,bool> $visible Visibility map.
	 * @return bool
	 */
	private static function question_is_required( $question, $visible ) {
		$qid = (int) $question->question_id;
		if ( empty( $visible[ $qid ] ) ) {
			return false;
		}
		if ( self::when_rule( $question ) ) {
			return true;
		}
		return ! empty( $question->is_required );
	}

	/**
	 * @param mixed    $raw    Posted value.
	 * @param string[] $values Choice keys.
	 * @return bool
	 */
	private static function posted_matches_values( $raw, $values ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		$picked = array();
		$items  = is_array( $raw ) ? $raw : array( $raw );
		foreach ( $items as $item ) {
			$key = Remember_Profile_Questions::sanitize_field_key( (string) $item );
			if ( '' !== $key ) {
				$picked[] = $key;
			}
		}
		return ! empty( array_intersect( $picked, $values ) );
	}

	/**
	 * @param object $question Question.
	 * @return array<int,array{key:string,label:string}>
	 */
	private static function option_pairs( $question ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-profile-questions.php';
		if ( 'boolean' === $question->field_type ) {
			return array(
				array(
					'key'   => 'yes',
					'label' => __( 'Yes', 'remember' ),
				),
				array(
					'key'   => 'no',
					'label' => __( 'No', 'remember' ),
				),
			);
		}
		$parsed = Remember_Profile_Questions::parse_options( isset( $question->options_text ) ? $question->options_text : '' );
		if ( ! empty( $parsed ) ) {
			return $parsed;
		}
		$out = array();
		foreach ( self::option_lines( isset( $question->options_text ) ? $question->options_text : '' ) as $line ) {
			$key = Remember_Profile_Questions::sanitize_field_key( $line );
			if ( '' === $key ) {
				continue;
			}
			$out[] = array(
				'key'   => $key,
				'label' => $line,
			);
		}
		return $out;
	}

	/**
	 * @param string $text Options, one per line.
	 * @return string[]
	 */
	private static function option_lines( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * @param string $value Stored multiselect JSON.
	 * @return string[]
	 */
	private static function decode_list( $value ) {
		$out = array();
		foreach ( explode( ' | ', (string) $value ) as $item ) {
			$item = trim( $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param int $survey_id Survey.
	 * @param int $member_id Member.
	 * @return object|null
	 */
	private static function response_for_member( $survey_id, $member_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::responses_table() . ' WHERE survey_id = %d AND member_id = %d',
				absint( $survey_id ),
				absint( $member_id )
			)
		);
		return $row ? $row : null;
	}

	/**
	 * @param int $response_id Response.
	 * @return array<int,string>
	 */
	private static function answers_for_response( $response_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT question_id, value_text FROM ' . self::answers_table() . ' WHERE response_id = %d',
				absint( $response_id )
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->question_id ] = (string) $row->value_text;
		}
		return $out;
	}

	/**
	 * @param int $survey_id Survey.
	 * @return int
	 */
	private static function response_count( $survey_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::responses_table() . ' WHERE survey_id = %d', absint( $survey_id ) ) );
	}

	/**
	 * Checked role ids from the question form. None checked means every role.
	 *
	 * @return int[]
	 */
	private static function posted_role_ids() {
		$raw = isset( $_POST['event_role_ids'] ) ? wp_unslash( $_POST['event_role_ids'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}
		$ids = array();
		foreach ( $raw as $role_id ) {
			$role_id = absint( $role_id );
			if ( $role_id > 0 ) {
				$ids[ $role_id ] = $role_id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * Whether every member asked the inner question is also asked the outer one.
	 *
	 * @param int[] $outer Roles of the question that must be asked too. Empty means every role.
	 * @param int[] $inner Roles of the question that depends on it. Empty means every role.
	 * @return bool
	 */
	private static function roles_cover( $outer, $inner ) {
		if ( empty( $outer ) ) {
			return true;
		}
		if ( empty( $inner ) ) {
			return false;
		}
		return ! array_diff( array_map( 'absint', $inner ), array_map( 'absint', $outer ) );
	}

	/**
	 * @return string
	 */
	public static function application_taken_message() {
		return __( 'This event already has a survey on the application. Add these questions to that survey and choose which roles answer each one.', 'remember' );
	}

	/**
	 * Answer tallies for the event screen.
	 *
	 * Fixed-choice questions list every option. The percent is that option's share of the responses
	 * that were asked the question, so a question for one role is measured against that role's responses.
	 * A multi-select can total more than 100 percent because one response may include several choices.
	 *
	 * @param int $event_id Event.
	 * @return array<int,array{survey:object,responses:int,questions:array<int,array<string,mixed>>}>
	 */
	public static function results_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( $event_id < 1 ) {
			return array();
		}
		$surveys = self::list_for_admin( $event_id );
		if ( empty( $surveys ) ) {
			return array();
		}

		$response_counts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT survey_id, COUNT(*) AS response_count FROM ' . self::responses_table() . ' WHERE event_id = %d GROUP BY survey_id',
				$event_id
			),
			OBJECT_K
		);
		$answers = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ans.question_id, ans.value_text
				FROM ' . self::answers_table() . ' ans
				INNER JOIN ' . self::responses_table() . ' resp ON resp.response_id = ans.response_id
				WHERE resp.event_id = %d',
				$event_id
			)
		);
		$by_question = array();
		if ( is_array( $answers ) ) {
			foreach ( $answers as $row ) {
				$qid = (int) $row->question_id;
				if ( ! isset( $by_question[ $qid ] ) ) {
					$by_question[ $qid ] = array();
				}
				$by_question[ $qid ][] = (string) $row->value_text;
			}
		}

		$out = array();
		foreach ( $surveys as $survey ) {
			$sid       = (int) $survey->survey_id;
			$responses = 0;
			if ( is_array( $response_counts ) && isset( $response_counts[ $sid ] ) ) {
				$responses = (int) $response_counts[ $sid ]->response_count;
			}
			$questions     = array();
			$survey_qs     = self::questions( $sid );
			$question_role = self::question_roles_map( wp_list_pluck( $survey_qs, 'question_id' ) );
			foreach ( $survey_qs as $question ) {
				$qid    = (int) $question->question_id;
				$values = isset( $by_question[ $qid ] ) ? $by_question[ $qid ] : array();
				$asked  = count( $values );
				$type   = (string) $question->field_type;
				$item   = array(
					'label'      => (string) $question->label,
					'field_type' => $type,
					'roles'      => empty( $question_role[ $qid ] ) ? '' : implode( ', ', self::role_names( $question_role[ $qid ] ) ),
					'asked'      => $asked,
					'choices'    => array(),
					'written'    => 0,
				);
				if ( in_array( $type, array( 'select', 'multiselect', 'boolean' ), true ) ) {
					$counts = array();
					foreach ( self::option_pairs( $question ) as $pair ) {
						$key = isset( $pair['key'] ) ? (string) $pair['key'] : '';
						if ( '' === $key ) {
							continue;
						}
						$counts[ $key ] = array(
							'label' => isset( $pair['label'] ) && $pair['label'] ? (string) $pair['label'] : $key,
							'count' => 0,
						);
					}
					foreach ( $values as $value ) {
						$keys = ( 'multiselect' === $type )
							? array_map( 'trim', explode( '|', $value ) )
							: array( trim( $value ) );
						foreach ( $keys as $key ) {
							if ( isset( $counts[ $key ] ) ) {
								$counts[ $key ]['count']++;
							}
						}
					}
					foreach ( $counts as $choice ) {
						$item['choices'][] = array(
							'label'   => $choice['label'],
							'count'   => $choice['count'],
							'percent' => $asked > 0 ? (int) round( ( $choice['count'] / $asked ) * 100 ) : 0,
						);
					}
				} else {
					$written = 0;
					foreach ( $values as $value ) {
						if ( '' !== trim( $value ) ) {
							$written++;
						}
					}
					$item['written'] = $written;
				}
				$questions[] = $item;
			}
			$out[] = array(
				'survey'    => $survey,
				'responses' => $responses,
				'questions' => $questions,
			);
		}
		return $out;
	}

	/**
	 * @return string
	 */
	private static function surveys_table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_surveys';
	}

	/**
	 * @return string
	 */
	private static function question_roles_table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_survey_question_roles';
	}

	/**
	 * @return string
	 */
	private static function questions_table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_survey_questions';
	}

	/**
	 * @return string
	 */
	private static function responses_table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_survey_responses';
	}

	/**
	 * @return string
	 */
	private static function answers_table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_survey_answers';
	}
}
