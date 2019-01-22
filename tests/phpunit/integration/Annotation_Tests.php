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

use Google\WP_Sourcery\Tests\PHPUnit\Framework\Integration_Test_Case;

/**
 * Testing annotations.
 */
class Annotation_Tests extends Integration_Test_Case {

	/**
	 * Performs a annotation test.
	 */
	public function testNothingUseful() {
		require_once __DIR__ . '/../data/plugins/hook-invoker.php';

		// Start workaround output buffering to deal with inability of ob_start() to manipulate buffer when calling ob_get_clean(). See <>https://stackoverflow.com/a/12392694>.
		ob_start();

		$ob_level = ob_get_level();
		$this->plugin->invocation_watcher->start();
		$this->plugin->output_annotator->start( false );
		$this->assertEquals( $ob_level + 1, ob_get_level() );

		\Google\WP_Sourcery\Tests\Data\Plugins\Hook_Invoker\run();

		ob_end_flush(); // End workaround buffer.
		$output = ob_get_clean();

		$document              = new \DOMDocument();
		$libxml_previous_state = libxml_use_internal_errors( true );
		$document->loadHTML( $output );
		libxml_use_internal_errors( $libxml_previous_state );
		$xpath = new \DOMXPath( $document );

		$expression = sprintf( '//comment()[ starts-with( ., " %1$s " ) or starts-with( ., " /%1$s " ) ]', \Google\WP_Sourcery\Output_Annotator::ANNOTATION_TAG );
		$comments   = $xpath->query( $expression );

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
}
