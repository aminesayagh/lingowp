import {
	Banner,
	BlockStack,
	Button,
	Card,
	Checkbox,
	ChoiceList,
	Text,
} from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'
import OnboardingPage from './OnboardingPage'

// Code-only categories come from the backend; labels are a frontend concern.
function humanize( code ) {
	return code
		.replace( /[_-]+/g, ' ' )
		.replace( /\b\w/g, ( c ) => c.toUpperCase() )
}

// Toast tone → colors, same tokens Dashboard.jsx uses for its bottom toast.
const TOAST_TONE_STYLE = {
	warning: {
		background: 'var(--p-color-bg-fill-caution)',
		color: 'var(--p-color-text-caution-on-bg-fill)',
	},
	info: {
		background: 'var(--p-color-bg-fill-inverse)',
		color: 'var(--p-color-text-inverse)',
	},
}

// Step 1 of 2. Source/default language is intentionally NOT shown here — it is
// inherited from WordPress. Toggle defaults follow the wireframe (cookie +
// browser detection on), not stored options, since this is first-run config.
export default function Configuration( {
	recommendedPrefixDefaultLanguage,
	defaultLanguageSlug,
	onDone,
} ) {
	const slug = defaultLanguageSlug || 'en'
	const [ categoryChoices, setCategoryChoices ] = useState( [] )
	const [ categories, setCategories ] = useState( [] )
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

	useEffect( () => {
		api.get( '/onboarding/categories' ).then( ( res ) => {
			if ( res.ok && Array.isArray( res.categories ) ) {
				setCategoryChoices(
					res.categories.map( ( code ) => ( {
						label: humanize( code ),
						value: code,
					} ) )
				)
			}
		} )
	}, [] )

	// Auto redirect requires browser detection (arch dependency).
	const canAutoRedirect = browser
	const effectiveAutoRedirect = autoRedirect && canAutoRedirect

	async function submit() {
		// Categories are optional (and the catalog is only available when the backend
		// is reachable) — never block Continue on them.
		setSubmitting( true )
		setError( '' )

		const res = await api.post( '/onboarding/configure', {
			categories,
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
		<OnboardingPage
			title="Configure Translangs"
			subtitle="Tell us about your site and how visitors should reach translated pages."
		>
			<BlockStack gap="400">
				{ error && (
					<Banner tone="critical">{ errorMessage( error ) }</Banner>
				) }

				{ /* Categories come from the backend catalog; hide the field entirely
				     when it is unavailable (e.g. backend down). Optional either way. */ }
				{ categoryChoices.length > 0 && (
					<Card>
						<ChoiceList
							allowMultiple
							title="Site categories"
							choices={ categoryChoices }
							selected={ categories }
							onChange={ setCategories }
						/>
						<Text as="p" tone="subdued" variant="bodySm">
							Helps AI match your site&#39;s tone.
						</Text>
					</Card>
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

				<Button
					variant="primary"
					size="large"
					loading={ submitting }
					onClick={ submit }
				>
					Continue
				</Button>
			</BlockStack>

			{ prefixToast && (
				<div
					role="status"
					aria-live="polite"
					style={ {
						position: 'fixed',
						left: '50%',
						bottom: '24px',
						transform: 'translateX(-50%)',
						zIndex: 999999,
						padding: '10px 14px',
						borderRadius: '8px',
						maxWidth: '420px',
						textAlign: 'center',
						boxShadow: 'var(--p-shadow-400)',
						fontSize: '13px',
						lineHeight: '20px',
						...TOAST_TONE_STYLE[ prefixToast.tone ],
					} }
				>
					{ prefixToast.content }
				</div>
			) }
		</OnboardingPage>
	)
}
