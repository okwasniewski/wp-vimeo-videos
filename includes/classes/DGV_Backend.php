<?php

class DGV_Backend {

	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	///////////////////////////////////////////// Class Setup  //////////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * The admin page handle
	 */
	const PAGE_HANDLE = 'dg-vimeo';

	/**
	 * The nonce handle
	 */
	const NONCE = 'DGV';

	/**
	 * The vimeo instance
	 * @var \Vimeo\Vimeo
	 */
	protected $vimeo = null;

	/**
	 * Singleton instance
	 * @var DGV_Backend
	 */
	protected static $instance = null;

	/**
	 * Singleton constructor
	 * @return DGV_Backend
	 */
	public static function instance() {
		if ( ! isset( static::$instance ) ) {
			static::$instance = new self;
		}

		return static::$instance;
	}

	/**
	 * DGV_Backend constructor.
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
		add_action( 'init', array( $this, 'register_post_types' ), 0 );
		add_action( 'wp_ajax_dgv_handle_upload', array( $this, 'handle_upload' ), 0 );
		add_action( 'wp_ajax_dgv_handle_settings', array( $this, 'handle_settings' ), 0 );
		$this->init_api();
	}

	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	///////////////////////////////////////////// Main Functionality  ///////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Initializes the api
	 */
	public function init_api() {
		$client_id     = get_option( 'dgv_client_id' );
		$client_secret = get_option( 'dgv_client_secret' );
		$access_token  = get_option( 'dgv_access_token' );
		$this->vimeo   = new \Vimeo\Vimeo( $client_id, $client_secret, $access_token );
	}

	/**
	 * Handles the upload to vimeo
	 */
	public function handle_upload() {
		$nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array(
				'message' => __( 'Unauthorized action', 'dg-vimeo-videos' )
			) );
			exit;
		}
		if ( ! isset( $_FILES['file'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'No file uploaded...', 'dg-vimeo-videos' )
			) );
			exit;
		}
		if ( ! file_exists( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'File does not exists on the server.', 'dg-vimeo-videos' )
			) );
			exit;
		}
		$wp_uploads  = wp_upload_dir();
		$destination = $wp_uploads['basedir'];
		if ( ! file_exists( $destination ) ) {
			@mkdir( $destination );
		}
		$file_path = $destination . DIRECTORY_SEPARATOR . $_FILES['file']['name'];
		if ( move_uploaded_file( $_FILES['file']['tmp_name'], $file_path ) ) {
			try {
				$privacy_view = isset( $_POST['privacy_view'] ) && ! empty( $_POST['privacy_view'] ) ? $_POST['privacy_view'] : 'anybody';
				$title        = isset( $_POST['title'] ) && ! empty( $_POST['title'] ) ? $_POST['title'] : '';
				$destination  = isset( $_POST['description'] ) && ! empty( $_POST['description'] ) ? $_POST['description'] : '';
				$params       = array(
					'name'        => $title,
					'description' => $destination,
					'privacy'     => array( 'view' => $privacy_view )
				);
				$response     = $this->upload( $file_path, $params );
				unlink( $file_path );
				$postID = wp_insert_post( array(
					'post_title'   => wp_strip_all_tags( $title ),
					'post_content' => $destination,
					'post_status'  => 'publish',
					'post_type'    => DGV_PT_VU,
					'post_author'  => get_current_user_id(),
				) );
				if ( ! is_wp_error( $postID ) ) {
					// Save the params
					foreach ( array( 'privacy' ) as $param ) {
						update_post_meta( $postID, 'dgv_' . $param, $params[ $param ] );
					}
					// Save the response
					update_post_meta( $postID, 'dgv_response', $response );
				}
				wp_send_json_success( array(
					'message'  => __( 'Video uploaded successfully!', 'dg-vimeo-videos' ),
					'response' => $response
				) );
			} catch ( \Vimeo\Exceptions\VimeoRequestException $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ) );
			} catch ( \Vimeo\Exceptions\VimeoUploadException $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ) );
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Error uploading file. Please check your disk space or permissionss.', 'dg-vimeo-videos' ) ) );
		}
	}

	public function handle_settings() {
		// check nonce
		$nonce = isset( $_REQUEST['nonce'] ) ? $_REQUEST['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array(
				'message' => __( 'Unauthorized action', 'dg-vimeo-videos' )
			) );
			exit;
		}
		$fields = array(
			'dgv_client_id'     => __( 'Client ID', 'dg-vimeo-videos' ),
			'dgv_client_secret' => __( 'Client Secret', 'dg-vimeo-videos' ),
			'dgv_access_token'  => __( 'Access Token', 'dg-vimeo-videos' )
		);
		// validate
		foreach ( $fields as $key => $field ) {
			if ( ! isset( $_POST[ $key ] ) || empty( $_POST[ $key ] ) || empty( trim( $_POST[ $key ] ) ) ) {
				wp_send_json_error( array(
					'message' => sprintf( __( 'Error: %s is required.', 'dg-vimeo-videos' ), $field )
				) );
				exit;
			}
		}
		// save
		foreach ( $fields as $key => $field ) {
			update_option( $key, $_POST[ $key ] );
		}
		wp_send_json_success( array(
			'message' => __( 'Settings saved successfully!', 'dg-vimeo-videos' )
		) );
	}

	/**
	 * Upload vimeo video
	 *
	 * @param $file_path
	 * @param $params
	 *
	 * @return string
	 * @throws \Vimeo\Exceptions\VimeoRequestException
	 * @throws \Vimeo\Exceptions\VimeoUploadException
	 */
	private function upload( $file_path, $params ) {
		return $this->vimeo->upload( $file_path, $params );
	}

	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	///////////////////////////////////////////// WordPress Hooks  //////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Register the vimeo post type
	 */
	public function register_post_types() {

		$labels = array(
			'name'                  => _x( 'Vimeo Uploads', 'Post Type General Name', 'dg-vimeo-videos' ),
			'singular_name'         => _x( 'Vimeo Upload', 'Post Type Singular Name', 'dg-vimeo-videos' ),
			'menu_name'             => __( 'Vimeo Uploads', 'dg-vimeo-videos' ),
			'name_admin_bar'        => __( 'Vimeo Upload', 'dg-vimeo-videos' ),
			'archives'              => __( 'Item Archives', 'dg-vimeo-videos' ),
			'attributes'            => __( 'Item Attributes', 'dg-vimeo-videos' ),
			'parent_item_colon'     => __( 'Parent Item:', 'dg-vimeo-videos' ),
			'all_items'             => __( 'All Items', 'dg-vimeo-videos' ),
			'add_new_item'          => __( 'Add New Item', 'dg-vimeo-videos' ),
			'add_new'               => __( 'Add New', 'dg-vimeo-videos' ),
			'new_item'              => __( 'New Item', 'dg-vimeo-videos' ),
			'edit_item'             => __( 'Edit Item', 'dg-vimeo-videos' ),
			'update_item'           => __( 'Update Item', 'dg-vimeo-videos' ),
			'view_item'             => __( 'View Item', 'dg-vimeo-videos' ),
			'view_items'            => __( 'View Items', 'dg-vimeo-videos' ),
			'search_items'          => __( 'Search Item', 'dg-vimeo-videos' ),
			'not_found'             => __( 'Not found', 'dg-vimeo-videos' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'dg-vimeo-videos' ),
			'featured_image'        => __( 'Featured Image', 'dg-vimeo-videos' ),
			'set_featured_image'    => __( 'Set featured image', 'dg-vimeo-videos' ),
			'remove_featured_image' => __( 'Remove featured image', 'dg-vimeo-videos' ),
			'use_featured_image'    => __( 'Use as featured image', 'dg-vimeo-videos' ),
			'insert_into_item'      => __( 'Insert into item', 'dg-vimeo-videos' ),
			'uploaded_to_this_item' => __( 'Uploaded to this item', 'dg-vimeo-videos' ),
			'items_list'            => __( 'Items list', 'dg-vimeo-videos' ),
			'items_list_navigation' => __( 'Items list navigation', 'dg-vimeo-videos' ),
			'filter_items_list'     => __( 'Filter items list', 'dg-vimeo-videos' ),
		);
		$args   = array(
			'label'               => __( 'Vimeo Upload', 'dg-vimeo-videos' ),
			'description'         => __( 'Vimeo Uploads Description', 'dg-vimeo-videos' ),
			'labels'              => $labels,
			'supports'            => false,
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'menu_position'       => 5,
			'menu_icon'           => 'dashicons-format-video',
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => false,
			'can_export'          => false,
			'has_archive'         => false,
			'exclude_from_search' => false,
			'publicly_queryable'  => false,
			'capability_type'     => 'page',
		);
		register_post_type( DGV_PT_VU, $args );
	}

	/**
	 * Enqueues the required scripts
	 */
	public function admin_enqueue_scripts() {
		if ( ! isset( $_REQUEST['page'] ) || $_REQUEST['page'] !== self::PAGE_HANDLE ) {
			return;
		}
		$ver = filemtime( DGV_ASSETS_PATH . '/dgv.js' );
		wp_enqueue_script( 'dgv-vimeo', DGV_ASSETS_URL . 'dgv.js', array( 'jquery' ), $ver, true );
		wp_localize_script( 'dgv-vimeo', 'DGV', array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'uploading' => sprintf( '%s %s', '<img src="' . admin_url( 'images/spinner.gif' ) . '">', __( 'Uploading video. Please wait...', 'dg-vimeo-videos' ) )
		) );

		$ver = filemtime( DGV_ASSETS_PATH . '/dgv.css' );
		wp_enqueue_style( 'dgv-vimeo', DGV_ASSETS_URL . 'dgv.css', null, $ver );

		$ver = filemtime( DGV_ASSETS_PATH . '/dgv-public.css' );
		wp_register_style( 'dgv-vimeo-public', DGV_ASSETS_URL . 'dgv-public.css', null, $ver );
	}

	/**
	 * Enqueue public scripts
	 */
	public function wp_enqueue_scripts() {
		$ver = filemtime( DGV_ASSETS_PATH . '/dgv-public.css' );
		wp_register_style( 'dgv-vimeo-public', DGV_ASSETS_URL . 'dgv-public.css', null, $ver );
	}

	/**
	 * Register the Media menu item
	 */
	public function admin_menu() {
		add_media_page( 'Vimeo', 'Vimeo', 'manage_options', self::PAGE_HANDLE, array( $this, 'admin_page' ) );
	}

	/**
	 * Returns the admin page view
	 */
	public function admin_page() {
		include DGV_PATH . 'views/admin.php';
	}

}

DGV_Backend::instance();