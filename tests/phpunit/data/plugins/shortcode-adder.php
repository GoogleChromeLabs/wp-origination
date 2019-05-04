<?php
/**
 * Shortcode adder.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @wordpress-plugin
 * Plugin Name: Shortcode Adder
 * Description: Test plugin for shortcodes.
 */

namespace Google\WP_Sourcery\Tests\Data\Plugins\Shortcode_Adder;

const SHORTCODE_TAG = 'passthru';

/**
 * Add shortcode.
 */
function add_shortcode() {
	\add_shortcode( SHORTCODE_TAG, __NAMESPACE__ . '\passthru_shortcode' );
}

/**
 * Run passthru shortcode, which just invokes the supplied (whitelisted) function and enqueues assets.
 *
 * @param array  $attributes Attributes.
 * @param string $content    Content.
 * @return string Content.
 */
function passthru_shortcode( $attributes, $content ) {
	$attributes = shortcode_atts(
		[
			'function' => '',
			'styles'   => [],
			'scripts'  => [],
		],
		$attributes,
		SHORTCODE_TAG
	);

	foreach ( explode( ',', $attributes['styles'] ) as $style ) {
		wp_enqueue_style( $style );
	}
	foreach ( explode( ',', $attributes['scripts'] ) as $script ) {
		wp_enqueue_script( $script );
	}

	$allowed_functions = [
		'strtoupper',
	];

	if ( isset( $attributes['function'] ) && in_array( $attributes['function'], $allowed_functions, true ) ) {
		$content = call_user_func( $attributes['function'], $content );
	}

	return $content;
}
