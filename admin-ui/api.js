import apiFetch from '@wordpress/api-fetch';

const NS = 'translangs/v1';

function qs( params ) {
	const parts = Object.entries( params )
		.filter( ( [ , v ] ) => v !== undefined && v !== null && v !== '' )
		.map( ( [ k, v ] ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( v ) }` );
	return parts.length ? `?${ parts.join( '&' ) }` : '';
}

export const getConfig = () => apiFetch( { path: `${ NS }/config` } );

export const getItems = ( params ) => apiFetch( { path: `${ NS }/items${ qs( params ) }` } );

export const getCounts = ( params ) => apiFetch( { path: `${ NS }/counts${ qs( params ) }` } );

export const getAllCounts = ( params ) => apiFetch( { path: `${ NS }/counts-all${ qs( params ) }` } );

export const getString = ( key, lang ) =>
	apiFetch( { path: `${ NS }/string/${ key }${ qs( { lang } ) }` } );

export const saveTranslation = ( key, lang, body ) =>
	apiFetch( {
		path: `${ NS }/string/${ key }/translation`,
		method: 'PUT',
		data: { lang, ...body },
	} );

export const deleteTranslation = ( key, lang ) =>
	apiFetch( {
		path: `${ NS }/string/${ key }/translation${ qs( { lang } ) }`,
		method: 'DELETE',
	} );

export const runScan = ( area ) =>
	apiFetch( { path: `${ NS }/scan`, method: 'POST', data: { area, scope: 'all' } } );

/** Bulk-save several languages at once; each lands as "needs review". */
export const saveAllTranslations = ( key, translations ) =>
	apiFetch( {
		path: `${ NS }/string/${ key }/translations`,
		method: 'PUT',
		data: { translations },
	} );

/** Other sources sharing this string's original text (apply-everywhere candidates). */
export const getDuplicates = ( key, lang ) =>
	apiFetch( { path: `${ NS }/string/${ key }/duplicates${ qs( { lang } ) }` } );

export const applyEverywhere = ( key, body ) =>
	apiFetch( {
		path: `${ NS }/string/${ key }/apply-everywhere`,
		method: 'POST',
		data: body,
	} );

/** Build the front-end "Preview on site" URL (opens the string highlighted in lang). */
export const getPreview = ( key, lang ) =>
	apiFetch( { path: `${ NS }/string/${ key }/preview${ qs( { lang } ) }` } );

/** Mark a source as "don't translate" (its translations are kept, just hidden). */
export const ignoreSource = ( key ) =>
	apiFetch( { path: `${ NS }/string/${ key }/ignore`, method: 'POST' } );

/** Paginated list of "don't translate" sources across all tables. */
export const getIgnored = ( cursor ) =>
	apiFetch( { path: `${ NS }/ignored${ qs( { cursor } ) }` } );

/** Un-ignore a set of sources (put them back into translation). */
export const restoreSources = ( keys ) =>
	apiFetch( { path: `${ NS }/ignored/restore`, method: 'POST', data: { keys } } );

/** Fetch the export CSV as a Blob (carries the nonce, unlike a plain link). */
export const exportCsv = ( params ) =>
	apiFetch( { path: `${ NS }/export${ qs( params ) }`, parse: false } ).then( ( r ) => r.blob() );

/** Upload a CSV; pass preview=1 to validate + count without writing. */
export const importCsv = ( file, { preview } = {} ) => {
	const body = new FormData();
	body.append( 'file', file );
	return apiFetch( { path: `${ NS }/import${ qs( { preview: preview ? 1 : '' } ) }`, method: 'POST', body } );
};

/** Pull a human message out of an apiFetch/WP_Error rejection. */
export const errorMessage = ( e ) =>
	( e && ( e.message || ( e.data && e.data.message ) ) ) || 'Something went wrong.';
