<?php
/**
 * Class Hook_Wrapper.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Origination;

/**
 * Class Hook_Wrapper
 */
class Hook_Wrapper {

	/**
	 * Function called before all the hook callbacks are invoked.
	 *
	 * @var callable|string
	 */
	public $before_all_callback;

	/**
	 * Function called before invoking each original hook callback.
	 *
	 * @var callable|string
	 */
	public $before_each_callback;

	/**
	 * Function called after invoking each original hook callback.
	 *
	 * @var callable|string
	 */
	public $after_each_callback;

	/**
	 * Function called after all the hook callbacks have been invoked.
	 *
	 * @var callable|string
	 */
	public $after_all_callback;

	/**
	 * List of namespaces to ignore wrapping.
	 *
	 * @var string[]
	 */
	public $ignored_callback_namespaces = [ __NAMESPACE__ ];

	/**
	 * Hook_Wrapper constructor.
	 *
	 * @param callable|string $before_each_callback Function which is called before the original callback function is invoked.
	 * @param callable|string $after_each_callback  Function which is called after the original callback function is invoked.
	 * @param callable|string $before_all_callback  Function which is called before all the hook callbacks are invoked.
	 * @param callable|string $after_all_callback   Function which is called after all the hook callbacks are invoked.
	 */
	public function __construct( $before_each_callback = null, $after_each_callback = null, $before_all_callback = null, $after_all_callback = null ) {
		$this->before_each_callback = $before_each_callback;
		$this->after_each_callback  = $after_each_callback;
		$this->before_all_callback  = $before_all_callback;
		$this->after_all_callback   = $after_all_callback;
	}

	/**
	 * Add all hook to wrap hook callbacks.
	 */
	public function add_all_hook() {
		add_action( 'all', [ $this, 'wrap_hook_callbacks' ] );
	}

	/**
	 * Remove all hook to wrap hook callbacks.
	 */
	public function remove_all_hook() {
		remove_action( 'all', [ $this, 'wrap_hook_callbacks' ] );
	}

	/**
	 * Wrap filter/action callback functions for a given hook to capture performance data.
	 *
	 * Wrapped callback functions are reset to their original functions after invocation.
	 * This runs at the 'all' action.
	 *
	 * @global \WP_Hook[] $wp_filter
	 *
	 * @param string $name Hook name for action or filter.
	 *
	 * @return void
	 */
	public function wrap_hook_callbacks( $name ) {
		global $wp_filter;

		// Short-circuit the 'all' hook if there aren't any callbacks added.
		if ( ! isset( $wp_filter[ $name ] ) ) {
			return;
		}

		// Run callback before all.
		if ( $this->before_all_callback ) {
			call_user_func( $this->before_all_callback, $name );
		}

		$priorities = array_keys( $wp_filter[ $name ]->callbacks );
		foreach ( $priorities as $priority ) {
			// @todo If $priority is PHP_INT_MAX, consider moving/merging them to PHP_INT_MAX - 1.
			foreach ( $wp_filter[ $name ]->callbacks[ $priority ] as &$callback ) {
				$function = $callback['function'];

				try {
					$source = new Calling_Reflection( $function );
				} catch ( \Exception $e ) {
					// Skip if the source callback function cannot be determined by chance.
					// @todo This should perform a callback to communicate this case.
					continue;
				}

				// Prevent wrapping our own hooks.
				if ( in_array( $source->get_namespace_name(), $this->ignored_callback_namespaces, true ) ) {
					continue;
				}

				/*
				 * A current limitation with wrapping callbacks is that the wrapped function cannot have
				 * any parameters passed by reference. Without this the result is:
				 *
				 * > PHP Warning:  Parameter 1 to wp_default_styles() expected to be a reference, value given.
				 */
				if ( $source->has_parameters_passed_by_reference() ) {
					// @todo This should perform a callback to communicate this case.
					continue;
				}

				$callback['function'] = function() use ( &$callback, $name, $priority, $function, $source ) {
					// Restore the original callback function after this wrapped callback function is invoked.
					$callback['function'] = $function;

					$hook_args = func_get_args();

					// @todo Optionally capture debug backtrace?
					$reflection     = $source->get_callback_reflection();
					$source_file    = $source->get_file_name();
					$function_name  = $source->get_name();
					$accepted_args  = $callback['accepted_args']; // @todo This is unnecessary.
					$is_filter      = ! did_action( $name );
					$value_modified = null;
					$context        = compact( 'name', 'function', 'function_name', 'reflection', 'source_file', 'accepted_args', 'priority', 'hook_args', 'is_filter' );
					if ( $this->before_each_callback ) {
						call_user_func( $this->before_each_callback, $context );
					}
					$exception = null;
					try {
						$return = call_user_func_array( $function, $hook_args );
						if ( $is_filter && isset( $hook_args[0] ) ) {
							$value_modified = ( $return !== $hook_args[0] );
						}
					} catch ( \Exception $e ) {
						$exception = $e;
						$return    = null;
					}
					if ( $this->after_each_callback ) {
						$context['return'] = $return;
						if ( $is_filter ) {
							$context['value_modified'] = $value_modified;
						}

						$return_override = call_user_func( $this->after_each_callback, $context );

						// Give the opportunity for the after_callback to override the (filtered) hook response, e.g. to add annotations.
						if ( isset( $return_override ) ) {
							$return = $return_override;
						}
					}
					if ( $exception ) {
						throw $exception;
					}

					return $return;
				};
			}
		}

		// Run callback after all.
		if ( $this->after_all_callback ) {
			$after_all_priority = max( $priorities ) + 1;
			$after_all_callback = function () use ( $name, &$after_all_callback, $after_all_priority ) {
				remove_filter( $name, $after_all_callback, $after_all_priority ); // Remove self.
				return call_user_func_array( $this->after_all_callback, array_merge( [ $name ], func_get_args() ) );
			};
			add_filter( $name, $after_all_callback, $after_all_priority, 2 );
		}
	}
}
