import {
	Badge,
	Banner,
	BlockStack,
	Box,
	Button,
	ButtonGroup,
	Card,
	DataTable,
	InlineGrid,
	InlineStack,
	Modal,
	ProgressBar,
	Spinner,
	Text,
} from '@shopify/polaris'
import { useCallback, useEffect, useState } from '@wordpress/element'
import { parseAsString, parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from '../api'
import { PENDING_WORDS_KEY } from '../billingKeys'
import { errorMessage } from '../errors'
import { formatPlanKey } from '../planFormat'

const RECEIPT_STATUS = {
	COMPLETED: { label: 'Paid', tone: 'success' },
	PENDING: { label: 'Pending', tone: 'warning' },
	DENIED: { label: 'Denied', tone: 'critical' },
	FAILED: { label: 'Failed', tone: 'critical' },
}

// Survives the full-page round trip to PayPal and back (a real navigation,
// not a fetch — React state doesn't survive that, sessionStorage does).
// Self-cleaning: read once on return and removed immediately.
const PENDING_ACTION_KEY = 'translangs_pending_billing_action'

function planLabel( plan ) {
	return plan.tier.charAt( 0 ).toUpperCase() + plan.tier.slice( 1 )
}

function planMeta( plan ) {
	return `${ plan.monthly_words.toLocaleString() } words a month · ${
		plan.sites
	} site${ plan.sites > 1 ? 's' : '' }`
}

function money( amount, currency ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency,
	} ).format( parseFloat( amount ) )
}

function intervalLabel( plan ) {
	return plan.interval === 'yearly' ? 'a year' : 'a month'
}

// Each poll kind has its own idea of "done" — subscribe waits for the sub to
// go live, purchase waits for the word balance to move, change_plan waits for
// the webhook-backed pending_plan_change to reflect the requested plan.
function isPollSettled( polling, entitlements ) {
	if ( polling.kind === 'subscribe' ) {
		return entitlements.active === true
	}
	if ( polling.kind === 'change_plan' ) {
		return entitlements.pending_plan_change === polling.targetPlan
	}
	return entitlements.purchased_words > polling.before
}

function formatDate( iso ) {
	if ( ! iso ) {
		return '—'
	}
	return new Date( iso ).toLocaleDateString( 'en-US', {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} )
}

function receiptItem( transaction ) {
	if ( transaction.type === 'word_pack' ) {
		return transaction.words
			? `${ transaction.words.toLocaleString() } extra words`
			: formatPlanKey( transaction.item )
	}

	return formatPlanKey( transaction.item )
}

function PlanCard( { plan, badge, actionLabel, disabled, loading, onAction } ) {
	return (
		<Card key={ plan.plan_key }>
			<BlockStack gap="200">
				<InlineStack gap="150" blockAlign="center">
					<Text as="h4" variant="headingSm">
						{ planLabel( plan ) }
					</Text>
					{ badge && (
						<Badge tone={ badge.tone || 'info' }>
							{ badge.text }
						</Badge>
					) }
				</InlineStack>
				<Text as="span" tone="subdued">
					{ money( plan.price, plan.currency ) }{ ' ' }
					{ intervalLabel( plan ) }
				</Text>
				<Text as="span" tone="subdued" variant="bodySm">
					{ planMeta( plan ) }
				</Text>
				<Button
					fullWidth
					disabled={ disabled }
					loading={ loading }
					variant={ disabled ? 'secondary' : 'primary' }
					onClick={ onAction }
				>
					{ actionLabel }
				</Button>
			</BlockStack>
		</Card>
	)
}

function ReceiptsCard( {
	transactions,
	hasMore,
	loadingMore,
	loadMoreError,
	onLoadMore,
} ) {
	return (
		<Card padding="0">
			<DataTable
				columnContentTypes={ [ 'text', 'text', 'text', 'text' ] }
				headings={ [ 'Date', 'What for', 'Amount', 'Status' ] }
				rows={ transactions.map( ( t ) => {
					const s = RECEIPT_STATUS[ t.status ]
					return [
						formatDate( t.time ),
						receiptItem( t ),
						`${ t.amount } ${ t.currency }`,
						s ? (
							<Badge key={ t.id } tone={ s.tone }>
								{ s.label }
							</Badge>
						) : (
							<Badge key={ t.id }>{ t.status }</Badge>
						),
					]
				} ) }
			/>
			{ ( hasMore || loadMoreError ) && (
				<Box padding="300">
					<InlineStack align="center">
						<Button
							onClick={ onLoadMore }
							loading={ loadingMore }
							disabled={ loadingMore }
						>
							{ loadMoreError ? 'Retry loading more' : 'Load more' }
						</Button>
					</InlineStack>
				</Box>
			) }
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

function BuyWordsModal( {
	open,
	onClose,
	packs,
	purchasedWords,
	onBuy,
	loading,
	error,
} ) {
	const [ selected, setSelected ] = useState( packs[ 0 ]?.pack_id )
	const pack = packs.find( ( p ) => p.pack_id === selected ) || packs[ 0 ]

	if ( ! pack ) {
		return null
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title="Add extra words"
			primaryAction={ {
				content: `Pay ${ money( pack.price, pack.currency ) }`,
				loading,
				onAction: () => onBuy( pack.pack_id ),
			} }
			secondaryActions={ [
				{ content: 'Cancel', onAction: onClose, disabled: loading },
			] }
		>
			<Modal.Section>
				<BlockStack gap="300">
					{ error && (
						<Banner tone="critical">
							{ errorMessage( error ) }
						</Banner>
					) }
					<Text as="p" tone="subdued">
						Extra words never expire. They&apos;re used after your
						monthly words run out.
					</Text>
					<BlockStack gap="200">
						{ packs.map( ( p ) => (
							<Card key={ p.pack_id }>
								<InlineStack
									align="space-between"
									blockAlign="center"
								>
									<Text as="span" variant="headingSm">
										{ p.words.toLocaleString() } words
									</Text>
									<InlineStack gap="200" blockAlign="center">
										<Text as="span" variant="headingSm">
											{ money( p.price, p.currency ) }
										</Text>
										<Button
											disabled={ loading }
											variant={
												selected === p.pack_id
													? 'primary'
													: 'secondary'
											}
											onClick={ () =>
												setSelected( p.pack_id )
											}
										>
											{ selected === p.pack_id
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

// Shared by the plan picker (NotSubscribedState) and the change-plan grid
// (ActiveState) — one toggle, one look, in both places that filter plans by
// billing interval.
function IntervalToggle( { value, onChange } ) {
	return (
		<ButtonGroup variant="segmented">
			<Button
				pressed={ value === 'monthly' }
				onClick={ () => onChange( 'monthly' ) }
			>
				Monthly
			</Button>
			<Button
				pressed={ value === 'yearly' }
				onClick={ () => onChange( 'yearly' ) }
			>
				Yearly
			</Button>
		</ButtonGroup>
	)
}

function NotSubscribedState( {
	plans,
	transactions,
	hasMoreTransactions,
	transactionsLoadingMore,
	transactionsLoadMoreError,
	onLoadMoreTransactions,
	actionLoading,
	actionError,
	pendingPlan,
	pendingApproveUrl,
	onChoose,
} ) {
	const hasHistory = transactions.length > 0
	// Yearly by default — it's the better deal, and the plan grid below is
	// what actually makes it a real choice instead of just a hint line.
	const [ billingInterval, setBillingInterval ] = useState( 'yearly' )
	const intervalPlans = plans.filter(
		( p ) => p.interval === billingInterval
	)

	return (
		<BlockStack gap="400">
			{ hasHistory && (
				<Banner tone="warning">
					<Text as="span">
						Your subscription isn&apos;t active. Your site stays
						translated — new content won&apos;t be translated
						automatically until you restart.
					</Text>
				</Banner>
			) }

			<BlockStack gap="200">
				<InlineStack
					align="space-between"
					blockAlign="end"
					gap="400"
					wrap={ false }
				>
					<BlockStack gap="050">
						<Text as="h3" variant="headingSm">
							{ hasHistory
								? 'Start again'
								: 'Add AI translation' }
						</Text>
						{ ! hasHistory && (
							<Text as="span" tone="subdued">
								We translate new content for you automatically,
								as you publish it.
							</Text>
						) }
					</BlockStack>
					<IntervalToggle
						value={ billingInterval }
						onChange={ setBillingInterval }
					/>
				</InlineStack>
				{ pendingApproveUrl && (
					<PendingSubscriptionBanner
						plans={ plans }
						pendingPlan={ pendingPlan }
						pendingApproveUrl={ pendingApproveUrl }
					/>
				) }
				{ actionError && (
					<Banner tone="critical">
						{ errorMessage( actionError ) }
					</Banner>
				) }
				<InlineGrid columns={ 3 } gap="300">
					{ intervalPlans.map( ( plan ) => (
						<PlanCard
							key={ plan.plan_key }
							plan={ plan }
							badge={
								plan.tier === 'pro'
									? { text: 'Most popular' }
									: undefined
							}
							actionLabel={
								hasHistory
									? `Restart ${ planLabel( plan ) }`
									: `Choose ${ planLabel( plan ) }`
							}
							loading={ actionLoading === plan.plan_key }
							disabled={
								actionLoading && actionLoading !== plan.plan_key
							}
							onAction={ () => onChoose( plan.plan_key ) }
						/>
					) ) }
				</InlineGrid>
				{ billingInterval === 'monthly' && (
					<Text as="span" tone="subdued" variant="bodySm">
						Pay yearly and get two months free.
					</Text>
				) }
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				{ transactions.length === 0 ? (
					<EmptyReceipts />
				) : (
					<ReceiptsCard
						transactions={ transactions }
						hasMore={ hasMoreTransactions }
						loadingMore={ transactionsLoadingMore }
						loadMoreError={ transactionsLoadMoreError }
						onLoadMore={ onLoadMoreTransactions }
					/>
				) }
			</BlockStack>
		</BlockStack>
	)
}

// entitlements.active is false but a PayPal checkout was already started and
// never finished (closed tab, browser back, etc.) — the backend keeps that
// approve_url alive rather than losing track of it. This is a notice, not a
// replacement for the plan picker below: the admin can either resume this
// checkout, or ignore it and pick a plan (start over) — the latter still 409s
// (already_subscribed) against the current backend, since there is no
// cancel-pending action yet; that fails visibly via the picker's own
// actionError banner rather than being silently blocked here.
function PendingSubscriptionBanner( {
	plans,
	pendingPlan,
	pendingApproveUrl,
} ) {
	const plan = plans.find( ( p ) => p.plan_key === pendingPlan )

	return (
		<Banner
			tone="warning"
			action={ {
				content: 'Resume checkout',
				onAction: () => window.location.assign( pendingApproveUrl ),
			} }
		>
			<Text as="span">
				You started subscribing to{ ' ' }
				{ plan ? planLabel( plan ) : 'a plan' } but didn&apos;t finish
				checkout on PayPal. Resume it below, or pick a plan to start
				over.
			</Text>
		</Banner>
	)
}

// entitlements.pending_approve_url is surfaced inside NotSubscribedState's
// own body, not up here — it never replaces the picker, so this stays the
// same two-way split as before that field existed.
function SubscriptionStateBody( {
	entitlements,
	plans,
	transactions,
	hasMoreTransactions,
	transactionsLoadingMore,
	transactionsLoadMoreError,
	onLoadMoreTransactions,
	onBuyWords,
	onChangePlan,
	changeLoading,
	changeError,
	actionLoading,
	actionError,
	onChoose,
} ) {
	if ( entitlements.active ) {
		return (
			<ActiveState
				entitlements={ entitlements }
				plans={ plans }
				transactions={ transactions }
				hasMoreTransactions={ hasMoreTransactions }
				transactionsLoadingMore={ transactionsLoadingMore }
				transactionsLoadMoreError={ transactionsLoadMoreError }
				onLoadMoreTransactions={ onLoadMoreTransactions }
				onBuyWords={ onBuyWords }
				onChangePlan={ onChangePlan }
				changeLoading={ changeLoading }
				changeError={ changeError }
			/>
		)
	}

	return (
		<NotSubscribedState
			plans={ plans }
			transactions={ transactions }
			hasMoreTransactions={ hasMoreTransactions }
			transactionsLoadingMore={ transactionsLoadingMore }
			transactionsLoadMoreError={ transactionsLoadMoreError }
			onLoadMoreTransactions={ onLoadMoreTransactions }
			actionLoading={ actionLoading }
			actionError={ actionError }
			pendingPlan={ entitlements.pending_plan }
			pendingApproveUrl={ entitlements.pending_approve_url }
			onChoose={ onChoose }
		/>
	)
}

function ActiveState( {
	entitlements,
	plans,
	transactions,
	hasMoreTransactions,
	transactionsLoadingMore,
	transactionsLoadMoreError,
	onLoadMoreTransactions,
	onBuyWords,
	onChangePlan,
	changeLoading,
	changeError,
} ) {
	const [ downgradeWarning, setDowngradeWarning ] = useState( null )

	const plan = plans.find( ( p ) => p.plan_key === entitlements.plan )

	if ( ! plan ) {
		return null
	}

	// Tier and cadence are separate axes (see reference-billing-api-integration.md
	// §3) — change-plan is tier-only. Crossing cadence (monthly ↔ yearly) has no
	// backend route yet, so the picker only ever offers plans on the customer's
	// *current* cadence — never user-selectable here.
	const intervalPlans = plans.filter( ( p ) => p.interval === plan.interval )
	const changePending = !! entitlements.pending_plan_change
	const allowance = entitlements.monthly_allowance ?? 0
	const remaining = entitlements.monthly_remaining ?? 0
	const consumed = Math.max( 0, allowance - remaining )
	const pct = allowance > 0 ? Math.round( ( consumed / allowance ) * 100 ) : 0
	const nearLimit = allowance > 0 && consumed / allowance >= 0.85
	const sitesLimit = entitlements.sites_limit

	function trySwitch( target ) {
		// entitlements has no sites_used field, only sites_limit — this can
		// only warn on a lower site *limit*, not a real usage conflict.
		if ( ( entitlements.sites_limit ?? 1 ) > ( target.sites ?? 1 ) ) {
			setDowngradeWarning( target )
			return
		}
		setDowngradeWarning( null )
		onChangePlan( target.plan_key )
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
								{ planLabel( plan ) }
							</Text>
							<Badge tone="success">Active</Badge>
						</InlineStack>
						<Text as="span" tone="subdued">
							{ `${ money(
								plan.price,
								plan.currency
							) } ${ intervalLabel(
								plan
							) } · renews ${ formatDate(
								entitlements.period_end
							) }${
								sitesLimit
									? ` · ${ sitesLimit } site${
											sitesLimit > 1 ? 's' : ''
									  }`
									: ''
							}` }
						</Text>
						{ allowance > 0 && (
							<ProgressBar
								progress={ pct }
								tone={ nearLimit ? 'critical' : 'primary' }
							/>
						) }
						<InlineStack gap="300" blockAlign="center" wrap>
							{ allowance > 0 && (
								<Text as="span" tone="subdued" variant="bodySm">
									{ `${ consumed.toLocaleString() } of ${ allowance.toLocaleString() } monthly words used` }
								</Text>
							) }
							{ entitlements.purchased_words > 0 && (
								<Text as="span" tone="subdued" variant="bodySm">
									Extra words:{ ' ' }
									<Text as="span" fontWeight="semibold">
										{ entitlements.purchased_words.toLocaleString() }
									</Text>
								</Text>
							) }
							<Button variant="secondary" onClick={ onBuyWords }>
								Buy words
							</Button>
						</InlineStack>
						{ nearLimit && (
							<Text as="span" tone="critical" variant="bodySm">
								You&apos;re close to your limit for this period.
								Add extra words to keep going.
							</Text>
						) }
					</BlockStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Change plan
				</Text>
				{ changeError && (
					<Banner tone="critical">
						{ errorMessage( changeError ) }
					</Banner>
				) }
				{ changePending && (
					<Banner tone="success">
						Changing to{ ' ' }
						{ formatPlanKey( entitlements.pending_plan_change ) } on
						your next billing date,{ ' ' }
						{ formatDate( entitlements.period_end ) }.
					</Banner>
				) }
				{ downgradeWarning && (
					<Banner
						tone="warning"
						onDismiss={ () => setDowngradeWarning( null ) }
					>
						{ planLabel( downgradeWarning ) } allows fewer connected
						sites than your current plan. Disconnect the extras
						first.
					</Banner>
				) }
				<InlineGrid columns={ 3 } gap="300">
					{ intervalPlans.map( ( target ) =>
						target.plan_key === plan.plan_key ? (
							<PlanCard
								key={ target.plan_key }
								plan={ target }
								badge={ { text: 'Your plan', tone: 'success' } }
								actionLabel="Your plan"
								disabled
							/>
						) : (
							<PlanCard
								key={ target.plan_key }
								plan={ target }
								actionLabel={ `Switch to ${ planLabel(
									target
								) }` }
								loading={ changeLoading === target.plan_key }
								disabled={
									changePending ||
									( changeLoading &&
										changeLoading !== target.plan_key )
								}
								onAction={ () => trySwitch( target ) }
							/>
						)
					) }
				</InlineGrid>
				<Text as="span" tone="subdued" variant="bodySm">
					Plan changes take effect on your next billing date. Extra
					word packs are charged once, straight away.
				</Text>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				{ transactions.length === 0 ? (
					<EmptyReceipts />
				) : (
					<ReceiptsCard
						transactions={ transactions }
						hasMore={ hasMoreTransactions }
						loadingMore={ transactionsLoadingMore }
						loadMoreError={ transactionsLoadMoreError }
						onLoadMore={ onLoadMoreTransactions }
					/>
				) }
			</BlockStack>
		</BlockStack>
	)
}

export default function BillingSubscription() {
	const [ plans, setPlans ] = useState( null )
	const [ plansError, setPlansError ] = useState( '' )
	const [ packs, setPacks ] = useState( null )
	const [ packsError, setPacksError ] = useState( '' )
	const [ entitlements, setEntitlements ] = useState( null )
	const [ entitlementsError, setEntitlementsError ] = useState( '' )
	const [ transactions, setTransactions ] = useState( null )
	const [ transactionsError, setTransactionsError ] = useState( '' )
	const [ transactionsPage, setTransactionsPage ] = useState( 1 )
	const [ hasMoreTransactions, setHasMoreTransactions ] = useState( false )
	const [ transactionsLoadingMore, setTransactionsLoadingMore ] =
		useState( false )
	const [ transactionsLoadMoreError, setTransactionsLoadMoreError ] =
		useState( '' )

	const [ buyWordsOpen, setBuyWordsOpen ] = useState( false )
	const [ buyLoading, setBuyLoading ] = useState( false )
	const [ buyError, setBuyError ] = useState( '' )

	const [ actionLoading, setActionLoading ] = useState( null ) // plan_key in flight, or null
	const [ actionError, setActionError ] = useState( '' )

	const [ changeLoading, setChangeLoading ] = useState( null ) // plan_key in flight, or null
	const [ changeError, setChangeError ] = useState( '' )

	// change_plan polls until entitlements.pending_plan_change matches
	// targetPlan — that field (not local state) is the single source of truth
	// for "a change is confirmed/scheduled", so ActiveState reads it directly.
	const [ polling, setPolling ] = useState( null ) // { kind: 'subscribe' | 'purchase' | 'change_plan', attempts, gaveUp, before?, targetPlan? } | null
	const [ noticeBanner, setNoticeBanner ] = useState( '' )

	const [ status, setStatus ] = useQueryState(
		'status',
		parseAsStringLiteral( [ 'success', 'cancel' ] )
	)
	const [ planKeyParam, setPlanKeyParam ] = useQueryState(
		'plan_key',
		parseAsString
	)
	const [ packIdParam, setPackIdParam ] = useQueryState(
		'pack_id',
		parseAsString
	)
	const [ tokenParam, setTokenParam ] = useQueryState(
		'token',
		parseAsString
	)

	// Each fetcher clears its own error before retrying, so a Retry click that
	// succeeds doesn't leave the previous failure's banner text stranded.
	const fetchPlans = useCallback( () => {
		setPlansError( '' )
		return api.get( '/billing/plans' ).then( ( res ) => {
			if ( res.ok ) {
				setPlans( res.plans )
			} else {
				setPlansError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	const fetchPacks = useCallback( () => {
		setPacksError( '' )
		return api.get( '/billing/packs' ).then( ( res ) => {
			if ( res.ok ) {
				setPacks( res.packs )
			} else {
				setPacksError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	const fetchTransactions = useCallback( ( page = 1 ) => {
		const loadingMore = page > 1
		if ( loadingMore ) {
			setTransactionsLoadingMore( true )
			setTransactionsLoadMoreError( '' )
		} else {
			setTransactionsError( '' )
		}

		return api
			.get( `/billing/transactions?page=${ page }&page_size=20` )
			.then( ( res ) => {
			if ( res.ok ) {
				setTransactions( ( current ) =>
					loadingMore
						? [ ...( current || [] ), ...res.transactions ]
						: res.transactions
				)
				setTransactionsPage( res.page )
				setHasMoreTransactions( res.has_more )
			} else {
				const error = res.error || 'ERR_UNREACHABLE'
				if ( loadingMore ) {
					setTransactionsLoadMoreError( error )
				} else {
					setTransactionsError( error )
				}
			}
			setTransactionsLoadingMore( false )
		} )
	}, [] )

	useEffect( () => {
		fetchPlans()
	}, [ fetchPlans ] )

	useEffect( () => {
		fetchPacks()
	}, [ fetchPacks ] )

	useEffect( () => {
		fetchTransactions()
	}, [ fetchTransactions ] )

	// The one place entitlements gets fetched — the initial load, a Retry
	// click, and the poll-until-settled loop below all call this, the latter
	// with fresh: true to bypass the 60s server-side cache.
	const refetchEntitlements = useCallback( ( { fresh = false } = {} ) => {
		setEntitlementsError( '' )
		return api
			.get( `/billing/entitlements${ fresh ? '?fresh=1' : '' }` )
			.then( ( res ) => {
				if ( res.ok ) {
					setEntitlements( res.entitlements )
				} else {
					setEntitlementsError( res.error || 'ERR_UNREACHABLE' )
				}
				return res
			} )
	}, [] )

	useEffect( () => {
		refetchEntitlements()
	}, [ refetchEntitlements ] )

	// Retries only whichever initial fetch(es) actually failed — the ones
	// that succeeded already have their data and don't need refetching.
	function retryInitialLoad() {
		if ( plans === null ) {
			fetchPlans()
		}
		if ( packs === null ) {
			fetchPacks()
		}
		if ( entitlements === null ) {
			refetchEntitlements()
		}
		if ( transactions === null ) {
			fetchTransactions()
		}
	}

	// Redirect-back handling — PayPal always returns with ?status=..., same
	// one-shot deep-link pattern as App.jsx's confirm_token handling. `status`
	// is a routing signal only, never proof of anything — it only decides
	// which follow-up call to make, never sets entitlements/active directly.
	useEffect( () => {
		if ( ! status ) {
			return
		}

		const clearParams = () => {
			setStatus( null )
			setPlanKeyParam( null )
			setPackIdParam( null )
			setTokenParam( null )
		}

		if ( status === 'cancel' ) {
			sessionStorage.removeItem( PENDING_ACTION_KEY )
			sessionStorage.removeItem( PENDING_WORDS_KEY )
			setNoticeBanner( 'Checkout cancelled.' )
			clearParams()
			return
		}

		if ( packIdParam ) {
			// Pack purchase return. order_id can't be one of our own params —
			// our redirect URL is built before PayPal generates an order id —
			// so it has to come from whatever param PayPal itself appends.
			// Working assumption: `token` (PayPal's long-standing
			// convention) — UNVERIFIED against a live sandbox redirect, must
			// be confirmed before relying on this in production.
			const orderId = tokenParam
			const before = Number(
				sessionStorage.getItem( PENDING_WORDS_KEY ) || 0
			)
			sessionStorage.removeItem( PENDING_WORDS_KEY )
			clearParams()

			const afterCapture = orderId
				? api.post( `/billing/packs/order/${ orderId }/capture` )
				: Promise.resolve()

			afterCapture.then( () => {
				setPolling( {
					kind: 'purchase',
					attempts: 0,
					gaveUp: false,
					before,
				} )
			} )
			return
		}

		// subscribe and change-plan share the same redirect shape
		// (status + plan_key, no action-type flag) — a sessionStorage flag
		// set right before the change-plan redirect is what tells them apart.
		const pendingAction = sessionStorage.getItem( PENDING_ACTION_KEY )
		const returnedPlanKey = planKeyParam
		clearParams()

		if ( pendingAction === 'change_plan' ) {
			sessionStorage.removeItem( PENDING_ACTION_KEY )
			// change-plan writes nothing locally on approval — the new plan
			// only becomes real entitlement at the next renewal payment. But
			// entitlements.pending_plan_change (the "scheduled" confirmation)
			// is itself backed by a webhook with the same few-second lag as
			// every other billing action here, so poll for it rather than
			// assuming success from the redirect alone.
			setPolling( {
				kind: 'change_plan',
				attempts: 0,
				gaveUp: false,
				targetPlan: returnedPlanKey,
			} )
			return
		}

		setPolling( { kind: 'subscribe', attempts: 0, gaveUp: false } )
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status ] )

	// Poll-until-settled — subscribe/purchase/change_plan.
	useEffect( () => {
		if ( ! polling || polling.gaveUp ) {
			return
		}

		const timer = setTimeout( async () => {
			const res = await refetchEntitlements( { fresh: true } )
			if ( ! res.ok || ! res.entitlements ) {
				return
			}

			if ( isPollSettled( polling, res.entitlements ) ) {
				setPolling( null )
				return
			}

			setPolling( ( p ) =>
				p && p.attempts + 1 >= 10
					? { ...p, attempts: p.attempts + 1, gaveUp: true }
					: { ...p, attempts: p.attempts + 1 }
			)
		}, 3000 )

		return () => clearTimeout( timer )
	}, [ polling, refetchEntitlements ] )

	async function doSubscribe( planKey ) {
		setActionLoading( planKey )
		setActionError( '' )
		const res = await api.post( '/billing/subscription', {
			plan_key: planKey,
		} )
		if ( res.ok && res.approve_url ) {
			window.location.assign( res.approve_url )
			return
		}
		setActionLoading( null )
		setActionError( res.error || 'ERR_UNREACHABLE' )
	}

	async function doChangePlan( planKey ) {
		setChangeLoading( planKey )
		setChangeError( '' )
		const res = await api.post( '/billing/subscription/plan', {
			plan_key: planKey,
		} )
		if ( ! res.ok ) {
			setChangeLoading( null )
			setChangeError( res.error || 'ERR_UNREACHABLE' )
			return
		}
		setChangeLoading( null )
		if ( res.approve_url ) {
			sessionStorage.setItem( PENDING_ACTION_KEY, 'change_plan' )
			window.location.assign( res.approve_url )
			return
		}
		// PayPal skipped re-consent for this revision (approve_url: null) —
		// applied on PayPal's side already, but entitlements.pending_plan_change
		// is still webhook-backed, so poll for it rather than assuming success
		// from this response alone.
		setPolling( {
			kind: 'change_plan',
			attempts: 0,
			gaveUp: false,
			targetPlan: planKey,
		} )
	}

	async function doBuyWords( packId ) {
		setBuyLoading( true )
		setBuyError( '' )
		sessionStorage.setItem(
			PENDING_WORDS_KEY,
			String( entitlements?.purchased_words ?? 0 )
		)
		const res = await api.post( '/billing/packs/order', {
			pack_id: packId,
		} )
		if ( res.ok && res.approve_url ) {
			window.location.assign( res.approve_url )
			return
		}
		sessionStorage.removeItem( PENDING_WORDS_KEY )
		setBuyLoading( false )
		setBuyError( res.error || 'ERR_UNREACHABLE' )
	}

	if (
		entitlements === null ||
		plans === null ||
		packs === null ||
		transactions === null
	) {
		// A still-null value paired with its error means that fetch failed —
		// distinct from simply not having resolved yet, which must keep
		// showing the spinner, not a premature error.
		const initialLoadError =
			( plans === null && plansError ) ||
			( packs === null && packsError ) ||
			( entitlements === null && entitlementsError ) ||
			( transactions === null && transactionsError )

		if ( initialLoadError ) {
			return (
				<Card>
					<BlockStack gap="200">
						<Text as="p" tone="critical">
							{ errorMessage( initialLoadError ) }
						</Text>
						<InlineStack>
							<Button onClick={ retryInitialLoad }>Retry</Button>
						</InlineStack>
					</BlockStack>
				</Card>
			)
		}

		return (
			<Box padding="500">
				<Spinner accessibilityLabel="Loading billing" size="small" />
			</Box>
		)
	}

	return (
		<BlockStack gap="400">
			{ ( plansError ||
				packsError ||
				entitlementsError ||
				transactionsError ) && (
				<Banner tone="critical">
					{ errorMessage(
						plansError ||
							packsError ||
							entitlementsError ||
							transactionsError
					) }
				</Banner>
			) }
			{ noticeBanner && (
				<Banner tone="info" onDismiss={ () => setNoticeBanner( '' ) }>
					{ noticeBanner }
				</Banner>
			) }
			{ polling && ! polling.gaveUp && (
				<Banner tone="info">
					{ polling.kind === 'change_plan'
						? 'Confirming your plan change…'
						: 'Payment received — updating your account…' }
				</Banner>
			) }
			{ polling?.gaveUp && (
				<Banner tone="warning">
					Still processing — this can take a minute. Refresh shortly
					to see the update.
				</Banner>
			) }

			<SubscriptionStateBody
				entitlements={ entitlements }
				plans={ plans }
				transactions={ transactions }
				hasMoreTransactions={ hasMoreTransactions }
				transactionsLoadingMore={ transactionsLoadingMore }
				transactionsLoadMoreError={ transactionsLoadMoreError }
				onLoadMoreTransactions={ () =>
					fetchTransactions( transactionsPage + 1 )
				}
				onBuyWords={ () => setBuyWordsOpen( true ) }
				onChangePlan={ doChangePlan }
				changeLoading={ changeLoading }
				changeError={ changeError }
				actionLoading={ actionLoading }
				actionError={ actionError }
				onChoose={ doSubscribe }
			/>

			<BuyWordsModal
				open={ buyWordsOpen }
				onClose={ () => setBuyWordsOpen( false ) }
				packs={ packs }
				purchasedWords={ entitlements.purchased_words }
				onBuy={ doBuyWords }
				loading={ buyLoading }
				error={ buyError }
			/>
		</BlockStack>
	)
}
