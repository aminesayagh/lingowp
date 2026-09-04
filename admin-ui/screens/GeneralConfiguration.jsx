import {
	Banner,
	BlockStack,
	Button,
	Checkbox,
	Divider,
	InlineStack,
	Spinner,
	Text,
} from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { useLanguageCatalog } from '../queries/languages'
import { useOnboardingState, useSaveSettings } from '../queries/onboarding'
import SearchableLanguageSelect from './SearchableLanguageSelect'

const languageLabel = ( item ) => {
	const english = item?.english_name || item?.locale || ''
	const native = item?.native_name || ''
	return native && native !== english ? `${ english } — ${ native }` : english
}

export default function GeneralConfiguration( { defaultLanguage, onSaved } ) {
	const [ prefix, setPrefix ] = useState( false )
	const [ cookie, setCookie ] = useState( true )
	const [ browser, setBrowser ] = useState( false )
	const [ autoRedirect, setAutoRedirect ] = useState( false )
	const [ selectedLocale, setSelectedLocale ] = useState( defaultLanguage )
	const [ notice, setNotice ] = useState( null )
	const [ confirmingLanguage, setConfirmingLanguage ] = useState( false )
	// The site already has a live URL structure (onboarding is long done), so any
	// move away from the loaded prefix value changes real, indexed URLs.
	const [ initialPrefix, setInitialPrefix ] = useState( false )
	const [ prefixToast, setPrefixToast ] = useState( null )
	// True once the form fields have been seeded from the bootstrap read — keeps
	// the loading gate up for the extra frame between the queries resolving and
	// the seed effect running, so the form never flashes its default values.
	const [ seeded, setSeeded ] = useState( false )

	const stateQ = useOnboardingState()
	const catalogQ = useLanguageCatalog()
	const saveSettings = useSaveSettings()

	const catalog = catalogQ.data?.catalog ?? []
	const currentLocale = stateQ.data?.default_language?.code || defaultLanguage
	const currentSlug = stateQ.data?.default_language_slug || ''
	const loadFailed = stateQ.isError || catalogQ.isError
	const loading =
		! loadFailed && ( stateQ.isPending || catalogQ.isPending || ! seeded )
	const saving = saveSettings.isPending

	// Seed the editable form fields from the bootstrap read exactly once — a
	// later background refetch must not stomp the user's in-progress edits.
	useEffect( () => {
		if ( seeded || ! stateQ.data || ! catalogQ.data ) {
			return
		}
		setSeeded( true )
		const settings = stateQ.data.settings || {}
		setPrefix( !! settings.prefix_default_language )
		setInitialPrefix( !! settings.prefix_default_language )
		setPrefixToast( null )
		setCookie( !! settings.cookie_preference )
		setBrowser( !! settings.browser_detection )
		setAutoRedirect( !! settings.auto_redirect )
		setSelectedLocale(
			stateQ.data.default_language?.code || defaultLanguage
		)
	}, [ seeded, stateQ.data, catalogQ.data, defaultLanguage ] )

	function handlePrefixChange( value ) {
		setPrefix( value )
		if ( value === initialPrefix ) {
			setPrefixToast( null )
			return
		}
		const slug = currentSlug || 'en'
		setPrefixToast( {
			tone: 'warning',
			content: value
				? `This will change existing URLs: /about/ will become /${ slug }/about/, which may temporarily affect search indexing.`
				: `This will change existing URLs: /${ slug }/about/ will become /about/, which may temporarily affect search indexing.`,
		} )
	}

	const canAutoRedirect = browser
	const selectedLanguage = catalog.find(
		( item ) => item.locale === selectedLocale
	)
	const selectedLabel = languageLabel( selectedLanguage ) || selectedLocale
	const languageOptions = catalog.map( ( item ) => ( {
		label: languageLabel( item ),
		value: item.locale,
	} ) )
	const languageChanged = selectedLocale !== currentLocale

	function selectSiteLanguage( locale ) {
		const item = catalog.find( ( entry ) => entry.locale === locale )
		if ( ! item ) {
			return
		}
		setSelectedLocale( locale )
		setConfirmingLanguage( false )
		setNotice( null )
	}

	async function save( confirmed = false ) {
		if ( languageChanged && ! confirmed ) {
			setConfirmingLanguage( true )
			return
		}
		setConfirmingLanguage( false )
		setNotice( null )
		try {
			await saveSettings.mutateAsync( {
				default_language: selectedLocale,
				prefix_default_language: prefix,
				cookie_preference: cookie,
				browser_detection: browser,
				auto_redirect: autoRedirect && canAutoRedirect,
			} )
			if ( languageChanged ) {
				window.location.reload()
				return
			}
			// The modal closes right after a successful save (see onSaved),
			// so there's no one left to show a "Configuration saved." banner
			// to — the close itself is the confirmation.
			setInitialPrefix( prefix )
			setPrefixToast( null )
			onSaved?.()
		} catch ( e ) {
			const errors = {
				ERR_LANGUAGE_PACK_PERMISSION:
					'You do not have permission to install the selected WordPress language pack.',
				ERR_LANGUAGE_PACK_INSTALL:
					'WordPress could not install the selected language pack.',
				ERR_LANGUAGE_CATALOG_UNAVAILABLE:
					'WordPress’s language catalog is unavailable. Please try again.',
			}
			setNotice( {
				tone: 'critical',
				content:
					errors[ e.code ] ||
					'Could not save the plugin configuration. Please try again.',
			} )
		}
	}

	return (
		<div>
			<BlockStack gap="400">
				{ notice && (
					<Banner tone={ notice.tone }>{ notice.content }</Banner>
				) }

				{ loadFailed && (
					<BlockStack gap="200">
						<Banner tone="critical">
							Could not load the plugin configuration.
						</Banner>
						<div>
							<Button
								onClick={ () => {
									stateQ.refetch()
									catalogQ.refetch()
								} }
							>
								Retry
							</Button>
						</div>
					</BlockStack>
				) }
				{ ! loadFailed && loading && (
					<Spinner
						accessibilityLabel="Loading configuration"
						size="small"
					/>
				) }
				{ ! loadFailed && ! loading && (
					<>
						<BlockStack gap="200">
							<Text as="h2" variant="headingMd">
								Language behavior
							</Text>
							<Checkbox
								label="Prefix default language"
								helpText={ `Add /${
									currentSlug || 'en'
								} to default-language URLs. Example: example.com/${
									currentSlug || 'en'
								}/about/` }
								checked={ prefix }
								onChange={ handlePrefixChange }
							/>
							<Checkbox
								label="Cookie preference"
								helpText="Remember a visitor's chosen language."
								checked={ cookie }
								onChange={ setCookie }
							/>
							<Checkbox
								label="Browser detection"
								helpText="Detect language from the browser."
								checked={ browser }
								onChange={ setBrowser }
							/>
							<Checkbox
								label="Auto redirect"
								helpText={
									canAutoRedirect
										? 'Send visitors to their detected language.'
										: 'Enable browser detection before using auto redirect.'
								}
								checked={ autoRedirect && canAutoRedirect }
								disabled={ ! canAutoRedirect }
								onChange={ setAutoRedirect }
							/>
						</BlockStack>

						{ prefixToast && (
							<Banner tone={ prefixToast.tone }>
								{ prefixToast.content }
							</Banner>
						) }

						<Divider />

						<BlockStack gap="100">
							<Text as="h2" variant="headingMd">
								Default language
							</Text>
							<SearchableLanguageSelect
								// label="WordPress site language"
								options={ languageOptions }
								value={ selectedLocale }
								onChange={ selectSiteLanguage }
								emptyMessage="No WordPress language matches this search."
							/>
							<Text as="p" tone="subdued" variant="bodySm">
								This also changes WordPress’s Site Language and
								the source language used by LingoWP.
							</Text>
						</BlockStack>

						{ confirmingLanguage && (
							<Banner
								tone="warning"
								title="Change the source language?"
							>
								<BlockStack gap="200">
									<Text as="p">
										The previous source language will become
										a target language. Existing translations
										are preserved, and WordPress will switch
										to { selectedLabel }.
									</Text>
									<InlineStack gap="200">
										<Button
											variant="primary"
											tone="critical"
											loading={ saving }
											onClick={ () => save( true ) }
										>
											Change language
										</Button>
										<Button
											onClick={ () =>
												setConfirmingLanguage( false )
											}
										>
											Cancel
										</Button>
									</InlineStack>
								</BlockStack>
							</Banner>
						) }

						<InlineStack align="end">
							<Button
								variant="primary"
								loading={ saving }
								disabled={ ! selectedLocale }
								onClick={ () => save( false ) }
							>
								Save configuration
							</Button>
						</InlineStack>
					</>
				) }
			</BlockStack>
		</div>
	)
}
