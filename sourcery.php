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

add_action( 'template_redirect', function() use ( $hook_inspector ) {
	foreach ( $hook_inspector->processed_hooks as $processed_hook ) {
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
			)
		) );
	}
	exit;
} );
