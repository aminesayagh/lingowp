import { BlockStack, Button, Card, ProgressBar, Text } from '@shopify/polaris'

// Rail quota card (wireframe 2.1 footer / 3.2). Fed by GET /quota; shows a
// placeholder until the backend usage endpoint answers. When AI is unavailable
// (backend off / disconnected) it says so — the plugin still works offline, only
// AI actions are disabled.
export default function QuotaCard( { quota, aiAvailable } ) {
	if ( ! quota ) {
		return (
			<Card>
				<BlockStack gap="100">
					<Text as="span" variant="bodySm">
						Free plan
					</Text>
					<Text
						as="span"
						tone={ aiAvailable ? 'subdued' : 'caution' }
						variant="bodySm"
					>
						{ aiAvailable
							? 'Usage shown after connect'
							: 'AI is off — reconnect Translangs in Settings to enable AI translation.' }
					</Text>
				</BlockStack>
			</Card>
		)
	}

	const {
		plan_name: plan,
		word_limit: limit,
		words_used: used,
		words_remaining: remaining,
		renewal_date: renews,
	} = quota
	const pct =
		limit > 0 ? Math.min( 100, Math.round( ( used / limit ) * 100 ) ) : 0
	const nearLimit = limit > 0 && remaining <= limit * 0.1

	return (
		<Card>
			<BlockStack gap="200">
				<Text as="span" variant="headingSm">
					{ plan || 'Plan & usage' }
				</Text>
				<Text as="span" variant="bodySm">
					{ used.toLocaleString() } / { limit.toLocaleString() } words
				</Text>
				<ProgressBar
					progress={ pct }
					size="small"
					tone={ nearLimit ? 'critical' : 'primary' }
				/>
				<Text as="span" tone="subdued" variant="bodySm">
					{ remaining.toLocaleString() } remaining
					{ renews ? ` · resets ${ renews }` : '' }
				</Text>
				{ nearLimit && (
					<Text as="span" tone="critical" variant="bodySm">
						Near limit — upgrade to keep translating.
					</Text>
				) }
				<Button
					variant="plain"
					url="https://translangs.com/upgrade"
					external
				>
					Upgrade plan
				</Button>
			</BlockStack>
		</Card>
	)
}
