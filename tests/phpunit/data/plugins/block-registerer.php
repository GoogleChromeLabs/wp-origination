<?php
/**
 * Block Registerer.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @wordpress-plugin
 * Plugin Name: Block Registerer
 * Description: Test plugin for blocks.
 */

namespace Google\WP_Origination\Tests\Data\Plugins\Block_Registerer;

const FOREIGN_TEXT_BLOCK_NAME = 'block-registerer/foreign-text';

const TEXT_TRANSFORM_BLOCK_NAME = 'block-registerer/text-transform';

const TEXT_TRANSFORM_STYLE_HANDLE = 'text-transformed';

const CURRENT_TIME_BLOCK_NAME = 'block-registerer/current-time';

/**
 * Register blocks.
 */
function register_blocks() {
	\WP_Block_Type_Registry::get_instance()->register(
		FOREIGN_TEXT_BLOCK_NAME,
		[
			'attributes' => [
				'lang' => [
					'type'      => 'string',
					'source'    => 'attribute',
					'selector'  => 'span',
					'attribute' => 'lang',
				],
				'dir'  => [
					'type'      => 'string',
					'enum'      => [
						'ltr',
						'rtl',
					],
					'source'    => 'attribute',
					'selector'  => 'span',
					'attribute' => 'dir',
				],
			],
		]
	);

	\WP_Block_Type_Registry::get_instance()->register(
		TEXT_TRANSFORM_BLOCK_NAME,
		[
			'attributes'      => [
				'transform'  => [
					'type' => 'string',
					'enum' => [
						'strtoupper',
						'strtolower',
						'ucfirst',
					],
				],
				'stylesheet' => [
					'type' => 'string',
				],
			],
			'render_callback' => __NAMESPACE__ . '\render_text_transform_block',
		]
	);

	\WP_Block_Type_Registry::get_instance()->register(
		CURRENT_TIME_BLOCK_NAME,
		[
			'attributes'      => [
				'format' => [
					'type'    => 'string',
					'default' => get_option( 'time_format' ),
				],
			],
			'render_callback' => __NAMESPACE__ . '\render_current_time_block',
		]
	);
}

/**
 * Get sample serialized blocks.
 *
 * @return string[] Sample serialized blocks.
 */
function get_sample_serialized_blocks() {

	$current_time_block   = sprintf(
		'<!-- wp:%1$s %2$s /-->',
		CURRENT_TIME_BLOCK_NAME,
		wp_json_encode( [ 'format' => 'c' ] )
	);
	$nested_columns_block = "<!-- wp:columns -->\n<div class=\"wp-block-columns has-2-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:paragraph -->\n<p>Column 1</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:columns -->\n<div class=\"wp-block-columns has-2-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:paragraph -->\n<p>Column 2a</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column -->\n<div class=\"wp-block-column\">$current_time_block</div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->";

	return [
		sprintf(
			'<!-- wp:%1$s %2$s --><span data-block-name="%1$s" dir="ltr" lang="es">¡Hola, [todo el] mundo!</span><!-- /wp:%1$s -->',
			FOREIGN_TEXT_BLOCK_NAME,
			wp_json_encode( [ 'voice' => 'Juan' ] )
		),
		sprintf(
			'<!-- wp:%1$s %2$s --><span data-block-name="%1$s">I used to be lower-case.</span><!-- /wp:%1$s -->',
			TEXT_TRANSFORM_BLOCK_NAME,
			wp_json_encode( [ 'transform' => 'strtoupper' ] )
		),
		$nested_columns_block,
		sprintf(
			'<!-- wp:%1$s --><p data-block-name="%1$s">This is a paragraph.</p><!-- /wp:%1$s -->',
			'paragraph'
		),
	];
}

/**
 * Unregister blocks.
 */
function unregister_blocks() {
	\WP_Block_Type_Registry::get_instance()->unregister( FOREIGN_TEXT_BLOCK_NAME );
	\WP_Block_Type_Registry::get_instance()->unregister( TEXT_TRANSFORM_BLOCK_NAME );
	\WP_Block_Type_Registry::get_instance()->unregister( CURRENT_TIME_BLOCK_NAME );
}

/**
 * Render the text-transform block.
 *
 * Note that the transform function is whitelisted via enum in the block's attribute schema.
 *
 * @param array  $attributes Attributes.
 * @param string $content    Content.
 * @return string Content.
 */
function render_text_transform_block( $attributes, $content ) {
	if ( ! empty( $attributes['transform'] ) ) {
		$content = preg_replace_callback(
			'/(<\w[^>]*)>([^<>]+?)(?=<)/',
			function ( $matches ) use ( $attributes ) {
				return $matches[1] . ' data-transform="' . esc_attr( $attributes['transform'] ) . '">' . call_user_func( $attributes['transform'], $matches[2] );
			},
			$content
		);

		wp_enqueue_style( TEXT_TRANSFORM_STYLE_HANDLE, 'https://example.com/text-transformed.css', [], '0.1' );
	}
	return $content;
}

/**
 * Render the current-time blocks.
 *
 * @param array $attributes Attributes.
 * @return string Content.
 */
function render_current_time_block( $attributes ) {
	return sprintf(
		'<span data-block-name="%s">%s</span>',
		esc_attr( CURRENT_TIME_BLOCK_NAME ),
		esc_html( gmdate( $attributes['format'] ) )
	);
}
