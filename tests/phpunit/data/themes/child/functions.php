<?php
/**
 * Child theme functions.
 *
 * @package   Google\WP_Origination
 */

namespace Google\WP_Origination\Tests\Data\Themes\Child;

if ( ! function_exists( __NAMESPACE__ . '\enqueue_scripts' ) ) {
	/**
	 * Enqueue scripts.
	 */
	function enqueue_scripts() {
		wp_enqueue_style( 'child-style', get_stylesheet_uri(), array( 'parent-style' ), '1.0' );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );

if ( ! function_exists( __NAMESPACE__ . '\add_child_theme_credits' ) ) {
	/**
	 * Add credits to footer.
	 */
	function add_child_theme_credits() {
		?>
		<p id="child-theme-credits">Proudly extended by child theme.</p>
		<?php
	}
}
add_action( 'wp_footer', __NAMESPACE__ . '\add_child_theme_credits' );
