import { useState } from '@wordpress/element';
import { exportCsv, importCsv, errorMessage } from '../api';

/**
 * Import / Export (wireframe A10). Export streams a CSV (one row per source, one
 * column per language); import reads that CSV back, with a no-write Preview pass
 * first. Imported translations land as "to review".
 */
export default function ImportExport( { config } ) {
	const areas = [ { key: 'all', label: 'All content' }, ...config.content_areas ];
	const [ area, setArea ] = useState( 'all' );
	const [ langs, setLangs ] = useState( config.languages.map( ( l ) => l.code ) );
	const [ exporting, setExporting ] = useState( false );

	const [ file, setFile ] = useState( null );
	const [ importing, setImporting ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	const toggleLang = ( code ) =>
		setLangs( ( cur ) => ( cur.includes( code ) ? cur.filter( ( c ) => c !== code ) : [ ...cur, code ] ) );

	const download = () => {
		setExporting( true );
		setError( null );
		exportCsv( { area, langs: langs.join( ',' ) } )
			.then( ( blob ) => {
				const url = window.URL.createObjectURL( blob );
				const a = document.createElement( 'a' );
				a.href = url;
				a.download = `translangs-${ area }.csv`;
				document.body.appendChild( a );
				a.click();
				a.remove();
				window.URL.revokeObjectURL( url );
				setExporting( false );
			} )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				setExporting( false );
			} );
	};

	const run = ( preview ) => {
		if ( ! file ) {
			return;
		}
		setImporting( true );
		setError( null );
		importCsv( file, { preview } )
			.then( ( res ) => {
				setResult( res );
				setImporting( false );
			} )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				setImporting( false );
			} );
	};

	return (
		<div className="tl-list">
			<h2>Import / Export</h2>
			{ error && <div className="tl-error">{ error }</div> }

			<section className="tl-ie-block">
				<h3>Export to CSV</h3>
				<p className="tl-modal-intro">One row per original text, one column per language. Opens in Excel.</p>
				<div className="tl-ie-row">
					<label>
						Content area:{ ' ' }
						<select value={ area } onChange={ ( e ) => setArea( e.target.value ) }>
							{ areas.map( ( a ) => (
								<option key={ a.key } value={ a.key }>{ a.label }</option>
							) ) }
						</select>
					</label>
					<span className="tl-ie-langs">
						Languages:
						{ config.languages.map( ( l ) => (
							<label key={ l.code } className="tl-ie-lang">
								<input
									type="checkbox"
									checked={ langs.includes( l.code ) }
									onChange={ () => toggleLang( l.code ) }
								/>
								{ l.native || l.name }
							</label>
						) ) }
					</span>
				</div>
				<button
					type="button"
					className="button button-primary"
					disabled={ exporting || langs.length === 0 }
					onClick={ download }
				>
					{ exporting ? 'Preparing…' : 'Download CSV' }
				</button>
			</section>

			<section className="tl-ie-block">
				<h3>Import from CSV</h3>
				<p className="tl-modal-intro">
					Upload the same file with translations filled in. Imported translations go to “to review”.
				</p>
				<input
					type="file"
					accept=".csv,text/csv"
					onChange={ ( e ) => {
						setFile( e.target.files[ 0 ] || null );
						setResult( null );
					} }
				/>
				<div className="tl-ie-row">
					<button type="button" className="button" disabled={ ! file || importing } onClick={ () => run( true ) }>
						Preview import
					</button>
					<button
						type="button"
						className="button button-primary"
						disabled={ ! file || importing }
						onClick={ () => run( false ) }
					>
						Import
					</button>
				</div>

				{ result && (
					<div className="tl-notice">
						{ result.preview ? 'Preview — nothing saved yet. ' : 'Imported. ' }
						{ result.saved } translation{ result.saved === 1 ? '' : 's' }
						{ result.preview ? ' will be saved' : ' saved' }
						{ result.skipped > 0 && `, ${ result.skipped } row${ result.skipped === 1 ? '' : 's' } skipped` }
						{ result.errors && result.errors.length > 0 && (
							<ul className="tl-ie-errors">
								{ result.errors.map( ( msg, i ) => (
									<li key={ i }>{ msg }</li>
								) ) }
							</ul>
						) }
					</div>
				) }
			</section>
		</div>
	);
}
