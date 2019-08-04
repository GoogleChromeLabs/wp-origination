<?php
/**
 * Child theme functions.
 *
 * @package   Google\WP_Sourcery
 */

namespace Google\WP_Sourcery\Tests\Data\Themes\Child;

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
