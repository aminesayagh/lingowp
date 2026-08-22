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
import { useCallback, useEffect, useState } from '@wordpress/element'
import { parseAsString, parseAsStringLiteral, useQueryState } from 'nuqs'
import { api } from '../api'
import { errorMessage } from '../errors'

const RECEIPT_STATUS = {
	COMPLETED: { label: 'Paid', tone: 'success' },
	PENDING: { label: 'Pending', tone: 'warning' },
	DENIED: { label: 'Denied', tone: 'critical' },
	FAILED: { label: 'Failed', tone: 'critical' },
}

// Both survive the full-page round trip to PayPal and back (a real
// navigation, not a fetch — React state doesn't survive that, sessionStorage
// does). Self-cleaning: each is read once on return and removed immediately.
const PENDING_ACTION_KEY = 'translangs_pending_billing_action'
const PENDING_WORDS_KEY = 'translangs_pending_words_before'

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
					{ money( plan.price, plan.currency ) } a month
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

function ReceiptsCard( { transactions } ) {
	return (
		<Card padding="0">
			<DataTable
				columnContentTypes={ [ 'text', 'text', 'text', 'text' ] }
				headings={ [ 'Date', 'What for', 'Amount', 'Status' ] }
				rows={ transactions.map( ( t ) => {
					const s = RECEIPT_STATUS[ t.status ]
					return [
						formatDate( t.time ),
						t.plan ?? '—',
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

function NotSubscribedState( {
	plans,
	transactions,
	actionLoading,
	actionError,
	onChoose,
} ) {
	const hasHistory = transactions.length > 0
	const monthlyPlans = plans.filter( ( p ) => p.interval === 'monthly' )

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
				<Text as="h3" variant="headingSm">
					{ hasHistory ? 'Start again' : 'Add AI translation' }
				</Text>
				{ ! hasHistory && (
					<Text as="span" tone="subdued">
						We translate new content for you automatically, as you
						publish it.
					</Text>
				) }
				{ actionError && (
					<Banner tone="critical">
						{ errorMessage( actionError ) }
					</Banner>
				) }
				<InlineGrid columns={ 3 } gap="300">
					{ monthlyPlans.map( ( plan ) => (
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
				<Text as="span" tone="subdued" variant="bodySm">
					Pay yearly and get two months free.
				</Text>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				{ transactions.length === 0 ? (
					<EmptyReceipts />
				) : (
					<ReceiptsCard transactions={ transactions } />
				) }
			</BlockStack>
		</BlockStack>
	)
}

function ActiveState( {
	entitlements,
	plans,
	transactions,
	onBuyWords,
	onChangePlan,
	changeLoading,
	changeError,
	changeConfirmed,
	confirmedPlanName,
} ) {
	const [ downgradeWarning, setDowngradeWarning ] = useState( null )

	const plan = plans.find( ( p ) => p.plan_key === entitlements.plan )

	if ( ! plan ) {
		return null
	}

	const monthlyPlans = plans.filter( ( p ) => p.interval === 'monthly' )

	const allowance = entitlements.monthly_allowance ?? 0
	const remaining = entitlements.monthly_remaining ?? 0
	const consumed = Math.max( 0, allowance - remaining )
	const pct = allowance > 0 ? Math.round( ( consumed / allowance ) * 100 ) : 0
	const nearLimit = allowance > 0 && consumed / allowance >= 0.85
	const showSites = ( entitlements.sites_limit ?? 1 ) > 1

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
							{ money( plan.price, plan.currency ) } a month ·
							renews { formatDate( entitlements.period_end ) }
						</Text>
						{ allowance > 0 && (
							<BlockStack gap="150">
								<Text as="span" tone="subdued" variant="bodySm">
									Words this period ·{ ' ' }
									{ formatDate( entitlements.period_start ) }{ ' ' }
									to { formatDate( entitlements.period_end ) }
								</Text>
								<ProgressBar
									progress={ pct }
									tone={ nearLimit ? 'critical' : 'primary' }
								/>
								<Text as="span" tone="subdued" variant="bodySm">
									{ consumed.toLocaleString() } of{ ' ' }
									{ allowance.toLocaleString() } used
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
						) }
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
								Up to { entitlements.sites_limit }
							</Text>
						</BlockStack>
					</Card>
				) }
				<Card>
					<BlockStack gap="150">
						<Text as="span" tone="subdued" variant="bodySm">
							Extra words in reserve
						</Text>
						<Text as="span" variant="headingLg">
							{ entitlements.purchased_words.toLocaleString() }
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
				{ changeError && (
					<Banner tone="critical">
						{ errorMessage( changeError ) }
					</Banner>
				) }
				{ changeConfirmed && (
					<Banner tone="success">
						Plan change to { confirmedPlanName } confirmed — it
						takes effect on your next billing date,{ ' ' }
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
					{ monthlyPlans.map( ( target ) =>
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
									changeLoading &&
									changeLoading !== target.plan_key
								}
								onAction={ () => trySwitch( target ) }
							/>
						)
					) }
				</InlineGrid>
				<Text as="span" tone="subdued" variant="bodySm">
					Changes take effect on your next billing date, not
					immediately.
				</Text>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Receipts
				</Text>
				{ transactions.length === 0 ? (
					<EmptyReceipts />
				) : (
					<ReceiptsCard transactions={ transactions } />
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

	const [ buyWordsOpen, setBuyWordsOpen ] = useState( false )
	const [ buyLoading, setBuyLoading ] = useState( false )
	const [ buyError, setBuyError ] = useState( '' )

	const [ actionLoading, setActionLoading ] = useState( null ) // plan_key in flight, or null
	const [ actionError, setActionError ] = useState( '' )

	const [ changeLoading, setChangeLoading ] = useState( null ) // plan_key in flight, or null
	const [ changeError, setChangeError ] = useState( '' )
	const [ changeConfirmed, setChangeConfirmed ] = useState( null ) // confirmed plan_key, or null

	// Never used for subscribe/pack-order — see the note above doChangePlan.
	const [ polling, setPolling ] = useState( null ) // { kind: 'subscribe' | 'purchase', attempts, gaveUp, before? } | null
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

	useEffect( () => {
		let cancelled = false
		api.get( '/billing/plans' ).then( ( res ) => {
			if ( cancelled ) {
				return
			}
			if ( res.ok ) {
				setPlans( res.plans )
			} else {
				setPlansError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
		return () => {
			cancelled = true
		}
	}, [] )

	useEffect( () => {
		let cancelled = false
		api.get( '/billing/packs' ).then( ( res ) => {
			if ( cancelled ) {
				return
			}
			if ( res.ok ) {
				setPacks( res.packs )
			} else {
				setPacksError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
		return () => {
			cancelled = true
		}
	}, [] )

	useEffect( () => {
		let cancelled = false
		api.get( '/billing/transactions' ).then( ( res ) => {
			if ( cancelled ) {
				return
			}
			if ( res.ok ) {
				setTransactions( res.transactions )
			} else {
				setTransactionsError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
		return () => {
			cancelled = true
		}
	}, [] )

	// The one place entitlements gets fetched — the initial load, and the
	// poll-until-settled loop below both call this, the latter with
	// fresh: true to bypass the 60s server-side cache.
	const refetchEntitlements = useCallback( ( { fresh = false } = {} ) => {
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

	// Redirect-back handling — PayPal always returns with ?status=..., same
	// one-shot deep-link pattern as App.jsx's license_key handling. `status`
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
			// change-plan writes nothing locally, ever, on approval — the new
			// plan only becomes real entitlement at the next renewal payment,
			// which can be weeks away. No poll loop here; the confirmation is
			// a static message built from data already on hand.
			setChangeConfirmed( returnedPlanKey )
			return
		}

		setPolling( { kind: 'subscribe', attempts: 0, gaveUp: false } )
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status ] )

	// Poll-until-settled — subscribe/purchase only, never change-plan (see
	// the redirect-handling effect above for why).
	useEffect( () => {
		if ( ! polling || polling.gaveUp ) {
			return
		}

		const timer = setTimeout( async () => {
			const res = await refetchEntitlements( { fresh: true } )
			if ( ! res.ok || ! res.entitlements ) {
				return
			}

			const settled =
				polling.kind === 'subscribe'
					? res.entitlements.active === true
					: res.entitlements.purchased_words > polling.before

			if ( settled ) {
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
		setChangeConfirmed( null )
		const res = await api.post( '/billing/subscription/plan', {
			plan_key: planKey,
		} )
		if ( ! res.ok ) {
			setChangeLoading( null )
			setChangeError( res.error || 'ERR_UNREACHABLE' )
			return
		}
		if ( res.approve_url ) {
			sessionStorage.setItem( PENDING_ACTION_KEY, 'change_plan' )
			window.location.assign( res.approve_url )
			return
		}
		// PayPal skipped re-consent for this revision — already applied,
		// nothing to redirect to. Same static-confirmation completion as the
		// redirect-return path above; no poll loop, no new fetch needed.
		setChangeLoading( null )
		setChangeConfirmed( planKey )
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
		return (
			<Text as="span" tone="subdued">
				Loading…
			</Text>
		)
	}

	const confirmedPlan = changeConfirmed
		? plans.find( ( p ) => p.plan_key === changeConfirmed )
		: null

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
					Payment received — updating your account…
				</Banner>
			) }
			{ polling?.gaveUp && (
				<Banner tone="warning">
					Still processing — this can take a minute. Refresh shortly
					to see the update.
				</Banner>
			) }

			{ entitlements.active ? (
				<ActiveState
					entitlements={ entitlements }
					plans={ plans }
					transactions={ transactions }
					onBuyWords={ () => setBuyWordsOpen( true ) }
					onChangePlan={ doChangePlan }
					changeLoading={ changeLoading }
					changeError={ changeError }
					changeConfirmed={ !! changeConfirmed }
					confirmedPlanName={
						confirmedPlan
							? planLabel( confirmedPlan )
							: 'your new plan'
					}
				/>
			) : (
				<NotSubscribedState
					plans={ plans }
					transactions={ transactions }
					actionLoading={ actionLoading }
					actionError={ actionError }
					onChoose={ doSubscribe }
				/>
			) }

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
