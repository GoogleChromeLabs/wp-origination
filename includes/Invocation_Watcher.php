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
	 * Filters that can be annotated.
	 *
	 * @var string[]
	 */
	public $annotatable_filters = [
		'the_content',
		'the_excerpt',
	];

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

		add_action( 'template_redirect', [ $this, 'wrap_shortcode_callbacks' ] );
		add_action( 'template_redirect', [ $this, 'wrap_block_render_callbacks' ] );
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
	 * Get the parent invocation based on the stack.
	 *
	 * @return Invocation|null Parent invocation.
	 */
	public function get_parent_invocation() {
		$parent = null;
		if ( ! empty( $this->invocation_stack ) ) {
			$parent = $this->invocation_stack[ count( $this->invocation_stack ) - 1 ];
		}
		return $parent;
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
		$parent = $this->get_parent_invocation();

		$args['parent'] = $parent;

		$invocation = new Hook_Invocation( $this, $this->incrementor, $this->database, $this->file_locator, $this->dependencies, $args );

		if ( $parent ) {
			$parent->children[] = $invocation;
		}

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

		$value_modified = ! empty( $args['value_modified'] );

		$invocation->finalize(
			compact( 'value_modified' )
		);

		// @todo There needs to be a callback to be given an $invocation and for us to determine whether or not to render given $args.
		// @todo $this->output_annotator->should_annotate( $invocation, $args )
		if ( $invocation->can_output() ) {
			echo $this->output_annotator->get_after_annotation( $invocation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( $value_modified && ! empty( $args['return'] ) && $invocation instanceof Hook_Invocation && ! $invocation->is_action() && in_array( $invocation->name, $this->annotatable_filters, true ) ) {
			return $this->output_annotator->get_before_annotation( $invocation ) . $args['return'] . $this->output_annotator->get_after_annotation( $invocation );
		}

		// Do not override the return value of the hook.
		return null;
	}

	/**
	 * Wrap each shortcode callback to capture the invocation.
	 *
	 * This function must be called after all shortcodes are added, such as at template_redirect.
	 * Note that overriding and wrapping the callback is done instead of using the 'do_shortcode_tag' filter
	 * because the former method allows us to capture the stylesheets that were enqueued when it was called.
	 *
	 * @global array $shortcode_tags
	 */
	public function wrap_shortcode_callbacks() {
		global $shortcode_tags;

		foreach ( $shortcode_tags as $tag => &$callback ) {
			$function = $callback;

			$callback = function( $attributes, $content ) use ( $tag, $function ) {
				$parent = $this->get_parent_invocation();

				$source = $this->hook_wrapper::get_source( $function );

				$args = compact( 'tag', 'attributes', 'content', 'parent', 'function' );

				$args['source_file']   = $source['file'];
				$args['reflection']    = $source['reflection'];
				$args['function_name'] = $source['function'];

				$invocation = new Shortcode_Invocation( $this, $this->incrementor, $this->database, $this->file_locator, $this->dependencies, $args );
				if ( $parent ) {
					$parent->children[] = $invocation;
				}

				$this->invocation_stack[]                = $invocation;
				$this->invocations[ $invocation->index ] = $invocation;

				$return = call_user_func( $function, $attributes, $content );

				array_pop( $this->invocation_stack );
				$invocation->finalize();

				return $this->output_annotator->get_before_annotation( $invocation ) . $return . $this->output_annotator->get_after_annotation( $invocation );
			};
		}
	}

	/**
	 * Wrap each block render callback to capture the invocation.
	 *
	 * This only applies to dynamic blocks. For static blocks, the 'render_block' filter is used and which is handled
	 * by the Output_Annotator, since static blocks do not involve invocations.
	 *
	 * This function must be called after all blocks are registered, such as at template_redirect.
	 * Note that overriding and wrapping the callback is done instead of exclusively using the 'render_block' filter
	 * because the former method allows us to capture the stylesheets that were enqueued when it was called.
	 *
	 * @see \Google\WP_Sourcery\Output_Annotator::add_static_block_annotation()
	 */
	public function wrap_block_render_callbacks() {
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $block_type ) {
			if ( ! $block_type->is_dynamic() ) {
				continue;
			}

			$function = $block_type->render_callback;

			$block_type->render_callback = function( $attributes, $content ) use ( $block_type, $function ) {
				$parent = $this->get_parent_invocation();

				$source = $this->hook_wrapper::get_source( $function );
				$name   = $block_type->name;

				$args = compact( 'name', 'attributes', 'content', 'parent', 'function' );

				$args['source_file']   = $source['file'];
				$args['reflection']    = $source['reflection'];
				$args['function_name'] = $source['function'];

				$invocation = new Block_Invocation( $this, $this->incrementor, $this->database, $this->file_locator, $this->dependencies, $args );
				if ( $parent ) {
					$parent->children[] = $invocation;
				}

				$this->invocation_stack[]                = $invocation;
				$this->invocations[ $invocation->index ] = $invocation;

				$return = call_user_func( $function, $attributes, $content );

				array_pop( $this->invocation_stack );
				$invocation->finalize();

				return $this->output_annotator->get_before_annotation( $invocation ) . $return . $this->output_annotator->get_after_annotation( $invocation );
			};
		}
	}
}

