import {
	Badge,
	Banner,
	BlockStack,
	Box,
	Button,
	Card,
	InlineGrid,
	InlineStack,
	ProgressBar,
	Spinner,
	Text,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { PENDING_WORDS_KEY } from '../billingKeys'
import ConnectionErrorCard from './ConnectionErrorCard'
import { errorMessage } from '../errors'
import {
	useBuyWordPack,
	useEntitlements,
	usePacks,
	useUsage,
} from '../queries/billing'

function formatDate( iso ) {
	if ( ! iso ) {
		return '—'
	}
	return new Date( iso ).toLocaleDateString( 'en-US', {
		day: 'numeric',
		month: 'short',
	} )
}

function money( amount, currency ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency,
	} ).format( parseFloat( amount ) )
}

// Plain CSS bars — a bar chart this simple doesn't need a charting library.
function DailyWordsChart( { daily } ) {
	const max = Math.max( 1, ...daily.map( ( d ) => d.words ) )
	return (
		<div
			style={ {
				display: 'flex',
				alignItems: 'flex-end',
				gap: 6,
				height: 120,
			} }
		>
			{ daily.map( ( d ) => (
				<div
					key={ d.date }
					title={ `${ formatDate(
						d.date
					) } · ${ d.words.toLocaleString() } words` }
					style={ {
						flex: 1,
						height: `${ Math.max( 4, ( d.words / max ) * 100 ) }%`,
						background: 'var(--p-color-bg-fill-info)',
						borderRadius: 2,
					} }
				/>
			) ) }
		</div>
	)
}

function perThousandWords( pack ) {
	return money(
		( parseFloat( pack.price ) / pack.words ) * 1000,
		pack.currency
	)
}

// "Best value" is computed from the real price-per-word ratio, never a
// fabricated "most bought" claim — there's no purchase-analytics data behind
// that number, so it can't be shown honestly.
function bestValuePackId( packs ) {
	return packs.reduce( ( best, p ) =>
		parseFloat( p.price ) / p.words < parseFloat( best.price ) / best.words
			? p
			: best
	).pack_id
}

function PackCard( { pack, featured, loading, disabled, onBuy } ) {
	return (
		<Card>
			<BlockStack gap="150">
				<InlineStack gap="150" blockAlign="center">
					<Text as="span" tone="subdued" variant="bodySm">
						{ pack.words.toLocaleString() } words
					</Text>
					{ featured && <Badge>Best value</Badge> }
				</InlineStack>
				<Text as="span" variant="headingLg">
					{ money( pack.price, pack.currency ) }
				</Text>
				<Text as="span" tone="subdued" variant="bodySm">
					{ perThousandWords( pack ) } per 1,000 words
				</Text>
				<Button
					fullWidth
					variant={ featured ? 'primary' : 'secondary' }
					loading={ loading }
					disabled={ disabled }
					onClick={ () => onBuy( pack.pack_id ) }
				>
					Buy
				</Button>
			</BlockStack>
		</Card>
	)
}

// "At this rate" projects this period's usage-so-far to a full month (guarding
// the first day or two of a period, where that projection would otherwise
// blow up toward infinity) and divides the extra-words reserve by it. Null
// whenever there's nothing to project from, so the caller can skip the line
// instead of showing a meaningless number.
function estimateReserveMonths( entitlements, usage ) {
	const {
		period_start: periodStart,
		period_end: periodEnd,
		purchased_words: purchasedWords,
	} = entitlements
	if ( ! periodStart || ! periodEnd || ! purchasedWords ) {
		return null
	}
	if ( ! usage.words_translated || usage.words_translated <= 0 ) {
		return null
	}
	const start = new Date( periodStart ).getTime()
	const end = new Date( periodEnd ).getTime()
	const elapsed = Math.min(
		1,
		Math.max( 0.03, ( Date.now() - start ) / ( end - start ) )
	)
	const projectedMonthlyBurn = usage.words_translated / elapsed
	if ( ! ( projectedMonthlyBurn > 0 ) ) {
		return null
	}
	return Math.max( 1, Math.round( purchasedWords / projectedMonthlyBurn ) )
}

export default function BillingUsage() {
	// ok:true but no approve_url is the one failure mode a mutation error can't
	// carry (unwrap already passed), so keep a local slot for the buy error.
	const [ buyError, setBuyError ] = useState( '' )

	const entQ = useEntitlements()
	const usageQ = useUsage()
	const packsQ = usePacks()
	const buyWords = useBuyWordPack()

	const entitlements = entQ.data ?? null
	const usage = usageQ.data ?? null
	const packs = packsQ.data ?? null
	const buyingId = buyWords.isPending ? buyWords.variables : null
	const entitlementsError = entQ.error?.code || ''
	const usageError = usageQ.error?.code || ''
	const packsError = packsQ.error?.code || ''

	if ( entitlements === null || usage === null || packs === null ) {
		// A still-null value paired with its error means that fetch failed —
		// distinct from simply not having resolved yet, which must keep
		// showing the spinner, not a premature error.
		const initialLoadError =
			( entitlements === null && entitlementsError ) ||
			( usage === null && usageError ) ||
			( packs === null && packsError )

		if ( initialLoadError ) {
			return (
				<ConnectionErrorCard
					error={ initialLoadError }
					onRetry={ () => {
						if ( entitlements === null ) {
							entQ.refetch()
						}
						if ( usage === null ) {
							usageQ.refetch()
						}
						if ( packs === null ) {
							packsQ.refetch()
						}
					} }
				/>
			)
		}

		return (
			<Box padding="500">
				<Spinner accessibilityLabel="Loading usage" size="small" />
			</Box>
		)
	}

	const allowance = entitlements.monthly_allowance ?? 0
	const remaining = entitlements.monthly_remaining ?? 0
	const consumed = Math.max( 0, allowance - remaining )
	const pct = allowance > 0 ? Math.round( ( consumed / allowance ) * 100 ) : 0
	const nearLimit = allowance > 0 && consumed / allowance >= 0.85
	const reserveMonths = estimateReserveMonths( entitlements, usage )
	const bestValueId = packs.length > 0 ? bestValuePackId( packs ) : null

	// Kicks off the order and redirects — same PENDING_WORDS_KEY handoff
	// BillingSubscription.jsx's "Buy more" flow uses. Note: PayPal's
	// pack-purchase redirect always lands back on tab=subscription (a fixed
	// backend contract, not something this tab controls), so a purchase
	// started here finishes on the Plan & billing tab, not this one.
	async function doBuyWords( packId ) {
		setBuyError( '' )
		sessionStorage.setItem(
			PENDING_WORDS_KEY,
			String( entitlements.purchased_words )
		)
		try {
			const res = await buyWords.mutateAsync( packId )
			if ( res.approve_url ) {
				window.location.assign( res.approve_url )
				return
			}
			throw new Error( 'ERR_UNREACHABLE' )
		} catch ( e ) {
			sessionStorage.removeItem( PENDING_WORDS_KEY )
			setBuyError( e.code || e.message || 'ERR_UNREACHABLE' )
		}
	}

	return (
		<BlockStack gap="400">
			{ ( entitlementsError || usageError || packsError ) && (
				<Banner tone="critical">
					{ errorMessage(
						entitlementsError || usageError || packsError
					) }
				</Banner>
			) }

			<InlineStack align="space-between" blockAlign="center">
				<Text as="h2" variant="headingMd">
					This month · { formatDate( entitlements.period_start ) } to{ ' ' }
					{ formatDate( entitlements.period_end ) }
				</Text>
			</InlineStack>

			<InlineGrid columns={ 2 } gap="300">
				<Card>
					<BlockStack gap="150">
						<InlineStack gap="150" blockAlign="center">
							<Text
								as="span"
								variant="bodySm"
								fontWeight="semibold"
							>
								Monthly words
							</Text>
							<Badge>{ `Resets ${ formatDate(
								entitlements.period_end
							) }` }</Badge>
						</InlineStack>
						<InlineStack gap="100" blockAlign="baseline">
							<Text as="span" variant="heading2xl">
								{ remaining.toLocaleString() }
							</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								left of { allowance.toLocaleString() }
							</Text>
						</InlineStack>
						<ProgressBar
							progress={ pct }
							tone={ nearLimit ? 'critical' : 'primary' }
						/>
					</BlockStack>
				</Card>

				<Card>
					<BlockStack gap="150">
						<InlineStack gap="150" blockAlign="center">
							<Text
								as="span"
								variant="bodySm"
								fontWeight="semibold"
							>
								Extra words
							</Text>
							<Badge tone="success">Never expire</Badge>
						</InlineStack>
						<InlineStack gap="100" blockAlign="baseline">
							<Text as="span" variant="heading2xl">
								{ entitlements.purchased_words.toLocaleString() }
							</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								in reserve
							</Text>
						</InlineStack>
						<Text as="span" tone="subdued" variant="bodySm">
							Only spent once the monthly words run out. They stay
							put across renewals and plan changes.
						</Text>
					</BlockStack>
				</Card>
			</InlineGrid>

			{ entitlements.purchased_words > 0 && (
				<Banner tone="info">
					{ 'Spend order: ' }
					<strong>monthly words first, then extra words.</strong>
					{ reserveMonths !== null &&
						` At this rate you have about ${ reserveMonths } month${
							reserveMonths === 1 ? '' : 's'
						} of reserve after your monthly allowance.` }
				</Banner>
			) }

			<BlockStack gap="200">
				<InlineStack gap="150" blockAlign="baseline">
					<Text as="h3" variant="headingSm">
						Buy extra words
					</Text>
				</InlineStack>
				{ buyError && (
					<Banner tone="critical">
						{ errorMessage( buyError ) }
					</Banner>
				) }
				<InlineGrid columns={ 3 } gap="300">
					{ packs.map( ( pack ) => (
						<PackCard
							key={ pack.pack_id }
							pack={ pack }
							featured={ pack.pack_id === bestValueId }
							loading={ buyingId === pack.pack_id }
							disabled={ buyingId && buyingId !== pack.pack_id }
							onBuy={ doBuyWords }
						/>
					) ) }
				</InlineGrid>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Daily words
				</Text>
				<Card>
					{ usage.daily.length > 0 ? (
						<DailyWordsChart daily={ usage.daily } />
					) : (
						<Text as="span" tone="subdued">
							No words translated yet this period.
						</Text>
					) }
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
