import { Banner, BlockStack, Modal, Text, TextField } from '@shopify/polaris'
import { useState } from '@wordpress/element'
import { errorMessage } from '../errors'
import { useSendSupportMessage } from '../queries/support'

const cfg = window.LingoWP || {}

// Matches SupportRestController::MAX_MESSAGE_LENGTH — the server rejects
// anything longer with ERR_INVALID_PAYLOAD, so stop it at the field instead.
const MAX_MESSAGE_LENGTH = 2000

// Floating "contact support" bubble, rendered by all four SPA mounts (App,
// AccountApp, InsightsApp) so help is reachable from whichever
// screen the admin got stuck on.
//
// ponytail: the button unmounts while the dialog is open rather than competing
// with Polaris's modal layer for a z-index — there is nothing to click behind
// an open dialog anyway.
export default function SupportBubble() {
	const [ open, setOpen ] = useState( false )
	// Prefilled from the address already localized for the "notify me" flows
	// (OnboardingPageController -> NotifyEmailResolver -> admin_email), and
	// editable in case replies should go somewhere else.
	const [ email, setEmail ] = useState( cfg.notify_email || '' )
	const [ message, setMessage ] = useState( '' )
	const [ sent, setSent ] = useState( false )

	const send = useSendSupportMessage()

	function handleOpen() {
		// A previous send's outcome shouldn't greet the next one.
		setSent( false )
		send.reset()
		setOpen( true )
	}

	function handleClose() {
		setOpen( false )
	}

	async function handleSend() {
		try {
			await send.mutateAsync( {
				email: email.trim(),
				message: message.trim(),
			} )
			setMessage( '' )
			setSent( true )
		} catch ( e ) {
			// surfaced via send.error below
		}
	}

	const canSend = !! email.trim() && !! message.trim()

	if ( ! open ) {
		return (
			<button
				type="button"
				aria-label="Contact support"
				onClick={ handleOpen }
				className="fixed bottom-6 right-6 z-[500] flex size-16 cursor-pointer items-center justify-center rounded-full border-0 bg-black p-0 shadow-lg shadow-black/40 transition-colors hover:bg-black/90 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2 *:focus:ring-offset-white"
			>
				<img
					src={ cfg.logo }
					alt=""
					className="h-8 w-8 object-contain"
				/>
			</button>
		)
	}

	return (
		<Modal
			open
			onClose={ handleClose }
			title="Contact support"
			primaryAction={
				sent
					? { content: 'Close', onAction: handleClose }
					: {
							content: 'Send',
							disabled: ! canSend,
							loading: send.isPending,
							onAction: handleSend,
					  }
			}
			secondaryActions={
				sent ? [] : [ { content: 'Cancel', onAction: handleClose } ]
			}
		>
			<Modal.Section>
				{ sent ? (
					<Text as="p">
						Thanks — we got your message and will reply to{ ' ' }
						{ email.trim() }.
					</Text>
				) : (
					<BlockStack gap="400">
						{ send.error && (
							<Banner tone="critical">
								{ errorMessage( send.error.code ) }
							</Banner>
						) }
						<TextField
							label="Reply to"
							type="email"
							autoComplete="email"
							value={ email }
							onChange={ setEmail }
						/>
						<TextField
							label="How can we help?"
							multiline={ 5 }
							autoComplete="off"
							maxLength={ MAX_MESSAGE_LENGTH }
							showCharacterCount
							placeholder="Tell us what you were doing and what went wrong."
							value={ message }
							onChange={ setMessage }
						/>
					</BlockStack>
				) }
			</Modal.Section>
		</Modal>
	)
}
