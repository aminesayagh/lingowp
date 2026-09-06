// Tiny REST client for the lingowp/v1 API. The REST root and nonce are handed
// in by wp_localize_script (OnboardingPageController) as window.LingoWP. The
// api_token is never exposed here — it stays server-side.

const cfg = window.LingoWP || { rest: '', nonce: '' }

// WordPress-level failures mapped onto the SPA's own ERR_* vocabulary. A bad
// nonce and a genuine capability denial are both 403 but mean different things
// to the user, so branch on the code WordPress sends, not the status alone.
function httpErrorCode( status, code ) {
	if ( code === 'rest_no_route' ) {
		return 'ERR_ROUTE_MISSING'
	}
	if ( code === 'rest_cookie_invalid_nonce' ) {
		return 'ERR_SESSION_EXPIRED'
	}
	if ( status === 401 || status === 403 ) {
		return 'ERR_FORBIDDEN'
	}
	return `ERR_HTTP_${ status }`
}

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
		// An aborted body read must still reject as an abort (see below).
		const data = await res.json().catch( ( e ) => {
			if ( e?.name === 'AbortError' ) {
				throw e
			}
			return {}
		} )

		// WordPress's own failures — a missing route, an expired nonce, a PHP
		// fatal — carry no `ok` field, and some aren't even JSON. Without this
		// they flow on as if they were a successful payload: unwrap() sees no
		// `ok:false`, React Query caches the error body as data, and the screen
		// breaks somewhere far from the cause. Convert them to the same
		// { ok:false, error } contract every caller already understands.
		if ( ! res.ok && data.ok === undefined ) {
			return { ok: false, error: httpErrorCode( res.status, data.code ) }
		}

		return data || {}
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
