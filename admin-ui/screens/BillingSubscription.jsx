import {
	Badge,
	Banner,
	BlockStack,
	Button,
	Card,
	DataTable,
	InlineGrid,
	InlineStack,
	Modal,
	ProgressBar,
	Text,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'

// ponytail: static mock content for this layout pass — real data
// (billing_subscriptions, allowance_periods, licenses, paypal.receipts)
// lands in a follow-up step once the backend billing endpoints exist.
const PLANS = [
	{
		id: 'starter',
		name: 'Starter',
		price_monthly: '$9',
		allowance_words: 50000,
		site_limit: 1,
	},
	{
		id: 'pro',
		name: 'Pro',
		price_monthly: '$19',
		allowance_words: 200000,
		site_limit: 3,
		badge: 'Most popular',
	},
	{
		id: 'business',
		name: 'Business',
		price_monthly: '$49',
		allowance_words: 1000000,
		site_limit: 10,
	},
]

const WORD_PACKS = [
	{ id: 'pack_50k', words: 50000, price: '$5', label: '50,000 words' },
	{
		id: 'pack_200k',
		words: 200000,
		price: '$15',
		label: '200,000 words',
		badge: 'Best value',
	},
	{ id: 'pack_1m', words: 1000000, price: '$49', label: '1,000,000 words' },
]

const EXPIRY_REASON_TEXT = {
	payment_failed: "last payment didn't go through",
	cancelled: 'you cancelled this plan',
	term_ended: 'the paid term ended',
}

const RECEIPT_STATUS = {
	COMPLETED: { label: 'Paid', tone: 'success' },
	PENDING: { label: 'Pending', tone: 'warning' },
	DENIED: { label: 'Denied', tone: 'critical' },
	FAILED: { label: 'Failed', tone: 'critical' },
}

const RECEIPT_ROWS = [
	{
		time: '12 Aug 26',
		item_label: 'Monthly plan',
		gross_amount: '$19.00',
		status: 'COMPLETED',
	},
	{
		time: '02 Aug 26',
		item_label: 'Extra words (200,000)',
		gross_amount: '$15.00',
		status: 'COMPLETED',
	},
	{
		time: '12 Jul 26',
		item_label: 'Monthly plan',
		gross_amount: '$19.00',
		status: 'PENDING',
	},
]

// ponytail: one mock "current subscription" per state, just enough to
// drive the copy — swap for billing_subscriptions + allowance_periods.
const MOCK = {
	active: {
		plan: PLANS[ 1 ],
		next_billing_date: '12 Sep 2026',
		period: {
			starts_at: '12 Aug',
			ends_at: '12 Sep',
			consumed_words: 172000,
			allowance_words: 200000,
		},
		purchased_words: 50000,
		sites_used: 2,
		payer_email_masked: 'a...e@mail.com',
	},
	expired: {
		plan: PLANS[ 1 ],
		ended_at: '02 Jul 2026',
		expiry_reason: 'cancelled',
		purchased_words: 25000,
	},
}

function planMeta( plan ) {
	return `${ plan.allowance_words.toLocaleString() } words a month · ${
		plan.site_limit
	} site${ plan.site_limit > 1 ? 's' : '' }`
}

function PlanCard( { plan, badge, actionLabel, disabled, onAction } ) {
	return (
		<Card key={ plan.id }>
			<BlockStack gap="200">
				<InlineStack gap="150" blockAlign="center">
					<Text as="h4" variant="headingSm">
						{ plan.name }
					</Text>
					{ badge && (
						<Badge tone={ badge.tone || 'info' }>
							{ badge.text }
						</Badge>
					) }
				</InlineStack>
				<Text as="span" tone="subdued">
					{ plan.price_monthly } a month
				</Text>
				<Text as="span" tone="subdued" variant="bodySm">
					{ planMeta( plan ) }
				</Text>
				<Button
					fullWidth
					disabled={ disabled }
					variant={ disabled ? 'secondary' : 'primary' }
					onClick={ onAction }
				>
					{ actionLabel }
				</Button>
			</BlockStack>
		</Card>
	)
}

function ReceiptsCard() {
	return (
		<Card padding="0">
			<DataTable
				columnContentTypes={ [ 'text', 'text', 'text', 'text' ] }
				headings={ [ 'Date', 'What for', 'Amount', 'Status' ] }
				rows={ RECEIPT_ROWS.map( ( r ) => {
					const s = RECEIPT_STATUS[ r.status ]
					return [
						r.time,
						r.item_label,
						r.gross_amount,
						<Badge key={ r.time } tone={ s.tone }>
							{ s.label }
						</Badge>,
					]
				} ) }
			/>
		</Card>
	)
}

function EmptyReceipts() {
	return (
		<Card>
			<Text as="span" tone="subdued">
				No receipts yet.
			</Text>
		</Card>
	)
}

function BuyWordsModal( { open, onClose, purchasedWords } ) {
	const [ selected, setSelected ] = useState( 'pack_200k' )
	const pack = WORD_PACKS.find( ( p ) => p.id === selected )

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title="Add extra words"
			primaryAction={ {
				content: `Pay ${ pack.price }`,
				onAction: onClose,
			} }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<BlockStack gap="300">
					<Text as="p" tone="subdued">
						Extra words never expire. They&apos;re used after your
						monthly words run out.
					</Text>
					<BlockStack gap="200">
						{ WORD_PACKS.map( ( p ) => (
							<Card key={ p.id }>
								<InlineStack
									align="space-between"
									blockAlign="center"
								>
									<InlineStack gap="150" blockAlign="center">
										<Text as="span" variant="headingSm">
											{ p.label }
										</Text>
										{ p.badge && (
											<Badge tone="info">
												{ p.badge }
											</Badge>
										) }
									</InlineStack>
									<InlineStack gap="200" blockAlign="center">
										<Text as="span" variant="headingSm">
											{ p.price }
										</Text>
										<Button
											variant={
												selected === p.id
													? 'primary'
													: 'secondary'
											}
											onClick={ () =>
												setSelected( p.id )
											}
										>
											{ selected === p.id
												? 'Selected'
												: 'Select' }
										</Button>
									</InlineStack>
								</InlineStack>
							</Card>
						) ) }
					</BlockStack>
					<Text as="span" tone="subdued" variant="bodySm">
						You&apos;ll have{ ' ' }
						{ ( purchasedWords + pack.words ).toLocaleString() }{ ' ' }
						extra words after this.
					</Text>
					<Text as="span" tone="subdued" variant="bodySm">
						You&apos;ll pay through PayPal. Nothing is charged until
						you confirm there.
					</Text>
				</BlockStack>
			</Modal.Section>
		</Modal>
	)
}

function NoneState() {
	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Add AI translation
				</Text>
				<Text as="span" tone="subdued">
					We translate new content for you automatically, as you
					publish it.
				</Text>
				<InlineGrid columns={ 3 } gap="300">
					{ PLANS.map( ( plan ) => (
						<PlanCard
							key={ plan.id }
							plan={ plan }
							badge={
								plan.badge ? { text: plan.badge } : undefined
							}
							actionLabel={ `Choose ${ plan.name }` }
						/>
					) ) }
				</InlineGrid>
				<Text as="span" tone="subdued" variant="bodySm">
					Pay yearly and get two months free.
				</Text>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				<EmptyReceipts />
			</BlockStack>
		</BlockStack>
	)
}

function ActiveState( { onBuyWords } ) {
	const {
		plan,
		next_billing_date: nextBillingDate,
		period,
		purchased_words: purchasedWords,
		sites_used: sitesUsed,
		payer_email_masked: payerEmailMasked,
	} = MOCK.active
	const [ cancelOpen, setCancelOpen ] = useState( false )
	const [ downgradeWarning, setDowngradeWarning ] = useState( null )

	const pct = Math.round(
		( period.consumed_words / period.allowance_words ) * 100
	)
	const nearLimit = period.consumed_words / period.allowance_words >= 0.85
	const showSites = plan.site_limit > 1

	function trySwitch( target ) {
		if ( target.site_limit < sitesUsed ) {
			setDowngradeWarning( target )
			return
		}
		setDowngradeWarning( null )
	}

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
								{ plan.name }
							</Text>
							<Badge tone="success">Active</Badge>
						</InlineStack>
						<Text as="span" tone="subdued">
							{ plan.price_monthly } a month · next charge{ ' ' }
							{ nextBillingDate }
						</Text>
						<BlockStack gap="150">
							<Text as="span" tone="subdued" variant="bodySm">
								Words this period · { period.starts_at } to{ ' ' }
								{ period.ends_at }
							</Text>
							<ProgressBar
								progress={ pct }
								tone={ nearLimit ? 'critical' : 'primary' }
							/>
							<Text as="span" tone="subdued" variant="bodySm">
								{ period.consumed_words.toLocaleString() } of{ ' ' }
								{ period.allowance_words.toLocaleString() } used
							</Text>
							{ nearLimit && (
								<Text
									as="span"
									tone="critical"
									variant="bodySm"
								>
									You&apos;re close to your limit for this
									period. Add extra words to keep going.
								</Text>
							) }
						</BlockStack>
						<InlineStack gap="200">
							<Button>Update billing</Button>
							<Button
								variant="plain"
								tone="critical"
								onClick={ () => setCancelOpen( true ) }
							>
								Cancel plan
							</Button>
						</InlineStack>
					</BlockStack>
				</Card>
			</BlockStack>

			<InlineGrid columns={ showSites ? 2 : 1 } gap="300">
				{ showSites && (
					<Card>
						<BlockStack gap="150">
							<Text as="span" tone="subdued" variant="bodySm">
								Connected sites
							</Text>
							<Text as="span" variant="headingLg">
								{ sitesUsed } of { plan.site_limit } used
							</Text>
							<Button>Manage sites</Button>
						</BlockStack>
					</Card>
				) }
				<Card>
					<BlockStack gap="150">
						<Text as="span" tone="subdued" variant="bodySm">
							Extra words in reserve
						</Text>
						<Text as="span" variant="headingLg">
							{ purchasedWords.toLocaleString() }
						</Text>
						<Text as="span" tone="subdued" variant="bodySm">
							Don&apos;t expire
						</Text>
						<Button onClick={ onBuyWords }>Buy more</Button>
					</BlockStack>
				</Card>
			</InlineGrid>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Change plan
				</Text>
				{ downgradeWarning && (
					<Banner
						tone="warning"
						onDismiss={ () => setDowngradeWarning( null ) }
					>
						You have { sitesUsed } sites connected.{ ' ' }
						{ downgradeWarning.name } allows{ ' ' }
						{ downgradeWarning.site_limit }. Disconnect the extras
						first.
					</Banner>
				) }
				<InlineGrid columns={ 3 } gap="300">
					{ PLANS.map( ( target ) =>
						target.id === plan.id ? (
							<PlanCard
								key={ target.id }
								plan={ target }
								badge={ { text: 'Your plan', tone: 'success' } }
								actionLabel="Your plan"
								disabled
							/>
						) : (
							<PlanCard
								key={ target.id }
								plan={ target }
								actionLabel={ `Switch to ${ target.name }` }
								onAction={ () => trySwitch( target ) }
							/>
						)
					) }
				</InlineGrid>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					How you pay
				</Text>
				<Card>
					<InlineStack align="space-between" blockAlign="center">
						<Text as="span">PayPal · { payerEmailMasked }</Text>
						<Button>Change</Button>
					</InlineStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				<ReceiptsCard />
			</BlockStack>

			<Modal
				open={ cancelOpen }
				onClose={ () => setCancelOpen( false ) }
				title="Cancel your plan?"
				primaryAction={ {
					content: 'Cancel plan',
					destructive: true,
					onAction: () => setCancelOpen( false ),
				} }
				secondaryActions={ [
					{
						content: 'Keep plan',
						onAction: () => setCancelOpen( false ),
					},
				] }
			>
				<Modal.Section>
					<Text as="p">
						Your site stays translated, but new content won&apos;t
						be translated automatically.
					</Text>
				</Modal.Section>
			</Modal>
		</BlockStack>
	)
}

function ExpiredState() {
	const {
		plan,
		ended_at: endedAt,
		expiry_reason: expiryReason,
		purchased_words: purchasedWords,
	} = MOCK.expired

	function scrollToRestart() {
		document
			.getElementById( 'restart' )
			?.scrollIntoView( { behavior: 'smooth' } )
	}

	return (
		<BlockStack gap="400">
			<Banner tone="warning">
				<BlockStack gap="150">
					<Text as="h3" variant="headingSm">
						AI translation is paused
					</Text>
					<Text as="span">
						Your plan ended on { endedAt }. Your site is still fully
						translated — new content just won&apos;t be translated
						automatically until you restart.
					</Text>
					<Button onClick={ scrollToRestart }>Restart my plan</Button>
				</BlockStack>
			</Banner>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Your last plan
				</Text>
				<Card>
					<BlockStack gap="200">
						<InlineStack gap="200" blockAlign="center">
							<Text as="h2" variant="headingMd">
								{ plan.name }
							</Text>
							<Badge tone="warning">Ended</Badge>
						</InlineStack>
						<Text as="span" tone="subdued">
							Ended { endedAt } ·{ ' ' }
							{ EXPIRY_REASON_TEXT[ expiryReason ] }
						</Text>
					</BlockStack>
				</Card>
			</BlockStack>

			{ purchasedWords > 0 && (
				<Card>
					<Text as="span">
						You have { purchasedWords.toLocaleString() } extra
						words.
					</Text>
				</Card>
			) }

			<BlockStack id="restart" gap="200">
				<Text as="h3" variant="headingSm">
					Start again
				</Text>
				<InlineGrid columns={ 3 } gap="300">
					{ PLANS.map( ( target ) =>
						target.id === plan.id ? (
							<PlanCard
								key={ target.id }
								plan={ target }
								badge={ { text: 'Your last plan' } }
								actionLabel={ `Restart ${ target.name }` }
							/>
						) : (
							<PlanCard
								key={ target.id }
								plan={ target }
								actionLabel={ `Choose ${ target.name }` }
							/>
						)
					) }
				</InlineGrid>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				<ReceiptsCard />
			</BlockStack>
		</BlockStack>
	)
}

const STATUS_OPTIONS = [
	{ id: 'none', label: 'No subscription' },
	{ id: 'active', label: 'Active' },
	{ id: 'expired', label: 'Expired' },
]

export default function BillingSubscription() {
	// ponytail: preview switcher for this mocked pass — subscription.status
	// comes from the backend once billing endpoints are wired.
	const [ status, setStatus ] = useState( 'active' )
	const [ buyWordsOpen, setBuyWordsOpen ] = useState( false )

	return (
		<BlockStack gap="400">
			<InlineStack gap="100">
				{ STATUS_OPTIONS.map( ( o ) => (
					<Button
						key={ o.id }
						size="slim"
						variant={ status === o.id ? 'primary' : 'tertiary' }
						onClick={ () => setStatus( o.id ) }
					>
						{ o.label }
					</Button>
				) ) }
			</InlineStack>

			{ status === 'none' && <NoneState /> }
			{ status === 'active' && (
				<ActiveState onBuyWords={ () => setBuyWordsOpen( true ) } />
			) }
			{ status === 'expired' && <ExpiredState /> }

			<BuyWordsModal
				open={ buyWordsOpen }
				onClose={ () => setBuyWordsOpen( false ) }
				purchasedWords={ MOCK.active.purchased_words }
			/>
		</BlockStack>
	)
}
