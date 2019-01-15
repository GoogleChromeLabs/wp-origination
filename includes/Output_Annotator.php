<?php
/**
 * Output_Annotator Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Output_Annotator.
 */
class Output_Annotator {

	/**
	 * Identifier used to signify annotation comments.
	 *
	 * @var string
	 */
	const ANNOTATION_TAG = 'sourcery';

	/**
	 * Instance of Invocation_Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Output_Annotator constructor.
	 *
	 * @param Invocation_Watcher $invocation_watcher Invocation watcher.
	 */
	public function __construct( Invocation_Watcher $invocation_watcher ) {
		$this->invocation_watcher = $invocation_watcher;
	}

	/**
	 * Get placeholder annotation pattern.
	 *
	 * Pattern assumes that regex delimiter will be '#'.
	 *
	 * @return string Pattern.
	 */
	public function get_placeholder_annotation_pattern() {
		return '<!-- (?P<closing>/)?' . static::ANNOTATION_TAG . ' (?P<id>\d+) -->';
	}

	/**
	 * Start.
	 */
	public function start() {

		// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
		ob_start(
			array( $this, 'finish' ),
			null,
			0
		);

		// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		add_filter( 'script_loader_tag', array( $this, 'add_enqueued_script_annotation' ), PHP_INT_MAX, 2 );
		add_filter( 'style_loader_tag', array( $this, 'add_enqueued_style_annotation' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Print annotation placeholder before an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 */
	public function print_before_annotation( Invocation $invocation ) {
		printf( '<!-- %s %d -->', static::ANNOTATION_TAG, $invocation->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Print annotation placeholder after an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 */
	public function print_after_annotation( Invocation $invocation ) {
		printf( '<!-- /%s %d -->', static::ANNOTATION_TAG, $invocation->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Add annotation for an enqueued dependency that was printed.
	 *
	 * @param string $tag      HTML tag.
	 * @param string $handle   Handle.
	 * @param string $type     Type, such as 'enqueued_script' or 'enqueued_style'.
	 * @param string $registry Registry name, such as 'wp_scripts' or 'wp_styles'.
	 * @return string HTML tag with annotation.
	 */
	public function add_enqueued_dependency_annotation( $tag, $handle, $type, $registry ) {
		if ( ! $handle ) {
			return $tag;
		}

		$invocations = $this->invocation_watcher->plugin->dependencies->get_dependency_enqueueing_invocations( $registry, $handle );
		if ( empty( $invocations ) ) {
			return $tag;
		}

		$data = array(
			'type'        => $type,
			'invocations' => wp_list_pluck( $invocations, 'id' ),
		);

		return implode(
			'',
			array(
				$this->invocation_watcher->output_annotator->get_annotation_comment( $data, false ),
				$tag,
				$this->invocation_watcher->output_annotator->get_annotation_comment( $data, true ),
			)
		);
	}

	/**
	 * Add annotation for an enqueued script that was printed.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @return string Script tag.
	 */
	public function add_enqueued_script_annotation( $tag, $handle = null ) {
		return $this->add_enqueued_dependency_annotation( $tag, $handle, 'enqueued_script', 'wp_scripts' );
	}

	/**
	 * Add annotation for an enqueued style that was printed.
	 *
	 * @param string $tag    Style tag.
	 * @param string $handle Handle.
	 * @return string Style tag.
	 */
	public function add_enqueued_style_annotation( $tag, $handle = null ) {
		return $this->add_enqueued_dependency_annotation( $tag, $handle, 'enqueued_style', 'wp_styles' );
	}

	/**
	 * Purge annotations in start tag.
	 *
	 * @param array $start_tag_matches Start tag matches.
	 * @return string Start tag.
	 */
	public function purge_annotations_in_start_tag( $start_tag_matches ) {
		$attributes = preg_replace_callback(
			'#' . static::get_placeholder_annotation_pattern() . '#',
			function( $annotation_matches ) {
				$id = intval( $annotation_matches['id'] );
				if ( isset( $this->finalized_invocations[ $id ] ) ) {
					$this->invocation_watcher->finalized_invocations[ $id ]->intra_tag = true;
				}
				return ''; // Purge since an HTML comment cannot occur in a start tag.
			},
			$start_tag_matches['attrs']
		);

		return '<' . $start_tag_matches['name'] . $attributes . '>';
	}

	/**
	 * Hydrate annotation comments with their invocation details.
	 *
	 * @param array $matches Matches.
	 * @return string Hydrated annotation.
	 */
	public function hydrate_placeholder_annotation( $matches ) {
		$id = intval( $matches['id'] );
		if ( ! isset( $this->invocation_watcher->finalized_invocations[ $id ] ) ) {
			return '';
		}
		$invocation = $this->invocation_watcher->finalized_invocations[ $id ];
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

	/**
	 * Finalize annotations.
	 *
	 * Given this HTML in the buffer:
	 *
	 *     <html data-first="B<A" <!-- sourcery 128 --> data-hello=world <!-- /sourcery 128--> data-second="A>B">.
	 *
	 * The returned string should be:
	 *
	 *     <html data-first="B<A"  data-hello=world  data-second="A>B">.
	 *
	 * @param string $buffer Buffer.
	 * @return string Processed buffer.
	 */
	public function finish( $buffer ) {
		$placeholder_annotation_pattern = static::get_placeholder_annotation_pattern();

		// Match all start tags that have attributes.
		$pattern = join(
			'',
			array(
				'#<',
				'(?P<name>[a-zA-Z0-9_\-]+)',
				'(?P<attrs>\s',
				'(?:' . $placeholder_annotation_pattern . '|[^<>"\']+|"[^"]*+"|\'[^\']*+\')*+', // Attribute tokens, plus annotations.
				')>#s',
			)
		);

		$buffer = preg_replace_callback(
			$pattern,
			array( $this, 'purge_annotations_in_start_tag' ),
			$buffer
		);

		$buffer = preg_replace_callback(
			'#' . $placeholder_annotation_pattern . '#',
			array( $this, 'hydrate_placeholder_annotation' ),
			$buffer
		);

		return $buffer;
	}

}
