import { BlockStack, Page } from '@shopify/polaris';

// Shared visual shell for every post-install step. Individual screens keep
// ownership of their cards and controls; this only fixes width and spacing.
export default function OnboardingPage( { children, title, subtitle } ) {
	return (
		<div className="w-full max-w-3xl">
			<Page title={ title } subtitle={ subtitle }>
				<BlockStack gap="400">
					{ children }
				</BlockStack>
			</Page>
		</div>
	);
}
