import {
	BlockStack,
	Banner,
	Button,
	Card,
	InlineStack,
	Modal,
	Select,
	Text,
	TextField,
} from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'

// ponytail: BYO-account storage (encrypted option.translangs_byo) is still
// mock/local-only — lands in a follow-up step. The connection-key section
// above it is real (GET /connection/status, POST /connection/recover).
export default function BillingToken() {
	const [ byoEnabled, setByoEnabled ] = useState( true )
	const [ provider, setProvider ] = useState( 'openai' )

	const [ status, setStatus ] = useState( null ) // null = loading
	const [ fetchError, setFetchError ] = useState( '' )
	const [ confirmOpen, setConfirmOpen ] = useState( false )
	const [ recovering, setRecovering ] = useState( false )
	const [ recoverBanner, setRecoverBanner ] = useState( null ) // { tone, content } | null

	useEffect( () => {
		let cancelled = false
		api.get( '/connection/status' ).then( ( res ) => {
			if ( cancelled ) {
				return
			}
			if ( res.ok ) {
				setStatus( res )
			} else {
				setFetchError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
		return () => {
			cancelled = true
		}
	}, [] )

	async function doRecover() {
		setRecovering( true )
		const res = await api.post( '/connection/recover' )
		setRecovering( false )
		setConfirmOpen( false )
		setRecoverBanner(
			res.ok
				? {
						tone: 'success',
						content:
							"New key emailed to the site admin. Your current key keeps working as normal — apply the new one from that email within 24 hours to complete the switch, or it'll switch over automatically once that window passes.",
				  }
				: { tone: 'critical', content: errorMessage( res.error ) }
		)
	}

	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h2" variant="headingMd">
					Connection key
				</Text>
				<Text as="span" tone="subdued">
					Links your site to your TransLangs account. Keep it private.
				</Text>
				{ fetchError && (
					<Banner tone="critical">
						{ errorMessage( fetchError ) }
					</Banner>
				) }
				{ recoverBanner && (
					<Banner tone={ recoverBanner.tone }>
						{ recoverBanner.content }
					</Banner>
				) }
				<Card>
					<Text as="span" tone={ status ? undefined : 'subdued' }>
						{ status?.masked_key || '—' }
					</Text>
				</Card>
				{ status &&
					( status.connected ? (
						<Text as="span" tone="success" variant="bodySm">
							● Connected
						</Text>
					) : (
						<Text as="span" tone="critical" variant="bodySm">
							● { errorMessage( 'ERR_NOT_CONNECTED' ) }
						</Text>
					) ) }
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Use your own translation account
				</Text>
				<Card>
					<BlockStack gap="300">
						<InlineStack
							align="space-between"
							blockAlign="center"
							gap="200"
						>
							<Text as="span" tone="subdued">
								Send translations through your own OpenAI or
								DeepL account instead of ours. Your translation
								costs go to them. We encrypt your key and never
								show it again.
							</Text>
							<Button
								pressed={ byoEnabled }
								onClick={ () => setByoEnabled( ( v ) => ! v ) }
							>
								Use my own account
							</Button>
						</InlineStack>
						{ byoEnabled && (
							<InlineStack
								gap="200"
								blockAlign="end"
								wrap={ false }
							>
								<div style={ { width: 180 } }>
									<Select
										label="Service"
										options={ [
											{
												label: 'OpenAI',
												value: 'openai',
											},
											{
												label: 'Anthropic',
												value: 'anthropic',
											},
											{ label: 'DeepL', value: 'deepl' },
										] }
										value={ provider }
										onChange={ setProvider }
									/>
								</div>
								<div style={ { flex: 1 } }>
									<TextField
										label="Your key"
										labelHidden
										type="password"
										placeholder="sk-proj-...."
										autoComplete="off"
									/>
								</div>
								<Button variant="primary" size="large">
									Save
								</Button>
							</InlineStack>
						) }
						{ byoEnabled && (
							<Text as="span" tone="success" variant="bodySm">
								● Key works. Translations will go through your
								account.
							</Text>
						) }
					</BlockStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Card>
					<InlineStack align="space-between" blockAlign="center">
						<BlockStack gap="100">
							<Text as="span">Create a new key</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								We&apos;ll email a new key to the site admin.
								Your current key keeps working until you apply
								it (or for 24 hours, whichever comes first).
							</Text>
						</BlockStack>
						<Button
							variant="primary"
							tone="critical"
							onClick={ () => setConfirmOpen( true ) }
						>
							Create new key
						</Button>
					</InlineStack>
				</Card>
			</BlockStack>

			<Modal
				open={ confirmOpen }
				onClose={ () => setConfirmOpen( false ) }
				title="Create a new connection key?"
				primaryAction={ {
					content: 'Create new key',
					loading: recovering,
					onAction: doRecover,
				} }
				secondaryActions={ [
					{
						content: 'Cancel',
						onAction: () => setConfirmOpen( false ),
					},
				] }
			>
				<Modal.Section>
					<Text as="p">
						Your site keeps translating normally on its current key
						— nothing changes right away. We&apos;ll email a new key
						to the site&apos;s admin address; opening that email and
						applying the key completes the switch. If it&apos;s
						never applied, the switch happens on its own after 24
						hours and the current key stops working at that point.
					</Text>
				</Modal.Section>
			</Modal>
		</BlockStack>
	)
}
