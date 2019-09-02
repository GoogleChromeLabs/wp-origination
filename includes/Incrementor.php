<?php
/**
 * Incrementor Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Origination;

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
