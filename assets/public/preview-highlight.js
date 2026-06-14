/**
 * Preview highlighter. When the admin opens "Preview on site" the page is
 * loaded with ?translangs_preview=<lang>&translangs_focus=<key>; the plugin
 * injects window.TranslangsPreview = { selector, text }. We locate that string
 * on the page, outline it, and scroll it into view. Best-effort and read-only.
 */
( function () {
	var cfg = window.TranslangsPreview;
	if ( ! cfg || ( ! cfg.selector && ! cfg.text ) ) {
		return;
	}

	function findByText( needle ) {
		needle = ( needle || '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! needle ) {
			return null;
		}
		var walker = document.createTreeWalker( document.body, NodeFilter.SHOW_TEXT, {
			acceptNode: function ( node ) {
				var p = node.parentElement;
				if ( ! p ) {
					return NodeFilter.FILTER_REJECT;
				}
				var tag = p.tagName;
				if ( tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' ) {
					return NodeFilter.FILTER_REJECT;
				}
				var value = ( node.nodeValue || '' ).replace( /\s+/g, ' ' ).trim();
				return value.indexOf( needle ) !== -1
					? NodeFilter.FILTER_ACCEPT
					: NodeFilter.FILTER_SKIP;
			},
		} );
		var n = walker.nextNode();
		return n ? n.parentElement : null;
	}

	function run() {
		var el = null;
		if ( cfg.selector ) {
			try {
				el = document.querySelector( cfg.selector );
			} catch ( e ) {
				el = null;
			}
		}
		if ( ! el ) {
			el = findByText( cfg.text );
		}
		if ( ! el ) {
			return;
		}
		el.classList.add( 'translangs-focus' );
		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
