// Maps stable ERR_* codes from PHP to user-facing copy. This is the single place
// the SPA owns error wording (localizable later); PHP never sends sentences.
// Voice: copywrite-profile-voice.md — concise, neutral-casual, state what failed
// and what the user can do next.

const MESSAGES = {
	ERR_UNREACHABLE: "Couldn't connect to Translangs. Try again in a moment.",
	ERR_ORIGIN_NOT_CONFIGURED:
		"Couldn't connect to Translangs. Try again in a moment.",
	ERR_BACKEND: "Couldn't connect to Translangs. Try again in a moment.",
	ERR_INVALID_TOKEN: "Couldn't validate this connection. Contact support.",
	ERR_NOT_FOUND: 'Not found. Refresh and try again.',
	ERR_INVALID_PAYLOAD: "That request wasn't valid. Refresh and try again.",
	ERR_INVALID_INPUT: 'Select at least one category to continue.',
	ERR_NOT_CONNECTED: 'Not connected. Finish setup to continue.',
	ERR_INVALID_STATUS: 'Unknown status filter. Refresh and try again.',
	ERR_DOMAIN_ALREADY_LICENSED:
		'This domain already has a license. We emailed a recovery link to the site admin — open it to reconnect.',
	ERR_DOMAIN_MISMATCH:
		"This site's address doesn't match our records. Reconnect this domain to continue.",
	ERR_RATE_LIMITED: 'Too many attempts. Wait a moment and try again.',
	ERR_REBIND_COOLDOWN:
		'You can only reconnect a domain once per day. Try again later.',
	ERR_DOMAIN_NOT_ALLOWED:
		"This domain isn't eligible to connect. Contact support.",
	ERR_DOMAIN_UNREACHABLE:
		"We couldn't reach this domain to verify it. Make sure your site is publicly accessible.",
	ERR_SITE_NOT_FOUND:
		"We couldn't find a site matching this license and domain.",
	ERR_INVALID_API_KEY:
		'This key was rejected by the provider. Double-check it and try again.',
	ERR_PROVIDER_UNREACHABLE:
		"Couldn't reach the provider to validate this key. Try again in a moment.",
}

export function errorMessage( code ) {
	return MESSAGES[ code ] || 'Something went wrong. Try again.'
}

// A query cancelled because a newer one superseded it (language/search/status
// changed, or the row scrolled out of view) — never a real failure, so callers
// use this to skip showing a notice for it.
export function isAbortError( err ) {
	return err?.name === 'AbortError'
}
