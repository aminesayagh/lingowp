import {
	Banner,
	BlockStack,
	Button,
	Checkbox,
	InlineStack,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { errorMessage } from '../errors'
import { useBackendPing, useConnect } from '../queries/connection'
import { useConfigureOnboarding } from '../queries/onboarding'
import CloudDisclosure from './CloudDisclosure'

// Step 1 of 2. Source/default language is intentionally NOT shown here — it is
// inherited from WordPress. Toggle defaults follow the wireframe (cookie +
// browser detection on), not stored options, since this is first-run config.
//
// Cloud connection is opt-in and decided here, not in the background: when
// the backend answers a ping the footer offers "Connect to LingoWP Cloud"
// (connect first, then save) as the primary action and "Continue without
// connecting" beside it; while the ping is pending or failed it's the plain
// Continue, so a slow or dark backend never holds the form.
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
	const connect = useConnect()
	const pingQ = useBackendPing()
	const cloudReachable = pingQ.data === true
	// connect() resolves { ok:false, error } rather than throwing — see
	// queries/connection.js — so its failure is kept here, not on the mutation.
	const [ connectError, setConnectError ] = useState( '' )
	// Which footer button is mid-flight, so only that one shows a spinner
	// while the other is merely disabled.
	const [ activeAction, setActiveAction ] = useState( null ) // 'connect' | 'manual' | null
	const busy = configure.isPending || connect.isPending
	const error = connectError || configure.error?.code || ''
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
		} finally {
			setActiveAction( null )
		}
	}

	function submitManually() {
		setActiveAction( 'manual' )
		return submit()
	}

	// Connect first, then save — a failed connect leaves the form as it was,
	// with both buttons still there, so the user can retry or continue
	// without the cloud. Settings are only written once, by submit().
	async function connectAndSubmit() {
		setConnectError( '' )
		setActiveAction( 'connect' )
		let res
		try {
			res = await connect.mutateAsync()
		} catch {
			res = { ok: false, error: 'ERR_UNREACHABLE' }
		}
		if ( ! res.ok ) {
			setConnectError( res.error || 'ERR_BACKEND' )
			setActiveAction( null )
			return
		}
		await submit()
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

			{ /* Reads with the form, not with the buttons: the disclosure is
			     something to take in while deciding, and the action row stays
			     two plain choices. Only shown when connecting is on offer. */ }
			{ cloudReachable && <CloudDisclosure /> }

			{ cloudReachable ? (
				<InlineStack align="end" blockAlign="center" gap="300">
					<Button
						variant="tertiary"
						loading={ activeAction === 'manual' }
						disabled={ busy }
						onClick={ submitManually }
					>
						Not now
					</Button>
					<Button
						variant="primary"
						loading={ activeAction === 'connect' }
						disabled={ busy }
						onClick={ connectAndSubmit }
					>
						Connect to Linvara AI
					</Button>
				</InlineStack>
			) : (
				<InlineStack align="end">
					<Button
						variant="primary"
						loading={ busy }
						onClick={ submitManually }
					>
						Continue
					</Button>
				</InlineStack>
			) }
		</BlockStack>
	)
}
