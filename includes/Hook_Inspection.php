<?php
/**
 * Hook_Inspection Class.
 *
 * @package Sourcery
 */
namespace Sourcery;

/**
 * Class Hook_Inspection.
 */
class Hook_Inspection {

	/**
	 * Hook name.
	 *
	 * @var string
	 */
	public $hook_name;

	/**
	 * Callback function.
	 *
	 * @var callable
	 */
	public $function;

	/**
	 * Nice name of the callback function.
	 *
	 * @var string
	 */
	public $function_name;

	/**
	 * File in which the function was defined.
	 *
	 * @var string
	 */
	public $source_file;

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
	 * @var array
	 */
	public $hook_args;

	/**
	 * Start time.
	 *
	 * @var float
	 */
	public $start_time;

	/**
	 * End time.
	 *
	 * @var float
	 */
	public $end_time;

	/**
	 * Number of queries before function called.
	 *
	 * @var int
	 */
	public $before_num_queries;

	/**
	 * The indices of the queries in $wpdb->queries that this hook was responsible for.
	 *
	 * @var int[]
	 */
	public $query_indices;

	/**
	 * Constructor.
	 *
	 * @param array $args Arguments which are assigned to properties.
	 */
	public function __construct( $args ) {
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
	}
}
