import {
	ActionList,
	Badge,
	BlockStack,
	Box,
	Button,
	Card,
	ChoiceList,
	Collapsible,
	Divider,
	EmptyState,
	Filters,
	InlineStack,
	Modal,
	Page,
	Popover,
	Spinner,
	Text,
	TextField,
	Thumbnail,
	Toast,
	Tooltip,
} from '@shopify/polaris'
import {
	useInfiniteQuery,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query'
import { useWindowVirtualizer } from '@tanstack/react-virtual'
import {
	lazy,
	Suspense,
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element'
import {
	AlertTriangleIcon,
	CheckCircleIcon,
	InfoIcon,
	MenuHorizontalIcon,
	PageIcon,
	SettingsIcon,
} from '@shopify/polaris-icons'
import { parseAsString, parseAsStringLiteral, useQueryState } from 'nuqs'
import { AnimatePresence, motion } from 'motion/react'
import { api } from '../api'
import { ensureConnected } from '../connection'
import { errorMessage } from '../errors'
import { AiIcon } from './AiIcon'
import LanguageList, { PUBLISH_MIN_PERCENT } from './LanguageList'
import { flagIconUrl } from './FlagIcon'
import CompareOverlay from './CompareOverlay'
import QuotaCard from './QuotaCard'
import { hasMarkers, stripMarkers } from './TranslationUnitEditor'
import { lengthWarningMessage } from './lengthWarning.mjs'

// Split out of the main bundle: each is a modal/screen opened only by an
// explicit action, never needed for first paint — GettextBoard behind the
// Plugins & Themes tab (Content is the default), the other behind its own
// "open" state (see managerEverOpened below, which keeps it mounted after
// its first open so Polaris' Modal still gets to run its own close
// transition instead of being yanked out mid-exit).
const GettextBoard = lazy( () =>
	import( /* webpackChunkName: "gettext-board" */ './GettextBoard' )
)
const LanguageManagerModal = lazy( () =>
	import(
		/* webpackChunkName: "language-manager-modal" */ './LanguageManagerModal'
	)
)

// Schema status → workspace pill. Completed rows keep a visible marker so table
// progress is scannable even when the row is not hovered.
const STATUS_BADGE = {
	missing: null,
	needs_review: { tone: 'warning', label: 'Review' },
	reviewed: { tone: 'success', label: 'Done' },
	translated: { tone: 'success', label: 'Done' },
}

// Toast tone → icon. Polaris' Toast has no native warning/success color, only
// a neutral default and a red `error` state — the icon is what keeps these
// tones visually distinct. 'critical' isn't listed here: it uses Toast's own
// `error` prop, which renders its own built-in icon.
const TOAST_TONE_ICON = {
	success: CheckCircleIcon,
	warning: AlertTriangleIcon,
	info: InfoIcon,
}

// Which SEO field a meta row represents — shown as a small badge alongside the
// status pill so title/description/keyword rows for the same post are
// distinguishable at a glance.
const SEO_FIELD_BADGE = {
	title: 'SEO title',
	description: 'Meta description',
	keyword: 'Focus keyword',
	og_title: 'OG title',
	og_description: 'OG description',
	twitter_title: 'Twitter title',
	twitter_description: 'Twitter description',
}

// Truncate a cell to a single line with an ellipsis; the whole cell is a
// clickable button that opens the overlay to read/edit the full text.
// ponytail: stays inline — `all: unset` must sit in the same declaration as the
// properties it resets, otherwise inline precedence wipes out equivalent utilities.
const oneLineStyle = {
	all: 'unset',
	cursor: 'pointer',
	display: 'block',
	width: '100%',
	whiteSpace: 'nowrap',
	overflow: 'hidden',
	textOverflow: 'ellipsis',
}

// Collection type → group heading prefix. Site settings has no prefix.
function groupHeading( { type, label } ) {
	const prefix = {
		page: 'Pages',
		post: 'Posts',
		cpt: 'Custom',
		taxonomy: 'Taxonomies',
		plugin: 'Plugin',
		theme: 'Theme',
	}[ type ]
	return prefix ? `${ prefix } · ${ label }` : label
}

const enc = encodeURIComponent
const cfg = window.Translangs || {}

// Open a text's destination in a new tab. Front-end pages get a scroll-to-text
// fragment so the browser jumps to (and highlights) the string being verified;
// admin editors (taxonomy/options) open as-is. Best-effort: a no-match snippet
// just lands on the page.
function openCanvas( url, target, snippet ) {
	if ( ! url ) {
		return
	}
	const final =
		target === 'frontend' && snippet
			? `${ url }#:~:text=${ enc( snippet ) }`
			: url
	window.open( final, '_blank', 'noopener' )
}

// Header status filters — sent as ?status= to the server now.
const FILTERS = [
	{ key: 'untranslated', label: 'Untranslated' },
	{ key: 'needs_review', label: 'Needs review' },
	{ key: 'translated', label: 'Translated' },
]
const ORIGIN_FILTERS = [
	{ key: 'manual', label: 'Manual' },
	{ key: 'ai', label: 'AI' },
	{ key: 'not_translated', label: 'Not translated' },
]
const CONTENT_TYPE_FILTERS = [
	{ key: 'page', label: 'Pages' },
	{ key: 'post', label: 'Posts' },
	{ key: 'cpt', label: 'Custom post types' },
	{ key: 'taxonomy', label: 'Taxonomies' },
	{ key: 'media', label: 'Media' },
	{ key: 'site_settings', label: 'Site settings' },
	{ key: 'html', label: 'Template content' },
	{ key: 'plugin', label: 'Plugin text' },
	{ key: 'theme', label: 'Theme text' },
]
const TEXT_LENGTH_FILTERS = [
	{ key: 'short', label: 'Short text' },
	{ key: 'medium', label: 'Medium text' },
	{ key: 'long', label: 'Long text' },
	// Same 30%/15-char rule as the inline length-warning toast (lengthWarning.mjs).
	{ key: 'length_mismatch', label: 'Length mismatch (30%+)' },
]
const PROGRESS_FILTERS = [
	{ key: 'not_started', label: 'Not started' },
	{ key: 'in_progress', label: 'In progress' },
	{ key: 'complete', label: 'Complete' },
	{ key: 'has_review', label: 'Has review items' },
]

function filterQueryParams( filters ) {
	return Object.entries( filters )
		.filter( ( [ , values ] ) => values.length > 0 )
		.map(
			( [ key, values ] ) => `&${ key }=${ enc( values.join( ',' ) ) }`
		)
		.join( '' )
}

// Search runs server-side (?search= on /collections and /collections/{id}/texts),
// matching source text OR its translation in the selected language.
// 300ms because each keystroke refetches every group's counts.
const SEARCH_DEBOUNCE_MS = 300
// One letter matches almost everything; below this the box is inert.
const SEARCH_MIN = 2
// Collections page size (matches CollectionRestController's DEFAULT_LIMIT).
const PAGE_SIZE = 20
// Rows revealed per "Show more" click in a group — an auto-opened 1,000-text
// group never dumps every row at once.
const TEXT_BATCH = 50
const boardParser = parseAsStringLiteral( [ 'content', 'plugins' ] )
	.withDefault( 'content' )
	.withOptions( { history: 'replace' } )
// Language codes are dynamic (whatever the site has configured), so this
// can't be a parseAsStringLiteral like board — plain string is enough since
// refreshLanguages() already validates the value against the fetched list.
const langParser = parseAsString.withDefault( '' ).withOptions( {
	history: 'replace',
} )

export default function Dashboard( {
	defaultLanguage,
	onboardingCompleted,
	onStartOnboarding,
} ) {
	const queryClient = useQueryClient()
	const [ languages, setLanguages ] = useState( [] )
	// Distinguishes "no languages yet" (still loading) from "confirmed empty" —
	// without it, the rail briefly renders an empty list + a bare "+ Add
	// language" button before the first /languages response lands.
	const [ languagesLoaded, setLanguagesLoaded ] = useState( false )
	// null means the REST request is still pending; an empty array is a completed
	// response with no additional WordPress locales available.
	const [ languageCatalog, setLanguageCatalog ] = useState( null )
	// Persisted in the URL so a refresh or a shared link keeps the working
	// language (mirrors `board` just below).
	const [ selectedLang, setSelectedLang ] = useQueryState(
		'lang',
		langParser
	)
	const [ search, setSearch ] = useState( '' )
	// The debounced term actually sent to the server ("" = not searching).
	const [ query, setQuery ] = useState( '' )
	// { id, cid } of the text unit open in the compare overlay, or null.
	const [ overlay, setOverlay ] = useState( null )
	// Collection id to scroll to once loaded, read once from a shared link's
	// "#collection=<id>" hash (view/board/lang are restored via nuqs already —
	// the hash just isn't part of that persisted URL state). Cleared once found
	// (or given up on) so a later refresh doesn't re-trigger the jump.
	const [ pendingScrollId, setPendingScrollId ] = useState( () => {
		const match = window.location.hash.match( /^#collection=(.+)$/ )
		return match ? decodeURIComponent( match[ 1 ] ) : null
	} )
	const [ managerOpen, setManagerOpen ] = useState( false )
	const [ managerInitial, setManagerInitial ] = useState( null )
	// Latches true on first open and never resets — lets LanguageManagerModal's
	// lazy chunk load only when actually needed, while staying mounted for
	// every open/close after that (so its own Polaris close transition still
	// gets to run, instead of the component being unmounted mid-exit).
	const [ managerEverOpened, setManagerEverOpened ] = useState( false )
	const [ pageMenuOpen, setPageMenuOpen ] = useState( false )
	const [ refreshing, setRefreshing ] = useState( false )
	// True right after the first language is created, until the initial
	// content scan (running since plugin activation, independent of
	// onboarding) reports done — gates the board body with a loader instead
	// of an empty content list.
	const [ extractionPending, setExtractionPending ] = useState( false )
	const [ confirm, setConfirm ] = useState( null )
	const [ ai, setAi ] = useState( { available: false, error: '' } )
	const [ quota, setQuota ] = useState( null )
	// A /license/connect confirm email is on its way — nothing is connected
	// yet, so this must never be shown/read as an error.
	const [ connectPending, setConnectPending ] = useState( false )
	// Optimistically false until entitlements + provider-keys load, so the AI
	// buttons don't flash into their blocked state on first paint.
	const [ billingBlocked, setBillingBlocked ] = useState( false )
	// Toast — every "action complete" message (AI translate, rescan, fallback
	// copy, autosave hint, length warning, share link, …) goes through this
	// one. Never a top-of-page Banner: that shifted the whole board down every
	// time it appeared/disappeared.
	const [ toast, setToast ] = useState( null ) // { tone, content } | null
	// Which "Translate All" job (if any) is running: null | 'language' | <collectionId>.
	// Rows with no translation shimmer while this matches their scope — approximate on
	// purpose, no tracking of which exact refs the API call actually included.
	const [ bulkTranslating, setBulkTranslating ] = useState( null )
	const [ statusFilter, setStatusFilter ] = useState( 'all' )
	const [ originFilter, setOriginFilter ] = useState( [] )
	const [ contentTypeFilter, setContentTypeFilter ] = useState( [] )
	const [ textLengthFilter, setTextLengthFilter ] = useState( [] )
	const [ progressFilter, setProgressFilter ] = useState( [] )
	// Which board is showing: today's Content tree, or gettext grouped by
	// plugin/theme. The URL is the source of truth so refreshes and shared links
	// retain the selection; replacements keep tab changes out of browser history.
	const [ board, setBoard ] = useQueryState( 'board', boardParser )

	function selectBoard( next ) {
		setBoard( next )
	}

	// A deep-linked collection must be reachable regardless of whatever filters
	// happen to be active, so a pending scroll target clears them up front.
	useEffect( () => {
		if ( ! pendingScrollId ) {
			return
		}
		setStatusFilter( 'all' )
		setOriginFilter( [] )
		setContentTypeFilter( [] )
		setTextLengthFilter( [] )
		setProgressFilter( [] )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- one-time on mount
	}, [] )

	// tone: 'success' | 'warning' | 'critical' | 'info'. Dismiss timing is
	// handled by Polaris' own Toast component (see the render below).
	function showToast( tone, content ) {
		setToast( { tone, content } )
	}

	// TextUnitRow's inline edit and CompareOverlay's autosave both report length
	// warnings up through this same prop.
	const showLengthWarning = ( message ) => showToast( 'warning', message )

	const defaultCode = defaultLanguage?.code || defaultLanguage
	const advancedFilters = {
		origin: originFilter,
		content_type: contentTypeFilter,
		text_length: textLengthFilter,
		progress: progressFilter,
	}
	const advancedFilterParams = filterQueryParams( advancedFilters )
	const hasAdvancedFilters = Object.values( advancedFilters ).some(
		( values ) => values.length > 0
	)
	const hasAnyFilter = statusFilter !== 'all' || hasAdvancedFilters

	// Open the language manager, optionally focused on a specific language.
	function openManager( code ) {
		setManagerInitial( code || 'new' )
		setManagerOpen( true )
		setManagerEverOpened( true )
	}

	// The one place entitlements gets fetched — mount, and after every AI
	// translate (usage changed). Also derives billingBlocked from the same
	// response instead of a separate request, and feeds the quota rail.
	const refetchEntitlements = useCallback( () => {
		return Promise.all( [
			api.get( '/billing/entitlements' ),
			api.get( '/provider-keys' ),
		] ).then( ( [ entitlementsRes, keysRes ] ) => {
			setQuota( entitlementsRes.ok ? entitlementsRes.entitlements : null )
			const subscribed = !! entitlementsRes.entitlements?.active
			const hasOwnKey = ( keysRes.keys || [] ).length > 0
			setBillingBlocked( ! subscribed && ! hasOwnKey )
		} )
	}, [] )

	// Validate an existing cloud connection on page load. A fresh local-only
	// install stops at ERR_NOT_CONNECTED and makes no external request; the
	// explicit QuotaCard action below is the sole first-connect path.
	useEffect( () => {
		ensureConnected().then( ( status ) => {
			setAi( { available: status.available, error: status.error } )
			setConnectPending( status.pending )
			if ( status.connected && ! status.available ) {
				showToast( 'warning', errorMessage( status.error ) )
			}
			if ( status.connected ) {
				refetchEntitlements()
			} else {
				setQuota( null )
				setBillingBlocked( true )
			}
		} )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- refetchEntitlements is stable
	}, [] )

	// Explicit, user-initiated connect/reconnect — the only place
	// /connection/connect is called from, since it can send an email and must
	// never loop or resend on its own.
	function handleConnect() {
		api.post( '/connection/connect' ).then( ( res ) => {
			if ( res.pending ) {
				setConnectPending( true )
				return
			}
			if ( res.ok ) {
				setAi( { available: !! res.ai_available, error: '' } )
				setConnectPending( false )
				refetchEntitlements()
				return
			}
			showToast( 'warning', errorMessage( res.error || 'ERR_BACKEND' ) )
		} )
	}

	// Row AI buttons route here instead of translating when billingBlocked —
	// a toast the user can click straight through to Billing, rather than a
	// silent disabled button.
	function handleAiBlocked() {
		setToast( {
			tone: 'info',
			content:
				'Subscribe or add your own API key to use AI translation. Tap to view plans.',
			onClick: () => window.location.assign( cfg.billing_url ),
		} )
	}

	useEffect( () => {
		function handleShortcut( event ) {
			if (
				( event.ctrlKey || event.metaKey ) &&
				event.key.toLowerCase() === 's'
			) {
				event.preventDefault()
				showToast( 'info', 'Auto save is on.' )
			}
		}

		window.addEventListener( 'keydown', handleShortcut )
		return () => window.removeEventListener( 'keydown', handleShortcut )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- showToast is stable across renders
	}, [] )

	// AI translate a scope (text | collection | language), then refresh views + quota.
	async function translateScope( scope, opts = {} ) {
		if ( ! ai.available ) {
			showToast(
				'warning',
				ai.error || 'AI unavailable — connect Translangs to enable it.'
			)
			return
		}
		// A caller (e.g. CompareOverlay translating one text into every configured
		// language) can target a language other than the dashboard's currently
		// selected one — every cache op below must key off THIS call's language,
		// not selectedLang, or translations into other languages never invalidate
		// their own cached views.
		const lang = opts.language ?? selectedLang
		const res = await api.post( '/translate', {
			scope,
			language: selectedLang,
			...opts,
		} )
		if ( ! res.ok ) {
			// Anything other than quota/input errors here means the backend call
			// itself failed — PHP already recorded that (ConnectionStatus), so
			// reflect it locally too instead of leaving `ai.available` stale.
			if ( res.error !== 'ERR_INVALID_INPUT' ) {
				setAi( { available: false, error: res.error } )
			}
			showToast( 'critical', 'AI translation failed. Try again.' )
			return
		}
		// Some items can be rejected while the rest save (a translation that dropped
		// or invented a protected variable/tag would break the rendered output). The
		// run still succeeded, so this is a warning, not a failure — but it must be
		// visible, or the user just sees rows that silently stayed untranslated.
		const failed = res.failed || 0
		showToast(
			failed > 0 ? 'warning' : 'success',
			`Translated ${ res.translated } text${
				res.translated === 1 ? '' : 's'
			}.${ failed > 0 ? ` ${ failed } rejected.` : '' }`
		)
		if ( res.collection_summary ) {
			patchCollectionSummary( res.collection_summary, lang )
		} else {
			invalidateCollections( lang )
		}
		if ( res.updated_unit ) {
			// scope 'text' resolves to one unit; propagate it to any other visible
			// occurrence of the same text (its own group is invalidated below anyway).
			propagateTranslation(
				opts.collection_id ?? opts.id,
				res.updated_unit,
				lang
			)
		}
		// Every mounted group's texts for this language may have changed (a scope
		// or language-wide AI translate isn't limited to one group).
		queryClient.invalidateQueries( {
			predicate: ( q ) =>
				q.queryKey[ 0 ] === 'texts' && q.queryKey[ 2 ] === lang,
		} )
		refetchEntitlements()
		refreshLanguages() // rail percentages changed
		return res
	}

	// Rail "Translate All": select that language, confirm, then AI-translate it whole.
	function translateLanguageAll( lang ) {
		setSelectedLang( lang.code )
		setConfirm( {
			title: `AI translate all of ${
				lang.display_name || lang.name || lang.code
			}?`,
			body: 'All untranslated text in this language will be sent to AI translation and may use a large number of words.',
			actionLabel: 'Translate all',
			onConfirm: async () => {
				setBulkTranslating( 'language' )
				await translateScope( 'language', { language: lang.code } )
				setBulkTranslating( null )
			},
		} )
	}

	function patchLangStatus( code, status ) {
		api.patch( `/languages/${ enc( code ) }`, { status } ).then(
			( res ) => {
				if ( res.ok ) {
					refreshLanguages()
				}
			}
		)
	}

	function deleteLang( code ) {
		api.del( `/languages/${ enc( code ) }` ).then( ( res ) => {
			if ( res.ok ) {
				setSelectedLang( ( cur ) => ( cur === code ? '' : cur ) )
				refreshLanguages()
			}
		} )
	}

	function confirmDeleteLang( lang ) {
		setConfirm( {
			title: `Delete ${ lang.display_name || lang.name || lang.code }?`,
			body: 'This removes it from the public site and the workspace. Its translations are kept and restored if you re-add the language.',
			actionLabel: 'Delete',
			destructive: true,
			onConfirm: () => deleteLang( lang.code ),
		} )
	}

	// Draft → active is "publish"; below PUBLISH_MIN_PERCENT it asks for
	// confirmation first, since visitors would see mostly-fallback content.
	function togglePublishStatus( lang ) {
		if ( lang.enabled ) {
			patchLangStatus( lang.code, 'draft' )
			return
		}
		const percent = lang.translated_percent ?? 0
		if ( percent >= PUBLISH_MIN_PERCENT ) {
			patchLangStatus( lang.code, 'active' )
			return
		}
		setConfirm( {
			title: `Publish ${
				lang.display_name || lang.name || lang.code
			} at ${ percent }% translated?`,
			body: 'Some pages are not fully translated yet. Publishing now may cause search engines to index fallback content in the wrong language, which could affect your SEO. You can continue translating after publishing.',
			actionLabel: 'Publish anyway',
			onConfirm: () => patchLangStatus( lang.code, 'active' ),
		} )
	}

	// Rail "Use fallback translation": bulk-copy the fallback language's existing
	// translations into every text this language is missing (marked Needs
	// review). LanguageList only enables this when the fallback shares the same
	// base language, but the backend re-validates it too.
	function applyFallbackTranslation( lang ) {
		setSelectedLang( lang.code )
		const fb = languages.find( ( l ) => l.code === lang.fallback_language )
		const fbLabel = fb
			? fb.display_name || fb.name || fb.code
			: lang.fallback_language
		setConfirm( {
			title: `Use ${ fbLabel } translations for ${
				lang.display_name || lang.name || lang.code
			}?`,
			body: "Fills in every text this language is missing using its fallback language's existing translations, marked as Needs review. Existing translations are never overwritten.",
			actionLabel: 'Use fallback translation',
			onConfirm: async () => {
				const res = await api.post(
					`/languages/${ enc( lang.code ) }/use-fallback`
				)
				if ( ! res.ok ) {
					showToast( 'critical', 'Could not copy translations.' )
					return
				}
				showToast(
					'success',
					`Copied ${ res.copied } translation${
						res.copied === 1 ? '' : 's'
					} from ${ fbLabel }.`
				)
				invalidateCollections()
				queryClient.invalidateQueries( {
					predicate: ( q ) =>
						q.queryKey[ 0 ] === 'texts' &&
						q.queryKey[ 2 ] === lang.code,
				} )
				refreshLanguages() // rail percentages changed
			},
		} )
	}

	function refreshLanguages() {
		api.get( '/languages' ).then( ( res ) => {
			if ( ! res.ok ) {
				// Resolve the pending state even when the request fails so the modal
				// never leaves the user behind an endless loading message.
				setLanguageCatalog( [] )
				setLanguagesLoaded( true )
				return
			}
			const langs = res.languages || []
			setLanguageCatalog( res.catalog || [] )
			setLanguages( langs )
			setLanguagesLoaded( true )
			setSelectedLang( ( cur ) => {
				if ( cur && langs.some( ( l ) => l.code === cur ) ) {
					return cur
				}
				const active = langs.find( ( l ) => l.enabled ) || langs[ 0 ]
				return active?.code || ''
			} )
		} )
	}

	// setSelectedLang (from useQueryState) is stable across renders like a
	// useState setter — nuqs's own examples omit it from effect deps too.
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( refreshLanguages, [] )

	// Debounce the box into the term we actually query with. Below SEARCH_MIN the term
	// counts as empty, so backspacing to one letter restores the full tree.
	useEffect( () => {
		const q = search.trim()
		const id = setTimeout(
			() => setQuery( q.length < SEARCH_MIN ? '' : q ),
			SEARCH_DEBOUNCE_MS
		)
		return () => clearTimeout( id )
	}, [ search ] )

	const searchParam = ( q ) => ( q ? `&search=${ enc( q ) }` : '' )

	// Collections, paged. Language/search/status/limit live in the key, so any
	// change refetches from page 0 automatically — no manual reset effect needed.
	const groupsQ = useInfiniteQuery( {
		queryKey: [
			'collections',
			{
				language: selectedLang,
				search: query,
				status: statusFilter,
				source: board,
				filters: advancedFilters,
				limit: PAGE_SIZE,
			},
		],
		initialPageParam: 0,
		queryFn: async ( { pageParam, signal } ) => {
			const res = await api.get(
				`/collections?language=${ enc(
					selectedLang
				) }&offset=${ pageParam }&limit=${ PAGE_SIZE }` +
					`${ searchParam( query ) }&status=${ enc(
						statusFilter
					) }&source=${ enc( board ) }${ advancedFilterParams }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		getNextPageParam: ( last ) =>
			last.has_more ? last.next_offset : undefined,
		// Plugins & Themes has its own board (GettextBoard) now — no need to
		// fetch the collections-count list while that tab is active.
		enabled: !! selectedLang && board === 'content',
	} )

	function invalidateCollections( lang = selectedLang ) {
		queryClient.invalidateQueries( {
			predicate: ( q ) =>
				q.queryKey[ 0 ] === 'collections' &&
				q.queryKey[ 1 ]?.language === lang,
		} )
	}

	// Manual "recheck the site for missing text": re-arm the same scan that runs
	// on activation, then poll status the same way pollFirstScan does after the
	// first language is created. On completion, refetch the board so newly
	// discovered text shows up without a full page reload.
	//
	// The HTML crawl also gets (re-)triggered by this same click, but it is
	// deliberately NOT part of what's polled below — it's network-bound (one
	// fetch per page) and can run far longer than the DB-only scan on a large
	// site. Waiting for it would hold the dashboard hostage for no reason: the
	// crawl keeps filling in tl_html in the background regardless, and newly
	// discovered hardcoded text just shows up next time the html:all group is
	// fetched (a manual refresh, reopening the group, etc.) — no spinner needed.
	function refreshScan() {
		if ( refreshing ) {
			return
		}
		setRefreshing( true )
		showToast( 'info', 'Rescanning your site…' )
		api.post( '/scan/refresh' ).then( ( res ) => {
			pollScanStatus( res.html_status === 'running' )
		} )
	}

	function pollScanStatus( htmlCrawlRunning ) {
		api.get( '/scan/status' ).then( ( res ) => {
			const status = res.status || 'running'
			if ( status === 'running' ) {
				setTimeout( () => pollScanStatus( htmlCrawlRunning ), 3000 )
				return
			}
			setRefreshing( false )
			if ( status === 'completed' ) {
				showToast(
					'success',
					htmlCrawlRunning
						? 'Scan complete — still scanning for more text.'
						: 'Scan complete.'
				)
				invalidateCollections()
			} else {
				showToast( 'critical', 'Scan failed. Try again.' )
			}
		} )
	}

	// Same poll-until-done shape as pollScanStatus, but gates the board body
	// (via extractionPending) instead of a toast — used once, right after the
	// very first language is created, so the user never sees an empty content
	// list while the activation-time scan is still filling it in.
	function pollFirstScan() {
		api.get( '/scan/status' ).then( ( res ) => {
			const status = res.status || 'running'
			if ( status === 'running' ) {
				setTimeout( pollFirstScan, 3000 )
				return
			}
			setExtractionPending( false )
			if ( status === 'completed' ) {
				invalidateCollections()
			}
		} )
	}

	// Copies a link that lands on this same board/language and scrolls straight
	// to this collection (view/board/lang restore via the URL state nuqs already
	// keeps in sync; the collection id rides in the hash — see pendingScrollId).
	async function shareCollection( c ) {
		const url = new URL( window.location.href )
		url.searchParams.set( 'view', 'dashboard' )
		url.searchParams.set( 'board', board )
		url.searchParams.set( 'lang', selectedLang )
		url.hash = `collection=${ enc( c.id ) }`
		await navigator.clipboard.writeText( url.toString() )
		showToast( 'success', 'Link copied.' )
	}

	// A mutation response that resolved to one collection's fresh counts
	// (`collection_summary`) patches the cached board directly — no refetch
	// round-trip. Also keeps the header total_texts/total_translated in step
	// (derives their delta from the one collection's old vs. new numbers), and
	// drops the row if it no longer matches the active status filter (mirrors
	// what a real refetch would do server-side).
	function patchCollectionSummary( summary, lang = selectedLang ) {
		if ( hasAdvancedFilters ) {
			queryClient.invalidateQueries( { queryKey: [ 'collections' ] } )
			queryClient.invalidateQueries( { queryKey: [ 'texts' ] } )
			queryClient.invalidateQueries( { queryKey: [ 'text-order' ] } )
			return
		}

		const stillMatchesFilter =
			statusFilter === 'all' ||
			( statusFilter === 'untranslated' && summary.missing > 0 ) ||
			( statusFilter === 'needs_review' && summary.needs_review > 0 ) ||
			( statusFilter === 'translated' && summary.translated > 0 )

		queryClient.setQueriesData(
			{
				predicate: ( q ) =>
					q.queryKey[ 0 ] === 'collections' &&
					q.queryKey[ 1 ]?.language === lang,
			},
			( old ) => {
				if ( ! old ) {
					return old
				}
				const delta = { total: 0, translated: 0 }
				const pages = old.pages.map( ( p ) => {
					if (
						! p.collections.some( ( c ) => c.id === summary.id )
					) {
						return p
					}
					const collections = p.collections.flatMap( ( c ) => {
						if ( c.id !== summary.id ) {
							return [ c ]
						}
						delta.total = summary.total - c.total
						delta.translated = summary.translated - c.translated
						return stillMatchesFilter
							? [ { ...c, ...summary } ]
							: []
					} )
					return { ...p, collections }
				} )
				const found = pages.some(
					( page, index ) => page !== old.pages[ index ]
				)
				if ( ! found ) {
					return old
				}
				return {
					...old,
					pages: pages.map( ( p ) => ( {
						...p,
						total_texts: p.total_texts + delta.total,
						total_translated: p.total_translated + delta.translated,
					} ) ),
				}
			}
		)
	}

	// Hash-link kinds (post/term/html/option) share one translation row across
	// every position with the same source text — a save in one group doesn't
	// magically update another, already-cached group showing the same text.
	// Patch every OTHER cached group's matching row (by original_hash) directly,
	// and recompute that group's summary from the patched array. Skips the
	// group just edited — that one already gets its normal targeted invalidate.
	function propagateTranslation(
		editedCid,
		updatedUnit,
		lang = selectedLang
	) {
		const hash = updatedUnit?.original_hash
		if ( ! hash ) {
			return // meta / unlinked kinds never share a translation
		}

		const entries = queryClient.getQueriesData( {
			predicate: ( q ) =>
				q.queryKey[ 0 ] === 'texts' &&
				q.queryKey[ 2 ] === lang &&
				q.queryKey[ 1 ] !== editedCid,
		} )

		for ( const [ key, data ] of entries ) {
			const texts = data?.texts
			if ( ! Array.isArray( texts ) ) {
				continue
			}
			let changed = false
			const nextTexts = texts.map( ( t ) => {
				if ( t.original_hash !== hash ) {
					return t
				}
				changed = true
				return {
					...t,
					translated: updatedUnit.translated,
					status: updatedUnit.status,
					origin: updatedUnit.origin,
					is_ai: updatedUnit.is_ai,
					word_count: updatedUnit.word_count,
				}
			} )
			if ( ! changed ) {
				continue
			}
			queryClient.setQueryData( key, { ...data, texts: nextTexts } )

			const cid = key[ 1 ]
			const summary = {
				id: cid,
				total: nextTexts.length,
				translated: 0,
				needs_review: 0,
				missing: 0,
			}
			for ( const t of nextTexts ) {
				if ( t.status === 'missing' ) {
					summary.missing++
				} else if ( t.status === 'needs_review' ) {
					summary.needs_review++
				} else {
					summary.translated++
				}
			}
			patchCollectionSummary( summary, lang )
		}
	}

	// Offset paging can repeat a row across pages (mid-scroll inserts/removals);
	// first occurrence wins, which preserves page order.
	const groups = useMemo( () => {
		const seen = new Set()
		const result = []
		for ( const p of groupsQ.data?.pages ?? [] ) {
			for ( const g of p.collections ?? [] ) {
				if ( seen.has( g.id ) ) {
					continue
				}
				seen.add( g.id )
				result.push( g )
			}
		}
		return result
	}, [ groupsQ.data ] )

	// Header progress badge — the whole matching set's totals, from the most
	// recently fetched page (every page carries the same whole-set totals).
	const pages = groupsQ.data?.pages ?? []
	const lastPage = pages[ pages.length - 1 ]
	const totalTexts = lastPage?.total_texts ?? 0
	const totalDone = lastPage?.total_translated ?? 0
	const totalGroups = lastPage?.total ?? groups.length

	// Every group is open by default; a manual collapse is remembered here and
	// reset when the working language changes (mirrors the old per-language reset).
	const [ collapsedIds, setCollapsedIds ] = useState( () => new Set() )
	useEffect( () => {
		setCollapsedIds( new Set() )
	}, [ selectedLang ] )

	const toggleCollapsed = useCallback( ( id ) => {
		setCollapsedIds( ( prev ) => {
			const next = new Set( prev )
			if ( next.has( id ) ) {
				next.delete( id )
			} else {
				next.add( id )
			}
			return next
		} )
	}, [] )

	// Window-scroll virtualization: only near-view group rows mount. One extra
	// "sentinel" row (count+1) carries the load-more/retry control when more
	// pages exist. Scroll margin is measured (not assumed), so content above the
	// list (notices, sticky header) doesn't misalign rows.
	const listRef = useRef( null )
	const [ scrollMargin, setScrollMargin ] = useState( 0 )
	useLayoutEffect( () => {
		const update = () => setScrollMargin( listRef.current?.offsetTop ?? 0 )
		update()
		window.addEventListener( 'resize', update )
		return () => window.removeEventListener( 'resize', update )
	}, [] )
	const getItemKey = useCallback(
		( i ) => ( i < groups.length ? groups[ i ].id : '__sentinel__' ),
		[ groups ]
	)
	const v = useWindowVirtualizer( {
		count: groupsQ.hasNextPage ? groups.length + 1 : groups.length,
		estimateSize: () => 320,
		overscan: 3,
		getItemKey,
		scrollMargin,
	} )
	const virtualItems = v.getVirtualItems()

	// Fetch the next page once the sentinel/last row nears the viewport.
	const fetchNextGroupsPage = groupsQ.fetchNextPage
	const hasNextGroupsPage = groupsQ.hasNextPage
	const isFetchingNextGroupsPage = groupsQ.isFetchingNextPage
	const isRefetchingGroups = groupsQ.isRefetching
	useEffect( () => {
		const last = virtualItems[ virtualItems.length - 1 ]
		if ( ! last ) {
			return
		}
		if (
			last.index >= groups.length - 3 &&
			hasNextGroupsPage &&
			! isFetchingNextGroupsPage &&
			! isRefetchingGroups
		) {
			fetchNextGroupsPage()
		}
	}, [
		virtualItems,
		groups.length,
		fetchNextGroupsPage,
		hasNextGroupsPage,
		isFetchingNextGroupsPage,
		isRefetchingGroups,
	] )

	// Deep-link landing: keep paging until the shared-link collection turns up,
	// then scroll it into view and drop the hash so a later refresh doesn't
	// re-trigger the jump. Gives up once every page is loaded and it's still
	// not there (removed, or filtered out on the visitor's language).
	const scrollSettlingRef = useRef( false )
	useEffect( () => {
		if (
			! pendingScrollId ||
			groupsQ.isPending ||
			scrollSettlingRef.current
		) {
			return
		}
		const index = groups.findIndex( ( g ) => g.id === pendingScrollId )
		if ( index >= 0 ) {
			// Rows above the target are still at estimateSize() until they've
			// actually mounted and measureElement reports their real height —
			// react-virtual never renders far-off-screen rows just to measure
			// them. A single scrollToIndex lands using those estimates, and
			// nothing re-corrects it afterwards, which reads as "jumps near
			// the target, then the final resting spot is off." Re-issuing the
			// jump across frames lets each pass mount a bit more of the path
			// and pull the estimate closer to reality; we stop once
			// v.scrollOffset (the virtualizer's own settled position) stops
			// moving between two consecutive frames, capped as a safety valve.
			scrollSettlingRef.current = true
			let previousOffset = null
			let stableFrames = 0
			let frame = 0
			const settle = () => {
				v.scrollToIndex( index, { align: 'start' } )
				const offset = v.scrollOffset
				stableFrames =
					previousOffset !== null &&
					Math.abs( offset - previousOffset ) < 1
						? stableFrames + 1
						: 0
				previousOffset = offset
				frame += 1
				if ( stableFrames < 2 && frame < 30 ) {
					requestAnimationFrame( settle )
					return
				}
				scrollSettlingRef.current = false
				window.history.replaceState(
					null,
					'',
					window.location.pathname + window.location.search
				)
				setPendingScrollId( null )
			}
			requestAnimationFrame( settle )
			return
		}
		if ( hasNextGroupsPage ) {
			if ( ! isFetchingNextGroupsPage ) {
				fetchNextGroupsPage()
			}
			return
		}
		showToast( 'warning', 'Collection not found.' )
		setPendingScrollId( null )
	}, [
		pendingScrollId,
		groups,
		groupsQ.isPending,
		hasNextGroupsPage,
		isFetchingNextGroupsPage,
		fetchNextGroupsPage,
		v,
	] )

	// The overlay reads its group's texts from the same cache key each
	// CollectionGroup uses, so opening a neighbor group is just a query-key
	// change (shares cache with that group's own row if it's mounted).
	const overlayTextsQ = useQuery( {
		queryKey: [
			'texts',
			overlay?.cid,
			selectedLang,
			query,
			statusFilter,
			advancedFilters,
		],
		queryFn: async ( { signal } ) => {
			const res = await api.get(
				`/collections/${ enc( overlay.cid ) }/texts?language=${ enc(
					selectedLang
				) }${ searchParam( query ) }&status=${ enc(
					statusFilter
				) }${ advancedFilterParams }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		enabled: !! overlay,
	} )
	const overlayTexts = overlayTextsQ.data?.texts ?? []
	const overlayIndex = overlay
		? overlayTexts.findIndex( ( t ) => t.id === overlay.id )
		: -1
	const overlayUnit = overlayIndex >= 0 ? overlayTexts[ overlayIndex ] : null

	// Cross-group prev/next: every matching group's text-id order, fetched only
	// while the overlay is open (not part of the paginated board itself).
	const orderQ = useQuery( {
		queryKey: [
			'text-order',
			selectedLang,
			query,
			statusFilter,
			board,
			advancedFilters,
		],
		queryFn: async ( { signal } ) => {
			const res = await api.get(
				`/collections/text-order?language=${ enc(
					selectedLang
				) }${ searchParam( query ) }&status=${ enc(
					statusFilter
				) }&source=${ enc( board ) }${ advancedFilterParams }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		enabled: !! overlay,
	} )
	const orderFlat = useMemo(
		() =>
			( orderQ.data?.groups ?? [] ).flatMap( ( g ) =>
				g.ids.map( ( id ) => ( {
					id,
					cid: g.cid,
					label: g.label,
					type: g.type,
				} ) )
			),
		[ orderQ.data ]
	)
	const globalIndex = overlay
		? orderFlat.findIndex( ( o ) => o.id === overlay.id )
		: -1

	// After a mutation on a unit: invalidate that group's texts (any cached
	// search variant); patch the collections cache directly if the mutation
	// returned a fresh summary, otherwise fall back to invalidating it; and
	// propagate the new value to any other visible occurrence of the same text.
	function afterMutate( cid, patch ) {
		queryClient.invalidateQueries( {
			queryKey: [ 'texts', cid, selectedLang ],
		} )
		if ( patch?.collection_summary ) {
			patchCollectionSummary( patch.collection_summary )
		} else {
			invalidateCollections()
		}
		if ( patch?.updated_unit ) {
			propagateTranslation( cid, patch.updated_unit )
		}
	}

	const searching = query !== ''
	const showGettextBoard = board === 'plugins'
	// Unfinished onboarding takes priority over every other board-body state —
	// a fresh install always finishes setup before it can add a language.
	const showNeedsOnboarding = ! onboardingCompleted
	// No language yet: board body shows a centered CTA instead of the content
	// list, but the sidebar/shell around it renders as usual.
	const showNoLanguages =
		! showNeedsOnboarding && languagesLoaded && languages.length === 0
	// While the first-scan gate is up, it fully replaces the board body —
	// no stacked/duplicate spinners underneath it.
	const showGroupsLoading =
		! showGettextBoard &&
		! showNeedsOnboarding &&
		! showNoLanguages &&
		! extractionPending &&
		groupsQ.isPending
	const showGroupsError =
		! showGettextBoard &&
		! showNeedsOnboarding &&
		! showNoLanguages &&
		! extractionPending &&
		! groupsQ.isPending &&
		groupsQ.isError &&
		groups.length === 0
	const showGroupsEmpty =
		! showGettextBoard &&
		! showNeedsOnboarding &&
		! showNoLanguages &&
		! extractionPending &&
		! groupsQ.isPending &&
		! showGroupsError &&
		groups.length === 0
	const showGroups =
		! showGettextBoard &&
		! showNeedsOnboarding &&
		! showNoLanguages &&
		! extractionPending &&
		! groupsQ.isPending &&
		! showGroupsError &&
		groups.length > 0
	let emptyGroupsMessage =
		'No translatable content found for this language yet.'
	if ( searching ) {
		emptyGroupsMessage = `No text matches “${ query }” in this language.`
	} else if ( hasAnyFilter ) {
		emptyGroupsMessage = 'No content matches the selected filters.'
	}

	// Column headers show the actual language names: source (default) on the left,
	// the selected target on the right. Prefer an explicit display name; otherwise
	// turn the code into a readable name (en → English) so raw codes never show.
	const codeName = ( code ) => {
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
	const langLabel = ( l ) =>
		l && ( l.display_name || l.name || codeName( l.code || l ) )
	const originalLabel =
		langLabel( defaultLanguage ) || codeName( defaultCode ) || 'Original'
	const translationLabel =
		langLabel( languages.find( ( l ) => l.code === selectedLang ) ) ||
		'Translation'
	const sourceFlag = flagIconUrl(
		typeof defaultLanguage === 'object' && defaultLanguage
			? defaultLanguage
			: { code: defaultCode }
	)

	const choices = ( items ) =>
		items.map( ( item ) => ( { label: item.label, value: item.key } ) )
	const selectedLabels = ( items, selected ) =>
		items
			.filter( ( item ) => selected.includes( item.key ) )
			.map( ( item ) => item.label )
			.join( ', ' )

	const filters = [
		{
			key: 'status',
			label: 'Status',
			filter: (
				<ChoiceList
					title="Status"
					titleHidden
					choices={ FILTERS.map( ( f ) => ( {
						label: f.label,
						value: f.key,
					} ) ) }
					selected={ statusFilter === 'all' ? [] : [ statusFilter ] }
					onChange={ ( vals ) =>
						setStatusFilter( vals[ 0 ] || 'all' )
					}
				/>
			),
			shortcut: true,
		},
		{
			key: 'origin',
			label: 'Origin',
			filter: (
				<ChoiceList
					title="Origin"
					titleHidden
					allowMultiple
					choices={ choices( ORIGIN_FILTERS ) }
					selected={ originFilter }
					onChange={ setOriginFilter }
				/>
			),
			shortcut: true,
		},
		{
			key: 'content_type',
			label: 'Content type',
			filter: (
				<ChoiceList
					title="Content type"
					titleHidden
					allowMultiple
					choices={ choices( CONTENT_TYPE_FILTERS ) }
					selected={ contentTypeFilter }
					onChange={ setContentTypeFilter }
				/>
			),
		},
		{
			key: 'text_length',
			label: 'Text length',
			filter: (
				<ChoiceList
					title="Text length"
					titleHidden
					allowMultiple
					choices={ choices( TEXT_LENGTH_FILTERS ) }
					selected={ textLengthFilter }
					onChange={ setTextLengthFilter }
				/>
			),
		},
		{
			key: 'progress',
			label: 'Collection progress',
			filter: (
				<ChoiceList
					title="Collection progress"
					titleHidden
					allowMultiple
					choices={ choices( PROGRESS_FILTERS ) }
					selected={ progressFilter }
					onChange={ setProgressFilter }
				/>
			),
		},
	]
	const appliedFilters = [
		...( statusFilter === 'all'
			? []
			: [
					{
						key: 'status',
						label: `Status: ${
							FILTERS.find( ( f ) => f.key === statusFilter )
								?.label
						}`,
						onRemove: () => setStatusFilter( 'all' ),
					},
			  ] ),
		...( originFilter.length === 0
			? []
			: [
					{
						key: 'origin',
						label: `Origin: ${ selectedLabels(
							ORIGIN_FILTERS,
							originFilter
						) }`,
						onRemove: () => setOriginFilter( [] ),
					},
			  ] ),
		...( contentTypeFilter.length === 0
			? []
			: [
					{
						key: 'content_type',
						label: `Content type: ${ selectedLabels(
							CONTENT_TYPE_FILTERS,
							contentTypeFilter
						) }`,
						onRemove: () => setContentTypeFilter( [] ),
					},
			  ] ),
		...( textLengthFilter.length === 0
			? []
			: [
					{
						key: 'text_length',
						label: `Text length: ${ selectedLabels(
							TEXT_LENGTH_FILTERS,
							textLengthFilter
						) }`,
						onRemove: () => setTextLengthFilter( [] ),
					},
			  ] ),
		...( progressFilter.length === 0
			? []
			: [
					{
						key: 'progress',
						label: `Collection progress: ${ selectedLabels(
							PROGRESS_FILTERS,
							progressFilter
						) }`,
						onRemove: () => setProgressFilter( [] ),
					},
			  ] ),
	]

	const clearAllFilters = () => {
		setStatusFilter( 'all' )
		setOriginFilter( [] )
		setContentTypeFilter( [] )
		setTextLengthFilter( [] )
		setProgressFilter( [] )
	}

	return (
		<Page fullWidth>
			<InlineStack
				gap="200"
				align="start"
				blockAlign="start"
				wrap={ false }
			>
				{ /* ── Left rail ─────────────────────────────────────────── */ }
				<div
					style={ {
						flex: '0 0 260px',
						width: 260,
						alignSelf: 'flex-start',
						position: 'sticky',
						top: 32,
						marginBlockStart: 8,
						height: 'calc(100vh - 100px)',
						display: 'flex',
						flexDirection: 'column',
					} }
				>
					<div
						style={ {
							flex: 1,
							minHeight: 0,
							overflowY: 'auto',
							paddingRight: 4,
						} }
					>
						<Card>
							<BlockStack gap="400">
								{ /* Brand header — name + logo live here, not in the page header. */ }
								<InlineStack
									align="space-between"
									blockAlign="center"
									wrap={ false }
								>
									<InlineStack
										gap="100"
										blockAlign="center"
										wrap={ false }
									>
										{ cfg.logo && (
											<img
												src={ cfg.logo }
												alt=""
												width="22"
												height="22"
												className="block"
											/>
										) }
										<Text as="span" variant="headingMd">
											Translangs
										</Text>
									</InlineStack>
									<Popover
										active={ pageMenuOpen }
										onClose={ () =>
											setPageMenuOpen( false )
										}
										className="mt-1"
										activator={
											<Tooltip content="Settings">
												<Button
													icon={ SettingsIcon }
													variant="tertiary"
													size="medium"
													accessibilityLabel="Settings"
													onClick={ () =>
														setPageMenuOpen(
															( o ) => ! o
														)
													}
												/>
											</Tooltip>
										}
									>
										<div className="tl-settings-menu">
											<ActionList
												items={ [
													{
														content: 'Settings',
														onAction: () => {
															setPageMenuOpen(
																false
															)
															openManager(
																'configuration'
															)
														},
													},
													{
														content: 'Refresh',
														disabled: refreshing,
														onAction: () => {
															setPageMenuOpen(
																false
															)
															refreshScan()
														},
													},
												] }
											/>
										</div>
									</Popover>
								</InlineStack>

								<Divider />

								<BlockStack gap="200">
									<Text as="h2" variant="headingSm">
										Target language
									</Text>
									{ languagesLoaded ? (
										<LanguageList
											languages={ languages }
											selectedCode={ selectedLang }
											aiAvailable={ ai.available }
											onSelect={ ( lang ) =>
												setSelectedLang( lang.code )
											}
											onEdit={ ( lang ) =>
												openManager( lang.code )
											}
											onToggleStatus={
												togglePublishStatus
											}
											onTranslateAll={ ( lang ) =>
												translateLanguageAll( lang )
											}
											onUseFallback={ ( lang ) =>
												applyFallbackTranslation( lang )
											}
											onDelete={ ( lang ) =>
												confirmDeleteLang( lang )
											}
											onAdd={ () => openManager( null ) }
										/>
									) : (
										<InlineStack align="center">
											<Spinner
												accessibilityLabel="Loading languages"
												size="small"
											/>
										</InlineStack>
									) }
								</BlockStack>

								<Divider />

								<BlockStack gap="100">
									<Text as="h2" variant="headingSm">
										Sources
									</Text>
									<BlockStack gap="050">
										<Button
											fullWidth
											textAlign="left"
											variant={
												board === 'content'
													? 'primary'
													: 'tertiary'
											}
											onClick={ () =>
												selectBoard( 'content' )
											}
										>
											Content
										</Button>
										<Button
											fullWidth
											textAlign="left"
											variant={
												board === 'plugins'
													? 'primary'
													: 'tertiary'
											}
											onClick={ () =>
												selectBoard( 'plugins' )
											}
										>
											Plugins & Themes
										</Button>
									</BlockStack>
								</BlockStack>
							</BlockStack>
						</Card>
					</div>
					<div className="pt-4">
						<QuotaCard
							quota={ quota }
							aiAvailable={ ai.available }
							aiError={ ai.error }
							connectPending={ connectPending }
							billingBlocked={ billingBlocked }
							onConnect={ handleConnect }
						/>
					</div>
				</div>

				{ /* ── Main: one card, sticky search header, scrolling body ─ */ }
				<div
					style={ {
						flex: 1,
						minWidth: 0,
						marginBlockStart: 8,
						minHeight: 'calc(100vh - 100px)',
					} }
				>
					<div
						style={ {
							minHeight: 'calc(100vh - 100px)',
							background: 'var(--p-color-bg-surface, #fff)',
							borderRadius: 'var(--p-border-radius-300, 12px)',
							boxShadow:
								'var(--p-shadow-100, 0 1px 2px rgba(0,0,0,0.08))',
						} }
					>
						<div
							style={ {
								position: 'sticky',
								top: 32,
								zIndex: 2,
								background: 'var(--p-color-bg-surface, #fff)',
								borderTopLeftRadius:
									'var(--p-border-radius-300, 12px)',
								borderTopRightRadius:
									'var(--p-border-radius-300, 12px)',
							} }
						>
							<Box padding="200">
								<BlockStack gap="200">
									{ /* Line 1: search (left) + AI translate all (right) */ }
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: 6,
											minHeight: 32,
										} }
									>
										<div className="flex-1 min-w-0">
											<TextField
												label="Search text"
												labelHidden
												placeholder={
													showGettextBoard
														? 'Search plugins & themes…'
														: 'Search text…'
												}
												value={ search }
												onChange={ setSearch }
												autoComplete="off"
												clearButton
												onClearButtonClick={ () =>
													setSearch( '' )
												}
											/>
										</div>
										<Button
											icon={ ( props ) => (
												<AiIcon
													{ ...props }
													size={ 14 }
												/>
											) }
											variant="secondary"
											disabled
											size="large"
											onClick={ () =>
												setConfirm( {
													title: 'AI translate all missing text?',
													body: 'This sends every untranslated text in the selected language to AI translation and may use a large number of words.',
													actionLabel:
														'Translate all',
													onConfirm: () =>
														translateScope(
															'language',
															{
																source: board,
															}
														),
												} )
											}
										>
											Translate All
										</Button>
									</div>
									{ /* Line 2: standard Polaris Filters (status) — these all
									filter the Content board's collections/texts query, which
									the Plugins & Themes board doesn't use. */ }
									{ ! showGettextBoard && (
										<div className="tl-filters">
											<Filters
												hideQueryField
												filters={ filters }
												appliedFilters={
													appliedFilters
												}
												onQueryChange={ () => {} }
												onQueryClear={ () => {} }
												onClearAll={ clearAllFilters }
											/>
										</div>
									) }
								</BlockStack>
							</Box>
						</div>
						{ showNeedsOnboarding && (
							<Box padding="500">
								<EmptyState
									heading="Finish setting up Translangs"
									action={ {
										content: 'Continue setup',
										onAction: onStartOnboarding,
									} }
								>
									<p>
										Set your translation preferences to
										start translating your content
										automatically.
									</p>
								</EmptyState>
							</Box>
						) }
						{ showNoLanguages && (
							<Box padding="500">
								<EmptyState
									heading="Add your first language"
									action={ {
										content: 'Create a language',
										onAction: () => openManager( null ),
									} }
								>
									<p>
										Your site is ready. Create a language to
										start translating your content.
									</p>
								</EmptyState>
							</Box>
						) }
						{ extractionPending && (
							<Box padding="500">
								<BlockStack gap="200" inlineAlign="center">
									<Spinner
										accessibilityLabel="Scanning your site"
										size="small"
									/>
									<Text as="p" tone="subdued">
										Automatically scanning your site for
										translatable content. This can take a
										moment.
									</Text>
								</BlockStack>
							</Box>
						) }
						{ showGettextBoard &&
							! showNeedsOnboarding &&
							! showNoLanguages &&
							! extractionPending && (
								<Suspense
									fallback={
										<Box padding="500">
											<Spinner
												accessibilityLabel="Loading Plugins & Themes"
												size="small"
											/>
										</Box>
									}
								>
									<GettextBoard
										language={ selectedLang }
										search={ query }
										originalLabel={ originalLabel }
										translationLabel={ translationLabel }
										sourceLabel={ originalLabel }
										sourceFlag={ sourceFlag }
										languages={ languages }
										aiAvailable={ ai.available }
										billingBlocked={ billingBlocked }
										onAiBlocked={ handleAiBlocked }
										onPickLanguage={ setSelectedLang }
										onConfirm={ setConfirm }
										onAiUnavailable={ ( error ) =>
											setAi( {
												available: false,
												error,
											} )
										}
									/>
								</Suspense>
							) }
						{ showGroupsLoading && (
							<Box padding="500">
								<Spinner
									accessibilityLabel="Loading content"
									size="small"
								/>
							</Box>
						) }
						{ showGroupsError && (
							<Box padding="500">
								<BlockStack gap="200">
									<Text as="p" tone="critical">
										Could not load content for this
										language.
									</Text>
									<InlineStack>
										<Button
											onClick={ () => groupsQ.refetch() }
										>
											Retry
										</Button>
									</InlineStack>
								</BlockStack>
							</Box>
						) }
						{ showGroupsEmpty && (
							<Box padding="500">
								<Text as="p" tone="subdued">
									{ emptyGroupsMessage }
								</Text>
							</Box>
						) }
						{ showGroups && (
							<BlockStack gap="0">
								{ searching && (
									<Box paddingInline="300" paddingBlock="200">
										<Text
											as="p"
											tone="subdued"
											variant="bodySm"
										>
											{ totalGroups }{ ' ' }
											{ totalGroups === 1
												? 'group matches'
												: 'groups match' }
											.
										</Text>
									</Box>
								) }
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
													// fontWeight="medium"
												>
													{ originalLabel }
												</Text>
											</Box>
											<Box width="45%" minWidth="0">
												<Text
													as="span"
													variant="bodySm"
													tone="subdued"
													// fontWeight="medium"
												>
													{ translationLabel }
												</Text>
											</Box>
											<Box width="16%" />
										</InlineStack>
									</Box>
								</Box>
								<Divider />
								<div
									ref={ listRef }
									style={ {
										position: 'relative',
										height: v.getTotalSize(),
									} }
								>
									{ virtualItems.map( ( row ) => {
										const isLoaderRow =
											row.index >= groups.length
										const c = groups[ row.index ]
										let loaderAction = null
										if ( groupsQ.isFetchNextPageError ) {
											loaderAction = (
												<Button
													onClick={ () =>
														groupsQ.fetchNextPage()
													}
												>
													Retry loading more
												</Button>
											)
										} else if ( groupsQ.hasNextPage ) {
											loaderAction = (
												<Button
													onClick={ () =>
														groupsQ.fetchNextPage()
													}
													loading={
														groupsQ.isFetchingNextPage
													}
													disabled={
														groupsQ.isFetchingNextPage
													}
												>
													Load more
												</Button>
											)
										}
										return (
											<div
												key={ row.key }
												data-index={ row.index }
												ref={ v.measureElement }
												style={ {
													position: 'absolute',
													top: 0,
													left: 0,
													width: '100%',
													transform: `translateY(${
														row.start - scrollMargin
													}px)`,
												} }
											>
												{ isLoaderRow ? (
													<Box padding="300">
														<InlineStack align="center">
															{ loaderAction }
														</InlineStack>
													</Box>
												) : (
													<CollectionGroup
														collection={ c }
														language={
															selectedLang
														}
														targetDir={
															languages.find(
																( l ) =>
																	l.code ===
																	selectedLang
															)?.direction ||
															'ltr'
														}
														search={ query }
														status={ statusFilter }
														filterKey={
															advancedFilters
														}
														filterParams={
															advancedFilterParams
														}
														collapsed={ collapsedIds.has(
															c.id
														) }
														onToggleCollapsed={ () =>
															toggleCollapsed(
																c.id
															)
														}
														onMutate={ ( patch ) =>
															afterMutate(
																c.id,
																patch
															)
														}
														onConfirm={ setConfirm }
														aiAvailable={
															ai.available
														}
														onAiTranslate={
															translateScope
														}
														billingBlocked={
															billingBlocked
														}
														onAiBlocked={
															handleAiBlocked
														}
														bulkTranslating={
															bulkTranslating
														}
														onOpenOverlay={ (
															unit,
															cid
														) =>
															setOverlay( {
																id: unit.id,
																cid,
															} )
														}
														onLengthWarning={
															showLengthWarning
														}
														onShare={
															shareCollection
														}
													/>
												) }
											</div>
										)
									} ) }
								</div>
							</BlockStack>
						) }

						{ board === 'content' && ! groupsQ.isPending && (
							<>
								<Divider />
								<Box padding="300">
									<Text
										as="p"
										alignment="end"
										tone="subdued"
										variant="bodySm"
									>
										{ totalDone } of { totalTexts }{ ' ' }
										translated
										{ searching || hasAnyFilter
											? ' in filtered text'
											: '' }
									</Text>
								</Box>
							</>
						) }
					</div>
				</div>
			</InlineStack>

			{ managerEverOpened && (
				<Suspense fallback={ null }>
					<LanguageManagerModal
						open={ managerOpen }
						languages={ languages }
						catalog={ languageCatalog }
						defaultCode={ defaultCode }
						initialCode={ managerInitial }
						onClose={ () => setManagerOpen( false ) }
						onConfirm={ setConfirm }
						onChanged={ ( createdCode ) => {
							const wasFirstLanguage = languages.length === 0
							refreshLanguages()
							// A newly created language is handed back explicitly so this
							// jumps to its tab even when the current selection was
							// already valid (refreshLanguages() only re-picks a language
							// when the current selection stops being valid).
							if ( createdCode ) {
								setSelectedLang( createdCode )
							}
							if ( wasFirstLanguage ) {
								api.get( '/scan/status' ).then( ( res ) => {
									if (
										( res.status || 'running' ) ===
										'running'
									) {
										setExtractionPending( true )
										setTimeout( pollFirstScan, 3000 )
									}
								} )
							}
						} }
					/>
				</Suspense>
			) }
			<CompareOverlay
				open={ !! overlayUnit }
				unit={ overlayUnit }
				language={ selectedLang }
				collectionId={ overlay?.cid }
				languages={ languages }
				sourceLabel={ originalLabel }
				sourceFlag={ sourceFlag }
				targetDir={
					languages.find( ( l ) => l.code === selectedLang )
						?.direction || 'ltr'
				}
				aiAvailable={ ai.available }
				onAiTranslate={ translateScope }
				onPickLanguage={ setSelectedLang }
				onOpenPreview={ openCanvas }
				onClose={ () => setOverlay( null ) }
				onSaved={ ( patch ) =>
					overlay && afterMutate( overlay.cid, patch )
				}
				onLengthWarning={ showLengthWarning }
				hasPrev={ globalIndex > 0 }
				hasNext={
					globalIndex >= 0 && globalIndex < orderFlat.length - 1
				}
				onPrev={ () => {
					const prev = orderFlat[ globalIndex - 1 ]
					setOverlay( { id: prev.id, cid: prev.cid } )
				} }
				onNext={ () => {
					const next = orderFlat[ globalIndex + 1 ]
					setOverlay( { id: next.id, cid: next.cid } )
				} }
			/>
			<ConfirmDialog
				confirm={ confirm }
				onClose={ () => setConfirm( null ) }
			/>
			{ toast && (
				<Toast
					content={ toast.content }
					error={ toast.tone === 'critical' }
					icon={ TOAST_TONE_ICON[ toast.tone ] }
					duration={
						toast.tone === 'success' || toast.tone === 'info'
							? 3000
							: 5000
					}
					onDismiss={ () => setToast( null ) }
					onClick={ toast.onClick }
				/>
			) }
		</Page>
	)
}

function ConfirmDialog( { confirm, onClose } ) {
	if ( ! confirm ) {
		return null
	}
	return (
		<Modal
			open
			onClose={ onClose }
			title={ confirm.title }
			primaryAction={ {
				content: confirm.actionLabel,
				destructive: confirm.destructive,
				onAction: () => {
					confirm.onConfirm()
					onClose()
				},
			} }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<Text as="p">{ confirm.body }</Text>
			</Modal.Section>
		</Modal>
	)
}

function CollectionGroup( {
	collection,
	language,
	targetDir,
	search,
	status,
	filterKey,
	filterParams,
	collapsed,
	onToggleCollapsed,
	onMutate,
	onConfirm,
	onOpenOverlay,
	aiAvailable,
	onAiTranslate,
	billingBlocked,
	onAiBlocked,
	bulkTranslating,
	onLengthWarning,
	onShare,
} ) {
	const c = collection
	const open = ! collapsed
	const searching = search !== ''
	const [ menuOpen, setMenuOpen ] = useState( false )
	const [ hovered, setHovered ] = useState( false )
	const [ shown, setShown ] = useState( TEXT_BATCH )

	// Suppress requests for groups only flashed past during fast scroll: a
	// collapsed/just-scrolled-past group never settles long enough to fetch.
	const [ settled, setSettled ] = useState( false )
	useEffect( () => {
		if ( collapsed ) {
			setSettled( false )
			return
		}
		const t = setTimeout( () => setSettled( true ), 120 )
		return () => clearTimeout( t )
	}, [ collapsed ] )

	const textsQ = useQuery( {
		queryKey: [ 'texts', c.id, language, search, status, filterKey ],
		queryFn: async ( { signal } ) => {
			const res = await api.get(
				`/collections/${ enc( c.id ) }/texts?language=${ enc(
					language
				) }${
					search ? `&search=${ enc( search ) }` : ''
				}&status=${ enc( status ) }${ filterParams }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		enabled: ! collapsed && settled,
	} )
	const allTexts = textsQ.data?.texts ?? []
	const rows = allTexts.slice( 0, shown )

	// Stale tl_html rows: text nobody (marking mode or the background crawl)
	// has re-encountered in the grace window. Html-only — staleness has no
	// meaning for the other collection types.
	const staleQ = useQuery( {
		queryKey: [ 'html-stale', c.id, language ],
		queryFn: async ( { signal } ) => {
			const res = await api.get(
				`/collections/${ enc( c.id ) }/stale?language=${ enc(
					language
				) }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		enabled: c.id === 'html:all' && ! collapsed && settled,
	} )
	const staleCount = staleQ.data?.texts?.length ?? 0

	async function bulk( action ) {
		setMenuOpen( false )
		const res = await api.post(
			`/collections/${ enc( c.id ) }/${ action }`,
			{
				language,
			}
		)
		if ( res.ok ) {
			onMutate( res )
		}
	}

	function confirmIgnoreAll() {
		onConfirm( {
			title: 'Ignore all texts in this group?',
			body: 'Every text in this group will be excluded from translation and moved to the Ignored view.',
			actionLabel: 'Ignore all',
			// destructive: true,
			onConfirm: () => bulk( 'ignore' ),
		} )
	}

	function confirmClearAll() {
		onConfirm( {
			title: 'Clear all translations in this group?',
			body: 'Every translation in this group for the selected language will be removed.',
			actionLabel: 'Clear all',
			// destructive: true,
			onConfirm: () => bulk( 'clear' ),
		} )
	}

	async function deleteStale() {
		setMenuOpen( false )
		const res = await api.post(
			`/collections/${ enc( c.id ) }/delete-stale`,
			{
				language,
			}
		)
		if ( res.ok ) {
			onMutate( res )
			staleQ.refetch()
		}
	}

	function confirmDeleteStale() {
		onConfirm( {
			title: 'Delete stale text?',
			body: `${ staleCount } ${
				staleCount === 1 ? "text hasn't" : "texts haven't"
			} been seen on the site in over 30 days. Deleting removes them and their translations permanently — this cannot be undone.`,
			actionLabel: 'Delete stale',
			destructive: true,
			onConfirm: deleteStale,
		} )
	}

	// Ignore all / Clear all are whole-collection on the server. Offering them beside
	// a filtered row list would read as "ignore these 3", so they hide during a search;
	// the preview link is scope-free and stays.
	const bulkItems = [
		{
			content: 'Share collection',
			onAction: () => {
				setMenuOpen( false )
				onShare( c )
			},
		},
		...( c.preview_url
			? [
					{
						content:
							c.preview_target === 'frontend'
								? 'Show in canvas'
								: 'Edit in WordPress',
						onAction: () => {
							setMenuOpen( false )
							openCanvas( c.preview_url, c.preview_target )
						},
					},
			  ]
			: [] ),
		...( searching
			? []
			: [
					{
						content: 'Clear all',
						onAction: confirmClearAll,
					},
					{
						content: 'Ignore all',
						destructive: true,
						onAction: confirmIgnoreAll,
					},
					...( c.id === 'html:all' && staleCount > 0
						? [
								{
									content: `Delete stale (${ staleCount })`,
									destructive: true,
									onAction: confirmDeleteStale,
								},
						  ]
						: [] ),
			  ] ),
	]

	return (
		<Box>
			<div
				onMouseEnter={ () => setHovered( true ) }
				onMouseLeave={ () => setHovered( false ) }
			>
				<Box
					padding="300"
					background={ open ? 'bg-surface-secondary' : undefined }
				>
					<InlineStack
						align="space-between"
						blockAlign="center"
						wrap={ false }
					>
						<button
							type="button"
							onClick={ onToggleCollapsed }
							style={ {
								all: 'unset',
								cursor: 'pointer',
								flex: 1,
								display: 'flex',
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
								{ groupHeading( c ) }
							</Text>
							{ /* While searching, the server's counts describe the matching subset,
                so `total` is the match count. */ }
							{ searching && (
								<Text as="span" variant="bodySm" tone="subdued">
									{ c.total }{ ' ' }
									{ c.total === 1 ? 'match' : 'matches' }
								</Text>
							) }
						</button>
						<motion.div
							animate={ {
								opacity: hovered || menuOpen ? 1 : 0,
							} }
							transition={ { duration: 0.12 } }
							style={ {
								visibility:
									hovered || menuOpen ? 'visible' : 'hidden',
							} }
						>
							<InlineStack
								gap="100"
								blockAlign="stretch"
								wrap={ false }
							>
								{ /* Popover wraps its activator in its own bare,
								unstyleable <div> — stretching the InlineStack
								stretches that wrapper, but the button inside
								stays its natural (shorter) height and sits at
								the top of it. This flex+center wrapper is one
								we control, so it can re-center the button
								within the stretched space. */ }
								{ bulkItems.length > 0 && (
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
										} }
									>
										<Popover
											active={ menuOpen }
											onClose={ () =>
												setMenuOpen( false )
											}
											activator={
												<Button
													variant="tertiary"
													size="micro"
													icon={ MenuHorizontalIcon }
													accessibilityLabel="More actions"
													onClick={ () =>
														setMenuOpen(
															( o ) => ! o
														)
													}
												/>
											}
										>
											<ActionList items={ bulkItems } />
										</Popover>
									</div>
								) }
							</InlineStack>
						</motion.div>
					</InlineStack>
				</Box>
			</div>
			<Collapsible open={ open } id={ `col-${ c.id }` }>
				<Box paddingBlockEnd="200" paddingInlineStart="1000">
					{ textsQ.isPending && (
						<Box padding="300">
							<Spinner
								accessibilityLabel="Loading texts"
								size="small"
							/>
						</Box>
					) }
					{ ! textsQ.isPending && textsQ.isError && (
						<Box padding="300">
							<Text as="p" tone="critical" variant="bodySm">
								Could not load texts for this group.
							</Text>
						</Box>
					) }
					{ ! textsQ.isPending && ! textsQ.isError && (
						<>
							{ rows.map( ( t ) => (
								<TextUnitRow
									key={ t.id }
									unit={ t }
									language={ language }
									targetDir={ targetDir }
									collectionId={ c.id }
									onMutate={ onMutate }
									onConfirm={ onConfirm }
									aiAvailable={ aiAvailable }
									onAiTranslate={ onAiTranslate }
									billingBlocked={ billingBlocked }
									onAiBlocked={ onAiBlocked }
									bulkActive={
										bulkTranslating === 'language'
									}
									onOpenOverlay={ ( unit ) =>
										onOpenOverlay( unit, c.id )
									}
									onLengthWarning={ onLengthWarning }
								/>
							) ) }
							{ allTexts.length > shown && (
								<Box padding="300">
									<Button
										onClick={ () =>
											setShown( ( n ) =>
												Math.min(
													n + TEXT_BATCH,
													allTexts.length
												)
											)
										}
									>
										Show{ ' ' }
										{ Math.min(
											TEXT_BATCH,
											allTexts.length - shown
										) }{ ' ' }
										more
									</Button>
								</Box>
							) }
						</>
					) }
				</Box>
			</Collapsible>
			<Divider />
		</Box>
	)
}

function TextUnitRow( {
	unit,
	language,
	targetDir = 'ltr',
	collectionId,
	onMutate,
	onConfirm,
	onOpenOverlay,
	aiAvailable,
	onAiTranslate,
	billingBlocked,
	onAiBlocked,
	bulkActive = false,
	onLengthWarning,
} ) {
	const [ draft, setDraft ] = useState( unit.translated ?? '' )
	const [ menuOpen, setMenuOpen ] = useState( false )
	const [ hovered, setHovered ] = useState( false )
	const [ translating, setTranslating ] = useState( false )
	const moreActionsRef = useRef( null )

	// A same-commit-as-`translating` override: written the instant an AI
	// translate response lands (see translateThisText), so the shimmer never
	// clears one frame before the real text does. `undefined` = no override,
	// defer to the prop. Cleared once the prop catches up (the invalidation
	// `onAiTranslate` already fires refetches for consistency).
	const [ pendingTranslated, setPendingTranslated ] = useState( undefined )
	const effectiveTranslated =
		pendingTranslated !== undefined ? pendingTranslated : unit.translated
	useEffect( () => {
		if (
			pendingTranslated !== undefined &&
			unit.translated === pendingTranslated
		) {
			setPendingTranslated( undefined )
		}
	}, [ unit.translated, pendingTranslated ] )

	const shown = effectiveTranslated ?? unit.fallback
	const hasTranslation =
		effectiveTranslated !== null && effectiveTranslated !== undefined
	// Approximate on purpose: shimmer any untranslated row while a bulk "Translate
	// All" covering it is running, regardless of whether this row's text actually
	// made it into that job's API call (see Dashboard's bulkTranslating state).
	const showShimmer = translating || ( bulkActive && ! hasTranslation )
	let badge = null
	if ( unit.status === 'needs_review' ) {
		badge = STATUS_BADGE.needs_review
	} else if ( hasTranslation ) {
		badge = STATUS_BADGE.translated
	}

	// "Long" = the original doesn't fit on one line in its cell at the current
	// width. Measured, so it adapts to screen/column size instead of a fixed
	// character count. Long text edits in the overlay; short text edits inline.
	// A composed unit carries codec markers ({1}…{/1}); it must edit in the
	// overlay's chip editor, never the plain inline input.
	const isComposed = hasMarkers( unit.original || '' )
	const originalText = isComposed
		? stripMarkers( unit.original )
		: unit.original
	const shownText = isComposed ? stripMarkers( shown ) : shown
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
	}, [ unit.original ] )

	// Only long or structured (rich-text) source opens the overlay; short plain
	// text is fully visible and edits inline, so its cell stays non-clickable.
	const clickable = isLong || isComposed

	// Keep the inline draft in sync when the translation changes elsewhere
	// (AI translate, overlay save).
	useEffect( () => {
		setDraft( effectiveTranslated ?? '' )
	}, [ effectiveTranslated ] )

	// Autosave the inline field on blur — only when the value actually changed.
	async function saveTranslation() {
		if ( ( unit.translated ?? '' ) === draft ) {
			return
		}
		const res = await api.post( '/translations', {
			text_unit_id: unit.id,
			language,
			text: draft,
			collection_id: collectionId,
		} )
		if ( res.ok ) {
			onMutate( res )
			const warning = lengthWarningMessage( unit.original, draft )
			if ( warning ) {
				onLengthWarning( warning )
			}
		}
	}

	async function doStatus( action ) {
		setMenuOpen( false )
		const res = await api.post( `/texts/${ enc( unit.id ) }/status`, {
			action,
			language,
			collection_id: collectionId,
		} )
		if ( res.ok ) {
			onMutate( res )
		}
	}

	function confirmIgnore() {
		onConfirm( {
			title: 'Ignore this text?',
			body: 'Ignored text is excluded from translation and moves to the Ignored view.',
			actionLabel: 'Ignore',
			destructive: true,
			onConfirm: () => doStatus( 'ignore' ),
		} )
	}

	async function translateThisText() {
		setTranslating( true )
		const res = await onAiTranslate( 'text', {
			ref: unit.id,
			collection_id: collectionId,
		} )
		// Write the new text straight from the response — do not wait on the
		// list refetch/invalidation `onAiTranslate` kicks off for consistency.
		// These three setState calls are synchronous (no await between them),
		// so React batches them into one commit: the row never renders a frame
		// that's no longer "generating" but still showing the old text.
		if ( res?.updated_unit ) {
			const text = res.updated_unit.translated ?? null
			setDraft( text ?? '' )
			setPendingTranslated( text )
		}
		setTranslating( false )
	}

	// The AI-translate button unmounts while generating (AnimatePresence) — move
	// focus to the adjacent "More actions" button first so a keyboard user
	// never loses focus to <body>.
	function handleAiClick() {
		moreActionsRef.current?.querySelector( 'button' )?.focus()
		translateThisText()
	}

	const menuSections = [
		{
			items: [
				{
					content: 'Open in overlay',
					onAction: () => {
						setMenuOpen( false )
						onOpenOverlay( unit )
					},
				},
				unit.preview_url && {
					content:
						unit.preview_target === 'frontend'
							? 'Preview on website'
							: 'Edit in WordPress',
					onAction: () => {
						setMenuOpen( false )
						openCanvas(
							unit.preview_url,
							unit.preview_target,
							stripMarkers(
								unit.translated ?? unit.original ?? ''
							)
						)
					},
				},
			].filter( Boolean ),
		},
		{
			items: [
				hasTranslation &&
					( unit.status === 'needs_review'
						? {
								content: 'Reviewed',
								onAction: () => doStatus( 'reviewed' ),
						  }
						: {
								content: 'To review',
								onAction: () => doStatus( 'review' ),
						  } ),
				hasTranslation && {
					content: 'Clear',
					onAction: () => doStatus( 'clear' ),
				},
				{
					content: 'Ignore',
					destructive: true,
					onAction: () => {
						setMenuOpen( false )
						confirmIgnore()
					},
				},
			].filter( Boolean ),
		},
	]

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
							{ unit.image_url && (
								<div style={ { flexShrink: 0 } }>
									<Thumbnail
										source={ unit.image_url }
										alt=""
										size="extraSmall"
									/>
								</div>
							) }
							{ /* div, not button — buttons block native text
							selection, and the original text must stay
							copyable by drag/selection. */ }
							<div
								ref={ originalRef }
								role={ clickable ? 'button' : undefined }
								tabIndex={ clickable ? 0 : undefined }
								onClick={
									clickable
										? () => onOpenOverlay( unit )
										: undefined
								}
								onKeyDown={
									clickable
										? ( e ) => {
												if (
													e.key === 'Enter' ||
													e.key === ' '
												) {
													e.preventDefault()
													onOpenOverlay( unit )
												}
										  }
										: undefined
								}
								style={ {
									...oneLineStyle,
									flex: 1,
									minWidth: 0,
									cursor: clickable ? 'pointer' : 'default',
									userSelect: 'text',
								} }
							>
								<Text as="span" variant="bodySm">
									{ originalText }
								</Text>
							</div>
						</div>
					</Box>

					<Box width="45%" minWidth="0">
						<div
							dir={ targetDir }
							style={ { position: 'relative', minWidth: 0 } }
						>
							{ clickable ? (
								<motion.div
									role="button"
									tabIndex={ 0 }
									onClick={ () => onOpenOverlay( unit ) }
									onKeyDown={ ( e ) => {
										if (
											e.key === 'Enter' ||
											e.key === ' '
										) {
											e.preventDefault()
											onOpenOverlay( unit )
										}
									} }
									animate={ {
										opacity: showShimmer ? 0 : 1,
									} }
									transition={ { duration: 0.15 } }
									style={ {
										...oneLineStyle,
										userSelect: 'text',
									} }
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
										{ shownText }
									</Text>
								</motion.div>
							) : (
								<motion.input
									type="text"
									aria-label="Translation"
									value={ draft }
									onChange={ ( e ) =>
										setDraft( e.target.value )
									}
									onBlur={ saveTranslation }
									onKeyDown={ ( e ) => {
										if ( e.key !== 'Enter' ) {
											return
										}
										e.preventDefault()
										const inputs = Array.from(
											document.querySelectorAll(
												'input[aria-label="Translation"]'
											)
										)
										const next =
											inputs[
												inputs.indexOf(
													e.currentTarget
												) + 1
											]
										if ( next ) {
											next.focus()
										} else {
											e.currentTarget.blur()
										}
									} }
									placeholder={ unit.fallback || '' }
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
									className="tl-shimmer"
									initial={ { opacity: 0 } }
									animate={ { opacity: 1 } }
									transition={ { duration: 0.15 } }
									style={ {
										whiteSpace: 'nowrap',
										overflow: 'hidden',
										textOverflow: 'ellipsis',
										fontSize:
											'var(--p-font-size-300, 0.75rem)',
										lineHeight:
											'var(--p-font-line-height-300, 1rem)',
										animationDirection:
											targetDir === 'rtl'
												? 'reverse'
												: 'normal',
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
							{ SEO_FIELD_BADGE[ unit.seo_field ] && (
								<Badge tone="info" size="small">
									{ SEO_FIELD_BADGE[ unit.seo_field ] }
								</Badge>
							) }
							{ badge && (
								<span
									role={
										unit.status === 'needs_review'
											? 'button'
											: undefined
									}
									tabIndex={
										unit.status === 'needs_review' ? 0 : undefined
									}
									aria-label={
										unit.status === 'needs_review'
											? 'Review. Double-click to mark as done.'
											: undefined
									}
									onDoubleClick={
										unit.status === 'needs_review'
											? () => doStatus( 'reviewed' )
											: undefined
									}
									onKeyDown={
										unit.status === 'needs_review'
											? ( event ) => {
													if (
														event.key === 'Enter' ||
														event.key === ' '
													) {
														event.preventDefault()
														doStatus( 'reviewed' )
													}
												  }
											: undefined
									}
									style={ {
										whiteSpace: 'nowrap',
										cursor:
											unit.status === 'needs_review'
												? 'pointer'
												: 'default',
										userSelect: 'none',
									} }
								>
									<Badge
										tone={ badge.tone }
										size="small"
										icon={
											unit.is_ai && badge.label === 'Done'
												? ( props ) => (
														<AiIcon
															{ ...props }
															size={ 12 }
														/>
												  )
												: undefined
										}
									>
										{ badge.label }
									</Badge>
								</span>
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
											{ /* Invisible ghost, same markup as the
											real button below — reserves its exact
											footprint (Polaris's own box, not a
											guessed size) so the slot never shifts
											when the real button mounts/unmounts. */ }
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
												{ ! showShimmer && (
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
															<Tooltip
																content={
																	billingBlocked
																		? 'Subscribe or add your own API key to use AI translation'
																		: 'AI translate'
																}
															>
																<Button
																	variant="secondary"
																	size="micro"
																	accessibilityLabel="AI translate"
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
																	onClick={
																		billingBlocked
																			? onAiBlocked
																			: handleAiClick
																	}
																/>
															</Tooltip>
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
											<ActionList
												sections={ menuSections }
											/>
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
