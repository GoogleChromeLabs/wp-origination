<?php
/**
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

const SHORTCODE_TAG = 'transform_text';

function add_shortcode() {
	\add_shortcode( SHORTCODE_TAG, __NAMESPACE__ . '\transform_text_shortcode' );
}

function transform_text_shortcode( $attributes, $content ) {
	$attributes = shortcode_atts(
		[
			'case'   => '',
			'styles' => [],
		],
		$attributes,
		SHORTCODE_TAG
	);

	foreach ( explode( ',', $attributes['styles'] ) as $style ) {
		wp_enqueue_style( $style );
	}

	if ( 'upper' === $attributes['case'] ) {
		$content = strtoupper( $content );
	} elseif ( 'lower' === $attributes['case'] ) {
		$content = strtolower( $content );
	}

	return $content;
}
