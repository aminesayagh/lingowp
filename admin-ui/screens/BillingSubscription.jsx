import {
	Badge,
	BlockStack,
	Button,
	Card,
	DataTable,
	InlineGrid,
	InlineStack,
	Text,
} from '@shopify/polaris'

// ponytail: static mock content for the layout pass — real data (paypal.subscription,
// paypal.transactions + orders.local) lands in a follow-up step.
const PLANS = [
	{
		name: 'Free',
		meta: '2 languages · 5k words / mo',
		action: 'Downgrade',
		current: false,
	},
	{
		name: 'Pro',
		meta: '10 languages · 100k words / mo',
		action: 'Current plan',
		current: true,
	},
	{
		name: 'Business',
		meta: 'Unlimited languages · 1M words / mo',
		action: 'Upgrade',
		current: false,
	},
]

const INVOICE_ROWS = [
	[ '12 Aug 26', 'Pro · monthly', '$19.00', 'Paid', 'PDF' ],
	[ '02 Aug 26', '50k word pack', '$12.00', 'Paid', 'PDF' ],
	[ '12 Jul 26', 'Pro · monthly', '$19.00', 'Paid', 'PDF' ],
]

export default function BillingSubscription() {
	return (
		<BlockStack gap="400">
			<Card>
				<BlockStack gap="300">
					<InlineStack gap="200" blockAlign="center">
						<Text as="h2" variant="headingMd">
							Pro
						</Text>
						<Badge tone="success">Active</Badge>
					</InlineStack>
					<Text as="span" tone="subdued">
						$19 / month · renews 12 Sep 2026 · billed through PayPal
					</Text>
					<InlineStack gap="200">
						<Button>Manage in PayPal</Button>
						<Button variant="plain" tone="critical">
							Cancel plan
						</Button>
					</InlineStack>
				</BlockStack>
			</Card>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Adjust plan
				</Text>
				<InlineGrid columns={ 3 } gap="300">
					{ PLANS.map( ( plan ) => (
						<Card key={ plan.name }>
							<BlockStack gap="200">
								<Text as="h4" variant="headingSm">
									{ plan.name }
									{ plan.current && (
										<Text as="span" tone="subdued">
											{ ' ' }
											· current
										</Text>
									) }
								</Text>
								<Text as="span" tone="subdued">
									{ plan.meta }
								</Text>
								<Button
									fullWidth
									disabled={ plan.current }
									variant={
										plan.current ? 'secondary' : 'primary'
									}
								>
									{ plan.action }
								</Button>
							</BlockStack>
						</Card>
					) ) }
				</InlineGrid>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Payment method
				</Text>
				<Card>
					<InlineStack align="space-between" blockAlign="center">
						<BlockStack gap="050">
							<Text as="span">PayPal · a...e@mail.com</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								Agreement I-9XK2....
							</Text>
						</BlockStack>
						<Button>Change</Button>
					</InlineStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Invoices
				</Text>
				<Card padding="0">
					<DataTable
						columnContentTypes={ [
							'text',
							'text',
							'text',
							'text',
							'text',
						] }
						headings={ [
							'Date',
							'Item',
							'Amount',
							'Status',
							'',
						] }
						rows={ INVOICE_ROWS.map( ( row ) => [
							row[ 0 ],
							row[ 1 ],
							row[ 2 ],
							<Badge key={ row[ 0 ] } tone="success">
								{ row[ 3 ] }
							</Badge>,
							<Button key={ `${ row[ 0 ] }-pdf` } variant="plain">
								{ row[ 4 ] }
							</Button>,
						] ) }
					/>
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
