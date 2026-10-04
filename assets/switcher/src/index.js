/**
 * LingoWP language switcher — progressive enhancement.
 *
 * PHP renders every language as a real link, and the list is visible until
 * this script runs, so a switcher whose JavaScript fails still works. For the
 * dropdown layout this reveals the trigger button, hides the list behind it and
 * positions the open panel with Floating UI. For every layout it records the
 * language-preference cookie on click, when the site allows that cookie.
 */
import {
	autoUpdate,
	computePosition,
	flip,
	offset,
	shift,
	size,
} from '@floating-ui/dom'
import './switcher.css'

const ROOT = '[data-lingowp-switcher]'
const VIEWPORT_PADDING = 8
// Lets the pointer cross the gap between trigger and panel in hover mode.
const HOVER_CLOSE_DELAY = 150

// --- language-preference cookie, any layout -------------------------------

document.addEventListener( 'click', ( event ) => {
	const link = event.target.closest( 'a[data-lingowp-lang]' )
	const root = link && link.closest( ROOT )
	// PHP sets data-cookie only when the admin left the preference cookie on.
	const name = root && root.dataset.cookie
	if ( ! name ) {
		return
	}

	document.cookie =
		`${ name }=${ encodeURIComponent( link.dataset.lingowpLang ) }` +
		'; path=/; max-age=31536000; SameSite=Lax' +
		( window.location.protocol === 'https:' ? '; Secure' : '' )
} )

// --- dropdown ---------------------------------------------------------------

function setupDropdown( root ) {
	const trigger = root.querySelector( '.lingowp-switcher__trigger' )
	const panel = root.querySelector( '.lingowp-switcher__list' )
	if ( ! trigger || ! panel ) {
		return
	}

	const hover = root.dataset.openOn === 'hover'
	let stopAutoUpdate = null
	let closeTimer = 0

	function place() {
		computePosition( trigger, panel, {
			// Fixed escapes headers with overflow: hidden, which would clip
			// an absolutely positioned panel.
			strategy: 'fixed',
			placement: 'bottom-start',
			middleware: [
				offset( 4 ),
				flip( { padding: VIEWPORT_PADDING } ),
				shift( { padding: VIEWPORT_PADDING } ),
				size( {
					padding: VIEWPORT_PADDING,
					apply( { availableHeight, elements } ) {
						elements.floating.style.maxHeight = `${ Math.max(
							0,
							availableHeight
						) }px`
					},
				} ),
			],
		} ).then( ( { x, y } ) => {
			panel.style.left = `${ x }px`
			panel.style.top = `${ y }px`
		} )
	}

	function onOutsidePointer( event ) {
		if ( ! root.contains( event.target ) ) {
			close( false )
		}
	}

	function open() {
		clearTimeout( closeTimer )
		if ( ! panel.hidden ) {
			return
		}
		panel.hidden = false
		trigger.setAttribute( 'aria-expanded', 'true' )
		stopAutoUpdate = autoUpdate( trigger, panel, place )
		document.addEventListener( 'pointerdown', onOutsidePointer )
	}

	function close( restoreFocus ) {
		clearTimeout( closeTimer )
		if ( panel.hidden ) {
			return
		}
		panel.hidden = true
		trigger.setAttribute( 'aria-expanded', 'false' )
		stopAutoUpdate()
		stopAutoUpdate = null
		document.removeEventListener( 'pointerdown', onOutsidePointer )
		if ( restoreFocus ) {
			trigger.focus()
		}
	}

	// Click always works — it is also how touch users open a hover dropdown.
	trigger.addEventListener( 'click', () =>
		panel.hidden ? open() : close( false )
	)

	root.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && ! panel.hidden ) {
			event.preventDefault()
			close( true )
		}
	} )

	// Tabbing out of the switcher closes it.
	root.addEventListener( 'focusout', ( event ) => {
		if ( ! root.contains( event.relatedTarget ) ) {
			close( false )
		}
	} )

	if ( hover ) {
		root.addEventListener( 'pointerenter', ( event ) => {
			if ( event.pointerType === 'mouse' ) {
				open()
			}
		} )
		root.addEventListener( 'pointerleave', ( event ) => {
			if ( event.pointerType === 'mouse' ) {
				closeTimer = setTimeout(
					() => close( false ),
					HOVER_CLOSE_DELAY
				)
			}
		} )
	}

	// Hand the closed state from CSS (.lingowp-js, first paint) to the
	// hidden attribute this script toggles from now on. Set .lingowp-js here
	// too: if this script runs after the <head> fail-safe already removed it
	// (a "delay JavaScript" optimizer, or a CSP blocking the inline flag),
	// switcher.css would otherwise hide the button while the list is hidden
	// — leaving no way to switch language.
	document.documentElement.classList.add( 'lingowp-js' )
	panel.hidden = true
	root.classList.add( 'is-enhanced' )
}

/**
 * Enhance every switcher under `scope` that is not enhanced yet. Safe to call
 * again after a page builder inserts new markup — each root is set up once.
 *
 * ponytail: no teardown; add destroy() when a builder needs to unmount
 * switchers while a dropdown is open.
 *
 * @param {Document|Element} scope
 */
export function init( scope = document ) {
	scope.querySelectorAll( ROOT ).forEach( ( root ) => {
		if ( root.dataset.lingowpReady ) {
			return
		}
		root.dataset.lingowpReady = '1'

		if ( root.classList.contains( 'lingowp-switcher--dropdown' ) ) {
			setupDropdown( root )
		}
	} )
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => init() )
} else {
	init()
}
