import { useMutation } from '@tanstack/react-query'
import { api, unwrap } from './http'

// The content board (Dashboard.jsx). Its list reads stay as inline useQuery /
// useInfiniteQuery at the call site — they depend on a bundle of local filter
// state, and their cache is kept fresh by hand-written setQueriesData patches
// keyed on queryKey[0] — so what this module provides is:
//   - fetchBoard(): the shared GET helper the inline queryFns call, so `api`
//     itself is imported only here.
//   - the write mutations, which return the server response for the caller's
//     own cache reconciliation (afterMutate) rather than invalidating here.

// GET helper for every board list query. Throws on `ok:false` so React Query
// enters its error state (replaces the inline `if (!res.ok) throw`).
export function fetchBoard( path, signal ) {
	return unwrap( api.get( path, { signal } ) )
}

// POST /translations — save one manual translation. Returns { ok, ...patch }.
export function useSaveTranslation() {
	return useMutation( {
		mutationFn: ( body ) => api.post( '/translations', body ),
	} )
}

// POST /texts/{ref}/status — change one text's review status. Pass
// { ref, action }.
export function useSetTextStatus() {
	return useMutation( {
		mutationFn: ( { ref, ...body } ) =>
			api.post( `/texts/${ encodeURIComponent( ref ) }/status`, body ),
	} )
}

// POST /collections/{id}/{ignore|clear|approve|delete-stale}. Pass
// { id, action, body? }.
export function useCollectionAction() {
	return useMutation( {
		mutationFn: ( { id, action, body } ) =>
			api.post(
				`/collections/${ encodeURIComponent( id ) }/${ action }`,
				body
			),
	} )
}

// POST /translate — queue an AI batch (text | collection | language scope).
// Left un-unwrapped: quota/plan gates come back as `ok:false` with an
// actionable code the caller routes to handleAiFailure(), not a thrown error.
export function useAiTranslate() {
	return useMutation( {
		mutationFn: ( body ) => api.post( '/translate', body ),
	} )
}
