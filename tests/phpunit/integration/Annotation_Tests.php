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
	 * All annotations found in the document, keyed by ID.
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
			if ( isset( $parsed_comment['data']['id'] ) ) {
				self::$annotations[ $parsed_comment['data']['id'] ] = $parsed_comment['data'];
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
			$this->assertArrayHasKey( 'id', $parsed_comment['data'] );
			if ( ! $parsed_comment['closing'] ) {
				$this->assertArrayHasKey( 'type', $parsed_comment['data'], 'Data array: ' . wp_json_encode( $parsed_comment['data'] ) );
			}

			if ( $parsed_comment['closing'] ) {
				$open_parsed_comment = array_pop( $stack );
				$this->assertEquals( $open_parsed_comment['data']['id'], $parsed_comment['data']['id'] );
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
			),
		);

		foreach ( $stack as $i => $annotation_data ) {
			$this->assertArraySubset( $expected[ $i ], $annotation_data );
			$this->assertInternalType( 'float', $annotation_data['own_time'] );
			$this->assertGreaterThan( 0.0, $annotation_data['own_time'] );
		}

		$this->assertContains( $stack[1]['id'], $stack[0]['children'] );
		$this->assertEquals( $stack[0]['id'], $stack[1]['parent'] );

		// Verify sources.
		$this->assertStringEndsWith( 'wp-includes/script-loader.php', $stack[0]['source']['file'] );
		$this->assertEquals( 'core', $stack[0]['source']['type'] );
		$this->assertEquals( 'wp-includes', $stack[0]['source']['name'] );
		$this->assertStringEndsWith( 'plugins/hook-invoker.php', $stack[1]['source']['file'] );
		$this->assertEquals( 'plugin', $stack[1]['source']['type'] );
		$this->assertEquals( 'hook-invoker.php', $stack[1]['source']['name'] );
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
