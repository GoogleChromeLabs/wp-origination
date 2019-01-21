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
	 * @param wpdb $wpdb WordPress Database.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
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
	 * Get index of the most recently-made query.
	 *
	 * @return int Index for the
	 */
	public function get_latest_query_index() {
		if ( 0 === $this->wpdb->num_queries ) {
			return 0;
		}
		return $this->wpdb->num_queries - 1;
	}

	/**
	 * Get a query by index.
	 *
	 * This depends on the SAVEQUERIES constant being set.
	 *
	 * @param int $index Query index for array item in `$wpdb->queries`.
	 * @return null|array {
	 *     Query information, or null if no query exists for the index.
	 *
	 *     @type string   $sql       SQL.
	 *     @type float    $duration  Duration the query took to run.
	 *     @type string[] $backtrace Backtrace of PHP calls for the query.
	 *     @type float    $timestamp Timestamp for the query.
	 * }
	 */
	public function get_query_by_index( $index ) {
		if ( ! isset( $this->wpdb->queries[ $index ] ) ) {
			null;
		}

		$query = $this->wpdb->queries[ $index ];

		// Purge references to the hook wrapper from the query call stack.
		$search    = sprintf( '%1$s->%2$s\{closure}, call_user_func_array, ', Hook_Wrapper::class, __NAMESPACE__ );
		$backtrace = explode( ', ', str_replace( $search, '', $query[2] ) );

		return [
			'sql'       => $query[0],
			'duration'  => floatval( $query[1] ),
			'backtrace' => $backtrace,
			'timestamp' => floatval( $query[3] ),
		];
	}

	/**
	 * Identify the queries that were made during the invocation.
	 *
	 * @param Invocation $invocation Invocation.
	 * @return int[] Query indices associated with the hook.
	 */
	public function identify_invocation_queries( Invocation $invocation ) {

		// Short-circuit if queries are not being saved (aka if SAVEQUERIES is not defined).
		if ( empty( $this->wpdb->queries ) ) {
			return [];
		}

		$latest_query_index = $this->get_latest_query_index();

		// If no queries have been made during the hook invocation, short-circuit.
		if ( $latest_query_index === $invocation->before_query_index ) {
			return [];
		}

		$query_indices = [];
		foreach ( range( $invocation->before_query_index, $latest_query_index ) as $query_index ) {

			// Flag this query as being associated with this hook instance.
			if ( ! isset( $this->sourced_query_indices[ $query_index ] ) ) {
				$query_indices[] = $query_index;

				$this->sourced_query_indices[ $query_index ] = true;
			}
		}

		return $query_indices;
	}
}
