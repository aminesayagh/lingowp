import {
	Box,
	BlockStack,
	Button,
	Divider,
	InlineGrid,
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

// A left-column entry is only ever a not-yet-added language (added ones move
// to the right column entirely, see AddLanguagesPicker below) — so the whole
// row does one thing: add it, with every one of its regions, right away. No
// separate "select" vs "configure" step.
function LeftRow( { group, onAdd } ) {
	return (
		<div
			role="button"
			tabIndex={ 0 }
			onClick={ () => onAdd( group ) }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault()
					onAdd( group )
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
				// Prefer a group member that actually carries a region (most
				// do) over an arbitrary items[0] — a group can mix a
				// region-less "General" entry with real regional variants.
				// flagIconUrl() falls back to a curated per-language flag
				// (REGION_FALLBACK in FlagIcon.jsx) keyed by `code` when
				// nothing in the group has one — the catalog entry itself has
				// no `code` field, only `language`.
				src={ flagIconUrl( {
					...( group.items.find( ( item ) => item.region ) ||
						group.items[ 0 ] ),
					code: group.language,
				} ) }
				size={ 16 }
			/>
			<div style={ { flex: 1, minWidth: 0 } }>
				<Text as="span" fontWeight="semibold" truncate>
					{ group.label }
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

export default function AddLanguagesPicker( {
	defaultCode,
	selection,
	onSelectionChange,
	onDone,
} ) {
	const [ catalog, setCatalog ] = useState( [] )
	const [ catalogStatus, setCatalogStatus ] = useState( 'loading' )
	const [ catalogReload, setCatalogReload ] = useState( 0 )
	const [ aiEngines, setAiEngines ] = useState( {
		hasSubscription: false,
		providerKeys: [],
	} )
	// The whole pick, client-side only until "Add languages" is submitted —
	// owned by the caller (not local state) so closing and reopening this
	// dialog (which unmounts this component in between) picks up right where
	// the user left off instead of starting over.
	const setSelection = onSelectionChange
	const [ search, setSearch ] = useState( '' )
	const [ aiModelOverride, setAiModelOverride ] = useState( null )
	const [ style, setStyle ] = useState( '' )
	const [ creating, setCreating ] = useState( false )
	const [ error, setError ] = useState( '' )

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
	// A language stays on the left as long as it has at least one region not
	// already on the right — only once *every* region is added does it drop
	// off entirely. Without this, removing just one of a language's several
	// regions made the whole language vanish from the left until every other
	// region was also removed — the only way back to that one region was to
	// undo everything and re-add the language from scratch.
	function remainingLocales( group ) {
		const already = selection.get( group.language )
		if ( ! already ) {
			return group.items
		}
		return group.items.filter( ( item ) => ! already.has( item.locale ) )
	}
	const availableGroups = groups.filter(
		( group ) => remainingLocales( group ).length > 0
	)
	const term = search.trim().toLowerCase()
	const rows = availableGroups.filter(
		( group ) =>
			! term ||
			`${ group.label } ${ group.language }`
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

	function addLanguage( group ) {
		setSelection( ( prev ) => {
			const next = new Map( prev )
			// Union with whatever's already added, not a fresh Set — a
			// partially-added language (some regions removed, still showing
			// on the left for its remaining ones) must only pick up what's
			// missing, not re-add regions the user deliberately removed.
			const merged = new Set( next.get( group.language ) )
			for ( const item of group.items ) {
				merged.add( item.locale )
			}
			next.set( group.language, merged )
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
			onDone()
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
					Select a language to add it, with all its regions.
				</Text>
				<InlineGrid columns={ 2 } gap="400">
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
								{ rows.map( ( group ) => (
									<LeftRow
										key={ group.language }
										group={ group }
										onAdd={ addLanguage }
									/>
								) ) }
								{ rows.length === 0 && (
									<Box padding="300">
										<Text
											as="p"
											tone="subdued"
											alignment="center"
										>
											{ availableGroups.length === 0
												? 'Every language has been added.'
												: `No languages match “${ search }”.` }
										</Text>
									</Box>
								) }
							</BlockStack>
						</div>
					</BlockStack>

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
											Select a language on the left to
											add it here.
										</Text>
									</Box>
								) }
							</BlockStack>
						</div>
					</BlockStack>
				</InlineGrid>

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

				<InlineStack align="end" blockAlign="center">
					<Button
						variant="primary"
						loading={ creating }
						disabled={ languageCount === 0 }
						onClick={ submit }
					>
						{ `Add ${ languageCount } language${
							languageCount === 1 ? '' : 's'
						}` }
					</Button>
				</InlineStack>
			</BlockStack>
		</div>
	)
}
