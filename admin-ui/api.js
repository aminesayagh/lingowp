// Tiny REST client for the translangs/v1 API. The REST root and nonce are handed
// in by wp_localize_script (OnboardingPageController) as window.Translangs. The
// api_token is never exposed here — it stays server-side.

const cfg = window.Translangs || { rest: '', nonce: '' };

async function request( method, path, body ) {
	try {
		const res = await fetch( cfg.rest + path, {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );
		return ( await res.json() ) || {};
	} catch ( e ) {
		// Network/parse failure below the REST layer surfaces as a transport error.
		return { ok: false, error: 'ERR_UNREACHABLE' };
	}
}

export const api = {
	get: ( path ) => request( 'GET', path ),
	post: ( path, body ) => request( 'POST', path, body ),
	patch: ( path, body ) => request( 'PATCH', path, body ),
	del: ( path, body ) => request( 'DELETE', path, body ),
};
