import { AppProvider, Frame, Modal, PortalsManager } from '@shopify/polaris'
import enTranslations from '@shopify/polaris/locales/en.json'
import { QueryClientProvider, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from '@wordpress/element'
import { makeQueryClient } from './queryClient'
import { qk } from './queryKeys'
import { useOnboardingState } from './queries/onboarding'
import Configuration from './screens/Configuration'
import Dashboard from './screens/Dashboard'
import AddLanguagesPicker from './screens/AddLanguagesPicker'
import SupportBubble from './screens/SupportBubble'
import AddonActivationToast from './screens/AddonActivationToast'

// Gap between finishing onboarding and the "add your first language" step
// appearing — the dialog itself never closes in between (see the Modal
// below), this just keeps the hand-off from feeling instant.
const ADD_LANGUAGE_DELAY_MS = 300

// Module-level: one client for the whole admin page, not per-mount.
const queryClient = makeQueryClient()

export default function App() {
	return (
		<QueryClientProvider client={ queryClient }>
			<AppInner />
		</QueryClientProvider>
	)
}

function AppInner() {
	const rqClient = useQueryClient()
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
	// createdLanguageCode rides along so Dashboard's handleLanguagesAdded()
	// can select it and, since this is always the very first language, check
	// on and report back the content-extraction scan the same way its own
	// add-language flows already do.
	const [ languageAddedSignal, setLanguageAddedSignal ] = useState( 0 )
	const [ createdLanguageCode, setCreatedLanguageCode ] = useState( null )

	const stateQ = useOnboardingState()
	const state = stateQ.data ?? null

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

	// One-shot: pick the initial onboarding step from the bootstrap read. The
	// ref guard means a later background refetch of /onboarding/state never
	// re-opens the dialog.
	//   - not completed -> 'onboarding'
	//   - completed but no language at all -> straight to 'add-language', no
	//     delay (that's only for the transition right after finishing
	//     onboarding). has_any_language, not has_target_language: a language
	//     created here starts as a draft, so the active-only flag would keep
	//     reopening this every reload until it's separately published.
	const seededStepRef = useRef( false )
	useEffect( () => {
		if ( seededStepRef.current || ! state ) {
			return
		}
		seededStepRef.current = true
		if ( ! state.onboarding_completed ) {
			setStep( 'onboarding' )
		} else if ( ! state.has_any_language ) {
			setStep( 'add-language' )
		}
	}, [ state ] )

	// Scopes the blurred backdrop (admin-ui/tailwind.css) to just this modal,
	// instead of every Polaris Modal in the app.
	useEffect( () => {
		document.body.classList.toggle(
			'lingowp-onboarding-modal-open',
			!! step
		)
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
		<AppProvider i18n={ enTranslations }>
			<PortalsManager container={ portalRoot }>
				<Frame>
					<Dashboard
						defaultLanguage={ state?.default_language }
						onboardingCompleted={ onboardingCompleted }
						onStartOnboarding={ () => setStep( 'onboarding' ) }
						languageAddedSignal={ languageAddedSignal }
						createdLanguageCode={ createdLanguageCode }
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
								? 'Add languages'
								: 'Finish setting up LingoWP'
						}
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
										// Optimistically flip the cached state so
										// Dashboard's "Finish setting up" prompt
										// doesn't flash before the invalidated
										// /onboarding/state refetch lands.
										rqClient.setQueryData(
											qk.onboarding.state(),
											( old ) =>
												old
													? {
															...old,
															onboarding_completed: true,
													  }
													: old
										)
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
								<AddLanguagesPicker
									defaultCode={
										state?.default_language?.code
									}
									// onCreated fires whenever anything was
									// created (partial or full success) and
									// carries the actual "refetch and check
									// extraction" signal; onDone (full
									// success only) just closes the dialog.
									onCreated={ ( code ) => {
										setCreatedLanguageCode( code )
										setLanguageAddedSignal( ( n ) => n + 1 )
									} }
									onDone={ () => setStep( null ) }
								/>
							</Modal.Section>
						) }
					</Modal>
					<SupportBubble />
					<AddonActivationToast />
				</Frame>
			</PortalsManager>
		</AppProvider>
	)
}
