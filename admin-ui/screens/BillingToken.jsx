import {
	BlockStack,
	Button,
	Card,
	InlineStack,
	Select,
	Text,
	TextField,
} from '@shopify/polaris'
import { DuplicateIcon, ViewIcon } from '@shopify/polaris-icons'
import { useState } from '@wordpress/element'

const MASKED_TOKEN = 'tl_live_8f3a....................4c7d'

// ponytail: static mock content for the layout pass — real token/BYO-key storage
// (option.translangs_token, encrypted option.translangs_byo) lands in a follow-up step.
export default function BillingToken() {
	const [ byoEnabled, setByoEnabled ] = useState( true )
	const [ provider, setProvider ] = useState( 'openai' )

	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h2" variant="headingMd">
					Site token
				</Text>
				<Text as="span" tone="subdued">
					Authenticates this site with the TransLangs service.
				</Text>
				<Card>
					<InlineStack gap="200" blockAlign="center" wrap={ false }>
						<div style={ { flex: 1 } }>
							<TextField
								label="Site token"
								labelHidden
								value={ MASKED_TOKEN }
								readOnly
								autoComplete="off"
							/>
						</div>
						<Button
							icon={ DuplicateIcon }
							accessibilityLabel="Copy token"
							onClick={ () =>
								navigator.clipboard?.writeText( MASKED_TOKEN )
							}
						/>
						<Button icon={ ViewIcon } accessibilityLabel="Reveal token" />
					</InlineStack>
				</Card>
				<Text as="span" tone="success" variant="bodySm">
					● Connected · last used 3 min ago · created 04 Jun 2026
				</Text>
			</BlockStack>

			<BlockStack gap="200">
				<InlineStack align="space-between" blockAlign="center">
					<Text as="h3" variant="headingSm">
						Bring your own key
					</Text>
				</InlineStack>
				<Card>
					<BlockStack gap="300">
						<InlineStack align="space-between" blockAlign="center">
							<Text as="span" tone="subdued">
								Route translations through your own provider key
								instead of the built-in engine. Stored
								encrypted, used server-side only.
							</Text>
							<Button
								pressed={ byoEnabled }
								onClick={ () => setByoEnabled( ( v ) => ! v ) }
							>
								{ byoEnabled ? 'Enabled' : 'Disabled' }
							</Button>
						</InlineStack>
						{ byoEnabled && (
							<InlineStack gap="200" blockAlign="end" wrap={ false }>
								<div style={ { width: 180 } }>
									<Select
										label="Provider"
										options={ [
											{ label: 'OpenAI', value: 'openai' },
											{ label: 'Anthropic', value: 'anthropic' },
											{ label: 'DeepL', value: 'deepl' },
										] }
										value={ provider }
										onChange={ setProvider }
									/>
								</div>
								<div style={ { flex: 1 } }>
									<TextField
										label="API key"
										labelHidden
										type="password"
										placeholder="sk-proj-...."
										autoComplete="off"
									/>
								</div>
								<Button variant="primary">Save</Button>
							</InlineStack>
						) }
						{ byoEnabled && (
							<Text as="span" tone="success" variant="bodySm">
								● Key validated · calls billed to your provider
								account
							</Text>
						) }
					</BlockStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm">
					Endpoint
				</Text>
				<Card>
					<InlineStack gap="200" blockAlign="center" wrap={ false }>
						<div style={ { flex: 1 } }>
							<TextField
								label="Endpoint"
								labelHidden
								value="https://api.translangs.io/v1"
								readOnly
								autoComplete="off"
							/>
						</div>
						<Button
							icon={ DuplicateIcon }
							accessibilityLabel="Copy endpoint"
							onClick={ () =>
								navigator.clipboard?.writeText(
									'https://api.translangs.io/v1'
								)
							}
						/>
					</InlineStack>
				</Card>
			</BlockStack>

			<BlockStack gap="200">
				<Text as="h3" variant="headingSm" tone="critical">
					Danger zone
				</Text>
				<Card>
					<InlineStack align="space-between" blockAlign="center">
						<BlockStack gap="050">
							<Text as="span">Regenerate token</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								The current token stops working immediately.
							</Text>
						</BlockStack>
						<Button tone="critical" variant="primary">
							Regenerate
						</Button>
					</InlineStack>
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
