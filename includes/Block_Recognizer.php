<?php
/**
 * Block_Recognizer Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Block_Recognizer.
 */
class Block_Recognizer {

	/**
	 * Cached mapping of namespace to source.
	 *
	 * @var array[]
	 */
	protected $cached_namespace_sources = [];

	/**
	 * Plugin folder.
	 *
	 * This is used primarily for testing purposes, as get_plugins_dir() allows a relative directory to be passed.
	 *
	 * @var array
	 */
	public $plugin_folder = '';

	/**
	 * Identify the source for a given block namespace.
	 *
	 * @todo Also add indication that guessed from namespace?
	 *
	 * @param string $namespace Block namespace.
	 * @return null|array {
	 *     Source.
	 *
	 *     @var string $type Type (either 'plugin', 'theme', or 'core').
	 *     @var string $name Name.
	 * }
	 */
	public function identify( $namespace ) {
		if ( 'core' === $namespace || 'core-embed' === $namespace ) {
			return [
				'type' => 'core',
			];
		}

		if ( isset( $this->cached_namespace_sources[ $namespace ] ) ) {
			return $this->cached_namespace_sources[ $namespace ];
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Look among plugins.
		foreach ( array_keys( get_plugins( $this->plugin_folder ) ) as $plugin_name ) {
			$plugin_slug = str_replace( '.php', '', strtok( $plugin_name, '/' ) );
			if ( $plugin_slug === $namespace ) {
				$this->cached_namespace_sources[ $namespace ] = [
					'type' => 'plugin',
					'name' => $plugin_name,
				];
				return $this->cached_namespace_sources[ $namespace ];
			}
		}

		// Look among themes.
		foreach ( wp_get_themes() as $theme ) {
			if ( $theme->get_stylesheet() === $namespace ) {
				$this->cached_namespace_sources[ $namespace ] = [
					'type' => 'theme',
					'name' => $theme->get_stylesheet(),
				];
				return $this->cached_namespace_sources[ $namespace ];
			}
		}

		// Look among mu-plugins.
		foreach ( array_keys( get_mu_plugins() ) as $mu_plugin_name ) {
			$mu_plugin_slug = str_replace( '.php', '', $mu_plugin_name );
			if ( $mu_plugin_slug === $namespace ) {
				$this->cached_namespace_sources[ $namespace ] = [
					'type' => 'mu-plugin',
					'name' => $mu_plugin_slug,
				];
				return $this->cached_namespace_sources[ $namespace ];
			}
		}

		return null;
	}
}
