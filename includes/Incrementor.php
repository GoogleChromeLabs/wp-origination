<?php
/**
 * Incrementor Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
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
