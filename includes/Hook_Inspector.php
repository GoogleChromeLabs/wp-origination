<?php
/**
 * Hook_Inspector Class.
 *
 * @package Google\WP_Sourcery
 */

namespace Google\WP_Sourcery;

use \WP_Dependencies, \WP_Styles, \WP_Scripts, \wpdb;

/**
 * Class Hook_Inspector.
 *
 * @todo Rename to Hook_Annotator?
 */
class Hook_Inspector {

	const ANNOTATION_TAG = 'sourcery';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Callback for whether queries can be displayed.
	 *
	 * @var callback
	 */
	public $can_show_queries_callback = '__return_true';

	/**
	 * Hook stack.
	 *
	 * @var Hook_Inspection[]
	 */
	public $hook_stack = array();

	/**
	 * Processed hooks.
	 *
	 * @var Hook_Inspection[]
	 */
	public $processed_hooks = array();

	/**
	 * Database abstraction for WordPress.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Lookup of which queries have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var bool[]
	 */
	protected $sourced_query_indices = array();

	/**
	 * Lookup of which enqueued scripts have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var bool[]
	 */
	protected $sourced_script_enqueues = array();

	/**
	 * Lookup of which enqueued styles have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var bool[]
	 */
	protected $sourced_style_enqueues = array();

	/**
	 * Hook_Inspector constructor.
	 *
	 * @param Plugin $plugin  Plugin instance.
	 * @param array  $options Options.
	 * @global \wpdb $wpdb
	 */
	public function __construct( Plugin $plugin, $options ) {
		global $wpdb;

		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}

		$this->plugin = $plugin;
		$this->wpdb   = $wpdb;
	}

	/**
	 * Get the WordPress DB.
	 *
	 * @todo Move this to the Plugin?
	 * @return wpdb|object DB.
	 */
	public function get_wpdb() {
		return $this->wpdb;
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
		return array();
	}

	/**
	 * Get the invocations that result in a dependency being enqueued (either directly or via another dependency).
	 *
	 * @param string $type   Type (e.g. 'wp_scripts', 'wp_styles').
	 * @param string $handle Dependency handle.
	 * @return Hook_Inspection[] Invocations.
	 */
	public function get_dependency_enqueueing_invocations( $type, $handle ) {
		$enqueueing_invocations = array();
		foreach ( $this->processed_hooks as $invocation ) {
			// @todo This should be be improved, perhaps a method that we can pass $type.
			if ( 'wp_scripts' === $type ) {
				$enqueued_handles = $invocation->enqueued_scripts;
			} elseif ( 'wp_styles' === $type ) {
				$enqueued_handles = $invocation->enqueued_styles;
			} else {
				$enqueued_handles = array();
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
			return array();
		}

		$dependency_handles = $dependencies->registered[ $handle ]->deps;
		if ( empty( $dependency_handles ) ) {
			return array();
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
	 * Determine whether a given hook is an action.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 * @return bool Whether hook is an action.
	 */
	public function is_action( Hook_Inspection $hook_inspection ) {
		return did_action( $hook_inspection->hook_name ) > 0;
	}

	/**
	 * Get hook placeholder annotation pattern.
	 *
	 * Pattern assumes that regex delimiter will be '#'.
	 *
	 * @return string Pattern.
	 */
	public function get_hook_placeholder_annotation_pattern() {
		return '<!-- (?P<closing>/)?' . static::ANNOTATION_TAG . ' (?P<id>\d+) -->';
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
		$hook_inspection = new Hook_Inspection( $this, $args );

		$this->hook_stack[] = $hook_inspection;

		if ( $this->is_action( $hook_inspection ) ) {
			$this->print_before_hook_annotation( $hook_inspection );
		}
	}

	/**
	 * After hook.
	 *
	 * @throws \Exception If the stack was empty, which should not happen.
	 */
	public function after_hook() {
		$hook_inspection = array_pop( $this->hook_stack );
		if ( ! $hook_inspection ) {
			throw new \Exception( 'Stack was empty' );
		}

		$hook_inspection->finalize();

		$this->identify_hook_queries( $hook_inspection );

		if ( $this->is_action( $hook_inspection ) ) {
			$this->print_after_hook_annotation( $hook_inspection );
		}

		$this->processed_hooks[ $hook_inspection->id ] = $hook_inspection;
	}

	/**
	 * Print hook annotation placeholder before an action hook's invoked callback.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 */
	public function print_before_hook_annotation( Hook_Inspection $hook_inspection ) {
		printf( '<!-- %s %d -->', static::ANNOTATION_TAG, $hook_inspection->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Print hook annotation placeholder after an action hook's invoked callback.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 */
	public function print_after_hook_annotation( Hook_Inspection $hook_inspection ) {
		printf( '<!-- /%s %d -->', static::ANNOTATION_TAG, $hook_inspection->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Finalize annotations.
	 *
	 * Given this HTML in the buffer:
	 *
	 *     <html data-first="B<A" <!-- hook 128 --> data-hello=world <!-- /hook 128--> data-second="A>B">.
	 *
	 * The returned string should be:
	 *
	 *     <html data-first="B<A"  data-hello=world  data-second="A>B">.
	 *
	 * @param string $buffer Buffer.
	 * @return string Processed buffer.
	 */
	public function finalize_hook_annotations( $buffer ) {
		$hook_placeholder_annotation_pattern = static::get_hook_placeholder_annotation_pattern();

		// Match all start tags that have attributes.
		$pattern = join(
			'',
			array(
				'#<',
				'(?P<name>[a-zA-Z0-9_\-]+)',
				'(?P<attrs>\s',
				'(?:' . $hook_placeholder_annotation_pattern . '|[^<>"\']+|"[^"]*+"|\'[^\']*+\')*+', // Attribute tokens, plus hook annotations.
				')>#s',
			)
		);

		$buffer = preg_replace_callback(
			$pattern,
			array( $this, 'purge_hook_annotations_in_start_tag' ),
			$buffer
		);

		$buffer = preg_replace_callback(
			'#' . $hook_placeholder_annotation_pattern . '#',
			array( $this, 'hydrate_hook_annotation' ),
			$buffer
		);

		return $buffer;
	}

	/**
	 * Purge hook annotations in start tag.
	 *
	 * @param array $start_tag_matches Start tag matches.
	 * @return string Start tag.
	 */
	public function purge_hook_annotations_in_start_tag( $start_tag_matches ) {
		$attributes = preg_replace_callback(
			'#' . static::get_hook_placeholder_annotation_pattern() . '#',
			function( $hook_matches ) {
				$id = intval( $hook_matches['id'] );
				if ( isset( $this->processed_hooks[ $id ] ) ) {
					$this->processed_hooks[ $id ]->intra_tag = true;
				}
				return ''; // Purge since an HTML comment cannot occur in a start tag.
			},
			$start_tag_matches['attrs']
		);

		return '<' . $start_tag_matches['name'] . $attributes . '>';
	}

	/**
	 * Hydrate hook annotation comments with their invocation details.
	 *
	 * @param array $matches Matches.
	 * @return string Hydrated hook annotation.
	 */
	public function hydrate_hook_annotation( $matches ) {
		$id = intval( $matches['id'] );
		if ( ! isset( $this->processed_hooks[ $id ] ) ) {
			return '';
		}
		$hook_inspection = $this->processed_hooks[ $id ];

		$closing = ! empty( $matches['closing'] );

		$data = array(
			'id'       => $hook_inspection->id,
			'type'     => $hook_inspection->is_action() ? 'action' : 'filter',
			'name'     => $hook_inspection->hook_name,
			'priority' => $hook_inspection->priority,
			'callback' => $hook_inspection->function_name,
		);
		if ( ! $closing ) {
			$data = array_merge(
				$data,
				array(
					'duration' => $hook_inspection->duration(),
					'source'   => array(
						'file' => $hook_inspection->source_file,
					),
				)
			);

			// Include queries if allowed.
			if ( ! empty( $this->can_show_queries_callback ) && call_user_func( $this->can_show_queries_callback ) ) {
				$queries = $hook_inspection->queries();
				if ( ! empty( $queries ) ) {
					$data['queries'] = $queries;
				}
			}

			if ( ! empty( $hook_inspection->enqueued_scripts ) ) {
				$data['enqueued_scripts'] = $hook_inspection->enqueued_scripts;
			}
			if ( ! empty( $hook_inspection->enqueued_styles ) ) {
				$data['enqueued_styles'] = $hook_inspection->enqueued_styles;
			}

			$file_location = $hook_inspection->file_location();
			if ( $file_location ) {
				$data['source']['type'] = $file_location['type'];
				$data['source']['name'] = $file_location['name'];
			}
		}

		return $this->get_annotation_comment( $data, $closing );
	}

	/**
	 * Get annotation comment.
	 *
	 * @param array $data    Data.
	 * @param bool  $closing Whether the comment is closing.
	 * @return string HTML comment.
	 */
	public function get_annotation_comment( array $data, $closing = false ) {

		// Escape double-hyphens in comment content.
		$json = str_replace(
			'--',
			'\u2D\u2D',
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES )
		);

		return sprintf(
			'<!-- %s' . static::ANNOTATION_TAG . ' %s -->',
			$closing ? '/' : '',
			$json
		);
	}

	/**
	 * Identify the queries that were made during the hook's invocation.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 * @return int[] Query indices associated with the hook.
	 */
	public function identify_hook_queries( Hook_Inspection $hook_inspection ) {

		// Short-circuit if queries are not being saved (aka if SAVEQUERIES is not defined).
		if ( empty( $this->wpdb->queries ) ) {
			return array();
		}

		$before_num_queries = $hook_inspection->get_before_num_queries();

		// If no queries have been made during the hook invocation, short-circuit.
		if ( $this->wpdb->num_queries === $before_num_queries ) {
			return array();
		}

		$query_indices = array();
		foreach ( range( $before_num_queries, $this->wpdb->num_queries - 1 ) as $query_index ) {

			// Flag this query as being associated with this hook instance.
			if ( ! isset( $this->sourced_query_indices[ $query_index ] ) ) {
				$query_indices[] = $query_index;

				$this->sourced_query_indices[ $query_index ] = true;
			}
		}

		return $query_indices;
	}

	/**
	 * Identify the scripts that were enqueued during the hook's invocation.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 * @return string[] Script handles.
	 */
	public function identify_enqueued_scripts( Hook_Inspection $hook_inspection ) {
		$before_script_handles = $hook_inspection->get_before_scripts_queue();
		$after_script_handles  = $this->get_dependency_queue( 'wp_scripts' );

		$enqueued_handles = array();
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
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 * @return string[] Style handles.
	 */
	public function identify_enqueued_styles( Hook_Inspection $hook_inspection ) {
		$before_style_handles = $hook_inspection->get_before_styles_queue();
		$after_style_handles  = $this->get_dependency_queue( 'wp_styles' );

		$enqueued_handles = array();
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

