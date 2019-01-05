<?php
/**
 * Plugin Name: Sourcery
 * Description: Determine the source of where things come from in WordPress whether slow code, inefficient queries, or bad markup.
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
require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/Hook_Inspection.php';
require_once __DIR__ . '/includes/Hook_Inspector.php';
require_once __DIR__ . '/includes/Hook_Wrapper.php';

global $sourcery_plugin;
$sourcery_plugin = new Plugin(
	array(
		'profile_hooks'   => isset( $_GET['sourcery_profile_hooks'] ),
		'output_buffer'   => isset( $_GET['sourcery_output_buffer'] ),
		// @todo 'annotate_output' => isset( $_GET['sourcery_annotate_output'] ),
	)
);

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	$sourcery_plugin->init();
}
