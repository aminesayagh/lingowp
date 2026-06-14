const settingsUrl = () =>
	( typeof window !== 'undefined' && window.TranslangsBoard && window.TranslangsBoard.settingsUrl ) || '#';

export default function Shell( { config, area, onArea, lang, onLang, children } ) {
	return (
		<div className="tl-shell">
			<header className="tl-header">
				<strong className="tl-brand">TransLangs</strong>
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
					{ config.content_areas.map( ( a ) => (
						<button
							key={ a.key }
							type="button"
							className={ 'tl-nav-item' + ( a.key === area ? ' is-active' : '' ) }
							onClick={ () => onArea( a.key ) }
						>
							{ a.label }
						</button>
					) ) }

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
