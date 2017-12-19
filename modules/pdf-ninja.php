<?php

class WPCF7_Pdf_Ninja extends WPCF7_Pdf_Forms_Service
{
	private static $instance = null;
	private $key = null;
	private $verify_ssl = null;
	private $error = null;
	
	const API_SERVER_URL = 'https://pdf.ninja';
	
	private function __construct() { }
	
	/*
	 * Returns a global instance of this class
	 */
	public static function get_instance()
	{
		if( !self::$instance )
			self::$instance = new self;
		
		return self::$instance;
	}
	
	/*
	 * Returns service name that this service provides
	 */
	public function get_service_name()
	{
		return 'pdf_ninja';
	}
	
	
	/*
	 * WPCF7_Service defined function
	 */
	public function get_title()
	{
		return __( 'Pdf.Ninja API', 'wpcf7-pdf-forms' );
	}
	
	/*
	 * WPCF7_Service defined function
	 */
	public function is_active()
	{
		try
		{
			$class = get_class();
			return ($this->get_key() != null) && (WPCF7_Pdf_Forms::get_instance()->get_service() instanceof $class);
		}
		catch(Exception $e)
		{
			$this->error = $e->getMessage();
			return false;
		}
	}
	
	/*
	 * WPCF7_Service defined function
	 */
	public function get_categories()
	{
		return array( 'pdf_forms' );
	}
	
	/*
	 * WPCF7_Service defined function
	 */
	public function icon() { }
	
	/*
	 * WPCF7_Service defined function
	 */
	public function link()
	{
		echo '<a href="https://pdf.ninja/">Pdf.Ninja</a>';
	}
	
	/*
	 * Returns (and initializes, if necessary) the current API key
	 */
	public function get_key()
	{
		if( ! $this->key )
			$this->key = WPCF7::get_option( 'wpcf7_pdf_forms_pdfninja_key' );
		
		if( ! $this->key )
			$this->set_key( $this->generate_key() );
		
		return $this->key;
	}
	
	/*
	 * Sets the current API key
	 */
	public function set_key( $value )
	{
		$this->key = $value;
		WPCF7::update_option( 'wpcf7_pdf_forms_pdfninja_key', $value );
		return true;
	}
	
	public function get_verify_ssl()
	{
		if( $this->verify_ssl === null )
		{
			$value = WPCF7::get_option( 'wpcf7_pdf_forms_verify_ssl' );
			if( $value == 'true' ) $this->verify_ssl = true;
			if( $value == 'false' ) $this->verify_ssl = false;
		}
		
		if( $this->verify_ssl === null )
			$this->set_verify_ssl( true );
		
		return $this->verify_ssl;
	}
	
	public function set_verify_ssl( $value )
	{
		$this->verify_ssl = $value;
		WPCF7::update_option( 'wpcf7_pdf_forms_verify_ssl', $value ? 'true' : 'false' );
		return true;
	}
	
	/*
	 * Requests a key from the API server
	 */
	public function generate_key()
	{
		$current_user = wp_get_current_user();
		
		if( ! $current_user )
			return null;
		
		$email = sanitize_email($current_user->user_email);
		
		if( ! $email )
			return null;
		
		return $this->api_get_key($email);
	}
	
	private function wp_remote_args()
	{
		return array(
			'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
			'compress'    => true,
			'decompress'  => true,
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => 'wpcf-pdf-forms/0.1',
			'sslverify'   => $this->get_verify_ssl(),
		);
	}
	
	/*
	 * Helper function for communicating with the API via the GET request
	 */
	private function api_get( $endpoint, $params )
	{
		$url = add_query_arg( $params, self::API_SERVER_URL . '/api/v1/' . $endpoint );
		$response = wp_remote_get( $url, $this->wp_remote_args() );
		
		if( is_wp_error( $response ) )
			throw new Exception( implode( ', ', $response->get_error_messages() ) );
		
		$body = wp_remote_retrieve_body( $response );
		
		if( ! $body )
			throw new Exception( __( "Failed to get API server response", 'wpcf7-pdf-forms' ) );
		
		$response = json_decode( $body , true );
		
		if( ! $response )
			throw new Exception( __( "Failed to decode API server response", 'wpcf7-pdf-forms' ) );
		
		return $response;
	}
	
	/*
	 * Helper function for communicating with the API via the POST request
	 */
	private function api_post( $endpoint, $payload, $headers = array(), $args_override = array() )
	{
		$args = $this->wp_remote_args();
		
		$args['body'] = $payload;
		
		if( is_array( $headers ) )
			foreach( $headers as $key => $value )
				$args['headers'][$key] = $value;
		
		if( is_array( $args_override ) )
			foreach( $args_override as $key => $value )
				$args[$key] = $value;
		
		$response = wp_remote_post( self::API_SERVER_URL . '/api/v1/' . $endpoint, $args );
		
		$body = wp_remote_retrieve_body( $response );
		
		if( ! $body )
			throw new Exception( __( "Failed to get API server response", 'wpcf7-pdf-forms' ) );
		
		$response = json_decode( $body , true );
		
		if( ! $response )
			throw new Exception( __( "Failed to decode API server response", 'wpcf7-pdf-forms' ) );
		
		return $response;
	}
	
	/*
	 * Communicates with the API server to get a new key
	 */
	public function api_get_key( $email )
	{
		$result = $this->api_get('key', array( 'email' => $email ) );
		
		if( $result['success'] != true )
			throw new Exception( $result['error'] );
		
		if( ! $result['key'])
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'wpcf7-pdf-forms' ) );
		
		return $result['key'];
	}
	
	/*
	 * Generates and returns file id to be used with the API server
	 */
	private function get_file_id( $attachment_id )
	{
		$file_id = WPCF7_Pdf_Forms::get_meta( $attachment_id, 'file_id' );
		if( ! $file_id )
		{
			$file_id = $attachment_id . "-" . get_site_url();
			return WPCF7_Pdf_Forms::set_meta( $attachment_id, 'file_id', $file_id );
		}
		else
			return $file_id;
	}
	
	/*
	 * Returns true if file hasn't yet been uploaded to the API server
	 */
	private function is_new_file( $attachment_id )
	{
		return WPCF7_Pdf_Forms::get_meta( $attachment_id, 'file_id' ) == null;
	}
	
	/*
	 * Communicates with the API to upload the media file
	 */
	public function api_upload_file( $attachment_id )
	{
		$md5sum = WPCF7_Pdf_Forms::update_attachment_md5sum( $attachment_id );
		
		$params = array(
			'fileId' => $this->get_file_id( $attachment_id ),
			'md5sum' => $md5sum,
			'key'    => $this->get_key(),
		);
		
		$boundary = wp_generate_password( 24 );
		
		$payload = "";
		
		foreach( $params as $name => $value )
			$payload .= "--{$boundary}\r\n"
			          . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
			          . "\r\n"
			          . "{$value}\r\n";
		
		$filepath = get_attached_file( $attachment_id );
		
		if( ! file_exists( $filepath ) )
			throw new Exception( __( "File not found", 'wpcf7-pdf-forms' ) );
		
		$filename = basename( $filepath );
		$filecontents = file_get_contents( $filepath );
		
		$payload .= "--{$boundary}\r\n"
		          . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
		          . "Content-Type: application/octet-stream\r\n"
		          . "\r\n"
		          . "{$filecontents}\r\n";
		
		$payload .= "--{$boundary}--";
		
		$headers  = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
		$args = array( 'timeout' => 300 );
		
		$result = $this->api_post( 'file', $payload, $headers, $args );
		
		if( $result['success'] != true )
			throw new Exception( $result['error'] );
		
		return true;
	}
	
	/*
	 * Returns true if we need to retry the action that works on the file
	 */
	private function api_check_retry( $previous_result, $attachment_id )
	{
		if( ! is_array( $previous_result ) )
			return false;
		
		// retry uploading the file
		// if file is gone from the API server
		// or if there is a md5 mismatch
		if( isset( $previous_result['reason'] ) )
			if( $previous_result['reason'] == 'noSuchFileId'
			||  $previous_result['reason'] == 'md5sumMismatch' )
				if( $this->api_upload_file( $attachment_id ) )
					return true;
		
		return false;
	}
	
	/*
	 * Helper function for communicating with the API to obtain the PDF file fields
	 */
	public function api_get_fields_helper( $attachment_id )
	{
		if( $this->is_new_file( $attachment_id ) )
			if( ! $this->api_upload_file( $attachment_id ) )
				return null;
		
		return $this->api_get( 'fields', array(
			'fileId' => $this->get_file_id( $attachment_id ),
			'md5sum' => WPCF7_Pdf_Forms::get_attachment_md5sum( $attachment_id ),
			'key'    => $this->get_key(),
		) );
	}
	
	/*
	 * Communicates with the API to obtain the PDF file fields
	 */
	public function api_get_fields( $attachment_id )
	{
		$result = $this->api_get_fields_helper( $attachment_id );
		
		if( $this->api_check_retry( $result, $attachment_id ) )
			$result = $this->api_get_fields_helper( $attachment_id );
		
		if( $result['success'] != true )
			throw new Exception( $result['error'] );
		
		if( ! is_array( $result['fields'] ) )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'wpcf7-pdf-forms' ) );
		
		return $result['fields'];
	}
	
	/*
	 * Helper function for communicating with the API to fill fields in the PDF file
	 */
	private function api_fill_helper( $attachment_id, $data )
	{
		if( $this->is_new_file( $attachment_id ) )
			if( ! $this->api_upload_file( $attachment_id ) )
				return null;
		
		$encoded_data = WPCF7_Pdf_Forms::json_encode( $data );
		if( $encoded_data === FALSE || $encoded_data === null )
			throw new Exception( __( "Failed to encode JSON data", 'wpcf7-pdf-forms' ) );
		
		$params = array(
			'fileId' => $this->get_file_id( $attachment_id ),
			'md5sum' => WPCF7_Pdf_Forms::get_attachment_md5sum( $attachment_id ),
			'key'    => $this->get_key(),
			'data'   => $encoded_data
		);
		
		return $this->api_post( 'fill', $params );
	}
	
	/*
	 * Communicates with the API to fill fields in the PDF file
	 */
	public function api_fill( $destfile, $attachment_id, $data )
	{
		$result = $this->api_fill_helper( $attachment_id, $data );
		
		if( $this->api_check_retry( $result, $attachment_id ) )
			$result = $this->api_fill_helper( $attachment_id, $data );
		
		if( $result['success'] != true )
			throw new Exception( $result['error'] );
		
		if( ! $result['fileUrl'] )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'wpcf7-pdf-forms' ) );
		
		$args = $this->wp_remote_args();
		$args['timeout'] = 100;
		$response = wp_remote_get( $result['fileUrl'], $args );
		if( is_wp_error( $response ) )
			throw new Exception( __( "Cannot download PDF file from the API server", 'wpcf7-pdf-forms' ) );
		
		$handle = @fopen( $destfile, 'w' );
		
		if( ! $handle )
			throw new Exception( __( "Cannot open temporary PDF file for writing", 'wpcf7-pdf-forms' ) );
		
		fwrite( $handle, $response['body'] );
		fclose( $handle );
		
		if( ! file_exists( $destfile ) )
			throw new Exception( __( "Cannot create temporary PDF file", 'wpcf7-pdf-forms' ) );
		
		return true;
	}
	
	/*
	 * Helper function for getting menu page URL
	 */
	private function menu_page_url( $args = '' )
	{
		$args = wp_parse_args( $args, array() );
		
		$url = menu_page_url( 'wpcf7-integration', false );
		$url = add_query_arg( array( 'service' => $this->get_service_name() ), $url );
		
		if ( ! empty( $args ) )
			$url = add_query_arg( $args, $url );
		
		return $url;
	}
	
	/*
	 * WPCF7_Service defined function used to process integration POST requests
	 */
	public function load( $action = '' )
	{
		if( 'edit' == $action )
		{
			if( 'POST' == $_SERVER['REQUEST_METHOD'] )
			{
				try
				{
					check_admin_referer( 'wpcf7-pdfninja-edit' );
					
					if ( ! current_user_can( 'wpcf7_manage_integration' ) )
						throw new Exception( __( "Permission denied", 'wpcf7-pdf-forms' ) );
					
					if( $_POST['new'] )
						$key = $this->generate_key();
					else
						$key = isset( $_POST['key'] ) ? trim( wp_unslash( $_POST['key'] ) ) : null;
					
					$nosslverify = isset( $_POST['nosslverify'] ) ? trim( wp_unslash( $_POST['nosslverify'] ) ) : false;
					
					$this->set_verify_ssl( !(bool)$nosslverify );
					
					if( $key && $this->set_key( $key ) )
						wp_safe_redirect( $this->menu_page_url( array( 'message' => 'success' ) ) );
				}
				catch(Exception $e)
				{
					$this->error = $e->getMessage();
				}
			}
		}
	}
	
	/*
	 * WPCF7_Service defined function used to display integration web UI
	 */
	public function display( $action = '' )
	{
		try
		{
			if( 'edit' == $action )
				return $this->display_edit();
			
			if( ! $this->is_active() && $this->error )
				return $this->display_error();
			
			return $this->display_info();
		}
		catch(Exception $e)
		{
			$this->error = $e->getMessage();
			return $this->display_error();
		}
	}
	
	/*
	 * Displays integration info web UI
	 */
	public function display_info()
	{
		echo WPCF7_Pdf_Forms::render( 'pdfninja_integration_info', array(
			'top-message' => esc_html__( "This service provides functionality for working with PDF forms via a web API.", 'wpcf7-pdf-forms' ),
			'key-label' => esc_html__( 'API Key', 'wpcf7-pdf-forms' ),
			'key' => esc_html( $this->get_key() ),
			'no-ssl-verify-label' => esc_html__( 'Ignore security certificate verification errors', 'wpcf7-pdf-forms' ),
			'no-ssl-verify-value' => !$this->get_verify_ssl() ? esc_html__( 'Yes' ) : esc_html__( 'No' ),
			'edit-label' => esc_html__( "Edit", 'wpcf7-pdf-forms' ),
			'edit-link' => esc_url( $this->menu_page_url( 'action=edit' ) ),
		) );
	}
	
	/*
	 * Displays integration error web UI
	 */
	public function display_error()
	{
		echo WPCF7_Pdf_Forms::render( 'pdfninja_integration_error', array(
			'top-message' => esc_html__( "Error!", 'wpcf7-pdf-forms' ),
			'error-message' => esc_html( $this->error ),
			'edit-label' => esc_html__( "Edit", 'wpcf7-pdf-forms' ),
			'edit-link' => esc_url( $this->menu_page_url( 'action=edit' ) ),
		) );
	}
	
	/*
	 * Displays integration edit web UI
	 */
	public function display_edit()
	{
		echo WPCF7_Pdf_Forms::render( 'pdfninja_integration_edit', array(
			'top-message' => esc_html__( "The following form allows you to edit your API key.", 'wpcf7-pdf-forms' ),
			'key-label' => esc_html__( 'API Key', 'wpcf7-pdf-forms' ),
			'key' => esc_html( $this->get_key() ),
			'no-ssl-verify-label' => esc_html__( 'Ignore security certificate verification errors', 'wpcf7-pdf-forms' ),
			'no-ssl-verify-value' => !$this->get_verify_ssl() ? esc_html__( 'checked' ) : '',
			'edit-link' => esc_url( $this->menu_page_url( 'action=edit' ) ),
			'nonce' => wp_nonce_field( 'wpcf7-pdfninja-edit' ),
			'save-label' => esc_html__( "Save", 'wpcf7-pdf-forms' ),
			'new-label' => esc_html__( "Get New Key", 'wpcf7-pdf-forms' ),
		) );
	}
	
	/*
	 * WPCF7_Service defined function
	 */
	public function admin_notice( $message = '' )
	{
		if( 'error' == $message )
			echo WPCF7_Pdf_Forms::render( 'notice_error', array(
				'label' => esc_html__( "PDF Forms for CF7 plugin error", 'wpcf7-pdf-forms' ),
				'message' => esc_html__( "Can't save new key.", 'wpcf7-pdf-forms' ),
			) );
		
		if( $this->error )
			echo WPCF7_Pdf_Forms::render( 'notice_error', array(
				'label' => esc_html__( "PDF Forms for CF7 plugin error", 'wpcf7-pdf-forms' ),
				'message' => esc_html( $this->error ),
			) );
		
		if( 'success' == $message )
			echo WPCF7_Pdf_Forms::render( 'notice_success', array(
				'message' => esc_html__( "Key saved.", 'wpcf7-pdf-forms' ),
			) );
	}
	
	public function admin_notices()
	{
		try { $key = $this->get_key(); } catch(Exception $e) { };
		if( ! $key )
		echo WPCF7_Pdf_Forms::render( 'notice_error', array(
			'label' => esc_html__( "PDF Forms Filler for CF7 plugin error", 'wpcf7-pdf-forms' ),
			'message' => esc_html__( "Could not get a Pdf.Ninja API key.", 'wpcf7-pdf-forms' ),
		) );
	}
}
