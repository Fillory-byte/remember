<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @package    reMember
 * @subpackage reMember/public
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    reMember
 * @subpackage reMember/public
 */
class Remember_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		$css = plugin_dir_path( __FILE__ ) . '../assets/css/public.css';
		$ver = is_readable( $css ) ? (string) filemtime( $css ) : $this->version;
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . '../assets/css/public.css', array(), $ver, 'all' );
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_script(
			$this->plugin_name . '-timezone',
			plugin_dir_url( __FILE__ ) . '../assets/js/timezone-picker.js',
			array( 'jquery' ),
			$this->version,
			true
		);
		$public_js  = plugin_dir_path( __FILE__ ) . '../assets/js/public.js';
		$public_ver = is_readable( $public_js ) ? (string) filemtime( $public_js ) : $this->version;
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . '../assets/js/public.js', array( 'jquery', $this->plugin_name . '-timezone' ), $public_ver, true );
		wp_localize_script( $this->plugin_name, 'rememberPublic', array(
			'ajaxurl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'remember_public_nonce' ),
			'profileCurrencyNonce' => wp_create_nonce( 'remember_profile_currency_status' ),
			'i18n'                 => array(
				'profileStale'     => __( 'Save your profile first (within the last 24 hours), then type the confirmation phrase.', 'remember' ),
				'applyGateTitle'   => __( 'Confirm your profile first', 'remember' ),
				'applyGateBody'    => __( 'Before you apply, you will review your profile and save it. Saving with or without changes is enough — that affirms it is current. After you save, you will return here to finish the application, including typing “my profile is current.”', 'remember' ),
				'applyGateContinue'=> __( 'Review profile', 'remember' ),
				'applyGateCancel'  => __( 'Cancel', 'remember' ),
			),
		) );

		// Interests uses wp_editor on profile edit and public registration.
		global $post;
		if ( $post instanceof WP_Post ) {
			$needs_editor = has_shortcode( $post->post_content, 'remember_register' )
				|| has_shortcode( $post->post_content, 'remember_registration' );
			if ( ! $needs_editor && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view flag only.
				$needs_editor = has_shortcode( $post->post_content, 'remember_profile' );
			}
			if ( $needs_editor ) {
				wp_enqueue_editor();
			}
		}
	}

	/**
	 * Register shortcodes.
	 *
	 * @since    1.0.0
	 */
	public function register_shortcodes() {
		add_shortcode( 'remember_dashboard', array( $this, 'shortcode_dashboard' ) );
		add_shortcode( 'remember_events', array( $this, 'shortcode_events' ) );
		add_shortcode( 'remember_apply', array( $this, 'shortcode_apply' ) );
		add_shortcode( 'remember_survey', array( $this, 'shortcode_survey' ) );
		add_shortcode( 'remember_profile', array( $this, 'shortcode_profile' ) );
		add_shortcode( 'remember_event_directory', array( $this, 'shortcode_event_directory' ) );
		add_shortcode( 'remember_event_detail', array( $this, 'shortcode_event_detail' ) );
		add_shortcode( 'remember_register', array( $this, 'shortcode_register' ) );
		add_shortcode( 'remember_registration', array( $this, 'shortcode_register' ) );
	}

	/**
	 * Output printable admission ticket when ?remember_ticket= is present.
	 *
	 * @return void
	 */
	public function maybe_output_admission_ticket() {
		if ( ! isset( $_GET['remember_ticket'] ) ) {
			return;
		}

		$application_id = absint( $_GET['remember_ticket'] );
		if ( $application_id <= 0 ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$nonce = isset( $_GET['remember_ticket_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['remember_ticket_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'remember_ticket_' . $application_id ) ) {
			wp_die( esc_html__( 'Invalid or expired ticket link. Open the ticket again from your dashboard or the admin application screen.', 'remember' ), esc_html__( 'Ticket', 'remember' ), array( 'response' => 403 ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-ticket.php';
		if ( ! Remember_Ticket::user_can_view( $application_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view this ticket.', 'remember' ), esc_html__( 'Ticket', 'remember' ), array( 'response' => 403 ) );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-ticket-renderer.php';
		$download = isset( $_GET['download'] ) && '1' === (string) $_GET['download'];
		Remember_Ticket_Renderer::output_standalone( $application_id, $download );
		exit;
	}

	/**
	 * Process public member registration POST before output (works when wp-login registration is off).
	 *
	 * @return void
	 */
	public function maybe_process_member_registration() {
		if ( ! isset( $_POST['remember_register_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST['remember_register_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['remember_register_nonce'] ), 'remember_register_member' ) ) {
			$this->redirect_member_registration( 'invalid_nonce' );
		}

		if ( ! apply_filters( 'remember_allow_public_registration', true ) ) {
			$this->redirect_member_registration( 'disabled' );
		}

		// Honeypot — should stay empty (no visible field; blocks naive bots).
		if ( ! empty( $_POST['remember_hp'] ) || ! empty( $_POST['remember_company'] ) ) {
			$this->redirect_member_registration( 'invalid_nonce' );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-registration-guard.php';
		$limited = Remember_Registration_Guard::limit_attempt();
		if ( '' !== $limited ) {
			$this->redirect_member_registration( $limited );
		}

		$username         = isset( $_POST['remember_reg_username'] ) ? sanitize_user( wp_unslash( $_POST['remember_reg_username'] ), true ) : '';
		$email            = isset( $_POST['remember_reg_email'] ) ? sanitize_email( wp_unslash( $_POST['remember_reg_email'] ) ) : '';
		$password         = isset( $_POST['remember_reg_password'] ) ? wp_unslash( $_POST['remember_reg_password'] ) : '';
		$password_confirm = isset( $_POST['remember_reg_password_confirm'] ) ? wp_unslash( $_POST['remember_reg_password_confirm'] ) : '';

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-timezone.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-fields.php';

		$profile_data = Remember_Profile_Fields::collect_profile_data_from_request();
		$meta_data    = Remember_Profile_Fields::collect_meta_from_request();
		$nickname     = $meta_data['nickname'];
		$timezone     = $meta_data['timezone_string'];
		$first_name   = $profile_data['legal_first_name'];
		$last_name    = $profile_data['legal_last_name'];

		if ( '' === $username || '' === $email || '' === $password || '' === $password_confirm ) {
			$this->redirect_member_registration( 'missing_fields' );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-questions.php';
		$pq_answers = Remember_Profile_Questions::collect_from_request();

		$missing = Remember_Profile_Fields::first_missing_required( $profile_data, $meta_data );
		if ( '' !== $missing ) {
			$this->redirect_member_registration( 'missing_fields' );
		}
		if ( null !== Remember_Profile_Questions::first_missing_required( $pq_answers ) ) {
			$this->redirect_member_registration( 'missing_fields' );
		}
		if ( '' !== Remember_Profile_Fields::first_missing_required_health_catalog() ) {
			$this->redirect_member_registration( 'missing_fields' );
		}
		if ( Remember_Profile_Fields::allergy_reaction_is_missing() ) {
			$this->redirect_member_registration( 'allergy_reaction' );
		}
		if ( Remember_Profile_Fields::interests_is_over_limit( $profile_data['interests'] ) ) {
			$this->redirect_member_registration( 'interests_too_long' );
		}

		if ( ! Remember_Timezone::is_valid_timezone( $timezone ) ) {
			$this->redirect_member_registration( 'invalid_timezone' );
		}

		if ( $password !== $password_confirm ) {
			$this->redirect_member_registration( 'password_mismatch' );
		}

		if ( ! is_email( $email ) ) {
			$this->redirect_member_registration( 'invalid_email' );
		}

		if ( ! validate_username( $username ) ) {
			$this->redirect_member_registration( 'invalid_username' );
		}

		$min_len = (int) apply_filters( 'remember_registration_password_min_length', 8 );
		if ( $min_len > 0 && strlen( $password ) < $min_len ) {
			$this->redirect_member_registration( 'weak_password' );
		}

		if ( username_exists( $username ) ) {
			$this->redirect_member_registration( 'username_exists' );
		}

		if ( email_exists( $email ) ) {
			$this->redirect_member_registration( 'email_exists' );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-registration-guard.php';
		$blocked = Remember_Registration_Guard::before_create( $username, $email );
		if ( '' !== $blocked ) {
			$this->redirect_member_registration( $blocked );
		}

		$remember_options     = get_option( 'remember_options', array() );
		$photo_max_dimensions = isset( $remember_options['photo_max_dimensions'] ) ? absint( $remember_options['photo_max_dimensions'] ) : 800;
		$photo_max_bytes      = isset( $remember_options['photo_max_size'] ) ? absint( $remember_options['photo_max_size'] ) : 2097152;
		if ( $photo_max_dimensions < 1 ) {
			$photo_max_dimensions = 800;
		}
		if ( $photo_max_bytes < 1 ) {
			$photo_max_bytes = 2097152;
		}

		$has_photo = ! empty( $_FILES['photo_file']['name'] );
		if ( ! $has_photo ) {
			$this->redirect_member_registration( 'photo_required' );
		}
		$file_size = isset( $_FILES['photo_file']['size'] ) ? absint( $_FILES['photo_file']['size'] ) : 0;
		if ( $file_size > 0 && $file_size > $photo_max_bytes ) {
			$this->redirect_member_registration( 'photo_too_large' );
		}
		$upload_err = isset( $_FILES['photo_file']['error'] ) ? (int) $_FILES['photo_file']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_err ) {
			$this->redirect_member_registration( 'photo_failed' );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-vetting-workflow.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-logger.php';
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-image-uploader.php';
		// wp_delete_user() (used for cleanup below) is admin-only and not loaded on the front end.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$upload_result = Remember_Image_Uploader::upload_square_image( $_FILES['photo_file'], $photo_max_dimensions );
		if ( is_wp_error( $upload_result ) || empty( $upload_result['url'] ) ) {
			Remember_Logger::warning(
				'Public member registration: photo upload failed',
				array(
					'error' => is_wp_error( $upload_result ) ? $upload_result->get_error_message() : '',
				)
			);
			$this->redirect_member_registration( 'photo_failed' );
		}
		$photo_url = $upload_result['url'];

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			Remember_Logger::warning(
				'Public member registration: wp_create_user failed',
				array( 'error' => $user_id->get_error_message() )
			);
			Remember_Image_Uploader::delete_image( $photo_url );
			$this->redirect_member_registration( 'create_failed' );
		}

		update_user_meta( $user_id, 'first_name', $first_name );
		update_user_meta( $user_id, 'last_name', $last_name );
		update_user_meta( $user_id, 'nickname', $nickname );
		update_user_meta( $user_id, 'timezone_string', $timezone );
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $nickname,
				'nickname'     => $nickname,
			)
		);

		$vetting_workflow = Remember_Vetting_Workflow::get_workflow();
		if ( 'first_application' === $vetting_workflow ) {
			$status = 'unvetted';
		} else {
			$status = 'pending_vetting';
		}

		$member_model = new Remember_Member();
		$member_ok    = $member_model->create( $user_id, $status );

		if ( ! $member_ok ) {
			Remember_Logger::error( 'Public member registration: member row failed', array( 'user_id' => $user_id ) );
			Remember_Image_Uploader::delete_image( $photo_url );
			wp_delete_user( $user_id );
			$this->redirect_member_registration( 'member_failed' );
		}

		global $wpdb;
		$profile_row               = $profile_data;
		$profile_row['member_id']  = $user_id;
		$profile_row['created_at'] = current_time( 'mysql' );
		$profile_row['updated_at'] = current_time( 'mysql' );
		$profile_row['updated_by'] = $user_id;

		$profile_inserted = $wpdb->insert( $wpdb->prefix . 'remember_member_profiles', $profile_row );

		if ( ! $profile_inserted ) {
			Remember_Logger::error(
				'Public member registration: profile insert failed',
				array( 'user_id' => $user_id, 'db_error' => $wpdb->last_error )
			);
			Remember_Image_Uploader::delete_image( $photo_url );
			$member_model->delete( $user_id );
			wp_delete_user( $user_id );
			$this->redirect_member_registration( 'profile_failed' );
		}

		$photo_saved = $member_model->update_photo( $user_id, $photo_url );
		if ( false === $photo_saved ) {
			Remember_Logger::error(
				'Public member registration: photo could not be saved',
				array( 'user_id' => $user_id, 'db_error' => $wpdb->last_error )
			);
			Remember_Image_Uploader::delete_image( $photo_url );
			$member_model->delete( $user_id );
			wp_delete_user( $user_id );
			$this->redirect_member_registration( 'photo_failed' );
		}

		Remember_Profile_Fields::save_junctions_from_request( $user_id );
		Remember_Profile_Questions::save_for_member( $user_id, $pq_answers );

		if ( Remember_Vetting_Workflow::should_vet_on_join() ) {
			$vetting_result = Remember_Vetting_Workflow::create_vetting_case( $user_id );
			if ( ! $vetting_result ) {
				Remember_Logger::warning( 'Public member registration: vetting case not created', array( 'member_id' => $user_id ) );
			}
		}

		Remember_Logger::info( 'Public member registration completed', array( 'user_id' => $user_id ) );

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-notifications.php';
		Remember_Notifications::notify_member_registered( $user_id );

		$registered_user = get_user_by( 'id', $user_id );
		if ( $registered_user instanceof WP_User ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true, is_ssl() );
			do_action( 'wp_login', $registered_user->user_login, $registered_user );
		}

		$this->redirect_member_registration( null, true );
	}

	/**
	 * Redirect after registration attempt (GET query args for shortcode messages).
	 *
	 * @param string|null $error_code Error code or null on success.
	 * @param bool        $success    Whether registration succeeded.
	 * @return void
	 */
	private function redirect_member_registration( $error_code, $success = false ) {
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			// The form posts to itself, so WordPress reports no referer. Stay on this page.
			$redirect = remove_query_arg( array( 'remember_registered', 'remember_reg_error' ) );
		}
		$redirect = wp_validate_redirect( $redirect, home_url( '/' ) );

		if ( $success ) {
			wp_safe_redirect( add_query_arg( 'remember_registered', '1', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'remember_reg_error', rawurlencode( (string) $error_code ), $redirect ) );
		exit;
	}

	/**
	 * Map registration error codes to messages.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private function get_member_registration_error_message( $code ) {
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-fields.php';

		$messages = array(
			'invalid_nonce'    => __( 'That submission was invalid or expired. Please try again.', 'remember' ),
			'disabled'         => __( 'New member registration is not available.', 'remember' ),
			'missing_fields'   => __( 'Please fill in all required fields.', 'remember' ),
			'invalid_email'    => __( 'Please enter a valid email address.', 'remember' ),
			'invalid_username' => __( 'That username is not allowed. Please choose another.', 'remember' ),
			'invalid_timezone' => __( 'Please select a valid time zone.', 'remember' ),
			'weak_password'    => __( 'Please choose a stronger password (at least eight characters).', 'remember' ),
			'password_mismatch' => __( 'Password and confirm password do not match.', 'remember' ),
			'username_exists'  => __( 'That username is already taken.', 'remember' ),
			'email_exists'     => __( 'That email address is already registered.', 'remember' ),
			'create_failed'    => __( 'Could not create your account. Please try again or contact support.', 'remember' ),
			'member_failed'    => __( 'Could not complete member setup. Please contact support.', 'remember' ),
			'profile_failed'   => __( 'Could not save your profile. Please contact support.', 'remember' ),
			'photo_required'   => __( 'A profile photo is required to register.', 'remember' ),
			'photo_too_large'  => __( 'That photo is too large. Please choose a smaller image and try again.', 'remember' ),
			'photo_failed'     => __( 'That photo could not be uploaded. Please try a different JPEG, PNG, or GIF.', 'remember' ),
			'interests_too_long' => Remember_Profile_Fields::interests_too_long_message(),
			'allergy_reaction' => __( 'Explain the nature and severity of your reaction to any allergen you selected.', 'remember' ),
			'captcha_failed'   => __( 'The verification check failed. Please try again.', 'remember' ),
			'rate_limited'     => __( 'Too many registration attempts from this network. Please wait an hour and try again.', 'remember' ),
			'disposable_email' => __( 'Please use a permanent email address.', 'remember' ),
			'rejected'         => __( 'Registration could not be completed.', 'remember' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Registration could not be completed.', 'remember' );
	}

	/**
	 * Full member registration shortcode (WP user + reMember member + profile).
	 *
	 * @param array $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function shortcode_register( $atts ) {
		$remember_register_success = isset( $_GET['remember_registered'] ) && '1' === (string) wp_unslash( $_GET['remember_registered'] );
		if ( $remember_register_success ) {
			require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-audit.php';
			$dashboard_url = Remember_Profile_Audit::get_dashboard_url();
			ob_start();
			?>
			<div class="remember-register-splash" data-remember-register-splash="1" data-dashboard-url="<?php echo esc_url( $dashboard_url ); ?>" role="status">
				<h2><?php esc_html_e( 'Your profile was successfully received.', 'remember' ); ?></h2>
				<p><?php esc_html_e( 'Taking you to your dashboard.', 'remember' ); ?></p>
				<p><a href="<?php echo esc_url( $dashboard_url ); ?>"><?php esc_html_e( 'Continue to your dashboard', 'remember' ); ?></a></p>
			</div>
			<?php
			return ob_get_clean();
		}

		if ( is_user_logged_in() ) {
			$created_pages     = get_option( 'remember_created_pages', array() );
			$dashboard_page_id = isset( $created_pages['member_dashboard'] ) ? absint( $created_pages['member_dashboard'] ) : ( isset( $created_pages['dashboard'] ) ? absint( $created_pages['dashboard'] ) : 0 );
			$dash              = $dashboard_page_id ? get_permalink( $dashboard_page_id ) : home_url( '/' );
			return '<p class="remember-notice remember-info">' .
				wp_kses_post(
					sprintf(
						/* translators: %s: Dashboard link */
						__( 'You are already logged in. %s', 'remember' ),
						'<a href="' . esc_url( $dash ) . '">' . esc_html__( 'Go to your dashboard', 'remember' ) . '</a>'
					)
				) .
				'</p>';
		}

		$remember_register_success = isset( $_GET['remember_registered'] ) && '1' === $_GET['remember_registered'];
		$remember_register_error_message = '';

		if ( isset( $_GET['remember_reg_error'] ) ) {
			$code = sanitize_text_field( wp_unslash( $_GET['remember_reg_error'] ) );
			$remember_register_error_message = $this->get_member_registration_error_message( $code );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-registration-guard.php';
		Remember_Registration_Guard::enqueue();

		ob_start();
		include plugin_dir_path( __FILE__ ) . 'partials/remember-register.php';
		return ob_get_clean();
	}

	/**
	 * Member dashboard shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_dashboard( $atts ) {
		// Check if user is logged in and is a member
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="remember-notice remember-error">' . 
				sprintf(
					__( 'Please %s to view your dashboard.', 'remember' ),
					'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'remember' ) . '</a>'
				) .
				'</p>';
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		$member_model = new Remember_Member();
		$member = $member_model->get( get_current_user_id() );

		if ( ! $member ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'You are not registered as a member. Please contact an administrator.', 'remember' ) . '</p>';
		}

		ob_start();
		include plugin_dir_path( __FILE__ ) . 'partials/remember-dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Events list shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_events( $atts ) {
		$atts = shortcode_atts( array(
			'status' => 'open', // open, all, upcoming
			'limit'  => 0,
		), $atts );

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-event.php';
		$event_model = new Remember_Event();

		if ( 'upcoming' === $atts['status'] ) {
			$events = $event_model->get_upcoming();
		} elseif ( 'all' === $atts['status'] ) {
			$events = $event_model->get_all();
		} else {
			$events = $event_model->get_by_status( 'open' );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-event-invites.php';
		$events = Remember_Event_Invites::visible_events( $events, get_current_user_id() );

		if ( $atts['limit'] > 0 ) {
			$events = array_slice( $events, 0, $atts['limit'] );
		}

		ob_start();
		$status = $atts['status'];
		$limit = $atts['limit'];
		include plugin_dir_path( __FILE__ ) . 'partials/remember-events.php';
		return ob_get_clean();
	}

	/**
	 * Application form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_apply( $atts ) {
		// Check if user is logged in and is a member
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="remember-notice remember-error">' . 
				sprintf(
					__( 'Please %s to apply for events.', 'remember' ),
					'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'remember' ) . '</a>'
				) .
				'</p>';
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		$member_model = new Remember_Member();
		$member = $member_model->get( get_current_user_id() );

		if ( ! $member ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'You are not registered as a member. Please contact an administrator.', 'remember' ) . '</p>';
		}

		$atts = shortcode_atts( array(
			'event_id' => isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0,
		), $atts );

		ob_start();
		$event_id = $atts['event_id'];
		include plugin_dir_path( __FILE__ ) . 'partials/remember-apply.php';
		return ob_get_clean();
	}

	/**
	 * AJAX: whether the current member's profile was saved within 24 hours.
	 *
	 * @return void
	 */
	public function ajax_profile_currency_status() {
		check_ajax_referer( 'remember_profile_currency_status', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'remember' ) ), 403 );
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-profile-audit.php';
		$member_id  = get_current_user_id();
		$updated_at = Remember_Profile_Audit::get_profile_updated_at( $member_id );
		$fresh      = Remember_Profile_Audit::is_profile_fresh( $member_id );

		wp_send_json_success(
			array(
				'fresh'      => $fresh,
				'updated_at' => $updated_at,
			)
		);
	}

	/**
	 * Follow-on survey shortcode.
	 *
	 * @return string
	 */
	public function shortcode_survey() {
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="remember-notice remember-error">' . sprintf(
				/* translators: %s: login link */
				__( 'Please %s to open this survey.', 'remember' ),
				'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'remember' ) . '</a>'
			) . '</p>';
		}
		require_once plugin_dir_path( __FILE__ ) . '../includes/utilities/class-remember-surveys.php';
		$survey_id = isset( $_REQUEST['survey_id'] ) ? absint( $_REQUEST['survey_id'] ) : 0;
		if ( $survey_id < 1 ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'Survey not found.', 'remember' ) . '</p>';
		}
		return Remember_Surveys::render_followup_page( $survey_id, get_current_user_id() );
	}

	/**
	 * Member profile shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_profile( $atts ) {
		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="remember-notice remember-error">' . 
				sprintf(
					__( 'Please %s to view your profile.', 'remember' ),
					'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'remember' ) . '</a>'
				) .
				'</p>';
		}

		$atts = shortcode_atts( array(
			'edit' => isset( $_GET['edit'] ) && $_GET['edit'] ? true : false,
		), $atts );

		ob_start();
		$is_edit = $atts['edit'];
		include plugin_dir_path( __FILE__ ) . 'partials/remember-profile.php';
		return ob_get_clean();
	}

	/**
	 * Event member directory shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_event_directory( $atts ) {
		// Check if user is logged in and is a member
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="remember-notice remember-error">' . 
				sprintf(
					__( 'Please %s to view event members.', 'remember' ),
					'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'remember' ) . '</a>'
				) .
				'</p>';
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		$member_model = new Remember_Member();
		$member = $member_model->get( get_current_user_id() );

		if ( ! $member ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'You are not registered as a member.', 'remember' ) . '</p>';
		}

		$atts = shortcode_atts( array(
			'event_id' => isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0,
		), $atts );

		if ( $atts['event_id'] <= 0 ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'Event ID is required.', 'remember' ) . '</p>';
		}

		ob_start();
		$event_id = $atts['event_id'];
		include plugin_dir_path( __FILE__ ) . 'partials/remember-event-directory.php';
		return ob_get_clean();
	}

	/**
	 * Event detail shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_event_detail( $atts ) {
		// Check if user is logged in and is a member
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="remember-notice remember-error">' . 
				sprintf(
					__( 'Please %s to view event details.', 'remember' ),
					'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'log in', 'remember' ) . '</a>'
				) .
				'</p>';
		}

		require_once plugin_dir_path( __FILE__ ) . '../includes/models/class-member.php';
		$member_model = new Remember_Member();
		$member = $member_model->get( get_current_user_id() );

		if ( ! $member ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'You are not registered as a member.', 'remember' ) . '</p>';
		}

		$atts = shortcode_atts( array(
			'event_id' => isset( $_GET['event'] ) ? absint( $_GET['event'] ) : ( isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0 ),
		), $atts );

		if ( $atts['event_id'] <= 0 ) {
			return '<p class="remember-notice remember-error">' . esc_html__( 'Event ID is required.', 'remember' ) . '</p>';
		}

		ob_start();
		$event_id = $atts['event_id'];
		include plugin_dir_path( __FILE__ ) . 'partials/remember-event-detail.php';
		return ob_get_clean();
	}
}
