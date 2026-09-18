import { BlockStack, Text } from '@shopify/polaris'

const cfg = window.LingoWP || {}

// Terms · Privacy links that sit next to every "Connect to LingoWP Cloud"
// button (onboarding, the workspace rail's QuotaCard). Connecting sends site
// metadata to the cloud, so the disclosure travels with the button.
export default function CloudDisclosure() {
	if ( ! cfg.cloud_terms_url && ! cfg.cloud_privacy_url ) {
		return null
	}
	return (
		<BlockStack gap="050">
			<Text as="span" tone="subdued" variant="bodySm">
				{ cfg.cloud_terms_url && (
					<a
						href={ cfg.cloud_terms_url }
						target="_blank"
						rel="noreferrer"
					>
						Terms
					</a>
				) }
				{ cfg.cloud_terms_url && cfg.cloud_privacy_url && ' · ' }
				{ cfg.cloud_privacy_url && (
					<a
						href={ cfg.cloud_privacy_url }
						target="_blank"
						rel="noreferrer"
					>
						Privacy Policy
					</a>
				) }
			</Text>
		</BlockStack>
	)
}
