import {
	AppProvider,
	BlockStack,
	Card,
	PortalsManager,
	Spinner,
} from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from '@wordpress/element'
import { AnimatePresence, motion, MotionConfig } from 'motion/react'
import { parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from './api'
import Configuration from './screens/Configuration'
import SettingUp from './screens/SettingUp'
import Dashboard from './screens/Dashboard'
import OnboardingPage from './screens/OnboardingPage'

// Module-level: one client for the whole admin page, not per-mount.
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

const viewParser = parseAsStringLiteral( [
	'onboarding_configuration',
	'onboarding_setting_up',
	'dashboard_empty_language',
	'dashboard_workspace',
] ).withOptions( { history: 'push' } )

// Decide the landing screen. Incomplete onboarding can never reach the dashboard;
// completed onboarding can never revisit configuration.
function resolveView( state, urlView ) {
	if ( ! state.onboarding_completed ) {
		if ( urlView === 'onboarding_setting_up' && state.connected ) {
			return 'onboarding_setting_up'
		}
		return 'onboarding_configuration'
	}
	if (
		urlView === 'dashboard_empty_language' ||
		urlView === 'dashboard_workspace'
	) {
		return urlView
	}
	return state.has_target_language
		? 'dashboard_workspace'
		: 'dashboard_empty_language'
}

export default function App() {
	const [ state, setState ] = useState( null )
	const [ urlView, setUrlView ] = useQueryState( 'view', viewParser )
	const view = state ? resolveView( state, urlView ) : null

	// Polaris renders popovers/tooltips/modals into a portal container that, by
	// default, lives inside the React tree — which in wp-admin sits under the
	// positioned #wpwrap, offsetting every overlay by the admin-bar height. Give
	// Polaris a portal root on <body> instead so overlays anchor to the document
	// and position exactly against their activator.
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

	const setView = useCallback(
		( next ) => {
			setUrlView( next )
		},
		[ setUrlView ]
	)

	useEffect( () => {
		api.get( '/onboarding/state' ).then( ( s ) => {
			setState( s )
		} )
	}, [] )

	if ( ! state || ! view ) {
		return (
			<QueryClientProvider client={ queryClient }>
				<AppProvider i18n={ enTranslations }>
					<OnboardingPage>
						<Card>
							<BlockStack inlineAlign="center">
								<Spinner accessibilityLabel="Loading Translangs" />
							</BlockStack>
						</Card>
					</OnboardingPage>
				</AppProvider>
			</QueryClientProvider>
		)
	}

	return (
		<QueryClientProvider client={ queryClient }>
			<AppProvider i18n={ enTranslations }>
				<PortalsManager container={ portalRoot }>
					<MotionConfig reducedMotion="user">
						<AnimatePresence initial={ false } mode="wait">
							<motion.div
								key={ view }
								initial={ { opacity: 0, y: 8 } }
								animate={ { opacity: 1, y: 0 } }
								exit={ { opacity: 0, y: -8 } }
								transition={ {
									duration: 0.18,
									ease: 'easeOut',
								} }
								style={ { width: '100%' } }
							>
								{ view === 'onboarding_configuration' && (
									<Configuration
										recommendedPrefixDefaultLanguage={
											state.recommended_prefix_default_language
										}
										defaultLanguageSlug={
											state.default_language_slug
										}
										onDone={ () =>
											setView( 'onboarding_setting_up' )
										}
									/>
								) }
								{ view === 'onboarding_setting_up' && (
									<SettingUp
										onDone={ ( next ) => setView( next ) }
									/>
								) }
								{ ( view === 'dashboard_empty_language' ||
									view === 'dashboard_workspace' ) && (
									<Dashboard
										defaultLanguage={
											state.default_language
										}
										onLanguageCreated={ () =>
											setView( 'dashboard_workspace' )
										}
									/>
								) }
							</motion.div>
						</AnimatePresence>
					</MotionConfig>
				</PortalsManager>
			</AppProvider>
		</QueryClientProvider>
	)
}
