import {
	FormLayout,
	Modal,
	Select,
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

export default function AddLanguageModal( { open, defaultCode, existing, onClose, onCreated } ) {
	const available = LANGUAGE_CHOICES.filter(
		( c ) => c.value !== defaultCode && ! existing.includes( c.value )
	);

	const [ code, setCode ] = useState( '' );
	const [ region, setRegion ] = useState( '' );
	const [ locale, setLocale ] = useState( '' );
	const [ slug, setSlug ] = useState( '' );
	const [ displayName, setDisplayName ] = useState( '' );
	const [ fallback, setFallback ] = useState( '' );
	const [ status, setStatus ] = useState( 'active' );
	const [ aiStyle, setAiStyle ] = useState( '' );
	const [ direction, setDirection ] = useState( 'ltr' );
	const [ saving, setSaving ] = useState( false );

	// Default the code/direction once choices load.
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
		const res = await api.post( '/languages', {
			code,
			region,
			locale,
			slug,
			display_name: displayName,
			fallback_language: fallback,
			status,
			ai_style: aiStyle,
			direction,
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
					<Select
						label="Language"
						options={ available.map( ( c ) => ( { label: c.label, value: c.value } ) ) }
						value={ code }
						onChange={ pickLanguage }
					/>
					<FormLayout.Group>
						<TextField label="Region / Locale" value={ region } onChange={ setRegion } autoComplete="off" />
						<TextField label="Locale" value={ locale } onChange={ setLocale } autoComplete="off" placeholder="fr-CA" />
					</FormLayout.Group>
					<TextField label="URL slug" value={ slug } onChange={ setSlug } autoComplete="off" />
					<TextField label="Display name" value={ displayName } onChange={ setDisplayName } autoComplete="off" />
					<Select
						label="Fallback language"
						options={ [ { label: 'None', value: '' }, ...LANGUAGE_CHOICES.map( ( c ) => ( { label: c.label, value: c.value } ) ) ] }
						value={ fallback }
						onChange={ setFallback }
					/>
					<Select
						label="AI Style"
						helpText="Guide the tone and wording used for this language."
						options={ [
							{ label: 'Default', value: '' },
							{ label: 'Formal', value: 'formal' },
							{ label: 'Casual', value: 'casual' },
						] }
						value={ aiStyle }
						onChange={ setAiStyle }
					/>
					<FormLayout.Group>
						<Select
							label="Status"
							options={ [ { label: 'Active', value: 'active' }, { label: 'Draft', value: 'draft' } ] }
							value={ status }
							onChange={ setStatus }
						/>
						<Select
							label="Text direction"
							options={ [ { label: 'Left to right', value: 'ltr' }, { label: 'Right to left', value: 'rtl' } ] }
							value={ direction }
							onChange={ setDirection }
						/>
					</FormLayout.Group>
				</FormLayout>
			</Modal.Section>
		</Modal>
	);
}
