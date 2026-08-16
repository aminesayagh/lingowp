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

const MASKED_KEY = 'tl_live_8f3a....................4c7d'

// ponytail: static mock content for the layout pass — real key/BYO-account storage
// (option.translangs_token, encrypted option.translangs_byo) lands in a follow-up step.
export default function BillingToken() {
	const [ byoEnabled, setByoEnabled ] = useState( true )
	const [ provider, setProvider ] = useState( 'openai' )

	return (
		<BlockStack gap="400">
			<BlockStack gap="200">
				<Text as="h2" variant="headingMd">
					Connection key
				</Text>
				<Text as="span" tone="subdued">
					Links your site to your TransLangs account. Keep it private.
				</Text>
				<Card>
					<InlineStack gap="200" blockAlign="center" wrap={ false }>
						<div style={ { flex: 1 } }>
							<TextField
								label="Connection key"
								labelHidden
								value={ MASKED_KEY }
								readOnly
								autoComplete="off"
							/>
						</div>
						<Button
							icon={ DuplicateIcon }
							accessibilityLabel="Copy key"
							onClick={ () =>
								navigator.clipboard?.writeText( MASKED_KEY )
							}
						/>
						<Button
							icon={ ViewIcon }
							accessibilityLabel="Show key"
						/>
					</InlineStack>
				</Card>
				<Text as="span" tone="success" variant="bodySm">
					● Connected · last used 3 min ago
				</Text>
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
								<Button variant="primary" size="medium">
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
						<BlockStack gap="050">
							<Text as="span">Create a new key</Text>
							<Text as="span" tone="subdued" variant="bodySm">
								Your site stops translating until you paste the
								new key in.
							</Text>
						</BlockStack>
						<Button tone="critical" variant="primary">
							Create new key
						</Button>
					</InlineStack>
				</Card>
			</BlockStack>
		</BlockStack>
	)
}
