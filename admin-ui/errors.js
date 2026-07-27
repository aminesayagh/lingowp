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
};

export function errorMessage( code ) {
	return MESSAGES[ code ] || 'Something went wrong. Please try again.';
}
