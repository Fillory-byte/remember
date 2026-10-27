<?php
/**
 * Event surveys admin.
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-surveys.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-profile-questions.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';

if ( ! current_user_can( 'remember_read_events' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'remember' ) );
}

$can_edit = current_user_can( 'remember_update_events' );
$event_id = isset( $_REQUEST['event_id'] ) ? absint( $_REQUEST['event_id'] ) : 0;

if ( $can_edit && isset( $_GET['delete_survey'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'remember_survey_delete_' . absint( $_GET['delete_survey'] ) ) ) {
	$result = Remember_Surveys::delete_survey( absint( $_GET['delete_survey'] ) );
	if ( is_wp_error( $result ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Survey deleted.', 'remember' ) . '</p></div>';
		unset( $_GET['survey_id'], $_GET['new'] );
	}
}

if ( $can_edit && isset( $_GET['delete_question'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'remember_survey_question_delete_' . absint( $_GET['delete_question'] ) ) ) {
	$result = Remember_Surveys::delete_question( absint( $_GET['delete_question'] ) );
	if ( is_wp_error( $result ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Question deleted.', 'remember' ) . '</p></div>';
	}
}

if ( $can_edit && isset( $_POST['remember_survey_action'] ) && check_admin_referer( 'remember_survey_admin', 'remember_survey_nonce' ) ) {
	$action = sanitize_key( wp_unslash( $_POST['remember_survey_action'] ) );
	if ( 'issue' === $action ) {
		$result = Remember_Surveys::issue( isset( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : 0 );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Follow-on survey issued. Accepted participants were emailed.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'delete' === $action ) {
		$result = Remember_Surveys::delete_survey( isset( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : 0 );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Survey deleted.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'save_header' === $action ) {
		$result = Remember_Surveys::save_header_from_admin( $event_id );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			$GLOBALS['remember_survey_header_failed'] = true;
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Survey saved.', 'remember' ) . '</p></div>';
			$_GET['survey_id'] = (int) $result->survey_id;
			$event_id          = (int) $result->event_id;
			unset( $_GET['new'], $_GET['new_followup'], $_GET['edit'] );
			$GLOBALS['remember_survey_saved_url'] = add_query_arg(
				array(
					'page'      => 'remember-surveys',
					'survey_id' => (int) $result->survey_id,
				),
				admin_url( 'admin.php' )
			);
		}
	} elseif ( 'copy' === $action ) {
		$result = Remember_Surveys::copy_survey(
			isset( $_POST['survey_id'] ) ? absint( $_POST['survey_id'] ) : 0,
			isset( $_POST['target_event_id'] ) ? absint( $_POST['target_event_id'] ) : 0
		);
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Survey copied. You are now editing the copy; the original was not changed.', 'remember' ) . '</p></div>';
			if ( ! empty( $result['notes'] ) ) {
				echo '<div class="notice notice-info is-dismissible"><ul style="list-style: disc; margin-left: 1.5em;">';
				foreach ( $result['notes'] as $note ) {
					echo '<li>' . esc_html( $note ) . '</li>';
				}
				echo '</ul></div>';
			}
			$_GET['survey_id'] = (int) $result['survey_id'];
			unset( $_GET['copy_survey'], $_GET['new'], $_GET['edit'] );
			$GLOBALS['remember_survey_saved_url'] = add_query_arg(
				array(
					'page'      => 'remember-surveys',
					'survey_id' => (int) $result['survey_id'],
				),
				admin_url( 'admin.php' )
			);
		}
	} elseif ( 'save_question' === $action ) {
		$result = Remember_Surveys::save_question_from_admin( $event_id );
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Question saved.', 'remember' ) . '</p></div>';
			$saved_question = Remember_Surveys::get_question( (int) $result );
			if ( $saved_question ) {
				$_GET['survey_id'] = (int) $saved_question->survey_id;
				unset( $_GET['new_followup'], $_GET['edit'] );
			}
		}
	}
}

global $wpdb;
$events = $wpdb->get_results( "SELECT event_id, event_name FROM {$wpdb->prefix}remember_events ORDER BY start_date DESC, event_name ASC" );
if ( ! is_array( $events ) ) {
	$events = array();
}

if ( $can_edit && isset( $_GET['dismiss_merge_notice'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'remember_survey_merge_notice_dismiss' ) ) {
	delete_option( 'remember_survey_merge_notice' );
	Remember_Logger::info( 'Dismissed merged surveys notice', array( 'user_id' => get_current_user_id() ) );
}

$merge_notice = $can_edit ? get_option( 'remember_survey_merge_notice', array() ) : array();
if ( is_array( $merge_notice ) && $merge_notice ) {
	$event_names = array();
	foreach ( $events as $ev ) {
		$event_names[ (int) $ev->event_id ] = (string) $ev->event_name;
	}
	$dismiss_url = wp_nonce_url(
		add_query_arg(
			array(
				'page'                 => 'remember-surveys',
				'dismiss_merge_notice' => 1,
			),
			admin_url( 'admin.php' )
		),
		'remember_survey_merge_notice_dismiss'
	);
	echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Application surveys were merged', 'remember' ) . '</strong></p>';
	echo '<p>' . esc_html__( 'Roles are now chosen per question, so each event has one survey on the application. Where an event had several, their questions and answers were moved into one survey. Each merged survey\'s instructions were added to the end of the kept survey\'s instructions, under its old title. Every applicant now sees the kept survey\'s title and all of the instructions, so check the wording.', 'remember' ) . '</p>';
	echo '<ul style="list-style: disc; margin-left: 1.5em;">';
	foreach ( $merge_notice as $entry ) {
		$entry_event = isset( $entry['event_id'] ) ? (int) $entry['event_id'] : 0;
		$entry_id    = isset( $entry['survey_id'] ) ? (int) $entry['survey_id'] : 0;
		$titles      = array();
		foreach ( isset( $entry['merged'] ) ? (array) $entry['merged'] : array() as $gone ) {
			$titles[] = isset( $gone['title'] ) && '' !== (string) $gone['title'] ? (string) $gone['title'] : __( '(untitled)', 'remember' );
		}
		$edit_url = add_query_arg(
			array(
				'page'      => 'remember-surveys',
				'survey_id' => $entry_id,
			),
			admin_url( 'admin.php' )
		);
		echo '<li>' . esc_html(
			sprintf(
				/* translators: 1: event name, 2: kept survey title, 3: comma-separated titles of the surveys merged into it */
				__( '%1$s: kept "%2$s" and merged in %3$s.', 'remember' ),
				isset( $event_names[ $entry_event ] ) ? $event_names[ $entry_event ] : sprintf( /* translators: %d: event ID */ __( 'Event #%d', 'remember' ), $entry_event ),
				isset( $entry['title'] ) ? (string) $entry['title'] : '',
				'"' . implode( '", "', $titles ) . '"'
			)
		) . ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit survey', 'remember' ) . '</a></li>';
	}
	echo '</ul>';
	echo '<p><a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss this notice', 'remember' ) . '</a></p></div>';
}

$copy_source = ( $can_edit && isset( $_GET['copy_survey'] ) ) ? Remember_Surveys::get( absint( $_GET['copy_survey'] ) ) : null;
$is_new      = ! $copy_source && ! empty( $_GET['new'] );
$active_id   = ( ! $copy_source && isset( $_GET['survey_id'] ) ) ? absint( $_GET['survey_id'] ) : 0;
$active    = $active_id > 0 ? Remember_Surveys::get( $active_id ) : null;
if ( $active ) {
	$event_id = (int) $active->event_id;
	$is_new   = false;
}
$show_editor = $is_new || $active;

$edit_question_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing_question = $edit_question_id > 0 ? Remember_Surveys::get_question( $edit_question_id ) : null;
if ( $editing_question && ( ! $active || (int) $editing_question->survey_id !== (int) $active->survey_id ) ) {
	$editing_question = null;
}

/**
 * @param object|null $survey   Survey being edited, or null when creating a follow-up.
 * @param string      $placement application|followup.
 * @param object|null $question Question being edited.
 * @return void
 */
function remember_survey_question_form( $survey, $placement, $question ) {
	$questions = $survey ? Remember_Surveys::questions( $survey->survey_id ) : array();
	$is_edit   = is_object( $question );
	$label     = $is_edit ? (string) $question->label : '';
	$field_key = $is_edit ? (string) $question->field_key : '';
	$field_type = $is_edit ? (string) $question->field_type : 'text';
	$choice_rows = $is_edit ? array() : array(
		array( 'key' => '', 'label' => '' ),
		array( 'key' => '', 'label' => '' ),
	);
	if ( $is_edit && in_array( $field_type, array( 'select', 'multiselect' ), true ) ) {
		$parsed = Remember_Profile_Questions::parse_options( $question->options_text );
		$choice_rows = ! empty( $parsed ) ? $parsed : $choice_rows;
	}
	$when_rule = $is_edit ? Remember_Profile_Questions::parse_required_when( isset( $question->required_when_json ) ? $question->required_when_json : null ) : null;
	if ( $when_rule ) {
		$req_mode = 'when';
	} elseif ( $is_edit && ! empty( $question->is_required ) ) {
		$req_mode = 'always';
	} else {
		$req_mode = 'optional';
	}
	$when_field  = $when_rule ? $when_rule['field_key'] : '';
	$when_values = $when_rule ? $when_rule['values'] : array();
	$sort_order = $is_edit ? (int) $question->sort_order : 0;
	$self_key   = $is_edit ? (string) $question->field_key : '';
	$role_options   = $survey ? Remember_Surveys::roles_for_event( $survey->event_id ) : array();
	$question_roles = $is_edit ? Remember_Surveys::question_role_ids( $question->question_id ) : array();
	$gate_meta   = array();
	foreach ( $questions as $candidate ) {
		if ( ! in_array( $candidate->field_type, array( 'select', 'multiselect', 'boolean' ), true ) ) {
			continue;
		}
		$key = (string) $candidate->field_key;
		if ( '' === $key || $key === $self_key ) {
			continue;
		}
		if ( 'boolean' === $candidate->field_type ) {
			$options = array(
				array( 'key' => 'yes', 'label' => __( 'Yes', 'remember' ) ),
				array( 'key' => 'no', 'label' => __( 'No', 'remember' ) ),
			);
		} else {
			$options = Remember_Profile_Questions::parse_options( $candidate->options_text );
		}
		$gate_meta[ $key ] = array(
			'label'   => (string) $candidate->label,
			'options' => $options,
		);
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="label"><?php esc_html_e( 'Question', 'remember' ); ?></label></th>
			<td>
				<input type="text" class="large-text" name="label" id="label" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'What sort of ice cream do you like?', 'remember' ); ?>">
				<p class="description"><?php esc_html_e( 'This is what members see on the survey.', 'remember' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Who answers this', 'remember' ); ?></th>
			<td>
				<fieldset class="remember-survey-roles">
					<legend class="screen-reader-text"><?php esc_html_e( 'Who answers this', 'remember' ); ?></legend>
					<?php foreach ( $role_options as $role ) : ?>
						<label>
							<input type="checkbox" name="event_role_ids[]" value="<?php echo esc_attr( (string) $role->event_role_id ); ?>" <?php checked( in_array( (int) $role->event_role_id, $question_roles, true ) ); ?>>
							<?php echo esc_html( $role->role_name ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">
					<?php
					echo esc_html(
						empty( $role_options )
							? __( 'This event has no roles yet, so every participant answers this question.', 'remember' )
							: __( 'Leave every role unticked to ask everyone. Tick roles to ask only members who apply for, or were accepted for, those roles.', 'remember' )
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="field_type"><?php esc_html_e( 'Answer type', 'remember' ); ?></label></th>
			<td>
				<select name="field_type" id="field_type">
					<option value="text" <?php selected( $field_type, 'text' ); ?>><?php esc_html_e( 'They type their own answer', 'remember' ); ?></option>
					<option value="textarea" <?php selected( $field_type, 'textarea' ); ?>><?php esc_html_e( 'They type a longer answer', 'remember' ); ?></option>
					<option value="select" <?php selected( $field_type, 'select' ); ?>><?php esc_html_e( 'They pick one from a list', 'remember' ); ?></option>
					<option value="multiselect" <?php selected( $field_type, 'multiselect' ); ?>><?php esc_html_e( 'They can pick several from a list', 'remember' ); ?></option>
					<option value="boolean" <?php selected( $field_type, 'boolean' ); ?>><?php esc_html_e( 'Yes or no', 'remember' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="remember-pq-options-row">
			<th scope="row"><?php esc_html_e( 'Choices', 'remember' ); ?></th>
			<td>
				<div class="remember-pq-choices" id="remember-pq-choices">
					<div class="remember-pq-choices-head">
						<span><?php esc_html_e( 'Label', 'remember' ); ?></span>
						<span><?php esc_html_e( 'Key', 'remember' ); ?></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Actions', 'remember' ); ?></span>
					</div>
					<div class="remember-pq-choices-list">
						<?php foreach ( $choice_rows as $choice ) : ?>
							<div class="remember-pq-choice-row">
								<input type="text" name="option_label[]" class="remember-pq-choice-label" value="<?php echo esc_attr( $choice['label'] ); ?>" placeholder="<?php esc_attr_e( 'Vanilla', 'remember' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'remember' ); ?>">
								<input type="text" name="option_key[]" class="remember-pq-choice-key" value="<?php echo esc_attr( $choice['key'] ); ?>" placeholder="vanilla" pattern="[a-z0-9_]*" autocomplete="off" aria-label="<?php esc_attr_e( 'Key', 'remember' ); ?>">
								<button type="button" class="button-link-delete remember-pq-remove-choice"><?php esc_html_e( 'Remove', 'remember' ); ?></button>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="remember-pq-choices-actions">
						<button type="button" class="button" id="remember-pq-add-choice"><?php esc_html_e( 'Add choice', 'remember' ); ?></button>
					</p>
				</div>
				<p class="description"><?php esc_html_e( 'Label is what members see; Key is stored and used in reports. Leave Key blank and we fill it from the label.', 'remember' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Required?', 'remember' ); ?></th>
			<td>
				<fieldset>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="required_mode" value="optional" <?php checked( $req_mode, 'optional' ); ?>>
						<?php esc_html_e( 'Optional', 'remember' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="required_mode" value="always" <?php checked( $req_mode, 'always' ); ?>>
						<?php esc_html_e( 'Always required', 'remember' ); ?>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="required_mode" value="when" <?php checked( $req_mode, 'when' ); ?> <?php disabled( empty( $gate_meta ) ); ?>>
						<?php esc_html_e( 'Required when another field matches…', 'remember' ); ?>
					</label>
				</fieldset>
				<?php if ( empty( $gate_meta ) ) : ?>
					<p class="description"><?php esc_html_e( 'Add a pick-one, pick-several, or yes/no question first if you want conditional requirements.', 'remember' ); ?></p>
				<?php else : ?>
					<div class="remember-pq-when-wrap" style="<?php echo 'when' === $req_mode ? '' : 'display:none;'; ?>">
						<p>
							<label for="required_when_field"><?php esc_html_e( 'When this field is', 'remember' ); ?></label>
							<select name="required_when_field" id="required_when_field">
								<option value=""><?php esc_html_e( '— Select field —', 'remember' ); ?></option>
								<?php foreach ( $gate_meta as $gk => $meta ) : ?>
									<option value="<?php echo esc_attr( $gk ); ?>" <?php selected( $when_field, $gk ); ?>><?php echo esc_html( $meta['label'] . ' (' . $gk . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<div id="remember-pq-when-values">
							<?php
							$opts_for_when = ( $when_field && isset( $gate_meta[ $when_field ] ) ) ? $gate_meta[ $when_field ]['options'] : array();
							if ( ! empty( $opts_for_when ) ) :
								?>
								<p class="description" style="margin-bottom:6px;"><?php esc_html_e( 'Any of these choices (member must answer this field):', 'remember' ); ?></p>
								<?php foreach ( $opts_for_when as $opt ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="required_when_values[]" value="<?php echo esc_attr( $opt['key'] ); ?>" <?php checked( in_array( $opt['key'], $when_values, true ) ); ?>>
										<?php echo esc_html( $opt['label'] ); ?>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Select a field to choose which answers make this required.', 'remember' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="field_key"><?php esc_html_e( 'Short name', 'remember' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" name="field_key" id="field_key" value="<?php echo esc_attr( $field_key ); ?>" pattern="[a-z0-9_]*" placeholder="ice_cream_flavor" autocomplete="off">
				<p class="description"><?php esc_html_e( 'A short name for this question, such as ice_cream_flavor. Leave blank and we will suggest one from the question. Survey reports use the question text as the column heading.', 'remember' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sort_order"><?php esc_html_e( 'Order', 'remember' ); ?></label></th>
			<td>
				<input type="number" name="sort_order" id="sort_order" value="<?php echo esc_attr( (string) $sort_order ); ?>" class="small-text">
				<p class="description"><?php esc_html_e( 'Lower numbers appear first. Put the gating field above questions that depend on it.', 'remember' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
	$GLOBALS['remember_survey_gate_meta']   = $gate_meta;
	$GLOBALS['remember_survey_key_touched'] = $is_edit;
}

/**
 * Title and optional instructions, separate from the question editor.
 *
 * @param object|null $survey   Survey, or null when creating one.
 * @param int         $event_id Event to preselect.
 * @param object[]    $events   Events for the picker.
 * @return void
 */
function remember_survey_header_form( $survey, $event_id, $events ) {
	$use_post = ! empty( $GLOBALS['remember_survey_header_failed'] );
	if ( $use_post ) {
		$title          = isset( $_POST['survey_title'] ) ? sanitize_text_field( wp_unslash( $_POST['survey_title'] ) ) : '';
		$instructions   = isset( $_POST['survey_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['survey_instructions'] ) ) : '';
		$timing         = isset( $_POST['survey_timing'] ) ? sanitize_key( wp_unslash( $_POST['survey_timing'] ) ) : 'after';
		$on_application = ! empty( $_POST['on_application'] );
		$event_id       = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : $event_id;
	} else {
		$title          = $survey ? (string) $survey->title : '';
		$instructions   = ( $survey && isset( $survey->instructions ) ) ? (string) $survey->instructions : '';
		$timing         = ( $survey && 'before' === $survey->timing ) ? 'before' : 'after';
		$on_application = $survey && Remember_Surveys::PLACEMENT_APPLICATION === $survey->placement;
	}
	$survey_id = $survey ? (int) $survey->survey_id : 0;
	$action    = array( 'page' => 'remember-surveys' );
	if ( $survey_id > 0 ) {
		$action['survey_id'] = $survey_id;
	} else {
		$action['new'] = 1;
	}
	$application_surveys = Remember_Surveys::application_survey_ids_by_event();
	?>
	<form method="post" class="remember-survey-masthead" action="<?php echo esc_url( add_query_arg( $action, admin_url( 'admin.php' ) ) ); ?>">
		<?php wp_nonce_field( 'remember_survey_admin', 'remember_survey_nonce' ); ?>
		<input type="hidden" name="remember_survey_action" value="save_header">
		<input type="hidden" name="survey_id" value="<?php echo esc_attr( (string) $survey_id ); ?>">
		<div class="remember-survey-header-grid">
			<p class="remember-survey-title-field">
				<label for="survey_title"><?php esc_html_e( 'Title', 'remember' ); ?></label>
				<input type="text" class="remember-survey-title-input" name="survey_title" id="survey_title" value="<?php echo esc_attr( $title ); ?>" required placeholder="<?php esc_attr_e( 'Survey title', 'remember' ); ?>">
			</p>
			<div>
			<p>
				<label for="survey_event_id"><?php esc_html_e( 'Event', 'remember' ); ?></label>
				<?php if ( $survey ) : ?>
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
					<select id="survey_event_id" disabled>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event->event_id ); ?>" <?php selected( $event_id, (int) $event->event_id ); ?>><?php echo esc_html( $event->event_name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<select name="event_id" id="survey_event_id" required>
						<option value=""><?php esc_html_e( 'Select an event', 'remember' ); ?></option>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event->event_id ); ?>" <?php selected( $event_id, (int) $event->event_id ); ?>><?php echo esc_html( $event->event_name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Choose which roles answer each question when you add it.', 'remember' ); ?></p>
			</div>
		</div>
		<p class="remember-survey-instructions-field">
			<label for="survey_instructions"><?php esc_html_e( 'Instructions', 'remember' ); ?></label>
			<textarea name="survey_instructions" id="survey_instructions" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Optional. Tell members how to answer this survey.', 'remember' ); ?>"><?php echo esc_textarea( $instructions ); ?></textarea>
		</p>
		<div class="remember-survey-header-grid">
			<p>
				<label>
					<input type="checkbox" name="on_application" id="survey_on_application" value="1" <?php checked( $on_application ); ?>>
					<?php esc_html_e( 'Show on the event application', 'remember' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'Off by default. Turn this on to put the survey on the application, before the agreements. Each event has one survey on the application; applicants see only the questions for the role they apply for. Leave it off to send the survey later to accepted participants.', 'remember' ); ?></span>
				<span class="description" id="remember-survey-role-taken" hidden></span>
			</p>
			<p id="remember-survey-timing" <?php echo $on_application ? 'hidden' : ''; ?>>
				<label for="survey_timing"><?php esc_html_e( 'When', 'remember' ); ?></label>
				<select name="survey_timing" id="survey_timing">
					<option value="before" <?php selected( $timing, 'before' ); ?>><?php esc_html_e( 'Before the event', 'remember' ); ?></option>
					<option value="after" <?php selected( $timing, 'after' ); ?>><?php esc_html_e( 'After the event', 'remember' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Used when this survey is sent to accepted participants.', 'remember' ); ?></span>
			</p>
		</div>
		<?php submit_button( __( 'Save survey', 'remember' ), 'primary', 'submit', false ); ?>
		<script type="application/json" id="remember-survey-application-slots"><?php echo wp_json_encode( (object) $application_surveys ); ?></script>
	</form>
	<?php
}

/**
 * @param object[] $questions Questions.
 * @param int      $event_id  Event.
 * @param int      $survey_id Survey, for edit links.
 * @return void
 */
function remember_survey_question_table( $questions, $event_id, $survey_id ) {
	if ( empty( $questions ) ) {
		echo '<p>' . esc_html__( 'No questions yet.', 'remember' ) . '</p>';
		return;
	}
	$roles_by_question = Remember_Surveys::question_roles_map( wp_list_pluck( $questions, 'question_id' ) );
	$role_names        = array();
	foreach ( Remember_Surveys::roles_for_event( $event_id ) as $role ) {
		$role_names[ (int) $role->event_role_id ] = (string) $role->role_name;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Question', 'remember' ) . '</th>';
	echo '<th>' . esc_html__( 'Asked of', 'remember' ) . '</th>';
	echo '<th>' . esc_html__( 'Short name', 'remember' ) . '</th>';
	echo '<th>' . esc_html__( 'Type', 'remember' ) . '</th>';
	echo '<th>' . esc_html__( 'Required', 'remember' ) . '</th>';
	echo '<th>' . esc_html__( 'Order', 'remember' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'remember' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $questions as $question ) {
		$args = array(
			'page'      => 'remember-surveys',
			'event_id'  => $event_id,
			'edit'      => (int) $question->question_id,
		);
		if ( $survey_id > 0 ) {
			$args['survey_id'] = $survey_id;
		}
		$edit_url = add_query_arg( $args, admin_url( 'admin.php' ) );
		$del_url  = wp_nonce_url(
			add_query_arg(
				array(
					'page'            => 'remember-surveys',
					'event_id'        => $event_id,
					'survey_id'       => $survey_id,
					'delete_question' => (int) $question->question_id,
				),
				admin_url( 'admin.php' )
			),
			'remember_survey_question_delete_' . (int) $question->question_id
		);
		echo '<tr>';
		$asked_of = array();
		foreach ( isset( $roles_by_question[ (int) $question->question_id ] ) ? $roles_by_question[ (int) $question->question_id ] : array() as $role_id ) {
			$asked_of[] = isset( $role_names[ $role_id ] ) ? $role_names[ $role_id ] : __( '(role removed)', 'remember' );
		}
		echo '<td><strong>' . esc_html( $question->label ) . '</strong></td>';
		echo '<td>' . esc_html( $asked_of ? implode( ', ', $asked_of ) : __( 'All roles', 'remember' ) ) . '</td>';
		echo '<td><code>' . esc_html( (string) $question->field_key ) . '</code></td><td>';
		if ( 'select' === $question->field_type ) {
			esc_html_e( 'Pick one', 'remember' );
		} elseif ( 'multiselect' === $question->field_type ) {
			esc_html_e( 'Pick several', 'remember' );
		} elseif ( 'textarea' === $question->field_type ) {
			esc_html_e( 'Long answer', 'remember' );
		} elseif ( 'boolean' === $question->field_type ) {
			esc_html_e( 'Yes or no', 'remember' );
		} else {
			esc_html_e( 'Type answer', 'remember' );
		}
		echo '</td><td>';
		$when = Remember_Profile_Questions::parse_required_when( isset( $question->required_when_json ) ? $question->required_when_json : null );
		if ( $when ) {
			esc_html_e( 'When…', 'remember' );
			echo ' <code>' . esc_html( $when['field_key'] ) . '</code>';
		} elseif ( ! empty( $question->is_required ) ) {
			esc_html_e( 'Always', 'remember' );
		} else {
			esc_html_e( 'No', 'remember' );
		}
		echo '</td><td>' . esc_html( (string) $question->sort_order ) . '</td><td>';
		echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'remember' ) . '</a> | ';
		echo '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this question and its answers?', 'remember' ) ) . '\');">' . esc_html__( 'Delete', 'remember' ) . '</a>';
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

$list_url = admin_url( 'admin.php?page=remember-surveys' );
$new_args = array(
	'page' => 'remember-surveys',
	'new'  => 1,
);
if ( ! $show_editor && $event_id > 0 ) {
	$new_args['event_id'] = $event_id;
}
$new_url = add_query_arg( $new_args, admin_url( 'admin.php' ) );
?>
<?php if ( ! empty( $GLOBALS['remember_survey_saved_url'] ) ) : ?>
<script>
if (window.history && history.replaceState) {
	history.replaceState(null, '', <?php echo wp_json_encode( $GLOBALS['remember_survey_saved_url'] ); ?>);
}
</script>
<?php endif; ?>
<div class="wrap remember-surveys">
<?php if ( $copy_source ) : ?>
	<?php
	$copy_question_count = count( Remember_Surveys::questions( $copy_source->survey_id ) );
	$copy_event_name     = '';
	foreach ( $events as $event ) {
		if ( (int) $event->event_id === (int) $copy_source->event_id ) {
			$copy_event_name = (string) $event->event_name;
			break;
		}
	}
	?>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Copy Survey', 'remember' ); ?></h1>
	<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to Surveys', 'remember' ); ?></a>
	<hr class="wp-header-end">
	<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => 'remember-surveys', 'copy_survey' => (int) $copy_source->survey_id ), admin_url( 'admin.php' ) ) ); ?>">
		<?php wp_nonce_field( 'remember_survey_admin', 'remember_survey_nonce' ); ?>
		<input type="hidden" name="remember_survey_action" value="copy">
		<input type="hidden" name="survey_id" value="<?php echo esc_attr( (string) $copy_source->survey_id ); ?>">
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Survey', 'remember' ); ?></th>
				<td>
					<strong><?php echo esc_html( $copy_source->title ); ?></strong>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: event name, 2: where the survey appears, 3: number of questions */
								_n( '%1$s · %2$s · %3$d question', '%1$s · %2$s · %3$d questions', $copy_question_count, 'remember' ),
								'' !== $copy_event_name ? $copy_event_name : __( '(event removed)', 'remember' ),
								Remember_Surveys::PLACEMENT_APPLICATION === $copy_source->placement ? __( 'On the application', 'remember' ) : __( 'Follow-up', 'remember' ),
								$copy_question_count
							)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="remember_copy_target_event"><?php esc_html_e( 'Copy to event', 'remember' ); ?></label></th>
				<td>
					<select name="target_event_id" id="remember_copy_target_event" required>
						<option value=""><?php esc_html_e( '— Choose an event —', 'remember' ); ?></option>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event->event_id ); ?>">
								<?php
								echo esc_html( $event->event_name );
								if ( (int) $event->event_id === (int) $copy_source->event_id ) {
									echo ' ' . esc_html__( '(same event)', 'remember' );
								}
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The copy gets the title, instructions and every question, with who answers each one and their "required when" rules. Responses are never copied, and the original survey is not changed.', 'remember' ); ?></p>
					<p class="description"><?php esc_html_e( 'Roles the chosen event does not have are left off each question; a question left with none is asked of every role. A survey on the application stays on the application unless the chosen event already has one; then the copy becomes a follow-up draft. Follow-up copies always start as drafts.', 'remember' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Copy survey', 'remember' ), 'primary', 'submit', false ); ?>
		<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'remember' ); ?></a>
	</form>
<?php elseif ( $show_editor ) : ?>
	<h1 class="wp-heading-inline"><?php echo esc_html( $active ? $active->title : __( 'Add Survey', 'remember' ) ); ?></h1>
	<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to Surveys', 'remember' ); ?></a>
	<?php if ( $active && $can_edit ) : ?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'remember-surveys', 'copy_survey' => (int) $active->survey_id ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Copy to an event', 'remember' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">
	<?php if ( $can_edit ) : ?>
		<?php remember_survey_header_form( $active, $event_id, $events ); ?>
	<?php elseif ( $active ) : ?>
		<?php if ( ! empty( $active->instructions ) ) : ?>
			<div class="remember-survey-instructions"><?php echo wpautop( esc_html( $active->instructions ) ); ?></div>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $active ) : ?>
		<h2><?php esc_html_e( 'Questions', 'remember' ); ?></h2>
		<?php remember_survey_question_table( Remember_Surveys::questions( $active->survey_id ), $event_id, (int) $active->survey_id ); ?>
		<?php if ( $can_edit ) : ?>
			<?php
			$question_args = array(
				'page'      => 'remember-surveys',
				'survey_id' => (int) $active->survey_id,
			);
			if ( $editing_question ) {
				$question_args['edit'] = (int) $editing_question->question_id;
			}
			?>
			<div class="remember-survey-question-editor">
				<h2><?php echo esc_html( $editing_question ? __( 'Edit question', 'remember' ) : __( 'Add a question', 'remember' ) ); ?></h2>
				<form method="post" class="remember-pq-form" action="<?php echo esc_url( add_query_arg( $question_args, admin_url( 'admin.php' ) ) ); ?>">
					<?php wp_nonce_field( 'remember_survey_admin', 'remember_survey_nonce' ); ?>
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
					<input type="hidden" name="remember_survey_action" value="save_question">
					<input type="hidden" name="survey_id" value="<?php echo esc_attr( (string) $active->survey_id ); ?>">
					<input type="hidden" name="question_id" value="<?php echo esc_attr( $editing_question ? (string) $editing_question->question_id : '0' ); ?>">
					<?php remember_survey_question_form( $active, $active->placement, $editing_question ); ?>
					<?php submit_button( $editing_question ? __( 'Save changes', 'remember' ) : __( 'Add question', 'remember' ), 'primary', 'submit', false ); ?>
					<?php if ( $editing_question ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'remember-surveys', 'survey_id' => (int) $active->survey_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'remember' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
		<?php endif; ?>
	<?php elseif ( $can_edit ) : ?>
		<p><?php esc_html_e( 'Save the survey, then add questions.', 'remember' ); ?></p>
	<?php endif; ?>
<?php else : ?>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Surveys', 'remember' ); ?></h1>
	<?php if ( $can_edit ) : ?>
		<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'remember' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">
	<div class="remember-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
		<form method="get" action="">
			<input type="hidden" name="page" value="remember-surveys">
			<label for="remember_survey_event"><?php esc_html_e( 'Filter by Event:', 'remember' ); ?></label>
			<select name="event_id" id="remember_survey_event">
				<option value="0"><?php esc_html_e( 'All Events', 'remember' ); ?></option>
				<?php foreach ( $events as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event->event_id ); ?>" <?php selected( $event_id, (int) $event->event_id ); ?>><?php echo esc_html( $event->event_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'remember' ); ?>">
			<?php if ( $event_id > 0 ) : ?>
				<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'remember' ); ?></a>
			<?php endif; ?>
		</form>
	</div>
	<?php $survey_list = Remember_Surveys::list_for_admin( $event_id ); ?>
	<?php if ( $survey_list ) : ?>
		<div class="remember-table-scroll">
		<table class="wp-list-table widefat striped remember-responsive-table remember-list-cards">
			<thead>
				<tr>
					<th class="column-name"><?php esc_html_e( 'Survey', 'remember' ); ?></th>
					<th class="column-event"><?php esc_html_e( 'Event', 'remember' ); ?></th>
					<th class="column-roles"><?php esc_html_e( 'Roles', 'remember' ); ?></th>
					<th class="column-when"><?php esc_html_e( 'When', 'remember' ); ?></th>
					<th class="column-questions"><?php esc_html_e( 'Questions', 'remember' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'remember' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $survey_list as $row ) : ?>
					<?php
					$edit_url = add_query_arg(
						array(
							'page'      => 'remember-surveys',
							'survey_id' => (int) $row->survey_id,
						),
						admin_url( 'admin.php' )
					);
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'          => 'remember-surveys',
								'delete_survey' => (int) $row->survey_id,
							),
							admin_url( 'admin.php' )
						),
						'remember_survey_delete_' . (int) $row->survey_id
					);
					$on_application = Remember_Surveys::PLACEMENT_APPLICATION === $row->placement;
					if ( $on_application ) {
						$status_label = __( 'On the application', 'remember' );
						$status_color = '#2271b1';
					} elseif ( 'issued' === $row->status ) {
						$status_label = __( 'Issued', 'remember' );
						$status_color = '#46b450';
					} else {
						$status_label = __( 'Draft', 'remember' );
						$status_color = '#996800';
					}
					?>
					<tr>
						<td class="column-name" data-label="<?php echo esc_attr__( 'Survey', 'remember' ); ?>">
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row->title ); ?></a></strong>
						</td>
						<td class="column-event" data-label="<?php echo esc_attr__( 'Event', 'remember' ); ?>"><?php echo esc_html( $row->event_name ? $row->event_name : __( '(event removed)', 'remember' ) ); ?></td>
						<td class="column-roles" data-label="<?php echo esc_attr__( 'Roles', 'remember' ); ?>">
							<?php echo esc_html( $row->role_names ? $row->role_names : __( 'All roles', 'remember' ) ); ?>
						</td>
						<td class="column-when" data-label="<?php echo esc_attr__( 'When', 'remember' ); ?>">
							<?php
							if ( $on_application ) {
								echo '—';
							} else {
								echo esc_html( 'before' === $row->timing ? __( 'Before the event', 'remember' ) : __( 'After the event', 'remember' ) );
							}
							?>
						</td>
						<td class="column-questions" data-label="<?php echo esc_attr__( 'Questions', 'remember' ); ?>">
							<span class="remember-survey-list-meta-label"><?php esc_html_e( 'Questions', 'remember' ); ?></span>
							<?php echo esc_html( (string) $row->question_count ); ?>
						</td>
						<td class="column-status" data-label="<?php echo esc_attr__( 'Status', 'remember' ); ?>">
							<span style="color: <?php echo esc_attr( $status_color ); ?>; font-weight: bold;"><?php echo esc_html( $status_label ); ?></span>
						</td>
						<td class="column-actions" data-label="<?php echo esc_attr__( 'Actions', 'remember' ); ?>">
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'remember' ); ?></a>
							<?php if ( $can_edit ) : ?>
								| <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'remember-surveys', 'copy_survey' => (int) $row->survey_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Copy', 'remember' ); ?></a>
								| <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this survey and its questions?', 'remember' ) ); ?>');"><?php esc_html_e( 'Delete', 'remember' ); ?></a>
								<?php if ( ! $on_application && 'issued' !== $row->status ) : ?>
									|
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'remember_survey_admin', 'remember_survey_nonce' ); ?>
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $row->event_id ); ?>">
										<input type="hidden" name="survey_id" value="<?php echo esc_attr( (string) $row->survey_id ); ?>">
										<input type="hidden" name="remember_survey_action" value="issue">
										<button type="submit" class="button-link"><?php esc_html_e( 'Issue', 'remember' ); ?></button>
									</form>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p class="description" style="margin-top: 15px;">
			<?php
			echo esc_html(
				sprintf(
					_n( 'Showing %d survey', 'Showing %d surveys', count( $survey_list ), 'remember' ),
					count( $survey_list )
				)
			);
			?>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'No surveys yet.', 'remember' ); ?></p>
	<?php endif; ?>
<?php endif; ?>
</div>
<script>
(function () {
	var gateMeta = <?php echo wp_json_encode( isset( $GLOBALS['remember_survey_gate_meta'] ) ? $GLOBALS['remember_survey_gate_meta'] : array() ); ?>;
	function slugify(text) {
		return String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').substring(0, 64);
	}
	function toggleOptions() {
		var sel = document.getElementById('field_type');
		if (!sel) return;
		var show = sel.value === 'select' || sel.value === 'multiselect';
		document.querySelectorAll('.remember-pq-options-row').forEach(function (row) {
			row.style.display = show ? '' : 'none';
		});
	}
	function toggleWhen() {
		var wrap = document.querySelector('.remember-pq-when-wrap');
		if (!wrap) return;
		var checked = document.querySelector('input[name="required_mode"]:checked');
		wrap.style.display = (checked && checked.value === 'when') ? '' : 'none';
	}
	function renderWhenValues(fieldKey) {
		var box = document.getElementById('remember-pq-when-values');
		if (!box) return;
		var meta = gateMeta[fieldKey];
		if (!meta || !meta.options || !meta.options.length) {
			box.innerHTML = '<p class="description"><?php echo esc_js( __( 'Select a field to choose which answers make this required.', 'remember' ) ); ?></p>';
			return;
		}
		var html = '<p class="description" style="margin-bottom:6px;"><?php echo esc_js( __( 'Any of these choices (member must answer this field):', 'remember' ) ); ?></p>';
		meta.options.forEach(function (opt) {
			html += '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="required_when_values[]" value="' + String(opt.key).replace(/"/g, '&quot;') + '"> ' + String(opt.label || opt.key).replace(/</g, '&lt;') + '</label>';
		});
		box.innerHTML = html;
	}
	function bindChoiceRow(row) {
		var labelInput = row.querySelector('.remember-pq-choice-label');
		var keyInput = row.querySelector('.remember-pq-choice-key');
		if (!labelInput || !keyInput) return;
		var keyTouched = keyInput.value !== '';
		keyInput.addEventListener('input', function () { keyTouched = keyInput.value !== ''; });
		labelInput.addEventListener('input', function () {
			if (!keyTouched) keyInput.value = slugify(labelInput.value);
		});
		var removeBtn = row.querySelector('.remember-pq-remove-choice');
		if (removeBtn) {
			removeBtn.addEventListener('click', function () {
				var list = row.parentNode;
				if (list && list.querySelectorAll('.remember-pq-choice-row').length > 1) {
					row.remove();
				} else {
					labelInput.value = '';
					keyInput.value = '';
					keyTouched = false;
				}
			});
		}
	}
	document.addEventListener('DOMContentLoaded', function () {
		var sel = document.getElementById('field_type');
		var label = document.getElementById('label');
		var key = document.getElementById('field_key');
		var keyTouched = <?php echo ! empty( $GLOBALS['remember_survey_key_touched'] ) ? 'true' : 'false'; ?>;
		if (sel) {
			sel.addEventListener('change', toggleOptions);
			toggleOptions();
		}
		if (key) key.addEventListener('input', function () { keyTouched = true; });
		if (label && key) {
			label.addEventListener('input', function () {
				if (!keyTouched) key.value = slugify(label.value);
			});
		}
		document.querySelectorAll('.remember-pq-choice-row').forEach(bindChoiceRow);
		var addBtn = document.getElementById('remember-pq-add-choice');
		var list = document.querySelector('#remember-pq-choices .remember-pq-choices-list');
		if (addBtn && list) {
			addBtn.addEventListener('click', function () {
				var first = list.querySelector('.remember-pq-choice-row');
				if (!first) return;
				var clone = first.cloneNode(true);
				clone.querySelectorAll('input').forEach(function (input) { input.value = ''; });
				list.appendChild(clone);
				bindChoiceRow(clone);
			});
		}
		document.querySelectorAll('input[name="required_mode"]').forEach(function (radio) {
			radio.addEventListener('change', toggleWhen);
		});
		toggleWhen();
		var onApplication = document.getElementById('survey_on_application');
		var timingRow = document.getElementById('remember-survey-timing');
		var eventField = document.getElementById('survey_event_id');
		var takenNote = document.getElementById('remember-survey-role-taken');
		var slotsNode = document.getElementById('remember-survey-application-slots');
		var slots = {};
		if (slotsNode) {
			try {
				slots = JSON.parse(slotsNode.textContent || '{}') || {};
			} catch (error) {
				slots = {};
			}
		}
		function surveyId() {
			var input = document.querySelector('input[name="survey_id"]');
			return input ? String(input.value || '0') : '0';
		}
		function slotTaken() {
			if (!eventField) return false;
			var taken = slots[String(eventField.value || '0')];
			return !!taken && String(taken) !== surveyId();
		}
		function syncApplicationSlot() {
			if (!onApplication) return;
			var taken = slotTaken();
			onApplication.disabled = taken;
			if (taken) onApplication.checked = false;
			if (takenNote) {
				if (taken) {
					takenNote.hidden = false;
					takenNote.textContent = <?php echo wp_json_encode( Remember_Surveys::application_taken_message() ); ?>;
				} else {
					takenNote.hidden = true;
					takenNote.textContent = '';
				}
			}
			if (timingRow) {
				if (onApplication.checked) {
					timingRow.setAttribute('hidden', 'hidden');
				} else {
					timingRow.removeAttribute('hidden');
				}
			}
		}
		if (onApplication && timingRow) {
			onApplication.addEventListener('change', syncApplicationSlot);
		}
		if (eventField) eventField.addEventListener('change', syncApplicationSlot);
		syncApplicationSlot();
		var whenField = document.getElementById('required_when_field');
		if (whenField) {
			whenField.addEventListener('change', function () { renderWhenValues(whenField.value); });
		}
	});
})();
</script>
