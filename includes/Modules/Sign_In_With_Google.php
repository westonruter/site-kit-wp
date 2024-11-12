<?php
/**
 * Class Google\Site_Kit\Modules\Sign_In_With_Google
 *
 * @package   Google\Site_Kit
 * @copyright 2024 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Modules;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Assets\Assets;
use Google\Site_Kit\Core\Assets\Script;
use Google\Site_Kit\Core\Authentication\Authentication;
use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Modules\Module_With_Assets;
use Google\Site_Kit\Core\Modules\Module_With_Assets_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Deactivation;
use Google\Site_Kit\Core\Modules\Module_With_Settings;
use Google\Site_Kit\Core\Modules\Module_With_Settings_Trait;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Storage\User_Options;
use Google\Site_Kit\Core\Util\Method_Proxy_Trait;
use Google\Site_Kit\Modules\Sign_In_With_Google\Settings;
use Google\Site_Kit_Dependencies\Google_Client;
use WP_Error;
use WP_User;

/**
 * Class representing the Sign in With Google module.
 *
 * @since 1.137.0
 * @access private
 * @ignore
 */
final class Sign_In_With_Google extends Module implements Module_With_Assets, Module_With_Settings, Module_With_Deactivation {

	use Method_Proxy_Trait;
	use Module_With_Assets_Trait;
	use Module_With_Settings_Trait;

	/**
	 * Module slug name.
	 */
	const MODULE_SLUG = 'sign-in-with-google';

	/**
	 * Option name to store the hashed version of the user ID received from Google.
	 */
	const GOOGLE_USER_HID_OPTION = 'googlesitekitpersistent_siwg_google_user_hid';

	/**
	 * The name for the Sign in with Google callback action.
	 */
	const LOGIN_ACTION_NAME = 'googlesitekit_auth';

	/**
	 * Cookie name to store the redirect URL before the user signs in with Google.
	 */
	const REDIRECT_COOKIE_NAME = 'googlesitekit_auth_redirect_to';

	/**
	 * Error codes.
	 */
	const ERROR_INVALID_REQUEST    = 'googlesitekit_auth_invalid_request';
	const ERROR_INVALID_CSRF_TOKEN = 'googlesitekit_auth_invalid_g_csrf_token';
	const ERROR_SIGNIN_FAILED      = 'googlesitekit_auth_failed';

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.137.0
	 */
	public function register() {
		add_filter( 'wp_login_errors', array( $this, 'handle_login_errors' ) );

		add_action( 'login_form_' . self::LOGIN_ACTION_NAME, $this->get_method_proxy( 'handle_auth_callback' ) );
		add_action( 'login_form', $this->get_method_proxy( 'render_signin_button' ) );
	}

	/**
	 * Handles the callback request after the user signs in with Google.
	 *
	 * @since n.e.x.t
	 */
	private function handle_auth_callback() {
		// Ignore the request if the request method is not POST.
		$request_method = $this->context->input()->filter( INPUT_SERVER, 'REQUEST_METHOD' );
		if ( 'POST' !== $request_method ) {
			return;
		}

		$redirect_to = $this->process_auth_callback();
		if ( ! empty( $redirect_to ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}
	}

	/**
	 * Processes the callback request after the user signs in with Google and returns
	 * the URL where to redirect the user to.
	 *
	 * @since n.e.x.t
	 *
	 * @return string URL to redirect the user to.
	 */
	private function process_auth_callback() {
		$login_url = wp_login_url();

		// Check if the CSRF token is valid, if not redirect to the login page with an error.
		$csrf_cookie = $this->context->input()->filter( INPUT_COOKIE, 'g_csrf_token' );
		$csrf_post   = $this->context->input()->filter( INPUT_POST, 'g_csrf_token' );
		if ( ! $csrf_cookie || $csrf_cookie !== $csrf_post ) {
			return add_query_arg( 'error', self::ERROR_INVALID_CSRF_TOKEN, $login_url );
		}

		$user = null;

		try {
			$settings = $this->get_settings()->get();
			$payload  = $this->get_google_auth_payload(
				$settings['clientID'],
				$this->context->input()->filter( INPUT_POST, 'credential' )
			);

			if ( ! is_wp_error( $payload ) ) {
				$user = $this->find_or_create_user( $payload );
			}
		} catch ( \Exception $e ) {
			return add_query_arg( 'error', self::ERROR_INVALID_REQUEST, $login_url );
		}

		// Redirect to the error page if the user is not found.
		if ( is_wp_error( $user ) ) {
			return add_query_arg( 'error', $user->get_error_code(), $login_url );
		} elseif ( ! $user instanceof WP_User ) {
			return add_query_arg( 'error', self::ERROR_INVALID_REQUEST, $login_url );
		}

		// Redirect to the error page if the user is not a member of the current blog in multisite.
		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
			if ( ! is_user_member_of_blog( $user->ID, $blog_id ) ) {
				if ( get_option( 'users_can_register' ) ) {
					add_user_to_blog( $blog_id, $user->ID, $this->get_default_role() );
				} else {
					return add_query_arg( 'error', self::ERROR_INVALID_REQUEST, $login_url );
				}
			}
		}

		// Set the user to be the current user.
		wp_set_current_user( $user->ID, $user->user_login );

		// Set the authentication cookies and trigger the wp_login action.
		wp_set_auth_cookie( $user->ID );
		/** This filter is documented in wp-login.php */
		do_action( 'wp_login', $user->user_login, $user );

		// Use the admin dashboard URL as the redirect URL by default.
		$redirect_to = admin_url();

		// If we have the redirect URL in the cookie, use it as the main redirect_to URL.
		$cookie_redirect_to = $this->context->input()->filter( INPUT_COOKIE, self::REDIRECT_COOKIE_NAME );
		if ( ! empty( $cookie_redirect_to ) ) {
			$redirect_to = $cookie_redirect_to;
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie( self::REDIRECT_COOKIE_NAME, '', time() - 3600, $this->get_cookie_path(), COOKIE_DOMAIN );
		}

		// Redirect to HTTPS if user wants SSL.
		if ( get_user_option( 'use_ssl', $user->ID ) && str_contains( $redirect_to, 'wp-admin' ) ) {
			$redirect_to = preg_replace( '|^http://|', 'https://', $redirect_to );
		}

		/** This filter is documented in wp-login.php */
		$redirect_to = apply_filters( 'login_redirect', $redirect_to, $redirect_to, $user );

		if ( ( empty( $redirect_to ) || 'wp-admin/' === $redirect_to || admin_url() === $redirect_to ) ) {
			// If the user doesn't belong to a blog, send them to user admin. If the user can't edit posts, send them to their profile.
			if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
				$redirect_to = user_admin_url();
			} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
				$redirect_to = get_dashboard_url( $user->ID );
			} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			}
		}

		return $redirect_to;
	}

	/**
	 * Verifies the Google auth token and returns the payload if the token is valid.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $client_id Google client ID.
	 * @param string $id_token Google ID token.
	 * @return array|WP_Error Google auth payload if the token is valid, WP_Error otherwise.
	 */
	private function get_google_auth_payload( $client_id, $id_token ) {
		$google_client = new Google_Client( array( 'client_id' => $client_id ) );
		$payload       = $google_client->verifyIdToken( $id_token );
		if ( empty( $payload['sub'] ) || empty( $payload['email'] ) ) {
			return new WP_Error( self::ERROR_INVALID_REQUEST );
		}

		return $payload;
	}

	/**
	 * Tries to find a user using user ID or email recieved from Google. If the user is not found,
	 * attempts to create a new one.
	 *
	 * @since n.e.x.t
	 *
	 * @param array $payload Google auth payload.
	 * @return WP_User|WP_Error User object if found or created, WP_Error otherwise.
	 */
	private function find_or_create_user( $payload ) {
		// Check if there are any existing WordPress users connected to this Google account.
		// The user ID is used as the unique identifier because users can change the email on their Google account.
		$g_user_hid = md5( $payload['sub'] );
		$users      = get_users(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => $this->user_options->get_meta_key( self::GOOGLE_USER_HID_OPTION ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $g_user_hid,
				'number'     => 1,
			)
		);

		if ( ! empty( $users ) ) {
			return $users[0];
		}

		// Find an existing user that matches the email and link to their Google account by store their user ID in user meta.
		$user = get_user_by( 'email', $payload['email'] );
		if ( $user ) {
			$this->user_options->switch_user( $user->ID );
			$this->user_options->set( self::GOOGLE_USER_HID_OPTION, $g_user_hid );

			return $user;
		}

		// We haven't found the user using their google user id and email. Thus we need to create
		// a new user. But if the registration is closed, we need to return an error to identify
		// that the sign in process failed.
		//
		// No need to check the multisite settings because it is already incorporated in the following
		// users_can_register check. See: https://github.com/WordPress/WordPress/blob/505b7c55f5363d51e7e28d512ce7dcb2d5f45894/wp-includes/ms-default-filters.php#L20.
		if ( ! get_option( 'users_can_register' ) ) {
			return new WP_Error( self::ERROR_SIGNIN_FAILED );
		}

		// Get the default role for new users.
		$default_role = $this->get_default_role();

		// Create a new user.
		$user_id = wp_insert_user(
			array(
				'user_pass'    => wp_generate_password( 64 ),
				'user_login'   => $payload['email'],
				'user_email'   => $payload['email'],
				'display_name' => $payload['name'],
				'first_name'   => $payload['given_name'],
				'last_name'    => $payload['family_name'],
				'role'         => $default_role,
				'meta_input'   => array(
					$this->user_options->get_meta_key( self::GOOGLE_USER_HID_OPTION ) => $g_user_hid,
				),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( self::ERROR_SIGNIN_FAILED );
		}

		// Add the user to the current site if it is a multisite.
		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), $user_id, $default_role );
		}

		// Send the new user notification.
		wp_send_new_user_notifications( $user_id );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Gets the default role for new users.
	 *
	 * @since n.e.x.t
	 *
	 * @return string Default role.
	 */
	private function get_default_role() {
		$default_role = get_option( 'default_role' );
		if ( empty( $default_role ) ) {
			$default_role = 'subscriber';
		}

		return $default_role;
	}

	/**
	 * Adds custom errors if Google auth flow failed.
	 *
	 * @since n.e.x.t
	 *
	 * @param WP_Error $error WP_Error instance.
	 * @return WP_Error $error WP_Error instance.
	 */
	public function handle_login_errors( $error ) {
		$error_code = $this->context->input()->filter( INPUT_GET, 'error' );
		if ( ! $error_code ) {
			return $error;
		}

		switch ( $error_code ) {
			case self::ERROR_INVALID_REQUEST:
			case self::ERROR_INVALID_CSRF_TOKEN:
				$error->add( self::MODULE_SLUG, __( 'Sign in with Google failed.', 'google-site-kit' ) );
				break;
			case self::ERROR_SIGNIN_FAILED:
				$error->add( self::MODULE_SLUG, __( 'The user is not registered on this site.', 'google-site-kit' ) );
				break;
			default:
				break;
		}

		return $error;
	}

	/**
	 * Cleans up when the module is deactivated.
	 *
	 * @since 1.137.0
	 */
	public function on_deactivation() {
		$this->get_settings()->delete();
	}

	/**
	 * Sets up information about the module.
	 *
	 * @since 1.137.0
	 *
	 * @return array Associative array of module info.
	 */
	protected function setup_info() {
		return array(
			'slug'        => self::MODULE_SLUG,
			'name'        => _x( 'Sign in with Google', 'Service name', 'google-site-kit' ),
			'description' => __( 'Improve user engagement, trust, and data privacy, while creating a simple, secure, and personalized experience for your visitors', 'google-site-kit' ),
			'order'       => 10,
			'homepage'    => __( 'https://developers.google.com/identity/gsi/web/guides/overview', 'google-site-kit' ),
		);
	}

	/**
	 * Sets up the module's assets to register.
	 *
	 * @since 1.137.0
	 *
	 * @return Asset[] List of Asset objects.
	 */
	protected function setup_assets() {
		return array(
			new Script(
				'googlesitekit-modules-sign-in-with-google',
				array(
					'src'          => $this->context->url( 'dist/assets/js/googlesitekit-modules-sign-in-with-google.js' ),
					'dependencies' => array(
						'googlesitekit-vendor',
						'googlesitekit-api',
						'googlesitekit-data',
						'googlesitekit-modules',
						'googlesitekit-datastore-site',
						'googlesitekit-datastore-user',
						'googlesitekit-components',
					),
				)
			),
		);
	}

	/**
	 * Sets up the module's settings instance.
	 *
	 * @since 1.137.0
	 *
	 * @return Settings
	 */
	protected function setup_settings() {
		return new Settings( $this->options );
	}

	/**
	 * Checks whether the module is connected.
	 *
	 * A module being connected means that all steps required as part of its activation are completed.
	 *
	 * @since 1.139.0
	 *
	 * @return bool True if module is connected, false otherwise.
	 */
	public function is_connected() {
		$options = $this->get_settings()->get();
		if ( empty( $options['clientID'] ) ) {
			return false;
		}

		return parent::is_connected();
	}

	/**
	 * Renders the sign in button.
	 *
	 * @since 1.139.0
	 */
	private function render_signin_button() {
		$settings = $this->get_settings()->get();
		if ( ! $settings['clientID'] ) {
			return;
		}

		$login_uri = add_query_arg( 'action', self::LOGIN_ACTION_NAME, wp_login_url() );
		if ( substr( $login_uri, 0, 5 ) !== 'https' ) {
			return;
		}

		$redirect_to = $this->context->input()->filter( INPUT_GET, 'redirect_to' );
		$redirect_to = trim( $redirect_to );

		$config = array(
			'client_id' => $settings['clientID'],
			'login_uri' => $login_uri,
			'ux_mode'   => 'redirect',
		);

		$btn_args = array(
			'theme' => $settings['theme'],
			'text'  => $settings['text'],
			'shape' => $settings['shape'],
		);

		// Render the Sign in with Google button and related inline styles.
		?>
<!-- Sign in with Google button added by Site Kit -->
<?php /* phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript */ ?>
<script src="https://accounts.google.com/gsi/client"></script>
<script>
( () => {
	const parent = document.createElement( 'div' );
	document.getElementById( 'login' ).insertBefore( parent, document.getElementById( 'loginform' ) );

	google.accounts.id.initialize( <?php echo wp_json_encode( $config ); ?> );
	google.accounts.id.renderButton( parent, <?php echo wp_json_encode( $btn_args ); ?> );
<?php if ( ! empty( $redirect_to ) ) : // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect ?>

	const expires = new Date();
	expires.setTime( expires.getTime() + 1000 * 60 * 5 );
	document.cookie = "<?php echo esc_js( self::REDIRECT_COOKIE_NAME ); ?>=<?php echo esc_js( $redirect_to ); ?>;expires="  + expires.toUTCString() + ";path=<?php echo esc_js( $this->get_cookie_path() ); ?>";
<?php endif; // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect ?>
} )();
</script>
<!-- End Sign in with Google button added by Site Kit -->
		<?php
	}

	/**
	 * Gets the path for the redirect cookie.
	 *
	 * @since n.e.x.t
	 *
	 * @return string Cookie path.
	 */
	protected function get_cookie_path() {
		return dirname( wp_parse_url( wp_login_url(), PHP_URL_PATH ) );
	}
}
