_Note: This repo is now archived since it hasn't been touched in awhile. It may get resurrected, but it is archived now in order to not give false promises of maintenance._

# Origination

_Determine the origin of where things come from in WordPress whether slow code, inefficient queries, or bad markup._

[![Build Status](https://api.travis-ci.org/GoogleChromeLabs/wp-origination.svg?branch=develop)](https://travis-ci.org/GoogleChromeLabs/wp-origination)

<!-- WP_README_DESCRIPTION -->

There are a lot of plugins and themes in the WordPress ecosystem. Many of them are not written with performance in mind, either in the database queries they make or the markup they add to the frontend. Also, because themes and plugins can hook into any part of the WordPress execution lifecycle it is difficult to identify which is responsible for a performance problem. This is what the Origination plugin assists with: it identifies the origin for where things come from in WordPress whether slow code, inefficient queries, or bad markup.

Most of the ideas in this plugin were first prototyped in the [AMP plugin](https://github.com/ampproject/amp-wp). It includes a sanitizer to remove any markup that is not valid, but it also needs to inform the site owner of where the offending markup came from in the first place. In order to provide this information, the AMP plugin adds wrappers around actions, filters, blocks, shortcodes, widgets, and embeds to annotate the output with HTML comments both preceding and following, similar to Gutenberg block comments. With these HTML comment annotations in place, when invalid AMP markup is encountered, the source(s) responsible for it can be determined by just looking all of the annotation comments that are open.

This plugin takes the ideas from the AMP plugin and generalizes them for use by other plugins, including the AMP plugin itself. One key use for these HTML source annotations is for Lighthouse to be able to indicate the theme/plugin that is responsible for problems identified by audits, whether they be for performance, accessibility, best practices, or SEO.

<!-- /WP_README_DESCRIPTION -->

Bonus: This plugin also outputs `Server-Timing` headers to tally how much time was spent on core, themes, and plugins.

In order to invoke the plugin's behavior, first enable `WP_DEBUG` mode in the `wp-config.php`; also enable `SAVEQUERIES` if you want to include the SQL queries performed (which will be displayed if you are an admin).

Then access a site frontend with `?origination` in the URL. For example, `https://example.com/2019/08/07/foo/?origination`. This will then cause the plugin to annotate the page output with Gutenberg-inspired HTML comments to annotate WordPress's execution. You'll see comments like the following (here with some added formatting):

```html
<!-- origination
{
    "callback": "rel_canonical",
    "duration": 0.0026450157165527344,
    "index": 242,
    "name": "wp_head",
    "priority": 10,
    "source": {
        "file": "/app/public/core-dev/src/wp-includes/link-template.php",
        "name": "wp-includes",
        "type": "core"
    }
}
-->
<link rel="canonical" href="https://example.com/2019/08/07/foo/" />
<!-- /origination {"index":242,"name":"wp_head","priority":10,"callback":"rel_canonical"} -->
```

With such annotation comments in place, to determine annotation stack for a given DOM node import [identify-node-sources.js](https://github.com/GoogleChromeLabs/wp-origination/blob/develop/js/identify-node-sources.js) you then select a node in DevTools and then paste the following JS code into the console (see second screenshot):

```js
(( node ) => {
    import( '/content/plugins/origination/js/identify-node-sources.js' ).then(
        ( module ) => {
            console.info( module.default( node ) );
        }
    );
})( $0 );
```

While this is also sent via `Server-Timing` headers, you can determine the amount of time spent by core, theme, and plugins with the following JS code:

```js
(() => {
    const durations = {};
    const openCommentPrefix = ' origination ';
    const expression = `//comment()[ starts-with( ., "${openCommentPrefix}" ) ]`;
    const result = document.evaluate( expression, document, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null );

    for ( let i = 0; i < result.snapshotLength; i++ ) {
        const commentText = result.snapshotItem( i ).nodeValue;
        const data = JSON.parse( commentText.substr( openCommentPrefix.length ).replace( /\/$/, '' ) );
        if ( ! data.source ) {
            continue;
        }

        const key = ( data.source.type || 'unknown' ) + ':' + ( data.source.name || 'unknown' );
        if ( ! ( key in durations ) ) {
            durations[ key ] = 0.0;
        }
        durations[ key ] += data.own_time;

    }
    return durations;
})();
```

### Contributing ###

You can [contribute](https://github.com/GoogleChromeLabs/wp-origination/blob/develop/CONTRIBUTING.md) to this plugin via its [GitHub project](https://github.com/GoogleChromeLabs/wp-origination).

This is not an officially supported Google product.

## Screenshots ##

### Server-Timing headers are sent when running.

![Server-Timing headers are sent when running.](wp-assets/screenshot-1.png)

### Determine where markup in the page comes from.

![Determine where markup in the page comes from.](wp-assets/screenshot-2.png)

### Identifying everything that depends on jQuery.

![Identifying everything that depends on jQuery.](wp-assets/screenshot-3.png)
