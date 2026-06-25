import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	getItems,
	getCounts,
	saveTranslation,
	deleteTranslation,
	ignoreSource,
	getDuplicates,
	getPreview,
	getPlural,
	savePlural,
	errorMessage,
} from '../api';
import Placeholders from './Placeholders';
import AutoTextarea from './AutoTextarea';
import AllLanguages from './AllLanguages';
import ApplyEverywhere from './ApplyEverywhere';
import CopyFromLanguage from './CopyFromLanguage';
import { setDirty, confirmDiscard } from '../unsavedGuard';

const TABS = [
	[ 'to_translate', 'To translate' ],
	[ 'to_review', 'To review' ],
	[ 'done', 'Done' ],
];

const FIELD_LABELS = {
	post_title: 'Title',
	post_content: 'Content',
	post_excerpt: 'Excerpt',
	_wp_attachment_image_alt: 'Alt text',
};

const fieldLabel = ( field ) => {
	if ( ! field ) {
		return '';
	}
	if ( FIELD_LABELS[ field ] ) {
		return FIELD_LABELS[ field ];
	}
	const words = field.replace( /^_+/, '' ).replace( /_/g, ' ' );
	return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
};

const STATE_CHIP = {
	to_translate: { cls: 'new', label: '● new' },
	done: { cls: 'done', label: '✓ done' },
	review_changed: { cls: 'changed', label: '⚠ changed' },
	review_flagged: { cls: 'flagged', label: '👤 flagged' },
};

// Hash-link kinds key their translation by text, so identical strings already
// share one translation everywhere — the "apply everywhere" prompt only makes
// sense for parent-id kinds (meta/staticText), where each position is separate.
const HASH_LINK_KINDS = [ 'post', 'term', 'html', 'option' ];

export default function ContentList( { config, area, lang, filters, onCountsChanged, title } ) {
	const [ tab, setTab ] = useState( 'to_translate' );

	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ cursor, setCursor ] = useState( null );
	const [ stack, setStack ] = useState( [] );

	const [ modal, setModal ] = useState( null );

	const dir = ( config.languages.find( ( l ) => l.code === lang ) || {} ).dir || 'ltr';

	const load = useCallback(
		( cur ) => {
			setLoading( true );
			setError( null );
			getItems( {
				area,
				lang,
				tab,
				q_original: filters.q_original,
				q_translated: filters.q_translated,
				origin: filters.origin,
				sort: filters.sort,
				cursor: cur || undefined,
				limit: 20,
			} )
				.then( ( res ) => {
					setData( res );
					setLoading( false );
				} )
				.catch( ( e ) => {
					setError( errorMessage( e ) );
					setLoading( false );
				} );
		},
		[ area, lang, tab, filters ]
	);

	// Reset paging and reload whenever the area, language, tab, or filters change.
	useEffect( () => {
		setCursor( null );
		setStack( [] );
		load( null );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ area, lang, tab, filters ] );

	const refreshCounts = () => {
		getCounts( { area, lang } )
			.then( ( counts ) => setData( ( d ) => ( d ? { ...d, counts } : d ) ) )
			.catch( () => {} );
		if ( onCountsChanged ) {
			onCountsChanged();
		}
	};

	/** Patch one item in place (keeps other rows' unsaved edits intact). */
	const patchItem = ( key, patch ) => {
		setData( ( d ) => {
			if ( ! d ) {
				return d;
			}
			const groups = d.groups.map( ( g ) => ( {
				...g,
				items: g.items.map( ( it ) => ( it.key === key ? { ...it, ...patch } : it ) ),
			} ) );
			return { ...d, groups };
		} );
	};

	const removeItem = ( key ) => {
		setData( ( d ) => {
			if ( ! d ) {
				return d;
			}
			const groups = d.groups
				.map( ( g ) => ( { ...g, items: g.items.filter( ( it ) => it.key !== key ) } ) )
				.filter( ( g ) => g.items.length > 0 );
			return { ...d, groups };
		} );
	};

	const next = () => {
		if ( data && data.next_cursor && confirmDiscard() ) {
			setStack( ( s ) => [ ...s, cursor ] );
			setCursor( data.next_cursor );
			load( data.next_cursor );
		}
	};

	const prev = () => {
		if ( ! confirmDiscard() ) {
			return;
		}
		const s = [ ...stack ];
		const p = s.pop() || null;
		setStack( s );
		setCursor( p );
		load( p );
	};

	const counts = ( data && data.counts ) || { to_translate: 0, to_review: 0, done: 0, total: 0 };
	const areaLabel = title || ( config.content_areas.find( ( a ) => a.key === area ) || {} ).label || area;

	return (
		<div className="tl-list">
			<div className="tl-list-head">
				<h2>{ areaLabel }</h2>
			</div>

			<div className="tl-tabs">
				{ TABS.map( ( [ k, label ] ) => (
					<button
						key={ k }
						type="button"
						className={ 'tl-tab' + ( tab === k ? ' is-active' : '' ) }
						onClick={ () => { if ( confirmDiscard() ) setTab( k ); } }
					>
						{ label } ({ counts[ k ] || 0 })
					</button>
				) ) }
			</div>

			<div className="tl-progress">
				<span
					className="tl-progress-bar"
					style={ { '--tl-pct': counts.total ? Math.round( ( counts.done / counts.total ) * 100 ) : 0 } }
				/>
				{ counts.done } of { counts.total } done · { counts.to_review } to review
			</div>

			{ error && <div className="tl-error">{ error }</div> }
			{ loading && <div className="tl-loading">Loading…</div> }

			{ ! loading && data && data.groups.length === 0 && (
				<div className="tl-empty">
					{ counts.total === 0
						? `We haven’t found any text in ${ areaLabel } yet. Use “Check for new text”.`
						: `Everything in ${ areaLabel } is translated. 🎉` }
				</div>
			) }

			{ ! loading &&
				data &&
				data.groups.map( ( g ) => (
					<div className="tl-card" key={ g.owner.kind + ':' + g.owner.id }>
						<div className="tl-card-title">
							{ g.owner.thumb && <img className="tl-thumb" src={ g.owner.thumb } alt="" /> }
							<span>
								{ g.owner.label }
							</span>
						</div>
						{ g.items.map( ( it ) => (
							<InlineRow
								key={ it.key }
								item={ it }
								lang={ lang }
								dir={ dir }
								languages={ config.languages }
								multiLang={ config.languages.length > 1 }
								onSaved={ ( patch ) => {
									patchItem( it.key, patch );
									refreshCounts();
								} }
								onRemoved={ () => {
									removeItem( it.key );
									refreshCounts();
								} }
								onModal={ setModal }
							/>
						) ) }
					</div>
				) ) }

			<div className="tl-pager">
				<button type="button" className="button" disabled={ stack.length === 0 } onClick={ prev }>
					◀ Prev
				</button>
				<button
					type="button"
					className="button"
					disabled={ ! ( data && data.next_cursor ) }
					onClick={ next }
				>
					Next ▶
				</button>
			</div>

			{ modal && modal.type === 'all' && (
				<AllLanguages
					config={ config }
					sourceKey={ modal.key }
					lang={ lang }
					onClose={ () => setModal( null ) }
					onSaved={ () => load( cursor ) }
				/>
			) }
			{ modal && modal.type === 'apply' && (
				<ApplyEverywhere
					sourceKey={ modal.key }
					lang={ lang }
					text={ modal.text }
					onClose={ () => setModal( null ) }
					onApplied={ () => load( cursor ) }
				/>
			) }
		</div>
	);
}

// A gettext plural message edits N forms; everything else is a single textarea.
function InlineRow( props ) {
	return props.item.plural ? <PluralRow { ...props } /> : <SingleRow { ...props } />;
}

function SingleRow( { item, lang, dir, languages, multiLang, onSaved, onRemoved, onModal } ) {
	const [ text, setText ] = useState( item.translation || '' );
	const [ busy, setBusy ] = useState( false );
	const [ rowError, setRowError ] = useState( null );
	const [ conflict, setConflict ] = useState( null );
	const [ copyOpen, setCopyOpen ] = useState( false );

	// Keep the field in sync if the row is patched from elsewhere.
	useEffect( () => {
		setText( item.translation || '' );
	}, [ item.translation ] );

	const chip = STATE_CHIP[ item.state ];
	const dirty = text !== ( item.translation || '' );

	// Register the row's unsaved state so navigation can warn (A12 / UC-AD30).
	useEffect( () => {
		setDirty( item.key, dirty );
		return () => setDirty( item.key, false );
	}, [ dirty, item.key ] );

	// `force` skips the optimistic-lock check (used by "Keep mine" on a conflict).
	const save = ( value, force ) => {
		setBusy( true );
		setRowError( null );
		saveTranslation( item.key, lang, {
			text: value,
			mark: 'reviewed',
			updated_at: force ? '' : ( item.updated_at || '' ),
		} )
			.then( ( res ) => {
				setBusy( false );
				setConflict( null );
				const s = res.string;
				onSaved( {
					translation: s ? s.translation : value,
					state: s ? s.state : 'done',
					updated_at: s ? s.updated_at : null,
					origin: 'human',
				} );
				// Hash-link kinds already propagate to every identical string; only
				// parent-id kinds (meta/staticText) need the apply-everywhere prompt.
				const kind = ( item.key || '' ).split( ':' )[ 0 ];
				if ( ! HASH_LINK_KINDS.includes( kind ) ) {
					getDuplicates( item.key, lang )
						.then( ( dups ) => {
							if ( dups.length > 0 ) {
								onModal( { type: 'apply', key: item.key, text: value } );
							}
						} )
						.catch( () => {} );
				}
			} )
			.catch( ( e ) => {
				setBusy( false );
				if ( e && e.code === 'conflict' && e.data && e.data.server ) {
					setConflict( e.data.server );
				} else {
					setRowError( errorMessage( e ) );
				}
			} );
	};

	// Accept the version saved elsewhere (UC-AD31), replacing the local edit.
	const loadLatest = () => {
		const s = conflict;
		setConflict( null );
		setText( s.translated_text || '' );
		onSaved( {
			translation: s.translated_text,
			state: s.effective_state,
			updated_at: s.tr_updated,
			origin: s.translated_text ? ( s.provider ? 'machine' : 'human' ) : null,
		} );
	};

	const clear = () => {
		setBusy( true );
		setRowError( null );
		deleteTranslation( item.key, lang )
			.then( () => {
				setBusy( false );
				onSaved( { translation: '', state: 'to_translate', updated_at: null, origin: null } );
			} )
			.catch( ( e ) => {
				setBusy( false );
				setRowError( errorMessage( e ) );
			} );
	};

	const dontTranslate = () => {
		setBusy( true );
		ignoreSource( item.key )
			.then( () => onRemoved() )
			.catch( ( e ) => {
				setBusy( false );
				setRowError( errorMessage( e ) );
			} );
	};

	const preview = () => {
		setRowError( null );
		getPreview( item.key, lang )
			.then( ( res ) => {
				if ( res && res.url ) {
					window.open( res.url, '_blank', 'noopener' );
				} else {
					setRowError( 'No preview available for this string.' );
				}
			} )
			.catch( ( e ) => setRowError( errorMessage( e ) ) );
	};

	return (
		<>
		<div className={ 'tl-pair' + ( item.field ? '' : ' tl-pair-nofield' ) }>
			{ item.field && (
				<span className="tl-field" title={ item.field }>{ fieldLabel( item.field ) }</span>
			) }

			<div className="tl-pair-row">
				<div className="tl-pair-cols">
					<div className="tl-col tl-col-original" title={ item.original }>
						<Placeholders text={ item.original } />
					</div>
					<div className="tl-col tl-col-translation">
						<AutoTextarea
							className="tl-textarea"
							rows={ 1 }
							dir={ dir }
							value={ text }
							disabled={ busy }
							placeholder="Add translation…"
							onChange={ ( e ) => setText( e.target.value ) }
						/>
					</div>
				</div>

				<div className="tl-pair-actions">
					{ chip && chip.cls !== 'new' && (
						<span className={ 'tl-state tl-state-' + chip.cls }>{ chip.label }</span>
					) }

					{ item.state === 'review_changed' && (
						<button
							type="button"
							className="button"
							disabled={ busy }
							title="Accept the current translation for the new wording"
							onClick={ () => save( item.translation || text ) }
						>
							Keep &amp; approve
						</button>
					) }

					<button
						type="button"
						className="button button-primary"
						disabled={ busy || text.trim() === '' || ( ! dirty && item.state === 'done' ) }
						onClick={ () => save( text ) }
					>
						Valid
					</button>

					<RowMenu
						canAllLanguages={ multiLang }
						canCopy={ multiLang }
						canPreview={ item.preview !== false }
						onPreview={ preview }
						onAllLanguages={ () => onModal( { type: 'all', key: item.key } ) }
						onCopyFrom={ () => setCopyOpen( true ) }
						onClear={ clear }
						onDontTranslate={ dontTranslate }
						hasTranslation={ ( item.translation || '' ) !== '' }
					/>
				</div>
			</div>

			{ conflict && (
				<div className="tl-notice tl-row-error tl-conflict">
					<span>Someone changed this translation while you were editing.</span>
					<button type="button" className="button" disabled={ busy } onClick={ loadLatest }>
						Load latest
					</button>
					<button type="button" className="button" disabled={ busy } onClick={ () => save( text, true ) }>
						Keep mine
					</button>
				</div>
			) }

			{ rowError && <div className="tl-error tl-row-error">{ rowError }</div> }
		</div>

		{ copyOpen && (
			<CopyFromLanguage
				sourceKey={ item.key }
				lang={ lang }
				languages={ languages }
				onClose={ () => setCopyOpen( false ) }
				onPick={ ( t ) => {
					setText( t );
					setCopyOpen( false );
				} }
			/>
		) }
		</>
	);
}

// Editor for a gettext plural message: one labelled box per CLDR form (e.g.
// French shows 2, Arabic 6). Forms + labels/examples come from the API per lang.
function PluralRow( { item, lang, dir, onSaved, onRemoved } ) {
	const [ forms, setForms ] = useState( null );
	const [ loaded, setLoaded ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ rowError, setRowError ] = useState( null );

	useEffect( () => {
		let alive = true;
		setForms( null );
		getPlural( item.key, lang )
			.then( ( res ) => {
				if ( ! alive ) {
					return;
				}
				const f = res.forms || [];
				setForms( f );
				setLoaded( JSON.stringify( f.map( ( x ) => x.text ) ) );
			} )
			.catch( ( e ) => alive && setRowError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ item.key, lang ] );

	const setFormText = ( idx, val ) =>
		setForms( ( fs ) => fs.map( ( f ) => ( f.index === idx ? { ...f, text: val } : f ) ) );

	const anyText = !! forms && forms.some( ( f ) => f.text.trim() !== '' );
	const dirty = !! forms && JSON.stringify( forms.map( ( f ) => f.text ) ) !== loaded;
	const chip = STATE_CHIP[ item.state ];

	useEffect( () => {
		setDirty( item.key, dirty && ! busy );
		return () => setDirty( item.key, false );
	}, [ dirty, busy, item.key ] );

	const save = () => {
		setBusy( true );
		setRowError( null );
		// Send every form, empty included: the server deletes the empty ones
		// (per-form clear) and saves the rest.
		const map = {};
		forms.forEach( ( f ) => {
			map[ f.index ] = f.text;
		} );
		savePlural( item.key, lang, map )
			.then( () => {
				setBusy( false );
				setLoaded( JSON.stringify( forms.map( ( f ) => f.text ) ) );
				// The board row tracks the base form (0), matching childOn.
				const base = forms.find( ( f ) => f.index === 0 );
				const hasBase = !! base && base.text.trim() !== '';
				onSaved( {
					translation: hasBase ? base.text : '',
					state: hasBase ? 'done' : 'to_translate',
					updated_at: null,
					origin: hasBase ? 'human' : null,
				} );
			} )
			.catch( ( e ) => {
				setBusy( false );
				setRowError( errorMessage( e ) );
			} );
	};

	const clear = () => {
		setBusy( true );
		setRowError( null );
		deleteTranslation( item.key, lang )
			.then( () => {
				setBusy( false );
				setForms( ( fs ) => fs.map( ( f ) => ( { ...f, text: '' } ) ) );
				setLoaded( JSON.stringify( ( forms || [] ).map( () => '' ) ) );
				onSaved( { translation: '', state: 'to_translate', updated_at: null, origin: null } );
			} )
			.catch( ( e ) => {
				setBusy( false );
				setRowError( errorMessage( e ) );
			} );
	};

	const dontTranslate = () => {
		setBusy( true );
		ignoreSource( item.key )
			.then( () => onRemoved() )
			.catch( ( e ) => {
				setBusy( false );
				setRowError( errorMessage( e ) );
			} );
	};

	return (
		<div className="tl-pair tl-pair-nofield tl-plural">
			<div className="tl-plural-originals">
				<div className="tl-col tl-col-original" title={ item.original }>
					<span className="tl-plural-tag">singular</span>
					<Placeholders text={ item.original } />
				</div>
				<div className="tl-col tl-col-original" title={ item.plural }>
					<span className="tl-plural-tag">plural</span>
					<Placeholders text={ item.plural } />
				</div>
			</div>

			<div className="tl-pair-row">
				<div className="tl-plural-forms">
					{ ! forms && <div className="tl-loading">Loading…</div> }
					{ forms &&
						forms.map( ( f ) => (
							<div className="tl-plural-form" key={ f.index }>
								<label className="tl-plural-label">
									{ f.label } <span className="tl-plural-eg">e.g. { f.example }</span>
								</label>
								<AutoTextarea
									className="tl-textarea"
									rows={ 1 }
									dir={ dir }
									value={ f.text }
									disabled={ busy }
									placeholder="Add translation…"
									onChange={ ( e ) => setFormText( f.index, e.target.value ) }
								/>
							</div>
						) ) }
				</div>

				<div className="tl-pair-actions">
					{ chip && chip.cls !== 'new' && (
						<span className={ 'tl-state tl-state-' + chip.cls }>{ chip.label }</span>
					) }
					<button
						type="button"
						className="button button-primary"
						disabled={ busy || ! dirty || ! anyText }
						onClick={ save }
					>
						Valid
					</button>
					<RowMenu
						canAllLanguages={ false }
						canCopy={ false }
						canPreview={ false }
						onClear={ clear }
						onDontTranslate={ dontTranslate }
						hasTranslation={ anyText }
					/>
				</div>
			</div>

			{ rowError && <div className="tl-error tl-row-error">{ rowError }</div> }
		</div>
	);
}

function RowMenu( { canAllLanguages, canCopy, canPreview, onPreview, onAllLanguages, onCopyFrom, onClear, onDontTranslate, hasTranslation } ) {
	const [ open, setOpen ] = useState( false );
	const run = ( fn ) => () => {
		setOpen( false );
		fn();
	};

	return (
		<span className="tl-menu">
			<button
				type="button"
				className="button tl-menu-toggle"
				aria-haspopup="true"
				aria-expanded={ open }
				onClick={ () => setOpen( ( v ) => ! v ) }
			>
				⋯
			</button>
			{ open && (
				<>
					<div className="tl-menu-overlay" onClick={ () => setOpen( false ) } />
					<div className="tl-menu-list" role="menu">
						{ canPreview && (
							<button type="button" role="menuitem" onClick={ run( onPreview ) }>
								Preview on site
							</button>
						) }
						{ canAllLanguages && (
							<button type="button" role="menuitem" onClick={ run( onAllLanguages ) }>
								Translate all languages
							</button>
						) }
						{ canCopy && (
							<button type="button" role="menuitem" onClick={ run( onCopyFrom ) }>
								Copy from another language
							</button>
						) }
						{ hasTranslation && (
							<button type="button" role="menuitem" onClick={ run( onClear ) }>
								Clear translation
							</button>
						) }
						<button type="button" role="menuitem" className="tl-menu-danger" onClick={ run( onDontTranslate ) }>
							Don’t translate
						</button>
					</div>
				</>
			) }
		</span>
	);
}
