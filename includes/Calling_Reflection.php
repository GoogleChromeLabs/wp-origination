<?php
/**
 * Source Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

use ReflectionMethod, ReflectionClass, ReflectionFunction;

/**
 * Class Calling_Reflection.
 *
 * This is a wrapper around ReflectionMethod/ReflectionFunction classes in order to capture the _calling_ context.
 * The `ReflectionMethod::getFileMame()` method returns the file in which the method was defined in its base class,
 * not in the subclass which invoked it. Similarly, `ReflectionMethod::$name` does not indicate the subclass name,
 * and there is only a `ReflectionMethod::getDeclaringClass()` and no `ReflectionMethod::getCallingClass()`. So this
 * class serves the purpose of capturing reflection information about from which class a method was called.
 */
class Calling_Reflection {

	/**
	 * Reflection object for callback.
	 *
	 * @var ReflectionFunction|ReflectionMethod
	 */
	protected $callback_reflection;

	/**
	 * Reflection object for a method's calling class (not declared class).
	 *
	 * @see ReflectionMethod::getDeclaringClass()
	 * @var ReflectionClass
	 */
	protected $class_reflection;

	/**
	 * Construct.
	 *
	 * @throws Exception When unable to obtain the reflection for the provided callback.
	 * @param callable|string|array $callback The callback for which to get the plugin.
	 */
	public function __construct( $callback ) {
		if ( is_string( $callback ) && is_callable( $callback ) ) {
			// The $callback is a function or static method.
			$exploded_callback = explode( '::', $callback, 2 );
			if ( 2 === count( $exploded_callback ) ) {
				$this->class_reflection    = new ReflectionClass( $exploded_callback[0] );
				$this->callback_reflection = new ReflectionMethod( $exploded_callback[0], $exploded_callback[1] );
			} else {
				$this->callback_reflection = new ReflectionFunction( $callback );
			}
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && method_exists( $callback[0], $callback[1] ) ) {
			$this->class_reflection    = new ReflectionClass( $callback[0] );
			$this->callback_reflection = new ReflectionMethod( $callback[0], $callback[1] );
		} elseif ( is_object( $callback ) && ( 'Closure' === get_class( $callback ) ) ) {
			$this->callback_reflection = new ReflectionFunction( $callback );
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$this->class_reflection    = new ReflectionClass( $callback );
			$this->callback_reflection = new ReflectionMethod( $callback, '__invoke' );
		} else {
			throw new Exception( 'Unrecognized callable.' );
		}
	}

	/**
	 * Get callback reflection.
	 *
	 * @return ReflectionFunction|ReflectionMethod
	 */
	public function get_callback_reflection() {
		return $this->callback_reflection;
	}

	/**
	 * Get normalized name.
	 *
	 * Get the name reference for the function/method, with a method's class normalized to the subclass being called.
	 *
	 * @return string Normalized name.
	 */
	public function get_name() {
		if ( $this->class_reflection ) {
			return $this->class_reflection->getName() . '::' . $this->callback_reflection->getName();
		} else {
			return $this->callback_reflection->getName();
		}
	}

	/**
	 * Get file name.
	 *
	 * The `ReflectionMethod::getFilename()` method is not suitable because for subclasses it returns the file name where
	 * the subclass is defined, not the original class where the non-overridden method is defined. Additionally, the
	 * subclass provided when instantiating a `ReflectionMethod` is not thereafter available.
	 *
	 * The best example
	 * of this is `WP_Widget` subclasses, where a `display_callback` is defined in the base class but we actually
	 * are interested in the subclass file.
	 *
	 * @see ReflectionMethod::getDeclaringClass()
	 * @return string File name where called method is defined.
	 */
	public function get_file_name() {
		if ( $this->class_reflection instanceof ReflectionClass ) {
			return $this->class_reflection->getFileName();
		} else {
			return $this->callback_reflection->getFileName();
		}
	}

	/**
	 * Get namespace for the called method/function.
	 *
	 * @return string Namespace.
	 */
	public function get_namespace_name() {
		if ( $this->class_reflection ) {
			return $this->class_reflection->getNamespaceName();
		} else {
			return $this->callback_reflection->getNamespaceName();
		}
	}

	/**
	 * Determine whether the given reflection method/function has params passed by reference.
	 *
	 * @return bool Whether there are parameters passed by reference.
	 */
	public function has_parameters_passed_by_reference() {
		foreach ( $this->callback_reflection->getParameters() as $parameter ) {
			if ( $parameter->isPassedByReference() ) {
				return true;
			}
		}
		return false;
	}
}
