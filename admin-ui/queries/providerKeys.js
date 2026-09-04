import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// BYO AI provider keys. Read by BillingToken, AiConfiguration, the language
// modal and Dashboard — one shared cache now.

// GET /provider-keys — returns the (masked) key list. Callers that want a
// { [provider]: key } map build it themselves.
export function useProviderKeys( options = {} ) {
	return useQuery( {
		queryKey: qk.providerKeys(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/provider-keys', { signal } ) ),
		select: ( res ) => res.keys ?? [],
		...options,
	} )
}

function useProviderKeyWrite( mutationFn ) {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.providerKeys() } )
			// The managed AI key's availability can flip when a BYO key is
			// added/removed, so refresh connection status too.
			queryClient.invalidateQueries( { queryKey: qk.connection.all } )
		},
	} )
}

// POST /provider-keys — pass { provider, api_key }. Response carries `key`.
export function useSaveProviderKey() {
	return useProviderKeyWrite( ( { provider, apiKey } ) =>
		unwrap( api.post( '/provider-keys', { provider, api_key: apiKey } ) )
	)
}

// DELETE /provider-keys/{provider}. ERR_NOT_FOUND is treated as success by
// callers that delete opportunistically — they can check `err.code`.
export function useDeleteProviderKey() {
	return useProviderKeyWrite( ( provider ) =>
		unwrap( api.del( `/provider-keys/${ provider }` ) )
	)
}
