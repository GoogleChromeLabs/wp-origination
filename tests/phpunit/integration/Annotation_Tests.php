<?php
/**
 * Class Google\WP_Sourcery\Tests\PHPUnit\Unit\Annotation_Tests
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery\Tests\PHPUnit\Unit;

use Google\WP_Sourcery\Plugin;
use Google\WP_Sourcery\Tests\PHPUnit\Framework\Integration_Test_Case;

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

		self::$plugin = new Plugin( WP_SOURCERY_PLUGIN_FILE );
		self::$plugin->init();

		array_unshift(
			self::$plugin->file_locator->plugins_directories,
			dirname( __DIR__ ) . '/data/plugins/'
		);

		self::$post_ids['test_core_filters'] = self::factory()->post->create(
			[
				'post_title'   => 'Test Core Filters',
				'post_excerpt' => 'Test... "texturize".',
				'post_content' => 'Test Wordpress', // Test capital_P_dangit.
			]
		);

		self::$post_ids['test_shortcode'] = self::factory()->post->create(
			[
				'post_title'   => 'Test Shortcodes',
				'post_content' => 'Please [transform_text case=upper]upper[/transform_text] the volume. I cannot year you.',
			]
		);

		require_once __DIR__ . '/../data/plugins/hook-invoker.php';
		require_once __DIR__ . '/../data/plugins/dependency-enqueuer.php';
		require_once __DIR__ . '/../data/plugins/shortcode-adder.php';

		// Start workaround output buffering to deal with inability of ob_start() to manipulate buffer when calling ob_get_clean(). See <>https://stackoverflow.com/a/12392694>.
		ob_start();

		self::$plugin->invocation_watcher->start();
		self::$plugin->output_annotator->start( false );

		// @todo Add Block_Registerer.
		// @todo Add Widget_Registerer.
		\Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker\add_hooks();
		\Google\WP_Sourcery\Tests\Data\Plugins\Shortcode_Adder\add_shortcode();
		\Google\WP_Sourcery\Tests\Data\Plugins\Dependency_Enqueuer\add_hooks();
		\Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker\print_template( [ 'p' => array_values( self::$post_ids ) ] );

		ob_end_flush(); // End workaround buffer.
		self::$output = ob_get_clean();

		self::$document        = new \DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		self::$document->loadHTML( self::$output );
		libxml_use_internal_errors( $libxml_previous_state );
		self::$xpath = new \DOMXPath( self::$document );

		$start_comments = self::$xpath->query( sprintf( '//comment()[ starts-with( ., " %s " ) ]', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG ) );
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
			sprintf( 'starts-with( ., " %s " )', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG ),
			sprintf( 'starts-with( ., " /%s " )', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG ),
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
	 * @covers \Google\WP_Sourcery\Output_Annotator::finish()
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
				'function' => 'Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker\print_document_write',
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
		$this->assertCount( 2, $stack[1]['invocations'] );

		$expected_source_enqueues = array(
			[ 'wp-a11y' ],
			[ 'jquery-ui-widget' ],
		);

		foreach ( $stack[1]['invocations'] as $i => $source_invocation_id ) {
			$this->assertArrayHasKey( $source_invocation_id, self::$annotations );
			$source_invocation = self::$annotations[ $source_invocation_id ];
			$this->assertTrue( ! empty( $source_invocation['enqueued_scripts'] ) );
			$this->assertEqualSets( $expected_source_enqueues[ $i ], $source_invocation['enqueued_scripts'] );
		}
	}

	/**
	 * Test that an enqueued style has the expected annotation stack.
	 */
	public function test_enqueued_style_has_annotation_stack() {
		$mediaelement_css_link = self::$document->getElementById( 'mediaelement-css' );
		$mediaelement_stack    = self::$plugin->output_annotator->get_node_annotation_stack( $mediaelement_css_link );
		$this->assertCount( 2, $mediaelement_stack );
		$this->assertInstanceOf( 'DOMElement', $mediaelement_css_link );

		$wp_mediaelement_css_link = self::$document->getElementById( 'wp-mediaelement-css' );
		$wp_mediaelement_stack    = self::$plugin->output_annotator->get_node_annotation_stack( $wp_mediaelement_css_link );
		$this->assertCount( 2, $wp_mediaelement_stack );
		$this->assertInstanceOf( 'DOMElement', $wp_mediaelement_css_link );

		$this->assertEquals( 'action', $mediaelement_stack[0]['type'] );
		$this->assertEquals( 'wp_head', $mediaelement_stack[0]['name'] );
		$this->assertEquals( 'wp_print_styles', $mediaelement_stack[0]['function'] );
		$this->assertStringEndsWith( 'wp-includes/functions.wp-styles.php', $mediaelement_stack[0]['source']['file'] );
		$this->assertEquals( $mediaelement_stack[0], $wp_mediaelement_stack[0] );

		$this->assertEquals( 'enqueued_style', $mediaelement_stack[1]['type'] );
		$this->assertEquals( $mediaelement_stack[1]['type'], $wp_mediaelement_stack[1]['type'] );
		$this->assertEquals( $mediaelement_stack[1]['invocations'], $wp_mediaelement_stack[1]['invocations'] );

		$this->assertEquals( $mediaelement_stack[1]['invocations'], $mediaelement_stack[1]['invocations'] );
		$source_invocation_id = $mediaelement_stack[1]['invocations'][0];
		$this->assertArrayHasKey( $source_invocation_id, self::$annotations );
		$source_invocation = self::$annotations[ $source_invocation_id ];

		$this->assertEquals( 'action', $source_invocation['type'] );
		$this->assertEquals( 'hook_invoker_enqueue_scripts', $source_invocation['name'] );
		$this->assertStringEndsWith( 'plugins/dependency-enqueuer.php', $source_invocation['source']['file'] );

		$this->assertArrayHasKey( 'enqueued_styles', $source_invocation );
		$this->assertContains( 'wp-mediaelement', $source_invocation['enqueued_styles'] );
	}

	/**
	 * Test that callbacks for the the_content filter which actually mutated the value get wrapping annotations.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_content_has_annotations_for_mutating_filters() {
		$this->assertContains( '<p>Test WordPress</p>', self::$output );

		$p = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_core_filters'] . '"]//div[ @class = "entry-content" ]/p[ text() = "Test WordPress"]' )->item( 0 );
		$this->assertInstanceOf( 'DOMElement', $p );

		$this->assertInstanceOf( 'DOMComment', $p->previousSibling );
		$this->assertInstanceOf( 'DOMComment', $p->previousSibling->previousSibling );
		$this->assertNotInstanceOf( 'DOMComment', $p->previousSibling->previousSibling->previousSibling, 'Expected there to not be a comment 3 nodes behind.' );
		$this->assertInstanceOf( 'DOMText', $p->nextSibling, 'Expected newline whitespace due to wpautop.' );
		$this->assertRegExp( '/^\s+$/', $p->nextSibling->nodeValue, 'Expected newline whitespace due to wpautop.' );
		$this->assertInstanceOf( 'DOMComment', $p->nextSibling->nextSibling );
		$this->assertInstanceOf( 'DOMComment', $p->nextSibling->nextSibling->nextSibling );
		$this->assertNotInstanceOf( 'DOMComment', $p->nextSibling->nextSibling->nextSibling->nextSibling, 'Expected there to not be a comment node 3 nodes ahead of the wpautop whitespace.' );
		$expected_pairs = [
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
			$annotation_stack[0]
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
			$annotation_stack[1]
		);
	}

	/**
	 * Test that callbacks for the the_excerpt filter which actually mutated the value get wrapping annotations.
	 *
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function test_the_excerpt_has_annotations_for_mutating_filters() {
		$text_node = self::$xpath->query( '//article[@id = "post-' . self::$post_ids['test_core_filters'] . '"]//div[ @class = "entry-excerpt" ]/p/text()' )->item( 0 );

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
				'children'       => [],
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

		$this->markTestIncomplete( 'The annotation stack needs to have a count of 3, with the top of the stack being a shortcode annotation.' );
	}

	/**
	 * Tear down after class.
	 */
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		self::$xpath    = null;
		self::$document = null;
		self::$plugin   = null;
		self::$output   = null;
	}
}
