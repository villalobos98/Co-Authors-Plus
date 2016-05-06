<?php

/**
 * Class CoAuthors_API_Autocomplete
 * 
 * Provides search results for authors and coauthors to the Autocomplete field on post.php
 */
class CoAuthors_API_Autocomplete extends CoAuthors_API_Controller {

	/**
	 * @var string
	 */
	protected $route = 'autocomplete/';

	/**
	 * @inheritdoc
	 */
	protected function get_args( $context = null ) {
		$args = array(
			'q' => array(
				'contexts' => array( 'get' ),
				'common'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ), 
			),
			'exclude'      => array( 
				'contexts' => array( 'get' ), 
				'common'   => array( 'required' => false, 'sanitize_callback' => array( $this, 'sanitize_exclude_array' ) ), 
			),
			'guest_name'   => array( 
				'contexts' => array( 'post' ), 
				'common'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ), 
			), 
			'guest_email'  => array( 
				'contexts' => array( 'post' ), 
				'common'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ), 
			),
		);

		return $this->filter_args( $context, $args );
	}

	/**
	 * Sanitize an array of excluded user_logins
	 *
	 * @param array $exclude Array of dirty user_logins
	 *
	 * @uses sanitize_text_field()
	 * 
	 * @return array Array of sanitized user_logins
	 */
	public function sanitize_exclude_array( $exclude ) {
		return array_map( 'sanitize_text_field', $exclude );
	}

	/**
	 * @inheritdoc
	 */
	public function create_routes() {
		register_rest_route( $this->get_namespace(), $this->get_route(), array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get' ),
			'permission_callback' => array( $this, 'authorization' ),
			'args'                => $this->get_args( 'get' )
		));

		register_rest_route( $this->get_namespace(), $this->get_route(), array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'post' ),
			'permission_callback' => array( $this, 'authorization' ),
			'args'                => $this->get_args( 'post' )
		));
	}

	/**
	 * @inheritdoc
	 */
	public function get( WP_REST_Request $request ) {
		global $coauthors_plus;

		$query = strtolower( $request['q'] );
		$exclude = $request['exclude'];

		$data = $this->prepare_data( $coauthors_plus->search_authors( $query, $exclude ) );

		return $this->send_response( array( 'coauthors' => $data ) );
	}

	public function post( WP_REST_Request $request ) {
		global $coauthors_plus;

		$display_name = sanitize_user( $request['guest_name'] );
		$email = sanitize_email( $request['guest_email'] );
		$login = sanitize_title( $display_name );
		$display_name_key = $coauthors_plus->guest_authors->get_post_meta_key( 'display_name' );
		$email_key = $coauthors_plus->guest_authors->get_post_meta_key( 'user_email' );
		$login_key = $coauthors_plus->guest_authors->get_post_meta_key( 'user_login' );

		// Bail if we have an invalid display name
		if ( ! $display_name ) {
			return new WP_Error( 'rest_createguest_nameinvalid', __( 'Invalid guest display name.', 'co-authors-plus' ),
				array( 'status' => self::BAD_REQUEST ) );
		}

		// Bail if we have an invalid email address
		if ( ! $email ) {
			return new WP_Error( 'rest_createguest_emailinvalid', __( 'Invalid guest email address.', 'co-authors-plus' ),
				array( 'status' => self::BAD_REQUEST ) );
		}

		// Check to see if there is a user account with this email address
		if ( email_exists( $email ) ) {
			return new WP_Error( 'rest_createguest_emailregistered', __( 'Email address is already in use with a user account.', 'co-authors-plus' ),
				array( 'status' => self::BAD_REQUEST ) );
		}

		// Check to see if there is a guest author with this email address
		if ( $coauthors_plus->guest_authors->get_guest_author_by( 'user_email', $email ) ) {
			return new WP_Error( 'rest_createguest_emailisguest', __( 'Email address is already in use with a guest author.', 'co-authors-plus' ),
				array( 'status' => self::BAD_REQUEST ) );
		}

		// Set up the guest author "post"
		$post = array( 
			'post_type' => 'guest-author', 
			'post_title' => $display_name, 
			'post_name' => $coauthors_plus->guest_authors->get_post_meta_key( $login ), 
			'post_status' => 'publish', 
		);

		// Try to insert the guest author post
		if ( $post_id = wp_insert_post( $post ) ) {
			update_post_meta( $post_id, $display_name_key, $display_name );
			update_post_meta( $post_id, $login_key, $login );
			update_post_meta( $post_id, $email_key, $email );

			// Add the post terms to the guest author post
			$author = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $post_id );
			$author_term = $coauthors_plus->update_author_term( $author );
			wp_set_post_terms( $post_id, array( $author_term->slug ), $coauthors_plus->coauthor_taxonomy, false );

			// Build the AJAX response
			$response = array( 
				'id' => absint( $post_id ), 
				'login' => $login, 
				'email' => $email, 
				'displayname' => $display_name, 
				'nicename' => $login, 
				'avatar' => $coauthors_plus->get_avatar_url( $post_id, $email, 'guest-author' ), 
			);

			// Success - send the response
			return $this->send_response( $response, self::CREATED );
		} else {
			// Inserting post failed. Send a generic error.
			return new WP_Error( 'rest_createguest_guestnotcreated', __( 'Cannot create guest author.', 'co-authors-plus' ),
				array( 'status' => self::BAD_REQUEST ) );
		}

	}

	/**
	 * @inheritdoc
	 */
	public function authorization( WP_REST_Request $request ) {
		global $coauthors_plus;

		return current_user_can( $coauthors_plus->guest_authors->list_guest_authors_cap );
	}

	/**
	 * @param array $coauthors
	 *
	 * @return array
	 */
	protected function prepare_data( array $coauthors ) {
		global $coauthors_plus;

		$data = array();

		foreach ( $coauthors as $coauthor ) {
			$data[] = array(
				'id'          => (int) $coauthor->ID,
				'login'       => $coauthor->user_login, 
				'displayname' => $coauthor->display_name,
				'email'       => $coauthor->user_email,
				'nicename'    => $coauthor->user_nicename, 
				'avatar'      => $coauthors_plus->get_avatar_url( $coauthor->ID, $coauthor->user_email ), 
			);
		}

		return $data;
	}
}