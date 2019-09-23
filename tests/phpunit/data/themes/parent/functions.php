<?php
/**
 * Parent theme functions.
 *
 * @package Google\WP_Origination
 */

namespace Google\WP_Origination\Tests\Data\Themes\Parent;

if ( ! function_exists( __NAMESPACE__ . '\setup' ) ) {
	/**
	 * Sets up theme defaults and registers support for various WordPress features.
	 *
	 * Note that this function is hooked into the after_setup_theme hook, which
	 * runs before the init hook. The init hook is too late for some features, such
	 * as indicating support for post thumbnails.
	 */
	function setup() {

		// Add default posts and comments RSS feed links to head.
		add_theme_support( 'automatic-feed-links' );

		/*
		 * Let WordPress manage the document title.
		 * By adding theme support, we declare that this theme does not use a
		 * hard-coded <title> tag in the document head, and expect WordPress to
		 * provide it for us.
		 */
		add_theme_support( 'title-tag' );

		/*
		 * Enable support for Post Thumbnails on posts and pages.
		 *
		 * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		 */
		add_theme_support( 'post-thumbnails' );

		// This theme uses wp_nav_menu() in one location.
		register_nav_menus(
			[
				'menu-1' => esc_html__( 'Primary', 'parent' ),
			]
		);

		/*
		 * Switch default core markup for search form, comment form, and comments
		 * to output valid HTML5.
		 */
		add_theme_support(
			'html5',
			[
				'search-form',
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
			]
		);

		// Set up the WordPress core custom background feature.
		add_theme_support(
			'custom-background',
			[
				'default-color' => 'ffffff',
				'default-image' => '',
			]
		);

		// Add theme support for selective refresh for widgets.
		add_theme_support( 'customize-selective-refresh-widgets' );

		/**
		 * Add support for core custom logo.
		 *
		 * @link https://codex.wordpress.org/Theme_Logo
		 */
		add_theme_support(
			'custom-logo',
			[
				'height'      => 250,
				'width'       => 250,
				'flex-width'  => true,
				'flex-height' => true,
			]
		);

		register_sidebar(
			[
				'name' => 'Sidebar',
				'id'   => 'sidebar-1',
			]
		);
	}
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\setup' );

if ( ! function_exists( __NAMESPACE__ . '\enqueue_scripts' ) ) {
	/**
	 * Enqueue scripts.
	 */
	function enqueue_scripts() {
		wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css', [], '1.0' );

		wp_enqueue_script( 'parent-navigation', get_template_directory_uri() . '/js/navigation.js', [], '20151215', true );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );

if ( ! function_exists( __NAMESPACE__ . '\add_parent_theme_credits' ) ) {

	/**
	 * Add credits to footer.
	 */
	function add_parent_theme_credits() {
		?>
		<p id="parent-theme-credits">Proudly powered by parent theme.</p>
		<?php
	}
}
add_action( 'wp_footer', __NAMESPACE__ . '\add_parent_theme_credits' );
