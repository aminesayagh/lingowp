import {
	Badge,
	Banner,
	BlockStack,
	Box,
	Button,
	Card,
	Divider,
	InlineStack,
	Modal,
	Text,
	TextField,
} from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { api } from '../api'

// Preview only — POST /owner/websites (add/delete/transfer) exists on the
// backend but its own integration tests are deliberately kept red pending an
// unresolved design question (the free-tier site limit and the connect
// ladder's admin_email handling both block basically every add-a-2nd-site
// scenario right now — see design-authentication-multisite-and-site-limits.md).
// This screen demonstrates the intended UX against local state only; nothing
// here calls the backend. Swap the two TODOs below for real POST/DELETE
// /owner/websites calls once that design lands.
const MOCK_SITES = [
	{
		domain: 'translangs.com',
		adminEmail: 'admin@translangs.com',
		status: 'active',
		thisSite: true,
	},
	{
		domain: 'shop.translangs.com',
		adminEmail: 'admin@translangs.com',
		status: 'active',
		thisSite: false,
	},
]

function statusBadge( status ) {
	if ( status === 'active' ) {
		return <Badge tone="success">Active</Badge>
	}
	return <Badge tone="attention">Waiting for the link</Badge>
}

function AddSiteModal( { open, onClose, slotsLeft, onAdd } ) {
	const [ domain, setDomain ] = useState( '' )

	function handleAdd() {
		if ( ! domain ) {
			return
		}
		onAdd( domain )
		setDomain( '' )
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title="Add a site"
			primaryAction={ {
				content: 'Send link',
				disabled: ! domain,
				onAction: handleAdd,
			} }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<BlockStack gap="400">
					<InlineStack align="space-between">
						<Text as="span" tone="subdued" variant="bodySm">
							The plugin has to be installed and active there
							already.
						</Text>
						<Text as="span" tone="subdued" variant="bodySm">
							{ `${ slotsLeft } slot${
								slotsLeft === 1 ? '' : 's'
							} left` }
						</Text>
					</InlineStack>
					<TextField
						label="Domain"
						autoComplete="off"
						placeholder="shop.example.com"
						value={ domain }
						onChange={ setDomain }
					/>
					<BlockStack gap="150">
						<Text as="span" fontWeight="semibold" variant="bodySm">
							What happens next
						</Text>
						<Text as="span" tone="subdued" variant="bodySm">
							1. We email a link to your owner address. 2. The
							link opens that site — you need to be signed in
							there as an administrator, which proves it&apos;s
							yours. 3. The plugin there picks up its licence on
							the spot. Nothing is created until the link is
							opened; the slot is held meanwhile.
						</Text>
					</BlockStack>
				</BlockStack>
			</Modal.Section>
		</Modal>
	)
}

function RemoveSiteModal( { site, onClose, onRemove } ) {
	const [ confirmText, setConfirmText ] = useState( '' )

	return (
		<Modal
			open={ !! site }
			onClose={ onClose }
			title={ site ? `Remove ${ site.domain }?` : '' }
			primaryAction={ {
				content: 'Remove',
				destructive: true,
				disabled: confirmText !== site?.domain,
				onAction: () => {
					onRemove( site.domain )
					setConfirmText( '' )
				},
			} }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<BlockStack gap="300">
					<Text as="p" tone="subdued">
						It stops translating right away and the slot frees up.
					</Text>
					<TextField
						label="Confirm"
						labelHidden
						autoComplete="off"
						placeholder={ `Type ${ site?.domain } to confirm` }
						value={ confirmText }
						onChange={ setConfirmText }
					/>
				</BlockStack>
			</Modal.Section>
		</Modal>
	)
}

export default function BillingSites() {
	const [ sites, setSites ] = useState( MOCK_SITES )
	const [ sitesLimit, setSitesLimit ] = useState( null )
	const [ addOpen, setAddOpen ] = useState( false )
	const [ removeTarget, setRemoveTarget ] = useState( null )

	// The one real thing on this screen — everything else below is mocked
	// (see file-level comment) — so the slot count at least matches the
	// account's real plan instead of being invented too.
	useEffect( () => {
		api.get( '/billing/entitlements' ).then( ( res ) => {
			if ( res.ok ) {
				setSitesLimit( res.entitlements?.sites_limit ?? null )
			}
		} )
	}, [] )

	const limit = sitesLimit ?? sites.length
	const used = sites.length
	const slotsLeft = Math.max( 0, limit - used )

	// TODO: replace with POST /owner/websites once the backend's multisite
	// design gap is resolved (see file-level comment above).
	function addSite( domain ) {
		setSites( ( s ) => [
			...s,
			{
				domain,
				adminEmail: null,
				status: 'pending',
				thisSite: false,
			},
		] )
		setAddOpen( false )
	}

	// TODO: replace with DELETE /owner/websites/{id}.
	function removeSite( domain ) {
		setSites( ( s ) => s.filter( ( site ) => site.domain !== domain ) )
		setRemoveTarget( null )
	}

	return (
		<BlockStack gap="400">
			<Banner tone="warning">
				Preview — this tab isn&apos;t connected to your account yet.
				Adding or removing a site here only changes what you see on this
				screen.
			</Banner>

			<InlineStack
				align="space-between"
				blockAlign="start"
				wrap={ false }
			>
				<BlockStack gap="050">
					<Text as="h2" variant="headingMd">
						Sites
					</Text>
					<Text as="span" tone="subdued" variant="bodySm">
						{ `${ used } of ${ limit } slots used. Every site draws from the same monthly words and reserve.` }
					</Text>
				</BlockStack>
				<Button
					variant="primary"
					disabled={ slotsLeft === 0 }
					onClick={ () => setAddOpen( true ) }
				>
					Add site
				</Button>
			</InlineStack>

			<Card padding="0">
				{ sites.map( ( site, index ) => (
					<BlockStack key={ site.domain } gap="0">
						{ index > 0 && <Divider /> }
						<Box padding="400">
							<InlineStack
								align="space-between"
								blockAlign="center"
								wrap={ false }
							>
								<InlineStack gap="200" blockAlign="center">
									<Text as="span" fontWeight="semibold">
										{ site.domain }
									</Text>
									{ site.thisSite && (
										<Badge>This site</Badge>
									) }
								</InlineStack>
								<Text as="span" tone="subdued" variant="bodySm">
									{ site.adminEmail || '—' }
								</Text>
								{ statusBadge( site.status ) }
								{ ! site.thisSite && (
									<Button
										variant="plain"
										tone="critical"
										onClick={ () =>
											setRemoveTarget( site )
										}
									>
										Remove
									</Button>
								) }
							</InlineStack>
						</Box>
					</BlockStack>
				) ) }
			</Card>

			<AddSiteModal
				open={ addOpen }
				onClose={ () => setAddOpen( false ) }
				slotsLeft={ slotsLeft }
				onAdd={ addSite }
			/>
			<RemoveSiteModal
				site={ removeTarget }
				onClose={ () => setRemoveTarget( null ) }
				onRemove={ removeSite }
			/>
		</BlockStack>
	)
}
