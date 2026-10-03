import {
	BlockStack,
	Banner,
	Button,
	Card,
	InlineStack,
	Modal,
	Select,
	Spinner,
	Text,
	TextField,
} from '@shopify/polaris'
import { useEffect, useMemo, useState } from '@wordpress/element'
import { errorMessage } from '../errors'
import {
	useDisconnect,
	useConnectionStatus,
	useRecoverKey,
} from '../queries/connection'
import {
	useDeleteProviderKey,
	useProviderKeys,
	useSaveProviderKey,
} from '../queries/providerKeys'

const PROVIDERS = [
	{ id: 'openai', label: 'OpenAI' },
	{ id: 'anthropic', label: 'Anthropic' },
]

const PROVIDER_OPTIONS = PROVIDERS.map( ( p ) => ( {
	label: p.label,
	value: p.id,
} ) )

const EMPTY_ROW = {
	provider: PROVIDERS[ 0 ].id,
	draft: '',
	saving: false,
	error: '',
}

function relativeTime( iso ) {
	if ( ! iso ) {
		return ''
	}
	const mins = Math.round(
		( Date.now() - new Date( iso ).getTime() ) / 60000
	)
	if ( mins < 1 ) {
		return 'just now'
	}
	if ( mins < 60 ) {
		return `${ mins }m ago`
	}
	const hours = Math.round( mins / 60 )
	if ( hours < 24 ) {
		return `${ hours }h ago`
	}
	return `${ Math.round( hours / 24 ) }d ago`
}

function providerLabel( id ) {
	return PROVIDERS.find( ( p ) => p.id === id )?.label || id
}

// A site can only have one BYO provider active at a time — the backend can
// technically hold one row per provider, but doSave() always deletes the
// other provider's row before saving a new one, so storage never disagrees
// with what this panel shows.
function KeyStatusLine( { provider, data, onRemove } ) {
	const label = providerLabel( provider )

	return (
		<InlineStack align="space-between" blockAlign="center">
			{ data.status === 'invalid' ? (
				<Text as="span" tone="critical" variant="bodySm">
					● { label } key rejected — enter a new key below
				</Text>
			) : (
				<Text as="span" tone="success" variant="bodySm">
					● { label } key active · ends { data.last4 } · billed to
					your account · checked{ ' ' }
					{ relativeTime( data.last_validated_at ) }
				</Text>
			) }
			<Button variant="plain" tone="critical" onClick={ onRemove }>
				Remove key
			</Button>
		</InlineStack>
	)
}

export default function BillingToken() {
	const [ confirmOpen, setConfirmOpen ] = useState( false )
	const [ recoverBanner, setRecoverBanner ] = useState( null ) // { tone, content } | null
	const [ disconnectOpen, setDisconnectOpen ] = useState( false )
	const [ row, setRow ] = useState( EMPTY_ROW )
	const [ removeOpen, setRemoveOpen ] = useState( false )

	const statusQ = useConnectionStatus()
	const providerKeysQ = useProviderKeys()
	const saveKey = useSaveProviderKey()
	const deleteKey = useDeleteProviderKey()
	const recover = useRecoverKey()
	const disconnect = useDisconnect()

	const status = statusQ.data ?? null // { connected, maskedKey, ... } | null
	const fetchError = statusQ.error?.code || ''
	// null = still loading; else a { [provider]: keyData } map, at most one entry.
	const providerKeys = useMemo( () => {
		if ( providerKeysQ.data === undefined ) {
			return null
		}
		const byProvider = {}
		for ( const key of providerKeysQ.data ) {
			byProvider[ key.provider ] = key
		}
		return byProvider
	}, [ providerKeysQ.data ] )
	const providerKeysError = providerKeysQ.error?.code || ''
	const removing = deleteKey.isPending
	const recovering = recover.isPending
	const disconnecting = disconnect.isPending

	const activeEntry = providerKeys
		? Object.entries( providerKeys )[ 0 ]
		: undefined

	// Once the stored key loads, default the Select to whichever provider is
	// already active. activeEntry is derived from providerKeys on every
	// render; depending on it directly would re-run this on every keystroke
	// instead of only on fetch/save/remove.
	useEffect( () => {
		if ( activeEntry ) {
			setRow( ( r ) => ( { ...r, provider: activeEntry[ 0 ] } ) )
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ providerKeys ] )

	function updateRow( patch ) {
		setRow( ( r ) => ( { ...r, ...patch } ) )
	}

	async function doSave() {
		const { provider, draft } = row
		updateRow( { saving: true, error: '' } )
		try {
			// A site holds one BYO key at a time — drop the other provider's
			// row first. ERR_NOT_FOUND just means there was nothing to drop.
			if ( activeEntry && activeEntry[ 0 ] !== provider ) {
				try {
					await deleteKey.mutateAsync( activeEntry[ 0 ] )
				} catch ( e ) {
					if ( e.code !== 'ERR_NOT_FOUND' ) {
						throw e
					}
				}
			}
			await saveKey.mutateAsync( { provider, apiKey: draft } )
			updateRow( { saving: false, draft: '', error: '' } )
		} catch ( e ) {
			updateRow( {
				saving: false,
				error: e.code || 'ERR_UNREACHABLE',
			} )
		}
	}

	async function doRemove() {
		const provider = activeEntry?.[ 0 ]
		if ( ! provider ) {
			setRemoveOpen( false )
			return
		}
		try {
			await deleteKey.mutateAsync( provider )
			setRemoveOpen( false )
			setRow( EMPTY_ROW )
		} catch ( e ) {
			setRemoveOpen( false )
			updateRow( { error: e.code || 'ERR_UNREACHABLE' } )
		}
	}

	async function doRecover() {
		// A working key rotates instantly; a dead key falls back to the
		// connect ladder, also instant — either way this resolves
		// synchronously, no "check your inbox" state. The mutation's
		// onSuccess invalidates connection status, so the masked key updates
		// on its own.
		const res = await recover.mutateAsync()
		setConfirmOpen( false )
		setRecoverBanner(
			res.ok
				? {
						tone: 'success',
						content:
							'New key created. Your previous key no longer works.',
				  }
				: { tone: 'critical', content: errorMessage( res.error ) }
		)
	}

	async function doDisconnect() {
		await disconnect.mutateAsync()
		setDisconnectOpen( false )
		setRecoverBanner( null )
	}

	const keyPlaceholder =
		activeEntry && activeEntry[ 0 ] === row.provider
			? `•••• ends ${ activeEntry[ 1 ].last4 }`
			: 'sk-...'

	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h2" variant="headingMd">
					Site token
				</Text>
				<Text as="span" tone="subdued">
					Connects this site to LingoWP.
				</Text>
				{ fetchError && (
					<Banner
						tone="critical"
						action={ {
							content: 'Retry',
							onAction: () => statusQ.refetch(),
						} }
					>
						{ errorMessage( fetchError ) }
					</Banner>
				) }
				{ recoverBanner && (
					<Banner tone={ recoverBanner.tone }>
						{ recoverBanner.content }
					</Banner>
				) }
				<Card>
					{ status === null && ! fetchError ? (
						<InlineStack gap="100" blockAlign="center">
							<Spinner
								accessibilityLabel="Loading site token"
								size="small"
							/>
						</InlineStack>
					) : (
						<Text as="span" tone={ status ? undefined : 'subdued' }>
							{ status?.maskedKey || '—' }
						</Text>
					) }
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
					Bring your own key
				</Text>
				<Card>
					<BlockStack gap="200">
						{ providerKeysError && (
							<Banner
								tone="critical"
								action={ {
									content: 'Retry',
									onAction: () => providerKeysQ.refetch(),
								} }
							>
								{ errorMessage( providerKeysError ) }
							</Banner>
						) }

						{ providerKeys === null ? (
							<InlineStack gap="100" blockAlign="center">
								<Spinner
									accessibilityLabel="Loading key"
									size="small"
								/>
							</InlineStack>
						) : (
							<BlockStack gap="150">
								{ row.error && (
									<Banner tone="critical">
										{ errorMessage( row.error ) }
									</Banner>
								) }
								<InlineStack
									gap="200"
									blockAlign="end"
									wrap={ false }
								>
									<div style={ { width: 160 } }>
										<Select
											label="Service"
											labelHidden
											options={ PROVIDER_OPTIONS }
											value={ row.provider }
											onChange={ ( v ) =>
												updateRow( { provider: v } )
											}
											disabled={ row.saving }
										/>
									</div>
									<div style={ { flex: 1 } }>
										<TextField
											label="Your key"
											labelHidden
											type="password"
											placeholder={ keyPlaceholder }
											autoComplete="off"
											spellCheck={ false }
											value={ row.draft }
											onChange={ ( v ) =>
												updateRow( { draft: v } )
											}
											disabled={ row.saving }
										/>
									</div>
									<Button
										variant="primary"
										size="large"
										loading={ row.saving }
										disabled={ row.saving || ! row.draft }
										onClick={ doSave }
									>
										Save
									</Button>
								</InlineStack>
								{ activeEntry && (
									<KeyStatusLine
										provider={ activeEntry[ 0 ] }
										data={ activeEntry[ 1 ] }
										onRemove={ () => setRemoveOpen( true ) }
									/>
								) }
							</BlockStack>
						) }
					</BlockStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm" tone="critical">
					Danger zone
				</Text>
				<Card>
					<InlineStack align="space-between" blockAlign="center">
						<BlockStack gap="100">
							<Text as="span" tone="critical">
								Create a new key
							</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								Immediately creates a new key for this site.
								Your current key stops working right away.
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
				{ status?.connected && (
					<Card>
						<InlineStack align="space-between" blockAlign="center">
							<BlockStack gap="100">
								<Text as="span" tone="critical">
									Disconnect from LingoWP Cloud
								</Text>
								<Text as="span" tone="subdued" variant="bodySm">
									Disables AI translation, provider keys, and
									billing until you reconnect. Your manually
									translated content is unaffected.
								</Text>
							</BlockStack>
							<Button
								tone="critical"
								onClick={ () => setDisconnectOpen( true ) }
							>
								Disconnect
							</Button>
						</InlineStack>
					</Card>
				) }
			</BlockStack>

			<Modal
				open={ confirmOpen }
				onClose={ () => setConfirmOpen( false ) }
				title="Create a new key?"
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
						Immediately creates a new key for this site. Your
						current key stops working right away — this can&apos;t
						be undone.
					</Text>
				</Modal.Section>
			</Modal>

			<Modal
				open={ removeOpen }
				onClose={ () => setRemoveOpen( false ) }
				title="Remove this key?"
				primaryAction={ {
					content: 'Remove key',
					destructive: true,
					loading: removing,
					onAction: doRemove,
				} }
				secondaryActions={ [
					{
						content: 'Cancel',
						onAction: () => setRemoveOpen( false ),
					},
				] }
			>
				<Modal.Section>
					<Text as="p">
						This key will be removed. You can add it again anytime.
					</Text>
				</Modal.Section>
			</Modal>

			<Modal
				open={ disconnectOpen }
				onClose={ () => setDisconnectOpen( false ) }
				title="Disconnect from LingoWP Cloud?"
				primaryAction={ {
					content: 'Disconnect',
					destructive: true,
					loading: disconnecting,
					onAction: doDisconnect,
				} }
				secondaryActions={ [
					{
						content: 'Cancel',
						onAction: () => setDisconnectOpen( false ),
					},
				] }
			>
				<Modal.Section>
					<Text as="p">
						AI translation, provider keys, and billing all stop
						working immediately. Local, manually translated content
						is unaffected. This site will not reconnect on its own —
						come back here and click Connect whenever you want cloud
						features again.
					</Text>
				</Modal.Section>
			</Modal>
		</BlockStack>
	)
}
