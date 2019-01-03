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
	public $hook_stack = array();

	/**
	 * Processed hooks.
	 *
	 * @var Hook_Inspection[]
	 */
	public $processed_hooks = array();

	/**
	 * Database abstraction for WordPress.
	 *
	 * @var \wpdb
	 */
	public $wpdb;

	/**
	 * Lookup of which queries have already been assigned to hooks.
	 *
	 * Array keys are the query indices and the values are all true.
	 *
	 * @var int[]
	 */
	protected $sourced_query_indices = array();

	/**
	 * Hook_Inspector constructor.
	 *
	 * @param \wpdb|object $wpdb DB.
	 */
	function __construct( object $wpdb ) {
		$this->wpdb = $wpdb;
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
	public function before_hook( $args ) {
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
	public function after_hook() {
		$hook_inspection = array_pop( $this->hook_stack );
		if ( ! $hook_inspection ) {
			throw new \Exception( 'Stack was empty' );
		}

		$hook_inspection->end_time = microtime( true );

		$this->identify_hook_queries( $hook_inspection );

		$this->processed_hooks[] = $hook_inspection;
	}

	/**
	 * Identify the queries that were made during the hook's invocation.
	 *
	 * @param Hook_Inspection $hook_inspection Hook inspection.
	 */
	public function identify_hook_queries( Hook_Inspection $hook_inspection ) {

		// Short-circuit if queries are not being saved (aka if SAVEQUERIES is not defined).
		if ( empty( $this->wpdb->queries ) ) {
			return;
		}

		// If no queries have been made during the hook invocation, short-circuit.
		if ( $this->wpdb->num_queries === $hook_inspection->before_num_queries ) {
			return;
		}

		$search = 'Sourcery\Hook_Wrapper->Sourcery\{closure}, call_user_func_array, ';

		$hook_inspection->query_indices = array();
		foreach ( range( $hook_inspection->before_num_queries, $this->wpdb->num_queries - 1 ) as $query_index ) {

			// Purge references to the hook wrapper from the query call stack.
			foreach ( $this->wpdb->queries[ $query_index ] as &$query ) {
				$query = str_replace( $search, '', $query );
			}

			// Flag this query as being associated with this hook instance.
			if ( ! isset( $this->sourced_query_indices[ $query_index ] ) ) {
				$hook_inspection->query_indices[]            = $query_index;
				$this->sourced_query_indices[ $query_index ] = true;
			}
		}
	}
}

