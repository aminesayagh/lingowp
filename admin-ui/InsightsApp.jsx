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
import { useState } from '@wordpress/element'
import { AnimatePresence, motion, MotionConfig } from 'motion/react'
import { parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from './api'
import { errorMessage } from './errors'
import { GhostKpiRow, Hero, TopicSection } from './screens/InsightsPreview'
import { KPI_ROW, TOPICS } from './screens/insightsContent.mjs'

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

const TABS = [ { id: 'overview', heading: 'Overview' }, ...TOPICS ]

const topicParser = parseAsStringLiteral(
	TABS.map( ( t ) => t.id )
).withDefault( 'overview' )

export default function InsightsApp() {
	const [ topicId, setTopicId ] = useQueryState( 'topic', topicParser )
	const activeTopic = TOPICS.find( ( t ) => t.id === topicId )

	// Session-only: no local persistence, no GET on mount. A reload always
	// shows the button in its default state — this only reports whether this
	// one click reached the backend.
	const [ notified, setNotified ] = useState( false )
	const [ notifying, setNotifying ] = useState( false )
	const [ notifyError, setNotifyError ] = useState( '' )

	async function onNotify() {
		setNotifying( true )
		setNotifyError( '' )
		const res = await api.post( '/insights/notify' )
		setNotifying( false )
		if ( res.ok ) {
			setNotified( true )
		} else {
			setNotifyError( errorMessage( res.error ) )
		}
	}

	// Same Frame-owned portal root as the other mounts, so Polaris
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
							<BlockStack gap="400">
								<InlineStack
									gap="200"
									align="start"
									blockAlign="start"
									wrap={ false }
								>
									{ /* ── Left rail — same shell/width as the workspace/billing ──── */ }
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
													{ TABS.map( ( tab ) => (
														<Button
															key={ tab.id }
															fullWidth
															textAlign="left"
															variant={
																topicId ===
																tab.id
																	? 'primary'
																	: 'tertiary'
															}
															onClick={ () =>
																setTopicId(
																	tab.id
																)
															}
														>
															{ tab.heading }
														</Button>
													) ) }
												</BlockStack>
											</BlockStack>
										</Card>
									</div>

									{ /* ── Main — active topic's panels ──────────────────────── */ }
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
													key={ topicId }
													initial={ {
														opacity: 0,
														y: 8,
													} }
													animate={ {
														opacity: 1,
														y: 0,
													} }
													exit={ {
														opacity: 0,
														y: -8,
													} }
													transition={ {
														duration: 0.18,
														ease: 'easeOut',
													} }
												>
													{ activeTopic ? (
														<TopicSection
															topic={
																activeTopic
															}
														/>
													) : (
														<BlockStack gap="400">
															<Hero
																notified={
																	notified
																}
																notifying={
																	notifying
																}
																notifyError={
																	notifyError
																}
																onNotify={
																	onNotify
																}
															/>
															<GhostKpiRow
																items={
																	KPI_ROW
																}
															/>
														</BlockStack>
													) }
												</motion.div>
											</AnimatePresence>
										</MotionConfig>
									</div>
								</InlineStack>
							</BlockStack>
						</Page>
					</Frame>
				</PortalsManager>
			</AppProvider>
		</QueryClientProvider>
	)
}
