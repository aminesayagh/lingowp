import {
	BlockStack,
	Button,
	ButtonGroup,
	InlineStack,
	Modal,
	Text,
} from '@shopify/polaris'
import { useState } from '@wordpress/element'
import SearchableLanguageSelect from './SearchableLanguageSelect'

const cfg = window.Translangs || { rest: '', nonce: '' }

// CSV export/import for content translations (posts, post meta, terms, site
// settings/branding) — the four tables the main workspace table renders.
// Gettext and hardcoded-HTML strings have their own per-dependency export/
// import elsewhere, so they're out of scope here.
//
// The CSV is a flat text-to-translation matrix: one row per unique source
// text (deduplicated across every post/term/meta/option that shares it —
// identical text always gets one shared translation), one column per
// language. No ref/type/location/status columns — those are an
// implementation detail the client doesn't want surfaced.
//
// Export triggers a plain browser navigation (native download handling,
// `_wpnonce` in the query string authenticates the same as the header would)
// rather than `api.get()`, which is JSON-only. Import posts multipart
// FormData via a raw fetch for the same reason — neither goes through api.js.
export default function ExportImportModal( {
	open,
	languages,
	initialLanguage,
	onClose,
} ) {
	const [ tab, setTab ] = useState( 'export' )

	const [ exportLang, setExportLang ] = useState( initialLanguage || '' )
	const [ scope, setScope ] = useState( 'current' )

	const [ file, setFile ] = useState( null )
	const [ fileError, setFileError ] = useState( '' )
	const [ importing, setImporting ] = useState( false )
	const [ result, setResult ] = useState( null )

	const langOptions = languages.map( ( l ) => ( {
		label: l.display_name || l.name || l.code,
		value: l.code,
	} ) )

	function close() {
		setResult( null )
		setFile( null )
		setFileError( '' )
		onClose()
	}

	function doExport() {
		const params = new URLSearchParams( {
			scope,
			_wpnonce: cfg.nonce,
		} )
		if ( scope === 'current' ) {
			params.set( 'language', exportLang )
		}

		window.location.href = `${ cfg.rest }/export?${ params.toString() }`
	}

	function onFileChange( e ) {
		const picked = e.target.files?.[ 0 ] || null
		if ( picked && ! picked.name.toLowerCase().endsWith( '.csv' ) ) {
			setFile( null )
			setFileError( 'Unsupported format — only .csv is accepted.' )
			return
		}
		setFile( picked )
		setFileError( '' )
		setResult( null )
	}

	async function doImport() {
		if ( ! file ) {
			return
		}
		setImporting( true )
		setResult( null )
		const body = new FormData()
		body.append( 'file', file )
		try {
			const res = await fetch( `${ cfg.rest }/import`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': cfg.nonce },
				body,
			} )
			const data = await res.json()
			setResult(
				data.ok
					? data
					: { ok: false, error: data.error || 'ERR_UNREACHABLE' }
			)
		} catch {
			setResult( { ok: false, error: 'ERR_UNREACHABLE' } )
		}
		setImporting( false )
	}

	const primaryAction =
		tab === 'export'
			? {
					content: 'Export',
					onAction: doExport,
					disabled: scope === 'current' && ! exportLang,
			  }
			: {
					content: 'Import',
					onAction: doImport,
					loading: importing,
					disabled: ! file,
			  }

	return (
		<Modal
			open={ open }
			onClose={ close }
			title="Export / Import"
			primaryAction={ primaryAction }
			secondaryActions={ [ { content: 'Close', onAction: close } ] }
		>
			<Modal.Section>
				<BlockStack gap="400">
					<ButtonGroup variant="segmented">
						<Button
							pressed={ tab === 'export' }
							onClick={ () => setTab( 'export' ) }
						>
							Export
						</Button>
						<Button
							pressed={ tab === 'import' }
							onClick={ () => setTab( 'import' ) }
						>
							Import
						</Button>
					</ButtonGroup>

					{ tab === 'export' ? (
						<BlockStack gap="300">
							<InlineStack gap="200">
								<Button
									pressed={ scope === 'current' }
									onClick={ () => setScope( 'current' ) }
								>
									Current language
								</Button>
								<Button
									pressed={ scope === 'all' }
									onClick={ () => setScope( 'all' ) }
								>
									All languages
								</Button>
							</InlineStack>

							{ scope === 'current' && (
								<SearchableLanguageSelect
									label="Target language"
									options={ langOptions }
									value={ exportLang }
									onChange={ setExportLang }
									placeholder="Search languages"
								/>
							) }

							<Text as="p" tone="subdued" variant="bodySm">
								One row per unique text, one column per
								language. Untranslated cells are left blank.
							</Text>
						</BlockStack>
					) : (
						<BlockStack gap="300">
							<Text as="p" tone="subdued" variant="bodySm">
								Each language column in the file is matched by
								its header (the same codes an export uses) —
								columns for languages that no longer exist are
								ignored, not an error.
							</Text>

							<input
								type="file"
								accept=".csv"
								onChange={ onFileChange }
							/>
							{ fileError && (
								<Text as="p" tone="critical" variant="bodySm">
									{ fileError }
								</Text>
							) }

							{ result && (
								<Text
									as="p"
									tone={ result.ok ? 'success' : 'critical' }
									variant="bodySm"
								>
									{ result.ok
										? `Imported ${
												result.applied
										  } translation${
												result.applied === 1 ? '' : 's'
										  }. ${ result.skipped } skipped, ${
												result.errors
										  } with errors.`
										: 'Import failed. Please check the file and try again.' }
								</Text>
							) }
						</BlockStack>
					) }
				</BlockStack>
			</Modal.Section>
		</Modal>
	)
}
