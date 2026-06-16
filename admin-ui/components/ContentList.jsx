import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	getItems,
	getCounts,
	saveTranslation,
	deleteTranslation,
	ignoreSource,
	getPreview,
	getDuplicates,
	runScan,
	errorMessage,
} from '../api';
import Placeholders from './Placeholders';
import AutoTextarea from './AutoTextarea';
import AllLanguages from './AllLanguages';
import ApplyEverywhere from './ApplyEverywhere';

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

export default function ContentList( { config, area, lang, filters, onCountsChanged } ) {
	const [ tab, setTab ] = useState( 'to_translate' );

	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ scanning, setScanning ] = useState( false );

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
		if ( data && data.next_cursor ) {
			setStack( ( s ) => [ ...s, cursor ] );
			setCursor( data.next_cursor );
			load( data.next_cursor );
		}
	};

	const prev = () => {
		const s = [ ...stack ];
		const p = s.pop() || null;
		setStack( s );
		setCursor( p );
		load( p );
	};

	const check = () => {
		setScanning( true );
		runScan( area )
			.then( () => {
				setScanning( false );
				load( cursor );
			} )
			.catch( () => setScanning( false ) );
	};

	const counts = ( data && data.counts ) || { to_translate: 0, to_review: 0, done: 0, total: 0 };
	const areaLabel = ( config.content_areas.find( ( a ) => a.key === area ) || {} ).label || area;

	return (
		<div className="tl-list">
			<div className="tl-list-head">
				<h2>{ areaLabel }</h2>
				<button type="button" className="button" disabled={ scanning } onClick={ check }>
					{ scanning ? 'Checking…' : 'Check for new text' }
				</button>
			</div>

			<div className="tl-tabs">
				{ TABS.map( ( [ k, label ] ) => (
					<button
						key={ k }
						type="button"
						className={ 'tl-tab' + ( tab === k ? ' is-active' : '' ) }
						onClick={ () => setTab( k ) }
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
							{ g.owner.label } <span className="tl-kind">({ g.owner.kind })</span>
						</div>
						{ g.items.map( ( it ) => (
							<InlineRow
								key={ it.key }
								item={ it }
								lang={ lang }
								dir={ dir }
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

function InlineRow( { item, lang, dir, multiLang, onSaved, onRemoved, onModal } ) {
	const [ text, setText ] = useState( item.translation || '' );
	const [ busy, setBusy ] = useState( false );
	const [ rowError, setRowError ] = useState( null );

	// Keep the field in sync if the row is patched from elsewhere.
	useEffect( () => {
		setText( item.translation || '' );
	}, [ item.translation ] );

	const chip = STATE_CHIP[ item.state ];
	const dirty = text !== ( item.translation || '' );

	const save = ( value ) => {
		setBusy( true );
		setRowError( null );
		saveTranslation( item.key, lang, { text: value, mark: 'reviewed', updated_at: item.updated_at || '' } )
			.then( ( res ) => {
				setBusy( false );
				const s = res.string;
				onSaved( {
					translation: s ? s.translation : value,
					state: s ? s.state : 'done',
					updated_at: s ? s.updated_at : null,
					origin: 'human',
				} );
				// Same text elsewhere? Offer to reuse it.
				getDuplicates( item.key, lang )
					.then( ( dups ) => {
						if ( dups.length > 0 ) {
							onModal( { type: 'apply', key: item.key, text: value } );
						}
					} )
					.catch( () => {} );
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
		getPreview( item.key, lang )
			.then( ( res ) => {
				if ( res.url ) {
					window.open( res.url, '_blank', 'noopener' );
				} else {
					setRowError( 'No preview available for this string.' );
				}
			} )
			.catch( ( e ) => setRowError( errorMessage( e ) ) );
	};

	return (
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
						canPreview={ item.preview !== false }
						canAllLanguages={ multiLang }
						onPreview={ preview }
						onAllLanguages={ () => onModal( { type: 'all', key: item.key } ) }
						onClear={ clear }
						onDontTranslate={ dontTranslate }
						hasTranslation={ ( item.translation || '' ) !== '' }
					/>
				</div>
			</div>

			{ rowError && <div className="tl-error tl-row-error">{ rowError }</div> }
		</div>
	);
}

function RowMenu( { canPreview, canAllLanguages, onPreview, onAllLanguages, onClear, onDontTranslate, hasTranslation } ) {
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
