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

		require_once __DIR__ . '/../data/plugins/hook-invoker.php';
		require_once __DIR__ . '/../data/plugins/dependency-enqueuer.php';

		// Start workaround output buffering to deal with inability of ob_start() to manipulate buffer when calling ob_get_clean(). See <>https://stackoverflow.com/a/12392694>.
		ob_start();

		self::$plugin->invocation_watcher->start();
		self::$plugin->output_annotator->start( false );

		\Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker\add_hooks();
		\Google\WP_Sourcery\Tests\Data\Plugins\Dependency_Enqueuer\add_hooks();
		\Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker\print_template();

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
		$this->assertTrue( 0 === $comments->length % 2, 'There should be an even number of comments.' );
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
			} else {
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
		/*
		 *                 <!-- sourcery {"type":"enqueued_style","invocations":[12]} -->
                    <link rel='stylesheet' id='mediaelement-css'  href='http://example.org/core-dev/src/wp-includes/js/mediaelement/mediaelementplayer-legacy.min.css?ver=4.2.6-78496d1' type='text/css' media='all' />
                <!-- /sourcery {"type":"enqueued_style","invocations":[12]} -->
                <!-- sourcery {"type":"enqueued_style","invocations":[12]} -->
                    <link rel='stylesheet' id='wp-mediaelement-css'  href='http://example.org/core-dev/src/wp-includes/js/mediaelement/wp-mediaelement.min.css?ver=5.1-beta1-44558-src' type='text/css' media='all' />
                <!-- /sourcery {"type":"enqueued_style","invocations":[12]} -->
		 */

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
