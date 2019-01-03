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

//add_action( 'init', function() {
//	global $wpdb;
//	do_action( 'foo_init' );
//} );
//
//add_action( 'foo_init', function() {
//	global $wpdb;
//	$wpdb->query( 'SELECT 1;' );
//	$wpdb->query( 'SELECT 2;' );
//	$wpdb->query( 'SELECT 3;' );
//} );

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

return;


$before = function() {
	global $wpdb;

	$start_time         = microtime( true );
	$before_num_queries = $wpdb->num_queries;

	return compact( 'before_num_queries', 'start_time' );
};

global $call_count;
$call_count = 0;

$after = function( $args ) {
	global $call_count;
	$call_count++;
	global $wpdb;
	$data = array(
		'hook' => $args['hook_name'],
	);
	if ( isset( $args['before_return']['start_time'] ) ) {
		$data['duration'] = microtime( true ) - $args['before_return']['start_time'];
	}
	if ( isset( $args['before_return']['before_num_queries'] ) ) {
		$data['query_count'] = $wpdb->num_queries - $args['before_return']['before_num_queries'];

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$result['query_indices'] = range( $args['before_return']['before_num_queries'], $wpdb->num_queries - 1 );
		}
	}
};

//add_action( 'shutdown', function() {
//	global $call_count;
//	echo "\n<!-- CALL COUNT: $call_count -->\n";
//	error_log( "CALL COUNT: $call_count" );
//} );

$hook_wrapper = new Hook_Wrapper( $before, $after );
$hook_wrapper->add_all_hook();


// @todo Class/function for determining the WordPress theme/plugin that contains a given file.
