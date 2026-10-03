import { useMutation } from '@tanstack/react-query'
import { api, unwrap } from './http'

// POST /support/message — the floating support bubble. Fire-and-forget, like
// useNotifyInsights(): nothing is stored locally, so there is no cache to touch.
export function useSendSupportMessage() {
	return useMutation( {
		mutationFn: ( { email, message } ) =>
			unwrap( api.post( '/support/message', { email, message } ) ),
	} )
}
