import {
	Banner,
	BlockStack,
	Button,
	Divider,
	InlineStack,
	Modal,
	Select,
	Text,
	TextField,
} from '@shopify/polaris'
import { useCallback, useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'
import AiConfiguration from './AiConfiguration'
import GeneralConfiguration from './GeneralConfiguration'
import LanguageList, { PUBLISH_MIN_PERCENT } from './LanguageList'
import SearchableLanguageSelect from './SearchableLanguageSelect'

const enc = encodeURIComponent
const localeSlug = ( locale ) => locale.toLowerCase().replaceAll( '_', '-' )
// '' (not "General") when the locale carries no real region and no variant —
// callers show the language name alone in that case rather than a
// meaningless "General" qualifier.
const regionLabel = ( item ) => {
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

// Language manager body: a sidebar of every language (reuses the dashboard's
// LanguageList) + a config pane. Selecting a row edits it; "+ Add" starts a new
// one. Creating a language closes the modal and hands its code back via
// onChanged so the caller can jump straight to that language's tab; editing an
// existing language keeps the modal open so several can be reconfigured in a row.
//
// Exported on its own (not just as the default-exported Modal below) so a
// caller that needs to host this content inside a *different* Modal instance
// can — e.g. App.jsx's onboarding modal, which stays open across "finish
// setup" and "add your first language" as one continuous dialog rather than
// closing one Modal and opening another.
export function LanguagesManagerBody( {
	open,
	languages,
	catalog,
	defaultCode,
	initialCode,
	onClose,
	onChanged,
	onConfirm,
	onSwitchToInternal,
	onWideChange,
} ) {
	const [ sel, setSel ] = useState(
		() => initialCode || languages[ 0 ]?.code || 'new'
	)
	// Re-point `sel` at whatever the caller asked for (openManager()'s code
	// argument) the instant the modal opens — synchronously during render,
	// not in an effect, so the very first painted frame already has the
	// right mode/width instead of showing 'new' for a frame and correcting
	// itself a moment later (this body stays mounted permanently once opened
	// once, see managerEverOpened in Dashboard.jsx, so a plain mount-time
	// initializer alone wouldn't catch later re-opens).
	const [ prevOpen, setPrevOpen ] = useState( open )
	if ( open !== prevOpen ) {
		setPrevOpen( open )
		if ( open ) {
			setSel( initialCode || languages[ 0 ]?.code || 'new' )
		}
	}
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

	const refreshAiEngines = useCallback( () => {
		return Promise.all( [
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
	}, [] )

	useEffect( () => {
		if ( ! open ) {
			return
		}
		refreshAiEngines()
	}, [ open, refreshAiEngines ] )

	useEffect( () => {
		setResolvedCatalog( catalog )
	}, [ catalog ] )

	const isConfiguration = sel === 'configuration'
	const isAi = sel === 'ai'
	let current = null
	if ( sel !== 'new' && ! isConfiguration && ! isAi ) {
		current =
			languages.find( ( language ) => language.code === sel ) || null
	}
	let mode = 'new'
	if ( isConfiguration ) {
		mode = 'configuration'
	} else if ( isAi ) {
		mode = 'ai'
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
		if (
			sel !== 'new' &&
			sel !== 'configuration' &&
			sel !== 'ai' &&
			! current
		) {
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
				lang.native_display || lang.name || lang.code
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

	// The wide (850px) treatment is for the Settings modal as a whole, once
	// there's a sidebar worth browsing — i.e. as soon as at least one
	// language exists, whichever of its views (a language's own edit form,
	// "+ Add" another, Configuration, AI settings) happens to be selected.
	// Before that (isFirstLanguage), there's no sidebar at all, just a single
	// form, so the plain default width is enough.
	const isWide = ! isFirstLanguage

	// The Modal itself lives in the parent wrapper (LanguageManagerModal
	// below) — it needs to know isWide too, to size itself, since Polaris's
	// own `size` prop (not just this marker class + CSS) is what puts the
	// dialog into the sizeLarge bucket the CSS override keys off.
	useEffect( () => {
		onWideChange?.( isWide )
	}, [ isWide, onWideChange ] )

	return (
		<div
			className={ isWide ? 'lingowp-wide-modal' : undefined }
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
						<BlockStack gap="050">
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
							<Button
								fullWidth
								textAlign="left"
								variant={ isAi ? 'primary' : 'tertiary' }
								onClick={ () => setSel( 'ai' ) }
							>
								AI settings
							</Button>
						</BlockStack>
						<Divider />
						<Text as="h3" variant="headingSm">
							Languages
						</Text>
						<LanguageList
							languages={ languages }
							selectedCode={ current?.code }
							showProgress={ false }
							flagGrayscale={ false }
							onSelect={ ( lang ) => setSel( lang.code ) }
							onToggleStatus={ toggleStatus }
							onSwitchToInternal={
								aiEngines.hasSubscription
									? onSwitchToInternal
									: undefined
							}
							onDelete={ del }
							onAdd={ () => setSel( 'new' ) }
							maxHeight={ 420 }
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
				) : isAi ? (
					<AiConfiguration
						hasSubscription={ aiEngines.hasSubscription }
						onConfirm={ onConfirm }
						onKeysChanged={ refreshAiEngines }
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
	)
}

// Thin Modal wrapper around the body above — this is what Dashboard.jsx
// mounts for every purpose except the onboarding hand-off (edit a language,
// open Settings, "+ Add" a later language).
export default function LanguageManagerModal( props ) {
	// undefined (Polaris's own default width) only before any language
	// exists — LanguagesManagerBody reports that back live via onWideChange,
	// since it alone knows whether a sidebar exists at all. But that's one
	// effect-cycle behind on the frame the modal actually opens on, which
	// flashed the wrong width for a frame — so mirror the same check here,
	// synchronously, against the props this wrapper already has, the instant
	// `open` flips true.
	const isWideFor = () => props.languages.length > 0
	const [ isWide, setIsWide ] = useState( isWideFor )
	const [ prevOpen, setPrevOpen ] = useState( props.open )
	if ( props.open !== prevOpen ) {
		setPrevOpen( props.open )
		if ( props.open ) {
			setIsWide( isWideFor() )
		}
	}
	return (
		<Modal
			open={ props.open }
			onClose={ props.onClose }
			title="Settings"
			size={ isWide ? 'large' : undefined }
		>
			<Modal.Section>
				<LanguagesManagerBody { ...props } onWideChange={ setIsWide } />
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
	const [ error, setError ] = useState( '' )
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

	// The very first language on a fresh site has nothing to differentiate a
	// name/slug from the region's own default (pickLocale already derives
	// both) — asking for them here is pure friction, so they're never shown
	// for it. Editing that same language afterward (isNew is false then)
	// still offers both, in case the auto-derived values aren't what's wanted.
	const hideNameAndSlug = isNew && firstLanguage
	// No real AI engine choice exists without a subscription or a stored
	// provider key — aiModelOptions() then returns just the single "Auto"
	// placeholder, so asking the user to pick from one option (or write a
	// style for it) is nothing but noise.
	const hasAiChoice = hasSubscription || providerKeys.length > 0
	const dirty =
		code !== baseline.code ||
		displayName !== baseline.displayName ||
		slug !== baseline.slug ||
		fallback !== baseline.fallback ||
		aiModel !== baseline.aiModel ||
		style !== baseline.style
	// Name/slug needing no user input (hidden for the first language) only
	// gates readiness on the region pick itself.
	const firstLanguageReady =
		code.trim() !== '' &&
		( hideNameAndSlug ||
			( displayName.trim() !== '' && slug.trim() !== '' ) )
	const showAction = firstLanguage || dirty
	const actionDisabled = firstLanguage
		? ! firstLanguageReady
		: isNew && ! code
	const regionsForLanguage = catalogItems.filter(
		( item ) => item.language === language
	)
	// The canonical label shown everywhere when no nickname is set.
	const canonicalName =
		catalogItems.find(
			( item ) => item.locale === ( isNew ? code : current.code )
		)?.native_display || ''
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
		...regionsForLanguage.map( ( item ) => ( {
			label: `${
				regionLabel( item ) || item.language_name
			}${ localeStatus( item.locale ) }`,
			value: item.locale,
			disabled: localeUnavailable( item.locale ),
		} ) ),
	]
	const defaultLanguage = catalogItems.find(
		( item ) => item.locale === defaultCode
	)
	// "Français (Canada)", not "fr_CA" — the catalog's canonical label, always
	// populated (falls back to the language name, never a bare locale).
	const fallbackLabel = ( item ) =>
		item.native_display || item.language_name || item.locale

	// Edit-only (see the form below): creation picks this automatically via
	// smartFallback() below, with no field shown, so it never interrupts adding
	// a language — this list exists only to let an already-added language's
	// fallback be reviewed or overridden afterward.
	const fallbackChoices = [
		{ label: 'None', value: '' },
		{
			label: `${
				defaultLanguage ? fallbackLabel( defaultLanguage ) : defaultCode
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
				label: fallbackLabel( item ),
				value: item.locale,
			} ) ),
	]

	function pickLanguage( value ) {
		if ( ! available.some( ( item ) => item.language === value ) ) {
			return
		}
		setLanguage( value )
		// Nothing to actually choose when there's only one region available —
		// pick it immediately instead of making the region Select a pointless
		// extra click.
		const onlyRegion = catalogItems.filter(
			( item ) =>
				item.language === value && ! localeUnavailable( item.locale )
		)
		if ( onlyRegion.length === 1 ) {
			pickLocale( onlyRegion[ 0 ].locale )
			return
		}
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
		// Display name is an optional workspace-board nickname — left blank
		// unless the user types one. Every screen shows the language's own
		// canonical label ("Português (Brasil)") when it's blank.
		setDisplayName( '' )
		setDirection( choice.direction || 'ltr' )
		setSlug( localeSlug( value ) )
		setFallback( smartFallback( choice.language ) )
	}

	async function submit() {
		setSaving( true )
		setError( '' )
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
		} else {
			setError( res.error || 'ERR_BACKEND' )
		}
	}

	if ( isNew && catalogError ) {
		return (
			<BlockStack gap="200">
				{ ! firstLanguage && (
					<Text as="h3" variant="headingMd">
						Add a language
					</Text>
				) }
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
				{ ! firstLanguage && (
					<Text as="h3" variant="headingMd">
						Add a language
					</Text>
				) }
				<Text as="p" tone="subdued">
					All available WordPress languages have already been added.
				</Text>
			</BlockStack>
		)
	}

	return (
		<BlockStack gap="300">
			{ /* The Modal wrapping this already says "Languages" — this
			duplicated it for the isFirstLanguage case (no sidebar to
			disambiguate against, unlike editing/adding among several). */ }
			{ ! firstLanguage && (
				<Text as="h3" variant="headingMd">
					{ isNew
						? 'Add a language'
						: `Edit ${
								current.native_display ||
								current.name ||
								current.code
						  }` }
				</Text>
			) }
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
				{ ! hideNameAndSlug && (
					<>
						<TextField
							label="Name"
							value={ displayName }
							onChange={ setDisplayName }
							autoComplete="off"
							disabled={ isNew && ! code }
							placeholder={ canonicalName }
							helpText="Optional. A nickname shown only on the translation board — leave blank to use the language's own name everywhere."
						/>
						<TextField
							label="URL slug"
							requiredIndicator
							value={ slug }
							onChange={ setSlug }
							autoComplete="off"
						/>
					</>
				) }
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
							helpText="Guides tone — never overrides the original text’s own intent."
							// Writing style ships with the LingoWP subscription
							// model only — disabled when this language uses a
							// BYOK provider key.
							disabled={ aiModel !== 'internal' }
						/>
					</BlockStack>
				</>
			) }

			{ error && (
				<Banner tone="critical">{ errorMessage( error ) }</Banner>
			) }

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
