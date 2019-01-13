<?php
/**
 * Class Google\WP_Sourcery\Plugin
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

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
	 * Instance of File_Locator.
	 *
	 * @var File_Locator
	 */
	public $file_locator;

	/**
	 * Instance of Invocation_Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

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
	 * Instance of Hook_Wrapper.
	 *
	 * @var Hook_Wrapper
	 */
	public $hook_wrapper;

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
	 * Determine whether sourcery should run for the current request.
	 *
	 * @return bool
	 */
	public function should_run() {
		return (
			defined( 'WP_DEBUG' )
			&&
			WP_DEBUG
			&&
			isset( $_GET['sourcery'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.NoNonceVerification
		);
	}

	/**
	 * Init.
	 */
	public function init() {
		if ( ! $this->should_run() ) {
			return;
		}

		$this->file_locator = new File_Locator();

		// @todo Let Database and Dependencies instances be added as direct dependencies of this class as well.
		$this->invocation_watcher = new Invocation_Watcher(
			$this,
			array(
				'can_show_queries_callback' => function() {
					return current_user_can( $this->show_queries_cap );
				},
			)
		);

		$this->database = new Database( $this->invocation_watcher );

		$this->dependencies = new Dependencies( $this->invocation_watcher );

		$this->hook_wrapper = new Hook_Wrapper(
			array( $this->invocation_watcher, 'before_hook' ),
			array( $this->invocation_watcher, 'after_hook' )
		);

		// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
		ob_start(
			array( $this->invocation_watcher, 'finalize_hook_annotations' ),
			null,
			0
		);

		// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		add_filter(
			'script_loader_tag',
			function( $tag, $handle = null ) {
				if ( ! $handle ) {
					return $tag;
				}

				$invocations = $this->dependencies->get_dependency_enqueueing_invocations( 'wp_scripts', $handle );
				if ( empty( $invocations ) ) {
					return $tag;
				}

				$data = array(
					'type'        => 'enqueued_script',
					'invocations' => wp_list_pluck( $invocations, 'id' ),
				);

				return implode(
					'',
					array(
						$this->invocation_watcher->get_annotation_comment( $data, false ),
						$tag,
						$this->invocation_watcher->get_annotation_comment( $data, true ),
					)
				);
			},
			10, // @todo Consider a higher priority.
			2
		);
		add_filter(
			'style_loader_tag',
			function( $tag, $handle = null ) {
				if ( ! $handle ) {
					return $tag;
				}

				$invocations = $this->dependencies->get_dependency_enqueueing_invocations( 'wp_styles', $handle );
				if ( empty( $invocations ) ) {
					return $tag;
				}

				$data = array(
					'type'        => 'enqueued_style',
					'invocations' => wp_list_pluck( $invocations, 'id' ),
				);

				return implode(
					'',
					array(
						$this->invocation_watcher->get_annotation_comment( $data, false ),
						$tag,
						$this->invocation_watcher->get_annotation_comment( $data, true ),
					)
				);
			},
			10,
			2
		);

		$this->hook_wrapper->add_all_hook();

		add_action( 'shutdown', array( $this, 'send_server_timing_headers' ) );
	}

	/**
	 * Send Server-Timing headers.
	 *
	 * @todo Move this to another class.
	 */
	public function send_server_timing_headers() {
		$entity_timings = array();

		foreach ( $this->invocation_watcher->finalized_invocations as $processed_hook ) {
			try {
				$hook_duration = $processed_hook->duration();
			} catch ( \Exception $e ) {
				$hook_duration = -1;
			}

			$file_location = $processed_hook->file_location();
			if ( $file_location ) {
				$entity_key = sprintf( '%s:%s', $file_location['type'], $file_location['name'] );
				if ( ! isset( $entity_timings[ $entity_key ] ) ) {
					$entity_timings[ $entity_key ] = 0.0;
				}
				$entity_timings[ $entity_key ] += $hook_duration;
			}
		}

		$round_to_fourth_precision = function( $timing ) {
			return round( $timing, 4 );
		};

		foreach ( array_map( $round_to_fourth_precision, $entity_timings ) as $entity => $timing ) {
			$value  = strtok( $entity, ':' );
			$value .= sprintf( ';desc="%s"', $entity );
			$value .= sprintf( ';dur=%f', $timing * 1000 );
			header( sprintf( 'Server-Timing: %s', $value ), false );
		}
	}

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
		static::$instance->init();

		return true;
	}
}
