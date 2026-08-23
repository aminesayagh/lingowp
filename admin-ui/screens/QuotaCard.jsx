import { BlockStack, Button, Card, ProgressBar, Text } from '@shopify/polaris'
import { CONNECT_PENDING_MESSAGE } from '../errors'
import { formatPlanKey } from '../planFormat'

const cfg = window.Translangs || {}
const TOKEN_TAB_URL = cfg.billing_url ? `${ cfg.billing_url }&tab=token` : ''

// Rail quota card (wireframe 2.1 footer / 3.2). Fed by GET /billing/entitlements
// (via Dashboard's existing fetch, shared with its billingBlocked calc) — shows
// a placeholder until that answers. When AI is unavailable (backend off /
// disconnected) it says so — the plugin still works offline, only AI actions
// are disabled. A connect email already on its way (connectPending) gets its
// own copy here instead of the generic "reconnect" line, since there's
// nothing to retry — the fix is in the admin's inbox, not a button in this UI.
function unavailableCopy() {
	return 'AI is off — reconnect Translangs in Settings to enable AI translation.'
}

function emptyStateTone( connectPending, aiAvailable ) {
	if ( connectPending || aiAvailable ) {
		return 'subdued'
	}
	return 'caution'
}

function emptyStateCopy( connectPending, aiAvailable ) {
	if ( connectPending ) {
		return CONNECT_PENDING_MESSAGE
	}
	return aiAvailable ? 'Usage shown after connect' : unavailableCopy()
}

export default function QuotaCard( {
	quota,
	aiAvailable,
	aiError,
	connectPending,
	billingBlocked,
	onConnect,
} ) {
	// Connected, but no active plan and no own provider key — nothing to
	// meter, so skip the progress display entirely and point straight at the
	// two ways to unblock AI translation, same copy as the row buttons'
	// billingBlocked toast (Dashboard's handleAiBlocked).
	if ( quota && billingBlocked ) {
		return (
			<Card>
				<BlockStack gap="100">
					<Text as="span" variant="bodySm">
						Subscribe to a plan or add your own AI provider key to
						use AI translation.
					</Text>
					<BlockStack gap="050">
						<Button variant="secondary" url={ cfg.billing_url }>
							Go to Billing
						</Button>
						<Button variant="tertiary" url={ TOKEN_TAB_URL }>
							Add your own API key
						</Button>
					</BlockStack>
				</BlockStack>
			</Card>
		)
	}

	if ( ! quota ) {
		// Never connected at all (fresh install / wiped locally) is the one case
		// with something to actually click — every other unavailable reason
		// (detached, mismatch, revoked-and-healing, backend down) is either
		// already pending or fixed elsewhere (Billing, the admin's inbox).
		const neverConnected =
			aiError === 'ERR_NOT_CONNECTED' && ! connectPending

		return (
			<Card>
				<BlockStack gap="100">
					<Text as="span" variant="bodySm">
						Free plan
					</Text>
					<Text
						as="span"
						tone={ emptyStateTone( connectPending, aiAvailable ) }
						variant="bodySm"
					>
						{ emptyStateCopy( connectPending, aiAvailable ) }
					</Text>
					{ neverConnected && (
						<Button variant="plain" onClick={ onConnect }>
							Connect Translangs
						</Button>
					) }
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
				<Button variant="secondary" url={ cfg.billing_url }>
					Upgrade plan
				</Button>
			</BlockStack>
		</Card>
	)
}
