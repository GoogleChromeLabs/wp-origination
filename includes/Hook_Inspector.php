<?php
/**
 * Hook_Inspector Class.
 *
 * @package Sourcery
 */

namespace Sourcery;

/**
 * Class Hook_Inspector.
 */
class Hook_Inspector {

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
	public $wpdb;

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
	 * @var int[]
	 */
	protected $sourced_query_indices = array();

	/**
	 * Hook_Inspector constructor.
	 *
	 * @param \wpdb $wpdb DB.
	 */
	public function __construct( $wpdb ) {
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
		global $wpdb;
		$this->hook_stack[] = new Hook_Inspection(
			array_merge(
				$args,
				array(
					'start_time'         => microtime( true ),
					'before_num_queries' => $wpdb->num_queries,
					// @todo Queued scripts.
					// @todo Queued styles.
				)
			)
		);
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

		$hook_inspection->end_time = microtime( true );

		$this->identify_hook_queries( $hook_inspection );

		$this->processed_hooks[] = $hook_inspection;
	}

	/**
	 * Identify the queries that were made during the hook's invocation.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 */
	public function identify_hook_queries( Hook_Inspection $hook_inspection ) {

		// Short-circuit if queries are not being saved (aka if SAVEQUERIES is not defined).
		if ( empty( $this->wpdb->queries ) ) {
			return;
		}

		// If no queries have been made during the hook invocation, short-circuit.
		if ( $this->wpdb->num_queries === $hook_inspection->before_num_queries ) {
			return;
		}

		$search = 'Sourcery\Hook_Wrapper->Sourcery\{closure}, call_user_func_array, ';

		$hook_inspection->query_indices = array();
		foreach ( range( $hook_inspection->before_num_queries, $this->wpdb->num_queries - 1 ) as $query_index ) {

			// Purge references to the hook wrapper from the query call stack.
			foreach ( $this->wpdb->queries[ $query_index ] as &$query ) {
				$query = str_replace( $search, '', $query );
			}

			// Flag this query as being associated with this hook instance.
			if ( ! isset( $this->sourced_query_indices[ $query_index ] ) ) {
				$hook_inspection->query_indices[]            = $query_index;
				$this->sourced_query_indices[ $query_index ] = true;
			}
		}
	}

	/**
	 * Identify the location for a given file.
	 *
	 * @param string $file File.
	 * @return array|null {
	 *     Location information.
	 *
	 *     @var string               $type The type of location, either core, plugin, mu-plugin, or theme.
	 *     @var string               $name The name of the entity, such as 'twentyseventeen' or 'amp/amp.php'.
	 *     @var \WP_Theme|array|null $data Additional data about the entity, such as the theme object or plugin data.
	 * }
	 */
	public function identify_file_location( $file ) {
		$file         = wp_normalize_path( $file );
		$slug_pattern = '(?P<root_slug>[^/]+)';

		if ( preg_match( ':' . preg_quote( $this->core_directory, ':' ) . '(wp-admin|wp-includes)/:s', $file, $matches ) ) {
			return array(
				'type' => 'core',
				'name' => $matches[1],
				'data' => null,
			);
		}

		foreach ( $this->themes_directories as $themes_directory ) {
			if ( preg_match( ':' . preg_quote( $themes_directory, ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
				return array(
					'type' => 'theme',
					'name' => $matches['root_slug'],
					'data' => wp_get_theme( $matches['root_slug'] ),
				);
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

			return array(
				'type' => 'plugin',
				'name' => $slug,
				'data' => $data,
			);
		}

		if ( preg_match( ':' . preg_quote( $this->mu_plugins_directory, ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
			return array(
				'type' => 'mu-plugin',
				'name' => $matches['root_slug'],
				'data' => get_plugin_data( $file ), // This is a best guess as $file may not actually be the plugin file.
			);
		}

		return null;
	}
}

