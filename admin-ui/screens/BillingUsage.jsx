import {
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
import { useCallback, useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'
import { GhostBars } from './InsightsPreview'

function formatDate( iso ) {
	if ( ! iso ) {
		return '—'
	}
	return new Date( iso ).toLocaleDateString( 'en-US', {
		day: 'numeric',
		month: 'short',
	} )
}

function GhostMetricValue() {
	return (
		<div
			className="tl-ghost-shimmer"
			style={ { height: 28, width: '70%', borderRadius: 6 } }
			aria-hidden="true"
		/>
	)
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

export default function BillingUsage() {
	const [ entitlements, setEntitlements ] = useState( null )
	const [ entitlementsError, setEntitlementsError ] = useState( '' )
	const [ usage, setUsage ] = useState( null )
	const [ usageError, setUsageError ] = useState( '' )
	const [ languages, setLanguages ] = useState( null )
	const [ languagesError, setLanguagesError ] = useState( '' )

	// Each fetcher clears its own error before retrying, so a Retry click that
	// succeeds doesn't leave the previous failure's banner text stranded.
	const fetchEntitlements = useCallback( () => {
		setEntitlementsError( '' )
		return api.get( '/billing/entitlements' ).then( ( res ) => {
			if ( res.ok ) {
				setEntitlements( res.entitlements )
			} else {
				setEntitlementsError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	const fetchUsage = useCallback( () => {
		setUsageError( '' )
		return api.get( '/billing/usage' ).then( ( res ) => {
			if ( res.ok ) {
				setUsage( res )
			} else {
				setUsageError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	const fetchLanguages = useCallback( () => {
		setLanguagesError( '' )
		return api.get( '/languages/targets' ).then( ( res ) => {
			if ( res.ok ) {
				setLanguages( res.languages )
			} else {
				setLanguagesError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	useEffect( () => {
		fetchEntitlements()
	}, [ fetchEntitlements ] )

	useEffect( () => {
		fetchUsage()
	}, [ fetchUsage ] )

	useEffect( () => {
		fetchLanguages()
	}, [ fetchLanguages ] )

	if ( entitlements === null || usage === null || languages === null ) {
		// A still-null value paired with its error means that fetch failed —
		// distinct from simply not having resolved yet, which must keep
		// showing the spinner, not a premature error.
		const initialLoadError =
			( entitlements === null && entitlementsError ) ||
			( usage === null && usageError ) ||
			( languages === null && languagesError )

		if ( initialLoadError ) {
			return (
				<Box padding="500">
					<BlockStack gap="200">
						<Text as="p" tone="critical">
							{ errorMessage( initialLoadError ) }
						</Text>
						<InlineStack>
							<Button
								onClick={ () => {
									if ( entitlements === null ) {
										fetchEntitlements()
									}
									if ( usage === null ) {
										fetchUsage()
									}
									if ( languages === null ) {
										fetchLanguages()
									}
								} }
							>
								Retry
							</Button>
						</InlineStack>
					</BlockStack>
				</Box>
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

	return (
		<BlockStack gap="400">
			{ ( entitlementsError || usageError || languagesError ) && (
				<Banner tone="critical">
					{ errorMessage(
						entitlementsError || usageError || languagesError
					) }
				</Banner>
			) }

			<InlineStack align="space-between" blockAlign="center">
				<Text as="h2" variant="headingMd">
					This month · { formatDate( entitlements.period_start ) } to{ ' ' }
					{ formatDate( entitlements.period_end ) }
				</Text>
			</InlineStack>

			<InlineGrid columns={ 3 } gap="300">
				<Card>
					<BlockStack gap="100">
						<Text as="span" tone="subdued" variant="bodySm">
							Words translated
						</Text>
						{ usage.words_translated > 0 ? (
							<Text as="span" variant="headingLg">
								{ usage.words_translated.toLocaleString() }
							</Text>
						) : (
							<Text as="span" tone="subdued">
								Nothing translated yet this period
							</Text>
						) }
					</BlockStack>
				</Card>
				<Card>
					<BlockStack gap="100">
						<Text as="span" tone="subdued" variant="bodySm">
							Words reused
						</Text>
						<GhostMetricValue />
					</BlockStack>
				</Card>
				<Card>
					<BlockStack gap="100">
						<Text as="span" tone="subdued" variant="bodySm">
							Translated pages viewed
						</Text>
						<GhostMetricValue />
					</BlockStack>
				</Card>
			</InlineGrid>

			{ allowance > 0 && (
				<BlockStack gap="200">
					<Text as="h3" variant="headingSm">
						Your monthly words
					</Text>
					<Card>
						<BlockStack gap="150">
							<ProgressBar progress={ pct } tone="primary" />
							<Text as="span" tone="subdued" variant="bodySm">
								{ consumed.toLocaleString() } of{ ' ' }
								{ allowance.toLocaleString() } used · resets{ ' ' }
								{ formatDate( entitlements.period_end ) }
							</Text>
						</BlockStack>
					</Card>
				</BlockStack>
			) }

			<Card>
				<InlineStack align="space-between" blockAlign="center">
					<BlockStack gap="050">
						<Text as="span">Extra words</Text>
						<Text as="span" tone="subdued" variant="bodySm">
							{ entitlements.purchased_words.toLocaleString() }{ ' ' }
							left. These don&apos;t expire.
						</Text>
					</BlockStack>
					<Button>Add more words</Button>
				</InlineStack>
			</Card>

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

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					By language
				</Text>
				<Card>
					{ Object.keys( languages ).length > 0 ? (
						<GhostBars
							items={ Object.values( languages ).map(
								( lang ) => ( {
									label: lang.native,
									width: 60, // uniform — no real per-language usage to rank by yet
								} )
							) }
						/>
					) : (
						<Text as="span" tone="subdued">
							No languages configured yet.
						</Text>
					) }
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
