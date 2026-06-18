import { useState, useEffect, useCallback } from '@wordpress/element';
import { getIgnored, restoreSources, errorMessage } from '../api';

/**
 * "Don't-translate" page (wireframe A9): every source the user has hidden from
 * translation, across all areas. Restores one or many back into translation.
 */
export default function DontTranslate( { onCountsChanged } ) {
	const [ rows, setRows ] = useState( null );
	const [ cursor, setCursor ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = useCallback( ( cur ) => {
		setError( null );
		getIgnored( cur || undefined )
			.then( ( res ) => {
				setRows( ( prev ) => ( cur ? [ ...( prev || [] ), ...res.rows ] : res.rows ) );
				setCursor( res.next_cursor );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	useEffect( () => {
		load( null );
	}, [ load ] );

	const toggle = ( key ) => setSelected( ( s ) => ( { ...s, [ key ]: ! s[ key ] } ) );

	const restore = ( keys ) => {
		if ( keys.length === 0 ) {
			return;
		}
		setBusy( true );
		restoreSources( keys )
			.then( () => {
				const gone = new Set( keys );
				setRows( ( prev ) => ( prev || [] ).filter( ( r ) => ! gone.has( r.source_key ) ) );
				setSelected( {} );
				setBusy( false );
				if ( onCountsChanged ) {
					onCountsChanged();
				}
			} )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				setBusy( false );
			} );
	};

	const selectedKeys = ( rows || [] ).map( ( r ) => r.source_key ).filter( ( k ) => selected[ k ] );

	return (
		<div className="tl-list">
			<div className="tl-list-head">
				<h2>Don’t-translate</h2>
				<button
					type="button"
					className="button"
					disabled={ busy || selectedKeys.length === 0 }
					onClick={ () => restore( selectedKeys ) }
				>
					Put selected back into translation
				</button>
			</div>
			<p className="tl-modal-intro">These texts always show in their original form.</p>

			{ error && <div className="tl-error">{ error }</div> }
			{ rows === null && <div className="tl-loading">Loading…</div> }
			{ rows !== null && rows.length === 0 && (
				<div className="tl-empty">Nothing is hidden from translation.</div>
			) }

			{ rows !== null && rows.length > 0 && (
				<ul className="tl-ignored-list">
					{ rows.map( ( r ) => (
						<li key={ r.source_key }>
							<label className="tl-ignored-main">
								<input
									type="checkbox"
									checked={ !! selected[ r.source_key ] }
									onChange={ () => toggle( r.source_key ) }
								/>
								<span className="tl-ignored-text" title={ r.original_text }>
									{ r.original_text }
								</span>
								<span className="tl-ignored-ref">
									({ r.kind }{ r.ref1 ? ` › ${ r.ref1 }` : '' })
								</span>
							</label>
							<button
								type="button"
								className="button"
								disabled={ busy }
								onClick={ () => restore( [ r.source_key ] ) }
							>
								Put back into translation
							</button>
						</li>
					) ) }
				</ul>
			) }

			{ cursor && (
				<div className="tl-pager">
					<button type="button" className="button" disabled={ busy } onClick={ () => load( cursor ) }>
						Load more
					</button>
				</div>
			) }
		</div>
	);
}
