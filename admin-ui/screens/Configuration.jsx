import {
	Banner,
	BlockStack,
	Button,
	Card,
	Checkbox,
	Text,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'

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
	const [ error, setError ] = useState( '' )
	const [ submitting, setSubmitting ] = useState( false )
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
		setSubmitting( true )
		setError( '' )

		const res = await api.post( '/onboarding/configure', {
			prefix_default_language: prefix,
			cookie_preference: cookie,
			browser_detection: browser,
			auto_redirect: effectiveAutoRedirect,
		} )

		setSubmitting( false )
		if ( res.ok ) {
			onDone()
		} else {
			setError( res.error || 'ERR_BACKEND' )
		}
	}

	return (
		<BlockStack gap="400">
			<Text as="p" tone="subdued">
				Tell us about your site and how visitors should reach translated
				pages.
			</Text>

			{ error && (
				<Banner tone="critical">{ errorMessage( error ) }</Banner>
			) }

			<Card>
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
								? 'Send visitors to their detected language. Visitors may be redirected automatically based on their browser language.'
								: 'Enable browser detection before using auto redirect.'
						}
						checked={ effectiveAutoRedirect }
						disabled={ ! canAutoRedirect }
						onChange={ setAutoRedirect }
					/>
				</BlockStack>
			</Card>

			{ prefixToast && (
				<Banner tone={ prefixToast.tone }>
					{ prefixToast.content }
				</Banner>
			) }

			<Button
				variant="primary"
				size="large"
				loading={ submitting }
				onClick={ submit }
			>
				Continue
			</Button>
		</BlockStack>
	)
}
