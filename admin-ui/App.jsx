import { AppProvider, Frame, Modal, PortalsManager } from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useState } from '@wordpress/element'
import { api } from './api'
import Configuration from './screens/Configuration'
import Dashboard from './screens/Dashboard'

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

export default function App() {
	const [ state, setState ] = useState( null )
	// Onboarding is never a separate screen — the Dashboard is always the
	// landing view. An unfinished onboarding only differs in that the
	// Dashboard's "Finish setting up LingoWP" notification can open this
	// modal. Every onboarding field already has a sensible default (see
	// Configuration.jsx), so the modal is deliberately not dismissible except
	// via "Continue" — no separate "reopen" affordance is needed since it can
	// never be left closed-but-incomplete.
	const [ onboardingModalOpen, setOnboardingModalOpen ] = useState( false )

	// Polaris renders popovers/tooltips/modals into a portal container that, by
	// default, lives inside the React tree — which in wp-admin sits under the
	// positioned #wpwrap, offsetting every overlay by the admin-bar height. Give
	// Polaris a portal root on <body> instead so overlays anchor to the document
	// and position exactly against their activator.
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

	useEffect( () => {
		api.get( '/onboarding/state' ).then( ( s ) => {
			setState( s )
			if ( ! s.onboarding_completed ) {
				setOnboardingModalOpen( true )
			}
		} )
	}, [] )

	// Scopes the blurred backdrop (admin-ui/tailwind.css) to just this modal,
	// instead of every Polaris Modal in the app.
	useEffect( () => {
		document.body.classList.toggle(
			'lingowp-onboarding-modal-open',
			onboardingModalOpen
		)
		return () => {
			document.body.classList.remove( 'lingowp-onboarding-modal-open' )
		}
	}, [ onboardingModalOpen ] )

	// state is only null for the brief moment before /onboarding/state
	// resolves — the Dashboard renders immediately either way (it already
	// tolerates an undefined defaultLanguage/no languages loaded yet), rather
	// than showing a separate loading screen first. Onboarding-completed
	// defaults to true while state is unknown, so the "Finish setting up"
	// prompt doesn't flash on for a moment before flashing off again.
	const onboardingCompleted = state ? state.onboarding_completed : true

	return (
		<QueryClientProvider client={ queryClient }>
			<AppProvider i18n={ enTranslations }>
				<PortalsManager container={ portalRoot }>
					<Frame>
						<Dashboard
							defaultLanguage={ state?.default_language }
							onboardingCompleted={ onboardingCompleted }
							onStartOnboarding={ () =>
								setOnboardingModalOpen( true )
							}
						/>
						<Modal
							open={ onboardingModalOpen }
							onClose={ () => {} }
							title="Finish setting up LingoWP"
						>
							<Modal.Section>
								{ state && (
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
											setOnboardingModalOpen( false )
										} }
									/>
								) }
							</Modal.Section>
						</Modal>
					</Frame>
				</PortalsManager>
			</AppProvider>
		</QueryClientProvider>
	)
}
