<?php
/*
 * Plugin Name: Authy for WordPress
 * Plugin URI: http://www.ethitter.com/plugins/authy-for-wordpress/
 * Description: Add <a href="http://www.authy.com/">Authy</a> two-factor authentication to WordPress. Users opt in for an added level of security that relies on random codes from their mobile devices.
 * Author: Erick Hitter
 * Version: 0.3
 * Author URI: http://www.ethitter.com/
 * License: GPL2+
 * Text Domain: authy_for_wp

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * AUTHY FOR WORDPRESS
 * Main plugin class
 *
 * @package Authy for WordPress
 * @since 0.1
 */

class Authy_WP {
	/**
	 * Class variables
	 */
	// Oh look, a singleton
	private static $__instance = null;

	// Some plugin info
	protected $name = 'Authy for WordPress';

	// Parsed settings
	private $settings = null;

	// Is API ready, should plugin act?
	protected $ready = false;
	protected $sms = false;

	// Authy API
	protected $api = null;
	protected $api_key = null;
	protected $api_endpoint = null;

	// Interface keys
	protected $settings_page = 'authy-for-wp';
	protected $users_page = 'authy-for-wp-user';
	protected $sms_action = 'authy-for-wp-sms';

	// Data storage keys
	protected $settings_key = 'authy_for_wp';
	protected $users_key = 'authy_for_wp_user';

	// Settings field placeholders
	protected $settings_fields = array();

	protected $settings_field_defaults = array(
		'label'     => null,
		'type'      => 'text',
		'sanitizer' => 'sanitize_text_field',
		'section'   => 'default',
		'class'     => null
	);

	// Default Authy data
	protected $user_defaults = array(
		'email'        => null,
		'phone'        => null,
		'country_code' => '+1',
		'authy_id'     => null
	);

	/**
	 * Singleton implementation
	 *
	 * @since 0.1
	 * @uses this::setup
	 * @return object
	 */
	public static function instance() {
		if ( ! is_a( self::$__instance, 'Authy_WP' ) ) {
			self::$__instance = new Authy_WP;
			self::$__instance->setup();
		}

		return self::$__instance;
	}

	/**
	 * Silence is golden.
	 *
	 * @since 0.1
	 */
	private function __construct() {}

	/**
	 * Load plugin API file and plugin setup
	 *
	 * @since 0.1
	 * @uses plugin_dir_path, add_action
	 * @return null
	 */
	private function setup() {
		require( plugin_dir_path( __FILE__ ) . 'authy-wp-api.php' );

		// Early plugin setup - nothing can occur before this.
		add_action( 'init', array( $this, 'action_init' ) );
	}

	/**
	 * Plugin setup
	 *
	 * @since 0.3
	 * @uses this::register_settings_fields, this::prepare_api, add_action, add_filter, wp_register_script, wp_register_style
	 * @action init
	 * @return null
	 */
	public function action_init() {
		$this->register_settings_fields();
		$this->prepare_api();

		// Plugin settings
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'action_admin_enqueue_scripts' ) );

		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 10, 2 );

		// Anything other than plugin configuration belongs in here.
		// Important to consider plugin state so we only load code when needed.
		if ( $this->ready ) {
			// Check SMS availability
			$this->check_sms_availability();

			// User settings
			add_action( 'show_user_profile', array( $this, 'action_show_user_profile' ) );
			add_action( 'edit_user_profile', array( $this, 'action_edit_user_profile' ) );
			add_action( 'wp_ajax_' . $this->users_page, array( $this, 'ajax_get_id' ) );

			add_action( 'personal_options_update', array( $this, 'action_personal_options_update' ) );
			add_action( 'edit_user_profile_update', array( $this, 'action_edit_user_profile_update' ) );

			// Authentication
			add_action( 'login_enqueue_scripts', array( $this, 'action_login_enqueue_scripts' ) );
			add_action( 'wp_ajax_nopriv_' . $this->sms_action, array( $this, 'ajax_sms_login' ) );
			add_action( 'login_form', array( $this, 'action_login_form' ), 50 );
			add_filter( 'authenticate', array( $this, 'action_authenticate' ), 9999, 2 );

			// Authy assets
			$version = date( 'Ymd' );
			wp_register_script( 'authy', 'https://www.authy.com/form.authy.min.js', array(), $version, false );
			wp_register_style( 'authy', 'https://www.authy.com/form.authy.min.css', array(), $version, 'screen' );
		} else {
			add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
		}
	}

	/**
	 * Add settings fields for main plugin page
	 *
	 * @since 0.1
	 * @uses __
	 * @return null
	 */
	protected function register_settings_fields() {
		$this->settings_fields = array(
			 array(
				'name'      => 'api_key_production',
				'label'     => __( 'Production API Key', 'authy_wp' ),
				'type'      => 'text',
				'sanitizer' => 'alphanumeric'
			),
			array(
				'name'      => 'api_key_development',
				'label'     => __( 'Development API Key', 'authy_wp' ),
				'type'      => 'text',
				'sanitizer' => 'alphanumeric'
			),
			array(
				'name'      => 'roles',
				'label'     => __( 'Roles', 'authy_wp' ),
				'type'      => 'roles',
				'sanitizer' => null
			)
		);
	}

	/**
	 * Set class variables regarding API
	 * Instantiates the Authy API class into $this->api
	 *
	 * @since 0.1
	 * @uses this::get_setting, Authy_WP_API::instance
	 */
	protected function prepare_api() {
		$endpoints = array(
			'production'  => 'https://api.authy.com',
			'development' => 'http://sandbox-api.authy.com'
		);

		// Capture API keys set via wp-config
		if ( ( defined( 'AUTHY_API_KEY_PRODUCTION' ) && AUTHY_API_KEY_PRODUCTION ) || ( defined( 'AUTHY_API_KEY_DEVELOPMENT' ) && AUTHY_API_KEY_DEVELOPMENT ) ) {
			// Prime settings
			$this->get_setting( null );

			// Process overrides from wp-config
			if ( defined( 'AUTHY_API_KEY_PRODUCTION' ) && AUTHY_API_KEY_PRODUCTION )
				$this->settings['api_key_production'] = $this->sanitize_alphanumeric( AUTHY_API_KEY_PRODUCTION );

			if ( defined( 'AUTHY_API_KEY_DEVELOPMENT' ) && AUTHY_API_KEY_DEVELOPMENT )
				$this->settings['api_key_development'] = $this->sanitize_alphanumeric( AUTHY_API_KEY_DEVELOPMENT );

			if ( defined( 'AUTHY_ENVIRONMENT' ) && isset( $endpoints[ AUTHY_ENVIRONMENT ] ) )
				$this->settings['environment'] = AUTHY_ENVIRONMENT;
		}

		// Plugin page accepts keys for production and development.
		// Cannot be toggled except via the `authy_wp_environment` filter.
		$environment = $this->get_setting( 'environment' );

		// API key is specific to the environment
		$api_key = $this->get_setting( 'api_key_' . $environment );

		// Only prepare the API endpoint if we have all information needed.
		if ( $api_key && isset( $endpoints[ $environment ] ) ) {
			$this->api_key = $api_key;
			$this->api_endpoint = $endpoints[ $environment ];

			$this->ready = true;
		}

		// Instantiate the API class
		$this->api = Authy_WP_API::instance( $this->api_key, $this->api_endpoint );
	}

	/**
	 * COMMON PLUGIN ELEMENTS
	 */

	/**
	 * Register plugin's setting and validation callback
	 *
	 * @since 0.1
	 * @param action admin_init
	 * @uses register_setting
	 * @return null
	 */
	public function action_admin_init() {
		register_setting( $this->settings_page, $this->settings_key, array( $this, 'validate_plugin_settings' ) );
	}

	/**
	 * Register plugin settings page and page's sections
	 *
	 * @since 0.1
	 * @uses add_options_page, add_settings_section
	 * @action admin_menu
	 * @return null
	 */
	public function action_admin_menu() {
		add_options_page( $this->name, 'Authy for WP', 'manage_options', $this->settings_page, array( $this, 'plugin_settings_page' ) );
		add_settings_section( 'default', '', array( $this, 'register_settings_page_sections' ), $this->settings_page );
	}

	/**
	 * Enqueue admin script for connection modal
	 *
	 * @since 0.1
	 * @uses get_current_screen, wp_enqueue_script, plugins_url, wp_localize_script, this::get_ajax_url, wp_enqueue_style
	 * @action admin_enqueue_scripts
	 * @return null
	 */
	public function action_admin_enqueue_scripts() {
		if ( ! $this->ready )
			return;

		$current_screen = get_current_screen();

		if ( 'profile' == $current_screen->base ) {
			wp_enqueue_script( 'authy-wp-profile', plugins_url( 'assets/authy-wp-profile.js', __FILE__ ), array( 'jquery', 'thickbox' ), 1.01, true );
			wp_localize_script( 'authy-wp-profile', 'AuthyForWP', array(
				'ajax'        => $this->get_ajax_url(),
				'th_text'     => __( 'Connection', 'authy_for_wp' ),
				'button_text' => __( 'Manage Authy Connection', 'authy_for_wp' )
			) );

			wp_enqueue_style( 'thickbox' );
		}
	}

	/**
	 * Add settings link to plugin row actions
	 *
	 * @since 0.1
	 * @param array $links
	 * @param string $plugin_file
	 * @uses menu_page_url, __
	 * @filter plugin_action_links
	 * @return array
	 */
	public function filter_plugin_action_links( $links, $plugin_file ) {
		if ( false !== strpos( $plugin_file, pathinfo( __FILE__, PATHINFO_FILENAME ) ) )
			$links['settings'] = '<a href="' . menu_page_url( $this->settings_page, false ) . '">' . __( 'Settings', 'authy_for_wp' ) . '</a>';

		return $links;
	}

	/**
	 * Display an admin nag when plugin is active but API keys are missing
	 *
	 * @since 0.1
	 * @uses esc_html, _e, __, menu_page_url
	 * @action admin_notices
	 * @return string or null
	 */
	public function action_admin_notices() {
		if ( ! $this->ready ) : ?>
			<div id="message" class="error">
				<p>
					<strong><?php echo esc_html( $this->name ); ?>:</strong>
					<?php _e( 'The plugin is active, but API keys are missing.', 'authy_for_wp' ); ?>
				</p>
				<p><?php _e( 'Until keys are entered, users cannot activate Authy on their accounts.', 'authy_for_wp' ); ?></p>
				<p><?php printf( __( '<a href="%s">Click here to configure</a>.', 'authy_for_wp' ), menu_page_url( $this->settings_page, false ) ); ?></p>
			</div>
		<?php endif;
	}

	/**
	 * Retrieve a plugin setting
	 *
	 * @since 0.1
	 * @param string $key
	 * @uses get_option, wp_parse_args, apply_filters
	 * @return array or false
	 */
	public function get_setting( $key ) {
		$value = false;

		if ( is_null( $this->settings ) || ! is_array( $this->settings ) ) {
			$this->settings = get_option( $this->settings_key, array() );

			$this->settings['roles_set'] = ( ! empty( $this->settings ) && array_key_exists( 'roles', $this->settings ) );

			$this->settings = wp_parse_args( $this->settings, array(
				'api_key_production'  => '',
				'api_key_development' => '',
				'environment'         => apply_filters( 'authy_wp_environment', 'production' ),
				'roles'               => array()
			) );
		}

		if ( isset( $this->settings[ $key ] ) )
			$value = $this->settings[ $key ];

		return $value;
	}

	/**
	 * Build Ajax URLs
	 *
	 * @since 0.1
	 * @uses add_query_arg, wp_create_nonce, admin_url
	 * @return string
	 */
	protected function get_ajax_url( $url = 'user' ) {
		switch( $url ) {
			default :
			case 'user' :
				return add_query_arg( array(
					'action' => $this->users_page,
					'nonce' => wp_create_nonce( $this->users_key . '_ajax' )
				), admin_url( 'admin-ajax.php' ) );

				break;

			case 'sms' :
				return add_query_arg( array(
					'action'   => $this->sms_action,
					'username' => ''
				), admin_url( 'admin-ajax.php' ) );

				break;
		}
	}

	/**
	 * Print common Ajax head element
	 *
	 * @since 0.1
	 * @uses wp_print_scripts, wp_print_styles
	 * @return string
	 */
	protected function ajax_head() {
		?><head>
			<?php
				wp_print_scripts( array( 'jquery', 'authy' ) );
				wp_print_styles( array( 'colors', 'authy' ) );
			?>

			<style type="text/css">
				body {
					width: 450px;
					height: 250px;
					overflow: hidden;
					padding: 0 10px 10px 10px;
				}

				div.wrap {
					width: 450px;
					height: 250px;
					overflow: hidden;
				}

				table th label {
					font-size: 12px;
				}

				.submit > * {
					float: left;
				}

				.no-js .hide-if-no-js { display: none; }
			</style>

			<script type="text/javascript">
				(function($){
					$( document ).ready( function() {
						$( '.authy-wp-user-modal p.submit, .authy-wp-sms p.submit' ).append( '<span class="spinner" style="display:none;"></span>' );
						$( '.authy-wp-user-modal p.submit .button, .authy-wp-sms p.submit .button' ).on( 'click.submitted', function() {
							$( this ).siblings( '.spinner' ).show();
						} );

						$( 'body' ).addClass( 'js' ).removeClass( 'no-js' );
					} );
				})(jQuery);
			</script>
		</head><?php
	}

	/**
	 * Check basic SMS availability.
	 * Subscription level dictates availability.
	 *
	 * @since 0.2
	 * @uses this::api::send_sms
	 * @return null
	 */
	public function check_sms_availability() {
		// SMS availability check disabled per https://github.com/ethitter/Authy-for-WP/issues/11
		// if ( ! in_array( $this->api->send_sms( 1 ), array( 503, false ) ) )
			$this->sms = true;
	}

	/**
	 * Ensure a given value only contains alphanumeric characters
	 *
	 * @since 0.3
	 * @param string $value
	 * @return string
	 */
	protected function sanitize_alphanumeric( $value ) {
		return preg_replace( '#[^a-z0-9]#i', '', $value );
	}

	/**
	 * GENERAL OPTIONS PAGE
	 */

	/**
	 * Populate settings page's sections
	 *
	 * @since 0.1
	 * @uses wp_parse_args, add_settings_field
	 * @return null
	 */
	public function register_settings_page_sections() {
		foreach ( $this->settings_fields as $args ) {
			$args = wp_parse_args( $args, $this->settings_field_defaults );

			add_settings_field( $args['name'], $args['label'], array( $this, 'form_field_' . $args['type'] ), $this->settings_page, $args['section'], $args );
		}
	}

	/**
	 * Render text input
	 *
	 * @since 0.1
	 * @param array $args
	 * @uses wp_parse_args, esc_attr, disabled, this::get_setting, esc_attr
	 * @return string or null
	 */
	public function form_field_text( $args ) {
		$args = wp_parse_args( $args, $this->settings_field_defaults );

		$name = esc_attr( $args['name'] );
		if ( empty( $name ) )
			return;

		if ( is_null( $args['class'] ) )
			$args['class'] = 'regular-text';

		if ( defined( 'AUTHY_' . strtoupper( $args['name'] ) ) && constant( 'AUTHY_' . strtoupper( $args['name'] ) ) ) :
			?><input type="text" class="<?php echo esc_attr( $args['class'] ); ?>" id="field-<?php echo $name; ?>" value="<?php echo esc_attr( constant( 'AUTHY_' . strtoupper( $args['name'] ) ) ); ?>" readonly="readonly" />

			<p class="description"><?php _e( 'This value is set in your site\'s <code>wp-config.php</code> file and cannot be changed here.', 'authy_wp' ); ?></p><?php
		else :
			$value = $this->get_setting( $args['name'] );

			?><input type="text" name="<?php echo esc_attr( $this->settings_key ); ?>[<?php echo $name; ?>]" class="<?php echo esc_attr( $args['class'] ); ?>" id="field-<?php echo $name; ?>" value="<?php echo esc_attr( $value ); ?>" /><?php
		endif;
	}

	/**
	 * Render Roles input fields
	 *
	 * @since 0.3
	 * @param array $args
	 * @uses wp_parse_args, esc_attr, this::get_setting, get_editable_roles, __, checked, translate_user_role
	 * @return string or null
	 */
	public function form_field_roles( $args ) {
		$args = wp_parse_args( $args, $this->settings_field_defaults );

		$name = esc_attr( $args['name'] );
		if ( empty( $name ) )
			return;

		$selected_roles = $this->get_setting( $args['name'] );

		$roles = get_editable_roles();

		if ( empty( $roles ) ) {
			printf( __( 'You are not able to specify the roles available for use with %s.', 'authy_wp' ), $this->name );
		} else {
			foreach ( $roles as $role => $details ) {
				?><input type="checkbox" name="<?php echo esc_attr( $this->settings_key ); ?>[<?php echo $name; ?>][]" id="field-<?php echo $name; ?>-<?php echo esc_attr( $role ); ?>" value="<?php echo esc_attr( $role ); ?>"<?php checked( in_array( $role, $selected_roles ) ); ?> /> <label for="field-<?php echo $name; ?>-<?php echo esc_attr( $role ); ?>"><?php echo translate_user_role( $details['name'] ); ?></label><br /><?php
			}
		}
	}

	/**
	 * Render settings page
	 *
	 * @since 0.1
	 * @uses screen_icon, esc_html, get_admin_page_title, settings_fields, do_settings_sections, submit_button
	 * @return string
	 */
	public function plugin_settings_page() {
		$plugin_name = esc_html( get_admin_page_title() );
		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2><?php echo $plugin_name; ?></h2>

			<?php if ( $this->ready ) : ?>
				<p><?php _e( 'With API keys provided, your users can now enable Authy on their individual accounts by visting their user profile pages.', 'authy_for_wp' ); ?></p>
			<?php else : ?>
				<p><?php printf( __( 'To use the Authy service, you must register an account at <a href="%1$s"><strong>%1$s</strong></a> and create an application for access to the Authy API.', 'authy_for_wp' ), 'http://www.authy.com/' ); ?></p>
				<p><?php _e( "Once you've created your application, enter your API keys in the fields below.", 'authy_for_wp' ); ?></p>
				<p><?php printf( __( 'Until your API keys are entered, the %s plugin cannot function.', 'authy_for_wp' ), $plugin_name ); ?></p>
			<?php endif; ?>

			<form action="options.php" method="post">

				<?php settings_fields( $this->settings_page ); ?>

				<?php do_settings_sections( $this->settings_page ); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Validate plugin settings
	 *
	 * @since 0.1
	 * @param array $settings
	 * @uses check_admin_referer, wp_parse_args, sanitize_text_field, get_editable_roles
	 * @return array
	 */
	public function validate_plugin_settings( $settings ) {
		check_admin_referer( $this->settings_page . '-options' );

		$settings_validated = array();

		foreach ( $this->settings_fields as $field ) {
			$field = wp_parse_args( $field, $this->settings_field_defaults );

			if ( ! isset( $settings[ $field['name'] ] ) )
				continue;

			switch ( $field['type'] ) {
				case 'text' :
					switch ( $field['sanitizer'] ) {
						case 'alphanumeric' :
							$value = $this->sanitize_alphanumeric( $settings[ $field['name' ] ] );
							break;

						default:
						case 'sanitize_text_field' :
							$value = sanitize_text_field( $settings[ $field['name'] ] );
							break;
					}
					break;

				case 'roles' :
					$roles = get_editable_roles();

					if ( empty( $roles ) )
						$roles = array();

					$roles = array_keys( $roles );

					$value = array();

					foreach ( $settings[ $field['name'] ] as $role ) {
						if ( in_array( $role, $roles ) )
							$value[] = $role;
					}
					break;

				default:
					$value = sanitize_text_field( $settings[ $field['name'] ] );
					break;
			}

			if ( isset( $value ) && ! empty( $value ) )
				$settings_validated[ $field['name'] ] = $value;
		}

		return $settings_validated;
	}

	/**
	 * USER INFORMATION FUNCTIONS
	 */

	/**
	 * Add Authy data to a given user account
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @param string $email
	 * @param string $phone
	 * @param string $country_code
	 * @uses this::user_has_authy_id, this::api::get_id, wp_parse_args, this::clear_authy_data, get_user_meta, update_user_meta
	 * @return null
	 */
	public function set_authy_data( $user_id, $email, $phone, $country_code ) {
		// Retrieve user's existing Authy ID, or get one from Authy
		if ( $this->user_has_authy_id( $user_id ) ) {
			$authy_id = $this->get_user_authy_id( $user_id );
		} else {
			// Request an Authy ID with given user information
			$authy_id = (int) $this->api->get_id( $email, $phone, $country_code );

			if ( ! $authy_id )
				unset( $authy_id );
		}

		// Build array of Authy data
		$data_sanitized = array(
			'email'        => $email,
			'phone'        => $phone,
			'country_code' => $country_code
		);

		if ( isset( $authy_id ) )
			$data_sanitized['authy_id'] = $authy_id;

		$data_sanitized = wp_parse_args( $data_sanitized, $this->user_defaults );

		// Update Authy data if sufficient information is provided, otherwise clear the option out.
		if ( empty( $data_sanitized['phone'] ) ) {
			$this->clear_authy_data( $user_id );
		} else {
			$data = get_user_meta( $user_id, $this->users_key, true );
			if ( ! is_array( $data ) )
				$data = array();

			$data[ $this->api_key ] = $data_sanitized;

			update_user_meta( $user_id, $this->users_key, $data );
		}
	}

	/**
	 * Retrieve a user's Authy data for a given API key
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @param string $api_key
	 * @uses get_user_meta, wp_parse_args
	 * @return array
	 */
	protected function get_authy_data( $user_id, $api_key = null ) {
		// Bail without a valid user ID
		if ( ! $user_id )
			return $this->user_defaults;

		// Validate API key
		if ( is_null( $api_key ) )
			$api_key = $this->api_key;
		else
			$api_key = $this->sanitize_alphanumeric( $api_key );

		// Get meta, which holds all Authy data by API key
		$data = get_user_meta( $user_id, $this->users_key, true );
		if ( ! is_array( $data ) )
			$data = array();

		// Return data for this API, if present, otherwise return default data
		if ( array_key_exists( $api_key, $data ) )
			return wp_parse_args( $data[ $api_key ], $this->user_defaults );

		return $this->user_defaults;
	}

	/**
	 * Delete any stored Authy connections for the given user.
	 * Expected usage is somewhere where clearing is the known action.
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @uses delete_user_meta
	 * @return null
	 */
	protected function clear_authy_data( $user_id ) {
		delete_user_meta( $user_id, $this->users_key );
	}

	/**
	 * Check if Authy is enabled for user's assigned role.
	 *
	 * user_can() lets one check a role, and while it's almost always wrong to do so, it's appropriate here.
	 * Yes, part of me died a bit when I did this; see http://erick.me/15.
	 *
	 * For backwards compatibility, we assume that all users can use Authy if plugin settings haven't been saved since updating from v0.2.
	 *
	 * @since 0.3
	 * @param int $user_id
	 * @uses is_super_admin, this::get_setting, user_can
	 * @return bool
	 */
	protected function users_role_allowed( $user_id ) {
		// Make sure we have a user ID, otherwise abort
		$user_id = (int) $user_id;

		if ( ! $user_id )
			return false;

		// Super Admins get a pass
		if ( is_super_admin( $user_id ) )
			return true;

		// Bypass this check if plugin was upgraded from v0.2 but new role settings haven't been set
		if ( ! $this->get_setting( 'roles_set' ) )
			return true;

		// Parse role settings
		// If, somehow, `roles` is set but isn't an array, plugin is enabled for all users to mimick pre v0.3 behaviour.
		$selected_roles = $this->get_setting( 'roles' );

		if ( is_array( $selected_roles ) ) {
			$allowed = false;

			foreach ( $selected_roles as $role ) {
				if ( user_can( $user_id, $role ) ) {
					$allowed = true;
					break;
				}
			}

			return $allowed;
		} else {
			return true;
		}
	}

	/**
	 * Check if a given user has an Authy ID set
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @uses this::users_role_allowed, this::get_user_authy_id
	 * @return bool
	 */
	protected function user_has_authy_id( $user_id ) {
		if ( ! $this->users_role_allowed( $user_id ) )
			return false;

		return (bool) $this->get_user_authy_id( $user_id );
	}

	/**
	 * Retrieve a given user's Authy ID
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @uses this::get_authy_data
	 * @return int|bool
	 */
	protected function get_user_authy_id( $user_id ) {
		$data = $this->get_authy_data( $user_id );

		if ( is_array( $data ) && is_numeric( $data['authy_id'] ) )
			return (int) $data['authy_id'];

		return false;
	}


	/**
	 * USER SETTINGS PAGES
	 */

	/**
	 * Non-JS connection interface
	 *
	 * @since 0.1
	 * @param object $user
	 * @uses this::users_role_allowed, this::get_authy_data, esc_attr
	 */
	public function action_show_user_profile( $user ) {
		// Don't bother if the user's role can't use Authy
		if ( ! $this->users_role_allowed( $user->ID ) )
			return;

		// User's Authy data
		$meta = $this->get_authy_data( $user->ID );
	?>
		<h3><?php echo esc_html( $this->name ); ?></h3>

		<table class="form-table" id="<?php echo esc_attr( $this->users_key ); ?>">
			<?php if ( $this->user_has_authy_id( $user->ID ) ) : ?>
				<tr>
					<th><label for="<?php echo esc_attr( $this->users_key ); ?>_disable"><?php _e( 'Disable your Authy connection?', 'authy_for_wp' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo esc_attr( $this->users_key ); ?>_disable" name="<?php echo esc_attr( $this->users_key ); ?>[disable_own]" value="1" />
						<label for="<?php echo esc_attr( $this->users_key ); ?>_disable"><?php _e( 'Yes, disable Authy for your account.', 'authy_for_wp' ); ?></label>

						<?php wp_nonce_field( $this->users_key . 'disable_own', $this->users_key . '[nonce]' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th><label for="authy-country-code"><?php _e( 'Country code', 'authy_for_wp' ); ?></label></th>
					<td>
						<input type="text" class="small-text" id="authy-country-code" name="<?php echo esc_attr( $this->users_key ); ?>[country_code]" value="<?php echo esc_attr( $meta['country_code'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th><label for="authy-cellphone"><?php _e( 'Mobile number', 'authy_for_wp' ); ?></label></th>
					<td>
						<input type="tel" class="regular-text" id="authy-cellphone" name="<?php echo esc_attr( $this->users_key ); ?>[phone]" value="<?php echo esc_attr( $meta['phone'] ); ?>" />

						<?php wp_nonce_field( $this->users_key . 'edit_own', $this->users_key . '[nonce]' ); ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>

	<?php
	}

	/**
	 * Handle non-JS changes to users' own connection
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @uses check_admin_referer, wp_verify_nonce, get_userdata, is_wp_error, this::set_authy_data, this::clear_authy_data,
	 * @return null
	 */
	public function action_personal_options_update( $user_id ) {
		check_admin_referer( 'update-user_' . $user_id );

		// Check if we have data to work with
		$authy_data = isset( $_POST[ $this->users_key ] ) ? $_POST[ $this->users_key ] : false;

		// Parse for nonce and API existence
		if ( is_array( $authy_data ) && array_key_exists( 'nonce', $authy_data ) ) {
			if ( wp_verify_nonce( $authy_data['nonce'], $this->users_key . 'edit_own' ) ) {
				// Email address
				$userdata = get_userdata( $user_id );
				if ( is_object( $userdata ) && ! is_wp_error( $userdata ) )
					$email = $userdata->data->user_email;
				else
					$email = null;

				// Phone number
				$phone = preg_replace( '#[^\d]#', '', $authy_data['phone'] );
				$country_code = preg_replace( '#[^\d\+]#', '', $authy_data['country_code'] );

				// Process information with Authy
				$this->set_authy_data( $user_id, $email, $phone, $country_code );
			} elseif ( wp_verify_nonce( $authy_data['nonce'], $this->users_key . 'disable_own' ) ) {
				// Delete Authy usermeta if requested
				if ( isset( $authy_data['disable_own'] ) )
					$this->clear_authy_data( $user_id );
			}
		}
	}

	/**
	 * Allow sufficiently-priviledged users to disable another user's Authy service.
	 *
	 * @since 0.1
	 * @param object $user
	 * @uses current_user_can, this::users_role_allowed, this::user_has_authy_id, get_user_meta, wp_parse_args, esc_attr, wp_nonce_field
	 * @action edit_user_profile
	 * @return string
	 */
	public function action_edit_user_profile( $user ) {
		if ( current_user_can( 'create_users' ) && $this->users_role_allowed( $user->ID ) ) {
		?>
			<h3>Authy Two-factor Authentication</h3>

			<table class="form-table">
				<?php if ( $this->user_has_authy_id( $user->ID ) ) :
					$meta = get_user_meta( get_current_user_id(), $this->users_key, true );
					$meta = wp_parse_args( $meta, $this->user_defaults );

					$name = esc_attr( $this->users_key );
				?>
				<tr>
					<th><label for="<?php echo $name; ?>"><?php _e( "Disable user's Authy connection?", 'authy_for_wp' ); ?></label></th>
					<td>
						<input type="checkbox" id="<?php echo $name; ?>" name="<?php echo $name; ?>" value="1" />
						<label for="<?php echo $name; ?>"><?php _e( 'Yes, force user to reset the Authy connection.', 'authy_for_wp' ); ?></label>
					</td>
				</tr>
				<?php else : ?>
				<tr>
					<th><?php _e( 'Connection', 'authy_for_wp' ); ?></th>
					<td><?php _e( 'This user has not enabled Authy.', 'authy_for_wp' ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
		<?php

			wp_nonce_field( $this->users_key . '_disable', "_{$this->users_key}_wpnonce" );
		}
	}

	/**
	 * Ajax handler for users' connection manager
	 *
	 * @since 0.1
	 * @uses wp_verify_nonce, get_current_user_id, get_userdata, this::get_authy_data, this::ajax_head, body_class, esc_url, this::get_ajax_url, this::user_has_authy_id, _e, __, submit_button, wp_nonce_field, esc_attr, this::clear_authy_data, wp_safe_redirect, sanitize_email, this::set_authy_data
	 * @action wp_ajax_{$this->users_page}
	 * @return string
	 */
	public function ajax_get_id() {
		$user_id = get_current_user_id();

		// If nonce isn't set, bail
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], $this->users_key . '_ajax' ) || ! $this->users_role_allowed( $user_id ) ) {
			?><script type="text/javascript">self.parent.tb_remove();</script><?php
			exit;
		}

		// User data
		$user_data = get_userdata( $user_id );
		$authy_data = $this->get_authy_data( $user_id );

		// Step
		$step = isset( $_REQUEST['authy_step'] ) ? preg_replace( '#[^a-z0-9\-_]#i', '', $_REQUEST['authy_step'] ) : false;

		// iframe head
		$this->ajax_head();

		// iframe body
		?><body <?php body_class( 'wp-admin wp-core-ui authy-wp-user-modal no-js' ); ?>>
			<div class="wrap">
				<h2>Authy for WP</h2>

				<form action="<?php echo esc_url( $this->get_ajax_url() ); ?>" method="post">

					<?php
						switch( $step ) {
							default :
								if ( $this->user_has_authy_id( $user_id ) ) : ?>
									<p><?php _e( 'You already have any Authy ID associated with your account.', 'authy_for_wp' ); ?></p>

									<p><?php printf( __( 'You can disable Authy for your <strong>%s</strong> user by clicking the button below', 'authy_for_wp' ), $user_data->user_login ); ?></p>

									<?php submit_button( __( 'Disable Authy', 'authy_for_wp' ) ); ?>

									<input type="hidden" name="authy_step" value="disable" />
									<?php wp_nonce_field( $this->users_key . '_ajax_disable' ); ?>
								<?php else : ?>
									<p><?php printf( __( 'Authy is not yet configured for your the <strong>%s</strong> account.', 'authy_for_wp' ), $user_data->user_login ); ?></p>

									<p><?php _e( 'To enable Authy for this account, complete the form below, then click <em>Continue</em>.', 'authy_for_wp' ); ?></p>

									<table class="form-table" id="<?php echo esc_attr( $this->users_key ); ?>-ajax">
										<tr>
											<th><label for="authy-countries"><?php _e( 'Country', 'authy_for_wp' ); ?></label></th>
											<td>
												<input type="text" class="small-text" id="authy-countries" name="authy_country_code" value="<?php echo esc_attr( $authy_data['country_code'] ); ?>" />
											</td>
										</tr>
										<tr>
											<th><label for="authy-cellphone"><?php _e( 'Mobile number', 'authy_for_wp' ); ?></label></th>
											<td>
												<input type="tel" class="regular-text" id="authy-cellphone" name="authy_phone" value="<?php echo esc_attr( $authy_data['phone'] ); ?>" />
											</td>
										</tr>
									</table>

									<input type="hidden" name="authy_step" value="check" />
									<?php wp_nonce_field( $this->users_key . '_ajax_check' ); ?>

									<?php submit_button( __( 'Continue', 'authy_for_wp' ) ); ?>

								<?php endif;

								break;

							case 'disable' :
								if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], $this->users_key . '_ajax_disable' ) )
									$this->clear_authy_data( $user_id );

								wp_safe_redirect( $this->get_ajax_url() );
								exit;

								break;

							case 'check' :
								if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], $this->users_key . '_ajax_check' ) ) {
									$email = sanitize_email( $user_data->user_email );
									$phone = isset( $_POST['authy_phone'] ) ? preg_replace( '#[^\d]#', '', $_POST['authy_phone'] ) : false;
									$country_code = isset( $_POST['authy_country_code'] ) ? preg_replace( '#[^\d]#', '', $_POST['authy_country_code'] ) : false;

									if ( $email && $phone && $country_code ) {
										$this->set_authy_data( $user_id, $email, $phone, $country_code );

										if ( $this->user_has_authy_id( $user_id ) ) : ?>
											<p><?php printf( __( 'Congratulations, Authy is now configured for your <strong>%s</strong> user account.', 'authy_for_wp' ), $user_data->user_login ); ?></p>

											<p><?php _e( 'Until disabled, you will be asked for an Authy token each time you log in.', 'authy_for_wp' ); ?></p>

											<p><a class="button button-primary" href="#" onClick="self.parent.tb_remove();return false;"><?php _e( 'Return to your profile', 'authy_for_wp' ); ?></a></p>
										<?php else : ?>
											<p><?php printf( __( 'Authy could not be activated for the <strong>%s</strong> user account.', 'authy_for_wp' ), $user_data->user_login ); ?></p>

											<p><?php _e( 'Please try again later.', 'authy_for_wp' ); ?></p>

											<p><a class="button button-primary" href="<?php echo esc_url( $this->get_ajax_url() ); ?>"><?php _e( 'Try again', 'authy_for_wp' ); ?></a></p>
										<?php endif;

										exit;
									}
								}

								wp_safe_redirect( $this->get_ajax_url() );
								exit;

								break;
						}
					?>
				</form>
			</div>
		</body><?php

		exit;
	}

	/**
	 * Clear a user's Authy configuration if an allowed user requests it.
	 *
	 * @since 0.1
	 * @param int $user_id
	 * @uses wp_verify_nonce, this::clear_authy_data
	 * @action edit_user_profile_update
	 * @return null
	 */
	public function action_edit_user_profile_update( $user_id ) {
		if ( isset( $_POST["_{$this->users_key}_wpnonce"] ) && wp_verify_nonce( $_POST["_{$this->users_key}_wpnonce"], $this->users_key . '_disable' ) ) {
			if ( isset( $_POST[ $this->users_key ] ) )
				$this->clear_authy_data( $user_id );
		}
	}

	/**
	 * AUTHENTICATION CHANGES
	 */

	/**
	 * Enqueue scripts to support SMS integration, if available
	 *
	 * @since 0.2
	 * @uses wp_enqueue_script, plugins_url, wp_enqueue_style
	 * @action login_enqueue_scripts
	 * @return null
	 */
	public function action_login_enqueue_scripts() {
		if ( $this->ready && $this->sms ) {
			wp_enqueue_script( 'authy-wp-login', plugins_url( 'assets/authy-wp-login.js', __FILE__ ), array( 'jquery', 'thickbox' ), 1.0, false );
			wp_localize_script( 'authy-wp-login', 'AuthyForWP', array( 'ajax' => $this->get_ajax_url( 'sms' ) ) );

			wp_enqueue_style( 'thickbox' );
		}
	}

	/**
	 * Render Ajax modal for SMS tokens at login
	 *
	 * @since 0.2
	 * @uses this::ajax_head, body_class, _e, esc_url, this::get_ajax_url, sanitize_user, esc_attr, wp_nonce_field, submit_button, __, wp_verify_nonce, get_user_by, is_wp_error, this::user_has_authy_id, this::api::send_sms, this::get_user_authy_id
	 * @action wp_ajax_nopriv_{$this->sms_action}
	 * @return string
	 */
	public function ajax_sms_login() {
		// iframe head
		$this->ajax_head();

		// iframe body
		?><body <?php body_class( 'wp-admin wp-core-ui authy-wp-sms no-js' ); ?>>
			<div class="wrap">
				<h2>Authy for WP: <?php _e( 'SMS', 'authy_for_wp' ); ?></h2>

				<form action="<?php echo esc_url( $this->get_ajax_url( 'sms' ) ); ?>" method="post">

					<?php
						if ( ! $this->ready || ! $this->sms ) {
						?>
							<p><?php _e( "This feature isn't available at this time.", 'authy_for_wp' ); ?></p>

							<p><a class="button button-primary hide-if-no-js" href="#" onClick="self.parent.tb_remove();return false;"><?php _e( 'Return to login', 'authy_for_wp' ); ?></a></p>
						<?php
						} else {
							// Do we have a username?
							$username = isset( $_REQUEST['username'] ) ? sanitize_user( $_REQUEST['username'] ) : '';

							// Step
							$step = isset( $_REQUEST['authy_step'] ) ? preg_replace( '#[^a-z0-9\-_]#i', '', $_REQUEST['authy_step'] ) : false;

							switch( $step ) {
								default : ?>
									<p><?php _e( "If you don't have access to the Authy app, you can receive an token via SMS.", 'authy_for_wp' ); ?></p>

									<p><?php _e( 'Enter your username to continue.', 'authy_for_wp' ); ?></p>

									<table class="form-table" id="<?php echo esc_attr( $this->users_key ); ?>-ajax">
										<tr>
											<th><label for="username"><?php _e( 'Username', 'authy_for_wp' ); ?></label></th>
											<td>
												<input type="tel" class="regular-text" id="username" name="authy_username" value="<?php echo esc_attr( $username ); ?>" />
											</td>
										</tr>
									</table>

									<input type="hidden" name="authy_step" value="check" />
									<?php wp_nonce_field( $this->users_key . '_ajax_check' ); ?>

									<?php submit_button( __( 'Continue', 'authy_for_wp' ) );

									break;

								case 'check' :
									if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], $this->users_key . '_ajax_check' ) && isset( $_POST['authy_username'] ) ) {
										$username = sanitize_user( $_POST['authy_username'] );

										$user = get_user_by( 'login', $username );

										if ( is_object( $user ) && ! is_wp_error( $user ) ) {
											if ( $this->user_has_authy_id( $user->ID ) ) {
												$sms = $this->api->send_sms( $this->get_user_authy_id( $user->ID ), true );

												if ( 200 == $sms ) {
												?>
													<p><?php printf( __( 'A text message containing an Authy token was sent to the mobile number used to enable Authy for the user account <strong>%s</strong>.', 'authy_for_wp' ), $username ); ?></p>

													<p><?php printf( __( 'Once you receive the text message, enter the code from the text message in the &quot;%s&quot; login field.', 'authy_for_wp' ), __( 'Authy Token', 'authy_for_wp' ) ); ?></p>
												<?php
												} else {
												?>
													<p><?php printf( __( 'A problem occurred sending an Authy token by SMS. Please try again later.', 'authy_for_wp' ), $username ); ?></p>
												<?php
												}
											} else {
											?>
												<p><?php printf( __( "Authy isn't enabled for the <strong>%s</strong> user account.", 'authy_for_wp' ), $username ); ?></p>

												<p><?php _e( 'You can log in without providing an Authy ID.', 'authy_for_wp' ); ?></p>
											<?php
											}

											?><p><a class="button button-primary hide-if-no-js" href="#" onClick="self.parent.tb_remove();return false;"><?php _e( 'Return to login', 'authy_for_wp' ); ?></a></p><?php
										} else {
										?>
											<p><?php printf( __( "A WordPress user account for <strong>%s</strong> doesn't exist.", 'authy_for_wp' ), $username ); ?></p>

											<p><?php _e( 'Please check your username and try again.', 'authy_for_wp' ); ?></p>

											<p><a class="button button-primary" href="<?php echo esc_url( $this->get_ajax_url( 'sms' ) ); ?>"><?php _e( 'Try again', 'authy_for_wp' ); ?></a></p>
										<?php
										}
									} else {
										wp_safe_redirect( $this->get_ajax_url( 'sms' ) );
										exit;
									}

									break;
							}
						}
					?>
				</form>
			</div>
		</body><?php

		exit;
	}

	/**
	 * Add Authy input field to login page
	 *
	 * @since 0.1
	 * @uses _e
	 * @action login_form
	 * @return string
	 */
	public function action_login_form() {
		?>
		<p>
			<label for="authy_token"><?php
				_e( 'Authy Token', 'authy_for_wp' );

				if ( $this->sms ) : ?> (<a href="<?php echo esc_url( $this->get_ajax_url( 'sms' ) ); ?>" id="authy-send-sms" target="_blank"><?php _e( 'Send SMS instead', 'authy_for_wp' ); ?></a>)<?php endif; ?>
			<br>
			<input type="text" name="authy_token" id="authy_token" class="input" value="" size="20" autocomplete="off"></label>
		</p>
		<?php
	}

	/**
	 * Attempt Authy verification if conditions are met.
	 *
	 * @since 0.1
	 * @param mixed $user
	 * @param string $username
	 * @uses XMLRPC_REQUEST, APP_REQUEST, this::user_has_authy_id, this::get_user_authy_id, this::api::check_token
	 * @return mixed
	 */
	public function action_authenticate( $user, $username ) {
		// If we don't have a username yet, or the method isn't supported, stop.
		if ( empty( $username ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'APP_REQUEST' ) && APP_REQUEST ) )
			return $user;

		// Don't bother if WP can't provide a user object.
		if ( ! is_object( $user ) || ! property_exists( $user, 'ID' ) )
			return $user;

		// User must opt in.
		if ( ! $this->user_has_authy_id( $user->ID ) )
			return $user;

		// If a user has opted in, he/she must provide a token
		if ( ! isset( $_POST['authy_token'] ) || empty( $_POST['authy_token'] ) )
			return new WP_Error( 'authentication_failed', sprintf( __( '<strong>ERROR</strong>: To log in as <strong>%s</strong>, you must provide an Authy token.' ), $username ) );

		// Check the specified token
		$authy_id = $this->get_user_authy_id( $user->ID );
		$authy_token = preg_replace( '#[^\d]#', '', $_POST['authy_token'] );
		$api_check = $this->api->check_token( $authy_id, $authy_token );

		// Act on API response
		if ( false === $api_check )
			return null;
		elseif ( is_string( $api_check ) )
			return new WP_Error( 'authentication_failed', __( '<strong>ERROR</strong>: ' . $api_check ) );

		return $user;
	}
}

Authy_WP::instance();
