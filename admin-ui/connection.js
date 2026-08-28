// Shared by every SPA root that makes a bearer-authenticated backend call.
// This helper may validate an existing connection, but it must never create a
// first connection: sending the site domain/admin email requires an explicit
// administrator action through /connection/connect.
//
// /connection/check never mutates anything by itself (it only self-heals a
// dead 401/402/410 key server-side).
import { api } from './api'

// /connection/check and /connection/connect name their error field
// differently (ai_error vs error) — normalize once here so every caller
// reads one consistent shape instead of re-deriving this.
function normalize( res ) {
	return {
		connected: !! res.connected,
		available: !! res.ai_available,
		error: res.ai_error || res.error || '',
	}
}

export async function ensureConnected() {
	const status = await api.post( '/connection/check' )

	return normalize( status )
}
