import {
	AppProvider,
	Badge,
	Banner,
	BlockStack,
	Box,
	Button,
	Card,
	Divider,
	Frame,
	InlineStack,
	Page,
	PortalsManager,
	Spinner,
	Text,
} from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from '@wordpress/element'
import { parseAsString, parseAsStringLiteral, useQueryState } from 'nuqs'
import { makeQueryClient } from './queryClient'
import {
	useAddons,
	useBuyAddon,
	useCaptureAddonOrder,
	useInstallAddon,
	useNotifyComingSoon,
} from './queries/addons'
import ConnectionErrorCard from './screens/ConnectionErrorCard'
import { errorMessage } from './errors'

const cfg = window.LingoWP || {}

const queryClient = makeQueryClient()
const EMPTY_LIST = []

const TABS = [
	{ id: 'overview', heading: 'Overview' },
	{ id: 'marketplace', heading: 'Marketplace' },
]

const topicParser = parseAsStringLiteral(
	TABS.map( ( t ) => t.id )
).withDefault( 'overview' )

// The API only puts a slug on each addon (`addon.categories`); this resolves
// it against the top-level {slug,label} list for display. If the list hasn't
// loaded yet or doesn't carry a match, humanize the slug ("dynamic_content"
// -> "Dynamic Content") rather than showing it raw.
function categoryLabel( categories, slug ) {
	const match = categories.find( ( c ) => c.slug === slug )
	if ( match ) {
		return match.label
	}
	return slug
		.split( '_' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' )
}

// `image` from the API is a bare filename ("lingowp-elementor.png"); cfg
// prepends the API's public icon base (/api/v1/addons/icons/), so icon changes
// ship from the backend, not a plugin release. The <img>'s onError below falls
// back to the LingoWP logo when an icon 404s or the backend is unreachable.
// To change an icon, replace the PNG in the backend's addons/icons/ and deploy
// it — we no longer ship copies under assets/.
function addonImageUrl( addon ) {
	if ( ! addon.image ) {
		return cfg.logo
	}
	return ( cfg.addons_asset_url || '' ) + addon.image
}

// 30% smaller than the original 80px — applies to every card image, real
// logo or LingoWP fallback alike.
const ADDON_IMAGE_SIZE = Math.round( 80 * 0.7 )

function ActionButton( { label, loading, error, onClick } ) {
	return (
		<BlockStack gap="100">
			<Button
				loading={ loading }
				disabled={ loading }
				onClick={ onClick }
			>
				{ label }
			</Button>
			{ error && (
				<Text as="span" tone="critical" variant="bodySm">
					{ errorMessage( error ) }
				</Text>
			) }
		</BlockStack>
	)
}

// Same ladder as InsightsPreview.jsx's Hero — session-only, no persistence
// check on mount, routed through the existing support-suggestion pipeline
// rather than a dedicated notify endpoint (see notifyComingSoon() on the
// PHP side).
function NotifyButton( { slug } ) {
	const [ notified, setNotified ] = useState( false )
	const notify = useNotifyComingSoon()
	const notifying = notify.isPending
	const notifyError = notify.error ? errorMessage( notify.error.code ) : ''

	async function onNotify() {
		try {
			await notify.mutateAsync( slug )
			setNotified( true )
		} catch {
			// surfaced via notify.error
		}
	}

	let label = 'Notify me'
	if ( notifying ) {
		label = 'Sending…'
	} else if ( notified ) {
		label = "You're on the list"
	}

	return (
		<BlockStack gap="100">
			<Button
				loading={ notifying }
				disabled={ notifying || notified }
				onClick={ onNotify }
			>
				{ label }
			</Button>
			{ notifyError && (
				<Text as="span" tone="critical" variant="bodySm">
					{ notifyError }
				</Text>
			) }
		</BlockStack>
	)
}

// Derives the one action a card needs from its own data plus the local
// installed_addons signal — not per-section, so the same addon gets the same
// button wherever it renders (Recommended, Available integrations, or
// Your integrations).
//   - coming-soon (no `owned`/`price` on the entry at all) -> Notify me
//   - owned, not installed on this site -> Install now (ownership is
//     per-license — it can come from a different site under the same license)
//   - owned, already installed here -> nothing
//   - not owned, free -> Add now (same install call as Install now)
//   - not owned, priced -> Buy now
function AddonAction( { addon, actions } ) {
	const isComingSoon = typeof addon.owned !== 'boolean'
	if ( isComingSoon ) {
		return <NotifyButton slug={ addon.slug } />
	}

	const installedHere = !! actions.installedAddons[ addon.product ]

	if ( addon.owned ) {
		if ( installedHere ) {
			return null
		}
		return (
			<ActionButton
				label="Install now"
				loading={ actions.installingId === addon.addon_id }
				error={ actions.installErrors[ addon.addon_id ] }
				onClick={ () => actions.onInstall( addon ) }
			/>
		)
	}

	if ( addon.price === '0.00' ) {
		return (
			<ActionButton
				label="Add now"
				loading={ actions.installingId === addon.addon_id }
				error={ actions.installErrors[ addon.addon_id ] }
				onClick={ () => actions.onInstall( addon ) }
			/>
		)
	}

	return (
		<ActionButton
			label="Buy now"
			loading={ actions.buyingId === addon.addon_id }
			error={ actions.buyErrors[ addon.addon_id ] }
			onClick={ () => actions.onBuy( addon ) }
		/>
	)
}

function AddonCard( {
	addon,
	categories = [],
	showCategoryChip = true,
	actions,
} ) {
	const badgeLabel =
		showCategoryChip && addon.categories?.[ 0 ]
			? categoryLabel( categories, addon.categories[ 0 ] )
			: null

	return (
		<Card>
			<div className="flex flex-col items-start text-start gap-3 h-full">
				<img
					src={ addonImageUrl( addon ) }
					alt=""
					width={ ADDON_IMAGE_SIZE }
					height={ ADDON_IMAGE_SIZE }
					className="rounded object-contain"
					onError={ ( e ) => {
						// Falls back once to the LingoWP logo on a missing
						// PNG; the handler is cleared first so a broken logo
						// URL itself can't loop.
						e.currentTarget.onerror = null
						e.currentTarget.src = cfg.logo
					} }
				/>
				<BlockStack gap="150">
					<InlineStack gap="150" blockAlign="start" align="start">
						<Text as="h3" variant="headingMd">
							{ addon.title }
						</Text>
						{ badgeLabel && (
							<Badge tone="info">{ badgeLabel }</Badge>
						) }
					</InlineStack>
					<Text as="p" tone="subdued">
						{ addon.description }
					</Text>
				</BlockStack>
				<AddonAction addon={ addon } actions={ actions } />
			</div>
		</Card>
	)
}

function AddonList( { addons, categories, showCategoryChip, actions } ) {
	return (
		<div className="grid grid-cols-3 2xl:grid-cols-4 gap-2">
			{ addons.map( ( addon ) => (
				<AddonCard
					key={ addon.addon_id || addon.slug }
					addon={ addon }
					categories={ categories }
					showCategoryChip={ showCategoryChip }
					actions={ actions }
				/>
			) ) }
		</div>
	)
}

function AddonSection( { title, addons, emptyText, categories, actions } ) {
	return (
		<BlockStack gap="300">
			<Text as="h2" variant="headingMd">
				{ title }
			</Text>
			{ addons.length === 0 ? (
				emptyText && (
					<Text as="p" tone="subdued">
						{ emptyText }
					</Text>
				)
			) : (
				<AddonList
					addons={ addons }
					categories={ categories }
					actions={ actions }
				/>
			) }
		</BlockStack>
	)
}

// One card can list several categories (e.g. a page builder that's also
// "dynamic content") — it appears once per category section it belongs to.
// `categories` is the API's own display-ordered {slug,label} list, already
// filtered to non-empty overall; each section here only renders if this
// particular `addons` slice actually has a match.
function CategorizedAddons( {
	categories,
	addons,
	showCategoryChip = false,
	actions,
} ) {
	const uncategorized = addons.filter( ( a ) => ! a.categories?.length )

	return (
		<BlockStack gap="400">
			{ categories.map( ( cat ) => {
				const inCategory = addons.filter( ( a ) =>
					a.categories?.includes( cat.slug )
				)
				if ( inCategory.length === 0 ) {
					return null
				}
				return (
					<BlockStack gap="200" key={ cat.slug }>
						<Text as="h3" variant="headingSm" fontWeight="medium">
							{ cat.label }
						</Text>
						<AddonList
							addons={ inCategory }
							categories={ categories }
							showCategoryChip={ showCategoryChip }
							actions={ actions }
						/>
					</BlockStack>
				)
			} ) }
			{ uncategorized.length > 0 && (
				<BlockStack gap="200">
					<Text as="h3" variant="headingSm" fontWeight="medium">
						Other
					</Text>
					<AddonList
						addons={ uncategorized }
						categories={ categories }
						showCategoryChip={ showCategoryChip }
						actions={ actions }
					/>
				</BlockStack>
			) }
		</BlockStack>
	)
}

const PANEL_HEADERS = {
	overview: {
		title: 'Integrations',
		description:
			'Connect LingoWP with the other plugins running on this site.',
	},
	marketplace: {
		title: 'Marketplace',
		description: 'Browse and install LingoWP add-ons.',
	},
}

// Shown regardless of load/error state — the page still says what it's for
// even while the catalog fetch is loading or failed.
function PanelHeader( { topicId } ) {
	const header = PANEL_HEADERS[ topicId ] || PANEL_HEADERS.overview
	return (
		<Card>
			<BlockStack gap="200">
				<Text as="h1" variant="headingLg">
					{ header.title }
				</Text>
				<Text as="p" tone="subdued">
					{ header.description }
				</Text>
				<div className="h-0.5"></div>
			</BlockStack>
		</Card>
	)
}

function OverviewPanel( { addons, actions } ) {
	// `owned` is the backend's own entitlement flag (GET /billing/addons) —
	// true for every free add-on in v1, and for anything actually purchased
	// later. Not a local "is this plugin installed/active here" check —
	// that's the separate `actions.installedAddons` signal, which only
	// decides whether an owned card still needs an "Install now" button.
	const yours = addons.filter( ( a ) => a.owned )
	const recommended = addons.filter( ( a ) => ! a.owned )

	return (
		<BlockStack gap="400">
			<AddonSection
				title="Your integrations"
				addons={ yours }
				emptyText="None of the LingoWP add-ons are installed on this site yet."
				actions={ actions }
			/>
			{ recommended.length > 0 && (
				<>
					<Divider />
					<AddonSection
						title="Recommended for your site"
						addons={ recommended }
						actions={ actions }
					/>
				</>
			) }
		</BlockStack>
	)
}

function MarketplacePanel( { addons, comingSoon, categories, actions } ) {
	return (
		<BlockStack gap="400">
			<BlockStack gap="300">
				<Text as="h2" variant="headingMd">
					Available integrations
				</Text>
				{ addons.length === 0 ? (
					<Text as="p" tone="subdued">
						No add-ons are available right now.
					</Text>
				) : (
					<AddonList
						addons={ addons }
						categories={ categories }
						showCategoryChip
						actions={ actions }
					/>
				) }
			</BlockStack>
			<Divider />
			<BlockStack gap="300">
				<Text as="h2" variant="headingMd">
					Coming soon
				</Text>
				<CategorizedAddons
					categories={ categories }
					addons={ comingSoon }
					actions={ actions }
				/>
			</BlockStack>
		</BlockStack>
	)
}

export default function IntegrationsApp() {
	return (
		<QueryClientProvider client={ queryClient }>
			<IntegrationsAppInner />
		</QueryClientProvider>
	)
}

function IntegrationsAppInner() {
	const [ topicId, setTopicId ] = useQueryState( 'topic', topicParser )

	// Server-computed once at page load (is_plugin_active per product) — kept
	// in state rather than a plain const so a successful install can update
	// it locally without needing a full page reload.
	const [ installedAddons, setInstalledAddons ] = useState(
		cfg.installed_addons || {}
	)

	const [ installErrors, setInstallErrors ] = useState( {} ) // addon_id -> ERR_* code
	const [ buyErrors, setBuyErrors ] = useState( {} )

	const [ polling, setPolling ] = useState( null ) // { addonId, attempts, gaveUp } | null
	const [ noticeBanner, setNoticeBanner ] = useState( '' )

	const [ status, setStatus ] = useQueryState(
		'status',
		parseAsStringLiteral( [ 'success', 'cancel' ] )
	)
	const [ addonIdParam, setAddonIdParam ] = useQueryState(
		'addon_id',
		parseAsString
	)
	const [ tokenParam, setTokenParam ] = useQueryState(
		'token',
		parseAsString
	)

	const addonsQ = useAddons()
	const installMut = useInstallAddon()
	const buyMut = useBuyAddon()
	const captureMut = useCaptureAddonOrder()

	const addons = addonsQ.data?.addons ?? EMPTY_LIST
	const comingSoon = addonsQ.data?.comingSoon ?? EMPTY_LIST
	const categories = addonsQ.data?.categories ?? EMPTY_LIST
	const loading = addonsQ.isPending
	const error = addonsQ.isError
		? addonsQ.error?.code || 'ERR_UNREACHABLE'
		: ''
	const installingId = installMut.isPending
		? installMut.variables.addonId
		: null
	const buyingId = buyMut.isPending ? buyMut.variables.addonId : null

	const refreshAddons = addonsQ.refetch

	// Handles both "Add now" (not-owned free) and "Install now" (owned,
	// not installed here) — same backend route either way.
	async function installAddon( addon ) {
		setInstallErrors( ( prev ) => ( { ...prev, [ addon.addon_id ]: '' } ) )
		try {
			await installMut.mutateAsync( {
				addonId: addon.addon_id,
				product: addon.product,
			} )
			setInstalledAddons( ( prev ) => ( {
				...prev,
				[ addon.product ]: true,
			} ) )
		} catch ( e ) {
			setInstallErrors( ( prev ) => ( {
				...prev,
				[ addon.addon_id ]: e.code || 'ERR_UNREACHABLE',
			} ) )
		}
	}

	async function buyAddon( addon ) {
		setBuyErrors( ( prev ) => ( { ...prev, [ addon.addon_id ]: '' } ) )
		try {
			const res = await buyMut.mutateAsync( { addonId: addon.addon_id } )
			if ( res.approve_url ) {
				window.location.assign( res.approve_url )
				return
			}
			throw new Error( 'ERR_UNREACHABLE' )
		} catch ( e ) {
			setBuyErrors( ( prev ) => ( {
				...prev,
				[ addon.addon_id ]: e.code || e.message || 'ERR_UNREACHABLE',
			} ) )
		}
	}

	const actions = {
		installedAddons,
		installingId,
		installErrors,
		onInstall: installAddon,
		buyingId,
		buyErrors,
		onBuy: buyAddon,
	}

	// PayPal always returns with ?status=&addon_id=&token= (confirmed with the
	// backend) — same one-shot deep-link pattern as the billing screens'
	// redirect handling.
	useEffect( () => {
		if ( ! status ) {
			return
		}

		const clearParams = () => {
			setStatus( null )
			setAddonIdParam( null )
			setTokenParam( null )
		}

		if ( status === 'cancel' ) {
			setNoticeBanner( 'Checkout cancelled.' )
			clearParams()
			return
		}

		const orderId = tokenParam
		const addonId = addonIdParam
		clearParams()

		const afterCapture = orderId
			? captureMut.mutateAsync( orderId ).catch( () => {} )
			: Promise.resolve()

		afterCapture.then( () => {
			if ( addonId ) {
				setPolling( { addonId, attempts: 0, gaveUp: false } )
			}
		} )
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status ] )

	// Poll-until-settled for the buy flow — payment is confirmed async via
	// webhook, so `owned` only flips once that's landed.
	useEffect( () => {
		if ( ! polling || polling.gaveUp ) {
			return
		}

		const timer = setTimeout( async () => {
			const res = await refreshAddons()
			const target = ( res.data?.addons || [] ).find(
				( a ) => a.addon_id === polling.addonId
			)
			if ( target?.owned ) {
				setPolling( null )
				return
			}

			setPolling( ( p ) =>
				p && p.attempts + 1 >= 10
					? { ...p, attempts: p.attempts + 1, gaveUp: true }
					: { ...p, attempts: p.attempts + 1 }
			)
		}, 3000 )

		return () => clearTimeout( timer )
	}, [ polling, refreshAddons ] )

	// Same Frame-owned portal root as the other mounts, so Polaris
	// popovers/modals anchor to the document instead of the wp-admin offset.
	const [ portalRoot ] = useState( () => {
		const existing = document.getElementById( 'lingowp-portals' )
		if ( existing ) {
			return existing
		}
		const el = document.createElement( 'div' )
		el.id = 'lingowp-portals'
		document.body.appendChild( el )
		return el
	} )

	return (
		<AppProvider i18n={ enTranslations }>
			<PortalsManager container={ portalRoot }>
				<Frame>
					<Page fullWidth>
						<BlockStack gap="400">
							<InlineStack
								gap="200"
								align="start"
								blockAlign="start"
								wrap={ false }
							>
								{ /* ── Left rail — same shell/width as Insights/workspace ──── */ }
								<div
									style={ {
										flex: '0 0 260px',
										width: 260,
										alignSelf: 'flex-start',
										position: 'sticky',
										top: 32,
										marginBlockStart: 8,
									} }
								>
									<Card>
										<BlockStack gap="400">
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
												<Text
													as="span"
													variant="headingMd"
												>
													LingoWP
												</Text>
											</InlineStack>

											<Divider />

											<BlockStack gap="050">
												{ TABS.map( ( tab ) => (
													<Button
														key={ tab.id }
														fullWidth
														textAlign="left"
														variant={
															topicId === tab.id
																? 'primary'
																: 'tertiary'
														}
														onClick={ () =>
															setTopicId( tab.id )
														}
													>
														{ tab.heading }
													</Button>
												) ) }
											</BlockStack>
										</BlockStack>
									</Card>
								</div>

								{ /* ── Main — active tab's panel ───────────────────────────── */ }
								<div
									style={ {
										flex: 1,
										minWidth: 0,
										marginBlockStart: 8,
									} }
								>
									<BlockStack gap="400">
										<PanelHeader topicId={ topicId } />
										{ noticeBanner && (
											<Banner
												tone="info"
												onDismiss={ () =>
													setNoticeBanner( '' )
												}
											>
												{ noticeBanner }
											</Banner>
										) }
										{ polling && ! polling.gaveUp && (
											<Banner tone="info">
												Payment received — updating your
												account…
											</Banner>
										) }
										{ polling?.gaveUp && (
											<Banner tone="warning">
												Still processing — this can take
												a minute. Refresh shortly to see
												the update.
											</Banner>
										) }
										{ loading && (
											<Box padding="500">
												<Spinner
													accessibilityLabel="Loading integrations"
													size="small"
												/>
											</Box>
										) }
										{ ! loading && error && (
											<ConnectionErrorCard
												error={ error }
												onRetry={ () =>
													addonsQ.refetch()
												}
											/>
										) }
										{ ! loading &&
											! error &&
											( topicId === 'marketplace' ? (
												<MarketplacePanel
													addons={ addons }
													comingSoon={ comingSoon }
													categories={ categories }
													actions={ actions }
												/>
											) : (
												<OverviewPanel
													addons={ addons }
													actions={ actions }
												/>
											) ) }
									</BlockStack>
								</div>
							</InlineStack>
						</BlockStack>
					</Page>
				</Frame>
			</PortalsManager>
		</AppProvider>
	)
}
