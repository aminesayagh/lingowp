import {
	ActionList,
	Badge,
	BlockStack,
	Box,
	Button,
	Card,
	ChoiceList,
	Divider,
	EmptyState,
	InlineStack,
	Modal,
	Page,
	Popover,
	Spinner,
	Tag,
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
	FilterIcon,
	InfoIcon,
	MenuHorizontalIcon,
	PageIcon,
	RefreshIcon,
	SettingsIcon,
} from '@shopify/polaris-icons'
import { parseAsString, parseAsStringLiteral, useQueryState } from 'nuqs'
import { AnimatePresence, motion } from 'motion/react'
import { qk } from '../queryKeys'
import {
	fetchBoard,
	useAiTranslate,
	useCollectionAction,
	useSaveTranslation,
	useSetTextStatus,
} from '../queries/board'
import { useTranslateGettext } from '../queries/gettext'
import { useEntitlements } from '../queries/billing'
import {
	useConfirmConnection,
	useConnect,
	useConnectionStatus,
} from '../queries/connection'
import {
	useDeleteLanguage,
	useLanguages,
	usePatchLanguage,
	useSetAllAiModel,
	useUseFallback,
} from '../queries/languages'
import { useProviderKeys } from '../queries/providerKeys'
import { useRefreshScan, useScanStatus } from '../queries/scan'
import { errorMessage } from '../errors'
import { AiIcon } from './AiIcon'
import LanguageList, {
	PUBLISH_MIN_PERCENT,
	sortLanguages,
} from './LanguageList'
import { flagIconUrl, FlagIcon } from './FlagIcon'
import QuotaCard from './QuotaCard'
import { hasMarkers, stripMarkers } from './TranslationUnitEditor'
import { lengthWarningMessage } from './lengthWarning.mjs'

// Split out of the main bundle: each is a modal/screen opened only by an
// explicit action, never needed for first paint — GettextBoard behind the
// Plugins & Themes tab (Content is the default), the other two behind their
// own "open" state (see managerEverOpened / overlayEverOpened below, which
// keep them mounted after their first open so Polaris' Modal still gets to
// run its own close transition instead of being yanked out mid-exit).
const GettextBoard = lazy( () =>
	import( /* webpackChunkName: "gettext-board" */ './GettextBoard' )
)
const LanguageManagerModal = lazy( () =>
	import(
		/* webpackChunkName: "language-manager-modal" */ './LanguageManagerModal'
	)
)
const AddLanguagesPicker = lazy( () =>
	import(
		/* webpackChunkName: "add-languages-picker" */ './AddLanguagesPicker'
	)
)
const CompareOverlay = lazy( () =>
	import( /* webpackChunkName: "compare-overlay" */ './CompareOverlay' )
)

// Schema status → workspace pill. Completed rows keep a visible marker so table
// progress is scannable even when the row is not hovered.
const STATUS_BADGE = {
	missing: null,
	needs_review: { tone: 'warning', label: 'Review' },
	reviewed: { tone: 'success', label: 'Ready' },
	translated: { tone: 'success', label: 'Ready' },
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

// How often to re-poll a texts query while it still holds a `pending` row
// (an AI translation queued but not yet applied) -- each fetch also drains
// the async queue server-side (CollectionsRestController::maybeCollectAiResults),
// so polling is what actually turns a pending row into its real result.
const PENDING_POLL_MS = 3000

// The AI submit can resolve before the shimmer sweep (1.2s loop, see
// .lingowp-shimmer) has visibly run — a fast backend makes the "translating"
// feedback flash and vanish. Once the shimmer starts (on click), hold it at
// least this long so it always registers as an animation.
const MIN_SHIMMER_MS = 1000

// TanStack Query v5 refetchInterval callback: (query) => ms | false. Reads
// query.state.data directly (not the `select`-transformed shape) per v5's
// migration notes -- fine here since these queries have no `select`.
function pollWhilePending( query ) {
	const texts = query.state.data?.texts
	return Array.isArray( texts ) &&
		texts.some( ( t ) => t.status === 'pending' )
		? PENDING_POLL_MS
		: false
}

// The collections list is the only read that drains the AI queue for groups the
// user has NOT expanded (GET /collections calls maybeCollectAiResults, same as
// the texts read, and one pull collects every language at once). A language-wide
// "Translate All" queues most of its work in collapsed groups, so without this
// poll nothing pulls those results back and the board -- and the rail's
// percentage -- sit frozen. Slower than the texts poll: this refetches every
// loaded page.
const BOARD_POLL_MS = 6000

// How often the rail re-reads its percentages while a queue is draining.
const LANGUAGE_POLL_MS = 6000

// POST /translate 402 codes that mean "this site can't use platform AI right
// now" (over its plan's site limit, no subscription, or out of words) — an
// expected, actionable gate, not a backend/connectivity failure.
const QUOTA_ERROR_CODES = [
	'ERR_SITE_OVER_PLAN_LIMIT',
	'ERR_HOSTED_AI_UNAVAILABLE',
	'ERR_INSUFFICIENT_AI_WORDS',
]

// BYOK providers the backend accepts, and their labels in the settings popover.
// Mirrors LanguageManagerModal's aiModelOptions vocabulary.
const PROVIDER_LABELS = { openai: 'OpenAI', anthropic: 'Anthropic' }

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
const cfg = window.LingoWP || {}

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

// Header status filters — sent as ?status= to the server now. 'ignored' is a
// separate view: the normal tree is hidden and a single "Ignored" collection
// lists every ignored text so it can be found and restored from its row menu.
const FILTERS = [
	{ key: 'untranslated', label: 'Untranslated' },
	{ key: 'needs_review', label: 'Needs review' },
	{ key: 'translated', label: 'Translated' },
	{ key: 'ignored', label: 'Ignored' },
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
	{ key: 'author_bios', label: 'Author bios' },
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
// Header status filter (all | untranslated | needs_review | translated) — kept
// in the URL like board/lang so a refresh or shared link keeps it applied.
const statusFilterParser = parseAsStringLiteral( [
	'all',
	...FILTERS.map( ( f ) => f.key ),
] )
	.withDefault( 'all' )
	.withOptions( { history: 'replace' } )
// Language codes are dynamic (whatever the site has configured), so this
// can't be a parseAsStringLiteral like board — plain string is enough since
// refreshLanguages() already validates the value against the fetched list.
const langParser = parseAsString.withDefault( '' ).withOptions( {
	history: 'replace',
} )
// Collection the board is scrolled to — updated as the user scrolls and read
// back on load, so a refresh / bookmark / shared link resumes on the same
// collection. history: 'replace' keeps scroll updates off the history stack;
// nuqs throttles the actual URL writes.
const collectionParser = parseAsString.withDefault( '' ).withOptions( {
	history: 'replace',
} )

export default function Dashboard( {
	defaultLanguage,
	onboardingCompleted,
	onStartOnboarding,
	// Bumped by App.jsx after its own "add your first language" step (the
	// onboarding hand-off modal) creates a language — paired with
	// createdLanguageCode below and run through the same handleLanguagesAdded()
	// as every other "language(s) created" flow, so onboarding also gets
	// extraction-scan polling and the "found N texts" toast, not just a
	// bare refetch.
	languageAddedSignal,
	createdLanguageCode,
} ) {
	const queryClient = useQueryClient()

	// --- server state via React Query --------------------------------------
	// These feed the local useState below through sync effects (further down),
	// so the many downstream references keep working unchanged while the
	// fetching moves off hand-rolled api.get().then(setState) onto cached,
	// shared queries.
	const languagesQ = useLanguages( {
		// Poll while ANY language still has rows queued on the AI backend, so
		// every rail percentage keeps moving -- not just the one whose board is
		// open. Stops on its own once pending_texts drains to zero everywhere.
		refetchInterval: ( q ) =>
			( q.state.data?.languages ?? [] ).some(
				( l ) => ( l.pending_texts ?? 0 ) > 0
			)
				? LANGUAGE_POLL_MS
				: false,
	} )
	const connectionQ = useConnectionStatus( { verify: true } )
	const connected = !! connectionQ.data?.connected
	const entitlementsQ = useEntitlements( { enabled: connected } )
	const providerKeysQ = useProviderKeys( { enabled: connected } )
	const connectMut = useConnect()
	const confirmConnectionMut = useConfirmConnection()
	const patchLangMut = usePatchLanguage()
	const deleteLangMut = useDeleteLanguage()
	const setAllAiModelMut = useSetAllAiModel()
	const useFallbackMut = useUseFallback()
	const refreshScanMut = useRefreshScan()
	const aiTranslateMut = useAiTranslate()
	const translateGettextMut = useTranslateGettext()
	// Non-null while a content-extraction scan is expected to be running: set
	// by the first-language flow and the manual "Rescan" button. Shape:
	// { mode: 'first' | 'rescan', lang?, htmlCrawlRunning? }
	const [ scanWatch, setScanWatch ] = useState( null )
	// Set to Date.now() whenever a watch is armed. scanQ stays mounted with
	// enabled:false between scans, so its cache can still hold the PREVIOUS
	// scan's terminal status (e.g. 'completed') the instant re-enabling flips
	// it back on — before the freshly-invalidated refetch has landed. The
	// completion effect below compares this against scanQ.dataUpdatedAt so it
	// never mistakes that stale leftover for the new scan already finishing.
	const scanArmedAtRef = useRef( 0 )
	const scanQ = useScanStatus( { enabled: !! scanWatch } )

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
	// Latches true on first open and never resets — lets CompareOverlay's lazy
	// chunk load only when actually needed, while staying mounted after that
	// (same reasoning as managerEverOpened below).
	const [ overlayEverOpened, setOverlayEverOpened ] = useState( false )
	useEffect( () => {
		if ( overlay ) {
			setOverlayEverOpened( true )
		}
	}, [ overlay ] )
	// ?collection=<id> — set only by "Share collection". On load it deep-links to
	// that collection (scroll into view) and opens the board in collapse-to-target
	// mode (see collapseToTarget below).
	const [ scrollCollection, setScrollCollection ] = useQueryState(
		'collection',
		collectionParser
	)
	// One-shot "we still owe a scroll-into-view": seeded from the URL on mount,
	// cleared once the jump settles (or the collection turns out to be gone).
	const [ pendingScrollId, setPendingScrollId ] = useState(
		() => scrollCollection || null
	)
	const [ managerOpen, setManagerOpen ] = useState( false )
	const [ managerInitial, setManagerInitial ] = useState( null )
	// Latches true on first open and never resets — lets LanguageManagerModal's
	// lazy chunk load only when actually needed, while staying mounted for
	// every open/close after that (so its own Polaris close transition still
	// gets to run, instead of the component being unmounted mid-exit).
	const [ managerEverOpened, setManagerEverOpened ] = useState( false )
	// A site with zero languages gets the two-column picker (same one
	// onboarding ships) instead of the older single-language form — that
	// form still handles adding a 2nd+ language and editing an existing one.
	const [ addLanguagesPickerOpen, setAddLanguagesPickerOpen ] =
		useState( false )
	const [ pageMenuOpen, setPageMenuOpen ] = useState( false )
	// The target-language column header is a switcher: click it for a popover
	// list of the configured languages.
	const [ langSwitcherOpen, setLangSwitcherOpen ] = useState( false )
	// Content board's filter trigger (icon button next to Translate All): one
	// Popover, its content switching between "pick a filter" (ActionList) and
	// "edit this one filter's value" (its own ChoiceList) — simpler than two
	// separately-anchored popovers, and avoids Polaris's own <Filters> bar,
	// whose built-in trigger can't be repositioned or given a different icon.
	const [ filterPopoverOpen, setFilterPopoverOpen ] = useState( false )
	const [ activeFilterKey, setActiveFilterKey ] = useState( null )
	function closeFilterPopover() {
		setFilterPopoverOpen( false )
		setActiveFilterKey( null )
	}
	const [ refreshing, setRefreshing ] = useState( false )
	// Icon button next to the "Target language" heading — re-pulls /languages
	// (and with it every language's translated_percent) so the rail bars catch
	// up without a full page reload.
	const [ refreshingPercents, setRefreshingPercents ] = useState( false )
	// True right after the first language is created, until the initial
	// content scan (running since plugin activation, independent of
	// onboarding) reports done — gates the board body with a loader instead
	// of an empty content list.
	const [ extractionPending, setExtractionPending ] = useState( false )
	const [ confirm, setConfirm ] = useState( null )
	const [ ai, setAi ] = useState( { available: false, error: '' } )
	const [ quota, setQuota ] = useState( null )
	// Stored BYOK keys (GET /provider-keys), fetched once when the connection
	// checks out. Drives the "Use your <provider> key" items in the settings
	// popover; empty until loaded or when the user has none.
	const [ providerKeys, setProviderKeys ] = useState( [] )
	// Non-null while a "set every language to provider X" run from the settings
	// popover is in flight — disables those items against a double-click.
	const [ applyingProvider, setApplyingProvider ] = useState( null )
	// True while the settings-popover "publish the ready ones" run is in flight.
	const [ publishingReady, setPublishingReady ] = useState( false )
	// A fresh connect also found a matching older licence and emailed its
	// verified owner a reclaim link — this site already works either way,
	// it's a non-blocking bonus notice, not a gate.
	const [ recoveryAvailable, setRecoveryAvailable ] = useState( false )
	// Optimistically false until entitlements load, so the AI buttons don't
	// flash into their blocked state on first paint. Covers two distinct
	// reasons platform AI is unusable (no subscription, or this site is past
	// its plan's site-slot allowance) — both get the same row-level gate;
	// overSlot below only exists to pick the right message for it. Not
	// gated on having a stored BYOK key: POST /translations doesn't consume
	// one yet (platform-only for now), so a stored key can't unblock this.
	const [ billingBlocked, setBillingBlocked ] = useState( false )
	// Language codes whose "Translate All" submit-loop is still in flight. Per
	// language, not one global flag: two languages queue independently on the
	// server, so the sidebar can start Spanish while French is still submitting.
	// Guards only against a second loop for the SAME language, which would
	// wastefully re-resolve the same scope.
	const [ translatingLangs, setTranslatingLangs ] = useState(
		() => new Set()
	)
	const markTranslating = ( code, on ) =>
		setTranslatingLangs( ( prev ) => {
			const next = new Set( prev )
			if ( on ) {
				next.add( code )
			} else {
				next.delete( code )
			}
			return next
		} )
	// The board shows one language at a time, so it shimmers only while that
	// one is submitting -- see the `bulkTranslating` prop below.
	const bulkTranslatingBoard = translatingLangs.has( selectedLang )
	// Narrower reason within billingBlocked: this site specifically is past
	// its plan's site-slot count (a *subscribed* plan, unlike the generic
	// billingBlocked case) — only read for messaging (handleAiBlocked,
	// QuotaCard), never for the row-level gate itself.
	const [ overSlot, setOverSlot ] = useState( false )
	// Toast — every "action complete" message (AI translate, rescan, fallback
	// copy, autosave hint, length warning, share link, …) goes through this
	// one. Never a top-of-page Banner: that shifted the whole board down every
	// time it appeared/disappeared.
	const [ toast, setToast ] = useState( null ) // { tone, content } | null
	const [ statusFilter, setStatusFilter ] = useQueryState(
		'status',
		statusFilterParser
	)
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

	// tone: 'success' | 'warning' | 'critical' | 'info'. Dismiss timing is
	// handled by Polaris' own Toast component (see the render below).
	function showToast( tone, content ) {
		setToast( { tone, content } )
	}

	// TextUnitRow's inline edit and CompareOverlay's autosave both report length
	// warnings up through this same prop.
	const showLengthWarning = ( message ) => showToast( 'warning', message )
	// A row action that was applied optimistically (e.g. a review toggle) failed
	// server-side and has been rolled back — tell the user why the badge snapped.
	const showActionError = ( message ) => showToast( 'critical', message )

	const defaultCode = defaultLanguage?.code || defaultLanguage
	// Same order as the sidebar's language list (LanguageList.jsx), so the
	// header's language-switcher popover matches it rather than the raw
	// /languages response order.
	const sortedLanguages = useMemo(
		() => sortLanguages( languages ),
		[ languages ]
	)
	// `quota` is the /billing/entitlements payload; `active` means a paid
	// subscription is in force (same check refetchEntitlements uses).
	const hasSubscription = !! quota?.active

	// Extra settings-popover items, gated on billing state:
	//  - paid + valid billing + at least one stored BYOK key: one "Use ..."
	//    item per engine (LingoWP model + each key), each bulk-setting every
	//    language and disabled once they're all already on it. With no BYOK
	//    key there's only one engine, so every language is already on it and
	//    the whole group is dead — skip it entirely.
	//  - free / no subscription: the two links that unlock AI.
	const ownKeyProviders = providerKeys
		.map( ( k ) => k.provider )
		.filter( ( p ) => PROVIDER_LABELS[ p ] )
	const langsReady = languagesLoaded && languages.length > 0
	const allLangsOn = ( p ) =>
		langsReady && languages.every( ( l ) => l.ai_model === p )
	// Draft languages already translated enough to go live — the settings-menu
	// "Publish ready languages" action operates on exactly these, and hides
	// itself when there are none.
	const readyToPublish = languages.filter(
		( l ) =>
			! l.enabled && ( l.translated_percent ?? 0 ) >= PUBLISH_MIN_PERCENT
	)
	const providerMenuItems = []
	if ( hasSubscription && ! billingBlocked && ownKeyProviders.length > 0 ) {
		providerMenuItems.push( {
			content: 'Use LingoWP AI model',
			disabled:
				! langsReady || allLangsOn( 'internal' ) || !! applyingProvider,
			onAction: () => {
				setPageMenuOpen( false )
				applyProviderToAllLanguages( 'internal' )
			},
		} )
		for ( const p of ownKeyProviders ) {
			providerMenuItems.push( {
				content: `Use your ${ PROVIDER_LABELS[ p ] } key`,
				disabled:
					! langsReady || allLangsOn( p ) || !! applyingProvider,
				onAction: () => {
					setPageMenuOpen( false )
					applyProviderToAllLanguages( p )
				},
			} )
		}
	} else if ( quota && ! quota.active ) {
		providerMenuItems.push(
			{
				content: 'Add your API key',
				onAction: () =>
					window.location.assign( `${ cfg.account_url }&tab=token` ),
			},
			{
				content: 'Subscribe to LingoWP',
				onAction: () => window.location.assign( cfg.account_url ),
			}
		)
	}
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

	// "Add a language" while none exist yet goes to the two-column picker
	// instead — same content, same call either way, everything else about
	// adding a first vs. a later language stays as it was.
	function openAddLanguage() {
		if ( languages.length === 0 ) {
			setAddLanguagesPickerOpen( true )
			return
		}
		openManager( null )
	}

	// Shared by every "a language was just created" flow — the older
	// single-language form's onChanged (createdCode set, jumps straight to
	// its tab), the two-column picker's onDone/onCreated (now reports back
	// the first code it created), and App.jsx's onboarding hand-off (relayed
	// down via createdLanguageCode, see the languageAddedSignal effect above).
	function handleLanguagesAdded( createdCode ) {
		const wasFirstLanguage = languages.length === 0
		refreshLanguages()
		if ( createdCode ) {
			setSelectedLang( createdCode )
		}
		if ( wasFirstLanguage ) {
			// Gate the board on the activation-time scan. The scan-completion
			// effect (below) clears extractionPending and fires the "found N
			// texts" toast once useScanStatus reports a terminal status —
			// including immediately, if the scan already finished.
			setExtractionPending( true )
			scanArmedAtRef.current = Date.now()
			queryClient.invalidateQueries( { queryKey: qk.scan.status() } )
			setScanWatch( { mode: 'first', lang: createdCode } )
		}
	}

	// Content count for the "extraction finished" toast — /scan/status itself
	// deliberately never carries counts (see ScanRestController's own doc
	// comment), so ask the board for the same total_texts value it already
	// shows in the header progress badge. total is a property of the source
	// content itself (how many translatable units a collection holds), not
	// of any one target language's translation progress, so any language
	// code works here, including one that's still a draft.
	function announceExtractionDone( lang ) {
		if ( ! lang ) {
			return
		}
		fetchBoard( `/collections?language=${ enc( lang ) }&limit=1` )
			.then( ( res ) => {
				const count = res.total_texts ?? 0
				showToast(
					'success',
					`Found ${ count } text${
						count === 1 ? '' : 's'
					} to translate.`
				)
			} )
			.catch( () => {} )
	}

	// Ask React Query to re-pull entitlements (called after an AI translate —
	// usage changed). The useEntitlements query + its sync effect below do the
	// actual fetch + state derivation.
	const refetchEntitlements = useCallback( () => {
		return queryClient.invalidateQueries( {
			queryKey: qk.billing.entitlements(),
		} )
	}, [ queryClient ] )

	// --- sync effects: query data -> the local useState the UI still reads ---

	// /languages -> the rail list + catalog, and reconcile the selected code.
	useEffect( () => {
		if ( languagesQ.isError ) {
			setLanguageCatalog( [] )
			setLanguagesLoaded( true )
			return
		}
		if ( ! languagesQ.data ) {
			return
		}
		const langs = languagesQ.data.languages || []
		setLanguageCatalog( languagesQ.data.catalog || [] )
		setLanguages( langs )
		setLanguagesLoaded( true )
		setSelectedLang( ( cur ) => {
			if ( cur && langs.some( ( l ) => l.code === cur ) ) {
				return cur
			}
			const active = langs.find( ( l ) => l.enabled ) || langs[ 0 ]
			return active?.code || ''
		} )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- setSelectedLang stable
	}, [ languagesQ.data, languagesQ.isError ] )

	// Any language with rows still queued on the AI backend. Drives both polls:
	// the rail re-reads its own percentages, and the board keeps reading (which
	// is what actually drains the queue, for every language at once).
	const anyLanguagePending = languages.some(
		( l ) => ( l.pending_texts ?? 0 ) > 0
	)

	// /billing/entitlements -> quota rail + billing gates.
	useEffect( () => {
		if ( entitlementsQ.isError ) {
			setQuota( null )
			return
		}
		const ent = entitlementsQ.data
		if ( ent === undefined ) {
			return
		}
		setQuota( ent )
		const subscribed = !! ent?.active
		const overSlotNow = !! ent?.over_slot
		setOverSlot( overSlotNow )
		setBillingBlocked( ! subscribed || overSlotNow )
	}, [ entitlementsQ.data, entitlementsQ.isError ] )

	// /connection/check -> AI availability, plus a one-shot warning toast when
	// connected-but-unavailable, and the disconnected billing gate.
	const connWarnedRef = useRef( false )
	useEffect( () => {
		const status = connectionQ.data
		if ( ! status ) {
			return
		}
		setAi( { available: status.available, error: status.error } )
		if (
			status.connected &&
			! status.available &&
			status.error &&
			! connWarnedRef.current
		) {
			connWarnedRef.current = true
			showToast( 'warning', errorMessage( status.error ) )
		}
		if ( ! status.connected ) {
			setBillingBlocked( true )
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- showToast stable
	}, [ connectionQ.data ] )

	// /provider-keys -> the BYOK list used by the settings popover.
	useEffect( () => {
		setProviderKeys(
			Array.isArray( providerKeysQ.data ) ? providerKeysQ.data : []
		)
	}, [ providerKeysQ.data ] )

	// Deep link from any confirm email — attach-website, a licence reclaim,
	// or POST /billing/owner's assign-owner ask: ?confirm_token=... . One
	// route (/connection/confirm) relays every kind; PHP branches on whichever
	// shape the backend's response actually carries. This is an opaque,
	// single-use, expiring token — never a raw license key — so nothing here
	// can leak a real credential via browser history, referrer headers, or
	// mail-scanner prefetching. Awaited (unlike the old fire-and-forget
	// version) so checkConnection() runs against the *applied* state — a
	// reclaim in particular must not render one frame of "free tier /
	// over_slot" right after the user clicked "move my plan here", which
	// reads as the reclaim having failed.
	const [ confirmToken, setConfirmToken ] = useQueryState(
		'confirm_token',
		parseAsString
	)
	useEffect( () => {
		if ( ! confirmToken ) {
			// The on-mount connection check is now the useConnectionStatus
			// query above.
			return
		}
		confirmConnectionMut
			.mutateAsync( { token: confirmToken } )
			.then( ( res ) => {
				setConfirmToken( null )
				if ( ! res.ok ) {
					showToast( 'warning', errorMessage( res.error ) )
				}
				// useConfirmConnection's onSuccess invalidates the connection
				// query, so `ai` re-syncs against the applied state.
			} )
			.catch( () => setConfirmToken( null ) )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- runs once on mount
	}, [] )

	// Explicit, user-initiated connect/reconnect — connect() never blocks or
	// emails anything itself now: every success carries a working key. The
	// mutation's onSuccess invalidates connection + entitlements + provider
	// keys, so those re-sync on their own.
	function handleConnect() {
		connectMut.mutate( undefined, {
			onSuccess: ( res ) => {
				if ( res.ok ) {
					setAi( { available: res.available, error: '' } )
					setRecoveryAvailable( res.recoveryAvailable )
					return
				}
				showToast(
					'warning',
					errorMessage( res.error || 'ERR_BACKEND' )
				)
			},
		} )
	}

	// Row AI buttons route here instead of translating when billingBlocked —
	// a toast the user can click straight through to Billing, rather than a
	// silent disabled button. overSlot picks the more specific message: this
	// site is subscribed but past its plan's site count, not unsubscribed.
	function handleAiBlocked() {
		setToast( {
			tone: 'info',
			content: overSlot
				? errorMessage( 'ERR_SITE_OVER_PLAN_LIMIT' )
				: `${ errorMessage(
						'ERR_HOSTED_AI_UNAVAILABLE'
				  ) } Tap to view plans.`,
			onClick: () => window.location.assign( cfg.account_url ),
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

	// Shared by translateScope()'s failure path and GettextBoard's per-unit
	// translate (onAiUnavailable) — quota/plan-limit errors are an expected,
	// actionable gate (over the site limit / no subscription / out of words),
	// never "the backend call itself failed", so they must never flip
	// ai.available off, and the toast should say exactly what's wrong instead
	// of a generic message (or, for GettextBoard, no message at all before
	// this existed).
	function handleAiFailure( error ) {
		const isQuotaError = QUOTA_ERROR_CODES.includes( error )
		if ( error !== 'ERR_INVALID_INPUT' && ! isQuotaError ) {
			setAi( { available: false, error } )
		}
		showToast(
			'critical',
			isQuotaError
				? errorMessage( error )
				: 'AI translation failed. Try again.'
		)
	}

	// Re-read everything a submit just made stale. The response is only
	// {ok, submitted, more} -- translation happens later in the async queue,
	// applied server-side on the next board read (see
	// CollectionsRestController::maybeCollectAiResults). So there's nothing to
	// patch, just invalidate so the freshly-stamped `pending` rows show up, and
	// await it so callers (the per-row "translating" flag) see the pending
	// status before they decide whether to keep showing their own spinner.
	async function refreshAfterTranslate( langs ) {
		for ( const code of langs ) {
			invalidateCollections( code )
		}
		await queryClient.invalidateQueries( {
			predicate: ( q ) =>
				q.queryKey[ 0 ] === 'texts' &&
				langs.includes( q.queryKey[ 2 ] ),
		} )
		refetchEntitlements()
		refreshLanguages() // rail percentages changed
	}

	// AI translate a scope (text | collection | language), then refresh views +
	// quota -- unless the caller passes `deferRefresh`, which the multi-batch
	// "translate all" loops use to refresh once at the end instead of once per
	// 500-text batch (mid-loop nothing has landed yet, so the refetch storm was
	// pure cost).
	async function translateScope( scope, opts = {} ) {
		if ( ! ai.available ) {
			showToast(
				'warning',
				ai.error || 'AI unavailable — connect LingoWP to enable it.'
			)
			return
		}
		const { deferRefresh = false, ...params } = opts
		// A caller can target languages other than the dashboard's currently
		// selected one — every cache op below keys off the response's own
		// language(s), not selectedLang. CompareOverlay passes `languages: [...]`
		// to translate one text into every configured language in a single
		// request; every other caller passes a single `language`.
		const multi =
			Array.isArray( params.languages ) && params.languages.length > 0
		const langs = multi
			? params.languages
			: [ params.language ?? selectedLang ]
		const res = await aiTranslateMut.mutateAsync( {
			scope,
			...params,
			// exactly one of these two goes on the wire; the other is dropped by
			// JSON.stringify (undefined values are omitted).
			...( multi
				? { languages: langs.join( ',' ), language: undefined }
				: { language: langs[ 0 ], languages: undefined } ),
		} )
		if ( ! res.ok ) {
			handleAiFailure( res.error )
			return
		}
		if ( ! deferRefresh ) {
			await refreshAfterTranslate( langs )
		}
		return res
	}

	// Header's sitewide "Translate All": one click queues every still-missing
	// text (never needs-review -- pending() on the server already excludes
	// it, same as the per-collection action) across the whole selected
	// language. No confirm dialog, same reasoning as the per-collection
	// action: this never removes anything. Auto-continues on `more` --
	// language-wide is exactly the scope most likely to exceed
	// AiQueueConfig::MAX_TEXTS_PER_SUBMIT in one request.
	async function translateAllInLanguage() {
		// Marking the language in flight also shimmers every still-missing row on
		// the board immediately, before the first POST -- same trick as the
		// per-collection "Translate all". The real `pending` status only lands a
		// POST + refetch later, which reads as the click having done nothing.
		const code = selectedLang
		if ( translatingLangs.has( code ) ) {
			return
		}
		markTranslating( code, true )
		const args = { source: board, language: code, deferRefresh: true }
		try {
			let res = await translateScope( 'language', args )
			while ( res?.more ) {
				res = await translateScope( 'language', args )
			}
			await refreshAfterTranslate( [ code ] )
		} finally {
			markTranslating( code, false )
		}
	}

	// Same as translateAllInLanguage() but targets one language explicitly (from
	// its row menu in the sidebar), so the user needn't select it first. Other
	// languages may be mid-submit at the same time; only a second loop for THIS
	// language is refused. A toast confirms the queue since an unselected
	// language shows no shimmering rows to signal work is underway.
	async function translateAllForLanguage( lang ) {
		if ( translatingLangs.has( lang.code ) ) {
			return
		}
		if ( billingBlocked ) {
			handleAiBlocked()
			return
		}
		if ( ! ai.available ) {
			showToast(
				'warning',
				ai.error || 'AI unavailable — connect LingoWP to enable it.'
			)
			return
		}
		markTranslating( lang.code, true )
		const args = {
			source: board,
			language: lang.code,
			deferRefresh: true,
		}
		try {
			let res = await translateScope( 'language', args )
			while ( res?.more ) {
				res = await translateScope( 'language', args )
			}
			if ( res?.ok ) {
				showToast(
					'success',
					`Translating ${
						lang.display_name ||
						lang.native_display ||
						lang.name ||
						lang.code
					}…`
				)
			}
			await refreshAfterTranslate( [ lang.code ] )
		} finally {
			markTranslating( lang.code, false )
		}
	}

	// Plugins & Themes board equivalent of the above -- a distinct endpoint
	// (gettext strings live in .mo files, not the Content board's tables), so
	// this can't reuse translateScope(). Same auto-continue-on-`more` shape;
	// the server scans a few never-opened components per call and the rest
	// wait for this loop's next POST (see BOARD_TRANSLATE_SCAN_BUDGET).
	async function translateAllGettext() {
		if ( ! ai.available ) {
			showToast(
				'warning',
				ai.error || 'AI unavailable — connect LingoWP to enable it.'
			)
			return
		}
		markTranslating( selectedLang, true )
		try {
			const gettextBoardArgs = { scope: 'board', locale: selectedLang }
			let res = await translateGettextMut.mutateAsync( gettextBoardArgs )
			while ( res?.ok && res.more ) {
				res = await translateGettextMut.mutateAsync( gettextBoardArgs )
			}
			if ( ! res?.ok ) {
				handleAiFailure( res?.error )
				return
			}
			await queryClient.invalidateQueries( {
				queryKey: [ 'gettext-domains', selectedLang ],
			} )
			await queryClient.invalidateQueries( {
				predicate: ( q ) =>
					q.queryKey[ 0 ] === 'gettext-units' &&
					q.queryKey[ 3 ] === selectedLang,
			} )
			refetchEntitlements()
		} finally {
			markTranslating( selectedLang, false )
		}
	}

	function patchLangStatus( code, status ) {
		patchLangMut.mutate( { code, status } )
	}

	// Row menu "Switch to LingoWP model": point a language that currently
	// translates through the user's own provider key back at the subscription
	// model. Only offered (see the LanguageList props below) when a subscription
	// is active and the language's ai_model is a BYO provider.
	function switchLangToInternal( lang ) {
		patchLangMut.mutate(
			{ code: lang.code, ai_model: 'internal' },
			{
				onSuccess: () =>
					showToast( 'success', 'Now using the LingoWP model.' ),
			}
		)
	}

	// Settings-popover bulk action: point every configured language at one AI
	// provider ('internal' for the LingoWP model, or a BYOK provider key). One
	// POST that rewrites the whole lingowp_languages map in a single option
	// write — a PATCH-per-language loop races itself (each PATCH is a
	// read-modify-write of that shared option) and silently drops some changes.
	async function applyProviderToAllLanguages( target ) {
		setApplyingProvider( target )
		let ok = true
		try {
			await setAllAiModelMut.mutateAsync( { ai_model: target } )
		} catch {
			ok = false
		}
		setApplyingProvider( null )
		showToast(
			ok ? 'success' : 'critical',
			ok ? 'All languages updated.' : 'Could not update the languages.'
		)
	}

	// Settings-popover bulk action: publish every draft language that already
	// has enough translated content. Sequential on purpose — each PATCH is a
	// read-modify-write of the shared lingowp_languages option, so firing them
	// in parallel would drop some of the changes.
	// ponytail: no bulk endpoint; the roster is a handful of languages.
	async function publishReadyLanguages( ready ) {
		setPublishingReady( true )
		let published = 0
		for ( const lang of ready ) {
			try {
				await patchLangMut.mutateAsync( {
					code: lang.code,
					status: 'active',
				} )
				published++
			} catch {
				// keep going; the count below reports what actually landed
			}
		}
		setPublishingReady( false )
		showToast(
			published === ready.length ? 'success' : 'critical',
			published === ready.length
				? `Published ${ published } language${
						published === 1 ? '' : 's'
				  }.`
				: `Published ${ published } of ${ ready.length } languages.`
		)
	}

	function deleteLang( code ) {
		deleteLangMut.mutate( code, {
			onSuccess: () =>
				setSelectedLang( ( cur ) => ( cur === code ? '' : cur ) ),
		} )
	}

	function confirmDeleteLang( lang ) {
		setConfirm( {
			title: `Delete ${
				lang.display_name ||
				lang.native_display ||
				lang.name ||
				lang.code
			}?`,
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
				lang.display_name ||
				lang.native_display ||
				lang.name ||
				lang.code
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
			? fb.display_name || fb.native_display || fb.name || fb.code
			: lang.fallback_language
		setConfirm( {
			title: `Use ${ fbLabel } translations for ${
				lang.display_name ||
				lang.native_display ||
				lang.name ||
				lang.code
			}?`,
			body: "Fills in every text this language is missing using its fallback language's existing translations, marked as Needs review. Existing translations are never overwritten.",
			actionLabel: 'Use fallback translation',
			onConfirm: async () => {
				let res
				try {
					res = await useFallbackMut.mutateAsync( lang.code )
				} catch {
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
			},
		} )
	}

	// Re-pull /languages (rail percentages, roster). The useLanguages query +
	// its sync effect above do the fetch + state derivation; this just marks
	// it stale. Returns the invalidation promise so the "refresh %" icon
	// button can await it.
	function refreshLanguages() {
		return queryClient.invalidateQueries( {
			queryKey: qk.languages.list(),
		} )
	}

	// languageAddedSignal starts undefined/0 and only changes after App.jsx's
	// onboarding hand-off actually creates a language — skip the initial
	// mount (refreshLanguages() above already covers it) and only react to a
	// real bump. Routed through handleLanguagesAdded(), not a bare
	// refreshLanguages(), so onboarding gets the same extraction-scan
	// polling and "found N texts" toast as every other add-language flow.
	const firstLanguageAddedSignal = useRef( languageAddedSignal )
	useEffect( () => {
		if ( languageAddedSignal === firstLanguageAddedSignal.current ) {
			return
		}
		firstLanguageAddedSignal.current = languageAddedSignal
		handleLanguagesAdded( createdLanguageCode )
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ languageAddedSignal ] )

	// A queued AI translation only consumes words later, when its `pending` row
	// is drained by a board read -- not by the POST that queued it.
	// translateScope()'s own refetchEntitlements() therefore runs too early to
	// see the usage change. Watch the cache and refetch once the last pending
	// row lands, so QuotaCard's consumed count catches up on its own.
	//
	// Counting collection summaries as well as texts rows is load-bearing: a
	// language-wide "Translate All" queues thousands of rows in collections the
	// user never expanded, so there is no `texts` query holding them -- the edge
	// would otherwise fire the moment the first expanded group finished.
	const pendingCountRef = useRef( 0 )
	useEffect( () => {
		const cache = queryClient.getQueryCache()
		const pendingCount = () => {
			let n = 0
			for ( const q of cache.getAll() ) {
				if ( q.queryKey[ 0 ] === 'texts' ) {
					const texts = q.state.data?.texts
					if ( Array.isArray( texts ) ) {
						n += texts.filter(
							( t ) => t.status === 'pending'
						).length
					}
				} else if ( q.queryKey[ 0 ] === 'collections' ) {
					for ( const page of q.state.data?.pages ?? [] ) {
						for ( const c of page.collections ?? [] ) {
							n += c.pending ?? 0
						}
					}
				}
			}
			return n
		}
		return cache.subscribe( () => {
			const now = pendingCount()
			// Falling edge only: the rail polls its own percentages while the
			// queue drains (see anyLanguagePending), so this exists for the
			// quota rail, which only settles once the last row lands.
			if ( pendingCountRef.current > 0 && now === 0 ) {
				refetchEntitlements()
				refreshLanguages()
			}
			pendingCountRef.current = now
		} )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- queryClient/refetchEntitlements stable; refreshLanguages is a stable declaration
	}, [] )

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
		queryFn: ( { pageParam, signal } ) =>
			fetchBoard(
				`/collections?language=${ enc(
					selectedLang
				) }&offset=${ pageParam }&limit=${ PAGE_SIZE }` +
					`${ searchParam( query ) }&status=${ enc(
						statusFilter
					) }&source=${ enc( board ) }${ advancedFilterParams }`,
				signal
			),
		getNextPageParam: ( last ) =>
			last.has_more ? last.next_offset : undefined,
		// Plugins & Themes has its own board (GettextBoard) now — no need to
		// fetch the collections-count list while that tab is active.
		enabled: !! selectedLang && board === 'content',
		// Gated on any language's queue, not just this board's: the drain it
		// triggers is global, so a background language's results still land.
		refetchInterval: anyLanguagePending ? BOARD_POLL_MS : false,
	} )

	function invalidateCollections( lang = selectedLang ) {
		queryClient.invalidateQueries( {
			predicate: ( q ) =>
				q.queryKey[ 0 ] === 'collections' &&
				q.queryKey[ 1 ]?.language === lang,
		} )
	}

	// Manual "recheck the site for missing text": re-arm the activation-time
	// scan. useScanStatus then polls, and the scan-completion effect below
	// refetches the board and toasts when it finishes.
	//
	// The HTML crawl also gets (re-)triggered by this same click, but it is
	// deliberately NOT waited on — it's network-bound and can run far longer
	// than the DB-only scan; newly discovered hardcoded text just shows up
	// next time the html:all group is fetched. htmlCrawlRunning only picks the
	// wording of the completion toast.
	function refreshScan() {
		if ( refreshing ) {
			return
		}
		setRefreshing( true )
		showToast( 'info', 'Rescanning your site…' )
		refreshScanMut.mutate( undefined, {
			onSuccess: ( res ) => {
				scanArmedAtRef.current = Date.now()
				setScanWatch( {
					mode: 'rescan',
					htmlCrawlRunning: res.html_status === 'running',
				} )
			},
			onError: () => {
				setRefreshing( false )
				showToast( 'critical', 'Scan failed. Try again.' )
			},
		} )
	}

	// "Refresh" on the first-scan gate: force an immediate status re-check
	// instead of waiting for the next poll tick. A terminal status is handled
	// by the scan-completion effect below.
	function recheckFirstScan() {
		scanQ.refetch().then( ( res ) => {
			if ( ( res.data?.status ?? 'running' ) === 'running' ) {
				showToast( 'info', 'Still scanning — check back in a moment.' )
			}
		} )
	}

	// Drive the one-shot scan outcomes off the polled status. scanWatch says
	// what kicked the scan; a terminal status runs the matching finish and
	// stops the watch (which stops useScanStatus polling).
	useEffect( () => {
		if ( ! scanWatch || ! scanQ.data ) {
			return
		}
		// scanQ can still be holding the PREVIOUS scan's terminal result the
		// instant this watch re-enables it — the invalidated refetch this watch
		// triggered hasn't landed yet. Wait for data that's actually newer than
		// the arm, so a stale 'completed' can't be mistaken for this scan.
		if ( scanQ.dataUpdatedAt < scanArmedAtRef.current ) {
			return
		}
		const status = scanQ.data.status || 'running'
		if ( status === 'running' ) {
			return
		}
		const watch = scanWatch
		setScanWatch( null )
		if ( watch.mode === 'first' ) {
			setExtractionPending( false )
			if ( status === 'completed' ) {
				invalidateCollections()
				announceExtractionDone( watch.lang )
			}
		} else {
			setRefreshing( false )
			if ( status === 'completed' ) {
				showToast(
					'success',
					watch.htmlCrawlRunning
						? 'Scan complete — still scanning for more text.'
						: 'Scan complete.'
				)
				invalidateCollections()
			} else {
				showToast( 'critical', 'Scan failed. Try again.' )
			}
		}
		// scanQ.dataUpdatedAt is listed, not just scanQ.data: React Query's
		// structural sharing can hand back the SAME data reference across two
		// fetches with equal content (e.g. two 'completed' results in a row),
		// which would silently skip this effect on the real completion if only
		// the object reference were watched.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- helpers are stable declarations
	}, [ scanQ.data, scanQ.dataUpdatedAt, scanWatch ] )

	// Copies a link that lands on this same board/language and scrolls straight
	// to this collection (view/board/lang/collection all restore from the query
	// string via nuqs — see pendingScrollId).
	async function shareCollection( c ) {
		const url = new URL( window.location.href )
		url.searchParams.set( 'view', 'dashboard' )
		url.searchParams.set( 'board', board )
		url.searchParams.set( 'lang', selectedLang )
		url.searchParams.set( 'collection', c.id )
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
			( statusFilter === 'untranslated' &&
				( summary.missing > 0 || summary.pending > 0 ) ) ||
			( statusFilter === 'needs_review' && summary.needs_review > 0 ) ||
			( statusFilter === 'translated' && summary.translated > 0 ) ||
			// Ignored view: keep the one "Ignored" card while any ignored text
			// remains (a Restore drops the row and decrements total); drop it at 0.
			( statusFilter === 'ignored' && summary.total > 0 )

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
	// A shared "?collection=" link opens the board with only that collection
	// expanded, so paging down to it stays cheap (collapsed groups don't fetch
	// their texts). The header's "Open all collections" button exits this mode.
	const [ collapseToTarget, setCollapseToTarget ] = useState(
		() => !! scrollCollection
	)
	// ids we've already auto-collapsed once, so a later manual expand sticks.
	const seededCollapseRef = useRef( new Set() )
	useEffect( () => {
		setCollapsedIds( new Set() )
	}, [ selectedLang ] )

	// While in collapse-to-target mode, collapse every non-target group as its
	// page arrives — once each, so a manual re-expand afterwards is not undone.
	useEffect( () => {
		if ( ! collapseToTarget ) {
			return
		}
		setCollapsedIds( ( prev ) => {
			const next = new Set( prev )
			let changed = false
			for ( const g of groups ) {
				if (
					g.id === scrollCollection ||
					seededCollapseRef.current.has( g.id )
				) {
					continue
				}
				seededCollapseRef.current.add( g.id )
				if ( ! next.has( g.id ) ) {
					next.add( g.id )
					changed = true
				}
			}
			return changed ? next : prev
		} )
	}, [ collapseToTarget, groups, scrollCollection ] )

	// Leave collapse-to-target mode (expand everything, clear ?collection=).
	// Reused by the header button and the settle effect's "not found" branch.
	const openAllCollections = useCallback( () => {
		setCollapseToTarget( false )
		setCollapsedIds( new Set() )
		seededCollapseRef.current = new Set()
		setScrollCollection( null )
		// eslint-disable-next-line react-hooks/exhaustive-deps -- setters are stable
	}, [] )

	// Window-scroll virtualization: only near-view group rows mount. One extra
	// "sentinel" row (count+1) carries the load-more/retry control when more
	// pages exist. Scroll margin is measured (not assumed), so content above the
	// list (notices, sticky header) doesn't misalign rows.
	const listRef = useRef( null )
	// True while the deep-link scroll-into-view rAF loop is running.
	const scrollSettlingRef = useRef( false )
	const [ scrollMargin, setScrollMargin ] = useState( 0 )
	useLayoutEffect( () => {
		const update = () => setScrollMargin( listRef.current?.offsetTop ?? 0 )
		update()
		window.addEventListener( 'resize', update )
		return () => window.removeEventListener( 'resize', update )
	}, [] )

	// Height of the sticky search/filter/column-headers bar, measured live so
	// each collection's own header can pin itself directly beneath it, and so
	// the collapse anchor and deep-link scroll land below it, not behind it.
	// A ResizeObserver keeps it right as the filter row animates open/closed
	// or the column headers show/hide.
	//
	// Split in two on purpose: the CSS variable (set imperatively, no React
	// render) is what each CollectionGroup header's `top` actually reads, so
	// it tracks every frame of the filter row's height animation for free.
	// `stickyBarH` React state is only for scrollPaddingStart/the collapse
	// anchor below — neither needs frame-perfect freshness, only "correct once
	// settled" — so it's debounced. Without that split, the filter-row
	// animation forced a full Dashboard re-render (every row's sticky offset,
	// the whole virtualized list) on every one of its ~60fps ticks, which is
	// what made the animation itself look stuttery.
	const stickyRef = useRef( null )
	const cardRef = useRef( null )
	const [ stickyBarH, setStickyBarH ] = useState( 0 )
	useLayoutEffect( () => {
		const el = stickyRef.current
		if ( ! el ) {
			return undefined
		}
		let settleTimer = null
		const update = () => {
			const h = el.offsetHeight
			cardRef.current?.style.setProperty(
				'--lingowp-group-sticky-top',
				`${ 32 + h }px`
			)
			clearTimeout( settleTimer )
			settleTimer = setTimeout( () => setStickyBarH( h ), 120 )
		}
		update()
		const ro = new ResizeObserver( update )
		ro.observe( el )
		return () => {
			clearTimeout( settleTimer )
			ro.disconnect()
		}
	}, [] )
	// 32 mirrors the bar's own `top`. Only feeds scrollPaddingStart and the
	// collapse anchor's scroll math below — the live per-render value groups
	// actually stick to is the CSS variable set above.
	const groupStickyTop = 32 + stickyBarH

	const getItemKey = useCallback(
		( i ) => ( i < groups.length ? groups[ i ].id : '__sentinel__' ),
		[ groups ]
	)
	const v = useWindowVirtualizer( {
		count: groupsQ.hasNextPage ? groups.length + 1 : groups.length,
		// Collapsed rows are ~5x shorter than expanded ones. Estimating each row
		// by its collapsed/expanded state keeps scrollToIndex accurate when
		// paging down to a deep-linked collection with everything above it
		// collapsed (collapse-to-target mode).
		estimateSize: ( i ) =>
			collapsedIds.has( groups[ i ]?.id ) ? 72 : 320,
		overscan: 3,
		getItemKey,
		scrollMargin,
		// So a deep-linked collection (pendingScrollId) lands just below the
		// sticky bar instead of behind it.
		scrollPaddingStart: groupStickyTop,
	} )
	const virtualItems = v.getVirtualItems()
	// Flow (non-absolute) layout: the rendered window sits between two spacer
	// divs instead of each row being absolutely positioned. This keeps every
	// group's header in normal document flow, which is what lets it use native
	// `position: sticky` and get pushed out by the next group's header for free.
	const listPadTop = virtualItems[ 0 ]
		? virtualItems[ 0 ].start - scrollMargin
		: 0
	const listPadBottom = virtualItems.length
		? Math.max(
				0,
				v.getTotalSize() -
					( virtualItems[ virtualItems.length - 1 ].end -
						scrollMargin )
		  )
		: 0

	const toggleCollapsed = useCallback(
		( id ) => {
			const collapsing = ! collapsedIds.has( id )

			// Keep the clicked header exactly where it is on screen. When it's
			// pinned to the sticky bar you've scrolled into the section;
			// collapsing pulls the content out from under it and the shrunk
			// section slides up, carrying the header away and surfacing the
			// next one. The section's *top* doesn't move when it collapses, so
			// anchoring the scroll to it (top flush under the bar) leaves the
			// header sitting at the same spot. `row` is the non-sticky wrapper,
			// so its rect is the section's true position, not the pinned one.
			let anchorY = null
			if ( collapsing ) {
				const index = groups.findIndex( ( g ) => g.id === id )
				const row =
					index >= 0 &&
					listRef.current?.querySelector(
						`[data-index="${ index }"]`
					)
				if ( row ) {
					const top = row.getBoundingClientRect().top + window.scrollY
					if ( window.scrollY > top - groupStickyTop ) {
						anchorY = Math.max( 0, top - groupStickyTop )
					}
				}
			}

			setCollapsedIds( ( prev ) => {
				const next = new Set( prev )
				if ( next.has( id ) ) {
					next.delete( id )
				} else {
					next.add( id )
				}
				return next
			} )

			if ( anchorY !== null ) {
				window.requestAnimationFrame( () =>
					window.scrollTo( 0, anchorY )
				)
			}
		},
		[ collapsedIds, groups, groupStickyTop ]
	)

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

	// Deep-link landing (from a shared "?collection=" link). Page forward from
	// offset 0 until the target appears in `groups`, then scroll it into view.
	// In collapse-to-target mode the groups above it are collapsed, so this walk
	// only fetches the summary pages, not every group's texts. Gives up once
	// every page is loaded and it's still not there (removed / filtered out).
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
			// Re-issue the jump across frames: each pass mounts a bit more of the
			// path so the size estimates converge on reality; stop once
			// v.scrollOffset stops moving between two frames, capped as a valve.
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
					window.requestAnimationFrame( settle )
					return
				}
				scrollSettlingRef.current = false
				setPendingScrollId( null )
			}
			window.requestAnimationFrame( settle )
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
		openAllCollections() // don't leave a fully-collapsed board with no target
		// eslint-disable-next-line react-hooks/exhaustive-deps -- setters + showToast are stable
	}, [
		pendingScrollId,
		groups,
		groupsQ.isPending,
		hasNextGroupsPage,
		isFetchingNextGroupsPage,
		fetchNextGroupsPage,
		openAllCollections,
		v,
	] )

	// Collapse-to-target mode only makes sense for the pristine shared-link view.
	// Any context change (search, filter, language, board) exits it. Not armed
	// until selectedLang is known, so an initial '' -> resolved language settle
	// doesn't count as a change (shared links always carry ?lang= anyway).
	const collapseCtxMountRef = useRef( false )
	useEffect( () => {
		if ( ! collapseCtxMountRef.current ) {
			if ( ! selectedLang ) {
				return
			}
			collapseCtxMountRef.current = true
			return
		}
		setCollapseToTarget( false )
		seededCollapseRef.current = new Set()
	}, [ selectedLang, query, statusFilter, board, advancedFilterParams ] )

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
		queryFn: ( { signal } ) =>
			fetchBoard(
				`/collections/${ enc( overlay.cid ) }/texts?language=${ enc(
					selectedLang
				) }${ searchParam( query ) }&status=${ enc(
					statusFilter
				) }${ advancedFilterParams }`,
				signal
			),
		enabled: !! overlay,
		refetchInterval: pollWhilePending,
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
		queryFn: ( { signal } ) =>
			fetchBoard(
				`/collections/text-order?language=${ enc(
					selectedLang
				) }${ searchParam( query ) }&status=${ enc(
					statusFilter
				) }&source=${ enc( board ) }${ advancedFilterParams }`,
				signal
			),
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
		if ( patch?.ignore_status_changed ) {
			// Ignore/restore moves the row between status views (in/out of the
			// Ignored filter). An optimistic single-collection patch can't add it
			// to the ignored:all list it isn't in yet, so refresh every board
			// query for this language plus the ignored:all texts list — with
			// staleTime 5m, an already-open Ignored view won't refetch otherwise.
			invalidateCollections()
			queryClient.invalidateQueries( {
				queryKey: [ 'texts', 'ignored:all', selectedLang ],
			} )
		} else if ( patch?.collection_summary ) {
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

	// The Plugins & Themes board only understands "Untranslated only" — any
	// other statusFilter value carried over from the Content board would sit
	// in the URL doing nothing visible (the button reads "not pressed"). Drop
	// it to 'all' on switch so the state matches what's shown.
	useEffect( () => {
		if (
			showGettextBoard &&
			statusFilter !== 'all' &&
			statusFilter !== 'untranslated'
		) {
			setStatusFilter( 'all' )
		}
	}, [ showGettextBoard, statusFilter, setStatusFilter ] )
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
	// The content board is up (in any of its states: loading, error, empty, or
	// populated). The column-headers row — which carries the language switcher —
	// rides with the board, not just the row list, so the switcher stays usable
	// while content loads and the header doesn't pop in when rows arrive.
	const showContentBoard =
		! showGettextBoard &&
		! showNeedsOnboarding &&
		! showNoLanguages &&
		! extractionPending

	let emptyGroupsMessage =
		'No translatable content found for this language yet.'
	if ( searching ) {
		emptyGroupsMessage = `No text matches “${ query }” in this language.`
	} else if ( statusFilter === 'ignored' ) {
		emptyGroupsMessage = 'No ignored text.'
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
		l &&
		( l.display_name ||
			l.native_display ||
			l.name ||
			codeName( l.code || l ) )
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
											LingoWP
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
										<div className="lingowp-settings-menu">
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
													...( readyToPublish.length
														? [
																{
																	content:
																		'Publish ready',
																	disabled:
																		publishingReady,
																	onAction:
																		() => {
																			setPageMenuOpen(
																				false
																			)
																			publishReadyLanguages(
																				readyToPublish
																			)
																		},
																},
														  ]
														: [] ),
													...providerMenuItems,
												] }
											/>
										</div>
									</Popover>
								</InlineStack>

								<Divider />

								<BlockStack gap="200">
									<InlineStack
										align="space-between"
										blockAlign="center"
									>
										<Text as="h2" variant="headingSm">
											Target language
										</Text>
										<Button
											icon={ RefreshIcon }
											variant="tertiary"
											size="micro"
											loading={ refreshingPercents }
											accessibilityLabel="Refresh translation progress"
											onClick={ () => {
												setRefreshingPercents( true )
												refreshLanguages().finally(
													() =>
														setRefreshingPercents(
															false
														)
												)
											} }
										/>
									</InlineStack>
									{ languagesLoaded ? (
										<LanguageList
											languages={ languages }
											selectedCode={ selectedLang }
											onSelect={ ( lang ) =>
												setSelectedLang( lang.code )
											}
											onEdit={ ( lang ) =>
												openManager( lang.code )
											}
											onTranslateAll={
												ai.available &&
												board === 'content'
													? translateAllForLanguage
													: undefined
											}
											onToggleStatus={
												togglePublishStatus
											}
											onUseFallback={ ( lang ) =>
												applyFallbackTranslation( lang )
											}
											onSwitchToInternal={
												hasSubscription
													? switchLangToInternal
													: undefined
											}
											onDelete={ ( lang ) =>
												confirmDeleteLang( lang )
											}
											onAdd={ openAddLanguage }
											maxHeight={ 420 }
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
							billingBlocked={ billingBlocked }
							overSlot={ overSlot }
							recoveryAvailable={ recoveryAvailable }
							onDismissRecoveryNotice={ () =>
								setRecoveryAvailable( false )
							}
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
						ref={ cardRef }
						style={ {
							minHeight: 'calc(100vh - 100px)',
							background: 'var(--p-color-bg-surface, #fff)',
							borderRadius: 'var(--p-border-radius-300, 12px)',
							boxShadow:
								'var(--p-shadow-100, 0 1px 2px rgba(0,0,0,0.08))',
						} }
					>
						<div
							ref={ stickyRef }
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
								{ /* gap="0": the filter row below supplies its own
								paddingBlockStart, inside the height-animated
								element, so that padding collapses away with it
								too instead of leaving a stray gap when hidden. */ }
								<BlockStack gap="0">
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
										{ /* The Ignored view lists excluded text —
										nothing to bulk-translate there. */ }
										{ statusFilter !== 'ignored' && (
											<Button
												icon={ ( props ) => (
													<AiIcon
														{ ...props }
														size={ 14 }
													/>
												) }
												variant="secondary"
												disabled={
													! ai.available ||
													bulkTranslatingBoard
												}
												size="large"
												onClick={ () => {
													if ( billingBlocked ) {
														handleAiBlocked()
													} else if (
														showGettextBoard
													) {
														translateAllGettext()
													} else {
														translateAllInLanguage()
													}
												} }
											>
												Translate All
											</Button>
										) }
										<Button
											size="large"
											pressed={
												statusFilter === 'untranslated'
											}
											onClick={ () =>
												setStatusFilter(
													statusFilter ===
														'untranslated'
														? 'all'
														: 'untranslated'
												)
											}
										>
											Untranslated
										</Button>
										{ ! showGettextBoard && (
											<Popover
												active={ filterPopoverOpen }
												onClose={ closeFilterPopover }
												activator={
													<Button
														icon={ FilterIcon }
														variant="secondary"
														size="large"
														accessibilityLabel="Filter"
														onClick={ () =>
															setFilterPopoverOpen(
																( open ) =>
																	! open
															)
														}
													/>
												}
											>
												{ activeFilterKey ? (
													<Box
														padding="300"
														minWidth="240px"
													>
														{
															filters.find(
																( f ) =>
																	f.key ===
																	activeFilterKey
															)?.filter
														}
													</Box>
												) : (
													<ActionList
														items={ filters.map(
															( f ) => ( {
																content:
																	f.label,
																onAction: () =>
																	setActiveFilterKey(
																		f.key
																	),
															} )
														) }
													/>
												) }
											</Popover>
										) }
									</div>
									{ /* Plugins & Themes board: the only filter that
									carries over is "Untranslated" (same
									statusFilter state, so it stays URL-shareable,
									now moved up to line 1 next to Translate All —
									same spot and style as the Content board's).
									The filter icon button and applied-filter
									pills below are Content-board only. */ }
									{ /* Line 2: applied filter pills (the
									"Untranslated" toggle and filter icon
									button up on line 1 pick and edit them —
									see above) and "Open all collections".
									Filters the Content board's collections/texts
									query, which the Plugins & Themes board
									doesn't use. */ }
									{ ! showGettextBoard &&
										( appliedFilters.length > 0 ||
											collapseToTarget ) && (
											<div
												className="lingowp-filters"
												style={ {
													display: 'flex',
													alignItems: 'center',
													flexWrap: 'wrap',
													gap: 8,
													paddingBlockStart: 8,
												} }
											>
												{ appliedFilters.map( ( f ) => (
													<Tag
														key={ f.key }
														onRemove={ f.onRemove }
													>
														{ f.label }
													</Tag>
												) ) }
												{ appliedFilters.length > 0 && (
													<Button
														variant="monochromePlain"
														size="micro"
														onClick={
															clearAllFilters
														}
													>
														Clear filters
													</Button>
												) }
												{ collapseToTarget && (
													<Button
														onClick={
															openAllCollections
														}
													>
														Open all collections
													</Button>
												) }
											</div>
										) }
								</BlockStack>
							</Box>
							{ /* Column headers ride inside the sticky bar so they
							stay pinned above the list while scrolling, same as the
							search/filter row. Shown for the whole content board
							(incl. loading/empty) so the language switcher stays
							available and the row doesn't pop in with the list.
							GettextBoard draws its own. */ }
							{ showContentBoard && (
								<>
									<Box paddingInlineStart="1000">
										<Box
											paddingInline="300"
											paddingBlock="200"
										>
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
													>
														{ originalLabel }
													</Text>
												</Box>
												<Box width="45%" minWidth="0">
													<Popover
														active={
															langSwitcherOpen
														}
														preferredAlignment="left"
														onClose={ () =>
															setLangSwitcherOpen(
																false
															)
														}
														activator={
															<button
																type="button"
																onClick={ () =>
																	setLangSwitcherOpen(
																		( o ) =>
																			! o
																	)
																}
																style={ {
																	all: 'unset',
																	cursor: 'pointer',
																	display:
																		'flex',
																	alignItems:
																		'center',
																	gap: 4,
																	maxWidth:
																		'100%',
																} }
															>
																<span
																	style={ {
																		minWidth: 0,
																		overflow:
																			'hidden',
																		textOverflow:
																			'ellipsis',
																		whiteSpace:
																			'nowrap',
																		marginTop: 1,
																	} }
																>
																	<Text
																		as="span"
																		variant="bodySm"
																		tone="subdued"
																	>
																		{
																			translationLabel
																		}
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
															items={ sortedLanguages.map(
																( l ) => ( {
																	content:
																		langLabel(
																			l
																		),
																	prefix: (
																		<FlagIcon
																			src={ flagIconUrl(
																				l
																			) }
																			size={
																				16
																			}
																			// Lighter than the sidebar's 0.8 — full desaturation read as too
																			// heavy against this popover's surface.
																			grayscale={
																				l.enabled
																					? false
																					: 0.35
																			}
																		/>
																	),
																	active:
																		l.code ===
																		selectedLang,
																	onAction:
																		() => {
																			setSelectedLang(
																				l.code
																			)
																			setLangSwitcherOpen(
																				false
																			)
																		},
																} )
															) }
														/>
													</Popover>
												</Box>
												<Box width="16%" />
											</InlineStack>
										</Box>
									</Box>
									<Divider />
								</>
							) }
						</div>
						{ showNeedsOnboarding && (
							<Box padding="500">
								<EmptyState
									heading="Finish setting up LingoWP"
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
										onAction: openAddLanguage,
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
								<EmptyState
									heading="Scanning your site for content"
									action={ {
										content: 'Refresh',
										onAction: recheckFirstScan,
									} }
								>
									<p>
										We’re finding translatable text across
										your site. This can take a minute on a
										larger site — refresh to check if it’s
										ready.
									</p>
								</EmptyState>
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
										untranslatedOnly={
											statusFilter === 'untranslated'
										}
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
										onAiUnavailable={ handleAiFailure }
										onActionError={ showActionError }
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
						{ showGroupsEmpty &&
							( searching || hasAnyFilter ? (
								<Box padding="500">
									<Text as="p" tone="subdued">
										{ emptyGroupsMessage }
									</Text>
								</Box>
							) : (
								// Genuinely nothing found — distinct from
								// extractionPending (the scan is done, this is
								// its real result) — same centered-notice
								// treatment as "Add your first language",
								// with the one thing actually worth doing:
								// check again.
								<Box padding="500">
									<EmptyState
										heading="No content found yet"
										action={ {
											content: 'Refresh',
											onAction: () => groupsQ.refetch(),
										} }
									>
										<p>
											If you just published something,
											refreshing may pick it up.
										</p>
									</EmptyState>
								</Box>
							) ) }
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
								<div ref={ listRef }>
									<div
										aria-hidden="true"
										style={ { height: listPadTop } }
									/>
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
														bulkTranslatingBoard={
															bulkTranslatingBoard
														}
														billingBlocked={
															billingBlocked
														}
														onAiBlocked={
															handleAiBlocked
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
														onActionError={
															showActionError
														}
														onShare={
															shareCollection
														}
													/>
												) }
											</div>
										)
									} ) }
									<div
										aria-hidden="true"
										style={ { height: listPadBottom } }
									/>
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
						onSwitchToInternal={
							hasSubscription ? switchLangToInternal : undefined
						}
						onChanged={ handleLanguagesAdded }
					/>
				</Suspense>
			) }
			{ addLanguagesPickerOpen && (
				<Suspense fallback={ null }>
					<Modal
						open={ addLanguagesPickerOpen }
						onClose={ () => setAddLanguagesPickerOpen( false ) }
						title="Add languages"
					>
						<Modal.Section>
							<AddLanguagesPicker
								defaultCode={ defaultCode }
								// onCreated fires whenever anything was
								// created (partial or full success) and does
								// all the actual work; onDone only ever
								// closes the dialog, on top of that — calling
								// handleLanguagesAdded() from both would run
								// it twice on a full success (onCreated then
								// onDone, in the same submit()).
								onCreated={ handleLanguagesAdded }
								onDone={ () =>
									setAddLanguagesPickerOpen( false )
								}
							/>
						</Modal.Section>
					</Modal>
				</Suspense>
			) }
			{ overlayEverOpened && (
				<Suspense fallback={ null }>
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
							globalIndex >= 0 &&
							globalIndex < orderFlat.length - 1
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
				</Suspense>
			) }
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
	bulkTranslatingBoard = false,
	billingBlocked,
	onAiBlocked,
	onLengthWarning,
	onActionError,
	onShare,
} ) {
	const c = collection
	const open = ! collapsed
	const searching = search !== ''
	const collectionActionMut = useCollectionAction()
	const [ menuOpen, setMenuOpen ] = useState( false )
	const [ hovered, setHovered ] = useState( false )
	const [ shown, setShown ] = useState( TEXT_BATCH )
	// True while "Translate all" for this collection is in flight — forces the
	// shimmer on every still-untranslated row up front (see translateAllInCollection).
	const [ bulkTranslating, setBulkTranslating ] = useState( false )

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
		queryFn: ( { signal } ) =>
			fetchBoard(
				`/collections/${ enc( c.id ) }/texts?language=${ enc(
					language
				) }${
					search ? `&search=${ enc( search ) }` : ''
				}&status=${ enc( status ) }${ filterParams }`,
				signal
			),
		enabled: ! collapsed && settled,
		refetchInterval: pollWhilePending,
	} )
	const allTexts = textsQ.data?.texts ?? []
	const rows = allTexts.slice( 0, shown )

	// "Needs review" rows in this group. c.needs_review is the summary count,
	// which lags behind inline review toggles when an advanced filter is active
	// (patchCollectionSummary invalidates instead of patching then) — so take
	// whichever is higher, the summary or the actually-loaded rows.
	const reviewCount = Math.max(
		c.needs_review ?? 0,
		allTexts.filter( ( t ) => t.status === 'needs_review' ).length
	)

	// Rows that actually hold a translation (reviewed/machine or needs-review) —
	// what "Clear all" would remove. Hidden when there's nothing to clear. Same
	// summary-vs-loaded-rows guard as reviewCount above.
	const clearableCount = Math.max(
		( c.translated ?? 0 ) + ( c.needs_review ?? 0 ),
		allTexts.filter(
			( t ) => t.status !== 'missing' && t.status !== 'pending'
		).length
	)

	// Stale html rows: text nobody (marking mode or the background crawl)
	// has re-encountered in the grace window. Html-only — staleness has no
	// meaning for the other collection types.
	const staleQ = useQuery( {
		queryKey: [ 'html-stale', c.id, language ],
		queryFn: ( { signal } ) =>
			fetchBoard(
				`/collections/${ enc( c.id ) }/stale?language=${ enc(
					language
				) }`,
				signal
			),
		enabled: c.id === 'html:all' && ! collapsed && settled,
	} )
	const staleCount = staleQ.data?.texts?.length ?? 0

	async function bulk( action ) {
		setMenuOpen( false )
		const res = await collectionActionMut.mutateAsync( {
			id: c.id,
			action,
			body: { language },
		} )
		if ( res.ok ) {
			onMutate( res )
		} else {
			onActionError( errorMessage( res.error ) )
		}
	}

	// One click queues every still-missing text in this collection (never
	// needs-review -- that already has a translation, possibly mid-review,
	// so a bulk action must not silently overwrite it). No confirm dialog --
	// unlike Ignore all/Clear all, this never removes anything.
	// AiTranslationController::submit() caps one request at
	// AiQueueConfig::MAX_TEXTS_PER_SUBMIT and reports `more` when the
	// collection has more than that -- keep resubmitting until it doesn't,
	// so a large collection needs one click, not several.
	async function translateAllInCollection() {
		setMenuOpen( false )
		// Shimmer every still-missing row immediately, before the request — the
		// real `pending` status only lands a POST + refetch later, which reads
		// as a lag. Cleared once the submit loop finishes (rows that were
		// actually queued keep shimmering via unit.status === 'pending').
		setBulkTranslating( true )
		try {
			let res = await onAiTranslate( 'collection', {
				id: c.id,
				language,
			} )
			while ( res?.more ) {
				res = await onAiTranslate( 'collection', {
					id: c.id,
					language,
				} )
			}
		} finally {
			setBulkTranslating( false )
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
		const res = await collectionActionMut.mutateAsync( {
			id: c.id,
			action: 'delete-stale',
			body: { language },
		} )
		if ( res.ok ) {
			onMutate( res )
			staleQ.refetch()
		} else {
			onActionError( errorMessage( res.error ) )
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
	// The synthetic "Ignored" collection has no whole-collection actions (its rows
	// are restore-only), so it gets no bulk menu at all.
	const bulkItems =
		c.type === 'ignored'
			? []
			: [
					{
						content: 'Share collection',
						onAction: () => {
							setMenuOpen( false )
							onShare( c )
						},
					},
					...( c.preview_url && c.preview_target !== 'frontend'
						? [
								{
									content: 'Edit in WordPress',
									onAction: () => {
										setMenuOpen( false )
										openCanvas(
											c.preview_url,
											c.preview_target
										)
									},
								},
						  ]
						: [] ),
					...( searching
						? []
						: [
								...( aiAvailable && c.missing > 0
									? [
											{
												content: 'Translate all',
												onAction: billingBlocked
													? onAiBlocked
													: translateAllInCollection,
											},
									  ]
									: [] ),
								...( reviewCount > 1
									? [
											{
												content: 'Approve all',
												// Non-destructive and reversible (re-flag any row),
												// so no confirm dialog — unlike Clear/Ignore all.
												onAction: () =>
													bulk( 'approve' ),
											},
									  ]
									: [] ),
								...( clearableCount > 0
									? [
											{
												content: 'Clear all',
												onAction: confirmClearAll,
											},
									  ]
									: [] ),
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
				style={ {
					position: 'sticky',
					// Set imperatively by Dashboard (a CSS var, not a prop) so
					// it tracks the sticky bar's height every animation frame
					// without forcing this whole tree to re-render for it.
					top: 'var(--lingowp-group-sticky-top, 32px)',
					zIndex: 1,
					// Opaque: a pinned collapsed header's inner Box has no
					// background, so without this the rows of the group below
					// show through it as it's pushed up.
					background: 'var(--p-color-bg-surface, #fff)',
				} }
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
							aria-expanded={ open }
							aria-controls={ `col-${ c.id }` }
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
			{ /* Height-eased open/close. `initial={false}` so groups already
			open on first paint don't all animate in. The row wrapper still
			re-measures each frame of the tween, but with the scroll anchor in
			toggleCollapsed doing the pinning there's no scrollToIndex reconcile
			fighting it, so it stays smooth. */ }
			<AnimatePresence initial={ false }>
				{ open && (
					<motion.div
						key="body"
						id={ `col-${ c.id }` }
						initial={ { height: 0, opacity: 0 } }
						animate={ { height: 'auto', opacity: 1 } }
						exit={ { height: 0, opacity: 0 } }
						transition={ {
							duration: 0.26,
							ease: [ 0.4, 0, 0.2, 1 ],
						} }
						style={ { overflow: 'hidden' } }
					>
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
									<Text
										as="p"
										tone="critical"
										variant="bodySm"
									>
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
											bulkTranslating={
												bulkTranslating ||
												bulkTranslatingBoard
											}
											onMutate={ onMutate }
											onConfirm={ onConfirm }
											aiAvailable={ aiAvailable }
											onAiTranslate={ onAiTranslate }
											billingBlocked={ billingBlocked }
											onAiBlocked={ onAiBlocked }
											onOpenOverlay={ ( unit ) =>
												onOpenOverlay( unit, c.id )
											}
											onLengthWarning={ onLengthWarning }
											onActionError={ onActionError }
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
					</motion.div>
				) }
			</AnimatePresence>
			<Divider />
		</Box>
	)
}

function TextUnitRow( {
	unit,
	language,
	targetDir = 'ltr',
	collectionId,
	bulkTranslating = false,
	onMutate,
	onConfirm,
	onOpenOverlay,
	aiAvailable,
	onAiTranslate,
	billingBlocked,
	onAiBlocked,
	onLengthWarning,
	onActionError,
} ) {
	const [ draft, setDraft ] = useState( unit.translated ?? '' )
	const [ menuOpen, setMenuOpen ] = useState( false )
	const [ hovered, setHovered ] = useState( false )
	const [ translating, setTranslating ] = useState( false )
	const moreActionsRef = useRef( null )
	const saveTranslationMut = useSaveTranslation()
	const setTextStatusMut = useSetTextStatus()

	// A review toggle (needs_review <-> reviewed) flips one flag server-side, so
	// show the new badge the instant it's clicked and reconcile — or revert —
	// once the request settles, rather than freezing the row for the round-trip.
	// { from, to }: `to` is what to show now; the override drops as soon as the
	// refetched row moves off `from` (whatever it lands on is then the truth).
	const [ optimistic, setOptimistic ] = useState( null )
	const status = optimistic ? optimistic.to : unit.status
	useEffect( () => {
		if ( optimistic && unit.status !== optimistic.from ) {
			setOptimistic( null )
		}
	}, [ unit.status, optimistic ] )

	const shown = unit.translated ?? unit.fallback
	const hasTranslation =
		unit.translated !== null && unit.translated !== undefined
	// `translating` covers the optimistic window between click and the queue
	// invalidation this triggers; `unit.status === 'pending'` covers the rest
	// of the async wait, cleared only when textsQ's poll (pollWhilePending)
	// sees the real result land -- a submit response no longer carries the
	// translation itself (see AiTranslationController::submit).
	// `bulkTranslating && !hasTranslation` shows the shimmer on every missing
	// row the instant "Translate all" is clicked, before its POST + refetch
	// turns them into real `pending` rows.
	const showShimmer =
		translating ||
		unit.status === 'pending' ||
		( bulkTranslating && ! hasTranslation )
	let badge = null
	if ( status === 'needs_review' ) {
		badge = STATUS_BADGE.needs_review
	} else if ( hasTranslation ) {
		badge = STATUS_BADGE.translated
	}
	const isReview = status === 'needs_review'

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
		setDraft( unit.translated ?? '' )
	}, [ unit.translated ] )

	// Autosave the inline field on blur — only when the value actually changed.
	async function saveTranslation() {
		if ( ( unit.translated ?? '' ) === draft ) {
			return
		}
		const res = await saveTranslationMut.mutateAsync( {
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
		} else {
			onActionError( errorMessage( res.error ) )
		}
	}

	async function doStatus( action ) {
		setMenuOpen( false )
		const OPTIMISTIC_STATUS = {
			reviewed: 'reviewed',
			review: 'needs_review',
		}
		const optimisticTo = OPTIMISTIC_STATUS[ action ] ?? null
		if ( optimisticTo ) {
			setOptimistic( { from: unit.status, to: optimisticTo } )
		}
		const res = await setTextStatusMut.mutateAsync( {
			ref: unit.id,
			action,
			language,
			collection_id: collectionId,
		} )
		if ( res.ok ) {
			onMutate( res )
		} else if ( optimisticTo ) {
			// unit.status was never touched — dropping the override reverts it.
			setOptimistic( null )
			onActionError( 'Could not update the review status. Try again.' )
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
		const startedAt = Date.now()
		// The submit resolves before translation actually happens (async
		// queue) -- the real text lands via textsQ's poll (pollWhilePending),
		// which updates `unit.translated` and the draft-sync effect above
		// picks it up. showShimmer's `unit.status === 'pending'` check covers
		// the wait in between.
		await onAiTranslate( 'text', {
			ref: unit.id,
			collection_id: collectionId,
		} )
		// Keep the shimmer up for at least one sweep even when the API beat it.
		const remaining = MIN_SHIMMER_MS - ( Date.now() - startedAt )
		if ( remaining > 0 ) {
			await new Promise( ( resolve ) => setTimeout( resolve, remaining ) )
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

	// The translation cell has three shapes: an ignored row (read-only text),
	// a "long/composed" row (click/Enter opens the compare overlay), or the
	// default inline-editable input.
	function renderTranslationCell() {
		if ( unit.ignored ) {
			return (
				<div style={ { ...oneLineStyle, userSelect: 'text' } }>
					<Text as="span" variant="bodySm" tone="subdued">
						{ shownText }
					</Text>
				</div>
			)
		}
		if ( clickable ) {
			return (
				<motion.div
					role="button"
					tabIndex={ 0 }
					onClick={ () => onOpenOverlay( unit ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault()
							onOpenOverlay( unit )
						}
					} }
					animate={ { opacity: showShimmer ? 0 : 1 } }
					transition={ { duration: 0.15 } }
					style={ { ...oneLineStyle, userSelect: 'text' } }
				>
					<Text
						as="span"
						variant="bodySm"
						tone={ hasTranslation ? undefined : 'subdued' }
					>
						{ shownText }
					</Text>
				</motion.div>
			)
		}
		return (
			<motion.input
				type="text"
				aria-label="Translation"
				value={ draft }
				onChange={ ( e ) => setDraft( e.target.value ) }
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
					const next = inputs[ inputs.indexOf( e.currentTarget ) + 1 ]
					if ( next ) {
						next.focus()
					} else {
						e.currentTarget.blur()
					}
				} }
				placeholder={ unit.fallback || '' }
				animate={ { opacity: showShimmer ? 0 : 1 } }
				transition={ { duration: 0.15 } }
				style={ {
					all: 'unset',
					display: 'block',
					width: '100%',
					minWidth: 0,
					cursor: 'text',
					fontSize: 'var(--p-font-size-300, 0.75rem)',
					lineHeight: 'var(--p-font-line-height-300, 1rem)',
					color: hasTranslation
						? 'var(--p-color-text)'
						: 'var(--p-color-text-subdued)',
				} }
			/>
		)
	}

	// The row's AI-translate button, spelled once — used both as the visible
	// control and as the hidden footprint-reserving ghost below. No tooltip on
	// the normal state: the icon plus accessibilityLabel already name it, and a
	// bubble on every row hover was pure noise. Only the billing-blocked state
	// keeps a tooltip (see below), since "why is this disabled" is worth saying.
	const aiTranslateButton = (
		<Button
			variant="secondary"
			size="micro"
			accessibilityLabel="AI translate"
			icon={ ( props ) => <AiIcon { ...props } size={ 12 } /> }
			onClick={ billingBlocked ? onAiBlocked : handleAiClick }
		/>
	)

	// Ignored view (statusFilter=ignored): the row is view + Restore only —
	// no overlay, no edit, no AI, no review actions. Restore flips the source
	// back to discovered and it reappears in the normal board, untranslated.
	const menuSections = unit.ignored
		? [
				{
					items: [
						{
							content: 'Restore',
							onAction: () => {
								setMenuOpen( false )
								doStatus( 'restore' )
							},
						},
					],
				},
		  ]
		: [
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
							( isReview
								? {
										content: 'Mark as ready',
										onAction: () => doStatus( 'reviewed' ),
								  }
								: {
										content: 'Flag for review',
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
							{ renderTranslationCell() }
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
								<Badge
									tone="info"
									size="small"
									className="text-nowrap"
								>
									{ SEO_FIELD_BADGE[ unit.seo_field ] }
								</Badge>
							) }
							{ badge && (
								<span
									role={ isReview ? 'button' : undefined }
									tabIndex={ isReview ? 0 : undefined }
									className={
										isReview
											? 'lingowp-review-toggle'
											: undefined
									}
									aria-label={
										isReview
											? 'Marked for review. Activate to mark as ready.'
											: undefined
									}
									onClick={
										isReview
											? () => doStatus( 'reviewed' )
											: undefined
									}
									onKeyDown={
										isReview
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
										cursor: isReview
											? 'pointer'
											: 'default',
										userSelect: 'none',
									} }
								>
									<Badge
										tone={ badge.tone }
										size="small"
										className="text-nowrap"
										icon={
											unit.is_ai &&
											badge.label === 'Ready'
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
									{ aiAvailable && ! unit.ignored && (
										<div
											style={ {
												position: 'relative',
												flexShrink: 0,
											} }
										>
											{ /* Invisible ghost, same button as the
											real one below — reserves its exact
											footprint (Polaris's own box, not a
											guessed size) so the slot never shifts
											when the real button mounts/unmounts. */ }
											<div
												aria-hidden="true"
												style={ {
													visibility: 'hidden',
												} }
											>
												{ aiTranslateButton }
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
															{ billingBlocked ? (
																<Tooltip content="AI translation unavailable — tap for details">
																	{
																		aiTranslateButton
																	}
																</Tooltip>
															) : (
																aiTranslateButton
															) }
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
