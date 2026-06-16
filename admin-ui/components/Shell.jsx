import { useState } from '@wordpress/element';

const settingsUrl = () =>
	( typeof window !== 'undefined' && window.TranslangsBoard && window.TranslangsBoard.settingsUrl ) || '#';

export default function Shell( { config, area, onArea, lang, onLang, filters, onFilters, areaCounts, children } ) {
	const [ draft, setDraft ] = useState( filters );
	const set = ( patch ) => setDraft( ( d ) => ( { ...d, ...patch } ) );
	const changed =
		draft.q_original !== filters.q_original ||
		draft.q_translated !== filters.q_translated ||
		draft.origin !== filters.origin;

	const apply = ( e ) => {
		e.preventDefault();
		onFilters( draft );
	};

	return (
		<div className="tl-shell">
			<header className="tl-header">
				<strong className="tl-brand">TransLangs</strong>
				<form className="tl-header-filters" onSubmit={ apply }>
					<input
						placeholder="Search original"
						value={ draft.q_original }
						onChange={ ( e ) => set( { q_original: e.target.value } ) }
					/>
					<input
						placeholder="Search translated"
						value={ draft.q_translated }
						onChange={ ( e ) => set( { q_translated: e.target.value } ) }
					/>
					<select value={ draft.origin } onChange={ ( e ) => set( { origin: e.target.value } ) }>
						<option value="all">All</option>
						<option value="human">Manually edited</option>
						<option value="machine">Auto-translated</option>
					</select>
					<button type="submit" className="button" disabled={ ! changed }>
						Filter
					</button>
				</form>
				<div className="tl-header-right">
					<label className="tl-lang">
						Translate to:{ ' ' }
						<select value={ lang } onChange={ ( e ) => onLang( e.target.value ) }>
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
								onClick={ () => onArea( a.key ) }
							>
								{ a.label }
								{ c && c.total > 0 && (
									<span className="tl-nav-count">
										({ c.to_translate }/{ c.total })
									</span>
								) }
							</button>
						);
					} ) }

					<div className="tl-nav-group">Site</div>
					<button type="button" className="tl-nav-item" disabled>
						Menus &amp; Branding
					</button>

					<div className="tl-nav-group">Manage</div>
					<button type="button" className="tl-nav-item" disabled>
						Don’t-translate
					</button>
					<button type="button" className="tl-nav-item" disabled>
						Import / Export
					</button>
				</nav>

				<main className="tl-main">{ children }</main>
			</div>
		</div>
	);
}
