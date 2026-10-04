import {
	keepPreviousData,
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// Language switcher design settings (Account page, "Language switcher" tab).
// Responses are flat: every design key, show_arrow, dropdown_animation, size,
// dropdown_shadow, button_width, custom_css_enabled, custom_css, can_edit_css.

// GET /switcher-settings — the saved settings.
export function useSwitcherSettings( options = {} ) {
	return useQuery( {
		queryKey: qk.switcher.settings(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/switcher-settings', { signal } ) ),
		...options,
	} )
}

// POST /switcher-settings — save the draft. unwrap() keeps only the error
// code, but the form needs to know WHICH fields were rejected, so the error
// carries `fields` too (ERR_INVALID_INPUT / ERR_FORBIDDEN).
export function useSaveSwitcherSettings() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: async ( body ) => {
			const res = await api.post( '/switcher-settings', body )
			if ( res?.ok === false ) {
				const err = new Error( res.error || 'ERR_UNREACHABLE' )
				err.code = res.error || 'ERR_UNREACHABLE'
				err.fields = Array.isArray( res.fields ) ? res.fields : []
				throw err
			}
			return res
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.switcher.all } )
		},
	} )
}

// POST /switcher-settings/preview — the real switcher rendered with the
// UNSAVED draft. A read, so a query keyed by the draft (callers debounce it);
// the previous render stays on screen while the next one loads.
export function useSwitcherPreview( draft, layout, options = {} ) {
	return useQuery( {
		queryKey: qk.switcher.preview( { draft, layout } ),
		queryFn: ( { signal } ) =>
			unwrap(
				api.post(
					'/switcher-settings/preview',
					{ ...draft, layout },
					{ signal }
				)
			),
		placeholderData: keepPreviousData,
		staleTime: 60_000,
		...options,
	} )
}
