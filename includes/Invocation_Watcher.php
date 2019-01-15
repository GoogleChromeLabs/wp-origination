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
	 * Instance of Hook_Wrapper.
	 *
	 * @var Hook_Wrapper
	 */
	public $hook_wrapper;

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

		$this->hook_wrapper = new Hook_Wrapper(
			array( $this, 'before_hook' ),
			array( $this, 'after_hook' )
		);
	}

	/**
	 * Start watching.
	 */
	public function start() {
		$this->hook_wrapper->add_all_hook();

		// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
		ob_start(
			array( $this, 'finalize_hook_annotations' ),
			null,
			0
		);

		// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
	}

	/**
	 * Determine whether queries can be shown.
	 *
	 * @return bool Whether to show queries.
	 */
	public function can_show_queries() {
		return ! empty( $this->can_show_queries_callback ) && call_user_func( $this->can_show_queries_callback );
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
		$invocation = new Hook_Invocation( $this, $args );

		$this->invocation_stack[] = $invocation;

		if ( $invocation->is_action() ) {
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
		if ( ! ( $invocation instanceof Hook_Invocation ) ) {
			throw new \Exception( 'Expected popped invocation to be Hook_Invocation' );
		}

		$invocation->finalize();

		// @todo This is not correct. An invocation should only store the start query index and end query index. Actual queries performed by invocation can then be determined by examining children.
		$this->plugin->database->identify_invocation_queries( $invocation );

		if ( $invocation->is_action() ) {
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
		$closing    = ! empty( $matches['closing'] );

		if ( $closing ) {
			$data = array(
				'id' => $invocation->id,
			);
		} else {
			$data = $invocation->data();
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

