<?php
/**
 * Profile custom questions — collect, validate, save, render.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Helpers for admin-defined profile questions.
 */
class Remember_Profile_Questions {

	/**
	 * Opted-in custom fields that sit beside the event-sheet photo.
	 *
	 * Remaining event-card rows continue in two columns under the photo row.
	 */
	const EVENT_SHEET_HERO_FIELD_COUNT = 4;

	/**
	 * Allowed field types.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_types() {
		return array( 'text', 'select', 'multiselect' );
	}

	/**
	 * Whether the type uses a defined choice list.
	 *
	 * @param string $field_type Type.
	 * @return bool
	 */
	public static function type_uses_options( $field_type ) {
		return in_array( (string) $field_type, array( 'select', 'multiselect' ), true );
	}

	/**
	 * Parse required_when_json into a normalized rule, or null if unset/invalid.
	 *
	 * Stored shape: { "field_key": "preferred_role", "values": ["role_a", "role_b"] }
	 * Also accepts legacy { "value": "role_a" } and { "any_of": [ { "field_key", "value"|"values" } ] }.
	 *
	 * @param mixed $json JSON string, array, or null.
	 * @return array{field_key:string,values:array<int,string>}|null
	 */
	public static function parse_required_when( $json ) {
		if ( null === $json || '' === $json ) {
			return null;
		}
		$data = is_array( $json ) ? $json : json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$field_key = isset( $data['field_key'] ) ? self::sanitize_field_key( (string) $data['field_key'] ) : '';
		$values    = array();

		if ( ! empty( $data['values'] ) && is_array( $data['values'] ) ) {
			foreach ( $data['values'] as $item ) {
				$key = self::sanitize_field_key( (string) $item );
				if ( '' !== $key ) {
					$values[] = $key;
				}
			}
		} elseif ( isset( $data['value'] ) && '' !== (string) $data['value'] ) {
			$key = self::sanitize_field_key( (string) $data['value'] );
			if ( '' !== $key ) {
				$values[] = $key;
			}
		}

		if ( ( '' === $field_key || empty( $values ) ) && ! empty( $data['any_of'] ) && is_array( $data['any_of'] ) ) {
			foreach ( $data['any_of'] as $clause ) {
				if ( ! is_array( $clause ) ) {
					continue;
				}
				if ( '' === $field_key && isset( $clause['field_key'] ) ) {
					$field_key = self::sanitize_field_key( (string) $clause['field_key'] );
				}
				if ( isset( $clause['value'] ) && '' !== (string) $clause['value'] ) {
					$key = self::sanitize_field_key( (string) $clause['value'] );
					if ( '' !== $key ) {
						$values[] = $key;
					}
				}
				if ( ! empty( $clause['values'] ) && is_array( $clause['values'] ) ) {
					foreach ( $clause['values'] as $item ) {
						$key = self::sanitize_field_key( (string) $item );
						if ( '' !== $key ) {
							$values[] = $key;
						}
					}
				}
			}
		}

		$values = array_values( array_unique( $values ) );
		if ( '' === $field_key || empty( $values ) ) {
			return null;
		}

		return array(
			'field_key' => $field_key,
			'values'    => $values,
		);
	}

	/**
	 * Encode a required-when rule for storage.
	 *
	 * @param string               $field_key Gate field short name.
	 * @param array<int, string>   $values    Choice keys that trigger requirement.
	 * @return string|null JSON or null if invalid.
	 */
	public static function encode_required_when( $field_key, $values ) {
		$rule = self::parse_required_when(
			array(
				'field_key' => $field_key,
				'values'    => is_array( $values ) ? $values : array(),
			)
		);
		if ( null === $rule ) {
			return null;
		}
		return wp_json_encode( $rule );
	}

	/**
	 * Whether a required-when rule matches current answers (by question_id).
	 *
	 * If the gate field itself is conditionally inactive, this returns false.
	 *
	 * @param array{field_key:string,values:array<int,string>} $rule    Rule.
	 * @param array<int, string>                               $answers question_id => value.
	 * @param int                                              $depth   Recursion guard.
	 * @return bool
	 */
	public static function required_when_matches( $rule, $answers, $depth = 0 ) {
		if ( $depth > 10 || ! is_array( $rule ) || empty( $rule['field_key'] ) || empty( $rule['values'] ) ) {
			return false;
		}
		$gate = self::question_model()->get_by_field_key( $rule['field_key'] );
		if ( ! $gate || empty( $gate->is_active ) ) {
			return false;
		}
		$gate_rule = self::parse_required_when( isset( $gate->required_when_json ) ? $gate->required_when_json : null );
		if ( null !== $gate_rule && ! self::required_when_matches( $gate_rule, $answers, $depth + 1 ) ) {
			return false;
		}
		$qid = (int) $gate->question_id;
		$val = isset( $answers[ $qid ] ) ? (string) $answers[ $qid ] : '';
		if ( 'multiselect' === $gate->field_type ) {
			$picked = self::decode_multi_keys( $val );
			return ! empty( array_intersect( $picked, $rule['values'] ) );
		}
		$val = trim( $val );
		return '' !== $val && in_array( $val, $rule['values'], true );
	}

	/**
	 * Whether this question must be answered given current answers.
	 *
	 * Conditional rules take precedence: when required_when is set, the field is
	 * required only if the gate matches (is_required is ignored for enforcement).
	 *
	 * @param object             $q       Question row.
	 * @param array<int, string> $answers question_id => value.
	 * @return bool
	 */
	public static function is_effectively_required( $q, $answers ) {
		if ( ! is_object( $q ) ) {
			return false;
		}
		$rule = self::parse_required_when( isset( $q->required_when_json ) ? $q->required_when_json : null );
		if ( null !== $rule ) {
			return self::required_when_matches( $rule, $answers );
		}
		return ! empty( $q->is_required );
	}

	/**
	 * Decode a multiselect stored value to option keys.
	 *
	 * Accepts JSON array, pipe-separated, or comma-separated keys.
	 *
	 * @param mixed $value Stored or imported value.
	 * @return array<int, string>
	 */
	public static function decode_multi_keys( $value ) {
		if ( is_array( $value ) ) {
			$raw = $value;
		} else {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return array();
			}
			if ( '[' === substr( $value, 0, 1 ) ) {
				$decoded = json_decode( $value, true );
				$raw     = is_array( $decoded ) ? $decoded : array();
			} elseif ( false !== strpos( $value, '|' ) ) {
				$raw = explode( '|', $value );
			} elseif ( false !== strpos( $value, ',' ) ) {
				$raw = explode( ',', $value );
			} else {
				$raw = array( $value );
			}
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $item ) {
			$key = self::sanitize_field_key( (string) $item );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $key;
		}
		return $out;
	}

	/**
	 * Encode multiselect keys for storage (JSON array).
	 *
	 * @param array<int, string> $keys Option keys.
	 * @return string Empty string when none.
	 */
	public static function encode_multi_keys( $keys ) {
		$keys = self::decode_multi_keys( $keys );
		if ( empty( $keys ) ) {
			return '';
		}
		return wp_json_encode( array_values( $keys ) );
	}

	/**
	 * Normalize export field key (CSV header / stable id).
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_field_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$key = trim( (string) $key, '_' );
		return substr( $key, 0, 64 );
	}

	/**
	 * Parse options JSON to list of key/label pairs.
	 *
	 * @param mixed $json JSON string or array.
	 * @return array<int, array{key:string,label:string}>
	 */
	public static function parse_options( $json ) {
		if ( is_array( $json ) ) {
			$decoded = $json;
		} else {
			$decoded = json_decode( (string) $json, true );
		}
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$opt_key = isset( $row['key'] ) ? self::sanitize_field_key( $row['key'] ) : '';
			if ( '' === $opt_key && isset( $row['value'] ) ) {
				$opt_key = self::sanitize_field_key( $row['value'] );
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			if ( '' === $label && isset( $row['name'] ) ) {
				$label = sanitize_text_field( (string) $row['name'] );
			}
			if ( '' === $opt_key ) {
				continue;
			}
			if ( '' === $label ) {
				$label = $opt_key;
			}
			$out[] = array(
				'key'   => $opt_key,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Encode options for storage.
	 *
	 * @param array $options Options list.
	 * @return string|null
	 */
	public static function encode_options( $options ) {
		$parsed = self::parse_options( $options );
		if ( empty( $parsed ) ) {
			return null;
		}
		return wp_json_encode( $parsed );
	}

	/**
	 * Suggest a short spreadsheet name from question text.
	 *
	 * @param string $label Question text.
	 * @return string
	 */
	public static function suggest_field_key_from_label( $label ) {
		$key = self::sanitize_field_key( $label );
		if ( '' !== $key ) {
			return $key;
		}
		return 'custom_field';
	}

	/**
	 * Build options from admin choices text.
	 *
	 * Accepts plain labels one per line, or comma-separated on a line
	 * (e.g. "Vanilla, Chocolate"). Optional advanced form key|Label still works.
	 *
	 * @param string $raw Textarea.
	 * @return array<int, array{key:string,label:string}>
	 */
	public static function options_from_textarea( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		$chunks = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $chunks ) ) {
			return array();
		}

		$pieces = array();
		foreach ( $chunks as $chunk ) {
			$chunk = trim( (string) $chunk );
			if ( '' === $chunk ) {
				continue;
			}
			// Comma-separated list on one line (only when not using key|label).
			if ( false === strpos( $chunk, '|' ) && false !== strpos( $chunk, ',' ) ) {
				foreach ( explode( ',', $chunk ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part ) {
						$pieces[] = $part;
					}
				}
			} else {
				$pieces[] = $chunk;
			}
		}

		$out  = array();
		$seen = array();
		foreach ( $pieces as $piece ) {
			if ( false !== strpos( $piece, '|' ) ) {
				$parts = explode( '|', $piece, 2 );
				$key   = self::sanitize_field_key( $parts[0] );
				$label = sanitize_text_field( trim( $parts[1] ) );
			} else {
				$label = sanitize_text_field( $piece );
				$key   = self::sanitize_field_key( $label );
			}
			if ( '' === $key ) {
				continue;
			}
			if ( '' === $label ) {
				$label = $key;
			}
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Options textarea value from stored JSON (member-facing labels).
	 *
	 * @param mixed $json Stored options.
	 * @return string
	 */
	public static function options_to_textarea( $json ) {
		$lines = array();
		foreach ( self::parse_options( $json ) as $opt ) {
			// Prefer the friendly label; keys are regenerated from labels on save when simple.
			$lines[] = $opt['label'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Load question model.
	 *
	 * @return Remember_Profile_Question
	 */
	private static function question_model() {
		require_once plugin_dir_path( __FILE__ ) . '../models/class-profile-question.php';
		return new Remember_Profile_Question();
	}

	/**
	 * Responses table name.
	 *
	 * @return string
	 */
	private static function responses_table() {
		global $wpdb;
		return $wpdb->prefix . 'remember_profile_question_responses';
	}

	/**
	 * Map question_id => value_text for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array<int, string>
	 */
	public static function get_responses_map( $member_id ) {
		global $wpdb;
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT question_id, value_text FROM ' . self::responses_table() . ' WHERE member_id = %d',
				$member_id
			)
		);
		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row->question_id ] = (string) $row->value_text;
			}
		}
		return $map;
	}

	/**
	 * Map field_key => stored value for a member (export / display).
	 *
	 * @param int $member_id Member ID.
	 * @return array<string, string>
	 */
	public static function get_responses_by_field_key( $member_id ) {
		$model = self::question_model();
		$qs    = $model->get_all_ordered();
		$map   = self::get_responses_map( $member_id );
		$out   = array();
		foreach ( $qs as $q ) {
			$qid    = (int) $q->question_id;
			$stored = isset( $map[ $qid ] ) ? $map[ $qid ] : '';
			if ( 'multiselect' === $q->field_type ) {
				$out[ (string) $q->field_key ] = implode( '|', self::decode_multi_keys( $stored ) );
			} else {
				$out[ (string) $q->field_key ] = $stored;
			}
		}
		return $out;
	}

	/**
	 * Collect answers from request for active questions.
	 *
	 * @return array<int, string> question_id => value
	 */
	public static function collect_from_request() {
		$model = self::question_model();
		$qs    = $model->get_active();
		$out   = array();
		foreach ( $qs as $q ) {
			$qid  = (int) $q->question_id;
			$name = 'remember_pq_' . $qid;
			$raw  = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'multiselect' === $q->field_type ) {
				$keys = array();
				if ( is_array( $raw ) ) {
					foreach ( $raw as $item ) {
						$keys[] = sanitize_text_field( (string) $item );
					}
				} elseif ( is_string( $raw ) && '' !== $raw ) {
					$keys[] = sanitize_text_field( $raw );
				}
				$out[ $qid ] = self::encode_multi_keys( $keys );
			} else {
				if ( is_array( $raw ) ) {
					$raw = '';
				}
				$out[ $qid ] = sanitize_text_field( (string) $raw );
			}
		}
		return $out;
	}

	/**
	 * First missing required custom answer, or null.
	 *
	 * Honors always-required and required_when conditional rules.
	 *
	 * @param array<int, string>|null $answers From collect_from_request().
	 * @return string|null Question label if missing.
	 */
	public static function first_missing_required( $answers = null ) {
		if ( null === $answers ) {
			$answers = self::collect_from_request();
		}
		$model = self::question_model();
		foreach ( $model->get_active() as $q ) {
			if ( ! self::is_effectively_required( $q, $answers ) ) {
				continue;
			}
			$qid = (int) $q->question_id;
			$val = isset( $answers[ $qid ] ) ? trim( (string) $answers[ $qid ] ) : '';
			if ( 'multiselect' === $q->field_type ) {
				if ( empty( self::decode_multi_keys( $val ) ) ) {
					return (string) $q->label;
				}
				continue;
			}
			if ( '' === $val ) {
				return (string) $q->label;
			}
			if ( 'select' === $q->field_type ) {
				$keys = wp_list_pluck( self::parse_options( $q->options_json ), 'key' );
				if ( ! in_array( $val, $keys, true ) ) {
					return (string) $q->label;
				}
			}
		}
		return null;
	}

	/**
	 * Validate select/multiselect keys; strip invalid. Returns cleaned answers.
	 *
	 * @param array<int, string> $answers Answers.
	 * @return array<int, string>
	 */
	public static function sanitize_answers( $answers ) {
		$model = self::question_model();
		$clean = array();
		foreach ( $model->get_active() as $q ) {
			$qid = (int) $q->question_id;
			$val = isset( $answers[ $qid ] ) ? trim( (string) $answers[ $qid ] ) : '';
			if ( 'select' === $q->field_type ) {
				$keys = wp_list_pluck( self::parse_options( $q->options_json ), 'key' );
				if ( '' !== $val && ! in_array( $val, $keys, true ) ) {
					$val = '';
				}
			} elseif ( 'multiselect' === $q->field_type ) {
				$allowed = wp_list_pluck( self::parse_options( $q->options_json ), 'key' );
				$picked  = array();
				foreach ( self::decode_multi_keys( $val ) as $key ) {
					if ( in_array( $key, $allowed, true ) ) {
						$picked[] = $key;
					}
				}
				$val = self::encode_multi_keys( $picked );
			}
			$clean[ $qid ] = $val;
		}
		return $clean;
	}

	/**
	 * Persist answers for a member (active questions only).
	 *
	 * @param int                $member_id Member ID.
	 * @param array<int, string> $answers   question_id => value.
	 * @return void
	 */
	public static function save_for_member( $member_id, $answers ) {
		global $wpdb;
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return;
		}
		$answers = self::sanitize_answers( $answers );
		$table   = self::responses_table();
		$now     = current_time( 'mysql' );

		foreach ( $answers as $question_id => $value ) {
			$question_id = absint( $question_id );
			$existing    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT response_id FROM {$table} WHERE question_id = %d AND member_id = %d",
					$question_id,
					$member_id
				)
			);
			if ( $existing ) {
				$wpdb->update(
					$table,
					array(
						'value_text' => $value,
						'updated_at' => $now,
					),
					array( 'response_id' => (int) $existing ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'question_id' => $question_id,
						'member_id'   => $member_id,
						'value_text'  => $value,
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%d', '%d', '%s', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Human label(s) for a stored select/multiselect value.
	 *
	 * @param object $question Question row.
	 * @param string $value    Stored key or JSON keys.
	 * @return string
	 */
	public static function display_value( $question, $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( ! is_object( $question ) ) {
			return $value;
		}
		if ( 'select' === $question->field_type ) {
			foreach ( self::parse_options( $question->options_json ) as $opt ) {
				if ( $opt['key'] === $value ) {
					return $opt['label'];
				}
			}
			return $value;
		}
		if ( 'multiselect' === $question->field_type ) {
			$labels_by_key = array();
			foreach ( self::parse_options( $question->options_json ) as $opt ) {
				$labels_by_key[ $opt['key'] ] = $opt['label'];
			}
			$labels = array();
			foreach ( self::decode_multi_keys( $value ) as $key ) {
				$labels[] = isset( $labels_by_key[ $key ] ) ? $labels_by_key[ $key ] : $key;
			}
			return implode( ', ', $labels );
		}
		return $value;
	}

	/**
	 * Render form fields HTML for active questions.
	 *
	 * @param int    $member_id Member ID (0 on register).
	 * @param string $context   admin|front|register.
	 * @return void
	 */
	public static function render_fields( $member_id = 0, $context = 'front', $overrides = null ) {
		$model = self::question_model();
		$qs    = $model->get_active();
		if ( empty( $qs ) ) {
			return;
		}
		// $overrides (qid => stored-value map, e.g. from collect_from_request()) repopulates
		// the fields after a failed save so the member's answers are not lost.
		$responses   = is_array( $overrides ) ? $overrides : ( $member_id > 0 ? self::get_responses_map( $member_id ) : array() );
		$is_admin    = ( 'admin' === $context );
		$is_register = ( 'register' === $context );
		$input_class = $is_admin ? 'regular-text' : ( $is_register ? 'remember-register-input' : 'remember-form-control' );

		if ( $is_admin ) {
			echo '<tr><th colspan="2"><h3 style="margin:1em 0 0.5em;">' . esc_html__( 'Custom fields', 'remember' ) . '</h3></th></tr>';
		} elseif ( $is_register ) {
			echo '<h3 class="remember-register-section-title">' . esc_html__( 'Additional questions', 'remember' ) . '</h3>';
		} else {
			echo '<div class="remember-form-section"><h3 class="remember-form-section-title">' . esc_html__( 'Additional questions', 'remember' ) . '</h3>';
		}

		// Map stored responses to answers for conditional required evaluation on profile edit.
		$answer_map = $responses;
		foreach ( $qs as $q ) {
			$qid   = (int) $q->question_id;
			$name  = 'remember_pq_' . $qid;
			$id    = $name;
			$value = isset( $responses[ $qid ] ) ? $responses[ $qid ] : '';
			$rule  = self::parse_required_when( isset( $q->required_when_json ) ? $q->required_when_json : null );
			// Admin member edit does not enforce custom-field required flags.
			$req   = ! $is_admin && self::is_effectively_required( $q, $answer_map );
			$always_required = ! $is_admin && null === $rule && ! empty( $q->is_required );
			$label = (string) $q->label;

			$is_multi = ( 'multiselect' === $q->field_type );
			$multi_selected = $is_multi ? self::decode_multi_keys( $value ) : array();

			$wrap_attrs  = ' class="remember-pq-field"';
			$wrap_attrs .= ' data-remember-pq-id="' . esc_attr( (string) $qid ) . '"';
			$wrap_attrs .= ' data-remember-pq-key="' . esc_attr( (string) $q->field_key ) . '"';
			$wrap_attrs .= ' data-remember-pq-type="' . esc_attr( (string) $q->field_type ) . '"';
			if ( $always_required ) {
				$wrap_attrs .= ' data-remember-pq-always-required="1"';
			}
			if ( $rule && ! $is_admin ) {
				$wrap_attrs .= ' data-remember-pq-when="' . esc_attr( wp_json_encode( $rule ) ) . '"';
			}
			$hidden = ( $rule && ! $is_admin && ! $req );

			if ( $is_admin ) {
				echo '<tr' . $wrap_attrs . '><th scope="row">';
				if ( $is_multi ) {
					echo '<span>' . esc_html( $label );
					if ( $req ) {
						echo ' <span class="description remember-pq-req-mark" style="color:#b32d2e;">*</span>';
					}
					echo '</span>';
				} else {
					echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label );
					if ( $req ) {
						echo ' <span class="description remember-pq-req-mark" style="color:#b32d2e;">*</span>';
					}
					echo '</label>';
				}
				echo '</th><td>';
			} elseif ( $is_register ) {
				// Stacked rows: custom questions are long; two-column register layout collides.
				echo '<div' . $wrap_attrs . ( $hidden ? ' hidden' : '' ) . '>';
				echo '<div class="remember-register-row remember-register-row--stack">';
				echo '<label' . ( $is_multi ? '' : ' for="' . esc_attr( $id ) . '"' ) . '>' . esc_html( $label );
				echo ' <span class="required remember-pq-req-mark"' . ( $req ? '' : ' hidden' ) . '>*</span>';
				echo '</label>';
			} else {
				echo '<div' . $wrap_attrs . ( $hidden ? ' hidden' : '' ) . '>';
				echo '<div class="remember-form-row"><div class="remember-form-col remember-form-col-full">';
				echo '<label' . ( $is_multi ? ' class="remember-form-label"' : ' for="' . esc_attr( $id ) . '" class="remember-form-label"' ) . '>' . esc_html( $label );
				echo ' <span class="remember-required remember-pq-req-mark"' . ( $req ? '' : ' hidden' ) . '>*</span>';
				echo '</label>';
			}

			if ( 'select' === $q->field_type ) {
				echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" class="' . esc_attr( $input_class ) . '"' . ( $req ? ' required' : '' ) . '>';
				echo '<option value="">' . esc_html__( '— Select —', 'remember' ) . '</option>';
				foreach ( self::parse_options( $q->options_json ) as $opt ) {
					echo '<option value="' . esc_attr( $opt['key'] ) . '" ' . selected( $value, $opt['key'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				echo '</select>';
			} elseif ( $is_multi ) {
				if ( $is_admin ) {
					$box_class = 'remember-pq-checkboxes';
				} elseif ( $is_register ) {
					$box_class = 'remember-register-checkboxes';
				} else {
					$box_class = 'remember-pq-checkboxes remember-checkbox-grid';
				}
				echo '<div class="' . esc_attr( $box_class ) . '" role="group" aria-label="' . esc_attr( $label ) . '"' . ( $req ? ' data-remember-pq-require-one="1"' : '' ) . '>';
				foreach ( self::parse_options( $q->options_json ) as $opt_i => $opt ) {
					$cid = $id . '_' . $opt_i;
					echo '<label class="remember-checkbox-label" for="' . esc_attr( $cid ) . '">';
					echo '<input type="checkbox" name="' . esc_attr( $name ) . '[]" id="' . esc_attr( $cid ) . '" value="' . esc_attr( $opt['key'] ) . '" ' . checked( in_array( $opt['key'], $multi_selected, true ), true, false ) . '>';
					echo ' <span>' . esc_html( $opt['label'] ) . '</span>';
					echo '</label>';
				}
				echo '</div>';
			} else {
				echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" class="' . esc_attr( $input_class ) . '"' . ( $req ? ' required' : '' ) . '>';
			}

			if ( $is_admin ) {
				echo '<p class="description">' . esc_html__( 'Short name:', 'remember' ) . ' <code>' . esc_html( $q->field_key ) . '</code></p>';
				echo '</td></tr>';
			} elseif ( $is_register ) {
				echo '</div></div>';
			} else {
				echo '</div></div></div>';
			}
		}

		if ( ! $is_admin && ! $is_register ) {
			echo '</div>';
		}
	}

	/**
	 * Render read-only custom field rows for member detail (table only; caller wraps the section).
	 *
	 * @param int $member_id Member ID.
	 * @return bool True if any rows were rendered.
	 */
	public static function render_detail_rows( $member_id ) {
		$rows = self::detail_rows_for( $member_id );
		if ( empty( $rows ) ) {
			return false;
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		$hero_left = self::EVENT_SHEET_HERO_FIELD_COUNT;
		foreach ( $rows as $row ) {
			$q       = $row['question'];
			$display = self::display_value( $q, $row['value'] );
			// The event-card printout keeps only rows an admin has opted in.
			$classes = array( 'remember-pq-row' );
			if ( ! empty( $q->show_on_event_card ) ) {
				$classes[] = 'remember-pq-row--event';
				if ( $hero_left > 0 ) {
					$classes[] = 'remember-pq-row--event-hero';
					--$hero_left;
				}
			}
			echo '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '"><th scope="row">' . esc_html( $q->label ) . '</th><td>';
			echo '' !== $display ? esc_html( $display ) : '<span class="description">—</span>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		return true;
	}

	/**
	 * Opted-in custom-field rows for the event sheet, in display order.
	 *
	 * @param int $member_id Member ID.
	 * @return array<int, array{question:object,value:string}>
	 */
	public static function event_card_rows( $member_id ) {
		$out = array();
		foreach ( self::detail_rows_for( $member_id ) as $row ) {
			if ( ! empty( $row['question']->show_on_event_card ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Render the first few event-card fields beside the event-sheet photo.
	 *
	 * @param int $member_id Member ID.
	 * @return int Number of rows rendered.
	 */
	public static function render_event_sheet_hero_rows( $member_id ) {
		$hero = array_slice( self::event_card_rows( $member_id ), 0, self::EVENT_SHEET_HERO_FIELD_COUNT );
		if ( empty( $hero ) ) {
			return 0;
		}
		echo '<table class="form-table remember-event-sheet-fields" role="presentation"><tbody>';
		foreach ( $hero as $row ) {
			$q       = $row['question'];
			$display = self::display_value( $q, $row['value'] );
			echo '<tr><th scope="row">' . esc_html( $q->label ) . '</th><td>';
			echo '' !== $display ? esc_html( $display ) : '<span class="description">—</span>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		return count( $hero );
	}

	/**
	 * Render opted-in custom fields that continue below the event-sheet photo row.
	 *
	 * @param int $member_id Member ID.
	 * @return int Number of fields rendered.
	 */
	public static function render_event_sheet_continuation_rows( $member_id ) {
		$rest = array_slice( self::event_card_rows( $member_id ), self::EVENT_SHEET_HERO_FIELD_COUNT );
		if ( empty( $rest ) ) {
			return 0;
		}
		echo '<div class="remember-print-event-only remember-event-sheet-more">';
		foreach ( $rest as $row ) {
			$q       = $row['question'];
			$display = self::display_value( $q, $row['value'] );
			echo '<div class="remember-event-sheet-field">';
			echo '<div class="remember-event-sheet-field__label">' . esc_html( $q->label ) . '</div>';
			echo '<div class="remember-event-sheet-field__value">';
			echo '' !== $display ? esc_html( $display ) : '<span class="description">—</span>';
			echo '</div></div>';
		}
		echo '</div>';
		return count( $rest );
	}

	/**
	 * Whether any of this member's custom answers are cleared for the event card.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public static function has_event_card_rows( $member_id ) {
		return ! empty( self::event_card_rows( $member_id ) );
	}

	/**
	 * Questions worth showing on a member's detail view, paired with their answers.
	 *
	 * A retired question stays visible only while the member still has an answer on file.
	 *
	 * @param int $member_id Member ID.
	 * @return array<int, array{question:object,value:string}>
	 */
	private static function detail_rows_for( $member_id ) {
		$qs = self::question_model()->get_all_ordered();
		if ( empty( $qs ) ) {
			return array();
		}
		$map  = self::get_responses_map( $member_id );
		$rows = array();
		foreach ( $qs as $q ) {
			$qid = (int) $q->question_id;
			$val = isset( $map[ $qid ] ) ? $map[ $qid ] : '';
			if ( '' === $val && empty( $q->is_active ) ) {
				continue;
			}
			$rows[] = array(
				'question' => $q,
				'value'    => $val,
			);
		}
		return $rows;
	}

	/**
	 * Field keys for CSV headers (all questions, stable order).
	 *
	 * @return array<int, string>
	 */
	public static function export_field_keys() {
		$model = self::question_model();
		$keys  = array();
		foreach ( $model->get_all_ordered() as $q ) {
			$keys[] = (string) $q->field_key;
		}
		return $keys;
	}

	/**
	 * Active custom field short names (CSV headers for event participant export).
	 *
	 * @return string[]
	 */
	public static function active_export_field_keys() {
		$model = self::question_model();
		$keys  = array();
		foreach ( $model->get_active() as $q ) {
			$keys[] = (string) $q->field_key;
		}
		return $keys;
	}

	/**
	 * Set one answer by field_key (import).
	 *
	 * @param int    $member_id Member ID.
	 * @param string $field_key Export key.
	 * @param string $value     Value (select key, multiselect keys, or text).
	 * @return bool
	 */
	public static function save_by_field_key( $member_id, $field_key, $value ) {
		$model = self::question_model();
		$q     = $model->get_by_field_key( $field_key );
		if ( ! $q ) {
			return false;
		}
		// Allow saving inactive questions on import; bypass active-only sanitize for that id.
		global $wpdb;
		$member_id   = absint( $member_id );
		$question_id = (int) $q->question_id;
		$value       = is_string( $value ) ? trim( $value ) : '';
		if ( 'select' === $q->field_type ) {
			$value = sanitize_text_field( $value );
			$keys  = wp_list_pluck( self::parse_options( $q->options_json ), 'key' );
			if ( '' !== $value && ! in_array( $value, $keys, true ) ) {
				// Accept label match → store key.
				foreach ( self::parse_options( $q->options_json ) as $opt ) {
					if ( 0 === strcasecmp( $opt['label'], $value ) ) {
						$value = $opt['key'];
						break;
					}
				}
				if ( ! in_array( $value, $keys, true ) ) {
					return false;
				}
			}
		} elseif ( 'multiselect' === $q->field_type ) {
			$allowed = array();
			$by_label = array();
			foreach ( self::parse_options( $q->options_json ) as $opt ) {
				$allowed[] = $opt['key'];
				$by_label[ strtolower( $opt['label'] ) ] = $opt['key'];
			}
			$picked = array();
			foreach ( self::decode_multi_keys( $value ) as $piece ) {
				if ( in_array( $piece, $allowed, true ) ) {
					$picked[] = $piece;
					continue;
				}
				$lk = strtolower( str_replace( '_', ' ', $piece ) );
				if ( isset( $by_label[ strtolower( $piece ) ] ) ) {
					$picked[] = $by_label[ strtolower( $piece ) ];
				} elseif ( isset( $by_label[ $lk ] ) ) {
					$picked[] = $by_label[ $lk ];
				}
			}
			// Also try splitting original by common separators when decode treated whole string as one key.
			if ( empty( $picked ) && '' !== $value && false === strpos( $value, '|' ) && false === strpos( $value, ',' ) && '[' !== substr( $value, 0, 1 ) ) {
				if ( isset( $by_label[ strtolower( $value ) ] ) ) {
					$picked[] = $by_label[ strtolower( $value ) ];
				}
			}
			$value = self::encode_multi_keys( $picked );
		} else {
			$value = sanitize_text_field( $value );
		}
		$table = self::responses_table();
		$now   = current_time( 'mysql' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT response_id FROM {$table} WHERE question_id = %d AND member_id = %d",
				$question_id,
				$member_id
			)
		);
		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'value_text' => $value,
					'updated_at' => $now,
				),
				array( 'response_id' => (int) $existing ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'question_id' => $question_id,
					'member_id'   => $member_id,
					'value_text'  => $value,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
		}
		return true;
	}
}
