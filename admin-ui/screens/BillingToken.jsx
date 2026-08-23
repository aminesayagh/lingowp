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
import { useCallback, useEffect, useState } from '@wordpress/element'
import { api } from '../api'
import { errorMessage } from '../errors'

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
	const [ status, setStatus ] = useState( null ) // null = loading
	const [ fetchError, setFetchError ] = useState( '' )
	const [ confirmOpen, setConfirmOpen ] = useState( false )
	const [ recovering, setRecovering ] = useState( false )
	const [ recoverBanner, setRecoverBanner ] = useState( null ) // { tone, content } | null

	const [ providerKeys, setProviderKeys ] = useState( null ) // null = loading, else { [provider]: keyData }, at most one entry
	const [ providerKeysError, setProviderKeysError ] = useState( '' )
	const [ row, setRow ] = useState( EMPTY_ROW )
	const [ removeOpen, setRemoveOpen ] = useState( false )
	const [ removing, setRemoving ] = useState( false )

	const activeEntry = providerKeys
		? Object.entries( providerKeys )[ 0 ]
		: undefined

	// Clears its own error before retrying, so a Retry click that succeeds
	// doesn't leave the previous failure's banner text stranded.
	const fetchStatus = useCallback( () => {
		setFetchError( '' )
		return api.get( '/connection/status' ).then( ( res ) => {
			if ( res.ok ) {
				setStatus( res )
			} else {
				setFetchError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	const fetchProviderKeys = useCallback( () => {
		setProviderKeysError( '' )
		return api.get( '/provider-keys' ).then( ( res ) => {
			if ( res.ok ) {
				const byProvider = {}
				for ( const key of res.keys || [] ) {
					byProvider[ key.provider ] = key
				}
				setProviderKeys( byProvider )
			} else {
				setProviderKeysError( res.error || 'ERR_UNREACHABLE' )
			}
		} )
	}, [] )

	useEffect( () => {
		fetchStatus()
	}, [ fetchStatus ] )

	useEffect( () => {
		fetchProviderKeys()
	}, [ fetchProviderKeys ] )

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

		if ( activeEntry && activeEntry[ 0 ] !== provider ) {
			const delRes = await api.del(
				`/provider-keys/${ activeEntry[ 0 ] }`
			)
			if ( ! delRes.ok && delRes.error !== 'ERR_NOT_FOUND' ) {
				updateRow( {
					saving: false,
					error: delRes.error || 'ERR_UNREACHABLE',
				} )
				return
			}
			setProviderKeys( {} )
		}

		const res = await api.post( '/provider-keys', {
			provider,
			api_key: draft,
		} )
		if ( res.ok ) {
			setProviderKeys( { [ provider ]: res.key } )
			updateRow( { saving: false, draft: '', error: '' } )
		} else {
			updateRow( {
				saving: false,
				error: res.error || 'ERR_UNREACHABLE',
			} )
		}
	}

	async function doRemove() {
		const provider = activeEntry?.[ 0 ]
		if ( ! provider ) {
			setRemoveOpen( false )
			return
		}
		setRemoving( true )
		const res = await api.del( `/provider-keys/${ provider }` )
		setRemoving( false )
		setRemoveOpen( false )
		if ( res.ok ) {
			setProviderKeys( {} )
			setRow( EMPTY_ROW )
		} else {
			updateRow( { error: res.error || 'ERR_UNREACHABLE' } )
		}
	}

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
							'Key emailed to the site admin. Your current key keeps working for 24 hours, or until you apply the new one.',
				  }
				: { tone: 'critical', content: errorMessage( res.error ) }
		)
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
					Connects this site to Translangs.
				</Text>
				{ fetchError && (
					<Banner
						tone="critical"
						action={ { content: 'Retry', onAction: fetchStatus } }
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
							{ status?.masked_key || '—' }
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
									onAction: fetchProviderKeys,
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
								<Text as="span" tone="subdued" variant="bodySm">
									Use a key with a spend limit set at your
									provider. Translangs can&apos;t cap your
									spending.
								</Text>
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
							<Text as="span">Create a new key</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								Your current key keeps working for 24 hours, or
								until you apply the new one we email to the site
								admin.
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
						Your current key keeps working for 24 hours, or until
						you apply the new one from the email we send to the site
						admin.
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
		</BlockStack>
	)
}
