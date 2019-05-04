<?php
/**
 * Dependency Enqueuer.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @wordpress-plugin
 * Plugin Name: Dependency Enqueuer
 * Description: Enqueue scripts and styles.
 */

namespace Google\WP_Sourcery\Tests\Data\Plugins\Dependency_Enqueuer;

/**
 * Add hooks.
 */
function add_hooks() {
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
	add_action( 'hook_invoker_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts_for_hook_invoker' );
}

/**
 * Enqueue scripts.
 */
function enqueue_scripts() {
	wp_enqueue_script( 'jquery-ui-widget' );
}

/**
 * Enqueue scripts for hook invoker.
 */
function enqueue_scripts_for_hook_invoker() {
	wp_enqueue_script( 'wp-a11y' );
	wp_enqueue_style( 'wp-mediaelement' );

	wp_enqueue_style( 'dependency-enqueuer-ie', 'https://example.com/ie.css', array(), '1' );
	wp_style_add_data( 'dependency-enqueuer-ie', 'conditional', 'lt IE 9' );
}
