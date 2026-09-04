import {
	Box,
	BlockStack,
	Button,
	Divider,
	InlineStack,
	Select,
	Spinner,
	Text,
	TextField,
} from '@shopify/polaris'
import { XSmallIcon } from '@shopify/polaris-icons'
import { useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'
import { FlagIcon, flagIconUrl } from './FlagIcon'
import {
	AI_STYLE_MAX,
	AI_STYLE_PLACEHOLDER,
	aiModelOptions,
} from './LanguageManagerModal'

const SEARCH_INPUT_ID = 'lingowp-add-languages-search'
const STORAGE_KEY = 'lingowp-add-languages-selection'

// The in-progress pick survives both closing/reopening this dialog and a full
// page refresh — persisted to localStorage (per-site, since it's scoped to
// this origin), not just lifted to a parent component's state, which only
// covers the first case. Cleared once a batch is actually submitted.
function loadSelection() {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY )
		const obj = raw ? JSON.parse( raw ) : null
		if ( ! obj ) {
			return new Map()
		}
		return new Map(
			Object.entries( obj ).map( ( [ language, locales ] ) => [
				language,
				new Set( locales ),
			] )
		)
	} catch {
		return new Map()
	}
}

function saveSelection( selection ) {
	try {
		if ( selection.size === 0 ) {
			window.localStorage.removeItem( STORAGE_KEY )
			return
		}
		const obj = {}
		for ( const [ language, locales ] of selection ) {
			obj[ language ] = Array.from( locales )
		}
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( obj ) )
	} catch {
		// Private browsing / storage disabled — the pick just won't survive
		// a refresh, which is an acceptable degrade, not worth surfacing.
	}
}

// Same region-label shape as LanguageManagerModal.jsx's own regionLabel() —
// duplicated rather than exported, since this is the only other caller and
// it's a three-line pure function. '' (not "General") when the locale
// carries no real region and no variant — callers show the language name
// alone in that case.
function regionLabel( item ) {
	let region = item.region_name || item.region || ''
	if (
		item.region &&
		( ! item.region_name || item.region_name === item.region )
	) {
		try {
			region =
				new Intl.DisplayNames( undefined, { type: 'region' } ).of(
					item.region
				) || item.region
		} catch {
			region = item.region
		}
	}
	if ( ! region ) {
		return item.variant_name || ''
	}
	return item.variant_name ? `${ region } — ${ item.variant_name }` : region
}

// Groups the flat WordPress language catalog by base language ("en", "fr",
// …) — same shape LanguageForm's own `languages` grouping uses in
// LanguageManagerModal.jsx. No `existing`-languages exclusion: this only
// ever renders for onboarding's very first language, so nothing but the
// site's own source language needs excluding.
function groupCatalog( catalogItems, defaultCode ) {
	const groups = new Map()
	for ( const item of catalogItems ) {
		if ( item.locale === defaultCode ) {
			continue
		}
		const group = groups.get( item.language ) || {
			language: item.language,
			label: item.language_name,
			items: [],
		}
		group.items.push( item )
		groups.set( item.language, group )
	}
	return Array.from( groups.values() ).sort( ( a, b ) =>
		a.label.localeCompare( b.label )
	)
}

// One not-yet-added region on the left — one row per locale, added individually
// (a language with several regions contributes several of these). Clicking it
// moves just that region to the right column.
function LeftRow( { group, item, onAdd } ) {
	const region = regionLabel( item )
	const label = region ? `${ group.label } (${ region })` : group.label
	return (
		<div
			role="button"
			tabIndex={ 0 }
			onClick={ () => onAdd( group, item ) }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault()
					onAdd( group, item )
				}
			} }
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: 10,
				minHeight: 36,
				padding: '8px 10px',
				borderRadius: 8,
				cursor: 'pointer',
			} }
		>
			<FlagIcon
				src={ flagIconUrl( { ...item, code: group.language } ) }
				size={ 16 }
			/>
			<div style={ { flex: 1, minWidth: 0 } }>
				<Text as="span" fontWeight="semibold" truncate>
					{ label }
				</Text>
			</div>
		</div>
	)
}

// One added region, its own removable row on the right — a language with 3
// regions contributes 3 of these, independent of its siblings; removing the
// last one for a language returns that language to the left column.
function RightRow( { group, item, onRemove } ) {
	const region = regionLabel( item )
	const label = region ? `${ group.label } — ${ region }` : group.label

	return (
		<div
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: 10,
				minHeight: 36,
				padding: '8px 10px',
			} }
		>
			<FlagIcon
				src={ flagIconUrl( { ...item, code: group.language } ) }
				size={ 16 }
			/>
			<div style={ { flex: 1, minWidth: 0 } }>
				<Text as="span" fontWeight="semibold" truncate>
					{ label }
				</Text>
			</div>
			<Button
				icon={ XSmallIcon }
				variant="tertiary"
				size="micro"
				accessibilityLabel={ `Remove ${ label }` }
				onClick={ () => onRemove( group.language, item.locale ) }
			/>
		</div>
	)
}

// Short, user-facing reason for a locale the backend didn't create — mirrors
// the reason codes bulkCreate() (LanguageRestController.php) attaches to
// each skipped entry.
const SKIP_REASON_LABELS = {
	already_exists: 'already added',
	unknown_locale: 'not recognized',
	language_mismatch: 'not recognized',
	slug_taken: 'its web address is already used',
	invalid_locale: 'not recognized',
}

export default function AddLanguagesPicker( {
	defaultCode,
	onDone,
	onCreated,
} ) {
	const [ catalog, setCatalog ] = useState( [] )
	const [ catalogStatus, setCatalogStatus ] = useState( 'loading' )
	const [ catalogReload, setCatalogReload ] = useState( 0 )
	const [ aiEngines, setAiEngines ] = useState( {
		hasSubscription: false,
		providerKeys: [],
	} )
	// The whole pick, client-side only until "Add languages" is submitted —
	// hydrated from and mirrored to localStorage (see loadSelection/
	// saveSelection above), so closing and reopening this dialog, or
	// refreshing the page entirely, picks up right where the user left off.
	const [ selection, setSelection ] = useState( loadSelection )
	useEffect( () => {
		saveSelection( selection )
	}, [ selection ] )
	const [ search, setSearch ] = useState( '' )
	const [ aiModelOverride, setAiModelOverride ] = useState( null )
	const [ style, setStyle ] = useState( '' )
	const [ creating, setCreating ] = useState( false )
	const [ error, setError ] = useState( '' )
	// Set only when a submit partially succeeded — some locales were created,
	// others skipped server-side (already added elsewhere, slug taken, …).
	// The modal stays open in that case instead of closing as if everything
	// went through, so the user actually sees what happened.
	const [ partialNotice, setPartialNotice ] = useState( '' )

	// A row is keyboard-focusable on its own (Enter/Space adds it) but isn't
	// a text input, and when the picker first opens nothing inside it has
	// focus at all — so a plain onKeyDown scoped to the row list only ever
	// catches a keystroke once something inside it already has focus. Listen
	// on the document instead: redirect any plain, unmodified character key,
	// wherever it lands, into the search field — focus it and feed it the
	// character just typed, rather than making the user notice and click the
	// search box first.
	// preventDefault() matters here: without it, the same keystroke's native
	// default action still applies to the input *after* focus lands on it
	// (a real browser quirk), inserting the character a second time on top
	// of the one this handler already added to `search` itself.
	useEffect( () => {
		function onKeyDown( event ) {
			if (
				event.key.length !== 1 ||
				event.ctrlKey ||
				event.metaKey ||
				event.altKey ||
				event.target.tagName === 'INPUT' ||
				event.target.tagName === 'TEXTAREA'
			) {
				return
			}
			event.preventDefault()
			setSearch( ( prev ) => prev + event.key )
			document.getElementById( SEARCH_INPUT_ID )?.focus()
		}
		// Capture phase: fires before anything else gets a chance to act on
		// the keystroke first (Polaris's own modal focus machinery included),
		// rather than waiting for it to bubble all the way up.
		document.addEventListener( 'keydown', onKeyDown, true )
		return () => document.removeEventListener( 'keydown', onKeyDown, true )
	}, [] )

	useEffect( () => {
		let stillMounted = true
		setCatalogStatus( 'loading' )
		api.get( '/languages/catalog' ).then( ( res ) => {
			if ( ! stillMounted ) {
				return
			}
			if ( ! res.ok || ! Array.isArray( res.catalog ) ) {
				setCatalogStatus( 'error' )
				return
			}
			setCatalog( res.catalog )
			setCatalogStatus( 'ready' )
		} )
		return () => {
			stillMounted = false
		}
	}, [ catalogReload ] )

	// A selection restored from localStorage can reference a language/locale
	// the catalog no longer has (stale data, or it changed) — prune it once
	// the catalog is actually loaded, so the counter and submit payload never
	// include something the right column can't even show.
	useEffect( () => {
		if ( catalogStatus !== 'ready' ) {
			return
		}
		const validGroups = groupCatalog( catalog, defaultCode )
		setSelection( ( prev ) => {
			let changed = false
			const next = new Map()
			for ( const [ language, locales ] of prev ) {
				const group = validGroups.find(
					( g ) => g.language === language
				)
				const validLocales = new Set(
					( group?.items || [] ).map( ( item ) => item.locale )
				)
				const kept = new Set(
					Array.from( locales ).filter( ( locale ) =>
						validLocales.has( locale )
					)
				)
				if ( kept.size !== locales.size ) {
					changed = true
				}
				if ( kept.size > 0 ) {
					next.set( language, kept )
				}
			}
			return changed ? next : prev
		} )
	}, [ catalogStatus, catalog, defaultCode ] )

	useEffect( () => {
		Promise.all( [
			api.get( '/provider-keys' ),
			api.get( '/billing/entitlements' ),
		] ).then( ( [ keysRes, entitlementsRes ] ) => {
			setAiEngines( {
				hasSubscription: !! entitlementsRes.entitlements?.active,
				providerKeys: Array.isArray( keysRes.keys ) ? keysRes.keys : [],
			} )
		} )
	}, [] )

	const groups = groupCatalog( catalog, defaultCode )
	// Every region of a language that isn't already on the right.
	function remainingLocales( group ) {
		const already = selection.get( group.language )
		if ( ! already ) {
			return group.items
		}
		return group.items.filter( ( item ) => ! already.has( item.locale ) )
	}
	// Flat list of every selectable region, one entry per locale, sorted by
	// language then region so a language's regions stay adjacent.
	const availableLocales = []
	for ( const group of groups ) {
		for ( const item of remainingLocales( group ) ) {
			availableLocales.push( { group, item } )
		}
	}
	availableLocales.sort( ( a, b ) => {
		const byLanguage = a.group.label.localeCompare( b.group.label )
		return byLanguage !== 0
			? byLanguage
			: regionLabel( a.item ).localeCompare( regionLabel( b.item ) )
	} )
	const term = search.trim().toLowerCase()
	const rows = availableLocales.filter(
		( { group, item } ) =>
			! term ||
			`${ group.label } ${ group.language } ${ regionLabel( item ) }`
				.toLowerCase()
				.includes( term )
	)

	// Flat list of every added region, one row each — languages alphabetical
	// by name (not insertion order), each one's own regions kept together.
	const selectedRows = []
	const sortedSelection = Array.from( selection.entries() ).sort(
		( [ a ], [ b ] ) => {
			const labelOf = ( language ) =>
				groups.find( ( g ) => g.language === language )?.label ||
				language
			return labelOf( a ).localeCompare( labelOf( b ) )
		}
	)
	for ( const [ language, locales ] of sortedSelection ) {
		const group = groups.find( ( g ) => g.language === language )
		if ( ! group ) {
			continue
		}
		for ( const locale of locales ) {
			const item = group.items.find( ( i ) => i.locale === locale )
			if ( item ) {
				selectedRows.push( { group, item } )
			}
		}
	}

	function addLocale( group, item ) {
		setSelection( ( prev ) => {
			const next = new Map( prev )
			const set = new Set( next.get( group.language ) )
			set.add( item.locale )
			next.set( group.language, set )
			return next
		} )
	}

	function removeLocale( language, locale ) {
		setSelection( ( prev ) => {
			const current = prev.get( language )
			if ( ! current ) {
				return prev
			}
			const next = new Map( prev )
			const set = new Set( current )
			set.delete( locale )
			if ( set.size === 0 ) {
				// No regions left for this language — it goes back to the
				// left column instead of lingering as an empty entry.
				next.delete( language )
			} else {
				next.set( language, set )
			}
			return next
		} )
	}

	const languageCount = selection.size

	const modelOptions = aiModelOptions( {
		hasSubscription: aiEngines.hasSubscription,
		providerKeys: aiEngines.providerKeys,
	} )
	const hasAiChoice =
		aiEngines.hasSubscription || aiEngines.providerKeys.length > 0
	const aiModel =
		aiModelOverride &&
		modelOptions.some( ( option ) => option.value === aiModelOverride )
			? aiModelOverride
			: modelOptions[ 0 ]?.value ?? 'auto'

	async function submit() {
		setCreating( true )
		setError( '' )
		setPartialNotice( '' )
		const res = await api.post( '/languages/bulk', {
			selections: Array.from( selection.entries() ).map(
				( [ language, set ] ) => ( {
					language,
					locales: Array.from( set ),
				} )
			),
			ai_model: aiModel,
			ai_style: style,
		} )
		setCreating( false )
		if ( res.ok ) {
			const createdCodes = ( res.languages || [] ).map(
				( lang ) => lang.code
			)
			const createdLocales = new Set( createdCodes )
			const skipped = Array.isArray( res.skipped ) ? res.skipped : []
			// Only the first created code rides along — callers use it just to
			// pick a language to land on / check content-extraction status
			// for, not to enumerate the whole batch.
			if ( createdCodes.length > 0 ) {
				onCreated?.( createdCodes[ 0 ] )
			}
			if ( skipped.length === 0 ) {
				// Clear localStorage directly, not just via the `selection`
				// state + its mirroring effect — onDone() below closes the
				// modal in the very same update batch, unmounting this
				// component before that effect ever gets a chance to run
				// with the new (emptied) value, leaving the just-added
				// languages sitting in storage forever.
				saveSelection( new Map() )
				setSelection( new Map() )
				onDone( createdCodes[ 0 ] )
				return
			}
			// Partial success: drop only what was actually created, leave the
			// skipped locales selected so the user can see and retry them,
			// and say why instead of closing as if the whole batch went
			// through.
			setSelection( ( prev ) => {
				const next = new Map()
				for ( const [ language, locales ] of prev ) {
					const kept = new Set(
						Array.from( locales ).filter(
							( locale ) => ! createdLocales.has( locale )
						)
					)
					if ( kept.size > 0 ) {
						next.set( language, kept )
					}
				}
				return next
			} )
			const reasons = Array.from(
				new Set(
					skipped.map(
						( item ) =>
							SKIP_REASON_LABELS[ item.reason ] || 'skipped'
					)
				)
			).join( ', ' )
			setPartialNotice(
				`Added ${ createdLocales.size } language${
					createdLocales.size === 1 ? '' : 's'
				}. ${ skipped.length } skipped — ${ reasons }.`
			)
		} else {
			setError( res.error || 'ERR_BACKEND' )
		}
	}

	if ( catalogStatus === 'error' ) {
		return (
			<BlockStack gap="200">
				<Text as="p" tone="critical">
					Could not load WordPress’s language catalog. Check the
					site’s outgoing connection and try again.
				</Text>
				<InlineStack>
					<Button
						onClick={ () =>
							setCatalogReload( ( reload ) => reload + 1 )
						}
					>
						Retry
					</Button>
				</InlineStack>
			</BlockStack>
		)
	}

	if ( catalogStatus === 'loading' ) {
		return (
			<InlineStack gap="200" blockAlign="center">
				<Spinner accessibilityLabel="Loading languages" size="small" />
				<Text as="span" tone="subdued">
					Loading WordPress languages…
				</Text>
			</InlineStack>
		)
	}

	if ( groups.length === 0 ) {
		return (
			<Text as="p" tone="subdued">
				All available WordPress languages have already been added.
			</Text>
		)
	}

	return (
		<div>
			<BlockStack gap="400">
				<Text as="p" tone="subdued">
					Select each language and region you want to add.
				</Text>
				<div
					style={ {
						display: 'grid',
						gridTemplateColumns: '1fr auto 1fr',
						gap: 'var(--p-space-400)',
					} }
				>
					<BlockStack gap="200">
						<TextField
							id={ SEARCH_INPUT_ID }
							autoFocus
							label="Search languages"
							labelHidden
							placeholder="Search languages…"
							value={ search }
							onChange={ setSearch }
							autoComplete="off"
							clearButton
							onClearButtonClick={ () => setSearch( '' ) }
						/>
						<div style={ { maxHeight: 360, overflowY: 'auto' } }>
							<BlockStack gap="050">
								{ rows.map( ( { group, item } ) => (
									<LeftRow
										key={ item.locale }
										group={ group }
										item={ item }
										onAdd={ addLocale }
									/>
								) ) }
								{ rows.length === 0 && (
									<Box padding="300">
										<Text
											as="p"
											tone="subdued"
											alignment="center"
										>
											{ availableLocales.length === 0
												? 'Every language has been added.'
												: `No languages match “${ search }”.` }
										</Text>
									</Box>
								) }
							</BlockStack>
						</div>
					</BlockStack>

					<div
						style={ {
							alignSelf: 'stretch',
							width: 1,
							background: 'var(--p-color-border)',
						} }
					/>

					<BlockStack gap="200">
						<Text as="h3" variant="headingSm">
							Added languages
						</Text>
						<div style={ { maxHeight: 360, overflowY: 'auto' } }>
							<BlockStack gap="0">
								{ selectedRows.map( ( { group, item } ) => (
									<RightRow
										key={ item.locale }
										group={ group }
										item={ item }
										onRemove={ removeLocale }
									/>
								) ) }
								{ selectedRows.length === 0 && (
									<Box padding="300">
										<Text
											as="p"
											tone="subdued"
											alignment="center"
										>
											Select a language on the left to add
											it here.
										</Text>
									</Box>
								) }
							</BlockStack>
						</div>
					</BlockStack>
				</div>

				{ hasAiChoice && (
					<>
						<Divider />
						<Text as="h3" variant="headingSm">
							AI settings
						</Text>
						<BlockStack gap="300">
							<Select
								label="AI model"
								options={ modelOptions }
								value={ aiModel }
								onChange={ setAiModelOverride }
							/>
							<TextField
								label="Website voice and style"
								multiline={ 2 }
								maxLength={ AI_STYLE_MAX }
								showCharacterCount
								value={ style }
								onChange={ setStyle }
								autoComplete="off"
								placeholder={ AI_STYLE_PLACEHOLDER }
								helpText="Guides tone — never overrides the original text’s own intent."
							/>
						</BlockStack>
					</>
				) }

				<Divider />

				{ error && (
					<Text as="p" tone="critical">
						{ errorMessage( error ) }
					</Text>
				) }

				{ partialNotice && (
					<Text as="p" tone="caution">
						{ partialNotice }
					</Text>
				) }

				<InlineStack align="end" blockAlign="center">
					<Button
						variant="primary"
						loading={ creating }
						disabled={ languageCount === 0 }
						onClick={ submit }
					>
						Add languages
					</Button>
				</InlineStack>
			</BlockStack>
		</div>
	)
}
