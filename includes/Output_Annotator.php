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
	 * Identifier used to signify invocation annotation comments.
	 *
	 * @var string
	 */
	const INVOCATION_ANNOTATION_PLACEHOLDER_TAG = 'sourcery_invocation';

	/**
	 * Identifier used to signify dependency (scripts & styles) annotation comments.
	 *
	 * @var string
	 */
	const DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG = 'sourcery_dependency';

	/**
	 * Instance of Invocation_Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Instance of Dependencies.
	 *
	 * @var Dependencies
	 */
	public $dependencies;

	/**
	 * Instance of Incrementor.
	 *
	 * @var Incrementor
	 */
	public $incrementor;

	/**
	 * Pending dependency annotations.
	 *
	 * @var array
	 */
	protected $pending_dependency_annotations = [];

	/**
	 * Output_Annotator constructor.
	 *
	 * @param Dependencies $dependencies Dependencies.
	 * @param Incrementor  $incrementor  Incrementor.
	 */
	public function __construct( Dependencies $dependencies, $incrementor ) {
		$this->dependencies = $dependencies;
		$this->incrementor  = $incrementor;
	}

	/**
	 * Set invocation watcher.
	 *
	 * @param Invocation_Watcher $invocation_watcher Invocation watcher.
	 */
	public function set_invocation_watcher( Invocation_Watcher $invocation_watcher ) {
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
		return sprintf(
			'<!-- (?P<closing>/)?(?P<type>%s) (?P<id>\d+) -->',
			static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG . '|' . static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG
		);
	}

	/**
	 * Start.
	 *
	 * @param bool $lock_buffer Whether buffer is locked (can be flushed/erased/cancelled).
	 */
	public function start( $lock_buffer = true ) {

		// Output buffer so that Server-Timing headers can be sent, and prevent plugins from flushing it.
		ob_start(
			[ $this, 'finish' ],
			null,
			$lock_buffer ? 0 : PHP_OUTPUT_HANDLER_STDFLAGS
		);

		// Prevent PHP Notice: ob_end_flush(): failed to send buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		add_filter( 'script_loader_tag', [ $this, 'add_enqueued_script_annotation' ], PHP_INT_MAX, 2 );
		add_filter( 'style_loader_tag', [ $this, 'add_enqueued_style_annotation' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Print annotation placeholder before an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 * @return string Before placeholder annotation HTML comment.
	 */
	public function get_before_annotation( Invocation $invocation ) {
		return sprintf( '<!-- %s %d -->', static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG, $invocation->id );
	}

	/**
	 * Print annotation placeholder after an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 * @return string After placeholder annotation HTML comment.
	 */
	public function get_after_annotation( Invocation $invocation ) {
		return sprintf( '<!-- /%s %d -->', static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG, $invocation->id );
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
		// Abort if filter has been applied without passing all required arguments.
		if ( ! $handle ) {
			return $tag;
		}

		/*
		 * Abort if this is a stylesheet that has conditional comments, as adding comments will cause them to be nested,
		 * which is not allowed for comments. Also, styles that are inside conditional comments are mostly pointless
		 * to identify the source of since they area dead and won't impact the page for any modern browsers.
		 */
		if ( 'wp_styles' === $registry && wp_styles()->get_data( $handle, 'conditional' ) ) {
			return $tag;
		}

		$id = $this->incrementor->next();

		$this->pending_dependency_annotations[ $id ] = compact( 'handle', 'type', 'registry' );

		return implode(
			'',
			[
				sprintf( '<!-- %s %d -->', static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG, $id ),
				$tag,
				sprintf( '<!-- /%s %d -->', static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG, $id ),
			]
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
					$this->invocation_watcher->invocations[ $id ]->intra_tag = true;
				}
				return ''; // Purge since an HTML comment cannot occur in a start tag.
			},
			$start_tag_matches['attrs']
		);

		return '<' . $start_tag_matches['name'] . $attributes . '>';
	}

	/**
	 * Hydrate annotation placeholder comments with their details.
	 *
	 * @param array $matches Matches.
	 * @return string Hydrated annotation.
	 */
	public function hydrate_placeholder_annotation( $matches ) {
		$id      = intval( $matches['id'] );
		$closing = ! empty( $matches['closing'] );

		if ( self::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG === $matches['type'] ) {
			if ( ! isset( $this->pending_dependency_annotations[ $id ] ) ) {
				return '';
			}

			// Determine invocations for this dependency and store for the closing annotation comment.
			if ( ! isset( $this->pending_dependency_annotations[ $id ]['invocations'] ) ) {
				$this->pending_dependency_annotations[ $id ]['invocations'] = wp_list_pluck(
					$this->dependencies->get_dependency_enqueueing_invocations(
						$this->invocation_watcher,
						$this->pending_dependency_annotations[ $id ]['registry'],
						$this->pending_dependency_annotations[ $id ]['handle']
					),
					'id'
				);
			}

			// Remove annotation entirely if there are no invocations (which shouldn't happen).
			if ( empty( $this->pending_dependency_annotations[ $id ]['invocations'] ) ) {
				unset( $this->pending_dependency_annotations[ $id ] );
				return '';
			}

			$data = [
				'id'          => $id,
				'type'        => $this->pending_dependency_annotations[ $id ]['type'],
				'invocations' => $this->pending_dependency_annotations[ $id ]['invocations'],
			];

			return $this->get_annotation_comment( $data, $closing );
		} elseif ( self::INVOCATION_ANNOTATION_PLACEHOLDER_TAG === $matches['type'] ) {
			if ( ! isset( $this->invocation_watcher->invocations[ $id ] ) ) {
				return '';
			}
			$invocation = $this->invocation_watcher->invocations[ $id ];

			if ( $closing ) {
				$data = [
					'id' => $invocation->id,
				];
			} else {
				$data = $invocation->data();
			}

			return $this->get_annotation_comment( $data, $closing );
		}
		return '';
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
	 * Parse data out of sourcery annotation comment text.
	 *
	 * @param string|\DOMComment $comment Comment.
	 * @return null|array {
	 *     Parsed comment. Returns null on parse error.
	 *
	 *     @type bool  $closing Closing.
	 *     @type array $data    Data.
	 * }
	 */
	public function parse_annotation_comment( $comment ) {
		if ( $comment instanceof \DOMComment ) {
			$comment = $comment->nodeValue;
		}
		$pattern = sprintf(
			'#^ (?P<closing>/)?%s (?P<json>{.+}) $#s',
			preg_quote( static::ANNOTATION_TAG, '#' )
		);
		if ( ! preg_match( $pattern, $comment, $matches ) ) {
			return null;
		}
		$closing = ! empty( $matches['closing'] );
		$data    = json_decode( $matches['json'], true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return compact( 'closing', 'data' );
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

		// Make sure that all open invocations get closed, which can happen when an exit is done in a hook callback.
		while ( ! empty( $this->invocation_watcher->invocation_stack ) ) {
			$invocation = array_pop( $this->invocation_watcher->invocation_stack );
			if ( $invocation->can_output() ) {
				$buffer .= $this->get_after_annotation( $invocation );
			}
		}

		// Match all start tags that have attributes.
		$pattern = join(
			'',
			[
				'#<',
				'(?P<name>[a-zA-Z0-9_\-]+)',
				'(?P<attrs>\s',
				'(?:' . $placeholder_annotation_pattern . '|[^<>"\']+|"[^"]*+"|\'[^\']*+\')*+', // Attribute tokens, plus annotations.
				')>#s',
			]
		);

		$buffer = preg_replace_callback(
			$pattern,
			[ $this, 'purge_annotations_in_start_tag' ],
			$buffer
		);

		$buffer = preg_replace_callback(
			'#' . $placeholder_annotation_pattern . '#',
			[ $this, 'hydrate_placeholder_annotation' ],
			$buffer
		);

		return $buffer;
	}

}
