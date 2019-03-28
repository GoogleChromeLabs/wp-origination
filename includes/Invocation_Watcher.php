<?php
/**
 * Invocation_Watcher Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Invocation_Watcher.
 */
class Invocation_Watcher {

	/**
	 * Instance of Database.
	 *
	 * @var Database
	 */
	public $database;

	/**
	 * Instance of Hook_Wrapper.
	 *
	 * @var Hook_Wrapper
	 */
	public $hook_wrapper;

	/**
	 * Instance of Output_Annotator.
	 *
	 * @var Output_Annotator
	 */
	public $output_annotator;

	/**
	 * Instance of File_Locator.
	 *
	 * @var File_Locator
	 */
	public $file_locator;

	/**
	 * Instance of Incrementor.
	 *
	 * @var Incrementor
	 */
	public $incrementor;

	/**
	 * Instance of Dependencies.
	 *
	 * @var Dependencies
	 */
	public $dependencies;

	/**
	 * Hook stack.
	 *
	 * @var Invocation[]
	 */
	public $invocation_stack = [];

	/**
	 * All invocations by index and ordered by occurrence.
	 *
	 * @var Invocation[]
	 */
	public $invocations = [];

	/**
	 * Invocation_Watcher constructor.
	 *
	 * @param File_Locator     $file_locator     File locator.
	 * @param Output_Annotator $output_annotator Output annotator.
	 * @param Dependencies     $dependencies     Dependencies.
	 * @param Database         $database         Database.
	 * @param Incrementor      $incrementor      Incrementor.
	 * @param Hook_Wrapper     $hook_wrapper     Hook wrapper.
	 */
	public function __construct( File_Locator $file_locator, Output_Annotator $output_annotator, Dependencies $dependencies, Database $database, Incrementor $incrementor, Hook_Wrapper $hook_wrapper ) {
		$this->file_locator     = $file_locator;
		$this->output_annotator = $output_annotator;
		$this->dependencies     = $dependencies;
		$this->database         = $database;
		$this->incrementor      = $incrementor;
		$this->hook_wrapper     = $hook_wrapper;
	}

	/**
	 * Start watching.
	 */
	public function start() {
		$this->hook_wrapper->before_callback = [ $this, 'before_hook' ];
		$this->hook_wrapper->after_callback  = [ $this, 'after_hook' ];
		$this->hook_wrapper->add_all_hook();
	}

	/**
	 * Determine whether queries can be shown.
	 *
	 * @return bool Whether to show queries.
	 */
	public function can_show_queries() {
		return ! empty( $this->can_show_queries_callback ) && call_user_func( $this->can_show_queries_callback );
	}

	/**
	 * Before hook.
	 *
	 * @param array $args {
	 *      Args.
	 *
	 *     @var string   $name          Name.
	 *     @var callable $function      Function.
	 *     @var int      $accepted_args Accepted argument count.
	 *     @var int      $priority      Priority.
	 *     @var array    $hook_args     Hook args.
	 * }
	 */
	public function before_hook( $args ) {
		$parent = null;
		if ( ! empty( $this->invocation_stack ) ) {
			$parent = $this->invocation_stack[ count( $this->invocation_stack ) - 1 ];
		}

		$args['parent'] = $parent;

		$invocation = new Hook_Invocation( $this, $this->incrementor, $this->database, $this->file_locator, $this->dependencies, $args );

		$parent->children[] = $invocation;

		$this->invocation_stack[] = $invocation;

		$this->invocations[ $invocation->index ] = $invocation;

		// @todo There needs to be a callback to be given an $invocation and for us to determine whether or not to render given $args.
		if ( $invocation->can_output() ) {
			echo $this->output_annotator->get_before_annotation( $invocation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * After hook.
	 *
	 * @throws \Exception If the stack was empty, which should not happen.
	 *
	 * @param array $args {
	 *      Args.
	 *
	 *     @var string   $name           Name.
	 *     @var callable $function       Function.
	 *     @var int      $accepted_args  Accepted argument count.
	 *     @var int      $priority       Priority.
	 *     @var array    $hook_args      Hook args.
	 *     @var bool     $value_modified Whether the value was modified.
	 *     @var mixed    $return         The returned value when filtering.
	 * }
	 * @return null|mixed
	 */
	public function after_hook( $args ) {
		$invocation = array_pop( $this->invocation_stack );
		if ( ! $invocation ) {
			throw new \Exception( 'Stack was empty' );
		}

		$invocation->finalize(
			array(
				'value_modified' => ! empty( $args['value_modified'] ),
			)
		);

		// @todo There needs to be a callback to be given an $invocation and for us to determine whether or not to render given $args.
		// @todo $this->output_annotator->should_annotate( $invocation, $args )
		if ( $invocation->can_output() ) {
			echo $this->output_annotator->get_after_annotation( $invocation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! empty( $args['value_modified'] ) && ! empty( $args['return'] ) && $invocation instanceof Hook_Invocation && ! $invocation->is_action() && 'the_content' === $invocation->name ) {
			return $this->output_annotator->get_before_annotation( $invocation ) . $args['return'] . $this->output_annotator->get_after_annotation( $invocation );
		}

		// Do not override the return value of the hook.
		return null;
	}
}

