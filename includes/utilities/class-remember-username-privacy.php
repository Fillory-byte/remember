<?php
/**
 * Keep login usernames out of public author URLs.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Username discovery hardening.
 *
 * WordPress builds author URLs from user_nicename, which defaults to the login
 * name. This removes the users sitemap, stops logged-out visitors walking
 * ?author=1, 2, 3… to author slugs, gives new accounts a random slug, and offers
 * a one-click random slug on profiles whose slug still equals the login.
 *
 * Author pages reached by slug keep working. Sites that want public author
 * listings can turn all of this off with the remember_hide_usernames filter.
 */
class Remember_Username_Privacy {

	/**
	 * admin-post action for the one-click slug change.
	 */
	const ACTION = 'remember_random_author_slug';

	/**
	 * Random slugs assigned during the current request's inserts, keyed by slug.
	 *
	 * @var array<string,bool>
	 */
	private static $assigned = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'filter_sitemap_provider' ), 10, 2 );
		add_filter( 'request', array( __CLASS__, 'block_author_id_lookup' ) );
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'randomize_new_user_slug' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'log_assigned_slug' ) );
		add_action( 'admin_notices', array( __CLASS__, 'profile_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_random_slug' ) );
	}

	/**
	 * Whether username hiding is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filter whether reMember hides login usernames from public author URLs.
		 *
		 * Return false to keep the users sitemap, ?author=<id> redirects and
		 * WordPress's default author slugs.
		 *
		 * @param bool $hide Default true.
		 */
		return (bool) apply_filters( 'remember_hide_usernames', true );
	}

	/**
	 * Drop the core users sitemap.
	 *
	 * @param WP_Sitemaps_Provider|false $provider Provider instance.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public static function filter_sitemap_provider( $provider, $name ) {
		if ( 'users' === $name && self::enabled() ) {
			return false;
		}
		return $provider;
	}

	/**
	 * Return a 404 to logged-out visitors who look up an author by ID.
	 *
	 * Runs before redirect_canonical, which would otherwise send ?author=<id>
	 * to /author/<slug>/. REST requests are left alone: /wp/v2/posts?author=<id>
	 * is a normal filter and returns IDs, not slugs.
	 *
	 * @param array<string,mixed> $query_vars Parsed query vars.
	 * @return array<string,mixed>
	 */
	public static function block_author_id_lookup( $query_vars ) {
		if ( ! isset( $_GET['author'] ) || isset( $query_vars['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query check.
			return $query_vars;
		}
		if ( is_user_logged_in() || ! self::enabled() ) {
			return $query_vars;
		}
		return array( 'error' => '404' );
	}

	/**
	 * Whether a slug is the login name (or WordPress's de-duplicated form of it).
	 *
	 * @param string $slug  user_nicename.
	 * @param string $login user_login.
	 * @return bool
	 */
	public static function slug_reveals_login( $slug, $login ) {
		$slug = strtolower( (string) $slug );
		if ( '' === $slug ) {
			return false;
		}
		$base = strtolower( sanitize_title( (string) $login ) );
		if ( $slug === strtolower( (string) $login ) || $slug === $base ) {
			return true;
		}
		return '' !== $base && 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '-\d+$/', $slug );
	}

	/**
	 * A random author slug no other user has.
	 *
	 * @return string
	 */
	public static function unique_random_slug() {
		do {
			$slug = 'user-' . strtolower( wp_generate_password( 10, false, false ) );
		} while ( get_user_by( 'slug', $slug ) || isset( self::$assigned[ $slug ] ) );
		return $slug;
	}

	/**
	 * Give new accounts a random slug unless one was chosen explicitly.
	 *
	 * @param array<string,mixed> $data   Sanitized user data about to be inserted.
	 * @param bool                $update Whether this is an update.
	 * @param int|null            $id     User ID on update.
	 * @return array<string,mixed>
	 */
	public static function randomize_new_user_slug( $data, $update, $id ) {
		unset( $id );
		if ( $update || ! self::enabled() || empty( $data['user_login'] ) ) {
			return $data;
		}
		if ( ! self::slug_reveals_login( isset( $data['user_nicename'] ) ? $data['user_nicename'] : '', $data['user_login'] ) ) {
			return $data;
		}
		$slug                     = self::unique_random_slug();
		$data['user_nicename']    = $slug;
		self::$assigned[ $slug ] = true;
		return $data;
	}

	/**
	 * Log the random slug once the new user has an ID.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public static function log_assigned_slug( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! isset( self::$assigned[ $user->user_nicename ] ) ) {
			return;
		}
		unset( self::$assigned[ $user->user_nicename ] );
		Remember_Logger::info(
			'Assigned a random author slug to a new account',
			array(
				'user_id' => (int) $user_id,
				'slug'    => $user->user_nicename,
			)
		);
	}

	/**
	 * User being viewed on profile.php / user-edit.php, if any.
	 *
	 * @return WP_User|null
	 */
	private static function profile_screen_user() {
		global $pagenow;
		if ( 'profile.php' === $pagenow ) {
			return wp_get_current_user();
		}
		if ( 'user-edit.php' === $pagenow && isset( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
			$user = get_userdata( absint( $_GET['user_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $user ? $user : null;
		}
		return null;
	}

	/**
	 * Notice on a profile whose author slug is the login name, with a one-click fix.
	 *
	 * @return void
	 */
	public static function profile_notice() {
		$user = self::profile_screen_user();
		if ( ! $user || ! $user->exists() || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		if ( isset( $_GET['remember_slug_changed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: new author page path */
					__( 'The author page address is now %s. It no longer shows the login name.', 'remember' ),
					'/author/' . $user->user_nicename . '/'
				)
			) . '</p></div>';
			return;
		}

		if ( ! self::enabled() || ! self::slug_reveals_login( $user->user_nicename, $user->user_login ) ) {
			return;
		}

		$own = get_current_user_id() === (int) $user->ID;
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Author page shows the login name', 'remember' ); ?></strong><br>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: author page path */
						$own
							? __( 'Your public author page address (%s) contains your login name, which helps attackers guess passwords. A random address hides it; links to the old author page will stop working.', 'remember' )
							: __( 'This user\'s public author page address (%s) contains their login name, which helps attackers guess passwords. A random address hides it; links to the old author page will stop working.', 'remember' ),
						'/author/' . $user->user_nicename . '/'
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 10px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $user->ID ); ?>
				<?php submit_button( __( 'Use a random author address', 'remember' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Replace a user's author slug with a random one.
	 *
	 * @return void
	 */
	public static function handle_random_slug() {
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
		check_admin_referer( self::ACTION . '_' . $user_id );

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to change this user.', 'remember' ), '', array( 'response' => 403 ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'User not found.', 'remember' ), '', array( 'response' => 404 ) );
		}

		$back = get_current_user_id() === $user_id
			? admin_url( 'profile.php' )
			: add_query_arg( 'user_id', $user_id, admin_url( 'user-edit.php' ) );

		if ( self::slug_reveals_login( $user->user_nicename, $user->user_login ) ) {
			$slug   = self::unique_random_slug();
			$result = wp_update_user(
				array(
					'ID'            => $user_id,
					'user_nicename' => $slug,
				)
			);
			if ( is_wp_error( $result ) ) {
				Remember_Logger::error(
					'Author slug change failed',
					array(
						'user_id' => $user_id,
						'error'   => $result->get_error_message(),
					)
				);
				wp_die( esc_html( $result->get_error_message() ) );
			}
			Remember_Logger::info(
				'Author slug changed to a random value',
				array(
					'user_id'    => $user_id,
					'slug'       => $slug,
					'changed_by' => get_current_user_id(),
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'remember_slug_changed', '1', $back ) );
		exit;
	}
}
