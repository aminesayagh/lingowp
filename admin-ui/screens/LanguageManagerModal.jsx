import {
	BlockStack,
	Button,
	FormLayout,
	InlineStack,
	Modal,
	Select,
	Text,
	TextField,
} from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';
import LanguageList from './LanguageList';

const enc = encodeURIComponent;

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

export const AI_MODELS = [ { label: 'Auto', value: 'auto' } ];
export const AI_STYLE_MAX = 500;
export const AI_STYLE_PLACEHOLDER =
	'e.g. Warm but professional. Use formal “vous”, keep product names in English, and prefer short, clear sentences.';

// Language manager: a sidebar of every language (reuses the dashboard's
// LanguageList) + a config pane. Selecting a row edits it; "+ Add" starts a new
// one. Stays open after save so several languages can be added/reconfigured.
export default function LanguageManagerModal( { open, languages, defaultCode, initialCode, onClose, onChanged } ) {
	const [ sel, setSel ] = useState( 'new' );

	useEffect( () => {
		if ( open ) {
			setSel( initialCode || languages[ 0 ]?.code || 'new' );
		}
	}, [ open, initialCode ] );

	const current = sel === 'new' ? null : languages.find( ( l ) => l.code === sel ) || null;
	const mode = current ? 'edit' : 'new';

	// If the selected language was deleted elsewhere, fall back to the first.
	useEffect( () => {
		if ( sel !== 'new' && ! current ) {
			setSel( languages[ 0 ]?.code || 'new' );
		}
	}, [ languages ] );

	function toggleStatus( lang ) {
		api.patch( `/languages/${ enc( lang.code ) }`, { status: lang.enabled ? 'draft' : 'active' } ).then( ( r ) => {
			if ( r.ok ) {
				onChanged();
			}
		} );
	}

	function del( lang ) {
		api.del( `/languages/${ enc( lang.code ) }` ).then( ( r ) => {
			if ( r.ok ) {
				if ( sel === lang.code ) {
					setSel( 'new' );
				}
				onChanged();
			}
		} );
	}

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			title="Configuration"
			large
		>
			<Modal.Section>
				<div className="tl-wide-modal" style={ { display: 'flex', gap: 16, minHeight: 420, maxWidth: '100%', overflowX: 'hidden' } }>
					<div
						style={ {
							flex: '0 0 220px',
							width: 220,
							borderInlineEnd: '1px solid var(--p-color-border, rgba(0,0,0,0.08))',
							paddingInlineEnd: 12,
						} }
					>
						<BlockStack gap="200">
							<Text as="h3" variant="headingSm">Languages</Text>
							<LanguageList
								languages={ languages }
								selectedCode={ current?.code }
								onSelect={ ( lang ) => setSel( lang.code ) }
								onToggleStatus={ toggleStatus }
								onDelete={ del }
								onAdd={ () => setSel( 'new' ) }
							/>
						</BlockStack>
					</div>

					<div style={ { flex: 1, minWidth: 0, paddingBlockStart: 4 } }>
						<LanguageForm
							key={ sel }
							mode={ mode }
							current={ current }
							defaultCode={ defaultCode }
							existing={ languages.map( ( l ) => l.code ) }
							onDone={ ( code ) => {
								onChanged();
								if ( mode === 'new' && code ) {
									setSel( code );
								}
							} }
						/>
					</div>
				</div>
			</Modal.Section>
		</Modal>
	);
}

function LanguageForm( { mode, current, defaultCode, existing, onDone } ) {
	const isNew = mode === 'new';
	const available = LANGUAGE_CHOICES.filter(
		( c ) => c.value !== defaultCode && ! existing.includes( c.value )
	);

	const [ code, setCode ] = useState( isNew ? available[ 0 ]?.value || '' : current.code );
	const [ displayName, setDisplayName ] = useState( isNew ? '' : current.display_name || '' );
	const [ slug, setSlug ] = useState( isNew ? available[ 0 ]?.value || '' : current.slug || '' );
	const [ fallback, setFallback ] = useState( isNew ? '' : current.fallback_language || '' );
	const [ aiModel, setAiModel ] = useState( isNew ? 'auto' : current.ai_model || 'auto' );
	const [ style, setStyle ] = useState( isNew ? '' : current.ai_style || '' );
	const [ direction, setDirection ] = useState(
		isNew ? ( available[ 0 ]?.rtl ? 'rtl' : 'ltr' ) : current.direction || 'ltr'
	);
	const [ saving, setSaving ] = useState( false );
	// Snapshot of the values when the form loaded; the Save button only appears
	// once the form differs from it (and resets to the saved values after save).
	const [ baseline, setBaseline ] = useState( { code, displayName, slug, fallback, aiModel, style } );

	const dirty =
		code !== baseline.code ||
		displayName !== baseline.displayName ||
		slug !== baseline.slug ||
		fallback !== baseline.fallback ||
		aiModel !== baseline.aiModel ||
		style !== baseline.style;

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
		const res = isNew
			? await api.post( '/languages', {
				code,
				slug,
				display_name: displayName,
				fallback_language: fallback,
				direction,
				ai_model: aiModel,
				ai_style: style,
			} )
			: await api.patch( `/languages/${ enc( current.code ) }`, {
				display_name: displayName,
				slug,
				fallback_language: fallback,
				ai_model: aiModel,
				ai_style: style,
			} );
		setSaving( false );
		if ( res.ok ) {
			setBaseline( { code, displayName, slug, fallback, aiModel, style } );
			onDone( isNew ? code : current.code );
		}
	}

	if ( isNew && available.length === 0 ) {
		return <Text as="p" tone="subdued">All available languages have been added.</Text>;
	}

	return (
		<BlockStack gap="400">
			<Text as="h3" variant="headingMd">
				{ isNew ? 'Add a language' : `Edit ${ current.display_name || current.name || current.code }` }
			</Text>
			<FormLayout>
				{ isNew && (
					<Select
						label="Language"
						requiredIndicator
						options={ available.map( ( c ) => ( { label: c.label, value: c.value } ) ) }
						value={ code }
						onChange={ pickLanguage }
					/>
				) }
				<TextField label="Name" requiredIndicator value={ displayName } onChange={ setDisplayName } autoComplete="off" />
				<TextField label="URL slug" requiredIndicator value={ slug } onChange={ setSlug } autoComplete="off" />
				<Select
					label="Fallback language"
					requiredIndicator
					options={ [ { label: 'None', value: '' }, ...LANGUAGE_CHOICES.map( ( c ) => ( { label: c.label, value: c.value } ) ) ] }
					value={ fallback }
					onChange={ setFallback }
				/>
			</FormLayout>

			<Text as="h3" variant="headingSm">AI settings</Text>
			<FormLayout>
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
			</FormLayout>

			{ dirty && (
				<InlineStack align="end">
					<Button variant="primary" loading={ saving } disabled={ isNew && ! code } onClick={ submit }>
						{ isNew ? 'Add language' : 'Save' }
					</Button>
				</InlineStack>
			) }
		</BlockStack>
	);
}
