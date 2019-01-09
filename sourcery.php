<?php
/**
 * Sourcery plugin initialization file.
 *
 * @package   Google\WP_Sourcery
 * @author    Weston Ruter
 * @copyright 2019 Google
 * @license   GNU General Public License, version 2 (or later)
 * @link      https://wordpress.org/plugins/feature-policy/
 *
 * @wordpress-plugin
 * Plugin Name: Sourcery
 * Plugin URI:  https://github.com/westonruter/wp-sourcery
 * Description: Determine the source of where things come from in WordPress whether slow code, inefficient queries, or bad markup.
 * Version:     0.1.0
 * Author:      Weston Ruter
 * Author URI:  https://weston.ruter.net/
 * License:     GNU General Public License v2 (or later)
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sourcery
 */

/* This file must be parsable by PHP 5.2. */

namespace Google\WP_Sourcery;

// @todo Composer autoload.
require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/Hook_Inspection.php';
require_once __DIR__ . '/includes/Hook_Inspector.php';
require_once __DIR__ . '/includes/Hook_Wrapper.php';

global $sourcery_plugin;
$sourcery_plugin = new Plugin(
	array(
		// @todo 'annotate_output' => isset( $_GET['sourcery_annotate_output'] ),
	)
);

if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['sourcery'] ) ) {
	$sourcery_plugin->init();
}
