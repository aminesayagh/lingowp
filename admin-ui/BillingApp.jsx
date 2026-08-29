import {
	AppProvider,
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
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from '@wordpress/element'
import { AnimatePresence, motion, MotionConfig } from 'motion/react'
import { parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from './api'
import { ensureConnected } from './connection'
import BillingSubscription from './screens/BillingSubscription'
import BillingUsage from './screens/BillingUsage'
import BillingSites from './screens/BillingSites'
import BillingToken from './screens/BillingToken'
import OwnerEmailCard from './screens/OwnerEmailCard'

const cfg = window.LingoWP || {}

const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			staleTime: 5 * 60_000,
			gcTime: 30 * 60_000,
			retry: 1,
			refetchOnWindowFocus: false,
		},
		mutations: { retry: 0 },
	},
} )

const tabParser = parseAsStringLiteral( [
	'subscription',
	'usage',
	'sites',
	'token',
] ).withDefault( 'subscription' )

const TABS = [
	{ id: 'subscription', label: 'Plan & billing' },
	{ id: 'usage', label: 'Usage' },
	{ id: 'sites', label: 'Sites' },
	{ id: 'token', label: 'API token' },
]

export default function BillingApp() {
	const [ tab, setTab ] = useQueryState( 'tab', tabParser )
	const [ subscribed, setSubscribed ] = useState( true ) // optimistic until entitlements load, so the Usage tab doesn't flash disabled
	const [ connectionReady, setConnectionReady ] = useState( false )
	const [ connectionStatus, setConnectionStatus ] = useState( null )
	const [ connecting, setConnecting ] = useState( false )
	const [ connectError, setConnectError ] = useState( '' )
	// A fresh connect also found a matching older licence and emailed its
	// verified owner a reclaim link — non-blocking, this site already works.
	const [ recoveryAvailable, setRecoveryAvailable ] = useState( false )

	useEffect( () => {
		let cancelled = false
		ensureConnected().then( ( status ) => {
			if ( cancelled ) {
				return
			}
			setConnectionStatus( status )
			if ( ! status.connected ) {
				setConnectionReady( true )
				return
			}
			api.get( '/billing/entitlements' ).then( ( res ) => {
				if ( cancelled ) {
					return
				}
				if ( res.ok ) {
					setSubscribed( !! res.entitlements?.active )
				}
				setConnectionReady( true )
			} )
		} )
		return () => {
			cancelled = true
		}
	}, [] )

	async function connectCloud() {
		setConnecting( true )
		setConnectError( '' )
		const res = await api.post( '/connection/connect' )
		setConnecting( false )
		if ( ! res.ok ) {
			setConnectError( res.error || 'ERR_UNREACHABLE' )
			return
		}
		const next = {
			connected: !! res.connected,
			available: !! res.ai_available,
			error: res.ai_error || '',
		}
		setConnectionStatus( next )
		setRecoveryAvailable( !! res.recovery_available )
		if ( next.connected ) {
			const entitlements = await api.get( '/billing/entitlements' )
			if ( entitlements.ok ) {
				setSubscribed( !! entitlements.entitlements?.active )
			}
		}
	}

	// A direct ?tab=usage link or a subscription that lapsed after loading the
	// tab both need this, not just the disabled Button below.
	useEffect( () => {
		if ( ! subscribed && tab === 'usage' ) {
			setTab( 'subscription' )
		}
	}, [ subscribed, tab, setTab ] )

	// Same Frame-owned portal root as the main workspace, so Polaris
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
		<QueryClientProvider client={ queryClient }>
			<AppProvider i18n={ enTranslations }>
				<PortalsManager container={ portalRoot }>
					<Frame>
						<Page fullWidth>
							<InlineStack
								gap="200"
								align="start"
								blockAlign="start"
								wrap={ false }
							>
								{ /* ── Left rail — same shell/width as the workspace ──── */ }
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
												{ TABS.map( ( t ) => (
													<Button
														key={ t.id }
														fullWidth
														textAlign="left"
														disabled={
															t.id === 'usage' &&
															! subscribed
														}
														variant={
															tab === t.id
																? 'primary'
																: 'tertiary'
														}
														onClick={ () =>
															setTab( t.id )
														}
													>
														{ t.label }
													</Button>
												) ) }
											</BlockStack>
										</BlockStack>
									</Card>
								</div>

								{ /* ── Main — active tab body ──────────────────────── */ }
								<div
									style={ {
										flex: 1,
										minWidth: 0,
										marginBlockStart: 8,
										display: 'flex',
										flexDirection: 'column',
										gap: 16,
									} }
								>
									{ recoveryAvailable && (
										<Banner
											tone="info"
											onDismiss={ () =>
												setRecoveryAvailable( false )
											}
										>
											We found an existing LingoWP
											account for this site — check your
											owner email to move its subscription
											and word balance here.
										</Banner>
									) }
									{ connectionReady &&
										connectionStatus?.connected && (
											<OwnerEmailCard />
										) }
									<MotionConfig reducedMotion="user">
										<AnimatePresence
											initial={ false }
											mode="wait"
										>
											<motion.div
												key={ tab }
												initial={ { opacity: 0, y: 8 } }
												animate={ { opacity: 1, y: 0 } }
												exit={ { opacity: 0, y: -8 } }
												transition={ {
													duration: 0.18,
													ease: 'easeOut',
												} }
											>
												{ ! connectionReady && (
													<Box padding="500">
														<Spinner
															accessibilityLabel="Connecting to LingoWP"
															size="small"
														/>
													</Box>
												) }
												{ connectionReady &&
													! connectionStatus?.connected && (
														<Card>
															<BlockStack gap="300">
																<Text
																	as="h2"
																	variant="headingMd"
																>
																	Connect to
																	LingoWP
																	Cloud
																</Text>
																<Text
																	as="p"
																	tone="subdued"
																>
																	Manual
																	translation
																	works
																	without a
																	cloud
																	connection.
																	Connecting
																	sends this
																	site&apos;s
																	domain and
																	WordPress
																	admin email
																	to
																	LingoWP
																	Cloud;
																	translation
																	text and
																	provider
																	keys are
																	sent only
																	when you
																	request
																	those cloud
																	features.
																</Text>
																{ connectError && (
																	<Text
																		as="p"
																		tone="critical"
																	>
																		{
																			connectError
																		}
																	</Text>
																) }
																<InlineStack gap="200">
																	<Button
																		variant="primary"
																		loading={
																			connecting
																		}
																		onClick={
																			connectCloud
																		}
																	>
																		Connect
																		to
																		LingoWP
																		Cloud
																	</Button>
																	{ cfg.cloud_terms_url && (
																		<Button
																			variant="plain"
																			url={
																				cfg.cloud_terms_url
																			}
																			external
																		>
																			Terms
																		</Button>
																	) }
																	{ cfg.cloud_privacy_url && (
																		<Button
																			variant="plain"
																			url={
																				cfg.cloud_privacy_url
																			}
																			external
																		>
																			Privacy
																			Policy
																		</Button>
																	) }
																</InlineStack>
															</BlockStack>
														</Card>
													) }
												{ connectionReady &&
													connectionStatus?.connected &&
													tab === 'subscription' && (
														<BillingSubscription />
													) }
												{ connectionReady &&
													connectionStatus?.connected &&
													tab === 'usage' && (
														<BillingUsage />
													) }
												{ connectionReady &&
													connectionStatus?.connected &&
													tab === 'sites' && (
														<BillingSites />
													) }
												{ connectionReady &&
													connectionStatus?.connected &&
													tab === 'token' && (
														<BillingToken />
													) }
											</motion.div>
										</AnimatePresence>
									</MotionConfig>
								</div>
							</InlineStack>
						</Page>
					</Frame>
				</PortalsManager>
			</AppProvider>
		</QueryClientProvider>
	)
}
