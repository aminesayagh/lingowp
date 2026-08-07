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
import { api } from '../api'
import SearchableLanguageSelect from './SearchableLanguageSelect'

const languageLabel = ( item ) => {
	const english = item?.english_name || item?.locale || ''
	const native = item?.native_name || ''
	return native && native !== english ? `${ english } — ${ native }` : english
}

export default function GeneralConfiguration( { defaultLanguage } ) {
	const [ prefix, setPrefix ] = useState( false )
	const [ cookie, setCookie ] = useState( true )
	const [ browser, setBrowser ] = useState( false )
	const [ autoRedirect, setAutoRedirect ] = useState( false )
	const [ catalog, setCatalog ] = useState( [] )
	const [ currentLocale, setCurrentLocale ] = useState( defaultLanguage )
	const [ selectedLocale, setSelectedLocale ] = useState( defaultLanguage )
	const [ loading, setLoading ] = useState( true )
	const [ loadFailed, setLoadFailed ] = useState( false )
	const [ reload, setReload ] = useState( 0 )
	const [ saving, setSaving ] = useState( false )
	const [ notice, setNotice ] = useState( null )
	const [ confirmingLanguage, setConfirmingLanguage ] = useState( false )

	useEffect( () => {
		let active = true
		setLoading( true )
		setLoadFailed( false )
		setNotice( null )
		Promise.all( [
			api.get( '/onboarding/state' ),
			api.get( '/languages/catalog' ),
		] ).then( ( [ stateRes, catalogRes ] ) => {
			if ( ! active ) {
				return
			}
			if (
				! stateRes.ok ||
				! catalogRes.ok ||
				! Array.isArray( catalogRes.catalog )
			) {
				setLoadFailed( true )
				setLoading( false )
				return
			}
			const settings = stateRes.settings || {}
			const locale = stateRes.default_language?.code || defaultLanguage
			const items = catalogRes.catalog
			setPrefix( !! settings.prefix_default_language )
			setCookie( !! settings.cookie_preference )
			setBrowser( !! settings.browser_detection )
			setAutoRedirect( !! settings.auto_redirect )
			setCatalog( items )
			setCurrentLocale( locale )
			setSelectedLocale( locale )
			setLoading( false )
		} )

		return () => {
			active = false
		}
	}, [ defaultLanguage, reload ] )

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
		setSaving( true )
		setConfirmingLanguage( false )
		setNotice( null )
		const res = await api.post( '/settings', {
			default_language: selectedLocale,
			prefix_default_language: prefix,
			cookie_preference: cookie,
			browser_detection: browser,
			auto_redirect: autoRedirect && canAutoRedirect,
		} )
		setSaving( false )
		if ( res.ok && languageChanged ) {
			window.location.reload()
			return
		}
		if ( res.ok ) {
			setNotice( { tone: 'success', content: 'Configuration saved.' } )
			return
		}
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
				errors[ res.error ] ||
				'Could not save the plugin configuration. Please try again.',
		} )
	}

	return (
		<div>
			<BlockStack gap="500">
				<BlockStack gap="100">
					<Text as="p" tone="subdued">
						Control how visitors discover and remember translated
						languages.
					</Text>
				</BlockStack>

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
								onClick={ () =>
									setReload( ( value ) => value + 1 )
								}
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
								helpText="Add /en to source URLs."
								checked={ prefix }
								onChange={ setPrefix }
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

						<Divider />

						<BlockStack gap="100">
							<Text as="h2" variant="headingMd">
								Default language
							</Text>
							<SearchableLanguageSelect
								label="WordPress site language"
								options={ languageOptions }
								value={ selectedLocale }
								onChange={ selectSiteLanguage }
								emptyMessage="No WordPress language matches this search."
							/>
							<Text as="p" tone="subdued" variant="bodySm">
								This also changes WordPress’s Site Language and
								the source language used by TransLangs.
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

						<div>
							<Button
								variant="primary"
								loading={ saving }
								disabled={ ! selectedLocale }
								onClick={ () => save( false ) }
							>
								Save configuration
							</Button>
						</div>
					</>
				) }
			</BlockStack>
		</div>
	)
}
