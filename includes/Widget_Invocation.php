<?php
/**
 * Widget_Invocation Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

/**
 * Class Widget_Invocation.
 */
class Widget_Invocation extends Invocation {

	/**
	 * Widget ID.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Widget object (if a multi-widget and not old singular style).
	 *
	 * @var \WP_Widget
	 */
	public $widget;

	/**
	 * Multi-widget number.
	 *
	 * @var int
	 */
	public $number;

	/**
	 * Widget name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Whether this invocation is expected to produce output (an action) vs a filter.
	 *
	 * @return bool Whether output is expected.
	 */
	public function can_output() {
		return true;
	}

	/**
	 * Get data for exporting.
	 *
	 * @return array Data.
	 */
	public function data() {
		$data  = parent::data();
		$index = $data['index'];
		unset( $data['index'] );

		$data = array_merge(
			compact( 'index' ),
			[
				'type'    => 'widget',
				'id_base' => $this->widget ? $this->widget->id_base : null,
				'number'  => $this->number,
				'id'      => $this->id,
				'name'    => $this->name,
			],
			$data
		);

		if ( $this->widget instanceof \WP_Widget && $this->number ) {
			$instances = $this->widget->get_settings();
			if ( isset( $instances[ $this->number ] ) ) {
				$data['instance'] = $instances[ $this->number ];
			}
		}

		return $data;
	}
}
