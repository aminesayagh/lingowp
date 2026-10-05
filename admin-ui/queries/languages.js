import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '../queryKeys'
import { api, unwrap } from './http'

// Read + write hooks for the language resource. Hooks return the raw lingowp/v1
// response envelope — callers destructure the field they need (no `select`, to
// stay uniform with the board queries).

// GET /languages — active target languages WITH per-language DB translation
// progress (the expensive read). Response: { ok, languages: [...], catalog: [...] }.
export function useLanguages( options = {} ) {
	return useQuery( {
		queryKey: qk.languages.list(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/languages', { signal } ) ),
		...options,
	} )
}

// GET /languages/targets — active target languages + display names only, no
// progress queries (light; use where the percentages aren't needed).
export function useLanguageTargets( options = {} ) {
	return useQuery( {
		queryKey: qk.languages.targets(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/languages/targets', { signal } ) ),
		...options,
	} )
}

// GET /languages/catalog — full WordPress installable-language catalog.
// Response: { ok, catalog: [...] }. Slow-changing, so a longer staleTime.
export function useLanguageCatalog( options = {} ) {
	return useQuery( {
		queryKey: qk.languages.catalog(),
		queryFn: ( { signal } ) =>
			unwrap( api.get( '/languages/catalog', { signal } ) ),
		staleTime: 30 * 60_000,
		...options,
	} )
}

// --- writes ----------------------------------------------------------------

// Fired on window when a write publishes the site's first language (the
// response's `first_published`). A one-off UI notice, not server data, so it
// is an event rather than query state.
export const FIRST_LANGUAGE_PUBLISHED_EVENT = 'lingowp:first-language-published'

// `board` argument: also blow away the content board, for writes that change
// which languages exist or what's translated (create / delete / use-fallback).
function useLanguageWrite( mutationFn, { board = false } = {} ) {
	const queryClient = useQueryClient()
	return useMutation( {
		mutationFn,
		onSuccess: ( data ) => {
			// The site just went from no published language to one —
			// SwitcherSetupToast offers the language switcher settings.
			if ( data?.first_published ) {
				window.dispatchEvent(
					new Event( FIRST_LANGUAGE_PUBLISHED_EVENT )
				)
			}
			queryClient.invalidateQueries( { queryKey: qk.languages.all } )
			if ( board ) {
				queryClient.invalidateQueries( {
					predicate: qk.board.matchAll,
				} )
			}
		},
	} )
}

// POST /languages — add one target language.
export function useAddLanguage() {
	return useLanguageWrite(
		( body ) => unwrap( api.post( '/languages', body ) ),
		{ board: true }
	)
}

// POST /languages/bulk — add many at once. Response carries `languages`
// (created) and `skipped`.
export function useBulkAddLanguages() {
	return useLanguageWrite(
		( body ) => unwrap( api.post( '/languages/bulk', body ) ),
		{ board: true }
	)
}

// PATCH /languages/{code} — pass { code, ...fields }. Only metadata (name,
// slug, fallback, ai_model, status, …); doesn't move translated content.
export function usePatchLanguage() {
	return useLanguageWrite( ( { code, ...fields } ) =>
		unwrap(
			api.patch( `/languages/${ encodeURIComponent( code ) }`, fields )
		)
	)
}

// DELETE /languages/{code}
export function useDeleteLanguage() {
	return useLanguageWrite(
		( code ) =>
			unwrap( api.del( `/languages/${ encodeURIComponent( code ) }` ) ),
		{ board: true }
	)
}

// POST /languages/ai-model — set the AI model for every language at once. Pass
// { ai_model, ai_style? }.
export function useSetAllAiModel() {
	return useLanguageWrite( ( body ) =>
		unwrap( api.post( '/languages/ai-model', body ) )
	)
}

// POST /languages/{code}/use-fallback — copy the fallback language's
// translations into every gap. Response carries `copied`.
export function useUseFallback() {
	return useLanguageWrite(
		( code ) =>
			unwrap(
				api.post(
					`/languages/${ encodeURIComponent( code ) }/use-fallback`
				)
			),
		{ board: true }
	)
}
