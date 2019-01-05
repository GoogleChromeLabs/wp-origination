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
		'output_buffer'     => false,
		'profile_hooks'     => false,
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

		if ( $this->options['output_buffer'] ) {
			// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
			ob_start(
				function ( $buffer ) {
					return $buffer;
				},
				null,
				0
			);

			// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
			remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		}

		if ( $this->options['profile_hooks'] ) {
			$this->hook_inspector = new Hook_Inspector();
			$this->hook_wrapper   = new Hook_Wrapper(
				array( $this->hook_inspector, 'before_hook' ),
				array( $this->hook_inspector, 'after_hook' )
			);
			$this->hook_wrapper->add_all_hook();
			add_action( 'shutdown', array( $this, 'output_profiling_info' ) );
		}
	}

	/**
	 * Output profiling info.
	 *
	 * If headers have not been sent (i.e. if output buffering), then the core/theme/plugin timings are sent via Server-Timing headers as well.
	 */
	public function output_profiling_info() {
		$entity_timings = array();
		$hook_timings   = array();
		$file_locations = array();
		$hooks_duration = 0.0;
		$all_results    = array();
		$do_all_results = current_user_can( $this->options['show_all_data_cap'] );

		foreach ( $this->hook_inspector->processed_hooks as $processed_hook ) {
			try {
				$hook_duration = $processed_hook->duration();
			} catch ( \Exception $e ) {
				$hook_duration = -1;
			}
			if ( ! isset( $hook_timings[ $processed_hook->hook_name ] ) ) {
				$hook_timings[ $processed_hook->hook_name ] = 0.0;
			}
			$hook_timings[ $processed_hook->hook_name ] += $hook_duration;

			if ( ! isset( $file_locations[ $processed_hook->source_file ] ) ) {
				$file_locations[ $processed_hook->source_file ] = $this->hook_inspector->identify_file_location( $processed_hook->source_file );
			}
			$file_location = $file_locations[ $processed_hook->source_file ];
			if ( $file_location ) {
				$entity_key      = sprintf( '%s:%s', $file_location['type'], $file_location['name'] );
				$hooks_duration += $hook_duration;
				if ( ! isset( $entity_timings[ $entity_key ] ) ) {
					$entity_timings[ $entity_key ] = 0.0;
				}
				$entity_timings[ $entity_key ] += $hook_duration;
			}

			if ( $do_all_results ) {
				$hook_result = array_merge(
					wp_array_slice_assoc(
						(array) $processed_hook,
						array(
							'hook_name',
							'function_name',
							'source_file',
						)
					),
					compact( 'file_location', 'hook_duration' )
				);
				unset( $hook_result['file_location']['data'] );

				if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && current_user_can( $this->options['show_queries_cap'] ) ) {
					$hook_result['queries'] = array_map(
						function ( $query_index ) {
							global $wpdb;

							return $wpdb->queries[ $query_index ];
						},
						$processed_hook->query_indices ?: array()
					);
				}

				$all_results[] = $hook_result;
			}
		}

		$round_to_fourth_precision = function( $timing ) {
			return round( $timing, 4 );
		};

		arsort( $entity_timings );
		arsort( $hook_timings );

		echo '<!--';
		echo "\nTime spent in core, theme, and plugins (in descending order):\n";
		foreach ( array_map( $round_to_fourth_precision, $entity_timings ) as $entity => $timing ) {
			printf( " * %.4f: %s\n", floatval( $timing ), esc_html( $entity ) );

			if ( ! headers_sent() ) {
				$value  = strtok( $entity, ':' );
				$value .= sprintf( ';desc="%s"', $entity );
				$value .= sprintf( ';dur=%f', $timing * 1000 );
				header( sprintf( 'Server-Timing: %s', $value ), false );
			}
		}

		echo "\nTime spent running hooks (in descending order):\n";
		foreach ( array_map( $round_to_fourth_precision, $hook_timings ) as $hook => $timing ) {
			printf( " * %.4f: %s\n", floatval( $timing ), esc_html( $hook ) );
		}

		printf( "\nSummed hook durations: %.4f\n", floatval( $hooks_duration ) );

		if ( $do_all_results ) {
			echo "\n\nAll results:\n";
			echo preg_replace( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'/--+/',
				'',
				wp_json_encode( $all_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			);
			echo "\n";
		}
		echo '-->';
	}
}
