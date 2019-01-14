<?php
/**
 * Hook_Invocation Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Hook_Invocation.
 */
class Hook_Invocation extends Invocation {

	/**
	 * Name of filter/action.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Accepted argument count.
	 *
	 * @var int
	 */
	public $accepted_args;

	/**
	 * Priority.
	 *
	 * @var int
	 */
	public $priority;

	/**
	 * Args passed when the hook was done/applied.
	 *
	 * @todo Consider not capturing this since can will incur a lot of memory.
	 * @var array
	 */
	public $hook_args;

	/**
	 * Returns whether it is an action hook.
	 *
	 * @return bool Whether an action hook.
	 */
	public function is_action() {
		return did_action( $this->name ) > 0;
	}

	/**
	 * Get data for exporting.
	 *
	 * @return array Data.
	 */
	public function data() {
		$data = parent::data();
		$id   = $data['id'];
		unset( $data['id'] );

		return array_merge(
			compact( 'id' ),
			array(
				'type'     => $this->is_action() ? 'action' : 'filter',
				'name'     => $this->name,
				'priority' => $this->priority,
			),
			$data
		);
	}
}
