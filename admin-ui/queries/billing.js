import {
	useInfiniteQuery,
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// Every /billing/* read + write goes through here. The four billing screens
// used to fetch /billing/entitlements independently (7 fetchers, 4 on a single
// page load) — now they share one cached query.

// --- reads -------------------------------------------------------------------

// GET /billing/entitlements — plan limits + feature flags. `fresh` bypasses the
// 60s server cache (the poll-until-settled loop in BillingSubscription needs
// that). `select` unwraps to the entitlements object so callers read it directly.
export function useEntitlements( { fresh = false, ...options } = {} ) {
	return useQuery( {
		queryKey: qk.billing.entitlements(),
		queryFn: ( { signal } ) =>
			unwrap(
				api.get( `/billing/entitlements${ fresh ? '?fresh=1' : '' }`, {
					signal,
				} )
			),
		select: ( res ) => res.entitlements ?? null,
		...options,
	} )
}

// GET /billing/usage — the whole response IS the usage payload
// ({ words_translated, daily: [...], ... }).
export function useUsage( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.usage(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/usage', { signal } ) ),
		...options,
	} )
}

// GET /billing/plans
export function usePlans( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.plans(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/plans', { signal } ) ),
		select: ( res ) => res.plans ?? [],
		staleTime: 30 * 60_000,
		...options,
	} )
}

// GET /billing/packs
export function usePacks( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.packs(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/packs', { signal } ) ),
		select: ( res ) => res.packs ?? [],
		staleTime: 30 * 60_000,
		...options,
	} )
}

// GET /billing/transactions — paginated receipts, "Load more" = fetchNextPage.
export function useTransactions( options = {} ) {
	return useInfiniteQuery( {
		queryKey: qk.billing.transactions(),
		queryFn: ( { pageParam, signal } ) =>
			unwrap(
				api.get(
					`/billing/transactions?page=${ pageParam }&page_size=20`,
					{ signal }
				)
			),
		initialPageParam: 1,
		getNextPageParam: ( last ) =>
			last.has_more ? last.page + 1 : undefined,
		...options,
	} )
}

// GET /billing/owner — { owner_email, verified }
export function useBillingOwner( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.owner(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/owner', { signal } ) ),
		...options,
	} )
}

// GET /billing/websites
export function useBillingWebsites( options = {} ) {
	return useQuery( {
		queryKey: qk.billing.websites(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/billing/websites', { signal } ) ),
		select: ( res ) => res.websites ?? [],
		...options,
	} )
}

// --- writes ----------------------------------------------------------------

// POST /billing/owner — send the confirm link. Nothing changes server-side
// until the emailed link is clicked, so this only refreshes the owner card.
export function useAssignOwner() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( email ) =>
			unwrap( api.post( '/billing/owner', { email } ) ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: qk.billing.owner() } ),
	} )
}

// POST /billing/websites — emails an attach link; the slot is held meanwhile.
export function useAddWebsite() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( { domain, adminEmail } ) =>
			unwrap(
				api.post( '/billing/websites', {
					domain,
					admin_email: adminEmail,
				} )
			),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: qk.billing.websites(),
			} )
			queryClient.invalidateQueries( { queryKey: qk.billing.owner() } )
		},
	} )
}

// DELETE /billing/websites/{siteId}
export function useRemoveWebsite() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( siteId ) =>
			unwrap( api.del( `/billing/websites/${ siteId }` ) ),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: qk.billing.websites(),
			} )
			queryClient.invalidateQueries( { queryKey: qk.billing.owner() } )
		},
	} )
}

// POST /billing/subscription — returns { approve_url } to redirect to. No cache
// work: the page navigates away, and the return handler polls entitlements.
export function useStartSubscription() {
	return useMutation( {
		mutationFn: ( planKey ) =>
			unwrap(
				api.post( '/billing/subscription', { plan_key: planKey } )
			),
	} )
}

// POST /billing/subscription/plan — may return { approve_url } (redirect) or
// null (PayPal skipped re-consent; caller polls instead).
export function useChangePlan() {
	return useMutation( {
		mutationFn: ( planKey ) =>
			unwrap(
				api.post( '/billing/subscription/plan', {
					plan_key: planKey,
				} )
			),
	} )
}

// POST /billing/packs/order — returns { approve_url }. Caller stashes the
// pre-purchase word count in sessionStorage (PENDING_WORDS_KEY) then redirects.
export function useBuyWordPack() {
	return useMutation( {
		mutationFn: ( packId ) =>
			unwrap( api.post( '/billing/packs/order', { pack_id: packId } ) ),
	} )
}

// POST /billing/packs/order/{orderId}/capture — finalize after the PayPal
// return. Quota + receipts move, so refresh all three.
export function useCapturePackOrder() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( orderId ) =>
			unwrap( api.post( `/billing/packs/order/${ orderId }/capture` ) ),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: qk.billing.entitlements(),
			} )
			queryClient.invalidateQueries( { queryKey: qk.billing.usage() } )
			queryClient.invalidateQueries( {
				queryKey: qk.billing.transactions(),
			} )
		},
	} )
}
