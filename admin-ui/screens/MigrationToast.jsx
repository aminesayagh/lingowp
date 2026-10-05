import { Toast } from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { useAddons } from '../queries/addons'
import { useConnectionStatus } from '../queries/connection'

const cfg = window.LingoWP || {}

// Dashboard only, once onboarding is done. Shown on every visit until the
// migration is done; closing it hides it for this visit.
//
// 1. A migration add-on is active and found something to migrate from
//    (window.LingoWP.migration, not yet migrated) → "Migrate", opening the
//    Account page's Migration tab.
// 2. No migration add-on, but the catalog has one for a plugin installed here
//    (a `migration` add-on that is `relevant` and not `installed`) → "Get the
//    add-on", opening the Integrations tab. Needs the cloud connection, like
//    the Integrations tab itself.
export default function MigrationToast( { ready } ) {
	const [ closed, setClosed ] = useState( false )
	const statusQ = useConnectionStatus()
	const addonsQ = useAddons( {
		enabled: ready && ! cfg.migration && !! statusQ.data?.connected,
	} )

	if ( ! ready || closed ) {
		return null
	}

	let content = null
	let action = null

	if ( cfg.migration ) {
		if ( cfg.migration.migrated ) {
			return null
		}
		content = `${ cfg.migration.label } translations found on this site. Import them into LingoWP.`
		action = { content: 'Migrate', tab: 'migration' }
	} else {
		const addon = ( addonsQ.data?.addons ?? [] ).find(
			( a ) =>
				( a.categories ?? [] ).includes( 'migration' ) &&
				a.relevant &&
				! a.installed
		)
		if ( ! addon ) {
			return null
		}
		content = `${ addon.title } is available. Get the add-on to bring your translations into LingoWP.`
		action = { content: 'Get the add-on', tab: 'integrations' }
	}

	return (
		<Toast
			content={ content }
			duration={ 10000 }
			action={ {
				content: action.content,
				onAction: () =>
					window.location.assign(
						`${ cfg.account_url }&tab=${ action.tab }`
					),
			} }
			onDismiss={ () => setClosed( true ) }
		/>
	)
}
