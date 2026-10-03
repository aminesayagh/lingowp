import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// GET /onboarding/state — the SPA bootstrap read: onboarding_completed,
// has_any_language, default_language, settings, default_language_slug, … Shared
// by App (the onboarding gate) and GeneralConfiguration (the settings page).
export function useOnboardingState( options = {} ) {
	return useQuery( {
		queryKey: qk.onboarding.state(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/onboarding/state', { signal } ) ),
		...options,
	} )
}

// POST /onboarding/configure — apply the first-run config (prefix, cookie,
// browser detection, auto redirect).
export function useConfigureOnboarding() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( body ) =>
			unwrap( api.post( '/onboarding/configure', body ) ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.onboarding.state() } )
			queryClient.invalidateQueries( { queryKey: qk.languages.all } )
		},
	} )
}

// POST /settings — save WP-owned behaviour toggles; may also change the Site
// Language (the caller reloads the page in that case).
export function useSaveSettings() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( body ) => unwrap( api.post( '/settings', body ) ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.onboarding.state() } )
			queryClient.invalidateQueries( { queryKey: qk.languages.all } )
		},
	} )
}
