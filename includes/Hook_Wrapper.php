<?php
/**
 * Class Hook_Wrapper.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Hook_Wrapper
 */
class Hook_Wrapper {

	/**
	 * Function called before invoking original hook callback.
	 *
	 * @var callable|string
	 */
	public $before_callback;

	/**
	 * Function called after invoking original hook callback.
	 *
	 * @var callable|string
	 */
	public $after_callback;

	/**
	 * Hook_Wrapper constructor.
	 *
	 * @param callable|string $before_callback Function which is called before the original callback function is invoked.
	 * @param callable|string $after_callback  Function which is called after the original callback function is invoked.
	 */
	public function __construct( $before_callback = null, $after_callback = null ) {
		$this->before_callback = $before_callback;
		$this->after_callback  = $after_callback;
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

		foreach ( $wp_filter[ $name ]->callbacks as $priority => &$callbacks ) {
			foreach ( $callbacks as &$callback ) {
				$function = $callback['function'];

				$source = static::get_source( $function );

				// Skip if the source callback function cannot be determined by chance.
				if ( ! $source ) {
					// @todo This should perform a callback to communicate this case.
					continue;
				}

				/*
				 * A current limitation with wrapping callbacks is that the wrapped function cannot have
				 * any parameters passed by reference. Without this the result is:
				 *
				 * > PHP Warning:  Parameter 1 to wp_default_styles() expected to be a reference, value given.
				 */
				if ( static::has_parameters_passed_by_reference( $source['reflection'] ) ) {
					// @todo This should perform a callback to communicate this case.
					continue;
				}

				$function_name = $source['function'];
				$source_file   = $source['file'];
				$reflection    = $source['reflection'];

				$callback['function'] = function() use ( &$callback, $name, $priority, $function, $reflection, $function_name, $source_file ) {
					// Restore the original callback function after this wrapped callback function is invoked.
					$callback['function'] = $function;

					$hook_args = func_get_args();

					// @todo Optionally capture debug backtrace?
					$accepted_args  = $callback['accepted_args'];
					$is_filter      = ! did_action( $name );
					$value_modified = null;
					$context        = compact( 'name', 'function', 'function_name', 'reflection', 'source_file', 'accepted_args', 'priority', 'hook_args', 'is_filter' );
					if ( $this->before_callback ) {
						call_user_func( $this->before_callback, $context );
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
					if ( $this->after_callback ) {
						$context['return'] = $return;
						if ( $is_filter ) {
							$context['value_modified'] = $value_modified;
						}

						call_user_func( $this->after_callback, $context );
					}
					if ( $exception ) {
						throw $exception;
					}

					return $return;
				};
			}
		}
	}

	/**
	 * Gets the plugin or theme of the callback, if one exists.
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
