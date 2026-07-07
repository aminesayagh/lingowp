import { BlockStack, Button, Card, Page, Spinner, Text } from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

// Step 2 of 2. The initial scan runs in the background; the user can leave for the
// dashboard at any time. No counts/percentages are shown (arch: not actionable).
export default function SettingUp( { onDone } ) {
	const [ status, setStatus ] = useState( 'running' );
	const [ finishing, setFinishing ] = useState( false );

	async function finish() {
		setFinishing( true );
		const res = await api.post( '/onboarding/complete' );
		onDone( res.next || 'dashboard_empty_language' );
	}

	// Light polling of scan status.
	useEffect( () => {
		let active = true;
		const tick = () => {
			api.get( '/scan/status' ).then( ( res ) => {
				if ( ! active ) {
					return;
				}
				setStatus( res.status || 'running' );
			} );
		};
		tick();
		const id = setInterval( tick, 3000 );
		return () => {
			active = false;
			clearInterval( id );
		};
	}, [] );

	// When the scan reports completed, move on automatically after a short beat.
	useEffect( () => {
		if ( status === 'completed' ) {
			const id = setTimeout( finish, 1500 );
			return () => clearTimeout( id );
		}
	}, [ status ] );

	return (
		<Page title="Setting up your site" subtitle="Step 2 of 2">
			<Card>
				<BlockStack gap="400" inlineAlign="start">
					{ status === 'running' && (
						<>
							<BlockStack gap="200" inlineAlign="start">
								<Spinner accessibilityLabel="Scanning" size="small" />
								<Text as="p" variant="bodyMd">
									We&#39;re automatically scanning your site for translatable content.
								</Text>
								<Text as="p" tone="subdued" variant="bodySm">
									Scanning in the background… No need to wait. You can go to the dashboard while setup continues.
								</Text>
							</BlockStack>
							<Button variant="primary" loading={ finishing } onClick={ finish }>
								Go to dashboard
							</Button>
						</>
					) }

					{ status === 'completed' && (
						<BlockStack gap="200" inlineAlign="start">
							<Text as="h2" variant="headingMd">Setup is ready</Text>
							<Text as="p" tone="subdued" variant="bodyMd">Opening your dashboard…</Text>
						</BlockStack>
					) }

					{ status === 'failed' && (
						<>
							<BlockStack gap="200" inlineAlign="start">
								<Text as="h2" variant="headingMd">Setup could not finish</Text>
								<Text as="p" tone="subdued" variant="bodyMd">
									We could not complete the automatic scan. Your setup may need support review.
								</Text>
							</BlockStack>
							<Button variant="primary" loading={ finishing } onClick={ finish }>
								Go to dashboard
							</Button>
						</>
					) }
				</BlockStack>
			</Card>
		</Page>
	);
}
