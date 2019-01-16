<?php
/**
 * Database Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

use \wpdb;

/**
 * Class Database.
 */
class Database {

	/**
	 * Invocation watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

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
	 * @var bool[]
	 */
	protected $sourced_query_indices = [];

	/**
	 * Database constructor.
	 *
	 * @param Invocation_Watcher $invocation_watcher Invocation watcher.
	 * @global \wpdb $wpdb
	 */
	public function __construct( Invocation_Watcher $invocation_watcher ) {
		global $wpdb;
		$this->invocation_watcher = $invocation_watcher;
		$this->wpdb               = $wpdb;
	}

	/**
	 * Get the WordPress DB.
	 *
	 * @return wpdb|object DB.
	 */
	public function get_wpdb() {
		return $this->wpdb;
	}

	/**
	 * Identify the queries that were made during the invocation.
	 *
	 * @todo Account for inclusive vs exclusive.
	 * @param Invocation $invocation Invocation.
	 * @return int[] Query indices associated with the hook.
	 */
	public function identify_invocation_queries( Invocation $invocation ) {

		// Short-circuit if queries are not being saved (aka if SAVEQUERIES is not defined).
		if ( empty( $this->wpdb->queries ) ) {
			return [];
		}

		$before_num_queries = $invocation->get_before_num_queries();

		// If no queries have been made during the hook invocation, short-circuit.
		if ( $this->wpdb->num_queries === $before_num_queries ) {
			return [];
		}

		$query_indices = [];
		foreach ( range( $before_num_queries, $this->wpdb->num_queries - 1 ) as $query_index ) {

			// Flag this query as being associated with this hook instance.
			if ( ! isset( $this->sourced_query_indices[ $query_index ] ) ) {
				$query_indices[] = $query_index;

				$this->sourced_query_indices[ $query_index ] = true;
			}
		}

		return $query_indices;
	}
}
