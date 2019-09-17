<?php
/**
 * Dependency Enqueuer.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 *
 * @wordpress-plugin
 * Plugin Name: Dependency Enqueuer
 * Description: Enqueue scripts and styles.
 */

namespace Google\WP_Origination\Tests\Data\Plugins\Dependency_Enqueuer;

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
	wp_enqueue_style( 'code-editor' );

	wp_enqueue_style( 'dependency-enqueuer-ie', 'https://example.com/ie.css', array(), '1' );
	wp_style_add_data( 'dependency-enqueuer-ie', 'conditional', 'lt IE 9' );
}
