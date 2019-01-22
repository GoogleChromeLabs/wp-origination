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

		$document              = new \DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		$document->loadHTML( self::$output );
		libxml_use_internal_errors( $libxml_previous_state );
		self::$xpath = new \DOMXPath( $document );
	}

	/**
	 * Ensure that the number of comments is as expected.
	 */
	public function test_expected_annotation_comment_counts() {

		$predicates = [
			sprintf( 'starts-with( ., " %s " )', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG ),
			sprintf( '( starts-with( ., "[" ) and contains( ., "<!-- %s" ) )', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG ),
			sprintf( 'starts-with( ., " /%s " )', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG ),
		];
		$expression = sprintf( '//comment()[ %s ]', implode( ' or ', $predicates ) );
		$comments   = self::$xpath->query( $expression );

		$this->assertGreaterThan( 0, $comments->length );
		$this->assertTrue( 0 === $comments->length % 2, 'There should be an even number of comments.' );
		$opening = [];
		$closing = [];
		foreach ( $comments as $comment ) {
			if ( preg_match( '#^ /#', $comment->nodeValue ) ) {
				$closing[] = $comment;
			} else {
				$opening[] = $comment;
			}
		}
		$this->assertEquals( count( $opening ), count( $closing ) );
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
