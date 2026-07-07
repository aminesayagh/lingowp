import {
	Banner,
	BlockStack,
	Button,
	Card,
	Checkbox,
	ChoiceList,
	Page,
	Text,
} from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';
import { errorMessage } from '../errors';

// Code-only categories come from the backend; labels are a frontend concern.
function humanize( code ) {
	return code.replace( /[_-]+/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

// Step 1 of 2. Source/default language is intentionally NOT shown here — it is
// inherited from WordPress. Toggle defaults follow the wireframe (cookie +
// browser detection on), not stored options, since this is first-run config.
export default function Configuration( { onDone } ) {
	const [ categoryChoices, setCategoryChoices ] = useState( [] );
	const [ categories, setCategories ] = useState( [] );
	const [ prefix, setPrefix ] = useState( false );
	const [ cookie, setCookie ] = useState( true );
	const [ browser, setBrowser ] = useState( true );
	const [ autoRedirect, setAutoRedirect ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );

	useEffect( () => {
		api.get( '/onboarding/categories' ).then( ( res ) => {
			if ( res.ok && Array.isArray( res.categories ) ) {
				setCategoryChoices(
					res.categories.map( ( code ) => ( { label: humanize( code ), value: code } ) )
				);
			}
		} );
	}, [] );

	// Auto redirect requires browser detection (arch dependency).
	const canAutoRedirect = browser;
	const effectiveAutoRedirect = autoRedirect && canAutoRedirect;

	async function submit() {
		// Categories are optional (and the catalog is only available when the backend
		// is reachable) — never block Continue on them.
		setSubmitting( true );
		setError( '' );

		const res = await api.post( '/onboarding/configure', {
			categories,
			prefix_default_language: prefix,
			cookie_preference: cookie,
			browser_detection: browser,
			auto_redirect: effectiveAutoRedirect,
		} );

		setSubmitting( false );
		if ( res.ok ) {
			onDone();
		} else {
			setError( res.error || 'ERR_BACKEND' );
		}
	}

	return (
		<Page title="Configure Translangs">
			<BlockStack gap="400">
				<Text as="p" variant="bodyMd">
					Tell us about your site and how visitors should reach translated pages.
				</Text>

				{ error && <Banner tone="critical">{ errorMessage( error ) }</Banner> }

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
							helpText="Add /en to source URLs. Example: example.com/en"
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
									? 'Send visitors to their detected language. Visitors may be redirected automatically based on their browser language.'
									: 'Enable browser detection before using auto redirect.'
							}
							checked={ effectiveAutoRedirect }
							disabled={ ! canAutoRedirect }
							onChange={ setAutoRedirect }
						/>
					</BlockStack>
				</Card>

				<Button variant="primary" loading={ submitting } onClick={ submit }>
					Continue
				</Button>
			</BlockStack>
		</Page>
	);
}
