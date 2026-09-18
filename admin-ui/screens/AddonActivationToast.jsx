import { Toast } from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { useAddons } from '../queries/addons'
import { useConnectionStatus } from '../queries/connection'
import { activationToasts, tierLabel } from './addonLists.mjs'

const cfg = window.LingoWP || {}

// "Elementor detected — install the Elementor add-on to translate it."
//
// The PHP side notes every third-party plugin activation / theme switch
// (AddonSiteMatcher); GET /billing/addons hands that note over exactly once
// (`recently_activated`, read-and-cleared server-side), so whichever LingoWP
// screen opens next shows each match one time. Mounted inside every app's
// Polaris <Frame>. Dismissed toasts aren't re-shown — the Integrations tab
// still lists the add-on.
//
// ponytail: this costs one GET /billing/addons per LingoWP screen load. Gate
// it behind a cheap boot-config flag (option read only) if that shows up.
export default function AddonActivationToast() {
	const statusQ = useConnectionStatus()
	const addonsQ = useAddons( { enabled: !! statusQ.data?.connected } )
	const [ queue, setQueue ] = useState( null ) // [{ addon, kind }] | null

	useEffect( () => {
		if ( queue !== null || ! addonsQ.data ) {
			return
		}
		setQueue(
			activationToasts(
				addonsQ.data.addons,
				addonsQ.data.recentlyActivated
			)
		)
	}, [ addonsQ.data, queue ] )

	const current = queue?.[ 0 ]
	if ( ! current ) {
		return null
	}

	const { addon, kind } = current
	const next = () => setQueue( ( q ) => q.slice( 1 ) )
	const tab = kind === 'install' ? 'integrations' : 'subscription'

	return (
		<Toast
			content={
				kind === 'install'
					? `${ addon.title } detected — install the ${ addon.title } add-on to translate it.`
					: `${ addon.title } detected — upgrade to ${ tierLabel(
							addon.tier
					  ) } to translate it.`
			}
			duration={ 8000 }
			action={ {
				content: kind === 'install' ? 'Install' : 'Upgrade',
				onAction: () =>
					window.location.assign(
						`${ cfg.billing_url }&tab=${ tab }`
					),
			} }
			onDismiss={ next }
		/>
	)
}
