/**
 * LingoWP language switcher — progressive enhancement.
 *
 * The dropdown works without this file: it is a native <details>. This adds the
 * niceties users expect from a menu — aria-expanded, Escape / click-outside to
 * close, arrow-key roving — and records the language-preference cookie on click
 * for every layout. Navigation never depends on any of it.
 */
;( function () {
	'use strict'

	// Mirror of ResolveRequestLanguage::COOKIE_NAME (PHP).
	const COOKIE = 'preferred_lang'

	// --- language-preference cookie, any layout -----------------------------
	document.addEventListener( 'click', function ( e ) {
		const a = e.target.closest( 'a[data-lingowp-lang]' )
		if ( ! a ) {
			return
		}
		const lang = a.getAttribute( 'data-lingowp-lang' )
		if ( lang ) {
			document.cookie =
				COOKIE +
				'=' +
				encodeURIComponent( lang ) +
				'; path=/; max-age=31536000; SameSite=Lax'
		}
	} )

	// --- dropdown enhancement ---------------------------------------------
	let open = [] // currently-open <details> elements

	function links( details ) {
		return Array.prototype.slice.call(
			details.querySelectorAll( '.lingowp-switcher__link' )
		)
	}

	function enhance( nav ) {
		const details = nav.querySelector( '.lingowp-switcher__details' )
		const summary = nav.querySelector( '.lingowp-switcher__current' )
		if ( ! details || ! summary ) {
			return
		}

		summary.setAttribute( 'aria-expanded', details.open ? 'true' : 'false' )

		details.addEventListener( 'toggle', function () {
			summary.setAttribute(
				'aria-expanded',
				details.open ? 'true' : 'false'
			)
			open = open.filter( function ( d ) {
				return d !== details
			} )
			if ( details.open ) {
				open.push( details )
			}
		} )

		nav.addEventListener( 'keydown', function ( e ) {
			const items = links( details )
			const i = items.indexOf( nav.ownerDocument.activeElement )

			if ( e.key === 'Escape' && details.open ) {
				details.open = false
				summary.focus()
			} else if ( e.key === 'ArrowDown' && ! details.open ) {
				e.preventDefault()
				details.open = true
				if ( items[ 0 ] ) {
					items[ 0 ].focus()
				}
			} else if ( e.key === 'ArrowDown' && i > -1 ) {
				e.preventDefault()
				;( items[ i + 1 ] || items[ 0 ] ).focus()
			} else if ( e.key === 'ArrowUp' && i > -1 ) {
				e.preventDefault()
				;( items[ i - 1 ] || items[ items.length - 1 ] ).focus()
			}
		} )
	}

	document.addEventListener( 'click', function ( e ) {
		open.slice().forEach( function ( details ) {
			if ( ! details.contains( e.target ) ) {
				details.open = false
			}
		} )
	} )

	function init() {
		const navs = document.querySelectorAll(
			'.lingowp-switcher--dropdown[data-lingowp-switcher]'
		)
		Array.prototype.forEach.call( navs, enhance )
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init )
	} else {
		init()
	}
} )()
