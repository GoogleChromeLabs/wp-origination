<?php
/**
 * Invocation_Watcher Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Invocation_Watcher.
 */
class Invocation_Watcher {

	const ANNOTATION_TAG = 'sourcery';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Callback for whether queries can be displayed.
	 *
	 * @var callback
	 */
	public $can_show_queries_callback = '__return_true';

	/**
	 * Hook stack.
	 *
	 * @var Invocation[]
	 */
	public $invocation_stack = array();

	/**
	 * Processed hooks.
	 *
	 * @var Invocation[]
	 */
	public $finalized_invocations = array();

	/**
	 * Invocation_Watcher constructor.
	 *
	 * @param Plugin $plugin  Plugin instance.
	 * @param array  $options Options.
	 */
	public function __construct( Plugin $plugin, $options ) {
		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}

		$this->plugin = $plugin;
	}

	/**
	 * Determine whether a given hook is an action.
	 *
	 * @param Invocation $invocation Invocation.
	 *
	 * @return bool Whether hook is an action.
	 */
	public function is_action( Invocation $invocation ) {
		return did_action( $invocation->hook_name ) > 0;
	}

	/**
	 * Get hook placeholder annotation pattern.
	 *
	 * Pattern assumes that regex delimiter will be '#'.
	 *
	 * @return string Pattern.
	 */
	public function get_hook_placeholder_annotation_pattern() {
		return '<!-- (?P<closing>/)?' . static::ANNOTATION_TAG . ' (?P<id>\d+) -->';
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
		$invocation = new Invocation( $this, $args );

		$this->invocation_stack[] = $invocation;

		if ( $this->is_action( $invocation ) ) {
			$this->print_before_hook_annotation( $invocation );
		}
	}

	/**
	 * After hook.
	 *
	 * @throws \Exception If the stack was empty, which should not happen.
	 */
	public function after_hook() {
		$invocation = array_pop( $this->invocation_stack );
		if ( ! $invocation ) {
			throw new \Exception( 'Stack was empty' );
		}

		$invocation->finalize();

		$this->plugin->database->identify_invocation_queries( $invocation );

		if ( $this->is_action( $invocation ) ) {
			$this->print_after_hook_annotation( $invocation );
		}

		$this->finalized_invocations[ $invocation->id ] = $invocation;
	}

	/**
	 * Print hook annotation placeholder before an action hook's invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 */
	public function print_before_hook_annotation( Invocation $invocation ) {
		printf( '<!-- %s %d -->', static::ANNOTATION_TAG, $invocation->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Print hook annotation placeholder after an action hook's invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 */
	public function print_after_hook_annotation( Invocation $invocation ) {
		printf( '<!-- /%s %d -->', static::ANNOTATION_TAG, $invocation->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Finalize annotations.
	 *
	 * Given this HTML in the buffer:
	 *
	 *     <html data-first="B<A" <!-- hook 128 --> data-hello=world <!-- /hook 128--> data-second="A>B">.
	 *
	 * The returned string should be:
	 *
	 *     <html data-first="B<A"  data-hello=world  data-second="A>B">.
	 *
	 * @param string $buffer Buffer.
	 * @return string Processed buffer.
	 */
	public function finalize_hook_annotations( $buffer ) {
		$hook_placeholder_annotation_pattern = static::get_hook_placeholder_annotation_pattern();

		// Match all start tags that have attributes.
		$pattern = join(
			'',
			array(
				'#<',
				'(?P<name>[a-zA-Z0-9_\-]+)',
				'(?P<attrs>\s',
				'(?:' . $hook_placeholder_annotation_pattern . '|[^<>"\']+|"[^"]*+"|\'[^\']*+\')*+', // Attribute tokens, plus hook annotations.
				')>#s',
			)
		);

		$buffer = preg_replace_callback(
			$pattern,
			array( $this, 'purge_hook_annotations_in_start_tag' ),
			$buffer
		);

		$buffer = preg_replace_callback(
			'#' . $hook_placeholder_annotation_pattern . '#',
			array( $this, 'hydrate_hook_annotation' ),
			$buffer
		);

		return $buffer;
	}

	/**
	 * Purge hook annotations in start tag.
	 *
	 * @param array $start_tag_matches Start tag matches.
	 * @return string Start tag.
	 */
	public function purge_hook_annotations_in_start_tag( $start_tag_matches ) {
		$attributes = preg_replace_callback(
			'#' . static::get_hook_placeholder_annotation_pattern() . '#',
			function( $hook_matches ) {
				$id = intval( $hook_matches['id'] );
				if ( isset( $this->finalized_invocations[ $id ] ) ) {
					$this->finalized_invocations[ $id ]->intra_tag = true;
				}
				return ''; // Purge since an HTML comment cannot occur in a start tag.
			},
			$start_tag_matches['attrs']
		);

		return '<' . $start_tag_matches['name'] . $attributes . '>';
	}

	/**
	 * Hydrate hook annotation comments with their invocation details.
	 *
	 * @param array $matches Matches.
	 * @return string Hydrated hook annotation.
	 */
	public function hydrate_hook_annotation( $matches ) {
		$id = intval( $matches['id'] );
		if ( ! isset( $this->finalized_invocations[ $id ] ) ) {
			return '';
		}
		$invocation = $this->finalized_invocations[ $id ];

		$closing = ! empty( $matches['closing'] );

		$data = array(
			'id'       => $invocation->id,
			'type'     => $invocation->is_action() ? 'action' : 'filter',
			'name'     => $invocation->hook_name,
			'priority' => $invocation->priority,
			'callback' => $invocation->function_name,
		);
		if ( ! $closing ) {
			$data = array_merge(
				$data,
				array(
					'duration' => $invocation->duration(),
					'source'   => array(
						'file' => $invocation->source_file,
					),
				)
			);

			// Include queries if allowed.
			if ( ! empty( $this->can_show_queries_callback ) && call_user_func( $this->can_show_queries_callback ) ) {
				$queries = $invocation->queries();
				if ( ! empty( $queries ) ) {
					$data['queries'] = $queries;
				}
			}

			if ( ! empty( $invocation->enqueued_scripts ) ) {
				$data['enqueued_scripts'] = $invocation->enqueued_scripts;
			}
			if ( ! empty( $invocation->enqueued_styles ) ) {
				$data['enqueued_styles'] = $invocation->enqueued_styles;
			}

			$file_location = $invocation->file_location();
			if ( $file_location ) {
				$data['source']['type'] = $file_location['type'];
				$data['source']['name'] = $file_location['name'];
			}
		}

		return $this->get_annotation_comment( $data, $closing );
	}

	/**
	 * Get annotation comment.
	 *
	 * @param array $data    Data.
	 * @param bool  $closing Whether the comment is closing.
	 * @return string HTML comment.
	 */
	public function get_annotation_comment( array $data, $closing = false ) {

		// Escape double-hyphens in comment content.
		$json = str_replace(
			'--',
			'\u2D\u2D',
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES )
		);

		return sprintf(
			'<!-- %s' . static::ANNOTATION_TAG . ' %s -->',
			$closing ? '/' : '',
			$json
		);
	}
}

