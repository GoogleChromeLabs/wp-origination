<?php
/**
 * Sourcery plugin initialization file.
 *
 * @package   Google\WP_Sourcery
 * @author    Weston Ruter
 * @copyright 2019 Google
 * @license   GNU General Public License, version 2 (or later)
 * @link      https://wordpress.org/plugins/sourcery/
 *
 * @wordpress-plugin
 * Plugin Name: Sourcery
 * Plugin URI:  https://github.com/westonruter/wp-sourcery
 * Description: Determine the source of where things come from in WordPress whether slow code, inefficient queries, or bad markup.
 * Version:     0.1.0-alpha
 * Author:      Weston Ruter
 * Author URI:  https://weston.ruter.net/
 * License:     GNU General Public License v2 (or later)
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sourcery
 */

/* This file must be parsable by PHP 5.2. */

/**
 * Loads the plugin.
 *
 * @since 0.1.0
 */
function _google_wp_sourcery_load() {
	if ( version_compare( phpversion(), '5.6', '<' ) ) {
		add_action( 'admin_notices', '_google_wp_sourcery_display_php_version_notice' );
		return;
	}

	if ( version_compare( get_bloginfo( 'version' ), '4.9', '<' ) ) {
		add_action( 'admin_notices', '_google_wp_sourcery_display_wp_version_notice' );
		return;
	}

	if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		add_action( 'admin_notices', '_google_wp_sourcery_display_composer_install_requirement' );
		return;
	}

	require_once __DIR__ . '/vendor/autoload.php';

	call_user_func( array( 'Google\\WP_Sourcery\\Plugin', 'load' ), __FILE__ );
}

/**
 * Displays an admin notice about an unmet PHP version requirement.
 *
 * @since 0.1.0
 */
function _google_wp_sourcery_display_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			sprintf(
				/* translators: 1: required version, 2: currently used version */
				__( 'Sourcery requires at least PHP version %1$s. Your site is currently running on PHP %2$s.', 'sourcery' ),
				'5.6',
				phpversion()
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Displays an admin notice about an unmet WordPress version requirement.
 *
 * @since 0.1.0
 */
function _google_wp_sourcery_display_wp_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			sprintf(
				/* translators: 1: required version, 2: currently used version */
				__( 'Sourcery requires at least WordPress version %1$s. Your site is currently running on WordPress %2$s.', 'sourcery' ),
				'4.9',
				get_bloginfo( 'version' )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Displays an admin notice about the need to run composer install.
 *
 * @since 0.1.0
 */
function _google_wp_sourcery_display_composer_install_requirement() {
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'The Sourcery plugin appears to being run from source and requires `composer install` to complete the plugin\'s installation.', 'sourcery' ); ?>
		</p>
	</div>
	<?php
}

_google_wp_sourcery_load();
