import {
	Banner,
	BlockStack,
	Button,
	Divider,
	InlineStack,
	Select,
	Spinner,
	Text,
	TextField,
} from '@shopify/polaris'
import { useEffect, useMemo, useState } from '@wordpress/element'
import { errorMessage } from '../errors'
import { useSetAllAiModel } from '../queries/languages'
import {
	useDeleteProviderKey,
	useProviderKeys,
	useSaveProviderKey,
} from '../queries/providerKeys'
import {
	AI_STYLE_MAX,
	AI_STYLE_PLACEHOLDER,
	aiModelOptions,
} from './LanguageManagerModal'

const cfg = window.LingoWP || {}

// Same shape as BillingToken.jsx's own "Bring your own key" section — this
// is the same feature (add/remove a stored OpenAI/Anthropic key), just
// surfaced here too so it's reachable without leaving the language manager.
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

function providerLabel( id ) {
	return PROVIDERS.find( ( p ) => p.id === id )?.label || id
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

// A site can only have one BYO provider active at a time — same rule
// BillingToken.jsx's own doSave() enforces (it deletes the other provider's
// row before saving a new one).
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
					● { label } key active · ends { data.last4 } · checked{ ' ' }
					{ relativeTime( data.last_validated_at ) }
				</Text>
			) }
			<Button variant="plain" tone="critical" onClick={ onRemove }>
				Remove key
			</Button>
		</InlineStack>
	)
}

export default function AiConfiguration( {
	hasSubscription,
	onConfirm,
	onKeysChanged,
} ) {
	const [ row, setRow ] = useState( EMPTY_ROW )
	const [ bulkModel, setBulkModel ] = useState( null )
	const [ bulkStyle, setBulkStyle ] = useState( '' )
	const [ bulkNotice, setBulkNotice ] = useState( null ) // { tone, content } | null

	const providerKeysQ = useProviderKeys()
	const saveKey = useSaveProviderKey()
	const deleteKey = useDeleteProviderKey()
	const setAllAiModel = useSetAllAiModel()

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
	const applying = setAllAiModel.isPending

	const activeEntry = providerKeys
		? Object.entries( providerKeys )[ 0 ]
		: undefined

	// Once the stored key loads, default the Select to whichever provider is
	// already active.
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
			// One BYO key at a time — drop the other provider's row first.
			// ERR_NOT_FOUND just means there was nothing to drop.
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
			onKeysChanged?.()
		} catch ( e ) {
			updateRow( {
				saving: false,
				error: e.code || 'ERR_UNREACHABLE',
			} )
		}
	}

	function doRemove() {
		const provider = activeEntry?.[ 0 ]
		if ( ! provider ) {
			return
		}
		onConfirm( {
			title: `Remove your ${ providerLabel( provider ) } key?`,
			body: 'You can add it again anytime. Languages currently using it fall back to the Lingostack model.',
			actionLabel: 'Remove key',
			destructive: true,
			onConfirm: async () => {
				try {
					await deleteKey.mutateAsync( provider )
					setRow( EMPTY_ROW )
					onKeysChanged?.()
				} catch ( e ) {
					updateRow( { error: e.code || 'ERR_UNREACHABLE' } )
				}
			},
		} )
	}

	const providerKeysList = providerKeys ? Object.values( providerKeys ) : []
	// No real choice to make without either — nothing to "apply to all" yet.
	const hasAiChoice = hasSubscription || providerKeysList.length > 0
	const modelOptions = aiModelOptions( {
		hasSubscription,
		providerKeys: providerKeysList,
	} )
	const resolvedBulkModel =
		bulkModel &&
		modelOptions.some( ( option ) => option.value === bulkModel )
			? bulkModel
			: modelOptions[ 0 ]?.value ?? 'auto'

	async function applyToAll() {
		setBulkNotice( null )
		try {
			await setAllAiModel.mutateAsync( {
				ai_model: resolvedBulkModel,
				ai_style: bulkStyle,
			} )
			setBulkNotice( {
				tone: 'success',
				content: 'Applied to all languages.',
			} )
		} catch {
			setBulkNotice( {
				tone: 'critical',
				content: 'Could not update the languages. Try again.',
			} )
		}
	}

	const keyPlaceholder =
		activeEntry && activeEntry[ 0 ] === row.provider
			? `•••• ends ${ activeEntry[ 1 ].last4 }`
			: 'sk-...'

	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h2" variant="headingMd">
					AI provider keys
				</Text>
				<Text as="p" tone="subdued">
					Bill translations to your own OpenAI or Anthropic account
					instead of your Lingostack subscription.
				</Text>
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
							accessibilityLabel="Loading keys"
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
							blockAlign="start"
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
									size="small"
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
									size="small"
								/>
							</div>
						</InlineStack>
						<InlineStack gap="300" blockAlign="center">
							<Button
								variant="primary"
								loading={ row.saving }
								disabled={ row.saving || ! row.draft }
								onClick={ doSave }
							>
								Save
							</Button>
							{ ! hasSubscription && (
								<Button
									variant="plain"
									onClick={ () =>
										window.location.assign(
											cfg.account_url
										)
									}
								>
									Use the Lingostack model instead
								</Button>
							) }
						</InlineStack>
						{ activeEntry && (
							<KeyStatusLine
								provider={ activeEntry[ 0 ] }
								data={ activeEntry[ 1 ] }
								onRemove={ doRemove }
							/>
						) }
					</BlockStack>
				) }
			</BlockStack>

			{ hasAiChoice && (
				<>
					<Divider />

					<BlockStack gap="200">
						<Text as="h2" variant="headingMd">
							Apply to all languages
						</Text>
						<Select
							label="AI model"
							options={ modelOptions }
							value={ resolvedBulkModel }
							onChange={ setBulkModel }
						/>
						<TextField
							label="Website voice and style"
							multiline={ 2 }
							maxLength={ AI_STYLE_MAX }
							showCharacterCount
							value={ bulkStyle }
							onChange={ setBulkStyle }
							autoComplete="off"
							placeholder={ AI_STYLE_PLACEHOLDER }
							helpText="Guides tone — never overrides the original text’s own intent."
						/>
						<InlineStack align="end">
							<Button
								variant="primary"
								loading={ applying }
								disabled={ removing }
								onClick={ applyToAll }
							>
								Apply to all languages
							</Button>
						</InlineStack>
						{ bulkNotice && (
							<Banner tone={ bulkNotice.tone }>
								{ bulkNotice.content }
							</Banner>
						) }
					</BlockStack>
				</>
			) }
		</BlockStack>
	)
}
