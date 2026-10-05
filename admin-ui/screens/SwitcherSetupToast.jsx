import { Toast } from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { FIRST_LANGUAGE_PUBLISHED_EVENT } from '../queries/languages'

const cfg = window.LingoWP || {}

// "Your first language is live — set up the language switcher."
//
// Shown whenever a language write takes the site from no published language
// to one (the server flags it as `first_published`; queries/languages.js turns
// that into a window event). It can show again if every language is
// unpublished and one is published later. Mounted inside the app's <Frame>.
export default function SwitcherSetupToast() {
	const [ open, setOpen ] = useState( false )

	useEffect( () => {
		const show = () => setOpen( true )
		window.addEventListener( FIRST_LANGUAGE_PUBLISHED_EVENT, show )
		return () =>
			window.removeEventListener( FIRST_LANGUAGE_PUBLISHED_EVENT, show )
	}, [] )

	if ( ! open ) {
		return null
	}

	return (
		<Toast
			content="Your first language is live. Set up the language switcher so visitors can find it."
			duration={ 10000 }
			action={ {
				content: 'Set up switcher',
				onAction: () =>
					window.location.assign(
						`${ cfg.account_url }&tab=switcher`
					),
			} }
			onDismiss={ () => setOpen( false ) }
		/>
	)
}
