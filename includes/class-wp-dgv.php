<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    WP_DGV
 * @subpackage WP_DGV/includes
 * @copyright     Darko Gjorgjijoski <info@codeverve.com>
 * @license    GPLv2
 */
class WP_DGV {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      WP_DGV_Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The basename of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_basename;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version         = WP_VIMEO_VIDEOS_VERSION;
		$this->plugin_name     = 'wp-vimeo-videos-pro';
		$this->plugin_basename = 'wp-vimeo-videos-pro/wp-vimeo-videos-pro.php';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * Load the composer packages that are required to run this plugin
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/helpers.php';

		/**
		 * Load vimeo library
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'vendor/autoload.php';

		/**
		 * The class responsible for displaying notices
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-notices-helper.php';

		/**
		 * The class responsible for communicating with the vimeo API
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-api-helper.php';

		/**
		 * The class responsible for communicating with the database and querying data
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-db-helper.php';

		/**
		 * The class responsible for communicating with the news service
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-news-helper.php';

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-i18n.php';

		/**
		 * The class responsible for creating list table of the videos
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-list-table.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'admin/class-wp-dgv-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'public/class-wp-dgv-public.php';

		/**
		 * The class responsible for handling all ajax requests
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-cron-system.php';

		/**
		 * The class responsible for handling all ajax requests
		 */
		require_once WP_VIMEO_VIDEOS_PATH . 'includes/class-wp-dgv-ajax-handler.php';


		$this->loader = new WP_DGV_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the WP_DGV_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new WP_DGV_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		// Init Classes
		$plugin_admin = new WP_DGV_Admin( $this->get_plugin_name(), $this->get_version() );
		$ajax_handler = new WP_DGV_Ajax_Handler( $this->get_plugin_name(), $this->get_version() );
		$cron_system  = new WP_DGV_Cron_System();

		// Init Dashboard
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'register_admin_menu' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'instructions' );

		// Int Cron tasks
		$this->loader->add_filter( 'cron_schedules', $cron_system, 'cron_schedules', 15, 1 );
		$this->loader->add_action( 'init', $cron_system, 'register_events' );
		$this->loader->add_action( 'wvv_event_clean_local_files', $cron_system, 'cleanup' );

		// Init Ajax endpoints
		$this->loader->add_action( 'wp_ajax_dgv_handle_upload', $ajax_handler, 'handle_upload' );
		$this->loader->add_action( 'wp_ajax_dgv_handle_settings', $ajax_handler, 'handle_settings' );
		$this->loader->add_action( 'wp_ajax_dgv_store_upload', $ajax_handler, 'store_upload' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new WP_DGV_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

		add_shortcode( 'vimeo_video', array( $plugin_public, 'shortcode_video' ) ); // DEPRECATED.
		add_shortcode( 'dgv_vimeo_video', array( $plugin_public, 'shortcode_video' ) );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return    string    The name of the plugin.
	 * @since     1.0.0
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return    WP_DGV_Loader    Orchestrates the hooks of the plugin.
	 * @since     1.0.0
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 * @since     1.0.0
	 */
	public function get_version() {
		return $this->version;
	}

}
