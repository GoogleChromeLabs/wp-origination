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
	 * Stack of pending hook invocations.
	 *
	 * When all filters have applied, the array of hook invocations is popped off this stack and used to wrap the filtered value.
	 * Deferring the annotations in this way prevents the annotations from interfering with the normal operation of the
	 * filter callbacks.
	 *
	 * @var array[Hook_Invocation[]]
	 */
	protected $pending_filter_invocations_stack = [];

	/**
	 * All invocations by index and ordered by occurrence.
	 *
	 * @var Invocation[]
	 */
	public $invocations = [];

	/**
	 * Filters that can be annotated.
	 *
	 * @todo This list should be easily configurable.
	 *
	 * @var string[]
	 */
	public $annotatable_filters = [
		'comment_text',
		'embed_handler_html',
		'embed_oembed_html',
		'post_gallery',
		'pre_wp_nav_menu',
		'render_block',
		'script_loader_tag',
		'style_loader_tag',
		'the_content',
		'the_excerpt',
		'the_title',
		'walker_nav_menu_start_el',
		'widget_text',
		'widget_text_content',
		'widget_title',
		'wp_nav_menu',
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
		$this->hook_wrapper->before_each_callback = [ $this, 'before_each_hook_callback' ];
		$this->hook_wrapper->after_each_callback  = [ $this, 'after_each_hook_callback' ];
		$this->hook_wrapper->before_all_callback  = [ $this, 'before_all_hook_callbacks' ];
		$this->hook_wrapper->after_all_callback   = [ $this, 'after_all_hook_callbacks' ];
		$this->hook_wrapper->add_all_hook();

		add_action( 'template_redirect', [ $this, 'wrap_shortcode_callbacks' ] );
		add_action( 'template_redirect', [ $this, 'wrap_block_render_callbacks' ] );
		add_action( 'template_redirect', [ $this, 'wrap_widget_callbacks' ] );
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
	 * Determine whether a hook is a filter.
	 *
	 * @param string $name Hook name.
	 * @return bool Whether filter.
	 */
	protected function is_filter( $name ) {
		return ! did_action( $name );
	}

	/**
	 * Add a filter to run at the very end to wrap the output with the annotations, if the filter is annotatable.
	 *
	 * @param string $name Name.
	 */
	public function before_all_hook_callbacks( $name ) {
		if ( $this->is_filter( $name ) ) {
			$this->pending_filter_invocations_stack[] = [];
		}
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
	public function before_each_hook_callback( $args ) {
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
	 */
	public function after_each_hook_callback( $args ) {
		$invocation = array_pop( $this->invocation_stack );
		if ( ! $invocation ) {
			throw new \Exception( 'Stack was empty' );
		}

		$value_modified = ! empty( $args['value_modified'] );
		$invocation->finalize(
			compact( 'value_modified' )
		);

		if ( $invocation instanceof Hook_Invocation && ! $invocation->is_action() ) {
			$this->pending_filter_invocations_stack[ count( $this->pending_filter_invocations_stack ) - 1 ][] = $invocation;
		}

		// @todo There needs to be a callback to be given an $invocation and for us to determine whether or not to render given $args.
		// @todo $this->output_annotator->should_annotate( $invocation, $args )
		if ( $invocation->can_output() ) {
			echo $this->output_annotator->get_after_annotation( $invocation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * After all filter hook callbacks.
	 *
	 * @param string $name  Hook name.
	 * @param mixed  $value Filtered value.
	 * @return mixed Filtered value, with wrapped annotations if filter is annotatable.
	 */
	public function after_all_hook_callbacks( $name, $value = null ) {
		if ( ! $this->is_filter( $name ) ) {
			return $value;
		}
		$pending_invocations = array_pop( $this->pending_filter_invocations_stack );
		assert( is_array( $pending_invocations ) );
		foreach ( $pending_invocations as $invocation ) {
			assert( $invocation instanceof Hook_Invocation );
			assert( ! $invocation->is_action() );
			assert( $name === $invocation->name );
			if ( $invocation->value_modified && in_array( $name, $this->annotatable_filters, true ) ) {
				$value = $this->output_annotator->get_before_annotation( $invocation ) . $value . $this->output_annotator->get_after_annotation( $invocation );
			}
		}
		return $value;
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

	/**
	 * Wrap each widget render callback to capture the invocation.
	 *
	 * @global array $wp_registered_widgets
	 */
	public function wrap_widget_callbacks() {
		global $wp_registered_widgets;
		foreach ( $wp_registered_widgets as $widget_id => &$registered_widget ) {
			$function = $registered_widget['callback'];

			/**
			 * Wrapped callback.
			 *
			 * @see dynamic_sidebar()
			 *
			 * @param array $args        An array of widget display arguments.
			 * @param array $widget_args An array of multi-widget arguments.
			 * @return mixed
			 */
			$registered_widget['callback'] = function( $args = array(), $widget_args = array() ) use ( $registered_widget, $function ) {
				$parent = $this->get_parent_invocation();
				$source = $this->hook_wrapper::get_source( $function );

				$invocation_args = compact( 'parent', 'function' );

				$invocation_args['source_file']   = $source['file'];
				$invocation_args['reflection']    = $source['reflection'];
				$invocation_args['function_name'] = $source['function'];

				$widget = null;
				if ( is_array( $function ) && isset( $function[0] ) && $function[0] instanceof \WP_Widget ) {
					$widget = $function[0];
				}
				$number = isset( $widget_args['number'] ) ? $widget_args['number'] : null;

				/*
				 * Note that $widget_args should only contain 'number' if it is a multi-widget.
				 * There is also a possibility that additional params could be registered for non-multi widgets
				 * when more than 4 arguments to wp_register_sidebar_widget(), but this is probably unlikely
				 * and it is even less likely to be necessary for our purposes here.
				 */
				$invocation_args = array_merge(
					$invocation_args,
					compact( 'widget', 'number' ),
					array(
						'id'   => $args['widget_id'],
						'name' => $args['widget_name'],
					)
				);

				$invocation = new Widget_Invocation( $this, $this->incrementor, $this->database, $this->file_locator, $this->dependencies, $invocation_args );
				if ( $parent ) {
					$parent->children[] = $invocation;
				}

				$this->invocation_stack[]                = $invocation;
				$this->invocations[ $invocation->index ] = $invocation;

				echo $this->output_annotator->get_before_annotation( $invocation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$return = call_user_func_array( $function, func_get_args() ); // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection
				echo $this->output_annotator->get_after_annotation( $invocation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				array_pop( $this->invocation_stack );
				$invocation->finalize();

				return $return;
			};
		}
	}
}

