import {
	Banner,
	BlockStack,
	Button,
	Card,
	ProgressBar,
	Text,
} from '@shopify/polaris'
import { errorMessage } from '../errors'
import { formatPlanKey } from '../planFormat'

const cfg = window.Translangs || {}

// Rail quota card (wireframe 2.1 footer / 3.2). Fed by GET /billing/entitlements
// (via Dashboard's existing fetch, shared with its billingBlocked calc) — shows
// a placeholder until that answers. When AI is unavailable (backend off /
// disconnected) it says so — the plugin still works offline, only AI actions
// are disabled.
function unavailableCopy() {
	return 'AI is off — reconnect Translangs in Settings to enable AI translation.'
}

function CloudDisclosure() {
	return (
		<BlockStack gap="050">
			<Text as="span" tone="subdued" variant="bodySm">
				Connecting sends this site&apos;s domain and WordPress admin
				email to Translangs Cloud. Translation text is sent only when
				you request AI translation.
			</Text>
			{ ( cfg.cloud_terms_url || cfg.cloud_privacy_url ) && (
				<Text as="span" tone="subdued" variant="bodySm">
					{ cfg.cloud_terms_url && (
						<a
							href={ cfg.cloud_terms_url }
							target="_blank"
							rel="noreferrer"
						>
							Terms
						</a>
					) }
					{ cfg.cloud_terms_url && cfg.cloud_privacy_url && ' · ' }
					{ cfg.cloud_privacy_url && (
						<a
							href={ cfg.cloud_privacy_url }
							target="_blank"
							rel="noreferrer"
						>
							Privacy Policy
						</a>
					) }
				</Text>
			) }
		</BlockStack>
	)
}

export default function QuotaCard( {
	quota,
	aiAvailable,
	aiError,
	billingBlocked,
	overSlot,
	recoveryAvailable,
	onDismissRecoveryNotice,
	onConnect,
} ) {
	// A fresh connect may have also found an older licence and emailed its
	// verified owner a reclaim link — this can be true regardless of which
	// card state below is showing (a brand-new free-tier connect very
	// quickly becomes billingBlocked once entitlements load), so it's
	// rendered above every branch rather than folded into just one of them.
	const recoveryNotice = recoveryAvailable && (
		<Banner tone="info" onDismiss={ onDismissRecoveryNotice }>
			We found an existing Translangs account for this site — check your
			owner email to move its subscription and word balance here.
		</Banner>
	)

	// Connected, but nothing to meter — either no active plan, or this site
	// specifically is past its plan's site-slot allowance (overSlot: still
	// subscribed, just not this site) — either way, skip the progress
	// display and point straight at the two ways to unblock AI translation,
	// same copy as the row buttons' billingBlocked toast (Dashboard's
	// handleAiBlocked).
	if ( quota && billingBlocked ) {
		return (
			<BlockStack gap="200">
				{ recoveryNotice }
				<Card>
					<BlockStack gap="100">
						<Text as="span" variant="bodySm">
							{ overSlot
								? errorMessage( 'ERR_SITE_OVER_PLAN_LIMIT' )
								: errorMessage( 'ERR_HOSTED_AI_UNAVAILABLE' ) }
						</Text>
						<Button variant="secondary" url={ cfg.billing_url }>
							Go to Billing
						</Button>
					</BlockStack>
				</Card>
			</BlockStack>
		)
	}

	if ( ! quota ) {
		// Never connected at all (fresh install / wiped locally) is the one case
		// with something to actually click — every other unavailable reason
		// (detached, revoked-and-healing, backend down) is fixed elsewhere
		// (Billing, or self-heals automatically on the next page load).
		const neverConnected = aiError === 'ERR_NOT_CONNECTED'

		return (
			<BlockStack gap="200">
				{ recoveryNotice }
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
						{ neverConnected && (
							<>
								<CloudDisclosure />
								<Button variant="plain" onClick={ onConnect }>
									Connect to Translangs Cloud
								</Button>
							</>
						) }
					</BlockStack>
				</Card>
			</BlockStack>
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
		<BlockStack gap="200">
			{ recoveryNotice }
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
		</BlockStack>
	)
}
