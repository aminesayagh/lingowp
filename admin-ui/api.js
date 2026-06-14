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

/** Mark a source as "don't translate" (drops its translation rows). */
export const ignoreSource = ( key ) =>
	apiFetch( { path: `${ NS }/string/${ key }/ignore`, method: 'POST' } );

/** Where this string appears on the live site (permalink, or selector for HTML). */
export const getPreview = ( key, lang ) =>
	apiFetch( { path: `${ NS }/string/${ key }/preview${ qs( { lang } ) }` } );

/** Pull a human message out of an apiFetch/WP_Error rejection. */
export const errorMessage = ( e ) =>
	( e && ( e.message || ( e.data && e.data.message ) ) ) || 'Something went wrong.';
