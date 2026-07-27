import { BlockStack, Checkbox, Modal, Text } from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

// Post-onboarding settings. v1 edits the WordPress-owned behavior toggles and
// shows the (frozen) default language read-only. Category editing and changing
// the default language are deferred — both require backend coordination.
//
// Note: the design calls for a right-side drawer, but @shopify/polaris ships no
// Drawer/Sheet, so v1 uses a Modal. A true drawer needs custom CSS (follow-up).
export default function SettingsModal( { open, defaultLanguage, onClose } ) {
	const [ prefix, setPrefix ] = useState( false );
	const [ cookie, setCookie ] = useState( true );
	const [ browser, setBrowser ] = useState( false );
	const [ autoRedirect, setAutoRedirect ] = useState( false );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		api.get( '/onboarding/state' ).then( ( res ) => {
			const s = res.settings || {};
			setPrefix( !! s.prefix_default_language );
			setCookie( !! s.cookie_preference );
			setBrowser( !! s.browser_detection );
			setAutoRedirect( !! s.auto_redirect );
		} );
	}, [ open ] );

	const canAutoRedirect = browser;

	async function save() {
		setSaving( true );
		await api.post( '/settings', {
			prefix_default_language: prefix,
			cookie_preference: cookie,
			browser_detection: browser,
			auto_redirect: autoRedirect && canAutoRedirect,
		} );
		setSaving( false );
		onClose();
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title="Settings"
			narrowWidth
			primaryAction={ { content: 'Save settings', onAction: save, loading: saving } }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<BlockStack gap="400">
					<BlockStack gap="200">
						<Text as="h3" variant="headingSm">Language behavior</Text>
						<Checkbox label="Prefix default language" helpText="Add /en to source URLs." checked={ prefix } onChange={ setPrefix } />
						<Checkbox label="Cookie preference" helpText="Remember a visitor's chosen language." checked={ cookie } onChange={ setCookie } />
						<Checkbox label="Browser detection" helpText="Detect language from the browser." checked={ browser } onChange={ setBrowser } />
						<Checkbox
							label="Auto redirect"
							helpText={ canAutoRedirect ? 'Send visitors to their detected language.' : 'Enable browser detection before using auto redirect.' }
							checked={ autoRedirect && canAutoRedirect }
							disabled={ ! canAutoRedirect }
							onChange={ setAutoRedirect }
						/>
					</BlockStack>

					<BlockStack gap="100">
						<Text as="h3" variant="headingSm">Default language</Text>
						<Text as="p" tone="subdued" variant="bodySm">
							{ defaultLanguage } — inherited from WordPress.
						</Text>
					</BlockStack>
				</BlockStack>
			</Modal.Section>
		</Modal>
	);
}
