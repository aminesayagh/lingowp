// Tiny REST client for the translangs/v1 API. The REST root and nonce are handed
// in by wp_localize_script (OnboardingPageController) as window.Translangs. The
// api_token is never exposed here — it stays server-side.

const cfg = window.Translangs || { rest: '', nonce: '' }

async function request( method, path, body, { signal } = {} ) {
	try {
		const res = await fetch( cfg.rest + path, {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
			signal,
		} )
		return ( await res.json() ) || {}
	} catch ( e ) {
		// A superseded request (React Query cancelling a stale page/group fetch)
		// must reject, not resolve — resolving would cache `undefined` as if it
		// were a successful response.
		if ( e?.name === 'AbortError' ) {
			throw e
		}
		// Network/parse failure below the REST layer surfaces as a transport error.
		return { ok: false, error: 'ERR_UNREACHABLE' }
	}
}

export const api = {
	get: ( path, opts ) => request( 'GET', path, undefined, opts ),
	post: ( path, body, opts ) => request( 'POST', path, body, opts ),
	patch: ( path, body, opts ) => request( 'PATCH', path, body, opts ),
	del: ( path, body, opts ) => request( 'DELETE', path, body, opts ),
}
