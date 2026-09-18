import {
	Badge,
	BlockStack,
	Box,
	Button,
	Card,
	Divider,
	InlineStack,
	Spinner,
	Text,
} from '@shopify/polaris'
import { Fragment, useState } from '@wordpress/element'
import { useAddons, useInstallAddon } from '../queries/addons'
import ConnectionErrorCard from './ConnectionErrorCard'
import { errorMessage } from '../errors'
import { partitionAddons, tierLabel } from './addonLists.mjs'

const cfg = window.LingoWP || {}

// The API only puts a slug on each addon (`addon.categories`); this resolves
// it against the top-level {slug,label} list for display. If the list hasn't
// loaded yet or doesn't carry a match, humanize the slug ("dynamic_content"
// -> "Dynamic Content") rather than showing it raw.
function categoryLabel( categories, slug ) {
	const match = categories.find( ( c ) => c.slug === slug )
	if ( match ) {
		return match.label
	}
	return slug
		.split( '_' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' )
}

// `image` from the API is a bare filename ("lingowp-elementor.png"); cfg
// prepends the API's public icon base (/api/v1/addons/icons/), so icon changes
// ship from the backend, not a plugin release. The <img>'s onError below falls
// back to the LingoWP logo when an icon 404s or the backend is unreachable.
function addonImageUrl( addon ) {
	if ( ! addon.image ) {
		return cfg.logo
	}
	return ( cfg.addons_asset_url || '' ) + addon.image
}

const ADDON_IMAGE_SIZE = Math.round( 80 * 0.7 )

function ActionButton( { label, loading, error, onClick } ) {
	return (
		<BlockStack gap="100">
			<Button
				loading={ loading }
				disabled={ loading }
				onClick={ onClick }
			>
				{ label }
			</Button>
			{ error && (
				<Text as="span" tone="critical" variant="bodySm">
					{ errorMessage( error ) }
				</Text>
			) }
		</BlockStack>
	)
}

// `variant` is which of the three lists the card sits in — decided once by
// partitionAddons(), not re-derived per card.
//   installed -> nothing to do
//   ready     -> Install now (plan covers it, plugin it targets is active)
//   upgrade   -> Upgrade to <tier> (plugin it targets is active, plan too low)
function AddonAction( { addon, variant, actions } ) {
	if ( variant === 'ready' ) {
		return (
			<ActionButton
				label="Install now"
				loading={ actions.installingId === addon.addon_id }
				error={ actions.installErrors[ addon.addon_id ] }
				onClick={ () => actions.onInstall( addon ) }
			/>
		)
	}
	if ( variant === 'upgrade' ) {
		return (
			<ActionButton
				label={ `Upgrade to ${ tierLabel( addon.tier ) }` }
				onClick={ actions.onUpgrade }
			/>
		)
	}
	return null
}

function AddonCard( { addon, variant, categories, actions } ) {
	const badgeLabel = addon.categories?.[ 0 ]
		? categoryLabel( categories, addon.categories[ 0 ] )
		: null

	return (
		<Card>
			<div className="flex flex-col items-start text-start gap-3 h-full">
				<img
					src={ addonImageUrl( addon ) }
					alt=""
					width={ ADDON_IMAGE_SIZE }
					height={ ADDON_IMAGE_SIZE }
					className="rounded object-contain"
					onError={ ( e ) => {
						// Falls back once to the LingoWP logo on a missing
						// PNG; the handler is cleared first so a broken logo
						// URL itself can't loop.
						e.currentTarget.onerror = null
						e.currentTarget.src = cfg.logo
					} }
				/>
				<BlockStack gap="150">
					<InlineStack gap="150" blockAlign="start" align="start">
						<Text as="h3" variant="headingMd">
							{ addon.title }
						</Text>
						{ badgeLabel && (
							<Badge tone="info">{ badgeLabel }</Badge>
						) }
					</InlineStack>
					<Text as="p" tone="subdued">
						{ addon.description }
					</Text>
				</BlockStack>
				<AddonAction
					addon={ addon }
					variant={ variant }
					actions={ actions }
				/>
			</div>
		</Card>
	)
}

function AddonSection( {
	title,
	description,
	addons,
	variant,
	categories,
	actions,
} ) {
	return (
		<BlockStack gap="300">
			<BlockStack gap="100">
				<Text as="h2" variant="headingMd">
					{ title }
				</Text>
				{ description && (
					<Text as="p" tone="subdued">
						{ description }
					</Text>
				) }
			</BlockStack>
			<div className="grid grid-cols-3 2xl:grid-cols-4 gap-2">
				{ addons.map( ( addon ) => (
					<AddonCard
						key={ addon.addon_id }
						addon={ addon }
						variant={ variant }
						categories={ categories }
						actions={ actions }
					/>
				) ) }
			</div>
		</BlockStack>
	)
}

// Billing > Integrations. Three lists from partitionAddons(): what's
// installed here, what the plan covers and this site could use, and what
// this site could use but the plan doesn't cover. `onUpgrade` switches to
// the Plan & billing tab.
export default function BillingIntegrations( { onUpgrade } ) {
	const [ installErrors, setInstallErrors ] = useState( {} ) // addon_id -> ERR_* code

	const addonsQ = useAddons()
	const installMut = useInstallAddon()

	if ( addonsQ.data === undefined ) {
		if ( addonsQ.isError ) {
			return (
				<ConnectionErrorCard
					error={ addonsQ.error?.code || 'ERR_UNREACHABLE' }
					onRetry={ () => addonsQ.refetch() }
				/>
			)
		}
		return (
			<Box padding="500">
				<Spinner
					accessibilityLabel="Loading integrations"
					size="small"
				/>
			</Box>
		)
	}

	const { addons, categories, tier } = addonsQ.data
	const { installed, ready, upgrade } = partitionAddons( addons )

	async function installAddon( addon ) {
		setInstallErrors( ( prev ) => ( { ...prev, [ addon.addon_id ]: '' } ) )
		try {
			await installMut.mutateAsync( {
				addonId: addon.addon_id,
				product: addon.product,
			} )
		} catch ( e ) {
			setInstallErrors( ( prev ) => ( {
				...prev,
				[ addon.addon_id ]: e.code || 'ERR_UNREACHABLE',
			} ) )
		}
	}

	const actions = {
		installingId: installMut.isPending
			? installMut.variables.addonId
			: null,
		installErrors,
		onInstall: installAddon,
		onUpgrade,
	}

	// Empty lists are not rendered at all. Sections are separated by a
	// divider only when there's a rendered section before them.
	const sections = [
		{
			title: 'Installed',
			addons: installed,
			variant: 'installed',
		},
		{
			title: 'Ready to install',
			description: `Included in your ${ tierLabel(
				tier
			) } plan and matching plugins active on this site.`,
			addons: ready,
			variant: 'ready',
		},
		{
			title: 'Needs a higher plan',
			description:
				"Plugins active on this site that your current plan doesn't cover.",
			addons: upgrade,
			variant: 'upgrade',
		},
	].filter( ( s ) => s.addons.length > 0 )

	if ( sections.length === 0 ) {
		return (
			<Text as="p" tone="subdued">
				No add-ons match the plugins active on this site.
			</Text>
		)
	}

	return (
		<BlockStack gap="400">
			{ sections.map( ( section, i ) => (
				<Fragment key={ section.variant }>
					{ i > 0 && <Divider /> }
					<AddonSection
						{ ...section }
						categories={ categories }
						actions={ actions }
					/>
				</Fragment>
			) ) }
		</BlockStack>
	)
}
