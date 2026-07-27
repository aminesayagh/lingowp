/**
 * Preview highlighter. When the admin opens "Preview on site" the page is
 * loaded with ?translangs_preview=<lang>&translangs_focus=<key>; the plugin
 * injects window.TranslangsPreview = { selector, text }. We locate that string
 * on the page, outline every occurrence, and scroll the first into view.
 * Best-effort and read-only.
 */
( function () {
	var cfg = window.TranslangsPreview;
	if ( ! cfg || ( ! cfg.selector && ! cfg.text ) ) {
		return;
	}

	// WordPress wptexturize() turns straight quotes/dashes in rendered content into
	// curly/typographic characters, so the DB's straight-quote text won't match the
	// page literally. Fold both sides back to plain ASCII + lowercase before comparing.
	function clean( s ) {
		return ( s || '' )
			.replace( /[‘’‚′]/g, "'" )
			.replace( /[“”„″]/g, '"' )
			.replace( /[–—]/g, '-' )
			.replace( /…/g, '...' )
			.replace( / /g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim()
			.toLowerCase();
	}

	function findAllByText( needle ) {
		needle = clean( needle );
		if ( ! needle ) {
			return [];
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
				var value = clean( node.nodeValue );
				return value.indexOf( needle ) !== -1
					? NodeFilter.FILTER_ACCEPT
					: NodeFilter.FILTER_SKIP;
			},
		} );
		var els = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
			if ( node.parentElement && els.indexOf( node.parentElement ) === -1 ) {
				els.push( node.parentElement );
			}
		}
		return els;
	}

	function collect( into, found ) {
		found.forEach( function ( el ) {
			if ( into.indexOf( el ) === -1 ) {
				into.push( el );
			}
		} );
		return into;
	}

	function run() {
		// 1. HTML templates carry an exact CSS selector.
		if ( cfg.selector ) {
			var bySel = [];
			try {
				bySel = Array.prototype.slice.call( document.querySelectorAll( cfg.selector ) );
			} catch ( e ) {
				bySel = [];
			}
			if ( bySel.length ) {
				highlight( bySel );
				return;
			}
		}

		if ( ! cfg.text ) {
			return;
		}

		// Post/term/html text is stored as a unit with {n}/{/n}/{n/} placeholders for
		// inline tags; the rendered page has the real text, not the markers.
		var els = [];

		// 2. Whole text in one node (titles, plain paragraphs, single-wrapper units).
		var whole = cfg.text.replace( /\{\/?\d+\/?\}/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		if ( whole ) {
			els = findAllByText( whole );
		}

		// 3. Composite unit split across inline tags: highlight each text segment that
		//    sat between placeholders (e.g. "{1}A{/1} {2}B{/2}" -> "A", "B").
		if ( ! els.length ) {
			cfg.text.split( /\{\/?\d+\/?\}/ ).forEach( function ( seg ) {
				seg = seg.replace( /\s+/g, ' ' ).trim();
				if ( seg.length >= 2 ) {
					els = collect( els, findAllByText( seg ) );
				}
			} );
		}

		if ( els.length ) {
			highlight( els );
		}
	}

	function highlight( els ) {
		els.forEach( function ( el ) {
			el.classList.add( 'translangs-focus' );
		} );
		els[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
