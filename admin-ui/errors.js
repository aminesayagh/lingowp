// Maps stable ERR_* codes from PHP to user-facing copy. This is the single place
// the SPA owns error wording (localizable later); PHP never sends sentences.
// Copy sourced from design-ux-onboarding-copy.md.

const MESSAGES = {
	ERR_UNREACHABLE: 'We could not connect Translangs right now. Please try again in a moment.',
	ERR_ORIGIN_NOT_CONFIGURED: 'We could not connect Translangs right now. Please try again in a moment.',
	ERR_BACKEND: 'We could not connect Translangs right now. Please try again in a moment.',
	ERR_INVALID_TOKEN: 'Translangs could not validate this connection. Please contact support.',
	ERR_NOT_FOUND: 'Translangs could not validate this connection. Please contact support.',
	ERR_INVALID_PAYLOAD: 'Translangs could not validate this connection. Please contact support.',
	ERR_INVALID_INPUT: 'Select at least one category to continue.',
	ERR_NOT_CONNECTED: 'Translangs is not connected. Please finish setup first.',
	ERR_INVALID_STATUS: 'Unknown status filter. Please refresh and try again.',
};

export function errorMessage( code ) {
	return MESSAGES[ code ] || 'Something went wrong. Please try again.';
}

// A query cancelled because a newer one superseded it (language/search/status
// changed, or the row scrolled out of view) — never a real failure, so callers
// use this to skip showing a notice for it.
export function isAbortError( err ) {
	return err?.name === 'AbortError';
}
