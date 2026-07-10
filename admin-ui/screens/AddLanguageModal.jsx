import {
	BlockStack,
	FormLayout,
	Modal,
	Select,
	Text,
	TextField,
} from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

// v1 addable languages. ponytail: static list mirroring SupportedLanguages —
// swap for a backend catalog proxy when the backend offers more codes.
export const LANGUAGE_CHOICES = [
	{ label: 'French', value: 'fr', rtl: false },
	{ label: 'Spanish', value: 'es', rtl: false },
	{ label: 'German', value: 'de', rtl: false },
	{ label: 'Italian', value: 'it', rtl: false },
	{ label: 'Portuguese', value: 'pt', rtl: false },
	{ label: 'Dutch', value: 'nl', rtl: false },
	{ label: 'Arabic', value: 'ar', rtl: true },
];

// Single "Auto" for now; a real list arrives when the backend exposes models.
export const AI_MODELS = [ { label: 'Auto', value: 'auto' } ];

export const AI_STYLE_MAX = 500;
export const AI_STYLE_PLACEHOLDER =
	'e.g. Warm but professional. Use formal “vous”, keep product names in English, and prefer short, clear sentences.';

export default function AddLanguageModal( { open, defaultCode, existing, onClose, onCreated } ) {
	const available = LANGUAGE_CHOICES.filter(
		( c ) => c.value !== defaultCode && ! existing.includes( c.value )
	);

	const [ code, setCode ] = useState( '' );
	const [ slug, setSlug ] = useState( '' );
	const [ displayName, setDisplayName ] = useState( '' );
	const [ fallback, setFallback ] = useState( '' );
	const [ aiModel, setAiModel ] = useState( 'auto' );
	const [ style, setStyle ] = useState( '' );
	const [ direction, setDirection ] = useState( 'ltr' );
	const [ saving, setSaving ] = useState( false );

	// Default the language/slug/direction once choices load.
	useEffect( () => {
		if ( open && ! code && available.length ) {
			setCode( available[ 0 ].value );
			setDirection( available[ 0 ].rtl ? 'rtl' : 'ltr' );
			setSlug( available[ 0 ].value );
		}
	}, [ open, available, code ] );

	function pickLanguage( value ) {
		setCode( value );
		const choice = LANGUAGE_CHOICES.find( ( c ) => c.value === value );
		setDirection( choice?.rtl ? 'rtl' : 'ltr' );
		if ( ! slug ) {
			setSlug( value );
		}
	}

	async function submit() {
		setSaving( true );
		// Region/locale/status dropped from v1; direction is auto-derived, status
		// defaults to active server-side.
		const res = await api.post( '/languages', {
			code,
			slug,
			display_name: displayName,
			fallback_language: fallback,
			direction,
			ai_model: aiModel,
			ai_style: style,
		} );
		setSaving( false );
		if ( res.ok ) {
			onCreated();
		}
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title="Add a language"
			primaryAction={ { content: 'Add language', onAction: submit, loading: saving, disabled: ! code } }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<FormLayout>
					<BlockStack gap="100">
						<Select
							label="Language"
							options={ available.map( ( c ) => ( { label: c.label, value: c.value } ) ) }
							value={ code }
							onChange={ pickLanguage }
						/>
						<TextField label="Name" value={ displayName } onChange={ setDisplayName } autoComplete="off" />
						<TextField label="URL slug" value={ slug } onChange={ setSlug } autoComplete="off" />
						<Select
							label="Fallback language"
							options={ [ { label: 'None', value: '' }, ...LANGUAGE_CHOICES.map( ( c ) => ( { label: c.label, value: c.value } ) ) ] }
							value={ fallback }
							onChange={ setFallback }
						/>
					</BlockStack>
				</FormLayout>
			</Modal.Section>
			<Modal.Section>
				<BlockStack gap="300">
					<Text as="h3" variant="headingSm">AI settings</Text>
					<BlockStack gap="100">
						<Select label="AI model" options={ AI_MODELS } value={ aiModel } onChange={ setAiModel } />
						<TextField
							label="Style"
							multiline={ 2 }
							maxLength={ AI_STYLE_MAX }
							showCharacterCount
							value={ style }
							onChange={ setStyle }
							autoComplete="off"
							placeholder={ AI_STYLE_PLACEHOLDER }
							helpText="Guide the tone and wording used for this language."
						/>
					</BlockStack>
				</BlockStack>
			</Modal.Section>
		</Modal>
	);
}
