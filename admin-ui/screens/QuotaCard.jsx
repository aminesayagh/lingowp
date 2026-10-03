import { BlockStack, Button, Card, ProgressBar, Text } from '@shopify/polaris'
import { errorMessage } from '../errors'
import { formatPlanKey } from '../planFormat'

const cfg = window.LingoWP || {}

// Rail quota card (wireframe 2.1 footer / 3.2). Fed by GET /billing/entitlements
// (via Dashboard's existing fetch, shared with its billingBlocked calc) — shows
// a placeholder until that answers. When AI is unavailable (backend off /
// disconnected) it says so — the plugin still works offline, only AI actions
// are disabled.
function unavailableCopy() {
	return 'AI is off — reconnect LingoWP in Settings to enable AI translation.'
}

export default function QuotaCard( {
	quota,
	aiAvailable,
	aiError,
	billingBlocked,
	overSlot,
} ) {
	// Never connected: render nothing at all.
	//
	// Connecting is onboarding's question to ask (Configuration.jsx offers
	// "Connect to LingoWP Cloud" / "Continue without connecting"), and the
	// Dashboard renders *behind* that modal rather than instead of it — see
	// App.jsx. A connect card in the rail therefore puts the same decision on
	// screen twice, in the one place it cannot be answered. After onboarding
	// the same applies: Settings owns the connection, so the rail has nothing
	// to meter and nothing useful to say.
	if ( ! quota && aiError === 'ERR_NOT_CONNECTED' ) {
		return null
	}

	// Connected, but nothing to meter — either no active plan, or this site
	// specifically is past its plan's site-slot allowance (overSlot: still
	// subscribed, just not this site) — either way, skip the progress
	// display and point at the one place that unblocks it, same copy as the
	// row buttons' billingBlocked toast (Dashboard's handleAiBlocked).
	if ( quota && billingBlocked ) {
		return (
			<Card>
				<BlockStack gap="100">
					<Text as="span" variant="bodySm">
						{ overSlot
							? errorMessage( 'ERR_SITE_OVER_PLAN_LIMIT' )
							: errorMessage( 'ERR_HOSTED_AI_UNAVAILABLE' ) }
					</Text>
					<Button variant="secondary" url={ cfg.account_url }>
						Go to Account
					</Button>
				</BlockStack>
			</Card>
		)
	}

	// Connected, entitlements not in yet, or AI unavailable for a reason that
	// is fixed elsewhere (detached, revoked-and-healing, backend down).
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
							: unavailableCopy() }
					</Text>
				</BlockStack>
			</Card>
		)
	}

	const {
		plan,
		monthly_allowance: allowance,
		monthly_remaining: remaining,
	} = quota
	const consumed = Math.max( 0, ( allowance ?? 0 ) - ( remaining ?? 0 ) )
	const pct =
		allowance > 0
			? Math.min( 100, Math.round( ( consumed / allowance ) * 100 ) )
			: 0
	const nearLimit = allowance > 0 && consumed / allowance >= 0.85

	return (
		<Card>
			<BlockStack gap="200">
				<Text as="span" variant="headingSm">
					{ plan ? formatPlanKey( plan ) : 'Plan & usage' }
				</Text>
				<Text as="span" variant="bodySm">
					{ consumed.toLocaleString() } /{ ' ' }
					{ ( allowance ?? 0 ).toLocaleString() } words
				</Text>
				<ProgressBar
					progress={ pct }
					size="small"
					tone={ nearLimit ? 'critical' : 'primary' }
				/>
				{ nearLimit && (
					<Text as="span" tone="critical" variant="bodySm">
						Near limit — upgrade to keep translating.
					</Text>
				) }
				<Button variant="secondary" url={ cfg.account_url }>
					Upgrade plan
				</Button>
			</BlockStack>
		</Card>
	)
}
