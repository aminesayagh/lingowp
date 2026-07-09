import { BlockStack, Card, Page, Spinner, Text } from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

// Step 2 of 2. Nothing to configure here — the initial scan runs automatically and
// the screen redirects to the dashboard once it finishes. No counts/percentages
// (arch: not actionable), and no manual controls.
export default function SettingUp( { onDone } ) {
	const [ status, setStatus ] = useState( 'running' );

	// Light polling of scan status.
	useEffect( () => {
		let active = true;
		const tick = () => {
			api.get( '/scan/status' ).then( ( res ) => {
				if ( active ) {
					setStatus( res.status || 'running' );
				}
			} );
		};
		tick();
		const id = setInterval( tick, 3000 );
		return () => {
			active = false;
			clearInterval( id );
		};
	}, [] );

	// When the scan is done (completed or failed), finish onboarding and redirect.
	// A short beat lets the user read the result first.
	useEffect( () => {
		if ( status === 'running' ) {
			return;
		}
		const delay = status === 'failed' ? 2500 : 1200;
		const id = setTimeout( () => {
			api.post( '/onboarding/complete' ).then( ( res ) => {
				onDone( res.next || 'dashboard_empty_language' );
			} );
		}, delay );
		return () => clearTimeout( id );
	}, [ status ] );

	return (
		<Page title="Setting up your site" narrowWidth>
			<Card>
				<BlockStack gap="300" inlineAlign="start">
					{ status === 'running' && (
						<>
							<Spinner accessibilityLabel="Scanning" size="small" />
							<Text as="p" variant="bodyMd">
								We&#39;re automatically scanning your site for translatable content.
							</Text>
							<Text as="p" tone="subdued" variant="bodySm">
								Scanning in the background… this only takes a moment.
							</Text>
						</>
					) }

					{ status === 'completed' && (
						<>
							<Text as="h2" variant="headingMd">Setup is ready</Text>
							<Text as="p" tone="subdued" variant="bodyMd">Opening your dashboard…</Text>
						</>
					) }

					{ status === 'failed' && (
						<>
							<Text as="h2" variant="headingMd">Setup could not finish</Text>
							<Text as="p" tone="subdued" variant="bodyMd">
								We could not complete the automatic scan. Opening your dashboard — your setup may need support review.
							</Text>
						</>
					) }
				</BlockStack>
			</Card>
		</Page>
	);
}
