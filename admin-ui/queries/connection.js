import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// Connection status + lifecycle mutations.
//
// These endpoints do NOT follow the usual `ok` contract:
//   - GET  /connection/status     -> always ok:true (cached read)
//   - POST /connection/check      -> ok == ai_available (false is a normal
//                                    "not subscribed" state, not a failure)
//   - POST /connection/confirm    -> ok == ai_available on success too
//   - POST /connection/disconnect -> always ok:false (available:false)
//   - POST /connection/connect    -> ok is a real success flag
//   - POST /connection/recover    -> ok is a real success flag
//
// So the mutations here never throw on `ok:false` — they return the normalized
// shape (with `ok` kept) and the caller branches on it, exactly like the old
// imperative code did. Only the GET-status query uses `unwrap`, and only to
// surface a transport failure (ERR_UNREACHABLE) as `isError`.

function normalize( res ) {
	return {
		ok: res.ok !== false,
		connected: !! res.connected,
		available: !! res.ai_available,
		maskedKey: res.masked_key || '',
		error: res.ai_error || res.error || '',
		recoveryAvailable: !! res.recovery_available,
	}
}

// Connection status under one key, so every mutation below refreshes it with a
// single invalidate.
//   - default:        GET /connection/status (cached, never mutates)
//   - { verify: true }: POST /connection/check (re-verifies + self-heals a
//     stale 401/402/410 key). Same normalized shape.
export function useConnectionStatus( { verify = false, ...options } = {} ) {
	return useQuery( {
		queryKey: qk.connection.status(),
		queryFn: ( { signal } ) =>
			verify
				? api
						.post( '/connection/check', undefined, { signal } )
						.then( normalize )
				: unwrap( api.get( '/connection/status', { signal } ) ).then(
						normalize
				  ),
		...options,
	} )
}

function useConnectionMutation( path ) {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: ( body ) => api.post( path, body ).then( normalize ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.connection.all } )
			queryClient.invalidateQueries( {
				queryKey: qk.billing.entitlements(),
			} )
			queryClient.invalidateQueries( { queryKey: qk.providerKeys() } )
		},
	} )
}

// POST /connection/connect — first connect / reconnect. Result carries
// `recoveryAvailable` when an older licence was found for this domain.
export function useConnect() {
	return useConnectionMutation( '/connection/connect' )
}

// POST /connection/confirm — finish a connect/reclaim/owner handshake from a
// ?confirm_token deep link. Pass { token }.
export function useConfirmConnection() {
	return useConnectionMutation( '/connection/confirm' )
}

// POST /connection/recover — rotate to a fresh site key (old one dies at once).
// `result.ok` is a real success flag here.
export function useRecoverKey() {
	return useConnectionMutation( '/connection/recover' )
}

// POST /connection/disconnect — drop the cloud connection. Always resolves
// (`ok` is always false); callers just await it.
export function useDisconnect() {
	return useConnectionMutation( '/connection/disconnect' )
}
