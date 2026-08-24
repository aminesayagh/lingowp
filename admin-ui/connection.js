// Shared by every SPA root that makes a bearer-authenticated backend call.
// This helper may validate an existing connection, but it must never create a
// first connection: sending the site domain/admin email requires an explicit
// administrator action through /connection/connect.
//
// /connection/check never mutates anything by itself (it only self-heals a
// stale 401/410 key server-side). /connection/connect is only fired here when
// the site has genuinely never connected at all — the backend dedupes a
// repeat pending ask for the same site instead of re-emailing, so this is
// safe to call once per page load even while a confirm email is still
// unclaimed (see plan-architecture-flow.md: "a pending token of the same
// kind for the same site is never duplicated").
import { api } from './api'

// /connection/check and /connection/connect name their error field
// differently (ai_error vs error) — normalize once here so every caller
// reads one consistent shape instead of re-deriving this.
function normalize( res ) {
	return {
		connected: !! res.connected,
		pending: !! res.pending,
		available: !! res.ai_available,
		error: res.ai_error || res.error || '',
	}
}

export async function ensureConnected() {
	const status = await api.post( '/connection/check' )

	return normalize( status )
}
