import { QueryClient } from '@tanstack/react-query'

// One QueryClient factory, shared by all four SPA roots (App / Billing /
// Insights / Integrations). Each root is a separate wp-admin page = a separate
// createRoot mount, so each gets its own instance — there is no cache to share
// across a full page reload. This only exists so the config lives in one place
// instead of being copy-pasted into every root.
export function makeQueryClient() {
	return new QueryClient( {
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
}
