<?php
/**
 * Invocation Class.
 *
 * @package   Google\WP_Origination
 * @link      https://github.com/westonruter/wp-origination
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Origination;

/**
 * Class Invocation.
 *
 * @todo Why not let this have the __invoke() method with ArrayAccess?
 */
class Invocation {

	/**
	 * Index.
	 *
	 * An auto-incremented number that indicates the order in which the invocation occurred.
	 *
	 * @var int
	 */
	public $index;

	/**
	 * Whether invocation has been finalized.
	 *
	 * @var bool
	 */
	public $finalized = false;

	/**
	 * Whether the invocation was annotated.
	 *
	 * @var bool
	 */
	public $annotated = false;

	/**
	 * Invocation Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Instance of File_Locator.
	 *
	 * @var File_Locator
	 */
	public $file_locator;

	/**
	 * Instance of Database.
	 *
	 * @var Database
	 */
	public $database;

	/**
	 * Instance of Dependencies.
	 *
	 * @var Dependencies
	 */
	public $dependencies;

	/**
	 * Parent invocation.
	 *
	 * @var Invocation
	 */
	public $parent;

	/**
	 * Children invocations.
	 *
	 * @var Invocation[]
	 */
	public $children = [];

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
	 * Number of queries before function started.
	 *
	 * @var int
	 */
	protected $before_query_index;

	/**
	 * Query index after the invocation finished.
	 *
	 * @var int
	 */
	protected $after_query_index;

	/**
	 * Script handles that were enqueued prior to running the hook callback.
	 *
	 * This is unset when the invocation is finalized.
	 *
	 * @var string[]
	 */
	protected $before_scripts_queue;

	/**
	 * Scripts enqueued during invocation of hook callback.
	 *
	 * @todo Put into a multi-dimensional enqueued_dependencies array?
	 * @var string[]
	 */
	protected $enqueued_scripts = [];

	/**
	 * Style handles that were enqueued prior to running the hook callback.
	 *
	 * This is unset when the invocation is finalized.
	 *
	 * @var string[]
	 */
	protected $before_styles_queue;

	/**
	 * Styles enqueued during invocation of hook callback.
	 *
	 * @todo Before finalized, this could return the current array_diff( wp_styles()->queue, $before_styles_queue ) or call identify_enqueued_styles? Would not be final, however.
	 * @var string[]
	 */
	protected $enqueued_styles = [];

	/**
	 * The indices of the queries in $wpdb->queries that this hook was responsible for.
	 *
	 * @var int[]
	 */
	public $own_query_indices = [];

	/**
	 * Constructor.
	 *
	 * @param Invocation_Watcher $watcher      Watcher.
	 * @param Incrementor        $incrementor  Incrementor.
	 * @param Database           $database     Database.
	 * @param File_Locator       $file_locator File locator.
	 * @param Dependencies       $dependencies Dependencies.
	 * @param array              $args    Arguments which are assigned to properties.
	 */
	public function __construct( Invocation_Watcher $watcher, Incrementor $incrementor, Database $database, File_Locator $file_locator, Dependencies $dependencies, $args ) {
		foreach ( $args as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
		$this->index              = $incrementor->next();
		$this->invocation_watcher = $watcher;
		$this->database           = $database;
		$this->file_locator       = $file_locator;
		$this->dependencies       = $dependencies;
		$this->start_time         = microtime( true );

		$this->before_query_index = $this->database->get_latest_query_index();

		// @todo Better to have some multi-dimensional array structure here?
		$this->before_scripts_queue = $this->dependencies->get_dependency_queue( 'wp_scripts' );
		$this->before_styles_queue  = $this->dependencies->get_dependency_queue( 'wp_styles' );
	}

	/**
	 * Whether this invocation is expected to produce output.
	 *
	 * @todo This is perhaps not relevant in the base class.
	 *
	 * @return bool Whether output is expected.
	 */
	public function can_output() {
		return true;
	}

	/**
	 * Number of queries before function started.
	 *
	 * @return int Query index.
	 */
	public function get_before_query_index() {
		return $this->before_query_index;
	}

	/**
	 * Number of queries after function started.
	 *
	 * The after_query_index won't be set if method is invoked before finalize() is called.
	 *
	 * @return int Query index.
	 */
	public function get_after_query_index() {
		return isset( $this->after_query_index ) ? $this->after_query_index : $this->database->get_latest_query_index();
	}

	/**
	 * Get the script handles enqueued before the hook callback was invoked.
	 *
	 * @todo Combine into get_before_dependencies_queue?
	 * @return string[] Script handles.
	 */
	public function get_before_scripts_queue() {
		return $this->before_scripts_queue;
	}

	/**
	 * Get the enqueued script handles during invocation (excluding child invocations).
	 *
	 * @throws \Exception If called prior to being finalized.
	 * @return string[] Script handles.
	 */
	public function get_enqueued_scripts() {
		if ( ! $this->finalized ) {
			throw new \Exception( 'Not finalized.' );
		}
		return $this->enqueued_scripts;
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
	 * Get the enqueued style handles during invocation (excluding child invocations).
	 *
	 * @throws \Exception If called prior to being finalized.
	 * @return string[] Style handles.
	 */
	public function get_enqueued_styles() {
		if ( ! $this->finalized ) {
			throw new \Exception( 'Not finalized.' );
		}
		return $this->enqueued_styles;
	}

	/**
	 * Finalize the invocation.
	 *
	 * @throws \Exception If the invocation was already finalized.
	 * @param array $args Additional args to merge.
	 */
	public function finalize( $args = array() ) {
		foreach ( $args as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
		if ( $this->finalized ) {
			throw new \Exception( 'Already finalized.' );
		}
		$this->end_time = microtime( true );

		// Flag the queries that were used during this hook.
		$this->after_query_index = $this->database->get_latest_query_index();

		$this->own_query_indices = $this->database->identify_invocation_queries( $this );

		// Capture the scripts and styles that were enqueued by this hook.
		$this->enqueued_scripts = $this->dependencies->identify_enqueued_scripts( $this );
		$this->enqueued_styles  = $this->dependencies->identify_enqueued_styles( $this );

		$this->finalized = true;

		// These are no longer needed after calling identify_queued_scripts and identify_queued_styles, and they just take up memory.
		unset( $this->before_scripts_queue );
		unset( $this->before_styles_queue );
	}

	/**
	 * Get the duration of the hook callback invocation.
	 *
	 * @param bool $own_time Whether to exclude children invocations from the total time.
	 * @return float Duration.
	 */
	public function duration( $own_time = true ) {
		// The end_time won't be set if method is invoked before finalize() is called.
		$end_time = isset( $this->end_time ) ? $this->end_time : microtime( true );

		$duration = $end_time - $this->start_time;

		if ( $own_time ) {
			foreach ( $this->children as $invocation ) {
				$duration -= $invocation->duration( false );
			}
		}
		return $duration;
	}

	/**
	 * Get the indices for the queries that were made for this hook.
	 *
	 * @param bool $own Whether to only return query indices for this invocation and not the children.
	 * @return array Query indices.
	 */
	public function query_indices( $own = true ) {
		$after_query_index = $this->get_after_query_index();

		if ( $this->get_before_query_index() === $after_query_index ) {
			return [];
		}

		$query_indices = $this->own_query_indices;

		if ( $own ) {
			return $query_indices;
		}

		// Recursively gather all children query indices.
		$query_indices = array_merge(
			$query_indices,
			...array_map(
				function ( Invocation $invocation ) {
					return $invocation->query_indices( false );
				},
				$this->children
			)
		);

		sort( $query_indices );
		return $query_indices;
	}

	/**
	 * Get the queries made during the hook callback invocation.
	 *
	 * @param bool $own Whether to only return queries from this invocation and not the children.
	 * @return array|null Queries or null if no queries are being saved (SAVEQUERIES).
	 */
	public function queries( $own = true ) {
		return array_filter(
			array_map(
				function( $query_index ) {
					return $this->database->get_query_by_index( $query_index );
				},
				$this->query_indices( $own )
			)
		);
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
		return $this->file_locator->identify( $this->source_file );
	}

	/**
	 * Get annotation data.
	 *
	 * @return array Data.
	 */
	public function data() {
		$data = [
			'index'    => $this->index,
			'function' => $this->function_name,
			'own_time' => $this->duration( true ),
			'source'   => [],
			'parent'   => $this->parent ? $this->parent->index : null,
			'children' => array_map(
				function( Invocation $invocation ) {
					return $invocation->index;
				},
				$this->children
			),
		];

		if ( ! empty( $this->enqueued_scripts ) ) {
			$data['enqueued_scripts'] = $this->enqueued_scripts;
		}
		if ( ! empty( $this->enqueued_styles ) ) {
			$data['enqueued_styles'] = $this->enqueued_styles;
		}

		if ( $this->source_file ) {
			$data['source']['file'] = $this->source_file;

			$file_location = $this->file_location();
			if ( $file_location ) {
				$data['source']['type'] = $file_location['type'];
				$data['source']['name'] = $file_location['name'];
			}
		} elseif ( $this->reflection ) {
			$data['source']['type'] = 'php';
			$data['source']['name'] = $this->reflection->getExtensionName();
		}

		return $data;
	}
}
