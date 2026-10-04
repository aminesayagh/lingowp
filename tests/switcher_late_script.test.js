/* eslint-env jest */
/**
 * Reproduction for switcher review finding #3 (Codex gpt-6-astra, 2026-10-04).
 *
 * The <head> fail-safe (Switcher::printJsFlag) removes html.lingowp-js on the
 * window load event when the switcher script has not run yet. If the script
 * then runs late anyway (a "delay JavaScript" optimizer, or a CSP that blocks
 * the inline flag but allows the same-origin bundle), it hides the list — and
 * switcher.css hides the button whenever .lingowp-js is missing. Result: no
 * button and no list. The script must restore .lingowp-js itself.
 *
 * Tests the BUILT bundle (what ships), so build first:
 *   npm run build:switcher
 *   npx wp-scripts test-unit-js tests/switcher_late_script.test.js
 */
const fs = require( 'fs' )
const path = require( 'path' )

const BUNDLE = path.join( __dirname, '..', 'assets/switcher/build/switcher.js' )

// Same shape as src/Switcher/templates/switcher.php renders for a dropdown.
const MARKUP = `
<nav id="ls" class="lingowp-switcher lingowp-switcher--dropdown" aria-label="Language" data-lingowp-switcher data-open-on="click">
	<button type="button" class="lingowp-switcher__trigger" aria-expanded="false" aria-controls="ls-list">English</button>
	<ul id="ls-list" class="lingowp-switcher__list">
		<li class="lingowp-switcher__item is-current"><span class="lingowp-switcher__link" aria-current="true">English</span></li>
		<li class="lingowp-switcher__item"><a class="lingowp-switcher__link" href="/fr/" data-lingowp-lang="fr_FR">Français</a></li>
	</ul>
</nav>`

describe( 'switcher script running after the fail-safe removed html.lingowp-js', () => {
	beforeAll( () => {
		// The fail-safe has already fired: no .lingowp-js on <html>.
		document.documentElement.classList.remove( 'lingowp-js' )
		document.body.innerHTML = MARKUP

		// Run the shipped IIFE in this page's global scope; jsdom's
		// readyState is already "complete", so it enhances immediately.
		// eslint-disable-next-line no-eval
		;( 0, eval )( fs.readFileSync( BUNDLE, 'utf8' ) )
	} )

	test( 'the dropdown is enhanced and its list hidden (setup)', () => {
		expect(
			document.getElementById( 'ls' ).classList.contains( 'is-enhanced' )
		).toBe( true )
		expect( document.getElementById( 'ls-list' ).hidden ).toBe( true )
	} )

	test( 'html.lingowp-js is restored, so the CSS keeps the button visible', () => {
		expect(
			document.documentElement.classList.contains( 'lingowp-js' )
		).toBe( true )
	} )
} )
