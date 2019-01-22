<?php
/**
 * Class Google\WP_Sourcery\Tests\PHPUnit\Framework\Integration_Test_Case
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery\Tests\PHPUnit\Framework;

use Google\WP_Sourcery\Plugin;
use WP_UnitTestCase;

/**
 * Class representing an integration test case.
 */
class Integration_Test_Case extends WP_UnitTestCase {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Set up.
	 */
	public function setUp() {
		parent::setUp();
		$this->plugin = new Plugin( WP_SOURCERY_PLUGIN_FILE );
		$this->plugin->init();

		array_unshift(
			$this->plugin->file_locator->plugins_directories,
			dirname( __DIR__ ) . '/data/plugins/'
		);
	}

}
