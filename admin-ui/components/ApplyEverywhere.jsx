import { useState, useEffect } from '@wordpress/element';
import { getDuplicates, applyEverywhere, errorMessage } from '../api';

/**
 * Reuse the current translation across other sources that share the same
 * original text. Loads /duplicates, lets the user pick targets + whether to
 * overwrite translations that already exist, then POSTs /apply-everywhere.
 */
export default function ApplyEverywhere( { sourceKey, lang, text, onClose, onApplied } ) {
	const [ dups, setDups ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ overwrite, setOverwrite ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		getDuplicates( sourceKey, lang )
			.then( ( rows ) => {
				setDups( rows );
				const pre = {};
				rows.forEach( ( r ) => {
					// Default-check only the ones with no translation yet.
					pre[ r.source_key ] = ! r.existing_translation;
				} );
				setSelected( pre );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ sourceKey, lang ] );

	const toggle = ( key ) => setSelected( ( s ) => ( { ...s, [ key ]: ! s[ key ] } ) );

	const targets = dups ? dups.filter( ( r ) => selected[ r.source_key ] ).map( ( r ) => r.source_key ) : [];

	const apply = () => {
		setBusy( true );
		setError( null );
		applyEverywhere( sourceKey, { lang, text, targets, overwrite } )
			.then( ( res ) => {
				setBusy( false );
				if ( onApplied ) {
					onApplied( res.applied );
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
					<h2>Use this translation elsewhere</h2>
					<button type="button" className="tl-modal-close" onClick={ onClose } aria-label="Close">
						×
					</button>
				</div>

				<div className="tl-modal-body">
					{ error && <div className="tl-error">{ error }</div> }

					{ dups === null && <div className="tl-loading">Loading…</div> }

					{ dups !== null && dups.length === 0 && (
						<div className="tl-empty">No other places use this exact text.</div>
					) }

					{ dups !== null && dups.length > 0 && (
						<>
							<p className="tl-modal-intro">
								Apply <code>{ lang }</code> translation to these matching strings:
							</p>
							<ul className="tl-dup-list">
								{ dups.map( ( r ) => (
									<li key={ r.source_key }>
										<label>
											<input
												type="checkbox"
												checked={ !! selected[ r.source_key ] }
												onChange={ () => toggle( r.source_key ) }
											/>
											<span className="tl-dup-field">{ r.ref1 || '(string)' }</span>
											{ r.existing_translation && (
												<span className="tl-dup-existing">
													already: “{ r.existing_translation }”
												</span>
											) }
										</label>
									</li>
								) ) }
							</ul>
							<label className="tl-overwrite">
								<input
									type="checkbox"
									checked={ overwrite }
									onChange={ ( e ) => setOverwrite( e.target.checked ) }
								/>
								Overwrite translations that already exist
							</label>
						</>
					) }
				</div>

				<div className="tl-modal-foot">
					<button type="button" className="button" onClick={ onClose }>
						Cancel
					</button>
					<button
						type="button"
						className="button button-primary"
						disabled={ busy || targets.length === 0 }
						onClick={ apply }
					>
						Apply to { targets.length } place{ targets.length === 1 ? '' : 's' }
					</button>
				</div>
			</div>
		</div>
	);
}
