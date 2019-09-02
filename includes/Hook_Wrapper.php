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

				$source = static::get_source( $function );

				// Skip if the source callback function cannot be determined by chance.
				if ( ! $source ) {
					// @todo This should perform a callback to communicate this case.
					continue;
				}

				/*
				 * Prevent wrapping our own hooks.
				 * @todo Why does ReflectionMethod::getNamespaceName() returns nothing? This is why we have to getDeclaringClass().
				 */
				$reflection = $source['reflection'];
				$namespace  = ( $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass() : $reflection )->getNamespaceName();
				if ( in_array( $namespace, $this->ignored_callback_namespaces, true ) ) {
					continue;
				}

				/*
				 * A current limitation with wrapping callbacks is that the wrapped function cannot have
				 * any parameters passed by reference. Without this the result is:
				 *
				 * > PHP Warning:  Parameter 1 to wp_default_styles() expected to be a reference, value given.
				 */
				if ( static::has_parameters_passed_by_reference( $reflection ) ) {
					// @todo This should perform a callback to communicate this case.
					continue;
				}

				$function_name = $source['function'];
				$source_file   = $source['file'];

				$callback['function'] = function() use ( &$callback, $name, $priority, $function, $reflection, $function_name, $source_file ) {
					// Restore the original callback function after this wrapped callback function is invoked.
					$callback['function'] = $function;

					$hook_args = func_get_args();

					// @todo Optionally capture debug backtrace?
					$accepted_args  = $callback['accepted_args'];
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

	/**
	 * Gets the plugin or theme of the callback, if one exists.
	 *
	 * @todo This is the wrong location for this.
	 *
	 * @param callable|string|array $callback The callback for which to get the plugin.
	 * @return array|null {
	 *     The source data.
	 *
	 *     @type string                                $function   Normalized function name.
	 *     @type \ReflectionMethod|\ReflectionFunction $reflection Reflection object.
	 *     @type string                                $file       Path to where the callback was defined.
	 * }
	 */
	public static function get_source( $callback ) {
		$reflection = null;
		$class_name = null; // Because ReflectionMethod::getDeclaringClass() can return a parent class.
		$file       = null;
		try {
			if ( is_string( $callback ) && is_callable( $callback ) ) {
				// The $callback is a function or static method.
				$exploded_callback = explode( '::', $callback, 2 );
				if ( 2 === count( $exploded_callback ) ) {
					$class_name = $exploded_callback[0];
					$reflection = new \ReflectionMethod( $exploded_callback[0], $exploded_callback[1] );
				} else {
					$reflection = new \ReflectionFunction( $callback );
				}
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && method_exists( $callback[0], $callback[1] ) ) {
				// The $callback is a method.
				if ( is_string( $callback[0] ) ) {
					$class_name = $callback[0];
				} elseif ( is_object( $callback[0] ) ) {
					$class_name = get_class( $callback[0] );
				}

				/*
				 * Obtain file from ReflectionClass because if the method is not on base class then
				 * file returned by ReflectionMethod will be for the base class not the subclass.
				 */
				$reflection = new \ReflectionClass( $callback[0] );
				$file       = $reflection->getFileName();

				// This is needed later for has_parameters_passed_by_reference().
				$reflection = new \ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && ( 'Closure' === get_class( $callback ) ) ) {
				$reflection = new \ReflectionFunction( $callback );
			}
		} catch ( \Exception $e ) {
			return null;
		}

		if ( ! $reflection ) {
			return null;
		}

		if ( ! $file ) {
			$file = $reflection->getFileName();
		}

		$source = compact( 'reflection', 'file' );
		if ( $class_name ) {
			$source['function'] = $class_name . '::' . $reflection->getName();
		} else {
			$source['function'] = $reflection->getName();
		}

		return $source;
	}

	/**
	 * Determine whether the given reflection method/function has params passed by reference.
	 *
	 * @param \ReflectionFunction|\ReflectionMethod $reflection Reflection.
	 * @return bool Whether there are parameters passed by reference.
	 */
	public static function has_parameters_passed_by_reference( $reflection ) {
		foreach ( $reflection->getParameters() as $parameter ) {
			if ( $parameter->isPassedByReference() ) {
				return true;
			}
		}
		return false;
	}
}
