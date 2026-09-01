import {
	BlockStack,
	Button,
	Divider,
	InlineStack,
	Modal,
	Select,
	Text,
	TextField,
} from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import GeneralConfiguration from './GeneralConfiguration'
import LanguageList, { PUBLISH_MIN_PERCENT } from './LanguageList'
import SearchableLanguageSelect from './SearchableLanguageSelect'

const enc = encodeURIComponent
const localeSlug = ( locale ) => locale.toLowerCase().replaceAll( '_', '-' )
const regionLabel = ( item ) => {
	let region = item.region_name || item.region || 'General'
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
	return item.variant_name ? `${ region } — ${ item.variant_name }` : region
}

export const AI_STYLE_MAX = 500
export const AI_STYLE_PLACEHOLDER =
	'Professional, clear, modern, and approachable.'

const PROVIDER_LABELS = { openai: 'OpenAI', anthropic: 'Anthropic' }

// AI model choices for a language: the LingoWP subscription model (only when
// subscribed) plus one entry per provider key the user has stored. Falls back to
// the legacy "Auto" when the user has neither — same single-option Select as
// before. ponytail: value vocabulary is 'internal' | 'openai' | 'anthropic',
// with legacy 'auto' resolving to the first option on next save.
export function aiModelOptions( { hasSubscription, providerKeys } ) {
	const opts = []
	if ( hasSubscription ) {
		opts.push( { label: 'LingoWP model', value: 'internal' } )
	}
	for ( const key of Array.isArray( providerKeys ) ? providerKeys : [] ) {
		if ( PROVIDER_LABELS[ key.provider ] ) {
			opts.push( {
				label: `Your ${ PROVIDER_LABELS[ key.provider ] } key`,
				value: key.provider,
			} )
		}
	}
	return opts.length ? opts : [ { label: 'Auto', value: 'auto' } ]
}

// Language manager: a sidebar of every language (reuses the dashboard's
// LanguageList) + a config pane. Selecting a row edits it; "+ Add" starts a new
// one. Creating a language closes the modal and hands its code back via
// onChanged so the caller can jump straight to that language's tab; editing an
// existing language keeps the modal open so several can be reconfigured in a row.
export default function LanguageManagerModal( {
	open,
	languages,
	catalog,
	defaultCode,
	initialCode,
	onClose,
	onChanged,
	onConfirm,
	onSwitchToInternal,
} ) {
	const [ sel, setSel ] = useState( 'new' )
	const [ resolvedCatalog, setResolvedCatalog ] = useState( catalog )
	const [ catalogStatus, setCatalogStatus ] = useState( 'idle' )
	const [ catalogReload, setCatalogReload ] = useState( 0 )
	const [ catalogRevision, setCatalogRevision ] = useState( 0 )
	// Which AI engines the language form can offer: the subscription model and/or
	// the user's stored provider keys. Fetched once per open (mirrors the
	// dashboard's own entitlements/provider-keys read).
	const [ aiEngines, setAiEngines ] = useState( {
		hasSubscription: false,
		providerKeys: [],
	} )
	const isFirstLanguage = languages.length === 0

	useEffect( () => {
		if ( open ) {
			setSel( initialCode || languages[ 0 ]?.code || 'new' )
		}
	}, [ initialCode, languages, open ] )

	useEffect( () => {
		if ( ! open ) {
			return
		}
		Promise.all( [
			api.get( '/provider-keys' ),
			api.get( '/billing/entitlements' ),
		] ).then( ( [ keysRes, entitlementsRes ] ) => {
			setAiEngines( {
				hasSubscription: !! entitlementsRes.entitlements?.active,
				// Array.isArray, not `|| []`: a failed /provider-keys call can
				// still hand back a truthy non-array error body, which `|| []`
				// would let straight through.
				providerKeys: Array.isArray( keysRes.keys ) ? keysRes.keys : [],
			} )
		} )
	}, [ open ] )

	useEffect( () => {
		setResolvedCatalog( catalog )
	}, [ catalog ] )

	const isConfiguration = sel === 'configuration'
	let current = null
	if ( sel !== 'new' && ! isConfiguration ) {
		current =
			languages.find( ( language ) => language.code === sel ) || null
	}
	let mode = 'new'
	if ( isConfiguration ) {
		mode = 'configuration'
	} else if ( current ) {
		mode = 'edit'
	}

	useEffect( () => {
		if ( ! open || mode !== 'new' ) {
			return undefined
		}

		let active = true
		setCatalogStatus( 'loading' )
		api.get( '/languages/catalog' ).then( ( res ) => {
			if ( ! active ) {
				return
			}
			if ( ! res.ok || ! Array.isArray( res.catalog ) ) {
				setCatalogStatus( 'error' )
				return
			}
			setResolvedCatalog( res.catalog )
			setCatalogRevision( ( revision ) => revision + 1 )
			setCatalogStatus( 'ready' )
		} )

		return () => {
			active = false
		}
	}, [ open, mode, catalogReload ] )

	// If the selected language was deleted elsewhere, fall back to the first.
	useEffect( () => {
		if ( sel !== 'new' && sel !== 'configuration' && ! current ) {
			setSel( languages[ 0 ]?.code || 'new' )
		}
	}, [ current, languages, sel ] )

	function patchStatus( code, status ) {
		api.patch( `/languages/${ enc( code ) }`, { status } ).then( ( r ) => {
			if ( r.ok ) {
				onChanged()
			}
		} )
	}

	// Draft → active is "publish"; below PUBLISH_MIN_PERCENT it asks for
	// confirmation first, since visitors would see mostly-fallback content.
	function toggleStatus( lang ) {
		if ( lang.enabled ) {
			patchStatus( lang.code, 'draft' )
			return
		}
		const percent = lang.translated_percent ?? 0
		if ( percent >= PUBLISH_MIN_PERCENT ) {
			patchStatus( lang.code, 'active' )
			return
		}
		onConfirm( {
			title: `Publish ${
				lang.display_name || lang.name || lang.code
			} at ${ percent }% translated?`,
			body: 'Some pages are not fully translated yet. Publishing now may cause search engines to index fallback content in the wrong language, which could affect your SEO. You can continue translating after publishing.',
			actionLabel: 'Publish anyway',
			onConfirm: () => patchStatus( lang.code, 'active' ),
		} )
	}

	function del( lang ) {
		api.del( `/languages/${ enc( lang.code ) }` ).then( ( r ) => {
			if ( r.ok ) {
				if ( sel === lang.code ) {
					setSel( 'new' )
				}
				onChanged()
			}
		} )
	}

	return (
		<Modal open={ open } onClose={ onClose } title="Languages" large>
			<Modal.Section>
				<div
					className="lingowp-wide-modal"
					style={ {
						display: 'flex',
						gap: isFirstLanguage ? 0 : 16,
						minHeight: 420,
						maxWidth: '100%',
						// `clip`, not `hidden`: `overflow-x: hidden` with a
						// `visible` y-axis computes y to `auto`, making this div a
						// stray vertical scroll container whose bar flickers on
						// and off against the submit button's spinner animation.
						// `clip` still contains horizontal bleed but leaves y
						// alone, so scrolling stays with the Polaris modal body.
						overflowX: 'clip',
					} }
				>
					{ ! isFirstLanguage && (
						<div
							style={ {
								flex: '0 0 220px',
								width: 220,
								borderInlineEnd:
									'1px solid var(--p-color-border, rgba(0,0,0,0.08))',
								paddingInlineEnd: 12,
							} }
						>
							<BlockStack gap="200">
								<Button
									fullWidth
									textAlign="left"
									variant={
										isConfiguration ? 'primary' : 'tertiary'
									}
									onClick={ () => setSel( 'configuration' ) }
								>
									Configuration
								</Button>
								<Divider />
								<Text as="h3" variant="headingSm">
									Languages
								</Text>
								<LanguageList
									languages={ languages }
									selectedCode={ current?.code }
									showProgress={ false }
									onSelect={ ( lang ) => setSel( lang.code ) }
									onToggleStatus={ toggleStatus }
									onSwitchToInternal={
										aiEngines.hasSubscription
											? onSwitchToInternal
											: undefined
									}
									onDelete={ del }
									onAdd={ () => setSel( 'new' ) }
								/>
							</BlockStack>
						</div>
					) }

					<div
						style={ {
							flex: 1,
							minWidth: 0,
							paddingBlockStart: 4,
							paddingInline: 5,
						} }
					>
						{ isConfiguration ? (
							<GeneralConfiguration
								defaultLanguage={ defaultCode }
								onSaved={ onClose }
							/>
						) : (
							<LanguageForm
								key={ `${ sel }:${ catalogRevision }:${
									resolvedCatalog?.length || 0
								}:${ aiEngines.hasSubscription }:${
									aiEngines.providerKeys.length
								}` }
								mode={ mode }
								current={ current }
								hasSubscription={ aiEngines.hasSubscription }
								providerKeys={ aiEngines.providerKeys }
								defaultCode={ defaultCode }
								catalog={
									mode === 'new' && catalogStatus !== 'ready'
										? null
										: resolvedCatalog
								}
								catalogError={
									mode === 'new' && catalogStatus === 'error'
								}
								onCatalogRetry={ () =>
									setCatalogReload( ( reload ) => reload + 1 )
								}
								existing={ languages.map( ( l ) => l.code ) }
								firstLanguage={ isFirstLanguage }
								onDone={ ( code ) => {
									// Saving is done either way — close the modal.
									// Creating a language also jumps the workspace
									// to its new tab so translating can start right
									// away; saving an edit just refreshes in place.
									onClose()
									if ( mode === 'new' && code ) {
										onChanged( code )
										return
									}
									onChanged()
								} }
							/>
						) }
					</div>
				</div>
			</Modal.Section>
		</Modal>
	)
}

function LanguageForm( {
	mode,
	current,
	hasSubscription = false,
	providerKeys = [],
	catalog,
	catalogError = false,
	onCatalogRetry,
	defaultCode,
	existing,
	firstLanguage = false,
	onDone,
} ) {
	const isNew = mode === 'new'
	const modelOptions = aiModelOptions( { hasSubscription, providerKeys } )
	// A stored value that no longer matches an available option (e.g. legacy
	// 'auto', or a provider key that was since removed) resolves to the first
	// option; it's only written back on the next save.
	const storedModel = isNew ? null : current.ai_model
	const initialAiModel =
		storedModel && modelOptions.some( ( o ) => o.value === storedModel )
			? storedModel
			: modelOptions[ 0 ].value
	const localeUnavailable = ( locale ) =>
		locale === defaultCode || existing.includes( locale )
	const localeStatus = ( locale ) => {
		if ( locale === defaultCode ) {
			return ' — Website default'
		}
		return existing.includes( locale ) ? ' — Already added' : ''
	}
	const catalogItems = catalog || []
	const available = catalogItems.filter(
		( item ) => ! localeUnavailable( item.locale )
	)
	const languages = Array.from(
		catalogItems
			.reduce( ( groups, item ) => {
				const group = groups.get( item.language ) || {
					label: item.language_name,
					items: [],
				}
				group.items.push( item )
				groups.set( item.language, group )
				return groups
			}, new Map() )
			.entries()
	)
	const languageChoices = languages.map( ( [ value, group ] ) => ( {
		label: group.label,
		value,
		disabled: group.items.every( ( item ) =>
			localeUnavailable( item.locale )
		),
	} ) )
	const initialLocale = isNew
		? null
		: ( catalog || [] ).find( ( item ) => item.locale === current.code ) ||
		  null
	const [ language, setLanguage ] = useState( initialLocale?.language || '' )
	const [ code, setCode ] = useState(
		isNew ? initialLocale?.locale || '' : current.code
	)
	const [ displayName, setDisplayName ] = useState(
		isNew ? '' : current.display_name || ''
	)
	const [ slug, setSlug ] = useState(
		isNew ? localeSlug( initialLocale?.locale || '' ) : current.slug || ''
	)
	const [ fallback, setFallback ] = useState(
		isNew ? defaultCode : current.fallback_language || ''
	)
	const [ aiModel, setAiModel ] = useState( initialAiModel )
	const [ style, setStyle ] = useState( isNew ? '' : current.ai_style || '' )
	const [ direction, setDirection ] = useState(
		isNew ? initialLocale?.direction || 'ltr' : current.direction || 'ltr'
	)
	const [ saving, setSaving ] = useState( false )
	// Snapshot of the values when the form loaded; the Save button only appears
	// once the form differs from it (and resets to the saved values after save).
	const [ baseline, setBaseline ] = useState( {
		code,
		displayName,
		slug,
		fallback,
		aiModel,
		style,
	} )

	const dirty =
		code !== baseline.code ||
		displayName !== baseline.displayName ||
		slug !== baseline.slug ||
		fallback !== baseline.fallback ||
		aiModel !== baseline.aiModel ||
		style !== baseline.style
	const firstLanguageReady =
		code.trim() !== '' && displayName.trim() !== '' && slug.trim() !== ''
	const showAction = firstLanguage || dirty
	const actionDisabled = firstLanguage
		? ! firstLanguageReady
		: isNew && ! code
	let actionLabel = 'Save'
	if ( firstLanguage ) {
		actionLabel = 'Create language'
	} else if ( isNew ) {
		actionLabel = 'Add language'
	}

	const regionChoices = [
		{
			label: language ? 'Select a region' : 'Select a language first',
			value: '',
			disabled: true,
		},
		...catalogItems
			.filter( ( item ) => item.language === language )
			.map( ( item ) => ( {
				label: `${ regionLabel( item ) }${ localeStatus(
					item.locale
				) }`,
				value: item.locale,
				disabled: localeUnavailable( item.locale ),
			} ) ),
	]
	const defaultLanguage = catalogItems.find(
		( item ) => item.locale === defaultCode
	)
	// Edit-only (see the form below): creation picks this automatically via
	// smartFallback() below, with no field shown, so it never interrupts adding
	// a language — this list exists only to let an already-added language's
	// fallback be reviewed or overridden afterward.
	const fallbackChoices = [
		{ label: 'None', value: '' },
		{
			label: `${
				defaultLanguage?.english_name || defaultCode
			} — Website default`,
			value: defaultCode,
		},
		...catalogItems
			.filter(
				( item ) =>
					existing.includes( item.locale ) &&
					item.locale !== code &&
					item.locale !== defaultCode
			)
			.map( ( item ) => ( {
				label: item.english_name,
				value: item.locale,
			} ) ),
	]

	function pickLanguage( value ) {
		if ( ! available.some( ( item ) => item.language === value ) ) {
			return
		}
		setLanguage( value )
		setCode( '' )
		setDisplayName( '' )
		setSlug( '' )
		setDirection( 'ltr' )
	}

	// A same-base-language sibling is a better fallback than the site default
	// (e.g. fr_CA should fall back to fr_FR, not the English source) — the
	// default only wins when it's itself that same base language, since it's
	// guaranteed complete. Picks the earliest-added sibling when several exist.
	function smartFallback( baseLanguage ) {
		if ( defaultLanguage?.language === baseLanguage ) {
			return defaultCode
		}
		const sibling = existing.find(
			( siblingCode ) =>
				catalogItems.find( ( item ) => item.locale === siblingCode )
					?.language === baseLanguage
		)
		return sibling || defaultCode
	}

	function pickLocale( value ) {
		const choice = catalogItems.find( ( item ) => item.locale === value )
		if ( ! choice || localeUnavailable( value ) ) {
			return
		}
		setCode( value )
		setDisplayName(
			`${ choice.language_name } (${ regionLabel( choice ) })`
		)
		setDirection( choice.direction || 'ltr' )
		setSlug( localeSlug( value ) )
		setFallback( smartFallback( choice.language ) )
	}

	async function submit() {
		setSaving( true )
		const res = isNew
			? await api.post( '/languages', {
					code,
					locale: code,
					slug,
					display_name: displayName,
					fallback_language: fallback,
					direction,
					ai_model: aiModel,
					ai_style: style,
			  } )
			: await api.patch( `/languages/${ enc( current.code ) }`, {
					display_name: displayName,
					slug,
					fallback_language: fallback,
					ai_model: aiModel,
					ai_style: style,
			  } )
		setSaving( false )
		if ( res.ok ) {
			setBaseline( { code, displayName, slug, fallback, aiModel, style } )
			onDone( isNew ? code : current.code )
		}
	}

	if ( isNew && catalogError ) {
		return (
			<BlockStack gap="200">
				<Text as="h3" variant="headingMd">
					Add a language
				</Text>
				<Text as="p" tone="critical">
					Could not load WordPress’s language catalog. Check the
					site’s outgoing connection and try again.
				</Text>
				<InlineStack>
					<Button onClick={ onCatalogRetry }>Retry</Button>
				</InlineStack>
			</BlockStack>
		)
	}

	if ( isNew && catalog === null ) {
		return (
			<Text as="p" tone="subdued">
				Loading WordPress languages…
			</Text>
		)
	}

	if ( isNew && available.length === 0 ) {
		return (
			<BlockStack gap="200">
				<Text as="h3" variant="headingMd">
					Add a language
				</Text>
				<Text as="p" tone="subdued">
					All available WordPress languages have already been added.
				</Text>
			</BlockStack>
		)
	}

	return (
		<BlockStack gap="300">
			<Text as="h3" variant="headingMd">
				{ isNew
					? 'Add a language'
					: `Edit ${
							current.display_name || current.name || current.code
					  }` }
			</Text>
			<BlockStack gap="300">
				{ isNew && (
					<>
						<SearchableLanguageSelect
							label="Language"
							requiredIndicator
							options={ languageChoices }
							value={ language }
							onChange={ pickLanguage }
							placeholder="Search languages"
						/>
						<Select
							label="Region"
							requiredIndicator
							options={ regionChoices }
							value={ code }
							onChange={ pickLocale }
							disabled={ ! language }
						/>
					</>
				) }
				<TextField
					label="Name"
					requiredIndicator
					value={ displayName }
					onChange={ setDisplayName }
					autoComplete="off"
					disabled={ isNew && ! code }
				/>
				<TextField
					label="URL slug"
					requiredIndicator
					value={ slug }
					onChange={ setSlug }
					autoComplete="off"
				/>
				{ ! isNew && (
					<SearchableLanguageSelect
						label="Fallback language"
						requiredIndicator
						options={ fallbackChoices }
						value={ fallback }
						onChange={ setFallback }
						placeholder="Search fallback languages"
					/>
				) }
			</BlockStack>

			<Divider />

			<Text as="h3" variant="headingSm">
				AI settings
			</Text>
			<BlockStack gap="300">
				<Select
					label="AI model"
					options={ modelOptions }
					value={ aiModel }
					onChange={ setAiModel }
					helpText={
						hasSubscription && providerKeys.length > 0
							? 'The LingoWP model bills to your subscription; your own key bills to your provider account.'
							: undefined
					}
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
					helpText="Your website voice guides the translation, but the original text’s tone and intent always take priority."
				/>
			</BlockStack>

			{ showAction && (
				<InlineStack align="end">
					<Button
						variant="primary"
						loading={ saving }
						disabled={ actionDisabled }
						onClick={ submit }
					>
						{ actionLabel }
					</Button>
				</InlineStack>
			) }
		</BlockStack>
	)
}
