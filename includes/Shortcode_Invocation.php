<?php
/**
 * Shortcode_Invocation Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

namespace Google\WP_Origination;

/**
 * Class Shortcode_Invocation.
 */
class Shortcode_Invocation extends Invocation {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	public $tag;

	/**
	 * Shortcode attributes.
	 *
	 * @var array
	 */
	public $attributes;

	/**
	 * Whether this invocation is expected to produce output (an action) vs a filter.
	 *
	 * @todo This may not make sense to be in the base class.
	 *
	 * @return bool Whether output is expected.
	 */
	public function can_output() {
		return false;
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
				'type'       => 'shortcode',
				'tag'        => $this->tag,
				'attributes' => $this->attributes,
			],
			$data
		);

		return $data;
	}
}
