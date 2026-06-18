import { useState, useEffect } from '@wordpress/element';
import { getString, errorMessage } from '../api';
import Placeholders from './Placeholders';

/**
 * "Copy from another language" (UC-AD23). Lists the languages that already have
 * a translation for this source and lets the user drop one into the field — it
 * only PREFILLS (onPick), leaving the row unconfirmed so they can edit + Valid.
 */
export default function CopyFromLanguage( { sourceKey, lang, languages, onClose, onPick } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		getString( sourceKey, lang )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ sourceKey, lang ] );

	const name = ( code ) => {
		const l = languages.find( ( x ) => x.code === code );
		return l ? l.native || l.name : code;
	};

	const options = data
		? data.siblings.filter( ( s ) => s.lang_code !== lang && ( s.translated_text || '' ).trim() !== '' )
		: [];

	return (
		<div className="tl-modal-backdrop" onClick={ onClose }>
			<div className="tl-modal" onClick={ ( e ) => e.stopPropagation() }>
				<div className="tl-modal-head">
					<h2>Copy from another language</h2>
					<button type="button" className="tl-modal-close" onClick={ onClose } aria-label="Close">
						×
					</button>
				</div>

				<div className="tl-modal-body">
					{ error && <div className="tl-error">{ error }</div> }
					{ ! data && ! error && <div className="tl-loading">Loading…</div> }

					{ data && (
						<>
							<label className="tl-label">Original</label>
							<div className="tl-original">
								<Placeholders text={ data.string.original } />
							</div>

							{ options.length === 0 ? (
								<div className="tl-empty">No other language has a translation yet.</div>
							) : (
								<ul className="tl-copy-list">
									{ options.map( ( s ) => (
										<li key={ s.lang_code }>
											<div className="tl-copy-meta">
												<strong>{ name( s.lang_code ) }</strong>
												<span className="tl-copy-text">{ s.translated_text }</span>
											</div>
											<button type="button" className="button" onClick={ () => onPick( s.translated_text ) }>
												Use
											</button>
										</li>
									) ) }
								</ul>
							) }
						</>
					) }
				</div>
			</div>
		</div>
	);
}
