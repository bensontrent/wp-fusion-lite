<?php

class WPF_ActiveCampaign_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_activecampaign_header_begin', array( $this, 'show_field_activecampaign_header_begin' ), 10, 2 );
		add_action( 'show_field_ac_key_end', array( $this, 'show_field_ac_key_end' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function init() {

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
		add_action( 'wpf_resync_contact', array( $this, 'resync_lists' ) );

		add_filter( 'validate_field_site_tracking', array( $this, 'validate_site_tracking' ), 10, 2 );
		add_filter( 'wpf_initialize_options', array( $this, 'maybe_get_tracking_id' ), 10 );

	}


	/**
	 * Loads ActiveCampaign connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['activecampaign_header'] = array(
			'title'   => __( 'ActiveCampaign Configuration', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'heading',
			'section' => 'setup'
		);

		$new_settings['ac_url'] = array(
			'title'   => __( 'API URL', 'wp-fusion' ),
			'desc'    => __( 'Enter the API URL for your ActiveCampaign account (find it under Settings >> Developer in your account).', 'wp-fusion' ),
			'std'     => '',
			'type'    => 'text',
			'section' => 'setup'
		);

		$new_settings['ac_key'] = array(
			'title'       => __( 'API Key', 'wp-fusion' ),
			'desc'        => __( 'The API key will appear beneath the API URL on the Developer settings page.', 'wp-fusion' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'ac_url', 'ac_key' )
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads ActiveCampaign specific settings fields
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function register_settings( $settings, $options ) {

		if( ! isset( $options['available_lists'] ) ) {
			$options['available_lists'] = array();
		}

		$new_settings['ac_lists'] = array(
			'title'       => __( 'Lists', 'wp-fusion' ),
			'desc'        => __( 'New contacts will be automatically added to the selected lists.', 'wp-fusion' ),
			'type'        => 'multi_select',
			'placeholder' => 'Select lists',
			'section'     => 'main',
			'choices'     => $options['available_lists']
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'assign_tags', $settings, $new_settings );

		if ( ! isset( $settings['create_users']['unlock']['ac_lists'] ) ) {
			$settings['create_users']['unlock'][] = 'ac_lists';
		}

		$settings['ac_lists']['disabled'] = ( wp_fusion()->settings->get( 'create_users' ) == 0 ? true : false );


		// Add site tracking option
		$site_tracking = array();

		$site_tracking['site_tracking_header'] = array(
			'title'   => __( 'ActiveCampaign Site Tracking', 'wp-fusion' ),
			'desc'    => '',
			'std'     => '',
			'type'    => 'heading',
			'section' => 'main'
		);

		$site_tracking['site_tracking'] = array(
			'title'   => __( 'Site Tracking', 'wp-fusion' ),
			'desc'    => __( 'Enable <a target="_blank" href="https://help.activecampaign.com/hc/en-us/articles/221493708-How-to-set-up-Site-Tracking">ActiveCampaign site tracking</a>.', 'wp-fusion' ),
			'std'     => 0,
			'type'    => 'checkbox',
			'section' => 'main'
		);

		$site_tracking['site_tracking_id'] = array(
			'std'     => '',
			'type'    => 'hidden',
			'section' => 'main'
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'profile_update_tags', $settings, $site_tracking );

		return $settings;

	}


	/**
	 * Loads standard ActiveCampaign field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once dirname( __FILE__ ) . '/activecampaign-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $activecampaign_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $activecampaign_fields[ $field ] );
				}

			}

		}

		return $options;

	}

	/**
	 * Enable / disable site tracking depending on selected option
	 *
	 * @access public
	 * @return bool Input
	 */

	public function validate_site_tracking( $input, $setting ) {

		$previous = wp_fusion()->settings->get('site_tracking', false);

		// Activate site tracking
		if( $input == true && $previous == false ) {

			wp_fusion()->crm->connect();
			wp_fusion()->crm->app->version(2);
			wp_fusion()->crm->app->api( 'tracking/site/status', array( 'status' => 'enable' ) );
			wp_fusion()->crm->app->api( 'tracking/whitelist', array( 'domain' => home_url() ) );

		}

		return $input;

	}

	/**
	 * Gets and saves tracking ID if site tracking is enabled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function maybe_get_tracking_id( $options ) {

		if ( isset( $options['site_tracking'] ) && $options['site_tracking'] == true && empty( $options['site_tracking_id'] ) ) {

			$this->crm->connect();
			$trackid = $this->crm->get_tracking_id();

			if ( empty( $trackid ) ) {
				return $options;
			}

			$options['site_tracking_id'] = $trackid;
			wp_fusion()->settings->set( 'site_tracking_id', $trackid );

		}

		return $options;

	}


	/**
	 * Puts a div around the Infusionsoft configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */

	public function show_field_activecampaign_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}

	/**
	 * Close out Active Campaign section
	 *
	 * @access  public
	 * @since   1.0
	 */


	public function show_field_ac_key_end( $id, $field ) {

		if ( $field['desc'] != '' ) {
			echo '<span class="description">' . $field['desc'] . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table><div id="connection-output"></div>';
		echo '</div>'; // close #activecampaign div
		echo '<table class="form-table">';

	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */

	public function test_connection() {

		$api_url = esc_url_raw( $_POST['ac_url'] );
		$api_key = sanitize_text_field( $_POST['ac_key'] );

		$connection = $this->crm->connect( $api_url, $api_key, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['ac_url']                = $api_url;
			$options['ac_key']                = $api_key;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;
			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}

	/**
	 * Triggered by Resync Contact button, loads lists for contact and saves to user meta
	 *
	 * @access public
	 * @return void
	 */

	public function resync_lists( $user_id ) {

		if ( is_wp_error( $this->crm->connect() ) ) {
			return false;
		}

		$contact_id = wp_fusion()->user->get_contact_id( $user_id );

		$result = $this->crm->app->api( 'contact/view?id=' . $contact_id );

		$lists = array();

		foreach ( $result->lists as $list_object ) {

			$lists[] = $list_object->listid;

		}

		update_user_meta( $user_id, 'activecampaign_lists', $lists );

	}


}