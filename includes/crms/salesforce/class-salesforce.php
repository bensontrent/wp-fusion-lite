<?php

class WPF_Salesforce {

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Contains SF instance URL
	 */

	public $instance_url;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Lets outside functions override the object type (Leads for example)
	 */

	public $object_type;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'salesforce';
		$this->name     = 'Salesforce';
		$this->supports = array();

		$this->object_type = 'Contact';

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Salesforce_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 10, 3 );

	}

	/**
	 * Formats user entered data to match Salesforce field formats
	 *
	 * @access public
	 * @return mixed
	 */

	public function format_field_value( $value, $field_type, $field ) {

		if ( $field_type == 'datepicker' || $field_type == 'date' ) {

			// Adjust formatting for date fields
			$date = date( 'Y-m-d', $value );

			return $date;

		} else {

			return $value;

		}

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos($url, 'salesforce') !== false ) {

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_message = wp_remote_retrieve_response_message( $response );

			if( $response_code == 401 ) {

				// Reauthorize
				remove_filter( 'http_response', array( $this, 'handle_http_response' ), 10, 3 );

				$this->connect(null, null, true);
				$response = wp_remote_request( $url, $args );

			} if( $response_code != 200 && $response_code != 201 && $response_code != 204 && !empty( $response_message ) ) {

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				if( is_array( $body ) && ! empty( $body[0] ) && ! empty( $body[0]->message ) ) {

					$response_message = $response_message . ' - ' . $body[0]->message;

				} elseif ( is_object( $body ) && isset( $body->error_description ) ) {

					$response_message = $response_message . ' - ' . $body->error_description;

				}

				$response = new WP_Error( 'error', $response_message );
				
			}

		}

		return $response;

	}


	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params() {

		// Get saved data from D
		$access_token = wp_fusion()->settings->get( 'sf_access_token' );

		$this->params = array(
			'timeout'     => 240,
			'headers'     => array( 'Authorization' => 'Bearer ' . $access_token )
		);

		$this->instance_url = wp_fusion()->settings->get('sf_instance_url');

		$this->object_type = apply_filters( 'wpf_crm_object_type', $this->object_type );

		return $this->params;
	}


	/**
	 * Initialize connection and get access token
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $username = null, $token = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if($token == null || $username == null) {
			$token = wp_fusion()->settings->get( 'sf_combined_token' );
			$username = wp_fusion()->settings->get( 'sf_username' );
		}

		$auth_args = array(
			'grant_type' 	=> 'password',
			'client_id' 	=> '3MVG9CEn_O3jvv0xMf5rhesocmw9vf_OV6x9fHYfh4bnqRC1zUohKbulHXLyuMdCaXEliMqXtW6XVAMiNa55K',
			'client_secret' => '6100590890846411326',
			'username' 		=> urlencode($username),
			'password' 		=> urlencode($token)
		);

		$auth_url = add_query_arg($auth_args, 'https://login.salesforce.com/services/oauth2/token');
		$response = wp_remote_post( $auth_url );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $body->error ) ) {
			return new WP_Error( $body->error, $body->error_description );
		}

		wp_fusion()->settings->set( 'sf_access_token', $body->access_token );
		wp_fusion()->settings->set( 'sf_instance_url', $body->instance_url );

		// Set params for subsequent ops
		$this->get_params();

		return true;
	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$query_args = array( 'q' => 'SELECT%20Name,%20Id%20from%20TagDefinition' );

		$request  = add_query_arg($query_args, $this->instance_url . '/services/data/v20.0/query');

		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {

			// For accounts without tags
			if( strpos( $response->get_error_message(), "'TagDefinition' is not supported" ) !== false ) {
				return array();
			}

			return $response;
		}

		$response = json_decode(wp_remote_retrieve_body( $response ));

		if(!empty($response->records)) {
			foreach($response->records as $tag) {
				$available_tags[$tag->Id] = $tag->Name;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;

	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$crm_fields = array();

		$request  = $this->instance_url . '/services/data/v20.0/sobjects/' . $this->object_type . '/describe/';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode(wp_remote_retrieve_body( $response ));

		foreach($response->fields as $field) {
			$crm_fields[$field->name] = $field->label;
		}

		// Clean up system fields
		unset( $crm_fields['Id'] );
		unset( $crm_fields['isDeleted'] );
		unset( $crm_fields['accountId'] );

		asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$query_args = array( 'q' => "SELECT Id from " . $this->object_type . " WHERE Email = '" . $email_address . "'" );

		$request  = add_query_arg($query_args, $this->instance_url . '/services/data/v20.0/query');

		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode(wp_remote_retrieve_body( $response ));

		if(empty($response) || empty($response->records))  {
			return false;
		}

		return $response->records[0]->Id;

	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_tags = array();

		$query_args = array( 'q' => "SELECT TagDefinitionId from ContactTag WHERE ItemId = '" . $contact_id . "'" );

		$request  = add_query_arg($query_args, $this->instance_url . '/services/data/v20.0/query');

		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode(wp_remote_retrieve_body( $response ));

		if(empty($response) || empty($response->records))  {
			return false;
		}

		$available_tags = wp_fusion()->settings->get( 'available_tags', array() );
		$needs_tag_sync = false;

		foreach($response->records as $tag) {

			$contact_tags[] = $tag->TagDefinitionId;

			if(!isset($available_tags[$tag->TagDefinitionId]))
				$needs_tag_sync = true;

		}

		if($needs_tag_sync)
			$this->sync_tags();

		return $contact_tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$params = $this->get_params();
		$params['headers']['Content-Type'] = 'application/json';

		foreach($tags as $tag_id) {

			$label = wp_fusion()->user->get_tag_label( $tag_id );

			$body = array(
				'Type'		=> wp_fusion()->settings->get('sf_tag_type', 'Personal'),
				'ItemID'	=> $contact_id,
				'Name'		=> $label
				);

			$params['body'] = json_encode($body);

			$response = wp_remote_post($this->instance_url . '/services/data/v20.0/sobjects/ContactTag/', $params);

			if( is_wp_error( $response ) ) {
				return $response;
			}

		}

		return true;
	}

	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$params = $this->get_params();
		$sf_tag_ids_to_remove = array();

		// First get the tag relationship IDs

		$query_args = array( 'q' => "SELECT Id, TagDefinitionId from ContactTag WHERE ItemId = '" . $contact_id . "'" );
		$request  = add_query_arg($query_args, $this->instance_url . '/services/data/v20.0/query');

		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode(wp_remote_retrieve_body( $response ));

		if(empty($response) || empty($response->records))  {
			return false;
		}


		foreach($response->records as $tag) {

			if(in_array($tag->TagDefinitionId, $tags)) {
				$sf_tag_ids_to_remove[] = $tag->Id;
			}

		}

		if(!empty( $sf_tag_ids_to_remove )) {

			$params['method'] = 'DELETE';

			foreach( $sf_tag_ids_to_remove as $tag_id ) {

				$response = wp_remote_request($this->instance_url . '/services/data/v20.0/sobjects/ContactTag/' . $tag_id, $params );

				if( is_wp_error( $response ) ) {
					return $response;
				}

			}

		}

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		$params = $this->get_params();
		$params['headers']['Content-Type'] = 'application/json';

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		$params['body'] = json_encode( $data );
		$response = wp_remote_post( $this->instance_url . '/services/data/v20.0/sobjects/' . $this->object_type . '/', $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode(wp_remote_retrieve_body($response));

		return $body->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		$params = $this->get_params();
		$params['headers']['Content-Type'] = 'application/json';

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if ( empty( $data ) ) {
			return true;
		}

		$params['body'] = json_encode( $data );
		$params['method'] = 'PATCH';
		$response = wp_remote_request( $this->instance_url . '/services/data/v20.0/sobjects/' . $this->object_type . '/' . $contact_id, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$response = wp_remote_get( $this->instance_url . '/services/data/v20.0/sobjects/' . $this->object_type . '/' . $contact_id, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( $field_data['active'] == true && isset( $body[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body[ $field_data['crm_field'] ];
			}

		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_ids = array();

		$query_args = array( 'q' => "SELECT ItemId, TagDefinitionId from ContactTag where TagDefinitionId = '" . $tag . "'" );

		$request  = add_query_arg($query_args, $this->instance_url . '/services/data/v20.0/query');

		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode(wp_remote_retrieve_body( $response ));

		if( ! empty($response->records)) {

			foreach($response->records as $contact) {
				$contact_ids[] = $contact->ItemId;
			}

		}

		return $contact_ids;

	}

}