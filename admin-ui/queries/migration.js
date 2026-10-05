import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// The Account page's Migration tab. The routes come from the migration
// add-on (lingowp-translatepress: lingowp/v1/migration/translatepress);
// window.LingoWP.migration.source names it, and is null when there's nothing
// to migrate, in which case none of these hooks is used.

const path = ( source ) => `/migration/${ source }`

// GET — { source: { installed, active }, state, finished_at, error,
// summary }. state: idle | queued | running | done | failed. Polls every 3 s
// while the background job is queued or running.
export function useMigrationStatus( source, options = {} ) {
	return useQuery( {
		queryKey: qk.migration.status( source ),
		queryFn: ( { signal } ) =>
			unwrap( api.get( path( source ), { signal } ) ),
		refetchInterval: ( query ) =>
			[ 'queued', 'running' ].includes( query.state.data?.state )
				? 3000
				: false,
		...options,
	} )
}

// GET …/preview — a dry run: { blocked, summary: { languages, totals,
// warnings } }. `blocked` says why nothing can be imported yet (e.g. LingoWP
// hasn't scanned the site). The server keeps it for 10 minutes.
export function useMigrationPreview( source, options = {} ) {
	return useQuery( {
		queryKey: qk.migration.preview( source ),
		queryFn: ( { signal } ) =>
			unwrap( api.get( `${ path( source ) }/preview`, { signal } ) ),
		...options,
	} )
}

// POST …/run — "Migrate now": queues the background job (409
// ERR_MIGRATION_RUNNING if one already is). Returns the new status.
export function useRunMigration( source ) {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: () => unwrap( api.post( `${ path( source ) }/run` ) ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.migration.all } )
		},
	} )
}

// POST …/deactivate — "Disable TranslatePress" (deactivates, never deletes).
export function useDeactivateSource( source ) {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: () =>
			unwrap( api.post( `${ path( source ) }/deactivate` ) ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: qk.migration.all } )
		},
	} )
}
