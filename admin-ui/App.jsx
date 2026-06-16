import { useState, useEffect, useCallback } from '@wordpress/element';
import { getConfig, getAllCounts, errorMessage } from './api';
import Shell from './components/Shell';
import ContentList from './components/ContentList';

// Active area + filters live in the URL query so a refresh restores them.
// ponytail: native URLSearchParams instead of nuqs — no dep/provider for 4 keys.
const readUrl = () => {
	const p = new URLSearchParams( window.location.search );
	return {
		area: p.get( 'area' ) || 'page',
		filters: {
			q_original: p.get( 'q_original' ) || '',
			q_translated: p.get( 'q_translated' ) || '',
			origin: p.get( 'origin' ) || 'all',
		},
	};
};

const writeUrl = ( area, filters ) => {
	const p = new URLSearchParams( window.location.search );
	const setOrDel = ( k, v, empty ) => ( v && v !== empty ? p.set( k, v ) : p.delete( k ) );
	setOrDel( 'area', area, 'page' );
	setOrDel( 'q_original', filters.q_original, '' );
	setOrDel( 'q_translated', filters.q_translated, '' );
	setOrDel( 'origin', filters.origin, 'all' );
	window.history.replaceState( null, '', `${ window.location.pathname }?${ p.toString() }` );
};

export default function App() {
	const initial = readUrl();
	const [ config, setConfig ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ area, setArea ] = useState( initial.area );
	const [ lang, setLang ] = useState( '' );
	const [ filters, setFilters ] = useState( initial.filters );
	const [ areaCounts, setAreaCounts ] = useState( {} );

	useEffect( () => {
		getConfig()
			.then( ( cfg ) => {
				setConfig( cfg );
				setLang( cfg.languages[ 0 ] ? cfg.languages[ 0 ].code : '' );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	useEffect( () => {
		writeUrl( area, filters );
	}, [ area, filters ] );

	const refreshAreaCounts = useCallback( () => {
		if ( ! lang ) {
			return;
		}
		getAllCounts( { lang } )
			.then( setAreaCounts )
			.catch( () => {} );
	}, [ lang ] );

	// Refresh sidebar badges whenever the language changes.
	useEffect( () => {
		refreshAreaCounts();
	}, [ refreshAreaCounts ] );

	if ( error ) {
		return <div className="tl-error">{ error }</div>;
	}
	if ( ! config ) {
		return <div className="tl-loading">Loading…</div>;
	}
	if ( config.languages.length === 0 ) {
		return (
			<div className="tl-empty">
				Add a target language in Settings to start translating.
			</div>
		);
	}

	return (
		<Shell
			config={ config }
			area={ area }
			onArea={ setArea }
			lang={ lang }
			onLang={ setLang }
			filters={ filters }
			onFilters={ setFilters }
			areaCounts={ areaCounts }
		>
			<ContentList
				config={ config }
				area={ area }
				lang={ lang }
				filters={ filters }
				onCountsChanged={ refreshAreaCounts }
			/>
		</Shell>
	);
}
