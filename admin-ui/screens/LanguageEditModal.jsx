import { FormLayout, Modal, Select, TextField } from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';

// Edit an existing target language (PATCH /languages/{code}). Status (active/draft)
// and delete live in the rail ⋯ menu; this modal edits display metadata only.
export default function LanguageEditModal( { open, lang, onClose, onSaved } ) {
	const [ displayName, setDisplayName ] = useState( '' );
	const [ slug, setSlug ] = useState( '' );
	const [ region, setRegion ] = useState( '' );
	const [ fallback, setFallback ] = useState( '' );
	const [ aiStyle, setAiStyle ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( open && lang ) {
			setDisplayName( lang.display_name || '' );
			setSlug( lang.slug || '' );
			setRegion( lang.region || '' );
			setFallback( lang.fallback_language || '' );
			setAiStyle( lang.ai_style || '' );
		}
	}, [ open, lang ] );

	if ( ! lang ) {
		return null;
	}

	async function save() {
		setSaving( true );
		const res = await api.patch( `/languages/${ encodeURIComponent( lang.code ) }`, {
			display_name: displayName,
			slug,
			region,
			fallback_language: fallback,
			ai_style: aiStyle,
		} );
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
			title={ `Edit ${ lang.display_name || lang.name || lang.code }` }
			primaryAction={ { content: 'Save', onAction: save, loading: saving } }
			secondaryActions={ [ { content: 'Cancel', onAction: onClose } ] }
		>
			<Modal.Section>
				<FormLayout>
					<TextField label="Display name" value={ displayName } onChange={ setDisplayName } autoComplete="off" />
					<TextField label="URL slug" value={ slug } onChange={ setSlug } autoComplete="off" />
					<TextField label="Region / Locale" value={ region } onChange={ setRegion } autoComplete="off" />
					<TextField label="Fallback language" value={ fallback } onChange={ setFallback } autoComplete="off" />
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
				</FormLayout>
			</Modal.Section>
		</Modal>
	);
}
