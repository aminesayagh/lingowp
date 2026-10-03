import { useQuery } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// The Account page's Integrations tab. Served entirely by /billing/*.

// GET /billing/addons — { tier, addons, categories, recently_activated }.
// Each addon carries the backend's `tier`/`included`/`detects` plus two facts
// only this site knows (computed in BillingRestController::addons()):
// `relevant` (a `detects` entry is active here) and `installed` (the
// lingowp-<product> plugin is active here). `recently_activated` is read-once
// server-side — it only feeds the activation toast.
export function useAddons( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.addons(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/addons', { signal } ) ),
		select: ( res ) => ( {
			tier: res.tier ?? null,
			addons: res.addons ?? [],
			categories: res.categories ?? [],
			recentlyActivated: res.recently_activated ?? [],
		} ),
		...options,
	} )
}
