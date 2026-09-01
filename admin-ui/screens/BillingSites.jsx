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
	Spinner,
	Text,
	TextField,
} from '@shopify/polaris'
import { useCallback, useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import ConnectionErrorCard from './ConnectionErrorCard'
import { errorMessage } from '../errors'

function AddSiteModal( { open, onClose, slotsLeft, loading, error, onAdd } ) {
	const [ domain, setDomain ] = useState( '' )
	const [ adminEmail, setAdminEmail ] = useState( '' )

	function handleAdd() {
		if ( ! domain || ! adminEmail ) {
			return
		}
		onAdd( domain, adminEmail )
	}

	function handleClose() {
		setDomain( '' )
		setAdminEmail( '' )
		onClose()
	}

	return (
		<Modal
			open={ open }
			onClose={ handleClose }
			title="Add a site"
			primaryAction={ {
				content: 'Send link',
				disabled: ! domain || ! adminEmail,
				loading,
				onAction: handleAdd,
			} }
			secondaryActions={ [
				{ content: 'Cancel', onAction: handleClose },
			] }
		>
			<Modal.Section>
				<BlockStack gap="400">
					{ error && (
						<Banner tone="critical">
							{ errorMessage( error ) }
						</Banner>
					) }
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
					<TextField
						label="Admin email"
						type="email"
						autoComplete="off"
						placeholder="admin@shop.example.com"
						value={ adminEmail }
						onChange={ setAdminEmail }
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

function RemoveSiteModal( { site, onClose, loading, error, onRemove } ) {
	const [ confirmText, setConfirmText ] = useState( '' )

	return (
		<Modal
			open={ !! site }
			onClose={ onClose }
			title={ site ? `Remove ${ site.domain }?` : '' }
			primaryAction={ {
				content: 'Remove',
				destructive: true,
				loading,
				disabled: confirmText !== site?.domain,
				onAction: () => {
					onRemove( site )
					setConfirmText( '' )
				},
			} }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<BlockStack gap="300">
					{ error && (
						<Banner tone="critical">
							{ errorMessage( error ) }
						</Banner>
					) }
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
	const [ websites, setWebsites ] = useState( null )
	const [ websitesError, setWebsitesError ] = useState( '' )
	const [ sitesLimit, setSitesLimit ] = useState( null )
	const [ sitesUsedOnPlan, setSitesUsedOnPlan ] = useState( null )
	const [ ownerVerified, setOwnerVerified ] = useState( false )

	const [ addOpen, setAddOpen ] = useState( false )
	const [ addLoading, setAddLoading ] = useState( false )
	const [ addError, setAddError ] = useState( '' )
	const [ addSuccess, setAddSuccess ] = useState( false )

	const [ removeTarget, setRemoveTarget ] = useState( null )
	const [ removeLoading, setRemoveLoading ] = useState( false )
	const [ removeError, setRemoveError ] = useState( '' )

	const fetchWebsites = useCallback( () => {
		setWebsitesError( '' )
		return api.get( '/billing/websites' ).then( ( res ) => {
			if ( res.ok ) {
				setWebsites( res.websites )
			} else {
				setWebsitesError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	useEffect( () => {
		fetchWebsites()
	}, [ fetchWebsites ] )

	useEffect( () => {
		api.get( '/billing/entitlements' ).then( ( res ) => {
			if ( res.ok ) {
				setSitesLimit( res.entitlements?.sites_limit ?? null )
				setSitesUsedOnPlan( res.entitlements?.sites_used ?? null )
			}
		} )
	}, [] )

	useEffect( () => {
		api.get( '/billing/owner' ).then( ( res ) => {
			setOwnerVerified( !! ( res.ok && res.verified ) )
		} )
	}, [] )

	async function addSite( domain, adminEmail ) {
		setAddLoading( true )
		setAddError( '' )
		const res = await api.post( '/billing/websites', {
			domain,
			admin_email: adminEmail,
		} )
		setAddLoading( false )
		if ( res.ok ) {
			setAddOpen( false )
			setAddSuccess( true )
			return
		}
		setAddError( res.error || 'ERR_UNREACHABLE' )
	}

	async function removeSite( site ) {
		setRemoveLoading( true )
		setRemoveError( '' )
		const res = await api.del( `/billing/websites/${ site.site_id }` )
		setRemoveLoading( false )
		if ( res.ok ) {
			setWebsites( ( list ) =>
				list.filter( ( w ) => w.site_id !== site.site_id )
			)
			setRemoveTarget( null )
			return
		}
		setRemoveError( res.error || 'ERR_UNREACHABLE' )
	}

	if ( websites === null ) {
		if ( websitesError ) {
			return (
				<ConnectionErrorCard
					error={ websitesError }
					onRetry={ fetchWebsites }
				/>
			)
		}
		return (
			<Box padding="500">
				<Spinner accessibilityLabel="Loading sites" size="small" />
			</Box>
		)
	}

	const limit = sitesLimit ?? websites.length
	const used = websites.length
	const slotsLeft = Math.max( 0, limit - used )
	const hostname = window.location.hostname
	// sites_used is the backend's own authoritative count (can exceed the
	// current plan's limit after a downgrade, unlike the client-derived
	// `used` above, which only ever reflects what /billing/websites lists).
	const overPlanLimit =
		sitesLimit !== null &&
		sitesUsedOnPlan !== null &&
		sitesUsedOnPlan > sitesLimit

	return (
		<BlockStack gap="400">
			{ overPlanLimit && (
				<Banner tone="warning">
					{ `This account is using ${ sitesUsedOnPlan } sites, over its plan's limit of ${ sitesLimit }. Sites past the limit lose built-in AI translation until you free a slot or upgrade.` }
				</Banner>
			) }
			{ addSuccess && (
				<Banner
					tone="success"
					onDismiss={ () => setAddSuccess( false ) }
				>
					Check your owner inbox (or wait a moment and try again if
					it&apos;s been a while) for a link to finish adding this
					site.
				</Banner>
			) }

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
					{ ! ownerVerified && (
						<Text as="span" tone="subdued" variant="bodySm">
							Set up an account owner above to add another site.
						</Text>
					) }
				</BlockStack>
				<Button
					variant="primary"
					disabled={ slotsLeft === 0 || ! ownerVerified }
					onClick={ () => setAddOpen( true ) }
				>
					Add site
				</Button>
			</InlineStack>

			<Card padding="0">
				{ websites.map( ( site, index ) => (
					<BlockStack key={ site.site_id } gap="0">
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
									{ site.domain === hostname && (
										<Badge>This site</Badge>
									) }
								</InlineStack>
								<Text as="span" tone="subdued" variant="bodySm">
									{ site.admin_email || '—' }
								</Text>
								{ site.domain !== hostname && (
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
				onClose={ () => {
					setAddOpen( false )
					setAddError( '' )
				} }
				slotsLeft={ slotsLeft }
				loading={ addLoading }
				error={ addError }
				onAdd={ addSite }
			/>
			<RemoveSiteModal
				site={ removeTarget }
				onClose={ () => {
					setRemoveTarget( null )
					setRemoveError( '' )
				} }
				loading={ removeLoading }
				error={ removeError }
				onRemove={ removeSite }
			/>
		</BlockStack>
	)
}
