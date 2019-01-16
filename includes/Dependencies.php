<?php
/**
 * Dependencies Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

use \WP_Dependencies, \WP_Scripts, \WP_Styles;

/**
 * Class Dependencies.
 */
class Dependencies {

	/**
	 * Invocation watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Lookup of which enqueued scripts have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var bool[]
	 */
	protected $sourced_script_enqueues = [];

	/**
	 * Lookup of which enqueued styles have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var bool[]
	 */
	protected $sourced_style_enqueues = [];

	/**
	 * Dependencies constructor.
	 *
	 * @param Invocation_Watcher $invocation_watcher Invocation watcher.
	 */
	public function __construct( Invocation_Watcher $invocation_watcher ) {
		$this->invocation_watcher = $invocation_watcher;
	}

	/**
	 * Get dependency registry.
	 *
	 * Note that this does not use the `wp_styles()`/`wp_scripts` function because that will cause `WP_Styles`/`WP_Scripts`
	 * to potentially be instantiated prematurely.
	 *
	 * @param string $type Type (e.g. 'wp_scripts', 'wp_styles').
	 * @return WP_Dependencies|null Dependency registry.
	 * @global WP_Scripts $wp_scripts
	 * @global WP_Styles $wp_styles
	 */
	public function get_dependency_registry( $type ) {
		if ( isset( $GLOBALS[ $type ] ) && $GLOBALS[ $type ] instanceof WP_Dependencies ) {
			return $GLOBALS[ $type ];
		}
		return null;
	}

	/**
	 * Return the scripts registry.
	 *
	 * Note that this does not use the `wp_scripts()` function because that will cause `WP_Scripts` to potentially be
	 * instantiated prematurely.
	 *
	 * @param string $type Type (e.g. 'wp_scripts', 'wp_styles').
	 * @return string[] Enqueued dependency handles.
	 */
	public function get_dependency_queue( $type ) {
		$dependencies = $this->get_dependency_registry( $type );
		if ( $dependencies && isset( $dependencies->queue ) ) {
			return $dependencies->queue;
		}
		return [];
	}

	/**
	 * Get the invocations that result in a dependency being enqueued (either directly or via another dependency).
	 *
	 * @param string $type   Type (e.g. 'wp_scripts', 'wp_styles').
	 * @param string $handle Dependency handle.
	 *
	 * @return Invocation[] Invocations.
	 */
	public function get_dependency_enqueueing_invocations( $type, $handle ) {
		$enqueueing_invocations = [];
		foreach ( $this->invocation_watcher->finalized_invocations as $invocation ) {
			// @todo This should be be improved, perhaps a method that we can pass $type.
			if ( 'wp_scripts' === $type ) {
				$enqueued_handles = $invocation->enqueued_scripts;
			} elseif ( 'wp_styles' === $type ) {
				$enqueued_handles = $invocation->enqueued_styles;
			} else {
				$enqueued_handles = [];
			}

			$is_enqueued = false;
			if ( in_array( $handle, $enqueued_handles, true ) ) {
				$is_enqueued = true;
			} else {
				foreach ( $enqueued_handles as $invocation_enqueue_handle ) {
					if ( in_array( $handle, $this->get_dependencies( $this->get_dependency_registry( $type ), $invocation_enqueue_handle ), true ) ) {
						$is_enqueued = true;
						break;
					}
				}
			}
			if ( $is_enqueued ) {
				// @todo In reality we can just break here because we will only detect the first invocation that enqueues a dependency.
				$enqueueing_invocations[] = $invocation;
			}
		}
		return $enqueueing_invocations;
	}

	/**
	 * Get the dependencies for a given $handle.
	 *
	 * @see WP_Dependencies::all_deps() Which isn't used because it has side effects.
	 *
	 * @param WP_Dependencies $dependencies Dependencies.
	 * @param string          $handle       Dependency handle.
	 * @param int             $max_depth    Maximum depth to look. Guards against infinite recursion.
	 * @return string[] Handles.
	 */
	public function get_dependencies( WP_Dependencies $dependencies, $handle, $max_depth = 50 ) {
		if ( $max_depth < 0 || ! isset( $dependencies->registered[ $handle ] ) ) {
			return [];
		}

		$dependency_handles = $dependencies->registered[ $handle ]->deps;
		if ( empty( $dependency_handles ) ) {
			return [];
		}

		$max_depth--;
		return array_merge(
			$dependency_handles,
			// Wow, this _is_ PHP (5.6)!
			...array_map(
				function( $dependency_handle ) use ( $dependencies, $max_depth ) {
					return $this->get_dependencies( $dependencies, $dependency_handle, $max_depth );
				},
				$dependency_handles
			)
		);
	}

	/**
	 * Identify the scripts that were enqueued during the hook's invocation.
	 *
	 * @param Invocation $invocation Invocation.
	 *
	 * @return string[] Script handles.
	 */
	public function identify_enqueued_scripts( Invocation $invocation ) {
		$before_script_handles = $invocation->get_before_scripts_queue();
		$after_script_handles  = $this->get_dependency_queue( 'wp_scripts' );

		$enqueued_handles = [];
		foreach ( array_diff( $after_script_handles, $before_script_handles ) as $enqueued_script ) {

			// Flag this script handle as being associated with this hook callback invocation.
			if ( ! isset( $this->sourced_script_enqueues[ $enqueued_script ] ) ) {
				$enqueued_handles[] = $enqueued_script;

				$this->sourced_script_enqueues[ $enqueued_script ] = true;
			}
		}
		return $enqueued_handles;
	}

	/**
	 * Identify the styles that were enqueued during the hook's invocation.
	 *
	 * @todo This needs to apply to widgets, shortcodes, and blocks as well.
	 *
	 * @param Invocation $invocation Invocation.
	 *
	 * @return string[] Style handles.
	 */
	public function identify_enqueued_styles( Invocation $invocation ) {
		$before_style_handles = $invocation->get_before_styles_queue();
		$after_style_handles  = $this->get_dependency_queue( 'wp_styles' );

		$enqueued_handles = [];
		foreach ( array_diff( $after_style_handles, $before_style_handles ) as $enqueued_style ) {

			// Flag this style handle as being associated with this hook callback invocation.
			if ( ! isset( $this->sourced_style_enqueues[ $enqueued_style ] ) ) {
				$enqueued_handles[] = $enqueued_style;

				$this->sourced_style_enqueues[ $enqueued_style ] = true;
			}
		}
		return $enqueued_handles;
	}
}
