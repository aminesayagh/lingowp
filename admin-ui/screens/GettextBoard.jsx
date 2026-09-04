import {
	ActionList,
	Badge,
	BlockStack,
	Box,
	Button,
	Collapsible,
	Divider,
	InlineStack,
	Popover,
	Spinner,
	Text,
	Tooltip,
} from '@shopify/polaris'
import { MenuHorizontalIcon, PageIcon } from '@shopify/polaris-icons'
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query'
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element'
import { AnimatePresence, motion } from 'motion/react'
import { api } from '../api'
import { AiIcon } from './AiIcon'
import { FlagIcon, flagIconUrl } from './FlagIcon'
import GettextOverlay from './GettextOverlay'
import { sortLanguages } from './LanguageList'

// Same readable-name resolution as the Content board's own column header.
function codeName( code ) {
	if ( ! code ) {
		return null
	}
	try {
		return new Intl.DisplayNames( undefined, { type: 'language' } ).of(
			code
		)
	} catch {
		return code
	}
}
// Board nickname first (display_name), then the language's own canonical
// label ("Português (Brasil)"), then bare fallbacks.
const langLabel = ( l ) =>
	l &&
	( l.display_name || l.native_display || l.name || codeName( l.code || l ) )

const oneLineStyle = {
	all: 'unset',
	cursor: 'pointer',
	display: 'block',
	width: '100%',
	whiteSpace: 'nowrap',
	overflow: 'hidden',
	textOverflow: 'ellipsis',
}

// Plugins & Themes board: every installed plugin/theme, scoped to the same
// single working language as the Content tab, is its own row/collapse
// (closed by default). Same list chrome as Content: a language-pair column
// header on top, 50 rows per page with a "Load more" button — not the whole
// site's plugins in one shot. Gettext strings (`__()`, `_e()`, etc.) are
// served by WordPress itself once a `.mo` is in place — this board only
// manages that file:
//   - rows with no translation yet are auto-checked against wordpress.org in
//     the background, one at a time, as each page of the list loads;
//   - still nothing found -> Import .po, or Translate manually (loads every
//     string this component's source scan finds, blank or already-translated,
//     into the same editable original/translation rows the Content tab uses);
//   - anything found (online, imported, or typed here) -> the row list is
//     always the editable view, never read-only — same row style, spacing,
//     inline input, and ⋯ menu (Open in overlay / Ignore) as the Content tab.
const PAGE_SIZE = 50

function rowKey( row ) {
	return `${ row.type }:${ row.domain }:${ row.locale }`
}

// Same idea as the Content board's pollWhilePending: while any row has an
// AI "Translate All" batch in flight for it, keep refetching every 3s so the
// board picks up results as GettextAiQueue::collectOnce() drains them
// server-side on each read.
const AI_POLL_MS = 3000

function pollWhileGettextRowsPending( query ) {
	const pages = query.state.data?.pages
	return Array.isArray( pages ) &&
		pages.some( ( p ) => p.rows.some( ( r ) => r.ai_pending > 0 ) )
		? AI_POLL_MS
		: false
}

function pollWhileGettextUnitsPending( query ) {
	const pages = query.state.data?.pages
	return Array.isArray( pages ) &&
		pages.some( ( p ) => p.units.some( ( u ) => u.ai_pending ) )
		? AI_POLL_MS
		: false
}

// While the progressive search-scan is running, list() is re-fetched
// mid-flight and a newly-matching component can cross the page-size boundary
// between two sequential page refetches — briefly yielding the same row on
// two pages. Dedupe so React never sees a duplicate key.
function dedupeByKey( items, keyOf ) {
	const seen = new Set()
	return items.filter( ( item ) => {
		const k = keyOf( item )
		if ( seen.has( k ) ) {
			return false
		}
		seen.add( k )
		return true
	} )
}

// Which plugin/theme rows are expanded, remembered per browser so a refresh
// doesn't collapse everything back. rowKey already bakes in the locale, so
// one flat list covers every language pair.
const OPEN_ROWS_STORAGE_KEY = 'lingowp_gettext_open_rows'

function loadOpenKeys() {
	try {
		const raw = window.localStorage.getItem( OPEN_ROWS_STORAGE_KEY )
		return raw ? new Set( JSON.parse( raw ) ) : new Set()
	} catch {
		return new Set()
	}
}

// One status chip per domain row — never a badge *and* a raw percent, since
// they'd carry the same information. The cached percentage is the single
// source of truth: while it's still being backfilled the row shows no chip
// (rather than flashing a wrong one), 0% reads as "Not found", 100% is just
// "Ready" with no number, and anything between shows how much is still
// untranslated, e.g. "34% left". `row.status` is deliberately NOT consulted
// here — it starts null for every not-yet-installed row and stays stale until
// the list reloads, so a row that already has translations (auto find-online
// just installed a file, percent backfilled) would otherwise be mislabelled
// "Not found".
function domainProgressChip( row ) {
	const p = row.translated_percent
	let chip = null
	if ( p === null || p === undefined ) {
		return null
	} else if ( p <= 0 ) {
		chip = <Badge>Not found</Badge>
	} else if ( p >= 100 ) {
		chip = (
			<Badge tone="success" size="small">
				Ready
			</Badge>
		)
	} else {
		chip = (
			<Badge tone="info" size="small">
				{ `${ 100 - p }% left` }
			</Badge>
		)
	}
	return <Box paddingInlineStart="200">{ chip }</Box>
}

export default function GettextBoard( {
	language,
	search,
	untranslatedOnly = false,
	originalLabel,
	translationLabel,
	sourceLabel,
	sourceFlag,
	languages,
	aiAvailable,
	billingBlocked,
	onAiBlocked,
	onPickLanguage,
	onConfirm,
	onAiUnavailable,
} ) {
	const queryClient = useQueryClient()
	const [ openKeys, setOpenKeys ] = useState( loadOpenKeys )
	// Sticky row headers, keyed by rowKey — toggle() reads these to anchor
	// scroll on collapse (see below), same idea as the Content board's own
	// CollectionGroup rows.
	const headerRefs = useRef( new Map() )
	// This board's own column-header bar (original/target labels) is pinned
	// separately from the shared search bar above it (Dashboard only measures
	// itself for the Content board's header — see the comment where this is
	// rendered below). Row headers need to stick right beneath it, not under
	// (i.e. behind, at the same offset as) it.
	//
	// Same split as Dashboard's own stickyBarH/CSS-var pair, and for the same
	// reason: this is set imperatively (a CSS var, not React state) so a
	// resize never forces every row to re-render just to pick up a new
	// offset, and — unlike a value computed once into React state — it
	// self-corrects immediately if the *outer* var Dashboard maintains
	// changes size without this board's own header changing size too (a
	// window resize that re-wraps the shared search bar's text, say).
	const boardRef = useRef( null )
	const ownHeaderElRef = useRef( null )
	const ownHeaderCleanupRef = useRef( null )
	const measureOwnHeader = useCallback( () => {
		const el = ownHeaderElRef.current
		if ( ! el || ! boardRef.current ) {
			return
		}
		// el's own `top` is already the resolved outer var (see its style
		// below) — read it back rather than re-deriving it, so this and that
		// stay in lockstep even if the outer var's own fallback or formula
		// ever changes.
		const outerTop = parseFloat( getComputedStyle( el ).top ) || 32
		boardRef.current.style.setProperty(
			'--lingowp-gettext-row-sticky-top',
			`${ outerTop + el.offsetHeight }px`
		)
	}, [] )
	// A callback ref, not a plain useRef + useLayoutEffect([]): the header
	// this measures doesn't exist on the board's first render (it's still
	// showing the loading spinner then), and an effect with an empty
	// dependency array only ever runs once, right after that first render —
	// it would find nothing to observe and never get a second chance once
	// the real header mounts. A callback ref fires exactly when the node
	// actually mounts (and again on unmount, for cleanup), whenever that is.
	const setOwnHeaderRef = useCallback(
		( el ) => {
			ownHeaderCleanupRef.current?.()
			ownHeaderCleanupRef.current = null
			ownHeaderElRef.current = el
			if ( ! el ) {
				return
			}
			measureOwnHeader()
			const ro = new ResizeObserver( measureOwnHeader )
			ro.observe( el )
			window.addEventListener( 'resize', measureOwnHeader )
			ownHeaderCleanupRef.current = () => {
				ro.disconnect()
				window.removeEventListener( 'resize', measureOwnHeader )
			}
		},
		[ measureOwnHeader ]
	)
	const [ langSwitcherOpen, setLangSwitcherOpen ] = useState( false )
	// Row-level "⋯" menu (Translate all) — same hover/open-reveal idea as the
	// Content board's own CollectionGroup menu. Only one row can be
	// hovered/have its menu open at a time, so a single key is enough (no
	// per-row component/state needed).
	const [ hoveredRowKey, setHoveredRowKey ] = useState( null )
	const [ menuOpenRowKey, setMenuOpenRowKey ] = useState( null )
	// Rows with a per-component "Translate all" batch currently being
	// submitted (the loading spinner on that row's own button) — separate
	// from ai_pending, which covers the wait for results once submitted.
	const [ translatingComponents, setTranslatingComponents ] = useState(
		() => new Set()
	)
	const checkedRef = useRef( new Set() )
	const runningRef = useRef( false )
	const percentCheckedRef = useRef( new Set() )
	const percentRunningRef = useRef( false )
	// Progressive "scan every plugin/theme so the header search can match its
	// original text" pass — same shape as the auto-check loop below.
	const scanRunningRef = useRef( false )
	const scannedRef = useRef( new Set() ) // attempted this session (don't retry)
	const scanFailedRef = useRef( new Set() ) // attempted but the scan errored
	const searchRef = useRef( search )
	searchRef.current = search
	const targetDir =
		languages.find( ( item ) => item.code === language )?.direction || 'ltr'
	// Same sidebar order (group by base language, "General" first) as the
	// Content board's own target-language column switcher.
	const sortedLanguages = useMemo(
		() => sortLanguages( languages ),
		[ languages ]
	)

	const rowsQ = useInfiniteQuery( {
		queryKey: [ 'gettext-domains', language, search, untranslatedOnly ],
		initialPageParam: 0,
		queryFn: async ( { pageParam, signal } ) => {
			const params = new URLSearchParams( {
				language,
				offset: String( pageParam ),
				limit: String( PAGE_SIZE ),
				...( search ? { search } : {} ),
				...( untranslatedOnly ? { status: 'untranslated' } : {} ),
			} )
			const res = await api.get(
				`/gettext-domains?${ params.toString() }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		getNextPageParam: ( last ) =>
			last.has_more ? last.next_offset : undefined,
		enabled: !! language,
		refetchInterval: pollWhileGettextRowsPending,
	} )

	const serverRows = dedupeByKey(
		( rowsQ.data?.pages ?? [] ).flatMap( ( p ) => p.rows ),
		rowKey
	)
	// Server filters by the CACHED percent; a row whose percent was uncached
	// at request time comes through, then backfills. Drop it here once it's
	// known to be fully done so the list settles.
	const rows = serverRows.filter(
		( r ) => ! untranslatedOnly || r.translated_percent !== 100
	)
	// If "Untranslated only" client-filtered a whole fetched page to nothing
	// but the server has more pages, keep pulling — otherwise the board would
	// wrongly show the "all translated" empty state while unfetched pages
	// still hold untranslated components.
	useEffect( () => {
		if (
			untranslatedOnly &&
			serverRows.length > 0 &&
			rows.length === 0 &&
			rowsQ.hasNextPage &&
			! rowsQ.isFetchingNextPage
		) {
			rowsQ.fetchNextPage()
		}
	}, [
		untranslatedOnly,
		serverRows.length,
		rows.length,
		rowsQ.hasNextPage,
		rowsQ.isFetchingNextPage,
	] )

	// Header search matches only a component's original text, and only for
	// components already scanned into the DB. list() returns which installed
	// components still need a first scan; scan them one at a time in the
	// background while a search is active, then reload() so their matches
	// surface. Progress is client-side (scannedRef) so a scan that fails
	// server-side still advances the count instead of pinning it.
	const lastPage = rowsQ.data?.pages?.at( -1 )
	const scanTotal = lastPage?.scan_total ?? 0
	const scanPending = search
		? ( lastPage?.unscanned ?? [] ).filter(
				( c ) => ! scannedRef.current.has( `${ c.type }:${ c.domain }` )
		  )
		: []
	const scanning = !! search && scanPending.length > 0
	const scanDone = scanTotal - scanPending.length
	const scanFailed = search ? scanFailedRef.current.size : 0

	const reload = useCallback( () => {
		queryClient.invalidateQueries( {
			queryKey: [ 'gettext-domains', language ],
		} )
	}, [ language, queryClient ] )

	// Auto-check online, one row at a time (not in parallel — each check is a
	// real wordpress.org round-trip plus a local language-pack install), only
	// for rows this session hasn't already checked and that have no file yet.
	useEffect( () => {
		if ( rowsQ.isPending || runningRef.current ) {
			return
		}
		const pending = rows.filter(
			( r ) =>
				r.status === null && ! checkedRef.current.has( rowKey( r ) )
		)
		if ( pending.length === 0 ) {
			return
		}

		runningRef.current = true
		;( async () => {
			for ( const row of pending ) {
				checkedRef.current.add( rowKey( row ) )
				// find-online installs a file and drops the server's percent
				// cache for this row, so the reload() below brings it back with
				// translated_percent: null. The backfill effect only visits a
				// row key once, so clear it here or the chip stays blank until
				// a full page reload.
				percentCheckedRef.current.delete( rowKey( row ) )
				try {
					await api.post( '/gettext-domains/find-online', {
						type: row.type,
						domain: row.domain,
						locale: row.locale,
					} )
				} catch {
					// best-effort; this row just stays "not found" until reloaded
				}
			}
			runningRef.current = false
			reload()
		} )()
	}, [ reload, rows, rowsQ.isPending ] )

	// Progressive search-scan: while a search is active, scan every
	// not-yet-scanned component one at a time so its original text becomes
	// matchable. Batches reload() so an N-page list doesn't refetch N times
	// per scan; bails if the search term changes or the board unmounts mid-run.
	useEffect( () => {
		if ( ! search || rowsQ.isPending || scanRunningRef.current ) {
			return undefined
		}
		const pending = ( rowsQ.data?.pages?.at( -1 )?.unscanned ?? [] ).filter(
			( c ) => ! scannedRef.current.has( `${ c.type }:${ c.domain }` )
		)
		if ( pending.length === 0 ) {
			return undefined
		}

		scanRunningRef.current = true
		const term = search
		let cancelled = false
		;( async () => {
			try {
				let sinceReload = 0
				for ( const c of pending ) {
					if ( cancelled || searchRef.current !== term ) {
						break
					}
					const compKey = `${ c.type }:${ c.domain }`
					// Marked attempted before the await so the loop always
					// terminates — a component that keeps failing is not retried
					// until the page is reloaded (scannedRef is per-mount).
					scannedRef.current.add( compKey )
					try {
						const res = await api.post( '/gettext-domains/scan', {
							type: c.type,
							domain: c.domain,
						} )
						if ( ! res?.ok ) {
							scanFailedRef.current.add( compKey )
						}
					} catch {
						scanFailedRef.current.add( compKey )
					}
					if ( ++sinceReload >= 5 && ! cancelled ) {
						sinceReload = 0
						reload()
					}
				}
			} finally {
				scanRunningRef.current = false
				if ( ! cancelled ) {
					reload()
				}
			}
		} )()

		return () => {
			cancelled = true
		}
	}, [ search, rowsQ.data, rowsQ.isPending, reload ] )

	// translated_percent is never computed by GET /gettext-domains itself
	// (a full source scan per row would be as slow as the old all-in-one
	// endpoint) — it arrives cached-or-null, and this backfills any row
	// still missing one, one domain at a time, patching each row into the
	// cache as its own response lands rather than waiting to reload() the
	// whole list.
	useEffect( () => {
		if ( rowsQ.isPending || percentRunningRef.current ) {
			return
		}
		const pending = rows.filter(
			( r ) =>
				( r.translated_percent === null ||
					r.translated_percent === undefined ) &&
				! percentCheckedRef.current.has( rowKey( r ) )
		)
		if ( pending.length === 0 ) {
			return
		}

		percentRunningRef.current = true
		;( async () => {
			for ( const row of pending ) {
				percentCheckedRef.current.add( rowKey( row ) )
				try {
					const params = new URLSearchParams( {
						type: row.type,
						domain: row.domain,
						locale: row.locale,
					} )
					const res = await api.get(
						`/gettext-domains/percent?${ params.toString() }`
					)
					if ( res.ok ) {
						patchPercent( rowKey( row ), res.translated_percent )
					}
				} catch {
					// best-effort; row just stays blank until a future reload
				}
			}
			percentRunningRef.current = false
		} )()
	}, [ rows, rowsQ.isPending ] )

	// Patches one row's percent across every cached search variant for this
	// language — same setQueriesData-over-paginated-pages shape as
	// Dashboard's patchCollectionSummary().
	function patchPercent( key, percent ) {
		queryClient.setQueriesData(
			{
				predicate: ( q ) =>
					q.queryKey[ 0 ] === 'gettext-domains' &&
					q.queryKey[ 1 ] === language,
			},
			( old ) => {
				if ( ! old ) {
					return old
				}
				return {
					...old,
					pages: old.pages.map( ( p ) => ( {
						...p,
						rows: p.rows.map( ( r ) =>
							rowKey( r ) === key
								? { ...r, translated_percent: percent }
								: r
						),
					} ) ),
				}
			}
		)
	}

	function toggle( key ) {
		// Keep the clicked header exactly where it is on screen. When it's
		// pinned to the sticky bar you've scrolled into this row; collapsing
		// pulls the content out from under it and the shrunk row slides up,
		// carrying the header away and surfacing the next one. Anchoring the
		// scroll to the header's own top (flush under the sticky bar) leaves
		// it sitting at the same spot. Same trick as the Content board's own
		// toggleCollapsed, minus the virtualizer plumbing this board doesn't
		// have — a plain ref to the header div stands in for its data-index
		// lookup, and the header's own resolved `top` (the sticky offset)
		// stands in for its groupStickyTop state.
		const collapsing = openKeys.has( key )
		let anchorY = null
		if ( collapsing ) {
			const el = headerRefs.current.get( key )
			if ( el ) {
				const stickyTop = parseFloat( getComputedStyle( el ).top ) || 32
				const top = el.getBoundingClientRect().top + window.scrollY
				if ( window.scrollY > top - stickyTop ) {
					anchorY = Math.max( 0, top - stickyTop )
				}
			}
		}

		setOpenKeys( ( prev ) => {
			const next = new Set( prev )
			if ( next.has( key ) ) {
				next.delete( key )
			} else {
				next.add( key )
			}
			try {
				window.localStorage.setItem(
					OPEN_ROWS_STORAGE_KEY,
					JSON.stringify( [ ...next ] )
				)
			} catch {
				// private mode / storage full — in-memory state still works
			}
			return next
		} )

		if ( anchorY !== null ) {
			window.requestAnimationFrame( () => window.scrollTo( 0, anchorY ) )
		}
	}

	// Per-component "Translate all" — the async queue counterpart to the
	// header's board-wide button. Same auto-continue-on-`more` loop; polling
	// (pollWhileGettextRowsPending/pollWhileGettextUnitsPending above) is what
	// actually surfaces the results, this only drives the submit side.
	async function translateAllInComponent( row ) {
		const key = rowKey( row )
		setTranslatingComponents( ( prev ) => new Set( prev ).add( key ) )
		try {
			let res = await api.post( '/gettext-domains/translate', {
				scope: 'component',
				type: row.type,
				domain: row.domain,
				locale: row.locale,
			} )
			while ( res?.ok && res.more ) {
				res = await api.post( '/gettext-domains/translate', {
					scope: 'component',
					type: row.type,
					domain: row.domain,
					locale: row.locale,
				} )
			}
			if ( ! res?.ok ) {
				if ( res?.error !== 'ERR_INVALID_INPUT' ) {
					onAiUnavailable?.( res?.error )
				}
				return
			}
			reload()
			queryClient.invalidateQueries( {
				predicate: ( q ) =>
					q.queryKey[ 0 ] === 'gettext-units' &&
					q.queryKey[ 1 ] === row.type &&
					q.queryKey[ 2 ] === row.domain &&
					q.queryKey[ 3 ] === row.locale,
			} )
		} catch {
			onAiUnavailable?.( 'ERR_UNREACHABLE' )
		} finally {
			setTranslatingComponents( ( prev ) => {
				const next = new Set( prev )
				next.delete( key )
				return next
			} )
		}
	}

	let paginationAction = null
	if ( rowsQ.isFetchNextPageError ) {
		paginationAction = (
			<Button onClick={ () => rowsQ.fetchNextPage() }>
				Retry loading more
			</Button>
		)
	} else if ( rowsQ.hasNextPage ) {
		paginationAction = (
			<Button
				onClick={ () => rowsQ.fetchNextPage() }
				loading={ rowsQ.isFetchingNextPage }
				disabled={ rowsQ.isFetchingNextPage }
			>
				Load more
			</Button>
		)
	}

	if ( rowsQ.isPending ) {
		return (
			<Box padding="500">
				<Spinner
					accessibilityLabel="Loading plugins & themes"
					size="small"
				/>
			</Box>
		)
	}

	if ( rowsQ.isError && rows.length === 0 ) {
		return (
			<Box padding="500">
				<BlockStack gap="200">
					<Text as="p" tone="critical">
						Could not load plugins & themes for this language.
					</Text>
					<InlineStack>
						<Button onClick={ () => rowsQ.refetch() }>Retry</Button>
					</InlineStack>
				</BlockStack>
			</Box>
		)
	}

	const scanIndicator = scanning ? (
		<Box paddingInlineStart="1000">
			<Box paddingInline="300" paddingBlock="200">
				<InlineStack gap="200" blockAlign="center">
					<Spinner
						accessibilityLabel="Searching plugins & themes"
						size="small"
					/>
					<Text as="span" variant="bodySm" tone="subdued">
						{ `Searching all plugins & themes… ${ scanDone }/${ scanTotal }` }
					</Text>
				</InlineStack>
			</Box>
		</Box>
	) : null

	if ( rows.length === 0 ) {
		// More server pages exist (the auto-fetch effect above is walking
		// them) — show progress, not a "nothing here" verdict.
		const stillPaging =
			untranslatedOnly &&
			serverRows.length > 0 &&
			( rowsQ.hasNextPage || rowsQ.isFetchingNextPage )
		return (
			<Box padding="500">
				{ scanning || stillPaging ? (
					<InlineStack gap="200" blockAlign="center">
						<Spinner
							accessibilityLabel="Loading plugins & themes"
							size="small"
						/>
						<Text as="span" tone="subdued">
							{ scanning
								? `Searching all plugins & themes… ${ scanDone }/${ scanTotal }`
								: 'Loading more…' }
						</Text>
					</InlineStack>
				) : (
					<Text as="p" tone="subdued">
						{ search && scanFailed > 0
							? `No matches yet — ${ scanFailed } plugin/theme couldn’t be scanned. Reload the page to retry.`
							: search
							? `No plugin or theme matches “${ search }”.`
							: untranslatedOnly
							? 'Every plugin and theme is fully translated for this language.'
							: 'No plugin/theme domains found.' }
					</Text>
				) }
			</Box>
		)
	}

	return (
		<div ref={ boardRef }>
		<BlockStack gap="0">
			{ /* GettextBoard draws its own column-header bar (the shared
			search bar above only measures itself for GettextBoard — see
			Dashboard's stickyRef), so it measures and pins itself the same
			way: sticky right under the search bar, and (see setOwnHeaderRef
			above) feeds its own height into --lingowp-gettext-row-sticky-top
			so the row headers below can stick just beneath it instead of
			underneath/overlapping it. */ }
			<div
				ref={ setOwnHeaderRef }
				style={ {
					position: 'sticky',
					top: 'var(--lingowp-group-sticky-top, 32px)',
					zIndex: 2,
					background: 'var(--p-color-bg-surface, #fff)',
				} }
			>
				<Box paddingInlineStart="1000">
					<Box paddingInline="300" paddingBlock="200">
						<InlineStack
							gap="300"
							align="space-between"
							blockAlign="center"
							wrap={ false }
						>
							<Box width="45%" minWidth="0">
								<Text
									as="span"
									variant="bodySm"
									tone="subdued"
									fontWeight="medium"
								>
									{ originalLabel }
								</Text>
							</Box>
							<Box width="45%" minWidth="0">
								<Popover
									active={ langSwitcherOpen }
									preferredAlignment="left"
									onClose={ () => setLangSwitcherOpen( false ) }
									activator={
										<button
											type="button"
											onClick={ () =>
												setLangSwitcherOpen( ( o ) => ! o )
											}
											style={ {
												all: 'unset',
												cursor: 'pointer',
												display: 'flex',
												alignItems: 'center',
												gap: 4,
												maxWidth: '100%',
											} }
										>
											<span
												style={ {
													minWidth: 0,
													overflow: 'hidden',
													textOverflow: 'ellipsis',
													whiteSpace: 'nowrap',
													marginTop: 1,
												} }
											>
												<Text
													as="span"
													variant="bodySm"
													tone="subdued"
													fontWeight="medium"
												>
													{ translationLabel }
												</Text>
											</span>
											<Text
												as="span"
												variant="bodySm"
												tone="subdued"
											>
												▾
											</Text>
										</button>
									}
								>
									<ActionList
										items={ sortedLanguages.map( ( l ) => ( {
											content: langLabel( l ),
											prefix: (
												<FlagIcon
													src={ flagIconUrl( l ) }
													size={ 16 }
													// Matches the Content board switcher: lighter than
													// the sidebar's full desaturation.
													grayscale={
														l.enabled ? false : 0.35
													}
												/>
											),
											active: l.code === language,
											onAction: () => {
												onPickLanguage( l.code )
												setLangSwitcherOpen( false )
											},
										} ) ) }
									/>
								</Popover>
							</Box>
							<Box width="16%" />
						</InlineStack>
					</Box>
				</Box>
				<Divider />
			</div>
			{ scanIndicator }
			{ rows.map( ( row, i ) => {
				const key = rowKey( row )
				const open = openKeys.has( key )
				const revealed =
					hoveredRowKey === key || menuOpenRowKey === key

				return (
					<Box key={ key }>
						{ i > 0 && <Divider /> }
						{ /* Same pinned-header treatment as the Content board's own
						CollectionGroup: sticky under the shared search bar (reads
						its live height off the --lingowp-group-sticky-top CSS var
						Dashboard sets on the common card ancestor), opaque so rows
						scrolling up behind it don't show through. */ }
						<div
							ref={ ( el ) => {
								if ( el ) {
									headerRefs.current.set( key, el )
								} else {
									headerRefs.current.delete( key )
								}
							} }
							onMouseEnter={ () => setHoveredRowKey( key ) }
							onMouseLeave={ () => setHoveredRowKey( null ) }
							style={ {
								position: 'sticky',
								// Stacks under this board's own column-header
								// bar (see setOwnHeaderRef above).
								top: 'var(--lingowp-gettext-row-sticky-top, 64px)',
								zIndex: 1,
								background: 'var(--p-color-bg-surface, #fff)',
							} }
						>
							<Box
								padding="300"
								background={
									open ? 'bg-surface-secondary' : undefined
								}
							>
								<InlineStack
									align="space-between"
									blockAlign="center"
									gap="200"
									wrap={ false }
								>
									<button
										type="button"
										onClick={ () => toggle( key ) }
										style={ {
											all: 'unset',
											cursor: 'pointer',
											display: 'flex',
											flex: 1,
											minWidth: 0,
											alignItems: 'center',
											gap: '8px',
										} }
									>
										<Text
											as="span"
											variant="bodyMd"
											fontWeight="medium"
										>
											{ open ? '▾' : '▸' }
										</Text>
										<span
											style={ {
												display: 'inline-flex',
												flexShrink: 0,
												width: 16,
												height: 16,
												color: 'var(--p-color-icon-subdued)',
											} }
										>
											<PageIcon
												fill="currentColor"
												width={ 16 }
												height={ 16 }
											/>
										</span>
										<Text
											as="span"
											variant="bodyMd"
											fontWeight="medium"
										>
											{ row.label }{ ' ' }
											<Text as="span" tone="subdued">
												({ row.type })
											</Text>
										</Text>
										{ domainProgressChip( row ) }
									</button>
									<InlineStack
										gap="200"
										blockAlign="center"
										wrap={ false }
									>
										{ row.ai_pending > 0 && (
											<Badge tone="info" size="small">
												{ `Translating ${ row.ai_pending }…` }
											</Badge>
										) }
										<GettextRowMenu
											row={ row }
											revealed={ revealed }
											active={ menuOpenRowKey === key }
											translating={ translatingComponents.has(
												key
											) }
											onToggle={ () =>
												setMenuOpenRowKey( ( k ) =>
													k === key ? null : key
												)
											}
											onClose={ () =>
												setMenuOpenRowKey( null )
											}
											aiAvailable={ aiAvailable }
											billingBlocked={ billingBlocked }
											onAiBlocked={ onAiBlocked }
											onTranslateAll={ () =>
												translateAllInComponent( row )
											}
											onImported={ reload }
										/>
									</InlineStack>
								</InlineStack>
							</Box>
						</div>
						<Collapsible open={ open }>
							<Box
								paddingInlineStart="1000"
								paddingBlockEnd="300"
							>
								<GettextUnitList
									row={ row }
									search={ search }
									untranslatedOnly={ untranslatedOnly }
									targetDir={ targetDir }
									onConfirm={ onConfirm }
									sourceLabel={ sourceLabel }
									sourceFlag={ sourceFlag }
									languages={ languages }
									aiAvailable={ aiAvailable }
									billingBlocked={ billingBlocked }
									onAiBlocked={ onAiBlocked }
									onPickLanguage={ onPickLanguage }
									onAiUnavailable={ onAiUnavailable }
								/>
							</Box>
						</Collapsible>
					</Box>
				)
			} ) }
			{ paginationAction && (
				<>
					<Divider />
					<Box padding="300">
						<InlineStack align="center">
							{ paginationAction }
						</InlineStack>
					</Box>
				</>
			) }
		</BlockStack>
		</div>
	)
}

// The row's "⋯" menu — same hover-reveal treatment as the Content board's own
// CollectionGroup menu. Always rendered now (not gated on aiAvailable): "Import
// .po" is offered at every stage — before any translation exists, over a
// wordpress.org language pack, or over a partial manual translation — so
// re-importing is never a dead end. "Translate all" is still added only when AI
// can act on this row. The editable string table itself lives in the expanded
// row body (GettextUnitList), reached just by opening the row.
function GettextRowMenu( {
	row,
	revealed,
	active,
	translating,
	onToggle,
	onClose,
	aiAvailable,
	billingBlocked,
	onAiBlocked,
	onTranslateAll,
	onImported,
} ) {
	const queryClient = useQueryClient()
	const [ importing, setImporting ] = useState( false )
	const [ error, setError ] = useState( '' )
	const fileRef = useRef( null )

	async function onFileChosen( e ) {
		const file = e.target.files?.[ 0 ]
		e.target.value = ''
		if ( ! file ) {
			return
		}
		if ( ! file.name.toLowerCase().endsWith( '.po' ) ) {
			setError( 'Unsupported format — only .po is accepted.' )
			return
		}

		setImporting( true )
		setError( '' )
		const body = new FormData()
		body.append( 'file', file )
		body.append( 'type', row.type )
		body.append( 'domain', row.domain )
		body.append( 'locale', row.locale )
		try {
			const res = await fetch(
				`${ ( window.LingoWP || {} ).rest }/gettext-domains/import`,
				{
					method: 'POST',
					headers: {
						'X-WP-Nonce': ( window.LingoWP || {} ).nonce,
					},
					body,
				}
			)
			const data = await res.json()
			if ( data.ok ) {
				onImported()
				// An already-open editor for this row caches its own string
				// list — refresh it so imported translations show without
				// collapsing and reopening.
				queryClient.invalidateQueries( {
					queryKey: [
						'gettext-units',
						row.type,
						row.domain,
						row.locale,
					],
				} )
			} else {
				setError(
					'Import failed — please check the file and try again.'
				)
			}
		} catch {
			setError( 'Import failed — please check the file and try again.' )
		}
		setImporting( false )
	}

	const items = [
		{
			content: 'Import .po',
			onAction: () => {
				onClose()
				fileRef.current?.click()
			},
		},
	]
	if ( aiAvailable && row.translated_percent !== 100 ) {
		items.push( {
			content: 'Translate all',
			onAction: () => {
				onClose()
				if ( billingBlocked ) {
					onAiBlocked?.()
				} else {
					onTranslateAll()
				}
			},
		} )
	}

	return (
		<InlineStack gap="100" blockAlign="center" wrap={ false }>
			<motion.div
				animate={ { opacity: revealed ? 1 : 0 } }
				transition={ { duration: 0.12 } }
				style={ { visibility: revealed ? 'visible' : 'hidden' } }
			>
				<Popover
					active={ active }
					onClose={ onClose }
					activator={
						<Button
							size="micro"
							variant="tertiary"
							icon={ MenuHorizontalIcon }
							accessibilityLabel="More actions"
							loading={ translating || importing }
							onClick={ onToggle }
						/>
					}
				>
					<ActionList items={ items } />
				</Popover>
			</motion.div>
			<input
				ref={ fileRef }
				type="file"
				accept=".po"
				hidden
				onChange={ onFileChosen }
			/>
			{ error && (
				<Text as="span" tone="critical" variant="bodySm">
					{ error }
				</Text>
			) }
		</InlineStack>
	)
}

// Server-side pagination, same as the domain/locale row list above: the
// server only ever scans+returns one page's worth of strings per request,
// not the whole component in one response.
const TEXT_PAGE_SIZE = 50

function unitKey( u ) {
	return `${ u.context || '' }\x04${ u.msgid }`
}

function GettextUnitList( {
	row,
	search,
	untranslatedOnly = false,
	targetDir,
	onConfirm,
	sourceLabel,
	sourceFlag,
	languages,
	aiAvailable,
	billingBlocked,
	onAiBlocked,
	onPickLanguage,
	onAiUnavailable,
} ) {
	const queryClient = useQueryClient()
	// search + untranslatedOnly are baked into the key so an expanded row's
	// string list narrows to the matching / still-untranslated strings while
	// the header filter is active, same as the Content board.
	const queryKey = [
		'gettext-units',
		row.type,
		row.domain,
		row.locale,
		search || '',
		untranslatedOnly,
	]
	const [ overlayIndex, setOverlayIndex ] = useState( null )

	const unitsQ = useInfiniteQuery( {
		queryKey,
		initialPageParam: 0,
		queryFn: async ( { pageParam, signal } ) => {
			const params = new URLSearchParams( {
				type: row.type,
				domain: row.domain,
				locale: row.locale,
				offset: String( pageParam ),
				limit: String( TEXT_PAGE_SIZE ),
				...( search ? { search } : {} ),
				...( untranslatedOnly ? { status: 'untranslated' } : {} ),
			} )
			const res = await api.get(
				`/gettext-domains/units?${ params.toString() }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		getNextPageParam: ( last ) =>
			last.has_more ? last.next_offset : undefined,
		refetchInterval: pollWhileGettextUnitsPending,
	} )

	const units = ( unitsQ.data?.pages ?? [] ).flatMap( ( p ) => p.units )
	const total = unitsQ.data?.pages?.at( -1 )?.total ?? units.length

	// Patch one cached unit, matched by its stable unitKey (context\x04msgid)
	// rather than a flattened array index — a poll refetch or the
	// untranslatedOnly filter can drop an earlier row between render and the
	// callback firing, which would make an index point at the wrong unit.
	function patchUnit( key, changes ) {
		queryClient.setQueryData( queryKey, ( old ) => {
			if ( ! old ) {
				return old
			}
			return {
				...old,
				pages: old.pages.map( ( p ) => ( {
					...p,
					units: p.units.map( ( u ) =>
						unitKey( u ) === key ? { ...u, ...changes } : u
					),
				} ) ),
			}
		} )
	}

	function updateUnit( key, msgstr ) {
		patchUnit( key, { msgstr } )
	}

	// A single-string AI translate was just queued — patch this row's
	// ai_pending optimistically so the shimmer + 3s polling start now,
	// without waiting on a refetch. The poll (pollWhileGettextUnitsPending)
	// takes over from here and clears it when the result lands.
	function markUnitPending( key ) {
		patchUnit( key, { ai_pending: true, ai_error: null } )
	}

	async function ignoreUnit( i ) {
		const unit = units[ i ]
		await api.post( '/gettext-domains/ignore-unit', {
			type: row.type,
			domain: row.domain,
			locale: row.locale,
			msgid: unit.msgid,
			context: unit.context,
		} )
		setOverlayIndex( null )
		// Removing one item shifts every following page's offset — simplest
		// correct fix is to refetch from page 0 rather than patch pages in place.
		queryClient.invalidateQueries( { queryKey } )
	}

	function confirmIgnore( i ) {
		onConfirm( {
			title: 'Ignore this string?',
			body: "Ignored strings are excluded from this component's translatable list.",
			actionLabel: 'Ignore',
			destructive: true,
			onConfirm: () => ignoreUnit( i ),
		} )
	}

	if ( unitsQ.isPending ) {
		return <Spinner accessibilityLabel="Loading strings" size="small" />
	}
	if ( unitsQ.isError && units.length === 0 ) {
		return (
			<InlineStack gap="200" blockAlign="center">
				<Text as="p" tone="critical" variant="bodySm">
					Could not scan this component’s source.
				</Text>
				<Button size="slim" onClick={ () => unitsQ.refetch() }>
					Retry
				</Button>
			</InlineStack>
		)
	}
	if ( units.length === 0 ) {
		return (
			<Text as="p" tone="subdued" variant="bodySm">
				{ untranslatedOnly && ! search
					? 'Every string in this component is translated for this language.'
					: untranslatedOnly && search
					? `No untranslated string matches “${ search }” here.`
					: search
					? `No string matches “${ search }” here.`
					: 'No translatable strings found in this component’s source.' }
			</Text>
		)
	}

	const overlayUnit = overlayIndex !== null ? units[ overlayIndex ] : null
	let paginationAction = null
	if ( unitsQ.isFetchNextPageError ) {
		paginationAction = (
			<Button onClick={ () => unitsQ.fetchNextPage() }>
				Retry loading more
			</Button>
		)
	} else if ( unitsQ.hasNextPage ) {
		paginationAction = (
			<Button
				onClick={ () => unitsQ.fetchNextPage() }
				loading={ unitsQ.isFetchingNextPage }
				disabled={ unitsQ.isFetchingNextPage }
			>
				Load more
			</Button>
		)
	}

	return (
		<BlockStack gap="0">
			{ units.map( ( unit, i ) => (
				<GettextUnitRow
					key={ unitKey( unit ) }
					unit={ unit }
					row={ row }
					targetDir={ targetDir }
					aiAvailable={ aiAvailable }
					billingBlocked={ billingBlocked }
					onAiBlocked={ onAiBlocked }
					onSaved={ ( msgstr ) =>
						updateUnit( unitKey( unit ), msgstr )
					}
					onQueued={ () => markUnitPending( unitKey( unit ) ) }
					onOpenOverlay={ () => setOverlayIndex( i ) }
					onIgnore={ () => confirmIgnore( i ) }
					onAiUnavailable={ onAiUnavailable }
				/>
			) ) }
			<Box padding="300">
				<InlineStack align="space-between" blockAlign="center">
					{ paginationAction }
					<Text as="p" tone="subdued" variant="bodySm">
						{ units.length } of { total }
					</Text>
				</InlineStack>
			</Box>
			<GettextOverlay
				open={ overlayUnit !== null }
				unit={ overlayUnit }
				row={ row }
				domainLabel={ row.label }
				locale={ row.locale }
				sourceLabel={ sourceLabel }
				sourceFlag={ sourceFlag }
				languages={ languages }
				aiAvailable={ aiAvailable }
				billingBlocked={ billingBlocked }
				onAiBlocked={ onAiBlocked }
				onPickLanguage={ onPickLanguage }
				onAiUnavailable={ onAiUnavailable }
				onClose={ () => setOverlayIndex( null ) }
				onQueued={ () =>
					overlayUnit &&
					markUnitPending( unitKey( overlayUnit ) )
				}
				onSaved={ ( msgstr ) =>
					overlayUnit &&
					updateUnit( unitKey( overlayUnit ), msgstr )
				}
				onPrev={ () =>
					setOverlayIndex( ( i ) => Math.max( 0, i - 1 ) )
				}
				onNext={ () =>
					setOverlayIndex( ( i ) =>
						Math.min( units.length - 1, i + 1 )
					)
				}
				hasPrev={ overlayIndex > 0 }
				hasNext={ overlayIndex < units.length - 1 }
			/>
		</BlockStack>
	)
}

function GettextUnitRow( {
	unit,
	row,
	targetDir = 'ltr',
	aiAvailable,
	billingBlocked,
	onAiBlocked,
	onSaved,
	onQueued,
	onOpenOverlay,
	onIgnore,
	onAiUnavailable,
} ) {
	const [ draft, setDraft ] = useState( unit.msgstr )
	const [ menuOpen, setMenuOpen ] = useState( false )
	const [ hovered, setHovered ] = useState( false )
	const [ translating, setTranslating ] = useState( false )
	const moreActionsRef = useRef( null )

	const hasTranslation = unit.msgstr !== ''
	const shownText = draft || unit.msgid
	// Every AI translate on this board is async now — the button submits to
	// the queue, this row's `ai_pending` (from GET /units, kept current by
	// the 3s poll) drives the shimmer, and the result lands in the .mo on a
	// later poll. `translating` only covers the instant between click and the
	// optimistic markUnitPending() patch.
	const showShimmer = translating || unit.ai_pending
	// An AI failure shows a plain "AI failed" chip (no error code) that
	// auto-clears after 10s. The failure stays in the DB; this is just a
	// transient nudge, dismissed per-unit for the session once shown.
	const [ errorDismissed, setErrorDismissed ] = useState( false )
	useEffect( () => {
		if ( unit.ai_pending || ! unit.ai_error ) {
			setErrorDismissed( false )
			return undefined
		}
		if ( errorDismissed ) {
			return undefined
		}
		const t = setTimeout( () => setErrorDismissed( true ), 10000 )
		return () => clearTimeout( t )
	}, [ unit.ai_pending, unit.ai_error, errorDismissed ] )
	const showAiFailed = ! unit.ai_pending && !! unit.ai_error && ! errorDismissed

	const originalRef = useRef( null )
	const [ isLong, setIsLong ] = useState( false )
	useLayoutEffect( () => {
		const el = originalRef.current
		if ( ! el ) {
			return
		}
		const check = () => setIsLong( el.scrollWidth > el.clientWidth + 1 )
		check()
		const ro = new ResizeObserver( check )
		ro.observe( el )
		return () => ro.disconnect()
	}, [ unit.msgid ] )

	useEffect( () => {
		setDraft( unit.msgstr )
	}, [ unit.msgstr ] )

	async function save() {
		if ( draft === unit.msgstr ) {
			return
		}
		const res = await api.post( '/gettext-domains/save-unit', {
			type: row.type,
			domain: row.domain,
			locale: row.locale,
			msgid: unit.msgid,
			context: unit.context,
			msgstr: draft,
		} )
		if ( res.ok ) {
			onSaved( draft )
		}
	}

	async function translateUnit() {
		setTranslating( true )
		let res
		try {
			res = await api.post( '/gettext-domains/translate', {
				scope: 'unit',
				type: row.type,
				domain: row.domain,
				locale: row.locale,
				msgid: unit.msgid,
				context: unit.context,
			} )
		} catch {
			res = { ok: false, error: 'ERR_UNREACHABLE' }
		}
		setTranslating( false )
		if ( res.ok ) {
			// Queued — flip this row to pending so the shimmer + poll take
			// over; the translated .mo entry arrives on a later poll.
			onQueued?.()
		} else if ( res.error !== 'ERR_INVALID_INPUT' ) {
			onAiUnavailable?.( res.error )
		}
	}

	const menuItems = [
		{
			content: 'Open in overlay',
			onAction: () => {
				setMenuOpen( false )
				onOpenOverlay()
			},
		},
		{
			content: 'Ignore',
			destructive: true,
			onAction: () => {
				setMenuOpen( false )
				onIgnore()
			},
		},
	]

	// The AI-translate button unmounts while generating (AnimatePresence) —
	// move focus to the adjacent "More actions" button first so a keyboard
	// user never loses focus to <body>.
	function handleAiClick() {
		moreActionsRef.current?.querySelector( 'button' )?.focus()
		translateUnit()
	}

	return (
		<div
			onMouseEnter={ () => setHovered( true ) }
			onMouseLeave={ () => setHovered( false ) }
		>
			<Box paddingInline="300" paddingBlock="200">
				<InlineStack
					gap="300"
					align="space-between"
					blockAlign="center"
					wrap={ false }
				>
					<Box width="45%" minWidth="0">
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '4px',
								minWidth: 0,
							} }
						>
							{ /* div, not button — buttons block native text
							selection, and the original text must stay
							copyable by drag/selection (same fix as the
							Content board's own original-text cell). */ }
							<div
								ref={ originalRef }
								role={ isLong ? 'button' : undefined }
								tabIndex={ isLong ? 0 : undefined }
								onClick={
									isLong ? onOpenOverlay : undefined
								}
								onKeyDown={
									isLong
										? ( e ) => {
												if (
													e.key === 'Enter' ||
													e.key === ' '
												) {
													e.preventDefault()
													onOpenOverlay()
												}
										  }
										: undefined
								}
								style={ {
									...oneLineStyle,
									flex: 1,
									minWidth: 0,
									cursor: isLong ? 'pointer' : 'default',
									userSelect: 'text',
								} }
							>
								<Text as="span" variant="bodySm">
									{ unit.msgid }
								</Text>
							</div>
						</div>
					</Box>

					<Box width="45%" minWidth="0">
						<div
							dir={ targetDir }
							style={ { position: 'relative', minWidth: 0 } }
						>
							{ isLong ? (
								<motion.button
									type="button"
									onClick={ onOpenOverlay }
									animate={ {
										opacity: showShimmer ? 0 : 1,
									} }
									transition={ { duration: 0.15 } }
									style={ oneLineStyle }
								>
									<Text
										as="span"
										variant="bodySm"
										tone={
											hasTranslation
												? undefined
												: 'subdued'
										}
									>
										{ unit.msgstr || unit.msgid }
									</Text>
								</motion.button>
							) : (
								<motion.input
									type="text"
									aria-label="Translation"
									value={ draft }
									onChange={ ( e ) =>
										setDraft( e.target.value )
									}
									onBlur={ save }
									placeholder={ unit.msgid }
									animate={ {
										opacity: showShimmer ? 0 : 1,
									} }
									transition={ { duration: 0.15 } }
									style={ {
										all: 'unset',
										display: 'block',
										width: '100%',
										minWidth: 0,
										cursor: 'text',
										fontSize:
											'var(--p-font-size-300, 0.75rem)',
										lineHeight:
											'var(--p-font-line-height-300, 1rem)',
										color: hasTranslation
											? 'var(--p-color-text)'
											: 'var(--p-color-text-subdued)',
									} }
								/>
							) }
							{ /* No AnimatePresence here on purpose — an exit
							transition would keep this mounted over the just-
							arrived real text and read as a stall. It gets an
							enter fade only; completion removes it instantly. */ }
							{ showShimmer && (
								<motion.span
									aria-hidden="true"
									className="lingowp-shimmer"
									initial={ { opacity: 0 } }
									animate={ { opacity: 1 } }
									transition={ { duration: 0.15 } }
									style={ {
										position: 'absolute',
										inset: 0,
										whiteSpace: 'nowrap',
										overflow: 'hidden',
										textOverflow: 'ellipsis',
										fontSize:
											'var(--p-font-size-300, 0.75rem)',
										lineHeight:
											'var(--p-font-line-height-300, 1rem)',
									} }
								>
									{ shownText }
								</motion.span>
							) }
						</div>
					</Box>

					<Box width="16%">
						<InlineStack
							gap="100"
							align="end"
							blockAlign="center"
							wrap={ false }
						>
							{ hasTranslation && (
								<Badge tone="success" size="small">
									Translated
								</Badge>
							) }
							{ showAiFailed && (
								<Badge tone="critical" size="small">
									AI failed
								</Badge>
							) }
							<motion.div
								animate={ {
									opacity: hovered || menuOpen ? 1 : 0,
								} }
								transition={ { duration: 0.12 } }
								style={ {
									visibility:
										hovered || menuOpen
											? 'visible'
											: 'hidden',
								} }
							>
								<InlineStack
									gap="100"
									blockAlign="center"
									wrap={ false }
								>
									{ aiAvailable && (
										<div
											style={ {
												position: 'relative',
												flexShrink: 0,
											} }
										>
											{ /* Invisible ghost, same markup as
											the real button below — reserves
											its exact footprint so the slot
											never shifts when the real button
											mounts/unmounts. */ }
											<div
												aria-hidden="true"
												style={ {
													visibility: 'hidden',
												} }
											>
												<Tooltip content="AI translate">
													<Button
														variant="secondary"
														size="micro"
														icon={ ( props ) => (
															<AiIcon
																{ ...props }
																size={ 12 }
															/>
														) }
													/>
												</Tooltip>
											</div>
											<AnimatePresence initial={ false }>
												{ ! translating && (
													<motion.div
														key="ai-translate"
														initial={ {
															opacity: 0,
															scale: 0.92,
														} }
														animate={ {
															opacity: 1,
															scale: 1,
														} }
														exit={ {
															opacity: 0,
															scale: 0.92,
														} }
														transition={ {
															duration: 0.2,
															ease: [
																0.4, 0, 0.2, 1,
															],
														} }
														style={ {
															position:
																'absolute',
															inset: 0,
														} }
													>
														<span
															style={
																billingBlocked
																	? {
																			opacity: 0.5,
																			cursor: 'not-allowed',
																	  }
																	: undefined
															}
														>
															<Button
																variant="secondary"
																size="micro"
																icon={ (
																	props
																) => (
																	<AiIcon
																		{ ...props }
																		size={
																			12
																		}
																	/>
																) }
																accessibilityLabel="AI translate"
																onClick={
																	billingBlocked
																		? onAiBlocked
																		: handleAiClick
																}
															/>
														</span>
													</motion.div>
												) }
											</AnimatePresence>
										</div>
									) }
									<span
										ref={ moreActionsRef }
										style={ { display: 'contents' } }
									>
										<Popover
											active={ menuOpen }
											onClose={ () =>
												setMenuOpen( false )
											}
											activator={
												<Button
													variant="secondary"
													size="micro"
													icon={ ( props ) => (
														<MenuHorizontalIcon
															{ ...props }
															style={ {
																...props.style,
																transform:
																	'scale(0.7)',
															} }
														/>
													) }
													accessibilityLabel="More actions"
													onClick={ () =>
														setMenuOpen(
															( o ) => ! o
														)
													}
												/>
											}
										>
											<ActionList items={ menuItems } />
										</Popover>
									</span>
								</InlineStack>
							</motion.div>
						</InlineStack>
					</Box>
				</InlineStack>
			</Box>
		</div>
	)
}
