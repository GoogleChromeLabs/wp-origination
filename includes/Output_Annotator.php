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
	 * Opening annotation type (start tag).
	 */
	const OPEN_ANNOTATION = 0;

	/**
	 * Closing annotation type (end tag).
	 */
	const CLOSE_ANNOTATION = 1;

	/**
	 * Empty annotation type (self-closing).
	 */
	const EMPTY_ANNOTATION = 2;

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
	 * Callback to determine whether to show queries.
	 *
	 * This is called once at shutdown to populate `$show_queries`.
	 *
	 * @var callback
	 */
	public $can_show_queries_callback = '__return_false';

	/**
	 * Whether to show queries.
	 *
	 * @var bool
	 */
	protected $show_queries = false;

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
	 * @param array        $options      Options.
	 */
	public function __construct( Dependencies $dependencies, Incrementor $incrementor, $options ) {
		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}
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
			'<!-- (?P<closing>/)?(?P<type>%s) (?P<index>\d+) -->',
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
		return sprintf( '<!-- %s %d -->', static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG, $invocation->index );
	}

	/**
	 * Print annotation placeholder after an printing invoked callback.
	 *
	 * @param Invocation $invocation Invocation.
	 * @return string After placeholder annotation HTML comment.
	 */
	public function get_after_annotation( Invocation $invocation ) {
		return sprintf( '<!-- /%s %d -->', static::INVOCATION_ANNOTATION_PLACEHOLDER_TAG, $invocation->index );
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

		$index = $this->incrementor->next();

		$this->pending_dependency_annotations[ $index ] = compact( 'handle', 'type', 'registry' );

		return implode(
			'',
			[
				sprintf( '<!-- %s %d -->', static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG, $index ),
				$tag,
				sprintf( '<!-- /%s %d -->', static::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG, $index ),
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
			'__return_empty_string',
			$start_tag_matches['attrs']
		);

		return '<' . $start_tag_matches['name'] . $attributes . '>';
	}

	/**
	 * Hydrate an placeholder annotation.
	 *
	 * @param int    $index   Index.
	 * @param string $type    Type.
	 * @param bool   $closing Closing.
	 * @return string Hydrated annotation.
	 */
	public function hydrate_placeholder_annotation( $index, $type, $closing ) {
		if ( self::DEPENDENCY_ANNOTATION_PLACEHOLDER_TAG === $type ) {
			return $this->hydrate_dependency_placeholder_annotation( $index, $closing );
		} elseif ( self::INVOCATION_ANNOTATION_PLACEHOLDER_TAG === $type ) {
			return $this->hydrate_invocation_placeholder_annotation( $index, $closing );
		}
		return '';
	}

	/**
	 * Hydrate an dependency placeholder annotation.
	 *
	 * @param int  $index   Index.
	 * @param bool $closing Closing.
	 * @return string Hydrated annotation.
	 */
	protected function hydrate_dependency_placeholder_annotation( $index, $closing ) {
		if ( ! isset( $this->pending_dependency_annotations[ $index ] ) ) {
			return '';
		}

		// Determine invocations for this dependency and store for the closing annotation comment.
		if ( ! isset( $this->pending_dependency_annotations[ $index ]['invocations'] ) ) {
			$this->pending_dependency_annotations[ $index ]['invocations'] = wp_list_pluck(
				$this->dependencies->get_dependency_enqueueing_invocations(
					$this->invocation_watcher,
					$this->pending_dependency_annotations[ $index ]['registry'],
					$this->pending_dependency_annotations[ $index ]['handle']
				),
				'index'
			);
		}

		// Remove annotation entirely if there are no invocations (which shouldn't happen).
		if ( empty( $this->pending_dependency_annotations[ $index ]['invocations'] ) ) {
			unset( $this->pending_dependency_annotations[ $index ] );
			return '';
		}

		$data = [
			'index'       => $index,
			'type'        => $this->pending_dependency_annotations[ $index ]['type'],
			'invocations' => $this->pending_dependency_annotations[ $index ]['invocations'],
		];

		return $this->get_annotation_comment( $data, $closing ? self::CLOSE_ANNOTATION : self::OPEN_ANNOTATION );
	}

	/**
	 * Hydrate a dependency placeholder annotation.
	 *
	 * @param int  $index   Index.
	 * @param bool $closing Closing.
	 * @return string Hydrated annotation.
	 */
	protected function hydrate_invocation_placeholder_annotation( $index, $closing ) {
		if ( ! isset( $this->invocation_watcher->invocations[ $index ] ) ) {
			return '';
		}
		$invocation = $this->invocation_watcher->invocations[ $index ];

		if ( $closing ) {
			$data = [
				'index' => $invocation->index,
			];
		} else {
			$data = $invocation->data();

			// Include queries if requested.
			if ( $this->show_queries ) {
				$queries = $invocation->queries( true );
				if ( ! empty( $queries ) ) {
					$data['queries'] = $queries;
				}
			}
		}

		return $this->get_annotation_comment( $data, $closing ? self::CLOSE_ANNOTATION : self::OPEN_ANNOTATION );
	}

	/**
	 * Get annotation comment.
	 *
	 * @param array $data    Data.
	 * @param int   $type    Comment type. Either OPEN_ANNOTATION, CLOSE_ANNOTATION, EMPTY_ANNOTATION.
	 * @return string HTML comment.
	 */
	public function get_annotation_comment( array $data, $type = self::OPEN_ANNOTATION ) {

		if ( ! in_array( $type, [ self::OPEN_ANNOTATION, self::CLOSE_ANNOTATION, self::EMPTY_ANNOTATION ], true ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Wrong annotation type.', 'sourcery' ), '0.1' );
		}

		// Escape double-hyphens in comment content.
		$json = str_replace(
			'--',
			'\u2D\u2D',
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES )
		);

		return sprintf(
			'<!-- %s%s %s %s-->',
			self::CLOSE_ANNOTATION === $type ? '/' : '',
			static::ANNOTATION_TAG,
			$json,
			self::EMPTY_ANNOTATION === $type ? '/' : ''
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
	 * Get the annotation stack for a given DOM node.
	 *
	 * @param \DOMNode $node Target DOM node.
	 * @return array[] Stack of annotation comment data.
	 * @throws \Exception If comments are found to be malformed.
	 */
	public function get_node_annotation_stack( \DOMNode $node ) {
		$stack = [];
		$xpath = new \DOMXPath( $node->ownerDocument );

		$open_prefix  = sprintf( ' %s ', self::ANNOTATION_TAG );
		$close_prefix = sprintf( ' /%s ', self::ANNOTATION_TAG );

		$expr = sprintf(
			'preceding::comment()[ starts-with( ., "%s" ) or starts-with( ., "%s" ) ]',
			$open_prefix,
			$close_prefix
		);

		foreach ( $xpath->query( $expr, $node ) as $comment ) {
			$parsed_comment = $this->parse_annotation_comment( $comment );
			if ( ! is_array( $parsed_comment ) ) {
				continue;
			}

			if ( $parsed_comment['closing'] ) {
				$popped = array_pop( $stack );
				if ( $popped['index'] !== $parsed_comment['data']['index'] ) {
					throw new \Exception( sprintf( 'Comment stack mismatch: saw closing comment %1$d but expected %2$d.', $parsed_comment['data']['index'], $popped['index'] ) );
				}
			} else {
				array_push( $stack, $parsed_comment['data'] );
			}
		}
		return $stack;
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

		$this->show_queries = call_user_func( $this->can_show_queries_callback );

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

		if ( ! preg_match_all( '#' . $placeholder_annotation_pattern . '#', $buffer, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $buffer;
		}

		// Determine all invocations that have been annotated.
		$closing_pos = 1;
		$type_pos    = 2;
		$index_pos   = 3;
		foreach ( $matches as $match ) {
			$type = $match[ $type_pos ][0];
			if ( self::INVOCATION_ANNOTATION_PLACEHOLDER_TAG === $type ) {
				$index = $match[ $index_pos ][0];
				$this->invocation_watcher->invocations[ $index ]->annotated = true;
			}
		}

		// Now hydrate the matching placeholder annotations.
		$offset_differential = 0;
		while ( ! empty( $matches ) ) {
			$match  = array_shift( $matches );
			$length = strlen( $match[0][0] );
			$offset = $match[0][1];

			$hydrated_annotation = $this->hydrate_placeholder_annotation(
				intval( $match[ $index_pos ][0] ),
				$match[ $type_pos ][0],
				! empty( $match[ $closing_pos ][0] )
			);

			// Splice the hydrated annotation into the buffer to replace the placeholder annotation.
			$buffer = substr_replace(
				$buffer,
				$hydrated_annotation,
				$offset + $offset_differential,
				$length
			);

			// Update the offset differential based on the difference in length of the hydration.
			$offset_differential += ( strlen( $hydrated_annotation ) - $length );
		}

		// Finally, amend the response with all remaining invocations that have not been annotated. These do not wrap any output.
		foreach ( $this->invocation_watcher->invocations as $invocation ) {
			if ( ! $invocation->annotated ) {
				$invocation->annotated = true;

				$buffer .= $this->get_annotation_comment( $invocation->data(), self::EMPTY_ANNOTATION );
			}
		}

		return $buffer;
	}

}
