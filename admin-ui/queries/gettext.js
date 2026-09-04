import { useMutation } from '@tanstack/react-query'
import { api, unwrap } from './http'

// The Plugins & Themes board (GettextBoard.jsx) + the gettext string overlay.
// Same split as queries/board.js: the inline list queries call fetchGettext(),
// and the writes are mutation hooks that hand the response back for the
// component's own cache patching.

export function fetchGettext( path, signal ) {
	return unwrap( api.get( path, { signal } ) )
}

// POST /gettext-domains/save-unit — save one translated string in place.
export function useSaveGettextUnit() {
	return useMutation( {
		mutationFn: ( body ) => api.post( '/gettext-domains/save-unit', body ),
	} )
}

// POST /gettext-domains/ignore-unit — mark one string ignored across scans.
export function useIgnoreGettextUnit() {
	return useMutation( {
		mutationFn: ( body ) =>
			api.post( '/gettext-domains/ignore-unit', body ),
	} )
}

// POST /gettext-domains/translate — queue AI translation (unit | component |
// board scope). Un-unwrapped for the same quota-gate reason as useAiTranslate.
export function useTranslateGettext() {
	return useMutation( {
		mutationFn: ( body ) => api.post( '/gettext-domains/translate', body ),
	} )
}

// POST /gettext-domains/find-online — look up an online .po/.mo (remote query).
export function useFindGettextOnline() {
	return useMutation( {
		mutationFn: ( body ) =>
			api.post( '/gettext-domains/find-online', body ),
	} )
}

// POST /gettext-domains/scan — (re)build a component's string template.
export function useScanGettext() {
	return useMutation( {
		mutationFn: ( body ) => api.post( '/gettext-domains/scan', body ),
	} )
}

// POST /gettext-domains/import — import a .po FILE. Multipart, so it can't go
// through the JSON `api` client — this is the one place a raw fetch is
// wrapped in a mutation. Pass { file, type, domain, locale }.
export function useImportGettext() {
	return useMutation( {
		mutationFn: async ( { file, type, domain, locale } ) => {
			const cfg = window.LingoWP || {}
			const body = new FormData()
			body.append( 'file', file )
			body.append( 'type', type )
			body.append( 'domain', domain )
			body.append( 'locale', locale )
			const res = await fetch( `${ cfg.rest }/gettext-domains/import`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.nonce },
				body,
			} )
			return res.json()
		},
	} )
}
