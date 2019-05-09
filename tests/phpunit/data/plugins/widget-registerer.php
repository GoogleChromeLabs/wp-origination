<?php
/**
 * Widget registerer.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @wordpress-plugin
 * Plugin Name: Widget Registerer
 * Description: Test plugin for widgets.
 */

namespace Google\WP_Sourcery\Tests\Data\Plugins\Widget_Registerer;

const SINGLE_WIDGET_ID = 'single';

const MULTI_WIDGET_ID_BASE = 'multi';

/**
 * Register widgets.
 */
function register_widgets() {
	wp_register_sidebar_widget(
		SINGLE_WIDGET_ID,
		'Single',
		__NAMESPACE__ . '\display_single_widget'
	);

	register_widget( __NAMESPACE__ . '\Multi_Widget' );
}

/**
 * Register sidebar(s) and populate them.
 *
 * @param string $sidebar_id Sidebar ID.
 */
function register_populated_widgets_sidebar( $sidebar_id = 'sidebar-1' ) {
	global $wp_widget_factory, $wp_registered_sidebars, $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_widget_updates;

	register_sidebar(
		[
			'name' => 'Sidebar',
			'id'   => $sidebar_id,
		]
	);

	update_option(
		'option_' . SINGLE_WIDGET_ID,
		[
			'title' => 'Singular',
		]
	);

	$multi_number = 2;

	update_option(
		'widget_' . MULTI_WIDGET_ID_BASE,
		[
			$multi_number  => [
				'title' => 'Multiple',
			],
			'_multiwidget' => true,
		]
	);

	$search_number = 3;
	update_option(
		'widget_search',
		[
			$search_number => [
				'title' => 'Not Google!',
			],
			'_multiwidget' => true,
		]
	);

	wp_set_sidebars_widgets(
		[
			$sidebar_id => [
				SINGLE_WIDGET_ID,
				MULTI_WIDGET_ID_BASE . '-' . $multi_number,
				"search-$search_number",
			],
		]
	);

	$wp_widget_factory             = new \WP_Widget_Factory(); // phpcs:ignore
	$wp_registered_sidebars        = array(); // phpcs:ignore
	$wp_registered_widgets         = array(); // phpcs:ignore
	$wp_registered_widget_controls = array(); // phpcs:ignore
	$wp_registered_widget_updates  = array(); // phpcs:ignore
	add_action( 'widgets_init', __NAMESPACE__ . '\register_widgets' );
	wp_widgets_init();
	remove_action( 'widgets_init', __NAMESPACE__ . '\register_widgets' );
}

/**
 * Display old-style single widget.
 *
 * @see WP_Widget::widget()
 *
 * @param array $args Widget args.
 */
function display_single_widget( $args ) {
	$title = apply_filters( 'widget_title', 'Single' );
	echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( ! empty( $title ) ) {
		echo $args['before_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo 'I am single!';
	echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Class Multi_Widget
 */
class Multi_Widget extends \WP_Widget {

	/**
	 * Multi_Widget constructor.
	 */
	public function __construct() {
		parent::__construct( MULTI_WIDGET_ID_BASE, 'Multi' );
	}

	/**
	 * Outputs the content of the widget
	 *
	 * @param array $args     Args.
	 * @param array $instance Instance.
	 */
	public function widget( $args, $instance ) {
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$title = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		if ( ! empty( $title ) ) {
			echo $args['before_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo 'I am multi!';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
