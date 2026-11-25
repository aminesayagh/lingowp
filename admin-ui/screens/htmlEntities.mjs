// Display-only decoding of HTML character codes in board text (issue #11).
// The plugin stores titles texturized as a visitor sees them ("&" is stored
// as "&#038;", "'" as "&#8217;"), and React prints text as-is, so the board
// showed the codes. Only what is SHOWN is decoded — the stored text stays
// byte-identical, because translations are matched against it.
//
// ponytail: numeric codes plus the few names WordPress and HTML extraction
// emit; any other name stays as written. Add names if one shows up raw.
const NAMED = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' }

export function decodeEntities( text ) {
	if ( typeof text !== 'string' || ! text.includes( '&' ) ) {
		return text
	}

	// One pass, so "&amp;#038;" becomes "&#038;" and is not decoded again.
	return text.replace(
		/&(?:#(\d+)|#[xX]([0-9a-fA-F]+)|([a-zA-Z]+));/g,
		( match, dec, hex, name ) => {
			if ( name ) {
				return NAMED[ name ] ?? match
			}
			const code = dec ? parseInt( dec, 10 ) : parseInt( hex, 16 )

			return code > 0 && code <= 0x10ffff
				? String.fromCodePoint( code )
				: match
		}
	)
}
