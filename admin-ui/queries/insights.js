import { useMutation } from '@tanstack/react-query'
import { api, unwrap } from './http'

// POST /insights/notify — fire-and-forget: register interest in the (still
// preview) Insights feature. No cache to touch.
export function useNotifyInsights() {
	return useMutation( {
		mutationFn: () => unwrap( api.post( '/insights/notify' ) ),
	} )
}
