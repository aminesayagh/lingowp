import {
	Banner,
	BlockStack,
	Button,
	Checkbox,
	InlineStack,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { errorMessage } from '../errors'
import { useConfigureOnboarding } from '../queries/onboarding'

// Step 1 of 2. Source/default language is intentionally NOT shown here — it is
// inherited from WordPress. Toggle defaults follow the wireframe (cookie +
// browser detection on), not stored options, since this is first-run config.
export default function Configuration( {
	recommendedPrefixDefaultLanguage,
	defaultLanguageSlug,
	onDone,
} ) {
	const slug = defaultLanguageSlug || 'en'
	const [ prefix, setPrefix ] = useState(
		!! recommendedPrefixDefaultLanguage
	)
	const [ cookie, setCookie ] = useState( true )
	const [ browser, setBrowser ] = useState( true )
	const [ autoRedirect, setAutoRedirect ] = useState( false )
	const configure = useConfigureOnboarding()
	const submitting = configure.isPending
	const error = configure.error?.code || ''
	// Advisory only — set when the user moves away from the recommended prefix
	// value, cleared when they return to it. Continue is still the only thing
	// that persists anything (see submit()).
	const [ prefixToast, setPrefixToast ] = useState( null )

	function handlePrefixChange( value ) {
		setPrefix( value )
		if ( value === !! recommendedPrefixDefaultLanguage ) {
			setPrefixToast( null )
			return
		}
		setPrefixToast(
			value
				? {
						tone: 'warning',
						content: `This will change existing URLs: /about/ will become /${ slug }/about/, which may temporarily affect search indexing.`,
				  }
				: {
						tone: 'info',
						content: `Default prefix disabled: your default-language URLs will use /about/ instead of /${ slug }/about/.`,
				  }
		)
	}

	// Auto redirect requires browser detection (arch dependency).
	const canAutoRedirect = browser
	const effectiveAutoRedirect = autoRedirect && canAutoRedirect

	async function submit() {
		try {
			await configure.mutateAsync( {
				prefix_default_language: prefix,
				cookie_preference: cookie,
				browser_detection: browser,
				auto_redirect: effectiveAutoRedirect,
			} )
			onDone()
		} catch {
			// Failure is surfaced through configure.error (see `error` above).
		}
	}

	return (
		<BlockStack gap="400">
			{ error && (
				<Banner tone="critical">{ errorMessage( error ) }</Banner>
			) }

			<BlockStack gap="300">
				<Checkbox
					label="Prefix default language"
					helpText={ `Add /${ slug } to default-language URLs. Example: example.com/${ slug }/about/` }
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
					checked={ effectiveAutoRedirect }
					disabled={ ! canAutoRedirect }
					onChange={ setAutoRedirect }
				/>
			</BlockStack>

			{ prefixToast && (
				<Banner tone={ prefixToast.tone }>
					{ prefixToast.content }
				</Banner>
			) }

			<InlineStack align="end">
				<Button
					variant="primary"
					loading={ submitting }
					onClick={ submit }
				>
					Continue
				</Button>
			</InlineStack>
		</BlockStack>
	)
}
