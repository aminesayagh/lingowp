import { useState, useEffect } from '@wordpress/element';
import { getString, saveAllTranslations, errorMessage } from '../api';
import Placeholders from './Placeholders';
import AutoTextarea from './AutoTextarea';

/**
 * Per-record "translate into all languages" modal (wireframe A5). Loads the
 * source's original + every language's current translation, lets the user fill
 * any languages, and saves them all as "needs review". Ignores the top language
 * filter by design. Empty rows are left untranslated.
 */
export default function AllLanguages( { config, sourceKey, lang, onClose, onSaved } ) {
	const [ data, setData ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );

	useEffect( () => {
		getString( sourceKey, lang )
			.then( ( res ) => {
				setData( res );
				const init = {};
				config.languages.forEach( ( l ) => {
					const sib = res.siblings.find( ( s ) => s.lang_code === l.code );
					init[ l.code ] = sib ? sib.translated_text || '' : '';
				} );
				setValues( init );
			} )
			.catch( ( e ) => setLoadError( errorMessage( e ) ) );
	}, [ sourceKey, lang, config ] );

	const set = ( code, text ) => setValues( ( v ) => ( { ...v, [ code ]: text } ) );

	const save = () => {
		const translations = config.languages
			.map( ( l ) => ( { lang: l.code, text: values[ l.code ] } ) )
			.filter( ( t ) => t.text.trim() !== '' );

		if ( translations.length === 0 ) {
			setError( 'Fill in at least one language.' );
			return;
		}

		setBusy( true );
		setError( null );
		saveAllTranslations( sourceKey, translations )
			.then( () => {
				setBusy( false );
				if ( onSaved ) {
					onSaved();
				}
				onClose();
			} )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				setBusy( false );
			} );
	};

	return (
		<div className="tl-modal-backdrop" onClick={ onClose }>
			<div className="tl-modal" onClick={ ( e ) => e.stopPropagation() }>
				<div className="tl-modal-head">
					<h2>Translate into all languages</h2>
					<button type="button" className="tl-modal-close" onClick={ onClose } aria-label="Close">
						×
					</button>
				</div>

				<div className="tl-modal-body">
					{ loadError && <div className="tl-error">{ loadError }</div> }
					{ ! data && ! loadError && <div className="tl-loading">Loading…</div> }

					{ data && (
						<>
							<label className="tl-label">Original</label>
							<div className="tl-original">
								<Placeholders text={ data.string.original } />
							</div>

							<table className="tl-lang-table">
								<tbody>
									{ config.languages.map( ( l ) => (
										<tr key={ l.code }>
											<th scope="row">
												{ l.native || l.name }
											</th>
											<td>
												<AutoTextarea
													className="tl-textarea"
													rows={ 1 }
													dir={ l.dir || 'ltr' }
													value={ values[ l.code ] || '' }
													onChange={ ( e ) => set( l.code, e.target.value ) }
												/>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>

							{ error && <div className="tl-error">{ error }</div> }
							<p className="tl-modal-intro">Empty languages stay untranslated. Saved → “to review”.</p>
						</>
					) }
				</div>

				<div className="tl-modal-foot">
					<button type="button" className="button" onClick={ onClose }>
						Cancel
					</button>
					<button type="button" className="button button-primary" disabled={ busy || ! data } onClick={ save }>
						Save all
					</button>
				</div>
			</div>
		</div>
	);
}
