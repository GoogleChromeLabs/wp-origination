<?php
/**
 * Class Plugin.
 *
 * @package Sourcery
 */

namespace Sourcery;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Options.
	 *
	 * @var array
	 */
	public $options = array(
		'show_queries_cap'  => 'manage_options',
		'show_all_data_cap' => 'manage_options',
	);

	/**
	 * Instance of Hook_Inspector.
	 *
	 * @var Hook_Inspector
	 */
	public $hook_inspector;

	/**
	 * Instance of Hook_Inspector.
	 *
	 * @var Hook_Wrapper
	 */
	public $hook_wrapper;

	/**
	 * Plugin constructor.
	 *
	 * @param array $options Args.
	 */
	public function __construct( $options = array() ) {
		$this->options = array_merge(
			$this->options,
			$options
		);
	}

	/**
	 * Init.
	 */
	public function init() {

		$this->hook_inspector = new Hook_Inspector(
			array(
				'can_show_queries_callback' => function() {
					return current_user_can( $this->options['show_queries_cap'] );
				},
			)
		);
		$this->hook_wrapper   = new Hook_Wrapper(
			array( $this->hook_inspector, 'before_hook' ),
			array( $this->hook_inspector, 'after_hook' )
		);

		// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
		ob_start(
			array( $this->hook_inspector, 'finalize_hook_annotations' ),
			null,
			0
		);

		// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		$this->hook_wrapper->add_all_hook();

		add_action( 'shutdown', array( $this, 'send_server_timing_headers' ) );
	}

	/**
	 * Send Server-Timing headers.
	 */
	public function send_server_timing_headers() {
		$entity_timings = array();

		foreach ( $this->hook_inspector->processed_hooks as $processed_hook ) {
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
}
