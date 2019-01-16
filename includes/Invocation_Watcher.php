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
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

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
	 * Callback for whether queries can be displayed.
	 *
	 * @var callback
	 */
	public $can_show_queries_callback = '__return_true';

	/**
	 * Hook stack.
	 *
	 * @var Invocation[]
	 */
	public $invocation_stack = [];

	/**
	 * Processed hooks.
	 *
	 * @var Invocation[]
	 */
	public $finalized_invocations = [];

	/**
	 * Invocation_Watcher constructor.
	 *
	 * @param Plugin $plugin  Plugin instance.
	 * @param array  $options Options.
	 */
	public function __construct( Plugin $plugin, $options ) {
		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}

		$this->plugin = $plugin;

		$this->hook_wrapper = new Hook_Wrapper(
			[ $this, 'before_hook' ],
			[ $this, 'after_hook' ]
		);

		$this->output_annotator = new Output_Annotator( $this );
	}

	/**
	 * Start watching.
	 */
	public function start() {
		$this->hook_wrapper->add_all_hook();
		$this->output_annotator->start();
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
	 *     @var string   $hook_name     Hook name.
	 *     @var callable $function      Function.
	 *     @var int      $accepted_args Accepted argument count.
	 *     @var int      $priority      Priority.
	 *     @var array    $hook_args     Hook args.
	 * }
	 */
	public function before_hook( $args ) {
		$invocation = new Hook_Invocation( $this, $args );

		$this->invocation_stack[] = $invocation;

		if ( $invocation->can_output() ) {
			$this->output_annotator->print_before_annotation( $invocation );
		}
	}

	/**
	 * After hook.
	 *
	 * @throws \Exception If the stack was empty, which should not happen.
	 */
	public function after_hook() {
		$invocation = array_pop( $this->invocation_stack );
		if ( ! $invocation ) {
			throw new \Exception( 'Stack was empty' );
		}

		$invocation->finalize();

		// @todo This is not correct. An invocation should only store the start query index and end query index. Actual queries performed by invocation can then be determined by examining children.
		$this->plugin->database->identify_invocation_queries( $invocation );

		if ( $invocation->can_output() ) {
			$this->output_annotator->print_after_annotation( $invocation );
		}

		$this->finalized_invocations[ $invocation->id ] = $invocation;
	}
}

