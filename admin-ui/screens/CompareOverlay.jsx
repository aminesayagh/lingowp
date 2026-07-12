import {
	Badge,
	BlockStack,
	Box,
	InlineStack,
	Modal,
	Text,
	TextField,
} from '@shopify/polaris';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { api } from '../api';
import TranslationUnitEditor from './TranslationUnitEditor';

// A composed (inline-tag) unit carries codec markers like {1}…{/1} in its source;
// those edit in the chip editor, never a plain textarea.
const MARKER_RE = /\{\/?\d+\/?\}/;

// Full side-by-side compare + edit for one text unit (wireframe 2.3). A manual
// save is REVIEWED (status 2) server-side; ⚡ AI translate is Phase D.
export default function CompareOverlay( { open, unit, fieldLabel, language, targetDir = 'ltr', aiAvailable, onAiTranslate, onClose, onSaved } ) {
	const [ text, setText ] = useState( '' );
	const [ valid, setValid ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const composed = useMemo(
		() => !! unit && MARKER_RE.test( unit.original || '' ),
		[ unit ]
	);

	useEffect( () => {
		if ( open && unit ) {
			setText( unit.translated ?? '' );
			setValid( true );
			setError( '' );
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
			return;
		}
		setError(
			res.error === 'ERR_TAG_STRUCTURE'
				? 'Tag structure is invalid — fix the highlighted tags before saving.'
				: 'Could not save. Please try again.'
		);
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title={ fieldLabel || 'Compare translation' }
			large
			primaryAction={ {
				content: 'Save',
				onAction: save,
				loading: saving,
				disabled: composed && ! valid,
			} }
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
				{ composed ? (
					<BlockStack gap="200">
						<TranslationUnitEditor
							key={ unit.id }
							sourceUnit={ unit.original }
							initialTranslation={ unit.translated ?? '' }
							tags={ unit.tags }
							dir={ targetDir }
							onChange={ ( serialized, isValid ) => {
								setText( serialized );
								setValid( isValid );
							} }
						/>
						{ error && (
							<Text as="p" tone="critical" variant="bodySm">{ error }</Text>
						) }
					</BlockStack>
				) : (
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
								{ error && (
									<Text as="p" tone="critical" variant="bodySm">{ error }</Text>
								) }
							</BlockStack>
						</Box>
					</InlineStack>
				) }
			</Modal.Section>
		</Modal>
	);
}
