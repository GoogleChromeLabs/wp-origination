/**
 * For a given DOM node, obtain the stack trace of annotations to reveal the sources for where the node came from.
 *
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 *
 * @param {Node} node DOM Node.
 * @returns {Object[]} Annotations.
 */
export default function identifyNodeSources( node ) {
	const openCommentPrefix = ' sourcery ';
	const closeCommentPrefix = ' /sourcery ';

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

		// Account for IE conditional comments which result in comment nodes that look like:
		// [if lt IE 9]><!-- sourcery {...
		const conditionalCommentOffset = commentText.indexOf( `<!--${openCommentPrefix}` );
		if ( conditionalCommentOffset !== -1 ) {
			commentText = commentText.substr( conditionalCommentOffset + 4 );
		}

		const isOpen = commentText.startsWith( openCommentPrefix );
		const data = JSON.parse( commentText.substr( isOpen ? openCommentPrefix.length : closeCommentPrefix.length ) );
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
