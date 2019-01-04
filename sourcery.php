<?php
/**
 * Plugin Name: Sourcery
 * Description: Determine the source of where things come from in WordPress: slow code, inefficient queries, and bad markup.
 * Plugin URI: https://github.com/westonruter/sourcery
 * Author: Weston Ruter
 * Author URI: https://weston.ruter.net
 * Version: 0.1.0
 * Text Domain: sourcery
 * License: MIT
 *
 * @package Sourcery
 */

namespace Sourcery;

function profile_hooks() {

	// @todo Composer autoload.
	require_once __DIR__ . '/includes/Hook_Inspection.php';
	require_once __DIR__ . '/includes/Hook_Inspector.php';
	require_once __DIR__ . '/includes/Hook_Wrapper.php';

	global $wpdb;
	$hook_inspector = new Hook_Inspector( $wpdb );
	$hook_wrapper   = new Hook_Wrapper(
		array( $hook_inspector, 'before_hook' ),
		array( $hook_inspector, 'after_hook' )
	);
	$hook_wrapper->add_all_hook();

	add_action( 'shutdown',
		function() use ( $hook_inspector ) {

			$entity_timings = array();
			$hook_timings   = array();
			$file_locations = array();
			$hooks_duration = 0.0;

			foreach ( $hook_inspector->processed_hooks as $processed_hook ) {

				$hook_duration = $processed_hook->duration();
				if ( ! isset( $hook_timings[ $processed_hook->hook_name ] ) ) {
					$hook_timings[ $processed_hook->hook_name ] = 0.0;
				}
				$hook_timings[ $processed_hook->hook_name ] += $hook_duration;

				if ( ! isset( $file_locations[ $processed_hook->source_file ] ) ) {
					$file_locations[ $processed_hook->source_file ] = $hook_inspector->identify_file_location( $processed_hook->source_file );
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

				continue;

				print_r( array_merge(
					wp_array_slice_assoc(
						(array) $processed_hook,
						array(
							'hook_name',
							'function_name',
							'source_file',
						)
					),
					array(
						'duration' => $processed_hook->end_time - $processed_hook->start_time,
						'queries'  => array_map(
							function ( $query_index ) {
								global $wpdb;
								return $wpdb->queries[ $query_index ];
							},
							$processed_hook->query_indices ?: array()
						),
						'location' => $hook_inspector->identify_file_location( $processed_hook->source_file ),
					)
				) );
			}

			$round_to_fourth_precision = function( $timing ) {
				return round( $timing, 4 );
			};

			arsort( $entity_timings );
			arsort( $hook_timings );

			echo '<!--';
			echo "\nTime spent in core, theme, and plugins (in descending order):\n";
			foreach ( array_map( $round_to_fourth_precision, $entity_timings ) as $entity => $timing ) {
				printf( " * %.4f: %s\n", $timing, $entity );
			}

			echo "\nTime spent running hooks (in descending order):\n";
			foreach ( array_map( $round_to_fourth_precision, $hook_timings ) as $hook => $timing ) {
				printf( " * %.4f: %s\n", $timing, $hook );
			}

			printf( "\nSummed hook durations: %.4f\n", $hooks_duration );
			echo "\n-->";
		}
	);
}

if ( isset( $_GET['sourcery_profile_hooks'] ) ) {
	profile_hooks();
}
