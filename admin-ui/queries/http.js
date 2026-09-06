import { api, httpErrorCode } from '../api'

// Re-export the raw client so every queryFn / mutationFn in admin-ui/queries/**
// imports it from here. The ESLint `no-restricted-imports` rule (added in the
// final step of the React Query migration) forbids importing '../api' anywhere
// outside this folder.
export { api, httpErrorCode }

// admin-ui/api.js resolves to `{ ok: false, error }` on a transport failure and
// does NOT throw on an application-level error either — every lingowp/v1
// response carries `ok`. React Query needs a thrown error to enter its `error`
// state, so wrap the call: `unwrap(api.get('/languages', { signal }))`.
//
// A missing `ok` counts as success (only `ok === false` throws), so this is
// safe on the few endpoints that stream/redirect rather than return a JSON
// envelope. The original error code is kept on `err.code` for callers that
// branch on specific codes (quota gates, ERR_INVALID_INPUT, …).
export async function unwrap( promise ) {
	const res = await promise
	if ( res && res.ok === false ) {
		const err = new Error( res.error || 'ERR_UNREACHABLE' )
		err.code = res.error || 'ERR_UNREACHABLE'
		throw err
	}
	return res
}
