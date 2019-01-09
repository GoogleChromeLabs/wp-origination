<?php
/**
 * Hook_Inspector Class.
 *
 * @package Google\WP_Sourcery
 */

namespace Google\WP_Sourcery;

/**
 * Class Hook_Inspector.
 */
class Hook_Inspector {

	const ANNOTATION_TAG = 'sourcery:hook';

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
	 * Core directory.
	 *
	 * @var string
	 */
	public $core_directory;

	/**
	 * Directory for plugins.
	 *
	 * @var string
	 */
	public $plugins_directory;

	/**
	 * Directory for mu-plugins.
	 *
	 * @var string
	 */
	public $mu_plugins_directory;

	/**
	 * Directories for themes.
	 *
	 * @var string[]
	 */
	public $themes_directories = array();

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
	 * Cached file locations.
	 *
	 * @see Hook_Inspector::identify_file_location()
	 * @var array[]
	 */
	protected $cached_file_locations = array();

	/**
	 * Hook_Inspector constructor.
	 *
	 * @param array $options Options.
	 * @global \wpdb $wpdb
	 */
	public function __construct( $options ) {
		global $wpdb;

		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}

		$this->wpdb = $wpdb;

		$this->plugins_directory    = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$this->mu_plugins_directory = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
		$this->core_directory       = trailingslashit( wp_normalize_path( ABSPATH ) );

		$theme_roots = array_unique(
			array_merge(
				get_theme_roots(),
				array( get_theme_root() ) // Because this one has a filter that applies.
			)
		);
		foreach ( $theme_roots as $theme_root ) {
			$this->themes_directories[] = trailingslashit( wp_normalize_path( $theme_root ) );
		}
	}

	/**
	 * Get the WordPress DB.
	 *
	 * @return \wpdb|object DB.
	 */
	public function get_wpdb() {
		return $this->wpdb;
	}

	/**
	 * Return the scripts registry.
	 *
	 * Note that this does not use the `wp_scripts()` function because that will cause `WP_Scripts` to potentially be
	 * instantiated prematurely.
	 *
	 * @return \WP_Scripts|null Scripts registry if defined.
	 * @global \WP_Scripts $wp_scripts
	 */
	public function get_scripts_registry() {
		global $wp_scripts;
		if ( isset( $wp_scripts ) && isset( $wp_scripts->registered ) ) {
			return $wp_scripts;
		}
		return $wp_scripts;
	}

	/**
	 * Get enqueued scripts.
	 *
	 * @return string[] Enqueued scripts.
	 */
	public function get_scripts_queue() {
		$scripts = $this->get_scripts_registry();
		if ( ! $scripts ) {
			return array();
		}
		return $scripts->queue;
	}

	/**
	 * Return the styles registry.
	 *
	 * Note that this does not use the `wp_styles()` function because that will cause `WP_Styles` to potentially be
	 * instantiated prematurely.
	 *
	 * @return \WP_Styles|null Styles registry if defined.
	 * @global \WP_Styles $wp_styles
	 */
	public function get_styles_registry() {
		global $wp_styles;
		if ( isset( $wp_styles ) && isset( $wp_styles->registered ) ) {
			return $wp_styles;
		}
		return $wp_styles;
	}

	/**
	 * Get enqueued styles.
	 *
	 * @return string[] Enqueued styles.
	 */
	public function get_styles_queue() {
		$styles = $this->get_styles_registry();
		if ( ! $styles ) {
			return array();
		}
		return $styles->queue;
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

		$is_closing = ! empty( $matches['closing'] );

		$args = array(
			'id'       => $hook_inspection->id,
			'name'     => $hook_inspection->hook_name,
			'priority' => $hook_inspection->priority,
			'callback' => $hook_inspection->function_name,
		);
		if ( ! $is_closing ) {
			$args = array_merge(
				$args,
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
					$args['queries'] = $queries;
				}
			}

			if ( ! empty( $hook_inspection->enqueued_scripts ) ) {
				$args['enqueued_scripts'] = $hook_inspection->enqueued_scripts;
			}
			if ( ! empty( $hook_inspection->enqueued_styles ) ) {
				$args['enqueued_styles'] = $hook_inspection->enqueued_styles;
			}

			$file_location = $hook_inspection->file_location();
			if ( $file_location ) {
				$args['source']['type'] = $file_location['type'];
				$args['source']['name'] = $file_location['name'];
			}
		}

		// Escape double-hyphens in comment content.
		$json = str_replace(
			'--',
			'\u2D\u2D',
			wp_json_encode( $args, JSON_UNESCAPED_SLASHES )
		);

		return sprintf(
			'<!-- %s' . static::ANNOTATION_TAG . ' %s -->',
			$is_closing ? '/' : '',
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
		$after_script_handles  = $this->get_scripts_queue();

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
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 * @return string[] Style handles.
	 */
	public function identify_enqueued_styles( Hook_Inspection $hook_inspection ) {
		$before_style_handles = $hook_inspection->get_before_styles_queue();
		$after_style_handles  = $this->get_styles_queue();

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

	/**
	 * Identify the location for a given file.
	 *
	 * @param string $file File.
	 * @return array|null {
	 *     Location information, or null if no location could be identified.
	 *
	 *     @var string               $type The type of location, either core, plugin, mu-plugin, or theme.
	 *     @var string               $name The name of the entity, such as 'twentyseventeen' or 'amp/amp.php'.
	 *     @var \WP_Theme|array|null $data Additional data about the entity, such as the theme object or plugin data.
	 * }
	 */
	public function identify_file_location( $file ) {

		if ( isset( $this->cached_file_locations[ $file ] ) ) {
			return $this->cached_file_locations[ $file ];
		}

		$file         = wp_normalize_path( $file );
		$slug_pattern = '(?P<root_slug>[^/]+)';

		if ( preg_match( ':' . preg_quote( $this->core_directory, ':' ) . '(wp-admin|wp-includes)/:s', $file, $matches ) ) {
			$this->cached_file_locations[ $file ] = array(
				'type' => 'core',
				'name' => $matches[1],
				'data' => null,
			);
			return $this->cached_file_locations[ $file ];
		}

		foreach ( $this->themes_directories as $themes_directory ) {
			if ( preg_match( ':' . preg_quote( $themes_directory, ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
				$this->cached_file_locations[ $file ] = array(
					'type' => 'theme',
					'name' => $matches['root_slug'],
					'data' => wp_get_theme( $matches['root_slug'] ),
				);
				return $this->cached_file_locations[ $file ];
			}
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( preg_match( ':' . preg_quote( $this->plugins_directory, ':' ) . $slug_pattern . '(?P<rel_path>/.+$)?:s', $file, $matches ) ) {
			$plugin_dir = $this->plugins_directory . '/' . $matches['root_slug'] . '/';
			$data       = get_plugin_data( $file );

			// Fallback slug is the path segment under the plugins directory.
			$slug = $matches['root_slug'];

			// If the file is itself a plugin file, then the slug includes the rel_path under the root_slug.
			if ( ! empty( $data['Name'] ) && ! empty( $matches['rel_path'] ) ) {
				$slug .= $matches['rel_path'];
			}

			// If the file is not a plugin file, try looking for {slug}/{slug}.php.
			if ( empty( $data['Name'] ) && file_exists( $plugin_dir . $matches['root_slug'] . '.php' ) ) {
				$slug = $matches['root_slug'] . '/' . $matches['root_slug'] . '.php';
				$data = get_plugin_data( $plugin_dir . $matches['root_slug'] . '.php' );
			}

			// Otherwise, grab the first plugin file located in the plugin directory.
			if ( empty( $data['Name'] ) ) {
				$plugins = get_plugins( '/' . $matches['root_slug'] );
				if ( ! empty( $plugins ) ) {
					$key  = key( $plugins );
					$data = $plugins[ $key ];
					$slug = $matches['root_slug'] . '/' . $key;
				}
			}

			// Failed to locate the plugin.
			if ( empty( $data['Name'] ) ) {
				$data = null;
			}

			$this->cached_file_locations[ $file ] = array(
				'type' => 'plugin',
				'name' => $slug,
				'data' => $data,
			);
			return $this->cached_file_locations[ $file ];
		}

		if ( preg_match( ':' . preg_quote( $this->mu_plugins_directory, ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
			$this->cached_file_locations[ $file ] = array(
				'type' => 'mu-plugin',
				'name' => $matches['root_slug'],
				'data' => get_plugin_data( $file ), // This is a best guess as $file may not actually be the plugin file.
			);
			return $this->cached_file_locations[ $file ];
		}

		return null;
	}
}

