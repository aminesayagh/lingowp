import {
	Fragment,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element'
import {
	parseUnit,
	normalize,
	serialize,
	validateStructure,
	groupCoincident,
	hideStructure,
	hasMarkers,
	stripMarkers,
} from './inlineTagCodec.mjs'

// Re-exported so existing importers (CompareOverlay.jsx, Dashboard.jsx) keep
// resolving these from "./TranslationUnitEditor".
export { hasMarkers, stripMarkers }

/*
 * Inline-tag token-chip editor (Option A, v1), split into the two pieces the
 * text-editor overlay composes: SourceChips (read-only, left column) and
 * ChipField (editable, right column).
 *
 * The stored format is untouched: this parses the codec's unit string
 * ("See {1}this {2}word{/2}{/1} now") into segments, renders {n} markers as
 * atomic chips, and re-serializes to the exact same string. onChange fires with
 * (serialized, isValid) so the host can gate autosave.
 *
 * The pure functions below (parseUnit / normalize / serialize /
 * validateStructure) are the exact mirror of the PHP UnitStructureGuard — keep
 * them in lockstep. v1 cuts: arrow-move, named-chip descriptors (falls back to
 * #n), backend stack-merge.
 */

// ---------- presentation helpers ----------

// ponytail: fixed light-tint palette — wp-admin renders Polaris light-only, so
// no dark-theme token wiring is needed. Add token-based hues if dark mode lands.
const HUES = [
	{ bg: '#e0f2fe', border: '#38bdf8', text: '#075985' },
	{ bg: '#fef3c7', border: '#fbbf24', text: '#92400e' },
	{ bg: '#d1fae5', border: '#34d399', text: '#065f46' },
	{ bg: '#ede9fe', border: '#a78bfa', text: '#5b21b6' },
	{ bg: '#ffe4e6', border: '#fb7185', text: '#9f1239' },
	{ bg: '#cffafe', border: '#22d3ee', text: '#155e75' },
]
const hueFor = ( n ) => HUES[ ( n - 1 ) % HUES.length ]

const TAG_LABELS = {
	a: 'link',
	strong: 'bold',
	b: 'bold',
	em: 'italic',
	i: 'italic',
	span: 'span',
	code: 'code',
	img: 'image',
	br: 'break',
	cite: 'cite',
	abbr: 'abbr',
	sup: 'sup',
	sub: 'sub',
	mark: 'mark',
	u: 'underline',
}
const labelFor = ( tags, n ) => {
	const t = tags?.[ n ]?.tag
	return t ? TAG_LABELS[ t ] || t : `#${ n }`
}
const chipText = ( seg, tags ) => {
	const base = labelFor( tags, seg.n )
	if ( seg.type === 'close' ) {
		return `/${ base }`
	}
	return base
}

// Label for a collapsed coincident stack. Descriptor-aware, but tag names are
// not wired to the editor yet (see plan) — so it falls back to a short neutral
// "tag" and upgrades automatically if `tags` lands. No count suffix.
const groupLabel = ( group, tags ) => {
	const names = group.ids.map( ( n ) =>
		tags?.[ n ]?.tag ? TAG_LABELS[ tags[ n ].tag ] || tags[ n ].tag : null
	)
	if ( names.length && names.every( Boolean ) ) {
		return names.length <= 2 ? names.join( '+' ) : names[ 0 ]
	}
	return 'tag'
}
const groupTitle = ( group, tags ) => {
	const path = group.ids
		.map( ( n ) =>
			tags?.[ n ]?.tag
				? TAG_LABELS[ tags[ n ].tag ] || tags[ n ].tag
				: `#${ n }`
		)
		.join( ' › ' )
	return `${ path } — formatting stack, keep it wrapping this text`
}

// Count the real markers in a hidden run (a folded empty group carries several).
const markerCount = ( nodes ) =>
	nodes.reduce( ( count, node ) => {
		if ( node.type === 'group' ) {
			return count + node.members.length + node.closers.length
		}
		return node.type === 'text' ? count : count + 1
	}, 0 )

// A quiet separator standing in for a run of pure layout markers (table/div
// scaffolding that wraps no words). The markers are kept in state and serialized
// verbatim — just not shown, so the translator sees the words, not the wall.
function HiddenStructure( { count } ) {
	return (
		<span
			aria-hidden="true"
			title={ `${ count } layout tag${
				count === 1 ? '' : 's'
			} here — kept, not shown` }
			style={ {
				display: 'inline-block',
				margin: '0 1px',
				color: 'var(--p-color-text-subdued)',
				opacity: 0.5,
				userSelect: 'none',
			} }
		>
			·
		</span>
	)
}

const chipBaseStyle = ( hue ) => ( {
	all: 'unset',
	boxSizing: 'border-box',
	display: 'inline-flex',
	alignItems: 'center',
	gap: '4px',
	margin: '2px',
	padding: '0 6px',
	height: '20px',
	borderRadius: '6px',
	border: `1px solid ${ hue.border }`,
	background: hue.bg,
	color: hue.text,
	fontSize: '11px',
	fontFamily: 'var(--p-font-family-mono, monospace)',
	lineHeight: '18px',
	userSelect: 'none',
	verticalAlign: 'middle',
} )

// ---------- editable text segment (inline, wrapping) ----------

const getCaretOffset = ( el ) => {
	const sel = el?.ownerDocument.defaultView.getSelection()
	if (
		! el ||
		! sel ||
		! sel.rangeCount ||
		! el.contains( sel.anchorNode )
	) {
		return el ? el.textContent.length : 0
	}
	return sel.getRangeAt( 0 ).startOffset
}
const caretFromPoint = ( el, x, y ) => {
	let container
	let offset
	if ( document.caretRangeFromPoint ) {
		const r = document.caretRangeFromPoint( x, y )
		if ( r ) {
			;( { startContainer: container, startOffset: offset } = r )
		}
	} else if ( document.caretPositionFromPoint ) {
		const p = document.caretPositionFromPoint( x, y )
		if ( p ) {
			;( { offsetNode: container, offset } = p )
		}
	}
	return container && el.contains( container )
		? offset
		: el.textContent.length
}

// Drop the caret at the start/end of an EditableText span — used to hop
// between segments on ArrowLeft/ArrowRight (see ChipField's jumpToNeighbor).
const placeCaret = ( el, atStart ) => {
	el.focus()
	const sel = el.ownerDocument.defaultView.getSelection()
	if ( ! sel ) {
		return
	}
	const range = el.ownerDocument.createRange()
	const node = el.firstChild
	if ( node ) {
		range.setStart( node, atStart ? 0 : node.textContent.length )
	} else {
		range.setStart( el, 0 )
	}
	range.collapse( true )
	sel.removeAllRanges()
	sel.addRange( range )
}

// A text segment as an inline, wrapping contentEditable span (an <input> can't
// wrap, which overflowed long text). The DOM text is written only when it
// differs from `value`, so typing never resets the caret.
function EditableText( {
	value,
	placeholder,
	dir,
	onInput,
	onCaret,
	onDrop,
	dragActive,
	pad,
	registerRef,
	onBoundary,
} ) {
	const ref = useRef( null )
	useEffect( () => {
		if ( ref.current && ref.current.textContent !== value ) {
			ref.current.textContent = value
		}
	}, [ value ] )
	const track = () => onCaret( getCaretOffset( ref.current ) )
	const setRef = ( node ) => {
		ref.current = node
		registerRef?.( node )
	}
	// A separate contentEditable per segment never lets the browser carry the
	// caret across a chip on its own (each span is its own isolated editable
	// region) — without this, ArrowLeft/Right just stop dead at a tag. Hopping
	// to the neighboring segment's near edge here is what keeps arrow-key
	// navigation working without reaching for the mouse.
	const onKeyDown = ( e ) => {
		if ( e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' ) {
			return
		}
		const el = ref.current
		if ( ! el ) {
			return
		}
		const offset = getCaretOffset( el )
		if ( e.key === 'ArrowLeft' && offset === 0 ) {
			e.preventDefault()
			onBoundary?.( 'left' )
		} else if (
			e.key === 'ArrowRight' &&
			offset === el.textContent.length
		) {
			e.preventDefault()
			onBoundary?.( 'right' )
		}
	}
	return (
		<span
			ref={ setRef }
			contentEditable
			suppressContentEditableWarning
			role="textbox"
			tabIndex={ 0 }
			dir={ dir }
			spellCheck={ false }
			className="tl-seg"
			data-ph={ placeholder || '' }
			aria-label="Translation text"
			onInput={ () => onInput( ref.current.textContent ) }
			onKeyDown={ onKeyDown }
			onKeyUp={ track }
			onMouseUp={ track }
			onFocus={ track }
			onClick={ ( e ) => e.stopPropagation() }
			onDragOver={ ( e ) => dragActive() && e.preventDefault() }
			onDrop={ ( e ) => {
				e.preventDefault()
				onDrop( caretFromPoint( ref.current, e.clientX, e.clientY ) )
			} }
			style={ {
				outline: 'none',
				minWidth: '4px',
				whiteSpace: 'pre-wrap',
				overflowWrap: 'anywhere',
				color: 'var(--p-color-text)',
				// A little breathing room between the wrapping chips and their content.
				padding: pad ? '0 3px' : undefined,
			} }
		/>
	)
}

// ---------- Chip ----------

function Chip( {
	seg,
	tags,
	readOnly,
	selected,
	onSelect,
	onKeyDown,
	onRemove,
	onDragStart,
	onDragEnd,
	label,
	hueN,
	title,
} ) {
	const hue = hueFor( hueN ?? seg.n )
	const text = label ?? chipText( seg, tags )
	return (
		<button
			type="button"
			draggable={ ! readOnly && !! onDragStart }
			onDragStart={ onDragStart }
			onDragEnd={ onDragEnd }
			tabIndex={ readOnly ? -1 : 0 }
			onClick={
				readOnly
					? undefined
					: ( event ) => {
							if (
								event.target.closest( '[data-remove-chip]' )
							) {
								onRemove?.()
								return
							}
							onSelect?.()
					  }
			}
			onKeyDown={ readOnly ? undefined : onKeyDown }
			title={
				title ??
				( readOnly
					? `Formatting tag ${ seg.n }`
					: `Formatting tag ${ seg.n } — keep it, don't retype it. Backspace removes it.` )
			}
			style={ {
				...chipBaseStyle( hue ),
				cursor: readOnly ? 'default' : 'pointer',
				opacity: readOnly ? 0.9 : 1,
				outline: selected ? `2px solid ${ hue.text }` : 'none',
				outlineOffset: selected ? '1px' : 0,
			} }
		>
			<span
				className="w-1.5 h-1.5 rounded-full"
				style={ { background: hue.border } }
			/>
			<span>{ text }</span>
			{ ! readOnly && selected && onRemove && (
				<span
					data-remove-chip
					className="ms-0.5 opacity-60 font-bold"
					title="Remove tag"
				>
					×
				</span>
			) }
		</button>
	)
}

// ---------- read-only source (left overlay column) ----------

export function SourceChips( { unit, tags, dir = 'ltr' } ) {
	const segs = useMemo( () => parseUnit( unit ?? '' ), [ unit ] )
	const tree = useMemo(
		() => hideStructure( groupCoincident( segs ) ),
		[ segs ]
	)
	return (
		<div
			dir={ dir }
			style={ {
				flex: 1,
				overflowY: 'auto',
				padding: '8px 4px',
				fontSize: '13px',
				lineHeight: '26px',
				color: 'var(--p-color-text)',
			} }
		>
			{ tree.map( ( node, i ) => {
				if ( node.type === 'text' ) {
					return <span key={ i }>{ node.value }</span>
				}
				if ( node.type === 'hidden' ) {
					return (
						<HiddenStructure
							key={ i }
							count={ markerCount( node.nodes ) }
						/>
					)
				}
				if ( node.type === 'group' ) {
					return (
						<Fragment key={ i }>
							<Chip
								tags={ tags }
								readOnly
								label={ groupLabel( node, tags ) }
								hueN={ node.ids[ 0 ] }
								title={ groupTitle( node, tags ) }
							/>
							<span style={ { padding: '0 3px' } }>
								{ node.inner.value.trim() }
							</span>
							<Chip
								tags={ tags }
								readOnly
								label={ `/${ groupLabel( node, tags ) }` }
								hueN={ node.ids[ 0 ] }
								title={ groupTitle( node, tags ) }
							/>
						</Fragment>
					)
				}
				return <Chip key={ i } seg={ node } tags={ tags } readOnly />
			} ) }
		</div>
	)
}

// ---------- editable field (right overlay column) ----------

export function ChipField( {
	sourceUnit,
	value,
	tags,
	dir = 'ltr',
	onChange,
} ) {
	const sourceSegs = useMemo(
		() => parseUnit( sourceUnit ?? '' ),
		[ sourceUnit ]
	)
	const [ segs, setSegs ] = useState( () => parseUnit( value ?? '' ) )
	const [ selectedChip, setSelectedChip ] = useState( null )
	const [ , setFlash ] = useState( null )
	const caretRef = useRef( null ) // { segIdx, pos }
	const flashTimer = useRef( null )
	const lastSent = useRef( null )
	const dragRef = useRef( null ) // indices of the stack being dragged
	const segElRefs = useRef( new Map() ) // segs index -> mounted EditableText span

	const validation = useMemo(
		() => validateStructure( segs, sourceSegs ),
		[ segs, sourceSegs ]
	)

	// Keep the host in sync (serialized value + validity) on real changes + mount.
	// Guarded by a ref so an inline host onChange can't drive a render loop.
	const serialized = useMemo( () => serialize( segs ), [ segs ] )
	useEffect( () => {
		const sig = `${ validation.ok ? 1 : 0 }|${ serialized }`
		if ( sig === lastSent.current ) {
			return
		}
		lastSent.current = sig
		onChange?.( serialized, validation.ok )
	}, [ serialized, validation.ok, onChange ] )

	// Adopt a value that changed outside the field (AI translate, copy source,
	// a refetch after autosave). Deliberately keyed on `value` only: while the
	// user types, the host's value lags our serialization and must not reparse.
	useEffect( () => {
		if ( ( value ?? '' ) !== serialize( segs ) ) {
			setSegs( parseUnit( value ?? '' ) )
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] )

	const showFlash = ( msg ) => {
		setFlash( msg )
		clearTimeout( flashTimer.current )
		flashTimer.current = setTimeout( () => setFlash( null ), 3200 )
	}
	useEffect( () => () => clearTimeout( flashTimer.current ), [] )

	const update = ( next ) => setSegs( normalize( next ) )

	// Render from the collapsed + structure-hidden tree; edits still resolve to
	// true-segment indices via idxOf.
	const tree = useMemo(
		() => hideStructure( groupCoincident( segs ) ),
		[ segs ]
	)
	const idxOf = useMemo(
		() => new Map( segs.map( ( s, i ) => [ s, i ] ) ),
		[ segs ]
	)

	// Segs indices of every actually-mounted EditableText, in on-screen order —
	// walked from `tree` (not a flat filter of `segs`) so whitespace-only text
	// folded into a HiddenStructure run is correctly left out: it has no span
	// to jump to.
	const editableSegIndices = useMemo( () => {
		const list = []
		for ( const node of tree ) {
			if ( node.type === 'text' ) {
				list.push( idxOf.get( node ) )
			} else if ( node.type === 'group' ) {
				list.push( idxOf.get( node.inner ) )
			}
		}
		return list
	}, [ tree, idxOf ] )

	// ArrowLeft/Right at a segment's edge: jump straight to the neighboring
	// segment's near edge, skipping over however many chips sit between them.
	const jumpToNeighbor = ( segIdx, direction ) => {
		const pos = editableSegIndices.indexOf( segIdx )
		if ( pos < 0 ) {
			return
		}
		const targetSegIdx =
			editableSegIndices[ direction === 'right' ? pos + 1 : pos - 1 ]
		if ( targetSegIdx === undefined ) {
			return
		}
		const el = segElRefs.current.get( targetSegIdx )
		if ( el ) {
			placeCaret( el, direction === 'right' )
		}
	}
	// -- text editing (markers can never be typed: paste guard) --
	const onTextChange = ( idx, raw ) => {
		const cleaned = raw.replace( /\{\/?\d+\/?\}/g, '' )
		if ( cleaned !== raw ) {
			showFlash( "Tag markers can't be typed or pasted — use the chips." )
		}
		update(
			segs.map( ( s, i ) => ( i === idx ? { ...s, value: cleaned } : s ) )
		)
	}

	const rememberCaret = ( idx, pos ) =>
		( caretRef.current = { segIdx: idx, pos: pos ?? 0 } )

	// Remove one or more chip segments as a unit, keeping surrounding text; a
	// collapsed group passes all its member+closer indices so a stack is atomic.
	const removeStack = ( indices ) => {
		const drop = new Set( indices )
		setSelectedChip( null )
		update( segs.filter( ( _, i ) => ! drop.has( i ) ) )
	}
	const removeChip = ( idx ) => removeStack( [ idx ] )

	// Move a stack over one adjacent word by shifting that word across the block:
	// only the two bracketing text segments change value, so the block (and every
	// marker's relative order) is untouched — the move can't break structure.
	// ponytail: word-hop only; hopping an adjacent chip when the between-text is
	// empty is left out — arrow-repeat or drag covers that. Arrow keys are logical
	// (not RTL-mirrored) in v1.
	const moveStack = ( indices, direction, jump ) => {
		const lo = Math.min( ...indices )
		const hi = Math.max( ...indices )
		const bi = lo - 1
		const ai = hi + 1
		if ( bi < 0 || ai >= segs.length ) {
			return
		}
		const next = segs.map( ( s ) => ( { ...s } ) )
		const B = next[ bi ].value
		const A = next[ ai ].value
		if ( direction === 'right' ) {
			if ( jump ) {
				next[ bi ].value = B + A
				next[ ai ].value = ''
			} else {
				const m = A.match( /^(\s*\S+)([\s\S]*)$/ )
				if ( ! m ) {
					return
				}
				next[ bi ].value = B + m[ 1 ]
				next[ ai ].value = m[ 2 ]
			}
		} else if ( jump ) {
			next[ ai ].value = B + A
			next[ bi ].value = ''
		} else {
			const m = B.match( /^([\s\S]*?)(\S+\s*)$/ )
			if ( ! m ) {
				return
			}
			next[ bi ].value = m[ 1 ]
			next[ ai ].value = m[ 2 ] + A
		}
		update( next )
	}

	const onChipKeyDown = ( sel ) => ( e ) => {
		const k = e.key
		if ( k === 'Backspace' || k === 'Delete' ) {
			e.preventDefault()
			// Two-press delete: first press selects, second removes.
			if ( selectedChip === sel.anchor ) {
				removeStack( sel.indices )
			} else {
				setSelectedChip( sel.anchor )
			}
		} else if ( k === 'ArrowLeft' || k === 'ArrowRight' ) {
			e.preventDefault()
			setSelectedChip( sel.anchor )
			moveStack(
				sel.indices,
				k === 'ArrowRight' ? 'right' : 'left',
				e.ctrlKey || e.metaKey
			)
		} else if ( k === 'Escape' ) {
			setSelectedChip( null )
		}
	}

	// Drag a stack to a caret slot in any text segment; markers reorder but the
	// block stays intact (self-drop is a no-op).
	const dropStackAt = ( targetNode, pos ) => {
		const indices = dragRef.current
		dragRef.current = null
		if ( ! indices ) {
			return
		}
		const lo = Math.min( ...indices )
		const hi = Math.max( ...indices )
		const rest = [ ...segs.slice( 0, lo ), ...segs.slice( hi + 1 ) ]
		const ti = rest.indexOf( targetNode ) // -1 if the target was inside the block
		if ( ti < 0 ) {
			return
		}
		const block = segs.slice( lo, hi + 1 ).map( ( s ) => ( { ...s } ) )
		const t = rest[ ti ]
		const at = Math.max(
			0,
			Math.min( pos ?? t.value.length, t.value.length )
		)
		rest.splice(
			ti,
			1,
			{ type: 'text', value: t.value.slice( 0, at ) },
			...block,
			{ type: 'text', value: t.value.slice( at ) }
		)
		setSelectedChip( null )
		update( rest )
	}

	const textInput = ( node ) => {
		const i = idxOf.get( node )
		return (
			<EditableText
				key={ `t${ i }` }
				value={ node.value }
				dir={ dir }
				placeholder={ segs.length === 1 ? 'Type the translation…' : '' }
				onInput={ ( text ) => onTextChange( i, text ) }
				onCaret={ ( pos ) => rememberCaret( i, pos ) }
				onDrop={ ( pos ) => dropStackAt( node, pos ) }
				dragActive={ () => !! dragRef.current }
				registerRef={ ( el ) => {
					if ( el ) {
						segElRefs.current.set( i, el )
					} else {
						segElRefs.current.delete( i )
					}
				} }
				onBoundary={ ( direction ) => jumpToNeighbor( i, direction ) }
			/>
		)
	}

	// A group's inner text hides the whitespace that pads the chip boundary
	// (`{2} Products {/2}`) — noise for the translator, but the backend needs it,
	// so it is re-attached on every edit and serialize() stays byte-identical.
	const innerInput = ( node ) => {
		const i = idxOf.get( node )
		const v = node.value
		const trimmed = v.trim()
		const lead =
			trimmed === '' ? v : v.slice( 0, v.length - v.trimStart().length )
		const trail = trimmed === '' ? '' : v.slice( v.trimEnd().length )
		return (
			<EditableText
				key={ `t${ i }` }
				value={ trimmed }
				dir={ dir }
				placeholder=""
				pad
				onInput={ ( text ) => {
					const cleaned = text.replace( /\{\/?\d+\/?\}/g, '' ).trim()
					if ( cleaned !== text.trim() ) {
						showFlash(
							"Tag markers can't be typed or pasted — use the chips."
						)
					}
					update(
						segs.map( ( s, idx ) =>
							idx === i
								? { ...s, value: lead + cleaned + trail }
								: s
						)
					)
				} }
				onCaret={ ( pos ) => rememberCaret( i, lead.length + pos ) }
				onDrop={ ( pos ) => dropStackAt( node, lead.length + pos ) }
				dragActive={ () => !! dragRef.current }
				registerRef={ ( el ) => {
					if ( el ) {
						segElRefs.current.set( i, el )
					} else {
						segElRefs.current.delete( i )
					}
				} }
				onBoundary={ ( direction ) => jumpToNeighbor( i, direction ) }
			/>
		)
	}

	return (
		<div
			style={ {
				flex: 1,
				minHeight: 0,
				display: 'flex',
				flexDirection: 'column',
				gap: '8px',
			} }
		>
			<style>{ `.tl-seg:empty:before{content:attr(data-ph);color:var(--p-color-text-subdued);opacity:.6;pointer-events:none;}` }</style>
			<div
				role="presentation"
				dir={ dir }
				onClick={ ( event ) => {
					if ( ! event.target.closest( 'button' ) ) {
						setSelectedChip( null )
					}
				} }
				style={ {
					flex: 1,
					minHeight: '120px',
					overflowY: 'auto',
					overflowWrap: 'anywhere',
					padding: '4px 8px',
					borderRadius: '8px',
					border: `1px solid ${
						validation.ok
							? 'var(--p-color-border)'
							: 'var(--p-color-border-critical)'
					}`,
					background: validation.ok
						? 'var(--p-color-bg-surface)'
						: 'var(--p-color-bg-surface-critical)',
					fontSize: '13px',
					lineHeight: '26px',
				} }
			>
				{ tree.map( ( node, ni ) => {
					if ( node.type === 'text' ) {
						return textInput( node )
					}
					if ( node.type === 'hidden' ) {
						return (
							<HiddenStructure
								key={ `h${ ni }` }
								count={ markerCount( node.nodes ) }
							/>
						)
					}
					if ( node.type === 'group' ) {
						const i = idxOf.get( node.members[ 0 ] )
						const indices = [
							...node.members,
							...node.closers,
						].map( ( o ) => idxOf.get( o ) )
						const sel = { anchor: i, indices }
						const label = groupLabel( node, tags )
						const title = groupTitle( node, tags )
						// Shared props so the opening and closing chip act as one atom:
						// selecting/removing/moving/dragging either affects the whole stack.
						const shared = {
							tags,
							hueN: node.ids[ 0 ],
							title,
							selected: selectedChip === i,
							onSelect: () => setSelectedChip( i ),
							onKeyDown: onChipKeyDown( sel ),
							onDragStart: ( e ) => {
								e.dataTransfer.setData( 'text/plain', '' )
								dragRef.current = indices
							},
							onDragEnd: () => ( dragRef.current = null ),
						}
						return (
							<Fragment key={ `g${ i }` }>
								<span>
									<Chip
										{ ...shared }
										label={ label }
										onRemove={ () =>
											removeStack( indices )
										}
									/>
								</span>
								{ innerInput( node.inner ) }
								<span>
									<Chip
										{ ...shared }
										label={ `/${ label }` }
									/>
								</span>
							</Fragment>
						)
					}
					const i = idxOf.get( node )
					const sel = { anchor: i, indices: [ i ] }
					return (
						<span key={ `c${ i }` }>
							<Chip
								seg={ node }
								tags={ tags }
								selected={ selectedChip === i }
								onSelect={ () => setSelectedChip( i ) }
								onKeyDown={ onChipKeyDown( sel ) }
								onRemove={ () => removeChip( i ) }
								onDragStart={ ( e ) => {
									e.dataTransfer.setData( 'text/plain', '' )
									dragRef.current = [ i ]
								} }
								onDragEnd={ () => ( dragRef.current = null ) }
							/>
						</span>
					)
				} ) }
			</div>

			{ /* status + missing-formatting dock (only when there's something to say) */ }
			{ /* {showPanel && (
      <Box padding="200" background="bg-surface-secondary" borderRadius="200">
        <div style={{ display: "flex", flexDirection: "column", gap: "6px" }}>
          <InlineStack gap="300" blockAlign="center" align="start">
            {pristine ? (
              <Text as="span" variant="bodyXs" tone="subdued">
                Copy the original to bring in its formatting, then translate the words.
              </Text>
            ) : !validation.ok ? (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-4 h-4">
                  <Icon source={XCircleIcon} tone="critical" />
                </span>
                <Text as="span" variant="bodySm" tone="critical">
                  {problem}
                </Text>
              </InlineStack>
            ) : null}

            {validation.ok && validation.emptyPairs.length > 0 && (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-3.5 h-3.5">
                  <Icon source={AlertTriangleIcon} tone="caution" />
                </span>
                <Text as="span" variant="bodyXs" tone="caution">
                  "{labelFor(tags, validation.emptyPairs[0])}" tag is empty — it will render with no text.
                </Text>
              </InlineStack>
            )}

            {spaceWarn && (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-3.5 h-3.5">
                  <Icon source={AlertTriangleIcon} tone="caution" />
                </span>
                <Text as="span" variant="bodyXs" tone="caution">
                  Missing space around formatting — text may run together.
                </Text>
              </InlineStack>
            )}

            {flash && (
              <Text as="span" variant="bodyXs" tone="caution">
                {flash}
              </Text>
            )}
          </InlineStack>

          {!pristine && (dock.length > 0 || looseMissing.length > 0) && (
            <InlineStack gap="200" blockAlign="center" align="start">
              <Text as="span" variant="bodyXs" tone="subdued">
                {dock.length + looseMissing.length} formatting missing — put back:
              </Text>
              {dock.map(({ g, label, ordinal, preview }) => {
                const hue = hueFor(g.ids[0]);
                return (
                  <button
                    key={`d${g.ids[0]}`}
                    type="button"
                    onClick={() => insertStack(g)}
                    title="Insert this formatting stack at the cursor"
                    style={{
                      ...chipBaseStyle(hue),
                      cursor: "pointer",
                      borderStyle: "dashed",
                      height: "auto",
                      padding: "1px 6px",
                    }}
                  >
                    <span className="w-2.5 h-2.5">
                      <Icon source={ReturnIcon} />
                    </span>
                    <span>
                      {label} — {ordinal}
                      {ordinalSuffix(ordinal)}
                    </span>
                    {preview && (
                      <span style={{ opacity: 0.7 }}>· “{truncate(preview, 24)}”</span>
                    )}
                  </button>
                );
              })}
              {looseMissing.map((k) => {
                const [type, nStr] = k.split(":");
                const seg = { type, n: Number(nStr) };
                const hue = hueFor(seg.n);
                return (
                  <button
                    key={`l${k}`}
                    type="button"
                    onClick={() => insertMissing(k)}
                    title="Insert at cursor position"
                    style={{
                      ...chipBaseStyle(hue),
                      cursor: "pointer",
                      borderStyle: "dashed",
                    }}
                  >
                    <span className="w-2.5 h-2.5">
                      <Icon source={ReturnIcon} />
                    </span>
                    {chipText(seg, tags)}
                  </button>
                );
              })}
            </InlineStack>
          )}
        </div>
      </Box>
      )} */ }
		</div>
	)
}
