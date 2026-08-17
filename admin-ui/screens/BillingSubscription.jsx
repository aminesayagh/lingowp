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
		meta: '5,000 words a month, in 2 languages',
		action: 'Switch to Free',
		current: false,
	},
	{
		name: 'Pro',
		meta: '100,000 words a month, in 10 languages',
		action: 'Current plan',
		current: true,
	},
	{
		name: 'Business',
		meta: '1,000,000 words a month, in any language',
		action: 'Switch to Business',
		current: false,
	},
]

const INVOICE_ROWS = [
	[ '12 Aug 26', 'Monthly plan', '$19.00', 'Paid' ],
	[ '02 Aug 26', 'Extra words (50,000)', '$12.00', 'Paid' ],
	[ '12 Jul 26', 'Monthly plan', '$19.00', 'Paid' ],
]

export default function BillingSubscription() {
	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Your plan
				</Text>
				<Card>
					<BlockStack gap="300">
						<InlineStack gap="200" blockAlign="center">
							<Text as="h2" variant="headingMd">
								Pro
							</Text>
							<Badge tone="success">Active</Badge>
						</InlineStack>
						<Text as="span" tone="subdued">
							$19 a month · next charge 12 Sep 2026
						</Text>
						<InlineStack gap="200">
							<Button>Manage in PayPal</Button>
							<Button variant="plain" tone="critical">
								Cancel plan
							</Button>
						</InlineStack>
					</BlockStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Change plan
				</Text>
				<InlineGrid columns={ 3 } gap="300">
					{ PLANS.map( ( plan ) => (
						<Card key={ plan.name }>
							<BlockStack gap="200">
								<Text as="h4" variant="headingSm">
									{ plan.name }
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
						<Text as="span">PayPal · a...e@mail.com</Text>
						<Button>Manage in PayPal</Button>
					</InlineStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				<Card padding="0">
					<DataTable
						columnContentTypes={ [
							'text',
							'text',
							'text',
							'text',
						] }
						headings={ [
							'Date',
							'Description',
							'Amount',
							'Status',
						] }
						rows={ INVOICE_ROWS.map( ( row ) => [
							row[ 0 ],
							row[ 1 ],
							row[ 2 ],
							<InlineStack
								key={ row[ 0 ] }
								align="space-between"
								blockAlign="center"
								gap="200"
							>
								<Badge tone="success">{ row[ 3 ] }</Badge>
								<Button variant="plain">Receipt</Button>
							</InlineStack>,
						] ) }
					/>
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
