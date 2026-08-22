import {
	AppProvider,
	BlockStack,
	Button,
	Card,
	Divider,
	Frame,
	InlineStack,
	Page,
	PortalsManager,
	Text,
} from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from '@wordpress/element'
import { AnimatePresence, motion, MotionConfig } from 'motion/react'
import { parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from './api'
import BillingSubscription from './screens/BillingSubscription'
import BillingUsage from './screens/BillingUsage'
import BillingToken from './screens/BillingToken'

const cfg = window.Translangs || {}

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
	'token',
] ).withDefault( 'subscription' )

const TABS = [
	{ id: 'subscription', label: 'Plan & billing' },
	{ id: 'usage', label: 'Usage' },
	{ id: 'token', label: 'API token' },
]

export default function BillingApp() {
	const [ tab, setTab ] = useQueryState( 'tab', tabParser )
	const [ subscribed, setSubscribed ] = useState( true ) // optimistic until entitlements load, so the Usage tab doesn't flash disabled

	useEffect( () => {
		let cancelled = false
		api.get( '/billing/entitlements' ).then( ( res ) => {
			if ( ! cancelled && res.ok ) {
				setSubscribed( !! res.entitlements?.active )
			}
		} )
		return () => {
			cancelled = true
		}
	}, [] )

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
		const existing = document.getElementById( 'translangs-portals' )
		if ( existing ) {
			return existing
		}
		const el = document.createElement( 'div' )
		el.id = 'translangs-portals'
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
													Translangs
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
									} }
								>
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
												{ tab === 'subscription' && (
													<BillingSubscription />
												) }
												{ tab === 'usage' && (
													<BillingUsage />
												) }
												{ tab === 'token' && (
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
