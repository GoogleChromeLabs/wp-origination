<?php
/**
 * File_Locator Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class File_Locator.
 */
class File_Locator {

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
	 * Cached file locations.
	 *
	 * @see File_Locator::identify_file_location()
	 * @var array[]
	 */
	protected $cached_file_locations = array();

	/**
	 * File_Locator constructor.
	 */
	public function __construct() {
		$this->plugins_directory    = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$this->mu_plugins_directory = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
		$this->core_directory       = trailingslashit( wp_normalize_path( ABSPATH ) );

		$theme_roots = array_unique(
			array_merge(
				(array) get_theme_roots(),
				array( get_theme_root() ) // Because this one has a filter that applies.
			)
		);
		foreach ( $theme_roots as $theme_root ) {
			$this->themes_directories[] = trailingslashit( wp_normalize_path( $theme_root ) );
		}
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
	public function identify( $file ) {

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

			// Fallback slug is the path segment under the plugins directory.
			$slug = $matches['root_slug'];

			$data = null;

			// Try getting the plugin data from the file itself if it is in a plugin directory.
			if ( empty( $matches['rel_path'] ) || 0 === substr_count( trim( $matches['rel_path'], '/' ), '/' ) ) {
				$data = get_plugin_data( $file );
			}

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
