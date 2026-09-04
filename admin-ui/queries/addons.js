import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// The Integrations page's add-on marketplace. Served entirely by /billing/*.

// GET /billing/addons — { addons, coming_soon, categories }.
export function useAddons( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.addons(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/addons', { signal } ) ),
		select: ( res ) => ( {
			addons: res.addons ?? [],
			comingSoon: res.coming_soon ?? [],
			categories: res.categories ?? [],
		} ),
		...options,
	} )
}

// POST /billing/addons/{addonId}/install — pass { addonId, product }. Used for
// both "Install now" (owned) and "Add now" (free, not yet owned).
export function useInstallAddon() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( { addonId, product } ) =>
			unwrap(
				api.post( `/billing/addons/${ addonId }/install`, { product } )
			),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: qk.billing.addons() } ),
	} )
}

// POST /billing/addons/order — pass { addonId }. Returns { approve_url } to
// redirect to; the page navigates away and the return handler polls.
export function useBuyAddon() {
	return useMutation( {
		mutationFn: ( { addonId } ) =>
			unwrap(
				api.post( '/billing/addons/order', { addon_id: addonId } )
			),
	} )
}

// POST /billing/addons/orders/{orderId}/capture — finalize after the PayPal
// return.
export function useCaptureAddonOrder() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( orderId ) =>
			unwrap( api.post( `/billing/addons/orders/${ orderId }/capture` ) ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: qk.billing.addons() } ),
	} )
}

// POST /billing/addons/coming-soon/{slug}/notify — register interest.
export function useNotifyComingSoon() {
	return useMutation( {
		mutationFn: ( slug ) =>
			unwrap(
				api.post( `/billing/addons/coming-soon/${ slug }/notify` )
			),
	} )
}
