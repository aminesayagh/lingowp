import { useState, useEffect } from '@wordpress/element';
import { getConfig, errorMessage } from './api';
import Shell from './components/Shell';
import ContentList from './components/ContentList';

export default function App() {
	const [ config, setConfig ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ area, setArea ] = useState( 'page' );
	const [ lang, setLang ] = useState( '' );

	useEffect( () => {
		getConfig()
			.then( ( cfg ) => {
				setConfig( cfg );
				setLang( cfg.languages[ 0 ] ? cfg.languages[ 0 ].code : '' );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

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
		>
			<ContentList config={ config } area={ area } lang={ lang } />
		</Shell>
	);
}
