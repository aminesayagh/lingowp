import {
	ActionList,
	Badge,
	Banner,
	BlockStack,
	Box,
	Button,
	Card,
	Collapsible,
	Divider,
	EmptyState,
	InlineStack,
	Modal,
	Page,
	Popover,
	Spinner,
	Text,
	TextField,
} from '@shopify/polaris';
import { createPortal, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { AnimatePresence, motion } from 'motion/react';
import { api } from '../api';
import SettingsModal from './SettingsModal';
import AddLanguageModal from './AddLanguageModal';
import LanguageEditModal from './LanguageEditModal';
import CompareOverlay from './CompareOverlay';
import QuotaCard from './QuotaCard';

// Schema status → workspace pill (per design-ux-data-mapping.md §2). Translated
// rows carry no pill.
const STATUS_BADGE = {
	missing: { tone: 'attention', label: 'New' },
	needs_review: { tone: 'warning', label: 'Needs review' },
	reviewed: { tone: 'success', label: 'Reviewed' },
	translated: null,
};

// Collection type → group heading prefix. Site settings has no prefix.
function groupHeading( { type, label } ) {
	const prefix = { page: 'Pages', post: 'Posts', cpt: 'Custom', taxonomy: 'Taxonomies' }[ type ];
	return prefix ? `${ prefix } · ${ label }` : label;
}

const enc = encodeURIComponent;

export default function Dashboard( { view, defaultLanguage, onLanguageCreated } ) {
	const [ languages, setLanguages ] = useState( [] );
	const [ selectedLang, setSelectedLang ] = useState( '' );
	const [ collections, setCollections ] = useState( null );
	// expanded[id] = 'loading' | array of text units (undefined = collapsed).
	const [ expanded, setExpanded ] = useState( {} );
	const [ search, setSearch ] = useState( '' );
	const [ addOpen, setAddOpen ] = useState( false );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const [ overlay, setOverlay ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ editLang, setEditLang ] = useState( null );
	const [ ai, setAi ] = useState( { available: false, error: '' } );
	const [ quota, setQuota ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const defaultCode = defaultLanguage?.code || defaultLanguage;

	function loadQuota() {
		api.get( '/quota' ).then( ( res ) => setQuota( res.ok ? res.quota : null ) );
	}

	useEffect( () => {
		api.get( '/connection/status' ).then( ( res ) => {
			setAi( { available: !! res.ai_available, error: res.ai_error || '' } );
		} );
		loadQuota();
	}, [] );

	// AI translate a scope (text | collection | language), then refresh views + quota.
	async function translateScope( scope, opts = {} ) {
		if ( ! ai.available ) {
			setNotice( { tone: 'warning', content: ai.error || 'AI is unavailable. Connect Translangs to enable AI translation.' } );
			return;
		}
		const res = await api.post( '/translate', { scope, language: selectedLang, ...opts } );
		if ( res.error === 'ERR_QUOTA' ) {
			setNotice( {
				tone: 'warning',
				content: `Not enough words remaining (needs ~${ res.estimated_words }, ${ res.words_remaining } left). Upgrade to run this.`,
			} );
			return;
		}
		if ( ! res.ok ) {
			setNotice( { tone: 'critical', content: 'AI translation failed. Please try again.' } );
			return;
		}
		setNotice( { tone: 'success', content: `Translated ${ res.translated } text${ res.translated === 1 ? '' : 's' }.` } );
		loadCollections();
		Object.keys( expanded ).forEach( ( id ) => {
			if ( Array.isArray( expanded[ id ] ) ) {
				loadTexts( id );
			}
		} );
		loadQuota();
		refreshLanguages(); // rail percentages changed
	}

	// Rail "Translate All": select that language, confirm, then AI-translate it whole.
	function translateLanguageAll( lang ) {
		setSelectedLang( lang.code );
		setConfirm( {
			title: `AI translate all of ${ lang.display_name || lang.name || lang.code }?`,
			body: 'All untranslated text in this language will be sent to AI translation and may use a large number of words.',
			actionLabel: 'Translate all',
			onConfirm: () => translateScope( 'language', { language: lang.code } ),
		} );
	}

	function patchLangStatus( code, status ) {
		api.patch( `/languages/${ enc( code ) }`, { status } ).then( ( res ) => {
			if ( res.ok ) {
				refreshLanguages();
			}
		} );
	}

	function deleteLang( code ) {
		api.del( `/languages/${ enc( code ) }` ).then( ( res ) => {
			if ( res.ok ) {
				setSelectedLang( ( cur ) => ( cur === code ? '' : cur ) );
				refreshLanguages();
			}
		} );
	}

	function confirmDeleteLang( lang ) {
		setConfirm( {
			title: `Delete ${ lang.display_name || lang.name || lang.code }?`,
			body: 'This removes it from the public site and the workspace. Its translations are kept and restored if you re-add the language.',
			actionLabel: 'Delete',
			destructive: true,
			onConfirm: () => deleteLang( lang.code ),
		} );
	}

	function refreshLanguages() {
		api.get( '/languages' ).then( ( res ) => {
			if ( ! res.ok ) {
				return;
			}
			const langs = res.languages || [];
			setLanguages( langs );
			setSelectedLang( ( cur ) => {
				if ( cur && langs.some( ( l ) => l.code === cur ) ) {
					return cur;
				}
				const active = langs.find( ( l ) => l.enabled ) || langs[ 0 ];
				return active?.code || '';
			} );
		} );
	}

	useEffect( refreshLanguages, [] );

	function loadCollections() {
		api.get( `/collections?language=${ enc( selectedLang ) }` ).then( ( res ) => {
			setCollections( res.ok ? res.collections || [] : [] );
		} );
	}

	// Reload collections + reset expansion whenever the working language changes.
	useEffect( () => {
		if ( ! selectedLang ) {
			return;
		}
		setCollections( null );
		setExpanded( {} );
		loadCollections();
	}, [ selectedLang ] );

	function loadTexts( id ) {
		api.get( `/collections/${ enc( id ) }/texts?language=${ enc( selectedLang ) }` ).then( ( res ) => {
			setExpanded( ( c ) => ( { ...c, [ id ]: res.ok ? res.texts || [] : [] } ) );
		} );
	}

	function toggleGroup( id ) {
		setExpanded( ( cur ) => {
			if ( cur[ id ] !== undefined ) {
				const next = { ...cur };
				delete next[ id ];
				return next;
			}
			loadTexts( id );
			return { ...cur, [ id ]: 'loading' };
		} );
	}

	// After a mutation on a unit: refresh that group's texts (if open) + counts.
	function afterMutate( cid ) {
		if ( expanded[ cid ] !== undefined ) {
			loadTexts( cid );
		}
		loadCollections();
	}

	// Empty-language state: same shell, centered CTA (wireframe 1.3).
	if ( view === 'dashboard_empty_language' && languages.length === 0 ) {
		return (
			<Page title="Translangs" fullWidth>
				<Card>
					<EmptyState
						heading="Add your first language"
						action={ { content: 'Create a language', onAction: () => setAddOpen( true ) } }
					>
						<p>Your site is ready. Create a language to start translating your content.</p>
						<p>Need more later? Add them from the sidebar anytime.</p>
					</EmptyState>
				</Card>
				<AddLanguageModal
					open={ addOpen }
					defaultCode={ defaultCode }
					existing={ languages.map( ( l ) => l.code ) }
					onClose={ () => setAddOpen( false ) }
					onCreated={ () => {
						setAddOpen( false );
						refreshLanguages();
						onLanguageCreated();
					} }
				/>
			</Page>
		);
	}

	const filtered = ( collections || [] ).filter( ( c ) =>
		groupHeading( c ).toLowerCase().includes( search.toLowerCase() )
	);
	const totalTexts = ( collections || [] ).reduce( ( n, c ) => n + c.total, 0 );
	const totalDone = ( collections || [] ).reduce( ( n, c ) => n + c.translated, 0 );

	return (
		<Page
			title="Translangs"
			primaryAction={ { content: 'Add language', onAction: () => setAddOpen( true ) } }
			secondaryActions={ [ { content: 'Settings', onAction: () => setSettingsOpen( true ) } ] }
			fullWidth
		>
			{ notice && (
				<Box paddingBlockEnd="300">
					<Banner tone={ notice.tone } onDismiss={ () => setNotice( null ) }>{ notice.content }</Banner>
				</Box>
			) }
			<InlineStack gap="400" align="start" blockAlign="start" wrap={ false }>
				{ /* ── Left rail ─────────────────────────────────────────── */ }
				<Box width="260px">
					<BlockStack gap="400">
						<Card>
							<BlockStack gap="200">
								<Text as="h2" variant="headingSm">Target language</Text>
								<div style={ { display: 'flex', flexDirection: 'column', gap: 0 } }>
									{ languages.map( ( lang ) => (
										<LanguageRow
											key={ lang.code }
											lang={ lang }
											selected={ lang.code === selectedLang }
											percent={ lang.translated_percent ?? 0 }
											aiAvailable={ ai.available }
											onSelect={ () => setSelectedLang( lang.code ) }
											onEdit={ () => setEditLang( lang ) }
											onToggleStatus={ () => patchLangStatus( lang.code, lang.enabled ? 'draft' : 'active' ) }
											onTranslateAll={ () => translateLanguageAll( lang ) }
											onDelete={ () => confirmDeleteLang( lang ) }
										/>
									) ) }
								</div>
								<Button variant="plain"  onClick={ () => setAddOpen( true ) }>+ Add language</Button>
							</BlockStack>
						</Card>

						<Card>
							<BlockStack gap="100">
								<Text as="h2" variant="headingSm">Sources</Text>
								{ /* ponytail: v1 has post + option only; per-source-type
								     totals land when the other 4 types are wired. */ }
								<InlineStack align="space-between">
									<Text as="span" variant="bodySm">Content</Text>
									<Text as="span" tone="subdued" variant="bodySm">{ totalTexts }</Text>
								</InlineStack>
							</BlockStack>
						</Card>

						<QuotaCard quota={ quota } aiAvailable={ ai.available } />
					</BlockStack>
				</Box>

				{ /* ── Main ──────────────────────────────────────────────── */ }
				<Box width="100%">
					<Card padding="0">
						<Box padding="300">
							<InlineStack gap="200" align="space-between" blockAlign="center" wrap={ false }>
								<Box width="100%">
									<TextField
										label="Search text"
										labelHidden
										placeholder="Search text…"
										value={ search }
										onChange={ setSearch }
										autoComplete="off"
									/>
								</Box>
								<Button
									disabled={ ! ai.available }
									onClick={ () => setConfirm( {
										title: 'AI translate all missing text?',
										body: 'This sends every untranslated text in the selected language to AI translation and may use a large number of words.',
										actionLabel: 'Translate all',
										onConfirm: () => translateScope( 'language' ),
									} ) }
								>⚡Translate all</Button>
							</InlineStack>
						</Box>
						<Divider />

						{ collections === null ? (
							<Box padding="500"><Spinner accessibilityLabel="Loading content" size="small" /></Box>
						) : filtered.length === 0 ? (
							<Box padding="500">
								<Text as="p" tone="subdued">No translatable content found for this language yet.</Text>
							</Box>
						) : (
							<BlockStack gap="0">
								{ filtered.map( ( c ) => (
									<CollectionGroup
										key={ c.id }
										collection={ c }
										language={ selectedLang }
										open={ expanded[ c.id ] !== undefined }
										texts={ Array.isArray( expanded[ c.id ] ) ? expanded[ c.id ] : null }
										loading={ expanded[ c.id ] === 'loading' }
										onToggle={ () => toggleGroup( c.id ) }
										onMutate={ () => afterMutate( c.id ) }
										onConfirm={ setConfirm }
										aiAvailable={ ai.available }
										onAiTranslate={ translateScope }
										onOpenOverlay={ ( unit ) =>
											setOverlay( { unit, cid: c.id, fieldLabel: `${ groupHeading( c ) } — ${ unit.field }` } )
										}
									/>
								) ) }
							</BlockStack>
						) }

						{ collections !== null && (
							<>
								<Divider />
								<Box padding="300">
									<Text as="p" alignment="end" tone="subdued" variant="bodySm">
										{ totalDone } of { totalTexts } translated
									</Text>
								</Box>
							</>
						) }
					</Card>
				</Box>
			</InlineStack>

			<AddLanguageModal
				open={ addOpen }
				defaultCode={ defaultCode }
				existing={ languages.map( ( l ) => l.code ) }
				onClose={ () => setAddOpen( false ) }
				onCreated={ () => {
					setAddOpen( false );
					refreshLanguages();
					onLanguageCreated();
				} }
			/>
			<SettingsModal
				open={ settingsOpen }
				defaultLanguage={ defaultCode }
				onClose={ () => setSettingsOpen( false ) }
			/>
			<LanguageEditModal
				open={ !! editLang }
				lang={ editLang }
				onClose={ () => setEditLang( null ) }
				onSaved={ refreshLanguages }
			/>
			<CompareOverlay
				open={ !! overlay }
				unit={ overlay?.unit }
				fieldLabel={ overlay?.fieldLabel }
				language={ selectedLang }
				aiAvailable={ ai.available }
				onAiTranslate={ translateScope }
				onClose={ () => setOverlay( null ) }
				onSaved={ () => overlay && afterMutate( overlay.cid ) }
			/>
			<ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
		</Page>
	);
}

// Country code → flag emoji (regional indicator letters). Falls back per language
// code, then a globe.
const REGION_FALLBACK = { fr: 'FR', es: 'ES', de: 'DE', it: 'IT', pt: 'PT', nl: 'NL', ar: 'MA', en: 'US' };

function flagEmoji( lang ) {
	let cc = ( lang.region || '' ).trim();
	if ( cc.length !== 2 && lang.locale ) {
		cc = ( lang.locale.split( /[_-]/ )[ 1 ] || '' ).trim();
	}
	if ( cc.length !== 2 ) {
		cc = REGION_FALLBACK[ lang.code ] || '';
	}
	if ( cc.length !== 2 ) {
		return '🌐';
	}
	const base = 0x1f1e6;
	const up = cc.toUpperCase();
	return String.fromCodePoint( base + up.charCodeAt( 0 ) - 65, base + up.charCodeAt( 1 ) - 65 );
}

// Animated dropdown (motion): a gap below the activator + a fade/slide on open,
// with click-outside to close. Portaled to <body> and fixed-positioned off the
// activator so no ancestor (e.g. a Polaris Card's overflow) can clip it. Reusable;
// the language rail is the first user.
function AnimatedMenu( { open, onClose, activator, children } ) {
	const anchorRef = useRef( null );
	const menuRef = useRef( null );
	const [ pos, setPos ] = useState( null );

	const place = useCallback( () => {
		const el = anchorRef.current;
		if ( el ) {
			const r = el.getBoundingClientRect();
			// Right-align the menu to the activator, 8px below it.
			setPos( { top: r.bottom + 8, right: window.innerWidth - r.right } );
		}
	}, [] );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		place();
		function onDoc( e ) {
			if ( anchorRef.current?.contains( e.target ) || menuRef.current?.contains( e.target ) ) {
				return;
			}
			onClose();
		}
		document.addEventListener( 'mousedown', onDoc );
		window.addEventListener( 'resize', place );
		window.addEventListener( 'scroll', place, true ); // capture: any scroll container
		return () => {
			document.removeEventListener( 'mousedown', onDoc );
			window.removeEventListener( 'resize', place );
			window.removeEventListener( 'scroll', place, true );
		};
	}, [ open, onClose, place ] );

	return (
		<>
			<span ref={ anchorRef } style={ { display: 'inline-flex' } }>{ activator }</span>
			{ createPortal(
				<AnimatePresence>
					{ open && pos && (
						<motion.div
							ref={ menuRef }
							initial={ { opacity: 0, y: -6 } }
							animate={ { opacity: 1, y: 0 } }
							exit={ { opacity: 0, y: -6 } }
							transition={ { duration: 0.15, ease: 'easeOut' } }
							style={ {
								position: 'fixed',
								top: pos.top,
								right: pos.right,
								zIndex: 520, // above Polaris cards; below modal backdrop
								minWidth: 168,
								background: 'var(--p-color-bg-surface, #fff)',
								borderRadius: 'var(--p-border-radius-300, 12px)',
								boxShadow: 'var(--p-shadow-400, 0 6px 16px rgba(0,0,0,0.16))',
								border: '1px solid var(--p-color-border-secondary, rgba(0,0,0,0.08))',
								overflow: 'hidden',
							} }
						>
							{ children }
						</motion.div>
					) }
				</AnimatePresence>,
				document.body
			) }
		</>
	);
}

function LanguageRow( { lang, selected, percent, aiAvailable, onSelect, onEdit, onToggleStatus, onTranslateAll, onDelete } ) {
	const [ hover, setHover ] = useState( false );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const showMenu = hover || menuOpen;
	const name = ( lang.display_name || lang.name || lang.code ) + ( lang.enabled ? '' : ' (Draft)' );

	const items = [
		{ content: 'Settings', onAction: () => { setMenuOpen( false ); onEdit(); } },
		{ content: lang.enabled ? 'Set to draft' : 'Publish', onAction: () => { setMenuOpen( false ); onToggleStatus(); } },
		percent < 100 && {
			content: 'Translate All',
			disabled: ! aiAvailable,
			onAction: () => { setMenuOpen( false ); onTranslateAll(); },
		},
		{ content: 'Delete', destructive: true, onAction: () => { setMenuOpen( false ); onDelete(); } },
	].filter( Boolean );

	return (
		<div
			onMouseEnter={ () => setHover( true ) }
			onMouseLeave={ () => setHover( false ) }
			onClick={ onSelect }
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: 8,
				height: 32,
				paddingInline: 8,
				borderRadius: 8,
				cursor: 'pointer',
				background: selected ? 'var(--p-color-bg-surface-selected, rgba(0,0,0,0.06))' : 'transparent',
			} }
		>
			<span style={ { fontSize: 16, lineHeight: 1 } }>{ flagEmoji( lang ) }</span>
			<span style={ { flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
				<Text as="span" variant="bodyMd">{ name }</Text>
			</span>
			{ /* Fixed-size slot so swapping %↔⋯ never changes the row height/width.
			     AnimatedMenu stays mounted (activator swaps) so the close animation plays. */ }
			<div
				onClick={ ( e ) => e.stopPropagation() }
				style={ { width: 36, height: 24, display: 'flex', alignItems: 'center', justifyContent: 'flex-end' } }
			>
				<AnimatedMenu
					open={ menuOpen }
					onClose={ () => setMenuOpen( false ) }
					activator={
						showMenu ? (
							<Button variant="tertiary" size="micro" onClick={ () => setMenuOpen( ( o ) => ! o ) }>⋯</Button>
						) : (
							<Text as="span" tone="subdued" variant="bodySm">{ percent }%</Text>
						)
					}
				>
					<ActionList items={ items } />
				</AnimatedMenu>
			</div>
		</div>
	);
}

function ConfirmDialog( { confirm, onClose } ) {
	if ( ! confirm ) {
		return null;
	}
	return (
		<Modal
			open
			onClose={ onClose }
			title={ confirm.title }
			primaryAction={ {
				content: confirm.actionLabel,
				destructive: confirm.destructive,
				onAction: () => {
					confirm.onConfirm();
					onClose();
				},
			} }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section><Text as="p">{ confirm.body }</Text></Modal.Section>
		</Modal>
	);
}

function CollectionGroup( { collection, language, open, texts, loading, onToggle, onMutate, onConfirm, onOpenOverlay, aiAvailable, onAiTranslate } ) {
	const c = collection;
	const [ menuOpen, setMenuOpen ] = useState( false );

	function confirmAiGroup() {
		onConfirm( {
			title: 'AI translate this group?',
			body: 'Untranslated text in this group will be sent to AI translation.',
			actionLabel: 'Translate',
			onConfirm: () => onAiTranslate( 'collection', { id: c.id } ),
		} );
	}

	async function bulk( action ) {
		setMenuOpen( false );
		const res = await api.post( `/collections/${ enc( c.id ) }/${ action }`, { language } );
		if ( res.ok ) {
			onMutate();
		}
	}

	function confirmIgnoreAll() {
		onConfirm( {
			title: 'Ignore all texts in this group?',
			body: 'Every text in this group will be excluded from translation and moved to the Ignored view.',
			actionLabel: 'Ignore all',
			destructive: true,
			onConfirm: () => bulk( 'ignore' ),
		} );
	}

	function confirmClearAll() {
		onConfirm( {
			title: 'Clear all translations in this group?',
			body: 'Every translation in this group for the selected language will be removed.',
			actionLabel: 'Clear all',
			destructive: true,
			onConfirm: () => bulk( 'clear' ),
		} );
	}

	return (
		<Box>
			<Box padding="300" background={ open ? 'bg-surface-secondary' : undefined }>
				<InlineStack align="space-between" blockAlign="center" wrap={ false }>
					<button
						type="button"
						onClick={ onToggle }
						style={ { all: 'unset', cursor: 'pointer', flex: 1 } }
					>
						<Text as="span" variant="bodyMd" fontWeight="medium">
							{ ( open ? '▾ ' : '▸ ' ) + groupHeading( c ) }
						</Text>
					</button>
					<InlineStack gap="200" blockAlign="center" wrap={ false }>
						<Text as="span" tone="subdued" variant="bodySm">
							{ c.total } texts · { c.translated } translated
						</Text>
						<Button size="micro" disabled={ ! aiAvailable } onClick={ confirmAiGroup }>⚡ Translate all</Button>
						<Popover
							active={ menuOpen }
							onClose={ () => setMenuOpen( false ) }
							activator={
								<Button variant="tertiary" size="micro" onClick={ () => setMenuOpen( ( o ) => ! o ) }>⋯</Button>
							}
						>
							<ActionList
								items={ [
									{ content: 'Ignore all', destructive: true, onAction: confirmIgnoreAll },
									{ content: 'Clear all', destructive: true, onAction: confirmClearAll },
								] }
							/>
						</Popover>
					</InlineStack>
				</InlineStack>
			</Box>
			<Collapsible open={ open } id={ `col-${ c.id }` }>
				<Box paddingBlockEnd="200">
					{ loading || texts === null ? (
						<Box padding="300"><Spinner accessibilityLabel="Loading texts" size="small" /></Box>
					) : (
						texts.map( ( t ) => (
							<TextUnitRow
								key={ t.id }
								unit={ t }
								language={ language }
								onMutate={ onMutate }
								onConfirm={ onConfirm }
								aiAvailable={ aiAvailable }
								onAiTranslate={ onAiTranslate }
								onOpenOverlay={ onOpenOverlay }
							/>
						) )
					) }
				</Box>
			</Collapsible>
			<Divider />
		</Box>
	);
}

function TextUnitRow( { unit, language, onMutate, onConfirm, onOpenOverlay, aiAvailable, onAiTranslate } ) {
	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const [ copyOpen, setCopyOpen ] = useState( false );

	const badge = STATUS_BADGE[ unit.status ];
	const shown = unit.translated ?? unit.fallback;
	const hasTranslation = unit.translated !== null && unit.translated !== undefined;

	function startEdit() {
		setDraft( unit.translated ?? '' );
		setEditing( true );
	}

	async function save() {
		setSaving( true );
		const res = await api.post( '/translations', { text_unit_id: unit.id, language, text: draft } );
		setSaving( false );
		if ( res.ok ) {
			setEditing( false );
			onMutate();
		}
	}

	async function doStatus( action ) {
		setMenuOpen( false );
		const res = await api.post( `/texts/${ enc( unit.id ) }/status`, { action, language } );
		if ( res.ok ) {
			onMutate();
		}
	}

	function confirmClear() {
		onConfirm( {
			title: 'Clear translation?',
			body: 'This removes the translation for this text in the selected language.',
			actionLabel: 'Clear',
			destructive: true,
			onConfirm: () => doStatus( 'clear' ),
		} );
	}

	function confirmIgnore() {
		onConfirm( {
			title: 'Ignore this text?',
			body: 'Ignored text is excluded from translation and moves to the Ignored view.',
			actionLabel: 'Ignore',
			destructive: true,
			onConfirm: () => doStatus( 'ignore' ),
		} );
	}

	function copyOriginal() {
		setCopyOpen( false );
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( unit.original );
		}
	}

	const menuItems = [
		aiAvailable && { content: 'AI translate', onAction: () => { setMenuOpen( false ); onAiTranslate( 'text', { ref: unit.id } ); } },
		{ content: 'Open in overlay', onAction: () => { setMenuOpen( false ); onOpenOverlay( unit ); } },
		hasTranslation && { content: 'To review', onAction: () => doStatus( 'review' ) },
		hasTranslation && { content: 'Clear', destructive: true, onAction: () => { setMenuOpen( false ); confirmClear(); } },
		{ content: 'Ignore', destructive: true, onAction: () => { setMenuOpen( false ); confirmIgnore(); } },
	].filter( Boolean );

	return (
		<Box paddingInline="300" paddingBlock="200">
			<InlineStack gap="300" align="space-between" blockAlign="start" wrap={ false }>
				<Box width="42%">
					<Popover
						active={ copyOpen }
						onClose={ () => setCopyOpen( false ) }
						activator={
							<button
								type="button"
								onClick={ () => setCopyOpen( true ) }
								style={ { all: 'unset', cursor: 'pointer', display: 'block' } }
							>
								<Text as="span" variant="bodySm">{ unit.original }</Text>
							</button>
						}
					>
						<ActionList items={ [ { content: 'Copy', onAction: copyOriginal } ] } />
					</Popover>
				</Box>

				<Box width="42%">
					{ editing ? (
						<BlockStack gap="100">
							<TextField
								label="Translation"
								labelHidden
								multiline={ 2 }
								value={ draft }
								onChange={ setDraft }
								autoComplete="off"
							/>
							<InlineStack gap="100">
								<Button size="micro" variant="primary" loading={ saving } onClick={ save }>Save</Button>
								<Button size="micro" onClick={ () => setEditing( false ) }>Cancel</Button>
							</InlineStack>
						</BlockStack>
					) : (
						<button
							type="button"
							onClick={ startEdit }
							style={ { all: 'unset', cursor: 'text', display: 'block', width: '100%' } }
						>
							<Text as="span" variant="bodySm" tone={ hasTranslation ? undefined : 'subdued' }>
								{ shown }
							</Text>
						</button>
					) }
				</Box>

				<Box width="16%">
					<InlineStack gap="100" align="end" blockAlign="center" wrap={ false }>
						{ unit.is_ai && <Badge size="small">AI</Badge> }
						{ badge && <Badge tone={ badge.tone } size="small">{ badge.label }</Badge> }
						<Popover
							active={ menuOpen }
							onClose={ () => setMenuOpen( false ) }
							activator={
								<Button variant="tertiary" size="micro" onClick={ () => setMenuOpen( ( o ) => ! o ) }>⋯</Button>
							}
						>
							<ActionList items={ menuItems } />
						</Popover>
					</InlineStack>
				</Box>
			</InlineStack>
		</Box>
	);
}
