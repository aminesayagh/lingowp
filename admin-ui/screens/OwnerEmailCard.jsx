import {
	Badge,
	Banner,
	BlockStack,
	Button,
	Card,
	InlineStack,
	Text,
	TextField,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { errorMessage } from '../errors'
import { useAssignOwner, useBillingOwner } from '../queries/billing'

const cfg = window.LingoWP || {}

// The licence's owner_email — who receives confirmations/receipts, and the
// only address that can add or remove sites. Never blocks translation itself;
// it only gates the (still-mocked) Sites tab. There is no backend read for
// the current value yet, only the write (POST /billing/owner) and its
// confirm callback — this site's own confirm response is the only moment we
// ever learn it, cached locally (see ConnectionRepository), so a fresh
// browser session always starts from that local cache, not a live backend
// read. Loading is deliberately quiet (no spinner) — "never blocks the
// plugin" extends to not making the whole page wait on this one card.
export default function OwnerEmailCard() {
	const [ mode, setMode ] = useState( 'view' ) // 'view' | 'edit' | 'pending'
	const [ draft, setDraft ] = useState( '' )

	const ownerQ = useBillingOwner()
	const assignOwner = useAssignOwner()

	// Quiet failure — this card is a convenience, not a gate; the rest of
	// Billing still works if this one read fails, so a failed load reads as
	// "no owner email set" ('') rather than staying in the loading state (null).
	const ownerEmail = ownerQ.isError ? '' : ownerQ.data?.owner_email ?? null
	const verified = !! ownerQ.data?.verified
	const sending = assignOwner.isPending
	const sendError = assignOwner.error?.code || ''

	function send( email ) {
		assignOwner.mutate( email, { onSuccess: () => setMode( 'pending' ) } )
	}

	if ( ownerEmail === null ) {
		return null
	}

	if ( verified && mode === 'view' ) {
		return (
			<Card>
				<InlineStack align="space-between" blockAlign="center">
					<InlineStack gap="200" blockAlign="center">
						<Text as="span" tone="subdued" variant="bodySm">
							Owner email
						</Text>
						<Text as="span" fontWeight="semibold" variant="bodySm">
							{ ownerEmail }
						</Text>
						<Badge tone="success">Verified</Badge>
					</InlineStack>
					<Button
						variant="plain"
						onClick={ () => {
							setDraft( '' )
							setMode( 'edit' )
						} }
					>
						Change
					</Button>
				</InlineStack>
			</Card>
		)
	}

	if ( mode === 'pending' ) {
		return (
			<Card>
				<BlockStack gap="200">
					<InlineStack gap="200" blockAlign="center">
						<Text as="span" variant="headingSm">
							Check { draft || cfg.notify_email }
						</Text>
						<Badge>Pending</Badge>
					</InlineStack>
					<Text as="span" tone="subdued" variant="bodySm">
						The link expires soon and works once. Nothing changes
						until it&apos;s clicked.
					</Text>
					<InlineStack gap="200">
						<Button
							onClick={ () => send( draft || cfg.notify_email ) }
							loading={ sending }
						>
							Resend
						</Button>
						<Button
							variant="plain"
							onClick={ () => {
								setDraft( '' )
								setMode( 'edit' )
							} }
						>
							Use another address
						</Button>
					</InlineStack>
				</BlockStack>
			</Card>
		)
	}

	if ( mode === 'edit' ) {
		return (
			<Card>
				<BlockStack gap="200">
					<Text as="span" variant="headingSm">
						Secure your licence
					</Text>
					{ sendError && (
						<Banner tone="critical">
							{ errorMessage( sendError ) }
						</Banner>
					) }
					<InlineStack gap="100" blockAlign="center" wrap={ false }>
						<div style={ { flexGrow: 1 } }>
							<TextField
								label="Owner email"
								labelHidden
								type="email"
								autoComplete="off"
								placeholder="owner@company.com"
								value={ draft }
								onChange={ setDraft }
							/>
						</div>
						<Button
							variant="primary"
							size="large"
							loading={ sending }
							disabled={ ! draft }
							onClick={ () => send( draft ) }
						>
							Send link
						</Button>
					</InlineStack>
					{ cfg.notify_email && (
						<Button
							variant="plain"
							onClick={ () => setMode( 'view' ) }
						>
							{ `Use ${ cfg.notify_email } instead` }
						</Button>
					) }
				</BlockStack>
			</Card>
		)
	}

	// Not set — the one-click path, using WordPress's own admin email.
	return (
		<Card>
			<BlockStack gap="200">
				<InlineStack gap="200" blockAlign="center">
					<Text as="span" variant="headingSm">
						Secure your licence
					</Text>
					<Badge tone="attention">No owner email</Badge>
				</InlineStack>
				<Text as="span" tone="subdued" variant="bodySm">
					The owner email owns this licence. Confirmations and
					receipts go there, and it&apos;s what lets you add or remove
					sites later. Confirm this site&apos;s WordPress admin
					address, or use another one.
				</Text>
				{ sendError && (
					<Banner tone="critical">
						{ errorMessage( sendError ) }
					</Banner>
				) }
				<InlineStack gap="300" blockAlign="center">
					{ cfg.notify_email && (
						<Button
							variant="primary"
							loading={ sending }
							onClick={ () => send( cfg.notify_email ) }
						>
							{ `Confirm ${ cfg.notify_email }` }
						</Button>
					) }
					<Button
						variant="plain"
						onClick={ () => {
							setDraft( '' )
							setMode( 'edit' )
						} }
					>
						Use another address
					</Button>
				</InlineStack>
			</BlockStack>
		</Card>
	)
}
