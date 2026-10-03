/* eslint-disable no-irregular-whitespace */
/* eslint-disable jsdoc/require-param-type */
/* eslint-disable no-nested-ternary */
/*
 * Pure, JSX-free inline-tag segment logic. Shared by the chip editor
 * (TranslationUnitEditor.jsx) and the standalone node test. Kept as .mjs (ESM)
 * because package.json has no "type":"module" — node would otherwise read a .js
 * here as CommonJS. wp-scripts/webpack resolves the explicit ./…​.mjs import.
 *
 * parseUnit / normalize / serialize / validateStructure are the exact mirror of
 * the PHP UnitStructureGuard — keep them in lockstep.
 */

// ---------- codec-side helpers (pure, mirror of UnitStructureGuard) ----------

export const MARKER_RE = /\{(\/?)(\d+)(\/?)\}/g

/**
 * Parse a unit string into a normalized segment list. Normalized = always
 *  starts/ends with a text segment, and every chip has a text segment on both
 *  sides (possibly empty), so a caret position always exists around any chip.
 * @param str
 */
export function parseUnit( str ) {
	const raw = []
	let last = 0
	MARKER_RE.lastIndex = 0
	let m
	while ( ( m = MARKER_RE.exec( str ) ) ) {
		if ( m.index > last ) {
			raw.push( { type: 'text', value: str.slice( last, m.index ) } )
		}
		const kind = m[ 1 ] === '/' ? 'close' : m[ 3 ] === '/' ? 'void' : 'open'
		raw.push( { type: kind, n: Number( m[ 2 ] ) } )
		last = m.index + m[ 0 ].length
	}
	if ( last < str.length ) {
		raw.push( { type: 'text', value: str.slice( last ) } )
	}
	return normalize( raw )
}

export function normalize( segs ) {
	const out = [ { type: 'text', value: '' } ]
	for ( const s of segs ) {
		if ( s.type === 'text' ) {
			out[ out.length - 1 ].value += s.value
		} else {
			out.push( { ...s } )
			out.push( { type: 'text', value: '' } )
		}
	}
	return out
}

export function serialize( segs ) {
	return segs
		.map( ( s ) =>
			s.type === 'text'
				? s.value
				: s.type === 'open'
				? `{${ s.n }}`
				: s.type === 'close'
				? `{/${ s.n }}`
				: `{${ s.n }/}`
		)
		.join( '' )
}

export const markerKey = ( s ) => `${ s.type }:${ s.n }`

/**
 * Front-end mirror of the server-side guard: multiset equality + nesting.
 * @param segs
 * @param sourceSegs
 */
export function validateStructure( segs, sourceSegs ) {
	const cur = segs.filter( ( s ) => s.type !== 'text' )
	const src = sourceSegs.filter( ( s ) => s.type !== 'text' )

	const countBy = ( list ) => {
		const c = {}
		for ( const s of list ) {
			c[ markerKey( s ) ] = ( c[ markerKey( s ) ] || 0 ) + 1
		}
		return c
	}
	const cm = countBy( cur )
	const sm = countBy( src )

	const missing = []
	const extra = []
	for ( const k of Object.keys( sm ) ) {
		for ( let i = 0; i < sm[ k ] - ( cm[ k ] || 0 ); i++ ) {
			missing.push( k )
		}
	}
	for ( const k of Object.keys( cm ) ) {
		for ( let i = 0; i < cm[ k ] - ( sm[ k ] || 0 ); i++ ) {
			extra.push( k )
		}
	}

	// Nesting: reordering whole pairs is allowed, interleaving is not.
	let nestError = null
	const stack = []
	for ( const s of cur ) {
		if ( s.type === 'open' ) {
			stack.push( s.n )
		} else if ( s.type === 'close' ) {
			if ( stack.length && stack[ stack.length - 1 ] === s.n ) {
				stack.pop()
			} else {
				nestError = s.n
				break
			}
		}
	}
	if ( nestError === null && stack.length ) {
		nestError = stack[ stack.length - 1 ]
	}

	// Empty pairs → warning only (renders e.g. an empty <a></a>).
	const emptyPairs = []
	for ( let i = 0; i + 2 < segs.length; i++ ) {
		if (
			segs[ i ].type === 'open' &&
			segs[ i + 1 ].type === 'text' &&
			segs[ i + 1 ].value.trim() === '' &&
			segs[ i + 2 ].type === 'close' &&
			segs[ i + 2 ].n === segs[ i ].n
		) {
			emptyPairs.push( segs[ i ].n )
		}
	}

	return {
		missing,
		extra,
		nestError,
		emptyPairs,
		ok: missing.length === 0 && extra.length === 0 && nestError === null,
	}
}

// ---------- shared marker helpers (single source of truth) ----------

/**
 * True when a unit string carries codec markers, i.e. it is a composed unit.
 * @param s
 */
export const hasMarkers = ( s ) => /\{\/?\d+\/?\}/.test( s || '' )

/**
 * Drop markers for plain-text display (table cells, placeholders).
 * @param s
 */
export const stripMarkers = ( s ) =>
	( s || '' )
		.replace( /\{\/?\d+\/?\}/g, '' )
		.replace( /\s+/g, ' ' )
		.trim()

// ---------- display-only coincident-stack collapse ----------

/*
 * groupCoincident wraps a coincident open/close stack into ONE render-only
 * 'group' node while the true segment list (state) is untouched. serializeGrouped
 * rebuilds the exact original string from the group's structured fields — it is
 * the round-trip proof used by the test; runtime save always uses serialize() on
 * the true segments, so collapse can never drift the stored string.
 *
 * A stack collapses only when (strict, §3): consecutive open markers (separated
 * only by whitespace-only text), a single plain-text inner payload, and the
 * mirror-reverse closes. Note: because consecutive opens across whitespace-only
 * text are always merged into one flat member list, the spec's "nested sub-run
 * inner" reduces to this same case — any non-whitespace text or extra chip
 * between the last open and first close is a branch and is NOT collapsed.
 */

const isWsText = ( s ) => s && s.type === 'text' && s.value.trim() === ''

/**
 * Try to match a full coincident stack starting at an open marker at index i.
 *  Returns { node, end } (end = index of the last close) or null.
 * @param segs
 * @param i
 */
function tryStack( segs, i ) {
	if ( segs[ i ] && segs[ i ].type === 'open' ) {
		// ok
	} else {
		return null
	}

	const members = []
	const openJoiners = []
	let j = i
	while ( segs[ j ] && segs[ j ].type === 'open' ) {
		members.push( segs[ j ] )
		if (
			isWsText( segs[ j + 1 ] ) &&
			segs[ j + 2 ] &&
			segs[ j + 2 ].type === 'open'
		) {
			openJoiners.push( segs[ j + 1 ] )
			j += 2
		} else {
			break
		}
	}
	const last = j // innermost open

	// Inner must be exactly the single text at last+1, immediately closed.
	const innerText = segs[ last + 1 ]
	if ( ! innerText || innerText.type !== 'text' ) {
		return null
	}
	if ( ! segs[ last + 2 ] || segs[ last + 2 ].type !== 'close' ) {
		return null
	}

	// Match mirror-reverse closes.
	const closers = []
	const closeJoiners = []
	let c = last + 2
	let end = -1
	for ( let k = 0; k < members.length; k++ ) {
		const expectN = members[ members.length - 1 - k ].n
		if (
			! segs[ c ] ||
			segs[ c ].type !== 'close' ||
			segs[ c ].n !== expectN
		) {
			return null
		}
		closers.push( segs[ c ] )
		if ( k < members.length - 1 ) {
			if ( ! isWsText( segs[ c + 1 ] ) ) {
				return null
			}
			closeJoiners.push( segs[ c + 1 ] )
			c += 2
		} else {
			end = c
		}
	}

	// ponytail: §3 — a lone empty pair {n}{/n} is left as raw chips (nothing gained
	// by collapsing, and the empty-pair warning reads it naturally on true segs).
	if ( members.length === 1 && innerText.value.trim() === '' ) {
		return null
	}

	return {
		node: {
			type: 'group',
			members,
			openJoiners,
			inner: innerText,
			closers,
			closeJoiners,
			ids: members.map( ( s ) => s.n ),
		},
		end,
	}
}

export function groupCoincident( segs ) {
	const out = []
	let i = 0
	while ( i < segs.length ) {
		const s = segs[ i ]
		if ( s.type === 'open' ) {
			const st = tryStack( segs, i )
			if ( st ) {
				out.push( st.node )
				i = st.end + 1
				continue
			}
		}
		out.push( s )
		i += 1
	}
	return out
}

/*
 * Fold maximal runs of pure structural markers (lone open/close/void chips and
 * whitespace-only text, i.e. no words and no text-wrapping group) into a single
 * 'hidden' node. These wrap no translatable text — table/div scaffolding — so the
 * editor renders them as a quiet separator instead of a wall of numbered chips.
 * Display-only: the nodes are carried verbatim and serialize to the exact string.
 * A run of only whitespace (no marker) is left as plain text — nothing to hide.
 */
export function hideStructure( tree ) {
	const out = []
	let run = []
	const flush = () => {
		if ( ! run.length ) {
			return
		}
		if ( run.some( ( n ) => n.type !== 'text' ) ) {
			out.push( { type: 'hidden', nodes: run } )
		} else {
			out.push( ...run )
		}
		run = []
	}
	for ( const node of tree ) {
		const structural =
			node.type === 'open' ||
			node.type === 'close' ||
			node.type === 'void' ||
			( node.type === 'text' && node.value.trim() === '' ) ||
			// A merged stack wrapping no words (empty or whitespace-only) is noise too.
			( node.type === 'group' && node.inner.value.trim() === '' )
		if ( structural ) {
			run.push( node )
		} else {
			flush()
			out.push( node )
		}
	}
	flush()
	return out
}

function serializeGroup( g ) {
	let s = ''
	for ( let k = 0; k < g.members.length; k++ ) {
		s += serialize( [ g.members[ k ] ] )
		if ( k < g.members.length - 1 ) {
			s += g.openJoiners[ k ].value
		}
	}
	s += serialize( [ g.inner ] )
	for ( let k = 0; k < g.closers.length; k++ ) {
		s += serialize( [ g.closers[ k ] ] )
		if ( k < g.closers.length - 1 ) {
			s += g.closeJoiners[ k ].value
		}
	}
	return s
}

/**
 * Rebuild the original string from a grouped (and optionally structure-hidden)
 *  tree — the test-only round-trip proof.
 * @param tree
 */
export function serializeGrouped( tree ) {
	return tree
		.map( ( node ) =>
			node.type === 'group'
				? serializeGroup( node )
				: node.type === 'hidden'
				? serializeGrouped( node.nodes )
				: serialize( [ node ] )
		)
		.join( '' )
}
