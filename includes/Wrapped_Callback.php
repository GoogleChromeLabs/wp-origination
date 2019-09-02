<?php
/**
 * Class Wrapped_Callback.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Origination;

/**
 * Class Wrapped_Callback
 */
class Wrapped_Callback implements \ArrayAccess {

	/**
	 * Callback.
	 *
	 * @var array|callable
	 */
	protected $callback;

	/**
	 * Invocation watcher.
	 *
	 * @var Invocation_Watcher
	 */
	protected $invocation_watcher;

	/**
	 * Invocation args.
	 *
	 * @var callable
	 */
	protected $invocation_creator;

	/**
	 * Invocation invoker.
	 *
	 * Callback to invoke the invocation and annotate the response.
	 *
	 * @var callable
	 */
	protected $invocation_invoker;

	/**
	 * Wrapped_Callback constructor.
	 *
	 * @param callable           $callback           Callback.
	 * @param Invocation_Watcher $invocation_watcher Parent invocation.
	 * @param callable           $invocation_creator Invocation creator.
	 * @param callable           $invocation_invoker Invocation invoker.
	 *
	 * @throws \Exception If the supplied $invocation_class is not an Invocation class name.
	 */
	public function __construct( $callback, $invocation_watcher, $invocation_creator, $invocation_invoker ) {
		$this->callback           = $callback;
		$this->invocation_watcher = $invocation_watcher;
		$this->invocation_creator = $invocation_creator;
		$this->invocation_invoker = $invocation_invoker;
	}

	/**
	 * Invoke wrapped callback.
	 *
	 * @return mixed
	 */
	public function __invoke() {
		$parent = $this->invocation_watcher->get_parent_invocation();

		try {
			$source  = new Calling_Reflection( $this->callback );
			$context = [
				'source_file'   => $source->get_file_name(),
				'reflection'    => $source->get_callback_reflection(),
				'function_name' => $source->get_name(),
			];
		} catch ( \Exception $e ) {
			$context['exception'] = $e->getMessage();
		}

		$invocation_args = array_merge(
			compact( 'parent' ),
			[ 'function' => $this->callback ],
			$context
		);

		$invocation = call_user_func( $this->invocation_creator, $invocation_args, func_get_args() );

		if ( $parent ) {
			$parent->children[] = $invocation;
		}

		// @todo This should be a method, like push_invocation().
		$this->invocation_watcher->invocation_stack[] = $invocation;

		$this->invocation_watcher->invocations[ $invocation->index ] = $invocation;

		$return = call_user_func( $this->invocation_invoker, $invocation, func_get_args() );

		array_pop( $this->invocation_watcher->invocation_stack );
		$invocation->finalize();

		return $return;
	}

	/**
	 * Offset set.
	 *
	 * @param mixed $offset Offset.
	 * @param mixed $value  Value.
	 */
	public function offsetSet( $offset, $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( ! is_array( $this->callback ) ) {
			return;
		}
		if ( is_null( $offset ) ) {
			$this->callback[] = $value;
		} else {
			$this->callback[ $offset ] = $value;
		}
	}

	/**
	 * Offset exists.
	 *
	 * @param mixed $offset Offset.
	 * @return bool Exists.
	 */
	public function offsetExists( $offset ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( ! is_array( $this->callback ) ) {
			return false;
		}
		return isset( $this->callback[ $offset ] );
	}

	/**
	 * Offset unset.
	 *
	 * @param mixed $offset Offset.
	 */
	public function offsetUnset( $offset ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( ! is_array( $this->callback ) ) {
			return;
		}
		unset( $this->callback[ $offset ] );
	}

	/**
	 * Offset get.
	 *
	 * @param mixed $offset Offset.
	 * @return mixed|null Value.
	 */
	public function offsetGet( $offset ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( is_array( $this->callback ) && isset( $this->callback[ $offset ] ) ) {
			return $this->callback[ $offset ];
		}
		return null;
	}
}
