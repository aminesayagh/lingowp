import { useState } from '@wordpress/element';
import { confirmDiscard } from '../unsavedGuard';

const settingsUrl = () =>
	( typeof window !== 'undefined' && window.TranslangsBoard && window.TranslangsBoard.settingsUrl ) || '#';

export default function Shell( { config, area, onArea, lang, onLang, filters, onFilters, areaCounts, children } ) {
	const [ draft, setDraft ] = useState( filters );
	const set = ( patch ) => setDraft( ( d ) => ( { ...d, ...patch } ) );
	const changed =
		draft.q_original !== filters.q_original ||
		draft.q_translated !== filters.q_translated ||
		draft.origin !== filters.origin;

	// Every navigation that reloads the list warns first if a row has an unsaved edit.
	const goArea = ( key ) => confirmDiscard() && onArea( key );
	const goLang = ( code ) => confirmDiscard() && onLang( code );

	const apply = ( e ) => {
		e.preventDefault();
		if ( confirmDiscard() ) {
			onFilters( { ...draft, sort: filters.sort } );
		}
	};

	const setSort = ( sort ) => confirmDiscard() && onFilters( { ...filters, sort } );

	return (
		<div className="tl-shell">
			<header className="tl-header">
				<strong className="tl-brand">TransLangs</strong>
				<form className="tl-header-filters" onSubmit={ apply }>
					<input
						className="tl-search"
						placeholder="Search original"
						value={ draft.q_original }
						onChange={ ( e ) => set( { q_original: e.target.value } ) }
					/>
					<input
						className="tl-search"
						placeholder="Search translated"
						value={ draft.q_translated }
						onChange={ ( e ) => set( { q_translated: e.target.value } ) }
					/>
					<select value={ draft.origin } onChange={ ( e ) => set( { origin: e.target.value } ) }>
						<option value="all">All</option>
						<option value="human">Manually edited</option>
						<option value="machine">Auto-translated</option>
					</select>
					<select value={ filters.sort } onChange={ ( e ) => setSort( e.target.value ) } aria-label="Sort">
						<option value="default">Default order</option>
						<option value="recent">Recently changed</option>
					</select>
					<button type="submit" className="button" disabled={ ! changed }>
						Filter
					</button>
				</form>
				<div className="tl-header-right">
					<label className="tl-lang">
						Translate to:{ ' ' }
						<select value={ lang } onChange={ ( e ) => goLang( e.target.value ) }>
							{ config.languages.map( ( l ) => (
								<option key={ l.code } value={ l.code }>
									{ l.native || l.name }
								</option>
							) ) }
						</select>
					</label>
					<a className="button" href={ settingsUrl() }>
						Settings
					</a>
				</div>
			</header>

			<div className="tl-body">
				<nav className="tl-sidebar">
					<div className="tl-nav-group">Content</div>
					{ config.content_areas.map( ( a ) => {
						const c = areaCounts && areaCounts[ a.key ];
						return (
							<button
								key={ a.key }
								type="button"
								className={ 'tl-nav-item' + ( a.key === area ? ' is-active' : '' ) }
								onClick={ () => goArea( a.key ) }
							>
								{ a.label }
								{ c && c.total > 0 && (
									<span className="tl-nav-count">
										({ c.done }/{ c.total })
									</span>
								) }
							</button>
						);
					} ) }

					<div className="tl-nav-group">Site</div>
					<button
						type="button"
						className={ 'tl-nav-item' + ( area === 'menus-branding' ? ' is-active' : '' ) }
						onClick={ () => goArea( 'menus-branding' ) }
					>
						Menus &amp; Branding
					</button>

					<div className="tl-nav-group">Manage</div>
					<button
						type="button"
						className={ 'tl-nav-item' + ( area === 'dont-translate' ? ' is-active' : '' ) }
						onClick={ () => goArea( 'dont-translate' ) }
					>
						Don’t-translate
					</button>
					<button
						type="button"
						className={ 'tl-nav-item' + ( area === 'import-export' ? ' is-active' : '' ) }
						onClick={ () => goArea( 'import-export' ) }
					>
						Import / Export
					</button>
				</nav>

				<main className="tl-main">{ children }</main>
			</div>
		</div>
	);
}
