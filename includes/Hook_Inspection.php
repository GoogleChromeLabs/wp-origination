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
	 * Inspector.
	 *
	 * @var Hook_Inspector
	 */
	public $inspector;

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
	 * Reflection object for the function.
	 *
	 * @var \ReflectionMethod|\ReflectionFunction
	 */
	public $reflection;

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
	protected $before_num_queries;

	/**
	 * Script handles that were enqueued prior to running the hook callback.
	 *
	 * This is unset when the inspection is finalized.
	 *
	 * @var string[]
	 */
	protected $before_scripts_queue;

	/**
	 * Scripts enqueued during invocation of hook callback.
	 *
	 * @var string[]
	 */
	public $enqueued_scripts;

	/**
	 * Style handles that were enqueued prior to running the hook callback.
	 *
	 * This is unset when the inspection is finalized.
	 *
	 * @var string[]
	 */
	protected $before_styles_queue;

	/**
	 * Styles enqueued during invocation of hook callback.
	 *
	 * @var string[]
	 */
	public $enqueued_styles;

	/**
	 * The indices of the queries in $wpdb->queries that this hook was responsible for.
	 *
	 * @var int[]
	 */
	public $query_indices;

	/**
	 * Constructor.
	 *
	 * @param Hook_Inspector $inspector  Inspector.
	 * @param array          $args       Arguments which are assigned to properties.
	 */
	public function __construct( Hook_Inspector $inspector, $args ) {
		foreach ( $args as $key => $value ) {
			$this->$key = $value;
		}
		$this->inspector  = $inspector;
		$this->start_time = microtime( true );

		$this->before_num_queries   = $this->inspector->get_wpdb()->num_queries;
		$this->before_scripts_queue = $this->inspector->get_scripts_queue();
		$this->before_styles_queue  = $this->inspector->get_styles_queue();
	}

	/**
	 * Get the query count before the hook was invoked.
	 *
	 * @return int Query count.
	 */
	public function get_before_num_queries() {
		return $this->before_num_queries;
	}

	/**
	 * Get the script handles enqueued before the hook callback was invoked.
	 *
	 * @return string[] Script handles.
	 */
	public function get_before_scripts_queue() {
		return $this->before_scripts_queue;
	}

	/**
	 * Get the style handles enqueued before the hook callback was invoked.
	 *
	 * @return string[] Style handles.
	 */
	public function get_before_styles_queue() {
		return $this->before_styles_queue;
	}

	/**
	 * Finalize the inspection.
	 */
	public function finalize() {
		$this->end_time = microtime( true );

		// Flag the queries that were used during this hook.
		$this->query_indices = $this->inspector->identify_hook_queries( $this );

		// Capture the scripts and styles that were enqueued by this hook.
		$this->enqueued_scripts = $this->inspector->identify_enqueued_scripts( $this );
		$this->enqueued_styles  = $this->inspector->identify_enqueued_styles( $this );

		// These are no longer needed after calling identify_queued_scripts and identify_queued_styles, and they just take up memory.
		unset( $this->before_scripts_queue );
		unset( $this->before_styles_queue );
	}

	/**
	 * Get the duration of the hook callback invocation.
	 *
	 * @throws \Exception If end_time was not set.
	 * @return float Duration.
	 */
	public function duration() {
		if ( ! isset( $this->end_time ) ) {
			throw new \Exception( 'Not finalized.' );
		}
		return $this->end_time - $this->start_time;
	}

	/**
	 * Get the queries made during the hook callback invocation.
	 *
	 * @return array|null Queries or null if no queries are being saved (SAVEQUERIES).
	 */
	public function queries() {
		$wpdb = $this->inspector->get_wpdb();
		if ( empty( $wpdb->queries ) || ! isset( $this->query_indices ) ) {
			return null;
		}

		$search = sprintf( '%1$s->%2$s\{closure}, call_user_func_array, ', Hook_Wrapper::class, __NAMESPACE__ );

		$queries = array_map(
			function ( $query_index ) use ( $wpdb, $search ) {
				$query = $wpdb->queries[ $query_index ];

				// Purge references to the hook wrapper from the query call stack.
				$backtrace = explode( ', ', str_replace( $search, '', $query[2] ) );

				return array(
					'sql'       => $query[0],
					'duration'  => floatval( $query[1] ),
					'backtrace' => $backtrace,
					'timestamp' => floatval( $query[2] ),
				);
			},
			$this->query_indices
		);

		return $queries;
	}

	/**
	 * Get the location of the file.
	 *
	 * @return array|null {
	 *     Location information, or null if no location could be identified.
	 *
	 *     @var string               $type The type of location, either core, plugin, mu-plugin, or theme.
	 *     @var string               $name The name of the entity, such as 'twentyseventeen' or 'amp/amp.php'.
	 *     @var \WP_Theme|array|null $data Additional data about the entity, such as the theme object or plugin data.
	 * }
	 */
	public function file_location() {
		return $this->inspector->identify_file_location( $this->source_file );
	}
}
