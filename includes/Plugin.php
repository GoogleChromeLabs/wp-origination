<?php
/**
 * Class Google\WP_Origination\Plugin
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Main instance of the plugin.
	 *
	 * @since 0.1.0
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Absolute path to the plugin main file.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $main_file;

	/**
	 * Capability required to reveal the database queries on the page.
	 *
	 * @var string
	 */
	public $show_queries_cap = 'manage_options';

	/**
	 * Instance of Invocation_Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Instance of Server_Timing_Headers.
	 *
	 * @var Server_Timing_Headers
	 */
	public $server_timing_headers;

	/**
	 * Instance of Dependencies.
	 *
	 * @var Dependencies
	 */
	public $dependencies;

	/**
	 * Instance of Database.
	 *
	 * @var Database
	 */
	public $database;

	/**
	 * Instance of File_Locator.
	 *
	 * @var File_Locator
	 */
	public $file_locator;

	/**
	 * Instance of Block_Recognizer.
	 *
	 * @var Block_Recognizer
	 */
	public $block_recognizer;

	/**
	 * Instance of Incrementor.
	 *
	 * @var Incrementor
	 */
	public $incrementor;

	/**
	 * Instance of Output_Annotator.
	 *
	 * @var Output_Annotator
	 */
	public $output_annotator;

	/**
	 * Instance of Hook_Wrapper.
	 *
	 * @var Hook_Wrapper
	 */
	public $hook_wrapper;

	/**
	 * Retrieves the main instance of the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return Plugin Plugin main instance.
	 */
	public static function instance() {
		return static::$instance;
	}

	/**
	 * Loads the plugin main instance and initializes it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $main_file Absolute path to the plugin main file.
	 * @return bool True if the plugin main instance could be loaded, false otherwise.
	 */
	public static function load( $main_file ) {
		if ( null !== static::$instance ) {
			return false;
		}

		static::$instance = new static( $main_file );
		static::$instance->start();

		return true;
	}

	/**
	 * Plugin constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $main_file Absolute path to the plugin main file. This is primarily useful for plugins that subclass this class.
	 */
	public function __construct( $main_file ) {
		$this->main_file = $main_file;
	}

	/**
	 * Gets the plugin basename, which consists of the plugin directory name and main file name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Plugin basename.
	 */
	public function basename() {
		return plugin_basename( $this->main_file );
	}

	/**
	 * Gets the absolute path for a path relative to the plugin directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative_path Optional. Relative path. Default '/'.
	 * @return string Absolute path.
	 */
	public function path( $relative_path = '/' ) {
		return plugin_dir_path( $this->main_file ) . ltrim( $relative_path, '/' );
	}

	/**
	 * Gets the full URL for a path relative to the plugin directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative_path Optional. Relative path. Default '/'.
	 * @return string Full URL.
	 */
	public function url( $relative_path = '/' ) {
		return plugin_dir_url( $this->main_file ) . ltrim( $relative_path, '/' );
	}

	/**
	 * Determine whether origination should run for the current request.
	 *
	 * @return bool
	 */
	public function should_run() {
		return (
			defined( 'WP_DEBUG' )
			&&
			WP_DEBUG
			&&
			isset( $_GET['origination'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.NoNonceVerification
		);
	}

	/**
	 * Construct classes used by the plugin.
	 *
	 * @global \wpdb $wpdb
	 */
	public function init() {
		global $wpdb;

		$this->incrementor = new Incrementor();

		$this->file_locator = new File_Locator();

		$this->block_recognizer = new Block_Recognizer();

		$this->dependencies = new Dependencies();

		// @todo Pass options for verbosity, which filters to wrap, whether to output annotations that have no output, etc.
		$this->output_annotator = new Output_Annotator(
			$this->dependencies,
			$this->incrementor,
			$this->block_recognizer,
			[
				'can_show_queries_callback' => function() {
					return current_user_can( $this->show_queries_cap );
				},
			]
		);

		$this->database = new Database( $wpdb );

		$this->hook_wrapper = new Hook_Wrapper();

		$this->invocation_watcher = new Invocation_Watcher(
			$this->file_locator,
			$this->output_annotator,
			$this->dependencies,
			$this->database,
			$this->incrementor,
			$this->hook_wrapper
		);

		$this->output_annotator->set_invocation_watcher( $this->invocation_watcher );

		$this->server_timing_headers = new Server_Timing_Headers( $this->invocation_watcher );
	}

	/**
	 * Run.
	 */
	public function start() {
		$wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( ! isset( $_GET['origination'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.NoNonceVerification
			return;
		}

		if ( ! $wp_debug ) {
			wp_die(
				esc_html__( 'WP_DEBUG must currently be enabled to generate Origination data.', 'origination' ),
				esc_html__( 'WP_DEBUG Required', 'origination' ),
				array(
					'response' => 400,
				)
			);
		}

		$this->init();

		$this->invocation_watcher->start();
		$this->output_annotator->start();
		add_action( 'shutdown', [ $this->server_timing_headers, 'send' ] );
	}
}
