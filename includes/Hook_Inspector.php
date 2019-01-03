<?php
/**
 * Hook_Inspector Class.
 *
 * @package Sourcery
 */

namespace Sourcery;

/**
 * Class Hook_Inspector.
 */
class Hook_Inspector {

	/**
	 * Hook stack.
	 *
	 * @var Hook_Inspection[]
	 */
	protected $hook_stack = array();

	/**
	 * Processed hooks.
	 *
	 * @var Hook_Inspection[]
	 */
	public $processed_hooks = array();

	/**
	 * Lookup of which queries have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var int[]
	 */
	protected $sourced_query_indices = array();

	function __construct() {

	}

	/**
	 * Before hook.
	 *
	 * @param array $args {
	 *      Args.
	 *
	 *     @var string   $hook_name     Hook name.
	 *     @var callable $function      Function.
	 *     @var int      $accepted_args Accepted argument count.
	 *     @var int      $priority      Priority.
	 *     @var array    $hook_args     Hook args.
	 * }
	 */
	function before_hook( $args ) {
		global $wpdb;
		$this->hook_stack[] = new Hook_Inspection( array_merge(
			$args,
			array(
				'start_time'         => microtime( true ),
				'before_num_queries' => $wpdb->num_queries,
				// @todo Queued scripts.
				// @todo Queued styles.
			)
		) );
	}

	/**
	 * After hook.
	 *
	 * @throws \Exception If the stack was empty, which should not happen.
	 */
	function after_hook() {
		global $wpdb;

		$hook_inspection = array_pop( $this->hook_stack );
		if ( ! $hook_inspection ) {
			throw new \Exception( 'Stack was empty' );
		}

		$hook_inspection->end_time = microtime( true );
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && $wpdb->num_queries !== $hook_inspection->before_num_queries ) {
			$search = 'Sourcery\Hook_Wrapper->Sourcery\{closure}, call_user_func_array, ';

			$hook_inspection->query_indices = array();
			foreach ( range( $hook_inspection->before_num_queries, $wpdb->num_queries - 1 ) as $query_index ) {

				// Purge references to the hook wrapper from the query call stack.
				foreach ( $wpdb->queries[ $query_index ] as &$query ) {
					$query = str_replace( $search, '', $query );
				}

				// Flag this query as being associated with this hook instance.
				if ( ! isset( $this->sourced_query_indices[ $query_index ] ) ) {
					$hook_inspection->query_indices[]            = $query_index;
					$this->sourced_query_indices[ $query_index ] = true;
				}
			}
		}

		$this->processed_hooks[] = $hook_inspection;
	}
}

