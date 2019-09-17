<?php
/**
 * Class Google\WP_Origination\Tests\PHPUnit\Unit\Annotation_Tests
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination\Tests\PHPUnit\Unit;

use Google\WP_Origination\Plugin;
use Google\WP_Origination\Tests\PHPUnit\Framework\Integration_Test_Case;
use Google\WP_Origination\Tests\Data\Plugins\Block_Registerer;
use Google\WP_Origination\Tests\Data\Plugins\Hook_Invoker;
use Google\WP_Origination\Tests\Data\Plugins\Shortcode_Adder;
use Google\WP_Origination\Tests\Data\Plugins\Dependency_Enqueuer;
use Google\WP_Origination\Tests\Data\Plugins\Widget_Registerer;
use Google\WP_Origination\Output_Annotator;

/**
 * Testing annotations.
 */
class Annotation_Tests extends Integration_Test_Case {

	/**
	 * Output.
	 *
	 * @var string
	 */
	protected static $output;

	/**
	 * All annotations found in the document, keyed by index.
	 *
	 * @var array[]
	 */
	protected static $annotations = [];

	/**
	 * Post IDs used for testing.
	 *
	 * @var array
	 */
	protected static $post_ids = [];

	/**
	 * Document.
	 *
	 * @var \DOMDocument
	 */
	protected static $document;

	/**
	 * XPath
	 *
	 * @var \DOMXPath
	 */
	protected static $xpath;

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	protected static $plugin;

	/**
	 * Set up before class.
	 */
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		// Switch to the test child theme.
		register_theme_directory( dirname( __DIR__ ) . '/data/themes' );
		search_theme_directories( true ); // Regenerate the transient.
		switch_theme( 'child' );

		self::$plugin = new Plugin( WP_ORIGINATION_PLUGIN_FILE );
		self::$plugin->init();

		self::$plugin->invocation_watcher->annotatable_filters[] = 'paragraph_contents';

		array_unshift(
			self::$plugin->file_locator->plugins_directories,
			dirname( __DIR__ ) . '/data/plugins/'
		);
		self::$plugin->block_recognizer->plugin_folder = substr(
			dirname( __DIR__ ) . '/data/plugins/',
			strlen( dirname( dirname( WP_ORIGINATION_PLUGIN_FILE ) ) )
		);

		require_once __DIR__ . '/../data/plugins/hook-invoker.php';
		require_once __DIR__ . '/../data/plugins/dependency-enqueuer.php';
		require_once __DIR__ . '/../data/plugins/shortcode-adder.php';
		require_once __DIR__ . '/../data/plugins/block-registerer.php';
		require_once __DIR__ . '/../data/plugins/widget-registerer.php';

		self::$post_ids['test_filters'] = self::factory()->post->create(
			[
				'post_title'   => 'Test Filters: Wordpress `code` is...beautiful', // Three filters will apply.
				'post_excerpt' => 'Test... "texturize".',
				'post_content' => 'Test Wordpress and this is more text that will lead to being greater than 100 characters. This is surely greater than 150 chars, correct? Yes, I think that it is now greater than.', // Test capital_P_dangit.
			]
		);

		self::$post_ids['test_shortcode'] = self::factory()->post->create(
			[
				'post_title'   => 'Test Shortcodes',
				'post_content' => 'Please [passthru styles=common scripts=wp-api-fetch,colorpicker function=strtoupper]upper[/passthru] the volume. I cannot year you.',
			]
		);

		self::$post_ids['test_blocks'] = self::factory()->post->create(
			[
				'post_title'   => 'Test Blocks',
				'post_content' => implode(
					"\n\n",
					Block_Registerer\get_sample_serialized_blocks()
				),
			]
		);

		// Mock the oEmbed responses.
		add_filter(
			'pre_http_request',
			function ( $pre, $r, $url ) {
				unset( $r );
				if ( false !== strpos( $url, 'youtube' ) ) {
					return [
						'body'     => "{\"thumbnail_width\":480,\"title\":\"AMP in WordPress, the WordPress Way (AMP Conf '19)\",\"author_url\":\"https:\\/\\/www.youtube.com\\/channel\\/UCXPBsjgKKG2HqsKBhWA4uQw\",\"provider_name\":\"YouTube\",\"width\":500,\"author_name\":\"The AMP Channel\",\"height\":281,\"version\":\"1.0\",\"html\":\"\\u003ciframe width=\\\"500\\\" height=\\\"281\\\" src=\\\"https:\\/\\/www.youtube.com\\/embed\\/4mavA1xow1M?feature=oembed\\\" frameborder=\\\"0\\\" allow=\\\"accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture\\\" allowfullscreen\\u003e\\u003c\\/iframe\\u003e\",\"type\":\"video\",\"thumbnail_height\":360,\"thumbnail_url\":\"https:\\/\\/i.ytimg.com\\/vi\\/4mavA1xow1M\\/hqdefault.jpg\",\"provider_url\":\"https:\\/\\/www.youtube.com\\/\"}",
						'response' => [
							'code'    => 200,
							'message' => 'OK',
						],
					];
				} elseif ( false !== strpos( $url, 'twitter' ) ) {
					return array(
						'body'     => '{"url":"https:\\/\\/twitter.com\\/iAlbMedina\\/status\\/1123397949361274880","author_name":"Alberto Medina","author_url":"https:\\/\\/twitter.com\\/iAlbMedina","html":"\\u003Cblockquote class=\\"twitter-tweet\\" data-width=\\"500\\" data-dnt=\\"true\\"\\u003E\\u003Cp lang=\\"en\\" dir=\\"ltr\\"\\u003EAMP in WordPress, the WordPress Way! This post is a summary of our recent \\u003Ca href=\\"https:\\/\\/twitter.com\\/hashtag\\/AMPConf?src=hash&amp;ref_src=twsrc%5Etfw\\"\\u003E#AMPConf\\u003C\\/a\\u003E talk. Check it out! \\u003Ca href=\\"https:\\/\\/t.co\\/1oXpeOFLtt\\"\\u003Ehttps:\\/\\/t.co\\/1oXpeOFLtt\\u003C\\/a\\u003E\\u003C\\/p\\u003E&mdash; Alberto Medina (@iAlbMedina) \\u003Ca href=\\"https:\\/\\/twitter.com\\/iAlbMedina\\/status\\/1123397949361274880?ref_src=twsrc%5Etfw\\"\\u003EMay 1, 2019\\u003C\\/a\\u003E\\u003C\\/blockquote\\u003E\\n\\u003Cscript async src=\\"https:\\/\\/platform.twitter.com\\/widgets.js\\" charset=\\"utf-8\\"\\u003E\\u003C\\/script\\u003E\\n","width":500,"height":null,"type":"rich","cache_age":"3153600000","provider_name":"Twitter","provider_url":"https:\\/\\/twitter.com","version":"1.0"}',
						'response' => [
							'code'    => 200,
							'message' => 'OK',
						],
					);
				}
				return $pre;
			},
			10,
			3
		);
		self::$post_ids['test_oembeds'] = self::factory()->post->create(
			[
				'post_title'   => 'Test oEmbeds',
				'post_content' => implode(
					"\n",
					[
						'<!-- wp:core-embed/youtube {"url":"https://www.youtube.com/embed/4mavA1xow1M","type":"rich","providerNameSlug":"embed-handler","className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->',
						'<figure class="wp-block-embed-youtube wp-block-embed is-type-rich is-provider-embed-handler wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">',
						'https://www.youtube.com/embed/4mavA1xow1M',
						'</div></figure>',
						'<!-- /wp:core-embed/youtube -->',
						'',
						'<!-- wp:core-embed/twitter {"url":"https://twitter.com/iAlbMedina/status/1123397949361274880","type":"rich","providerNameSlug":"twitter","className":""} -->',
						'<figure class="wp-block-embed-twitter wp-block-embed is-type-rich is-provider-twitter"><div class="wp-block-embed__wrapper">',
						'https://twitter.com/iAlbMedina/status/1123397949361274880',
						'</div></figure>',
						'<!-- /wp:core-embed/twitter -->',
						'',
						'<!-- wp:embed {"url":"https://example.com/podcast.mp3","type":"rich","providerNameSlug":"embed-handler","className":""} -->',
						'<figure class="wp-block-embed is-type-rich is-provider-embed-handler"><div class="wp-block-embed__wrapper">',
						'https://example.com/podcast.mp3',
						'</div></figure>',
						'<!-- /wp:embed -->',
					]
				),
			]
		);

		// Start workaround output buffering to deal with inability of ob_start() to manipulate buffer when calling ob_get_clean(). See <>https://stackoverflow.com/a/12392694>.
		ob_start();

		self::$plugin->invocation_watcher->start();
		self::$plugin->output_annotator->start( false );

		require get_stylesheet_directory() . '/functions.php';
		require get_template_directory() . '/functions.php';
		unset( $GLOBALS['wp_actions']['wp_loaded'] );
		do_action( 'after_setup_theme' );

		wp();

		Hook_Invoker\add_hooks();
		Shortcode_Adder\add_shortcode();
		Dependency_Enqueuer\add_hooks();
		Block_Registerer\register_blocks();
		Widget_Registerer\register_populated_widgets_sidebar(
			Hook_Invoker\SIDEBAR_ID
		);

		self::$plugin->invocation_watcher->wrap_shortcode_callbacks();
		self::$plugin->invocation_watcher->wrap_block_render_callbacks();
		self::$plugin->invocation_watcher->wrap_widget_callbacks();

		// We can't use locate_template() to load since it uses TEMPLATEPATH.
		require get_template_directory() . '/index.php';

		ob_end_flush(); // End workaround buffer.
		self::$output = ob_get_clean();

		self::$document        = new \DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		self::$document->loadHTML( self::$output );
		libxml_use_internal_errors( $libxml_previous_state );
		self::$xpath = new \DOMXPath( self::$document );

		$start_comments = self::$xpath->query( sprintf( '//comment()[ starts-with( ., " %s " ) ]', Output_Annotator::ANNOTATION_TAG ) );
		foreach ( $start_comments as $start_comment ) {
			$parsed_comment = self::$plugin->output_annotator->parse_annotation_comment( $start_comment );
			if ( isset( $parsed_comment['data']['index'] ) ) {
				self::$annotations[ $parsed_comment['data']['index'] ] = $parsed_comment['data'];
			}
		}
	}

	/**
	 * Ensure that the number of comments is as expected.
	 */
	public function test_expected_well_formed_annotations() {
		$predicates = [
			sprintf( 'starts-with( ., " %s " )', output_Annotator::ANNOTATION_TAG ),
			sprintf( 'starts-with( ., " /%s " )', output_Annotator::ANNOTATION_TAG ),
		];
		$expression = sprintf( '//comment()[ %s ]', implode( ' or ', $predicates ) );
		$comments   = self::$xpath->query( $expression );

		$this->assertGreaterThan( 0, $comments->length );
		$stack = [];
		foreach ( $comments as $comment ) {
			$parsed_comment = self::$plugin->output_annotator->parse_annotation_comment( $comment );
			$this->assertInternalType( 'array', $parsed_comment );
			$this->assertArrayHasKey( 'data', $parsed_comment );
			$this->assertArrayHasKey( 'closing', $parsed_comment );
			$this->assertArrayHasKey( 'index', $parsed_comment['data'] );
			if ( ! $parsed_comment['closing'] ) {
				$this->assertArrayHasKey( 'type', $parsed_comment['data'], 'Data array: ' . wp_json_encode( $parsed_comment['data'] ) );
			}

			if ( $parsed_comment['closing'] ) {
				$open_parsed_comment = array_pop( $stack );
				$this->assertEquals( $open_parsed_comment['data']['index'], $parsed_comment['data']['index'] );
			} elseif ( ! $parsed_comment['self_closing'] ) {
				array_push( $stack, $parsed_comment );
			}
		}
		$this->assertCount( 0, $stack );
	}

	/**
	 * Ensure that filter annotations do not get written inside of start tags.
	 *
	 * @covers \Google\WP_Origination\Output_Annotator::finish()
	 */
	public function test_annotation_omission_inside_start_tag() {
		$this->assertEquals( 1, preg_match( '#<html.+>#', self::$output, $matches ) );
		$start_tag = $matches[0];
		$this->assertContains( ' class="no-js no-svg"', self::$output );
		$this->assertContains( ' data-lang="test"', self::$output );
		$this->assertContains( ' lang="', self::$output );
		$this->assertNotContains( '<!--', $start_tag );

		$this->assertEquals( 1, preg_match( '#(<main.+>)<!--inner_main_start-->#', self::$output, $matches ) );
		$start_tag = $matches[1];
		$this->assertNotContains( '<!--', $start_tag );
	}

	/**
	 * Test that script output during wp_print_footer_scripts has expected annotation comments.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_expected_print_footer_scripts_annotations() {
		$script = self::$document->getElementById( 'document-write-script' );
		$this->assertInstanceOf( 'DOMElement', $script );

		$stack = self::$plugin->output_annotator->get_node_annotation_stack( $script );

		$this->assertCount( 2, $stack );

		$expected = array(
			array(
				'type'     => 'action',
				'name'     => 'wp_footer',
				'function' => 'wp_print_footer_scripts',
				'priority' => 20,
				'parent'   => null,
			),
			array(
				'type'     => 'action',
				'name'     => 'wp_print_footer_scripts',
				'function' => 'Google\WP_Origination\Tests\Data\Plugins\Hook_Invoker\print_document_write',
				'children' => [],
				'priority' => 10,
				'parent'   => $stack[0]['index'],
			),
		);

		foreach ( $stack as $i => $annotation_data ) {
			$this->assertArraySubset( $expected[ $i ], $annotation_data );
			$this->assertInternalType( 'float', $annotation_data['own_time'] );
			$this->assertGreaterThanOrEqual( 0.0, $annotation_data['own_time'] );
		}
		$this->assertContains( $stack[1]['index'], $stack[0]['children'] );

		// Verify sources.
		$this->assertStringEndsWith( 'wp-includes/script-loader.php', $stack[0]['source']['file'] );
		$this->assertEquals( 'core', $stack[0]['source']['type'] );
		$this->assertEquals( 'wp-includes', $stack[0]['source']['name'] );
		$this->assertStringEndsWith( 'plugins/hook-invoker.php', $stack[1]['source']['file'] );
		$this->assertEquals( 'plugin', $stack[1]['source']['type'] );
		$this->assertEquals( 'hook-invoker.php', $stack[1]['source']['name'] );
	}

	/**
	 * Test that an enqueued script has the expected annotation stack.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_enqueued_script_has_annotation_stack() {
		$script = self::$xpath->query( '//script[ contains( @src, "jquery.js" ) ]' )->item( 0 );
		$this->assertInstanceOf( 'DOMElement', $script );
		$stack = self::$plugin->output_annotator->get_node_annotation_stack( $script );
		$this->assertCount( 2, $stack );

		$this->assertEquals( 'action', $stack[0]['type'] );
		$this->assertEquals( 'wp_head', $stack[0]['name'] );
		$this->assertEquals( 'wp_print_head_scripts', $stack[0]['function'] );
		$this->assertStringEndsWith( 'wp-includes/script-loader.php', $stack[0]['source']['file'] );

		$this->assertEquals( 'enqueued_script', $stack[1]['type'] );
		$this->assertCount( 3, $stack[1]['invocations'] );

		$expected = array(
			[
				'function'         => 'Google\WP_Origination\Tests\Data\Plugins\Dependency_Enqueuer\enqueue_scripts_for_hook_invoker',
				'enqueued_scripts' => [ 'wp-a11y' ],
				'enqueued_styles'  => [ 'code-editor', 'dependency-enqueuer-ie' ],
			],
			[
				'function'         => 'Google\WP_Origination\Tests\Data\Plugins\Dependency_Enqueuer\enqueue_scripts',
				'enqueued_scripts' => [ 'jquery-ui-widget' ],
			],
			[
				'function'         => 'wp_audio_shortcode',
				'enqueued_scripts' => [ 'wp-mediaelement' ],
				'enqueued_styles'  => [ 'wp-mediaelement' ],
			],
		);

		foreach ( $stack[1]['invocations'] as $i => $source_invocation_id ) {
			$this->assertArrayHasKey( $source_invocation_id, self::$annotations );
			$source_invocation = self::$annotations[ $source_invocation_id ];
			$this->assertSame( $expected[ $i ]['function'], $source_invocation['function'] );
			$this->assertEqualSets( $expected[ $i ]['enqueued_scripts'], $source_invocation['enqueued_scripts'] );
			if ( isset( $expected[ $i ]['enqueued_styles'] ) ) {
				$this->assertEqualSets( $expected[ $i ]['enqueued_styles'], $source_invocation['enqueued_styles'] );
			}
		}
	}

	/**
	 * Test that an enqueued style has the expected annotation stack.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_enqueued_style_has_annotation_stack() {
		$parent_handle = 'wp-codemirror';
		$child_handle  = 'code-editor';

		$parent_link  = self::$document->getElementById( $parent_handle . '-css' );
		$parent_stack = self::$plugin->output_annotator->get_node_annotation_stack( $parent_link );
		$this->assertCount( 2, $parent_stack );
		$this->assertInstanceOf( 'DOMElement', $parent_link );

		$child_link  = self::$document->getElementById( $child_handle . '-css' );
		$child_stack = self::$plugin->output_annotator->get_node_annotation_stack( $child_link );
		$this->assertCount( 2, $child_stack );
		$this->assertInstanceOf( 'DOMElement', $child_link );

		$this->assertEquals( 'action', $parent_stack[0]['type'] );
		$this->assertEquals( 'wp_head', $parent_stack[0]['name'] );
		$this->assertEquals( 'wp_print_styles', $parent_stack[0]['function'] );
		$this->assertStringEndsWith( 'wp-includes/functions.wp-styles.php', $parent_stack[0]['source']['file'] );
		$this->assertEquals( $parent_stack[0], $child_stack[0] );

		$this->assertEquals( 'enqueued_style', $parent_stack[1]['type'] );
		$this->assertEquals( $parent_stack[1]['type'], $child_stack[1]['type'] );
		$this->assertEquals( $parent_stack[1]['invocations'], $child_stack[1]['invocations'] );

		$this->assertEquals( $parent_stack[1]['invocations'], $parent_stack[1]['invocations'] );
		$source_invocation_id = $parent_stack[1]['invocations'][0];
		$this->assertArrayHasKey( $source_invocation_id, self::$annotations );
		$source_invocation = self::$annotations[ $source_invocation_id ];

		$this->assertEquals( 'action', $source_invocation['type'] );
		$this->assertEquals( 'hook_invoker_enqueue_scripts', $source_invocation['name'] );
		$this->assertStringEndsWith( 'plugins/dependency-enqueuer.php', $source_invocation['source']['file'] );

		$this->assertArrayHasKey( 'enqueued_styles', $source_invocation );
		$this->assertContains( $child_handle, $source_invocation['enqueued_styles'] );
		$this->assertContains( 'dependency-enqueuer-ie', $source_invocation['enqueued_styles'] );
	}

	/**
	 * Test that comment-reply script with async tag injected has the expected annotation stack.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_filtered_script_loader_tag_has_expected_annotations() {
		$script = self::$xpath->query( '//script[ contains( @src, "comment-reply.js" ) ]' )->item( 0 );
		$this->assertInstanceOf( 'DOMElement', $script );

		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $script );
		$this->assertCount( 4, $annotation_stack );

		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_footer',
				'function' => 'wp_print_footer_scripts',
				'parent'   => null,
			],
			$annotation_stack[0]
		);
		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_print_footer_scripts',
				'function' => '_wp_footer_scripts',
				'parent'   => $annotation_stack[0]['index'],
			],
			$annotation_stack[1]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'script_loader_tag',
				'function'       => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Hook_Invoker\\{closure}',
				'value_modified' => true,
				'parent'         => $annotation_stack[1]['index'],
			],
			$annotation_stack[2]
		);
		$this->assertArraySubset(
			[
				'type' => 'enqueued_script',
			],
			$annotation_stack[3]
		);

		$this->assertNotEmpty( $annotation_stack[3]['invocations'] );
		$this->assertArraySubset(
			[
				'type'             => 'action',
				'name'             => 'wp_enqueue_scripts',
				'function'         => 'Google\WP_Origination\Tests\Data\Plugins\Hook_Invoker\enqueue_comment_reply_async',
				'enqueued_scripts' => [ 'comment-reply' ],
			],
			self::$annotations[ $annotation_stack[3]['invocations'][0] ]
		);
	}

	/**
	 * Test that callbacks for the the_content filter which actually mutated the value get wrapping annotations.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_title_has_annotations_for_mutating_filters() {
		/**
		 * Elements
		 *
		 * @var \DOMElement $h1
		 * @var \DOMElement $code
		 */
		$h1 = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_filters'] . '"]//h1[ @class = "entry-title" ] ' )->item( 0 );

		$this->assertInstanceOf( 'DOMElement', $h1 );
		$this->assertContains( 'Test Filters: WordPress <code>code</code> is…beautiful', self::$document->saveHTML( $h1 ) );
		$code = $h1->getElementsByTagName( 'code' )->item( 0 );

		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $code );

		$this->assertCount( 3, $annotation_stack );

		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_title',
				'priority'       => 100,
				'function'       => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Hook_Invoker\\convert_backticks_to_code',
				'source'         => [
					'file' => dirname( WP_ORIGINATION_PLUGIN_FILE ) . '/tests/phpunit/data/plugins/hook-invoker.php',
					'type' => 'plugin',
					'name' => 'hook-invoker.php',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[0]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_title',
				'priority'       => 11,
				'function'       => 'capital_P_dangit',
				'source'         => [
					'file' => ABSPATH . 'wp-includes/formatting.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[1]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_title',
				'priority'       => 10,
				'function'       => 'wptexturize',
				'source'         => [
					'file' => ABSPATH . 'wp-includes/formatting.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[2]
		);
	}

	/**
	 * Test that callbacks for the the_content filter which actually mutated the value get wrapping annotations.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_content_has_annotations_for_mutating_filters() {
		/**
		 * Paragraph.
		 *
		 * @var \DOMElement $p
		 */
		$p = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_filters'] . '"]//div[ @class = "entry-content" ]/p' )->item( 0 );
		$this->assertInstanceOf( 'DOMElement', $p );

		$this->assertInstanceOf( 'DOMComment', $p->previousSibling );
		$this->assertInstanceOf( 'DOMComment', $p->previousSibling->previousSibling );
		$this->assertInstanceOf( 'DOMComment', $p->previousSibling->previousSibling->previousSibling );
		$this->assertNotInstanceOf( 'DOMComment', $p->previousSibling->previousSibling->previousSibling->previousSibling, 'Expected there to not be a comment 4 nodes behind.' );
		$this->assertInstanceOf( 'DOMText', $p->nextSibling, 'Expected newline whitespace due to wpautop.' );
		$this->assertRegExp( '/^\s+$/', $p->nextSibling->nodeValue, 'Expected newline whitespace due to wpautop.' );
		$this->assertInstanceOf( 'DOMComment', $p->nextSibling->nextSibling );
		$this->assertInstanceOf( 'DOMComment', $p->nextSibling->nextSibling->nextSibling );
		$this->assertInstanceOf( 'DOMComment', $p->nextSibling->nextSibling->nextSibling->nextSibling );
		$this->assertNotInstanceOf( 'DOMComment', $p->nextSibling->nextSibling->nextSibling->nextSibling->nextSibling, 'Expected there to not be a comment node 4 nodes ahead of the wpautop whitespace.' );
		$expected_pairs = [
			[
				$p->previousSibling->previousSibling->previousSibling,
				$p->nextSibling->nextSibling->nextSibling->nextSibling,
			],
			[
				$p->previousSibling->previousSibling,
				$p->nextSibling->nextSibling->nextSibling,
			],
			[
				$p->previousSibling,
				$p->nextSibling->nextSibling,
			],
		];

		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $p );
		$this->assertCount( count( $expected_pairs ), $annotation_stack );

		foreach ( $expected_pairs as $i => $expected_pair ) {
			$before_comment = self::$plugin->output_annotator->parse_annotation_comment( $expected_pair[0] );
			$after_comment  = self::$plugin->output_annotator->parse_annotation_comment( $expected_pair[1] );

			$this->assertSame( $before_comment['data']['index'], $after_comment['data']['index'] );
			$this->assertSame( $annotation_stack[ $i ]['index'], $before_comment['data']['index'] );
		}

		/*
		 * Note that the first callback for the filter is the one that appears highest in the stack.
		 * Each subsequent filter callback wraps the previous filter callback's output. This is why
		 * the stack appears somewhat to be in reverse order.
		 */
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_content',
				'priority'       => 100,
				'function'       => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Hook_Invoker\\Paragraph_Contents_Filter::__invoke',
				'source'         => [
					'file' => dirname( WP_ORIGINATION_PLUGIN_FILE ) . '/tests/phpunit/data/plugins/hook-invoker.php',
					'type' => 'plugin',
					'name' => 'hook-invoker.php',
				],
				'parent'         => null,
				'value_modified' => true,
			],
			$annotation_stack[0]
		);
		$this->assertCount( 2, $annotation_stack[0]['children'] );
		$child_anchor_element   = $p->getElementsByTagName( 'a' )->item( 0 );
		$child_annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $child_anchor_element );
		$this->assertCount( 5, $child_annotation_stack );
		$this->assertEquals( $annotation_stack[0], $child_annotation_stack[0] );
		$this->assertEquals( $annotation_stack[1], $child_annotation_stack[1] );
		$this->assertEquals( $annotation_stack[2], $child_annotation_stack[2] );
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'paragraph_contents',
				'priority'       => 13,
				'function'       => 'Google\WP_Origination\Tests\Data\Plugins\Hook_Invoker\Paragraphs::prepend_paragraph_anchor',
				'source'         => [
					'file' => dirname( WP_ORIGINATION_PLUGIN_FILE ) . '/tests/phpunit/data/plugins/hook-invoker.php',
					'type' => 'plugin',
					'name' => 'hook-invoker.php',
				],
				'parent'         => $annotation_stack[0]['index'],
				'children'       => [],
				'value_modified' => true,
			],
			$child_annotation_stack[3]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'paragraph_contents',
				'priority'       => 12,
				'function'       => 'Google\WP_Origination\Tests\Data\Plugins\Hook_Invoker\Paragraphs::append_paragraph_word_count',
				'source'         => [
					'file' => dirname( WP_ORIGINATION_PLUGIN_FILE ) . '/tests/phpunit/data/plugins/hook-invoker.php',
					'type' => 'plugin',
					'name' => 'hook-invoker.php',
				],
				'parent'         => $annotation_stack[0]['index'],
				'children'       => [],
				'value_modified' => true,
			],
			$child_annotation_stack[4]
		);

		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_content',
				'priority'       => 11,
				'function'       => 'capital_P_dangit',
				'source'         => [
					'file' => ABSPATH . 'wp-includes/formatting.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[1]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_content',
				'priority'       => 10,
				'function'       => 'wpautop',
				'source'         => [
					'file' => ABSPATH . 'wp-includes/formatting.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[2]
		);
	}

	/**
	 * Test that callbacks for the the_excerpt filter which actually mutated the value get wrapping annotations.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_excerpt_has_annotations_for_mutating_filters() {
		$text_node = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_filters'] . '"]//div[ @class = "entry-excerpt" ]/p/text()' )->item( 0 );

		$this->assertInstanceOf( 'DOMText', $text_node );

		$this->assertEquals( 'Test… “texturize”.', $text_node->nodeValue );
		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $text_node );
		$this->assertCount( 2, $annotation_stack );

		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_excerpt',
				'priority'       => 10,
				'function'       => 'wpautop',
				'source'         => [
					'file' => ABSPATH . 'wp-includes/formatting.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[0]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_excerpt',
				'priority'       => 10,
				'function'       => 'wptexturize',
				'source'         => [
					'file' => ABSPATH . 'wp-includes/formatting.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$annotation_stack[1]
		);
	}

	/**
	 * Test that shortcodes are added.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_content_has_annotations_for_shortcodes() {
		$text_node = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_shortcode'] . '"]//div[ @class = "entry-content" ]/p/text()[ contains( ., "UPPER" ) ]' )->item( 0 );
		$this->assertInstanceOf( 'DOMText', $text_node );

		$this->assertSame( 'Please UPPER the volume. I cannot year you.', $text_node->parentNode->textContent );

		$shortcode_annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $text_node );

		$this->assertCount( 3, $shortcode_annotation_stack );

		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_content',
				'priority'       => 11,
				'function'       => 'do_shortcode',
				'source'         =>
					[
						'file' => ABSPATH . 'wp-includes/shortcodes.php',
						'type' => 'core',
						'name' => 'wp-includes',
					],
				'parent'         => null,
				'children'       => [
					$shortcode_annotation_stack[2]['index'],
				],
				'value_modified' => true,
			],
			$shortcode_annotation_stack[0]
		);
		$this->assertArraySubset(
			[
				'type'           => 'filter',
				'name'           => 'the_content',
				'priority'       => 10,
				'function'       => 'wpautop',
				'source'         =>
					[
						'file' => ABSPATH . 'wp-includes/formatting.php',
						'type' => 'core',
						'name' => 'wp-includes',
					],
				'parent'         => null,
				'children'       => [],
				'value_modified' => true,
			],
			$shortcode_annotation_stack[1]
		);
		$this->assertArraySubset(
			[
				'type'             => 'shortcode',
				'tag'              => 'passthru',
				'attributes'       => [
					'function' => 'strtoupper',
					'styles'   => 'common',
					'scripts'  => 'wp-api-fetch,colorpicker',
				],
				'function'         => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Shortcode_Adder\\passthru_shortcode',
				'source'           =>
					[
						'file' => dirname( __DIR__ ) . '/data/plugins/shortcode-adder.php',
						'type' => 'plugin',
						'name' => 'shortcode-adder.php',
					],
				'parent'           => $shortcode_annotation_stack[0]['index'],
				'children'         => [],
				'enqueued_styles'  => [ 'common' ],
				'enqueued_scripts' => [ 'wp-api-fetch', 'colorpicker' ],
			],
			$shortcode_annotation_stack[2]
		);
	}

	/**
	 * Test that annotations for blocks are added.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_content_has_annotations_for_blocks() {
		$block_elements = [];
		$block_stacks   = [];
		foreach ( [ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME, Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME, Block_Registerer\CURRENT_TIME_BLOCK_NAME, 'paragraph' ] as $block_name ) {
			$block_elements[ $block_name ] = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_blocks'] . '"]//*[ @data-block-name = "' . $block_name . '" ]' )->item( 0 );
			$block_stacks[ $block_name ]   = self::$plugin->output_annotator->get_node_annotation_stack( $block_elements[ $block_name ] );
		}

		$this->assertCount( 4, array_filter( $block_elements ) );

		// Foreign text block: a static block.
		$this->assertCount( 2, $block_stacks[ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME ] );
		$this->assertArraySubset(
			[
				'type'     => 'filter',
				'name'     => 'the_content',
				'function' => 'do_blocks',
			],
			$block_stacks[ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME ][0]
		);

		$this->assertArraySubset(
			[
				'type'       => 'block',
				'name'       => Block_Registerer\FOREIGN_TEXT_BLOCK_NAME,
				'dynamic'    => false,
				'attributes' => [ 'voice' => 'Juan' ],
				'source'     => [
					'type' => 'plugin',
					'name' => 'block-registerer.php',
				],
			],
			$block_stacks[ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME ][1]
		);
		$this->assertArrayNotHasKey( 'parent', $block_stacks[ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME ][1] ); // @todo Why shouldn't static blocks get parent/child relationships?

		// Text transform block: a dynamic block.
		$this->assertCount( 2, $block_stacks[ Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME ] );
		$this->assertSame( $block_stacks[ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME ][0], $block_stacks[ Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME ][0] );
		$this->assertArraySubset(
			[
				'type'            => 'block',
				'name'            => Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME,
				'dynamic'         => true,
				'attributes'      => [ 'transform' => 'strtoupper' ],
				'enqueued_styles' => [ Block_Registerer\TEXT_TRANSFORM_STYLE_HANDLE ],
				'function'        => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Block_Registerer\\render_text_transform_block',
				'source'          => [
					'file' => dirname( __DIR__ ) . '/data/plugins/block-registerer.php',
					'type' => 'plugin',
					'name' => 'block-registerer.php',
				],
				'parent'          => $block_stacks[ Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME ][0]['index'],
			],
			$block_stacks[ Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME ][1]
		);
		$this->assertEquals( 'I USED TO BE LOWER-CASE.', $block_elements[ Block_Registerer\TEXT_TRANSFORM_BLOCK_NAME ]->textContent );

		// Current time block: a dynamic block inside nested column blocks.
		$this->assertCount( 6, $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ] );
		$this->assertSame( $block_stacks[ Block_Registerer\FOREIGN_TEXT_BLOCK_NAME ][0], $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][0] );
		$this->assertEquals( 'core/columns', $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][1]['name'] );
		$this->assertEquals( 'core/column', $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][2]['name'] );
		$this->assertEquals( 'core/columns', $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][3]['name'] );
		$this->assertEquals( 'core/column', $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][4]['name'] );
		$this->assertArraySubset(
			[
				'type'       => 'block',
				'name'       => Block_Registerer\CURRENT_TIME_BLOCK_NAME,
				'dynamic'    => true,
				'attributes' => [ 'format' => 'c' ],
				'function'   => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Block_Registerer\\render_current_time_block',
				'source'     => [
					'file' => dirname( __DIR__ ) . '/data/plugins/block-registerer.php',
					'type' => 'plugin',
					'name' => 'block-registerer.php',
				],
				'parent'     => $block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][0]['index'],
			],
			$block_stacks[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ][5]
		);
		$this->assertContains( gmdate( 'Y' ), $block_elements[ Block_Registerer\CURRENT_TIME_BLOCK_NAME ]->textContent );

		// Make sure the paragraph block is attributed to core.
		$this->assertEquals( 'core', $block_stacks['paragraph'][1]['source']['type'] );
	}

	/**
	 * Test that annotations for oEmbeds are added.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_content_has_annotations_for_oembeds() {
		$oembeds = [
			[
				'selector'             => 'iframe',
				'url'                  => 'https://www.youtube.com/embed/4mavA1xow1M',
				'actual_annotations'   => [],
				'expected_annotations' => [
					/* ... */
					[
						'type'       => 'block',
						'name'       => 'core-embed/youtube',
						'dynamic'    => false,
						'attributes' => [
							'url'              => 'https://www.youtube.com/embed/4mavA1xow1M',
							'type'             => 'rich',
							'providerNameSlug' => 'embed-handler',
							'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
						],
						'source'     => [
							'type' => 'core',
						],
					],
					[
						'type'       => 'oembed',
						'url'        => 'https://www.youtube.com/embed/4mavA1xow1M',
						'attributes' => [
							'width'  => 500,
							'height' => 750,
						],
						'internal'   => true,
					],
					[
						'type'       => 'oembed',
						'url'        => 'https://youtube.com/watch?v=4mavA1xow1M',
						'attributes' => [
							'width'    => 500,
							'height'   => 750,
							'discover' => true,
						],
						'internal'   => false,
					],
				],
			],
			[
				'selector'             => 'script',
				'url'                  => 'https://twitter.com/iAlbMedina/status/1123397949361274880',
				'actual_annotations'   => [],
				'expected_annotations' => [
					/* ... */
					[
						'type'       => 'block',
						'name'       => 'core-embed/twitter',
						'dynamic'    => false,
						'attributes' => [
							'url'              => 'https://twitter.com/iAlbMedina/status/1123397949361274880',
							'type'             => 'rich',
							'providerNameSlug' => 'twitter',
							'className'        => '',
						],
						'source'     => [
							'type' => 'core',
						],
					],
					[
						'type'       => 'oembed',
						'url'        => 'https://twitter.com/iAlbMedina/status/1123397949361274880',
						'attributes' => [
							'width'    => 500,
							'height'   => 750,
							'discover' => true,
						],
						'internal'   => false,
					],
				],
			],
			[
				'selector'             => 'audio',
				'url'                  => 'https://example.com/podcast.mp3',
				'actual_annotations'   => [],
				'expected_annotations' => [
					[
						'type'       => 'block',
						'name'       => 'core-embed/twitter',
						'dynamic'    => false,
						'attributes' => [
							'url'              => 'https://twitter.com/iAlbMedina/status/1123397949361274880',
							'type'             => 'rich',
							'providerNameSlug' => 'twitter',
							'className'        => '',
						],
						'source'     => [
							'type' => 'core',
						],
					],
					[
						'type'       => 'oembed',
						'url'        => 'https://twitter.com/iAlbMedina/status/1123397949361274880',
						'attributes' => [
							'width'    => 500,
							'height'   => 750,
							'discover' => true,
						],
						'internal'   => false,
					],
				],
			],
		];

		$common_base_annotation_count = 3;

		foreach ( $oembeds as &$oembed ) {
			$element = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_oembeds'] . '"]//div[ @class = "entry-content" ]//' . $oembed['selector'] )->item( 0 );
			$this->assertInstanceOf( 'DOMElement', $element );
			$oembed['actual_annotations'] = self::$plugin->output_annotator->get_node_annotation_stack( $element );
		}

		// Make sure each oEmbed shares the same base annotation stack.
		$initial_annotations = array_slice( $oembeds[0]['actual_annotations'], 0, $common_base_annotation_count );
		for ( $i = 1, $len = count( $oembeds ); $i < $len; $i++ ) {
			$this->assertEquals(
				$initial_annotations,
				array_slice( $oembeds[ $i ]['actual_annotations'], 0, $common_base_annotation_count )
			);
		}

		foreach ( $oembeds as $oembed ) {
			foreach ( $oembed['expected_annotations'] as $i => $expected_subset_annotation ) {
				$this->assertArraySubset(
					$expected_subset_annotation,
					$oembed['actual_annotations'][ $common_base_annotation_count + $i ]
				);
			}
		}
	}

	/**
	 * Test that annotations for widgets are added.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_content_has_annotations_for_widgets() {
		$sidebar_element = self::$document->getElementById( 'sidebar' );
		$this->assertInstanceOf( 'DOMElement', $sidebar_element );

		/**
		 * Widget elements.
		 *
		 * @var \DOMNodeList|\DOMElement[] $widget_elements
		 */
		$widget_elements = $sidebar_element->getElementsByTagName( 'li' );
		$this->assertEquals( 3, $widget_elements->count() );

		$expected_annotations = [
			[
				'type'     => 'widget',
				'id_base'  => null,
				'number'   => null,
				'id'       => Widget_Registerer\SINGLE_WIDGET_ID,
				'name'     => 'Single',
				'function' => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Widget_Registerer\\display_single_widget',
				'source'   => [
					'file' => dirname( __DIR__ ) . '/data/plugins/widget-registerer.php',
					'type' => 'plugin',
					'name' => 'widget-registerer.php',
				],
				'parent'   => null,
			],
			[
				'type'     => 'widget',
				'id_base'  => Widget_Registerer\MULTI_WIDGET_ID_BASE,
				'number'   => 2,
				'id'       => Widget_Registerer\MULTI_WIDGET_ID_BASE . '-2',
				'name'     => 'Multi',
				'function' => 'Google\\WP_Origination\\Tests\\Data\\Plugins\\Widget_Registerer\\Multi_Widget::display_callback',
				'source'   => [
					'file' => dirname( __DIR__ ) . '/data/plugins/widget-registerer.php',
					'type' => 'plugin',
					'name' => 'widget-registerer.php',
				],
				'parent'   => null,
				'instance' => [
					'title' => 'Multiple',
				],
			],
			[
				'type'     => 'widget',
				'id_base'  => 'search',
				'number'   => 3,
				'id'       => 'search-3',
				'name'     => 'Search',
				'function' => 'WP_Widget_Search::display_callback',
				'source'   =>
					array(
						'file' => ABSPATH . 'wp-includes/widgets/class-wp-widget-search.php',
						'type' => 'core',
						'name' => 'wp-includes',
					),
				'parent'   => null,
				'instance' => [
					'title' => 'Not Google!',
				],
			],
		];

		foreach ( $widget_elements as $i => $widget_element ) {
			$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $widget_element );
			$this->assertCount( 1, $annotation_stack );
			$this->assertArraySubset( $expected_annotations[ $i ], $annotation_stack[0], false, "Expected annotation (i=$i) to be a subset." );
		}
	}

	/**
	 * Test that wrapped callbacks for registered widgets can be introspected with array accessors.
	 *
	 * @see \Google\WP_Origination\Tests\Data\Plugins\Widget_Registerer\add_option_name_to_before_widget()
	 */
	public function test_introspectable_registered_widget_wrapped_callbacks() {
		/**
		 * Widget elements.
		 *
		 * @var \DOMNodeList|\DOMElement[] $widget_elements
		 */
		$widget_elements = self::$document->getElementById( 'sidebar' )->getElementsByTagName( 'li' );

		$this->assertFalse( $widget_elements[0]->hasAttribute( 'data-widget-class-name' ) );
		$this->assertSame( 'widget_' . Widget_Registerer\MULTI_WIDGET_ID_BASE, $widget_elements[1]->getAttribute( 'data-widget-class-name' ) );
		$this->assertSame( 'widget_search', $widget_elements[2]->getAttribute( 'data-widget-class-name' ) );
	}

	/**
	 * Test proper sourcing of hooks in parent and child themes.
	 *
	 * @covers \Google\WP_Origination\File_Locator::identify()
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_footer_has_theme_credits() {
		$parent_theme_credits = self::$document->getElementById( 'parent-theme-credits' );
		$this->assertInstanceOf( 'DOMElement', $parent_theme_credits );
		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $parent_theme_credits );
		$this->assertCount( 1, $annotation_stack );
		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_footer',
				'priority' => 10,
				'function' => 'Google\WP_Origination\Tests\Data\Themes\Parent\add_parent_theme_credits',
				'source'   => [
					'file' => dirname( __DIR__ ) . '/data/themes/parent/functions.php',
					'type' => 'theme',
					'name' => 'parent',
				],
				'parent'   => null,
			],
			$annotation_stack[0]
		);

		$child_theme_credits = self::$document->getElementById( 'child-theme-credits' );
		$this->assertInstanceOf( 'DOMElement', $child_theme_credits );
		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $child_theme_credits );
		$this->assertCount( 1, $annotation_stack );
		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_footer',
				'priority' => 10,
				'function' => 'Google\WP_Origination\Tests\Data\Themes\Child\add_child_theme_credits',
				'source'   => [
					'file' => dirname( __DIR__ ) . '/data/themes/child/functions.php',
					'type' => 'theme',
					'name' => 'child',
				],
				'parent'   => null,
			],
			$annotation_stack[0]
		);

	}

	/**
	 * Test that styles enqueued by themes have expected annotations.
	 *
	 * @covers \Google\WP_Origination\File_Locator::identify()
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_theme_enqueued_styles() {
		$parent_style_element = self::$document->getElementById( 'parent-style-css' );
		$child_style_element  = self::$document->getElementById( 'child-style-css' );
		$this->assertInstanceOf( 'DOMElement', $parent_style_element );
		$this->assertInstanceOf( 'DOMElement', $child_style_element );

		$parent_annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $parent_style_element );
		$child_annotation_stack  = self::$plugin->output_annotator->get_node_annotation_stack( $child_style_element );
		$this->assertCount( 2, $parent_annotation_stack );
		$this->assertCount( 2, $child_annotation_stack );
		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_head',
				'priority' => 8,
				'function' => 'wp_print_styles',
				'source'   => [
					'file' => ABSPATH . WPINC . '/functions.wp-styles.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
				'parent'   => null,
			],
			$parent_annotation_stack[0]
		);
		$this->assertEquals( $parent_annotation_stack[0], $child_annotation_stack[0] );

		$this->assertEquals( 'enqueued_style', $parent_annotation_stack[1]['type'] );
		$this->assertEquals( 'enqueued_style', $child_annotation_stack[1]['type'] );
		$this->assertCount( 2, $parent_annotation_stack[1]['invocations'], 'Expected 2 because the parent enqueues the parent style, and the child enqueues a style that depends on the parent style.' );
		$this->assertCount( 1, $child_annotation_stack[1]['invocations'] );

		// Check that the enqueued_style annotation for parent-style was invoked by both the parent theme and the child theme.
		$this->assertArraySubset(
			[
				'type'            => 'action',
				'name'            => 'wp_enqueue_scripts',
				'priority'        => 10,
				'function'        => 'Google\WP_Origination\Tests\Data\Themes\Child\enqueue_scripts',
				'enqueued_styles' => [ 'child-style' ],
				'source'          => [
					'file' => dirname( __DIR__ ) . '/data/themes/child/functions.php',
					'type' => 'theme',
					'name' => 'child',
				],
			],
			self::$annotations[ $parent_annotation_stack[1]['invocations'][0] ]
		);
		$this->assertArraySubset(
			[
				'type'            => 'action',
				'name'            => 'wp_enqueue_scripts',
				'priority'        => 10,
				'function'        => 'Google\WP_Origination\Tests\Data\Themes\Parent\enqueue_scripts',
				'enqueued_styles' => [ 'parent-style' ],
				'source'          => [
					'file' => dirname( __DIR__ ) . '/data/themes/parent/functions.php',
					'type' => 'theme',
					'name' => 'parent',
				],
			],
			self::$annotations[ $parent_annotation_stack[1]['invocations'][1] ]
		);

		$this->assertEquals(
			$parent_annotation_stack[1]['invocations'][0],
			$child_annotation_stack[1]['invocations'][0]
		);
	}

	/**
	 * Test that script enqueued by theme has expected annotations.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_theme_enqueued_script() {
		/**
		 * Elements
		 *
		 * @var \DOMElement $script
		 */
		$script = self::$xpath->query( '//script[ contains( @src, "js/navigation.js" ) ]' )->item( 0 );
		$this->assertInstanceOf( 'DOMElement', $script );

		$annotation_stack = self::$plugin->output_annotator->get_node_annotation_stack( $script );
		$this->assertCount( 3, $annotation_stack );

		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_footer',
				'priority' => 20,
				'function' => 'wp_print_footer_scripts',
				'source'   => [
					'file' => ABSPATH . WPINC . '/script-loader.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
			],
			self::$annotations[ $annotation_stack[0]['index'] ]
		);

		$this->assertArraySubset(
			[
				'type'     => 'action',
				'name'     => 'wp_print_footer_scripts',
				'priority' => 10,
				'function' => '_wp_footer_scripts',
				'source'   => [
					'file' => ABSPATH . WPINC . '/script-loader.php',
					'type' => 'core',
					'name' => 'wp-includes',
				],
			],
			self::$annotations[ $annotation_stack[1]['index'] ]
		);

		$this->assertEquals( 'enqueued_script', self::$annotations[ $annotation_stack[2]['index'] ]['type'] );
		$this->assertCount( 1, self::$annotations[ $annotation_stack[2]['index'] ]['invocations'] );

		$this->assertArraySubset(
			[
				'type'             => 'action',
				'name'             => 'wp_enqueue_scripts',
				'priority'         => 10,
				'function'         => 'Google\WP_Origination\Tests\Data\Themes\Parent\enqueue_scripts',
				'enqueued_scripts' => [ 'parent-navigation' ],
				'source'           => [
					'file' => dirname( __DIR__ ) . '/data/themes/parent/functions.php',
					'type' => 'theme',
					'name' => 'parent',
				],
			],
			self::$annotations[ self::$annotations[ $annotation_stack[2]['index'] ]['invocations'][0] ]
		);
	}

	/**
	 * Tear down after class.
	 */
	public static function tearDownAfterClass() {
		Block_Registerer\unregister_blocks();
		parent::tearDownAfterClass();
		self::$xpath    = null;
		self::$document = null;
		self::$plugin   = null;
		self::$output   = null;
	}
}
