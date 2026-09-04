import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// GET /scan/status — the content-extraction scan's progress. Self-polls every
// 3s while `status === 'running'`, then stops. The response has no `ok`
// envelope contract (callers read `res.status || 'running'`), so no unwrap.
// Pass `enabled: <a scan is expected to be running>` so it's not polled on
// every dashboard mount.
export function useScanStatus( options = {} ) {
	return useQuery( {
		queryKey: qk.scan.status(),
		queryFn: ( { signal } ) => api.get( '/scan/status', { signal } ),
		refetchInterval: ( query ) =>
			( query.state.data?.status ?? 'running' ) === 'running'
				? 3000
				: false,
		...options,
	} )
}

// POST /scan/refresh — re-arm the activation-time scan (+ an HTML re-crawl the
// caller doesn't wait on). Response carries `html_status`. The endpoint always
// returns ok:true server-side, so ok:false only ever means a transport failure
// — unwrap() so that surfaces as onError, not a false-positive onSuccess.
export function useRefreshScan() {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn: () => unwrap( api.post( '/scan/refresh' ) ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: qk.scan.status() } ),
	} )
}
