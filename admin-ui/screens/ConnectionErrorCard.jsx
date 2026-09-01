import { BlockStack, Button, Card, InlineStack, Text } from '@shopify/polaris'
import { errorMessage } from '../errors'

// Standard "this tab failed to load" state — used wherever a screen has
// nothing else to show until its initial fetch(es) succeed. For a partial
// failure alongside content that already loaded, use a Banner with a Retry
// action instead (see BillingToken.jsx) — this is only for the whole-screen case.
export default function ConnectionErrorCard( { error, onRetry } ) {
	return (
		<Card>
			<BlockStack gap="200">
				<Text as="p" tone="critical">
					{ errorMessage( error ) }
				</Text>
				<InlineStack>
					<Button onClick={ onRetry }>Retry</Button>
				</InlineStack>
			</BlockStack>
		</Card>
	)
}
