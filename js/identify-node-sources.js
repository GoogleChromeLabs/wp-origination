/**
 * For a given DOM node, obtain the stack trace of annotations to reveal the sources for where the node came from.
 *
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 *
 * @param {Node} node DOM Node.
 * @returns {Object[]} Annotations.
 */
export default function identifyNodeSources( node ) {
	const openCommentPrefix = ' origination ';
	const closeCommentPrefix = ' /origination ';

	const invocations = {};
	const expression = `
		preceding::comment()[
			starts-with( ., "${openCommentPrefix}" )
			or
			starts-with( ., "${closeCommentPrefix}" )
		]`;
	const xPathResult = document.evaluate( expression, node, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null );
	const annotationStack = [];
	for ( let i = 0; i < xPathResult.snapshotLength; i++ ) {
		let commentText = xPathResult.snapshotItem( i ).nodeValue;

		const isOpen = commentText.startsWith( openCommentPrefix );
		const isSelfClosing = commentText.endsWith( '/' );

		commentText = commentText.substr( isOpen ? openCommentPrefix.length : closeCommentPrefix.length );
		if ( isSelfClosing ) {
			commentText = commentText.replace( /\/$/, '' );
		}

		const data = JSON.parse( commentText );
		if ( isOpen ) {
			if ( data.index ) {
				invocations[ data.index ] = data;
			}
			if ( data.invocations ) {
				for ( const index of data.invocations ) {
					annotationStack.push( invocations[ index ] );
				}
			} else {
				annotationStack.push( data );
			}

			// Turn around and pop right away when self-closing.
			if ( isSelfClosing ) {
				annotationStack.pop();
			}
		} else {
			if ( data.invocations ) {
				for ( const index of [...data.invocations].reverse() ) {
					const popped = annotationStack.pop();
					if ( index !== popped.index ) {
						throw new Error( 'Unexpected closing annotation comment for ref: ' + commentText );
					}
				}
			} else {
				const popped = annotationStack.pop();
				if ( data.index !== popped.index ) {
					throw new Error( 'Unexpected closing annotation comment: ' + commentText );
				}
			}
		}
	}
	return annotationStack;
}
