<?php
/**
 * Incrementor Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Incrementor.
 */
class Incrementor {

	/**
	 * Current value.
	 *
	 * @var int
	 */
	protected $current_value = 0;

	/**
	 * Get next incremented value.
	 *
	 * @return int Value.
	 */
	public function next() {
		return ++$this->current_value;
	}
}
