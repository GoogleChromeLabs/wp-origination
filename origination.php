<?php
/**
 * Origination plugin initialization file.
 *
 * @package   Google\WP_Origination
 * @author    Weston Ruter
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 *
 * @wordpress-plugin
 * Plugin Name: Origination
 * Plugin URI:  https://github.com/GoogleChromeLabs/wp-origination
 * Description: Determine the origin of where things come from in WordPress whether slow code, inefficient queries, or bad markup.
 * Version:     0.1.0-alpha
 * Author:      Weston Ruter
 * Author URI:  https://weston.ruter.net/
 * License:     Apache License 2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: origination
 */

/* This file must be parsable by PHP 5.2. */

/**
 * Errors encountered while loading the plugin.
 *
 * This has to be a global for the same of PHP 5.2.
 *
 * @var WP_Error $_google_wp_origination_load_errors
 */
global $_google_wp_origination_load_errors;

define( 'WP_ORIGINATION_PLUGIN_FILE', __FILE__ );

/**
 * Load the plugin, making sure the required dependencies are met.
 *
 * @since 0.1.0
 */
function _google_wp_origination_load() {
	global $_google_wp_origination_load_errors;
	$load_errors = new WP_Error();

	if ( version_compare( phpversion(), '7.0', '<' ) ) {
		$load_errors->add(
			'php_version',
			sprintf(
				/* translators: 1: required PHP version, 2: currently used PHP version */
				__( 'Plugin requires at least PHP version %1$s; your site is currently running on PHP %2$s.', 'origination' ),
				'7.0',
				phpversion()
			)
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), '4.9', '<' ) ) {
		$load_errors->add(
			'wp_version',
			sprintf(
				/* translators: 1: required WordPress version, 2: currently used WordPress version */
				__( 'Plugin requires at least WordPress version %1$s; your site is currently running on WordPress %2$s.', 'origination' ),
				'4.9',
				get_bloginfo( 'version' )
			)
		);
	}

	// DIST_REMOVED.
	if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		$load_errors->add(
			'composer_install',
			sprintf(
				sprintf(
					/* translators: %s is composer install command */
					__( 'Plugin appears to be running from source and requires %s to complete the plugin\'s installation.', 'origination' ),
					'<code>composer install</code>'
				),
				'4.9',
				get_bloginfo( 'version' )
			)
		);
	}

	if ( ! empty( $load_errors->errors ) ) {
		$_google_wp_origination_load_errors = $load_errors;
		add_action( 'admin_notices', '_google_wp_show_dependency_errors_admin_notice' );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$messages = array( __( 'Origination plugin unable to load.', 'origination' ) );
			foreach ( array_keys( $load_errors->errors ) as $error_code ) {
				$messages[] = $load_errors->get_error_message( $error_code );
			}
			$message = implode( ' ', $messages );
			$message = str_replace( array( '<code>', '</code>' ), '`', $message );
			WP_CLI::warning( $message );
		}
	} else {
		require_once __DIR__ . '/vendor/autoload.php';
		call_user_func( array( 'Google\\WP_Origination\\Plugin', 'load' ), WP_ORIGINATION_PLUGIN_FILE );
	}
}

/**
 * Displays an admin notice about why the plugin is unable to load.
 *
 * @since 0.1.0
 * @global WP_Error $_google_wp_origination_load_errors
 */
function _google_wp_show_dependency_errors_admin_notice() {
	global $_google_wp_origination_load_errors;
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Origination plugin unable to load.', 'origination' ); ?></strong>
			<?php foreach ( array_keys( $_google_wp_origination_load_errors->errors ) as $error_code ) : ?>
				<?php echo wp_kses_post( $_google_wp_origination_load_errors->get_error_message( $error_code ) ); ?>
			<?php endforeach; ?>
		</p>
	</div>
	<?php
}

_google_wp_origination_load();
