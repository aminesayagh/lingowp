import {
	BlockStack,
	Button,
	Card,
	DataTable,
	InlineGrid,
	InlineStack,
	ProgressBar,
	Text,
} from '@shopify/polaris'

// ponytail: static mock content for the layout pass — real numbers come from
// stats.daily + paypal.subscription in a follow-up step.
const METRICS = [
	{ label: 'Words used', value: '38,420' },
	{ label: 'Cache hit rate', value: '71%' },
	{ label: 'Pages served', value: '12,905' },
]

const DAILY_WORDS = [
	1800, 2200, 1600, 2400, 2100, 2600, 3900, 2000, 2300, 1900, 2500, 2700,
]

const LANGUAGE_ROWS = [
	[ 'French', '18,200', '13,100', '5,100' ],
	[ 'Spanish', '11,640', '8,900', '2,740' ],
	[ 'Arabic', '8,580', '5,300', '3,280' ],
]

// Plain CSS bars — a bar chart this simple doesn't need a charting library.
function DailyWordsChart( { values } ) {
	const max = Math.max( ...values )
	return (
		<div
			style={ {
				display: 'flex',
				alignItems: 'flex-end',
				gap: 6,
				height: 120,
			} }
		>
			{ values.map( ( v, i ) => (
				<div
					key={ i }
					title={ `${ v.toLocaleString() } words` }
					style={ {
						flex: 1,
						height: `${ Math.max( 4, ( v / max ) * 100 ) }%`,
						background: 'var(--p-color-bg-fill-info)',
						borderRadius: 2,
					} }
				/>
			) ) }
		</div>
	)
}

export default function BillingUsage() {
	return (
		<BlockStack gap="400">
			<InlineStack align="space-between" blockAlign="center">
				<Text as="h2" variant="headingMd">
					This billing period · 12 Aug – 12 Sep
				</Text>
			</InlineStack>

			<InlineGrid columns={ 3 } gap="300">
				{ METRICS.map( ( m ) => (
					<Card key={ m.label }>
						<BlockStack gap="100">
							<Text as="span" tone="subdued" variant="bodySm">
								{ m.label }
							</Text>
							<Text as="span" variant="headingLg">
								{ m.value }
							</Text>
						</BlockStack>
					</Card>
				) ) }
			</InlineGrid>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Plan quota
				</Text>
				<Card>
					<BlockStack gap="150">
						<ProgressBar progress={ 38 } tone="primary" />
						<Text as="span" tone="subdued" variant="bodySm">
							38,420 of 100,000 words · resets 12 Sep
						</Text>
					</BlockStack>
				</Card>
			</BlockStack>

			<Card>
				<InlineStack align="space-between" blockAlign="center">
					<BlockStack gap="050">
						<Text as="span">Extra word credits</Text>
						<Text as="span" tone="subdued" variant="bodySm">
							50,000 left, never expire
						</Text>
					</BlockStack>
					<Button>Buy credits</Button>
				</InlineStack>
			</Card>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Daily words
				</Text>
				<Card>
					<DailyWordsChart values={ DAILY_WORDS } />
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					By language
				</Text>
				<Card padding="0">
					<DataTable
						columnContentTypes={ [ 'text', 'numeric', 'numeric', 'numeric' ] }
						headings={ [ 'Language', 'Words', 'Cached', 'New' ] }
						rows={ LANGUAGE_ROWS }
					/>
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
