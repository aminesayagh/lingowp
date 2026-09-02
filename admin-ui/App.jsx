import { AppProvider, Frame, Modal, PortalsManager } from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useEffect, useRef, useState } from '@wordpress/element'
import { api } from './api'
import Configuration from './screens/Configuration'
import Dashboard from './screens/Dashboard'
import { LanguagesManagerBody } from './screens/LanguageManagerModal'

// Gap between finishing onboarding and the "add your first language" step
// appearing — the dialog itself never closes in between (see the Modal
// below), this just keeps the hand-off from feeling instant.
const ADD_LANGUAGE_DELAY_MS = 800

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
	// landing view. This one Modal instance carries the whole "finish setup"
	// hand-off as two steps, never closing between them:
	//   - 'onboarding': Configuration.jsx. Every field already has a sensible
	//     default, so this step is deliberately not dismissible except via
	//     "Continue" — no separate "reopen" affordance is needed since it can
	//     never be left closed-but-incomplete.
	//   - 'add-language': the same "add your first language" content
	//     LanguageManagerModal shows elsewhere. Dismissible — a fresh site
	//     with zero languages is a valid (if incomplete) state to leave the
	//     dialog in, unlike onboarding's own toggles.
	// null closes the dialog entirely.
	const [ step, setStep ] = useState( null )
	const addLanguageTimerRef = useRef( null )
	useEffect( () => () => clearTimeout( addLanguageTimerRef.current ), [] )
	// Bumped once a language is actually created via the 'add-language' step,
	// so Dashboard knows to refetch and jump to it (see languageAddedSignal
	// there) — it owns the languages list, this modal doesn't fetch one.
	const [ languageAddedSignal, setLanguageAddedSignal ] = useState( 0 )

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
				setStep( 'onboarding' )
			} else if ( ! s.has_target_language ) {
				// Onboarding was already done on a previous visit but no
				// language exists yet (e.g. the user dismissed the
				// 'add-language' step last time, or refreshed mid-way) — go
				// straight to it, no delay (that's only for the transition
				// right after finishing onboarding, not a page load).
				setStep( 'add-language' )
			}
		} )
	}, [] )

	// Scopes the blurred backdrop (admin-ui/tailwind.css) to just this modal,
	// instead of every Polaris Modal in the app.
	useEffect( () => {
		document.body.classList.toggle( 'lingowp-onboarding-modal-open', !! step )
		return () => {
			document.body.classList.remove( 'lingowp-onboarding-modal-open' )
		}
	}, [ step ] )

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
							onStartOnboarding={ () => setStep( 'onboarding' ) }
							languageAddedSignal={ languageAddedSignal }
						/>
						<Modal
							open={ !! step }
							onClose={
								// Only the 'add-language' step is dismissible.
								step === 'add-language'
									? () => setStep( null )
									: () => {}
							}
							title={
								step === 'add-language'
									? 'Languages'
									: 'Finish setting up LingoWP'
							}
							large={ step === 'add-language' }
						>
							{ step === 'onboarding' && state && (
								<Modal.Section>
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
											clearTimeout(
												addLanguageTimerRef.current
											)
											addLanguageTimerRef.current =
												setTimeout( () => {
													setStep( 'add-language' )
												}, ADD_LANGUAGE_DELAY_MS )
										} }
									/>
								</Modal.Section>
							) }
							{ step === 'add-language' && (
								<Modal.Section>
									<LanguagesManagerBody
										open
										languages={ [] }
										catalog={ null }
										defaultCode={
											state?.default_language?.code
										}
										initialCode="new"
										onClose={ () => setStep( null ) }
										onConfirm={ () => {} }
										onChanged={ () => {
											setStep( null )
											setLanguageAddedSignal(
												( n ) => n + 1
											)
										} }
									/>
								</Modal.Section>
							) }
						</Modal>
					</Frame>
				</PortalsManager>
			</AppProvider>
		</QueryClientProvider>
	)
}
