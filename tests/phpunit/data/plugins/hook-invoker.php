<?php
/**
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @wordpress-plugin
 * Plugin Name: Hook Invoker
 * Description: Test plugin for hooks.
 */

namespace Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker;

function add_hooks() {
	add_filter( 'language_attributes', __NAMESPACE__ . '\filter_language_attributes' );
	add_filter( 'hook_invoker_container_attributes', __NAMESPACE__ . '\add_container_id_attribute' );
	add_action( 'hook_invoker_container_print_extra_attributes', __NAMESPACE__ . '\print_container_attributes' );
	add_action( 'hook_invoker_body', __NAMESPACE__ . '\print_body' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
	add_action( 'wp_print_footer_scripts', __NAMESPACE__ . '\print_document_write' );

	// @todo Add shortcode.
	// @todo Add widget.
	// @todo Add block.
}

function filter_language_attributes( $attributes ) {
	$attributes .= ' data-lang="test"';
	return $attributes;
}

function enqueue_scripts() {
	do_action( 'hook_invoker_enqueue_scripts' );
}

function add_container_id_attribute( $attributes ) {
	$attributes['id'] = 'container';
	return $attributes;
}

function print_container_attributes() {
	echo ' data-extra-printed=1';
}

function print_document_write() {
	echo '<script id="document-write-script">document.write("This is a bad function call.");</script>';
}

function print_template( $query_args ) {
	$query = new \WP_Query( $query_args );

	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?> class="no-js no-svg">
		<head>
			<meta charset="utf-8">
			<?php wp_head(); ?>
		</head>
		<body <?php body_class(); ?>>
			<?php do_action( 'hook_invoker_body' ); ?>

			<?php while ( $query->have_posts() ) : ?>
				<?php $query->the_post(); ?>
				<article id="post-<?php the_ID(); ?>">
					<h1 class="entry-title"><?php the_title(); ?></h1>
					<div class="entry-excerpt">
						<?php the_excerpt(); ?>
					</div>
					<div class="entry-content">
						<?php the_content(); ?>
					</div>
				</article>
			<?php endwhile; ?>
			<?php wp_reset_postdata(); ?>

			<?php wp_footer(); ?>
		</body>
	</html>
	<?php
}

function print_body() {
	echo '<main ';
	$attributes = apply_filters( 'hook_invoker_container_attributes', [] );

	foreach ( $attributes as $name => $value ) {
		printf( ' %s="%s"', esc_attr( $name ), esc_attr( $name ) );
	}

	do_action( 'hook_invoker_container_print_extra_attributes' );
	echo '>';
	echo '<!--inner_main_start-->';

	echo '</main>';
}
