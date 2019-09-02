<?php
/**
 * Hook Invoker.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @wordpress-plugin
 * Plugin Name: Hook Invoker
 * Description: Test plugin for hooks.
 */

namespace Google\WP_Origination\Tests\Data\Plugins\Hook_Invoker;

const SIDEBAR_ID = 'sidebar-1';

/**
 * Add hooks.
 */
function add_hooks() {
	add_filter( 'language_attributes', __NAMESPACE__ . '\filter_language_attributes' );
	add_filter( 'hook_invoker_container_attributes', __NAMESPACE__ . '\add_container_id_attribute' );
	add_filter( 'the_title', __NAMESPACE__ . '\convert_backticks_to_code', 100 );
	add_filter( 'the_content', __NAMESPACE__ . '\convert_backticks_to_code', 100 );
	add_action( 'hook_invoker_container_print_extra_attributes', __NAMESPACE__ . '\print_container_attributes' );
	add_action( 'hook_invoker_body', __NAMESPACE__ . '\print_body' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
	add_action( 'wp_print_footer_scripts', __NAMESPACE__ . '\print_document_write' );
	add_filter( 'the_content', __NAMESPACE__ . '\filter_paragraph_contents', 100 );
	add_filter( 'paragraph_contents', __NAMESPACE__ . '\append_paragraph_word_count', 12 );
	add_filter( 'paragraph_contents', __NAMESPACE__ . '\prepend_paragraph_anchor', 13 );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_comment_reply_async' );
	add_action(
		'wp_body_open',
		function() {
			do_action( 'hook_invoker_body' );
		}
	);
}

/**
 * Filter language attributes.
 *
 * @param string $attributes Attributes.
 * @return string Attributes.
 */
function filter_language_attributes( $attributes ) {
	$attributes .= ' data-lang="test"';
	return $attributes;
}

/**
 * Convert backticks to <code> elements.
 *
 * @param string $value Value.
 * @return string Value.
 */
function convert_backticks_to_code( $value ) {
	return preg_replace( '/`(.+?)`/', '<code>$1</code>', $value );
}

/**
 * Trigger hook_invoker_enqueue_scripts action.
 */
function enqueue_scripts() {
	do_action( 'hook_invoker_enqueue_scripts' );
}

/**
 * Add container ID attribute.
 *
 * @param array $attributes Attributes.
 * @return array Attributes.
 */
function add_container_id_attribute( $attributes ) {
	$attributes['id'] = 'container';
	return $attributes;
}

/**
 * Print container attributes.
 */
function print_container_attributes() {
	echo ' data-extra-printed=1';
}

/**
 * Print document.write().
 *
 * Or write document.print()? 😉
 */
function print_document_write() {
	echo '<script id="document-write-script">document.write("This is a bad function call.");</script>';
}

/**
 * Print body.
 */
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

/**
 * Filter paragraph contents.
 *
 * Only applies on paragraphs that are at least 150 characters long.
 *
 * @param string $content Content.
 * @return string Content.
 */
function filter_paragraph_contents( $content ) {
	return preg_replace_callback(
		':(?<=<p>)(.+?)(?=</p>):s',
		function( $matches ) {
			if ( strlen( $matches[0] ) < 150 ) {
				return $matches[0];
			}

			/**
			 * Filters paragraph contents.
			 *
			 * @param string $paragraph_contents Paragraph contents.
			 */
			return apply_filters( 'paragraph_contents', $matches[1] );
		},
		$content
	);
}

/**
 * Append paragraph word count.
 *
 * @param string $content Content with appended word count.
 * @return string Content.
 */
function append_paragraph_word_count( $content ) {
	$word_count = preg_match_all( '/\w+/', wp_strip_all_tags( $content ), $matches );
	return $content . sprintf( ' (Word Count: %d)', esc_html( $word_count ) );
}

/**
 * Prepend paragraph anchor.
 *
 * @param string $content Content with prepended anchor.
 * @return string Content.
 */
function prepend_paragraph_anchor( $content ) {
	return sprintf( '<a name="%s"></a>', esc_attr( md5( $content ) ) ) . $content;
}

/**
 * Enqueue comment-reply with async.
 */
function enqueue_comment_reply_async() {
	wp_enqueue_script( 'comment-reply' );
	add_filter(
		'script_loader_tag',
		function ( $tag, $handle ) {
			if ( 'comment-reply' === $handle ) {
				$tag = preg_replace( '/(?<=<script\s)/', ' async ', $tag );
			}
			return $tag;
		},
		10,
		2
	);
}
