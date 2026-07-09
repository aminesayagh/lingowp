import {
	Badge,
	BlockStack,
	Box,
	InlineStack,
	Modal,
	Text,
	TextField,
} from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

// Full side-by-side compare + edit for one text unit (wireframe 2.3). A manual
// save is REVIEWED (status 2) server-side; ⚡ AI translate is Phase D.
export default function CompareOverlay( { open, unit, fieldLabel, language, aiAvailable, onAiTranslate, onClose, onSaved } ) {
	const [ text, setText ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( open && unit ) {
			setText( unit.translated ?? '' );
		}
	}, [ open, unit ] );

	if ( ! unit ) {
		return null;
	}

	async function save() {
		setSaving( true );
		const res = await api.post( '/translations', { text_unit_id: unit.id, language, text } );
		setSaving( false );
		if ( res.ok ) {
			onSaved();
			onClose();
		}
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title={ fieldLabel || 'Compare translation' }
			large
			primaryAction={ { content: 'Save', onAction: save, loading: saving } }
			secondaryActions={ [
				{ content: 'Cancel', onAction: onClose },
				{
					content: '⚡ AI translate',
					disabled: ! aiAvailable,
					onAction: async () => {
						await onAiTranslate( 'text', { ref: unit.id } );
						onClose();
					},
				},
			] }
		>
			<Modal.Section>
				<InlineStack gap="500" align="start" blockAlign="start" wrap={ false }>
					<Box width="50%">
						<BlockStack gap="200">
							<Text as="h3" variant="headingSm">Original</Text>
							<Text as="p" variant="bodyMd">{ unit.original }</Text>
							<Text as="p" tone="subdued" variant="bodySm">
								{ unit.char_count } characters · { unit.word_count } words
							</Text>
						</BlockStack>
					</Box>
					<Box width="50%">
						<BlockStack gap="200">
							<InlineStack gap="100" blockAlign="center">
								<Text as="h3" variant="headingSm">Translation</Text>
								{ unit.is_ai && <Badge size="small">AI</Badge> }
							</InlineStack>
							<TextField
								label="Translation"
								labelHidden
								multiline={ 4 }
								value={ text }
								onChange={ setText }
								autoComplete="off"
							/>
						</BlockStack>
					</Box>
				</InlineStack>
			</Modal.Section>
		</Modal>
	);
}
