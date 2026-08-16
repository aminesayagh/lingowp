import {
	AppProvider,
	BlockStack,
	Card,
	Frame,
	PortalsManager,
	Spinner,
} from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from '@wordpress/element'
import { AnimatePresence, motion, MotionConfig } from 'motion/react'
import { parseAsString, parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from './api'
import Configuration from './screens/Configuration'
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
	'dashboard',
] ).withOptions( { history: 'push' } )

// Decide the landing screen. Incomplete onboarding can never reach the dashboard;
// completed onboarding can never revisit configuration.
function resolveView( state ) {
	return state.onboarding_completed ? 'dashboard' : 'onboarding_configuration'
}

export default function App() {
	const [ state, setState ] = useState( null )
	const [ , setUrlView ] = useQueryState( 'view', viewParser )
	const view = state ? resolveView( state ) : null

	// Deep link from a /license/recover email's "Refresh license in WordPress"
	// button: ?page=translangs&license_key=... . Applied silently in the
	// background — clicking a link that arrived in the site's own inbox is
	// already the user's confirmation, nothing here should block on another
	// click. The dashboard's own on-mount /connection/check (unaffected by
	// this) will reflect the applied key next time the admin loads/refreshes
	// the page; no reload is forced from here.
	const [ recoveredLicenseKey, setRecoveredLicenseKey ] = useQueryState(
		'license_key',
		parseAsString
	)

	useEffect( () => {
		if ( ! recoveredLicenseKey ) {
			return
		}
		api.post( '/connection/apply-license', {
			license_key: recoveredLicenseKey,
		} )
		setRecoveredLicenseKey( null )
	}, [ recoveredLicenseKey, setRecoveredLicenseKey ] )

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

	const content =
		! state || ! view ? (
			<OnboardingPage>
				<Card>
					<BlockStack inlineAlign="center">
						<Spinner accessibilityLabel="Loading Translangs" />
					</BlockStack>
				</Card>
			</OnboardingPage>
		) : (
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
								onDone={ () => {
									setState( ( s ) => ( {
										...s,
										onboarding_completed: true,
									} ) )
									setView( 'dashboard' )
								} }
							/>
						) }
						{ view === 'dashboard' && (
							<Dashboard
								defaultLanguage={ state.default_language }
							/>
						) }
					</motion.div>
				</AnimatePresence>
			</MotionConfig>
		)

	return (
		<QueryClientProvider client={ queryClient }>
			<AppProvider i18n={ enTranslations }>
				<PortalsManager container={ portalRoot }>
					<Frame>{ content }</Frame>
				</PortalsManager>
			</AppProvider>
		</QueryClientProvider>
	)
}
