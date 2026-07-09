import {
	Banner,
	BlockStack,
	Button,
	Card,
	EmptyState,
	FormLayout,
	InlineStack,
	Modal,
	Page,
	Select,
	Text,
	TextField,
} from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';
import { errorMessage } from '../errors';
import SettingsModal from './SettingsModal';

// v1 addable languages. ponytail: static list mirroring SupportedLanguages —
// swap for a backend catalog proxy when the backend offers more codes.
const LANGUAGE_CHOICES = [
	{ label: 'French', value: 'fr', rtl: false },
	{ label: 'Spanish', value: 'es', rtl: false },
	{ label: 'German', value: 'de', rtl: false },
	{ label: 'Italian', value: 'it', rtl: false },
	{ label: 'Portuguese', value: 'pt', rtl: false },
	{ label: 'Dutch', value: 'nl', rtl: false },
	{ label: 'Arabic', value: 'ar', rtl: true },
];

export default function Dashboard( { view, defaultLanguage, onLanguageCreated } ) {
	const [ languages, setLanguages ] = useState( [] );
	const [ addOpen, setAddOpen ] = useState( false );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	// AI availability: the plugin works offline; only AI actions depend on this.
	const [ ai, setAi ] = useState( { connected: true, ai_available: true, ai_error: '' } );
	const [ retrying, setRetrying ] = useState( false );

	function refresh() {
		api.get( '/languages' ).then( ( res ) => {
			if ( res.ok ) {
				setLanguages( res.languages || [] );
			}
		} );
	}

	function refreshStatus() {
		api.get( '/connection/status' ).then( ( res ) => {
			if ( res.ok ) {
				setAi( res );
			}
		} );
	}

	useEffect( () => {
		refresh();
		refreshStatus();
	}, [] );

	// Retry: connect if we never connected, otherwise re-validate the token.
	async function retryConnection() {
		setRetrying( true );
		const res = ai.connected
			? await api.post( '/connection/check' )
			: await api.post( '/connection/connect' );
		setRetrying( false );
		setAi( {
			connected: !! res.connected,
			ai_available: !! res.ai_available,
			ai_error: res.ai_error || '',
		} );
	}

	const defaultCode = defaultLanguage?.code || defaultLanguage;

	const secondaryActions = [ { content: 'Settings', onAction: () => setSettingsOpen( true ) } ];

	return (
		<Page
			title="Translangs"
			primaryAction={ languages.length ? { content: 'Add language', onAction: () => setAddOpen( true ) } : undefined }
			secondaryActions={ secondaryActions }
			fullWidth
		>
			<BlockStack gap="400">

			{ view === 'dashboard_empty_language' && languages.length === 0 ? (
				<Card>
					<EmptyState
						heading="Add your first language"
						action={ { content: 'Create a language', onAction: () => setAddOpen( true ) } }
					>
						<p>Your site is ready. Create a language to start translating your content.</p>
						<p>Need more later? Add them from the sidebar anytime.</p>
					</EmptyState>
				</Card>
			) : (
				<Card>
					<BlockStack gap="300">
						<Text as="h2" variant="headingMd">Languages</Text>
						{ languages.map( ( lang ) => (
							<InlineStack key={ lang.code } align="space-between">
								<Text as="span" variant="bodyMd">
									{ lang.display_name || lang.name || lang.code }
								</Text>
								<Text as="span" tone="subdued" variant="bodySm">
									{ lang.enabled ? 'Active' : 'Draft' } · /{ lang.slug }
								</Text>
							</InlineStack>
						) ) }
					</BlockStack>
				</Card>
			) }

			<AddLanguageModal
				open={ addOpen }
				defaultCode={ defaultCode }
				existing={ languages.map( ( l ) => l.code ) }
				onClose={ () => setAddOpen( false ) }
				onCreated={ () => {
					setAddOpen( false );
					refresh();
					onLanguageCreated();
				} }
			/>

			<SettingsModal
				open={ settingsOpen }
				defaultLanguage={ defaultCode }
				onClose={ () => setSettingsOpen( false ) }
			/>
			</BlockStack>
		</Page>
	);
}

function AddLanguageModal( { open, defaultCode, existing, onClose, onCreated } ) {
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
			title="Add language"
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
						<TextField label="Region" value={ region } onChange={ setRegion } autoComplete="off" />
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
