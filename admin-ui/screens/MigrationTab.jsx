import {
	Badge,
	Banner,
	BlockStack,
	Box,
	Button,
	Card,
	InlineStack,
	Spinner,
	Text,
} from '@shopify/polaris'
import { useEffect, useRef } from '@wordpress/element'
import { errorMessage } from '../errors'
import {
	useDeactivateSource,
	useMigrationPreview,
	useMigrationStatus,
	useRunMigration,
} from '../queries/migration'

const cfg = window.LingoWP || {}

// Account page, "Migration" tab: move another translation plugin's work into
// LingoWP. Shown only when a migration add-on reports something to migrate
// from (window.LingoWP.migration). Two steps, in order: import the
// translations ("Migrate now"), then disable the old plugin.
export default function MigrationTab() {
	const { source, label } = cfg.migration
	const statusQ = useMigrationStatus( source )
	const status = statusQ.data
	const state = status?.state ?? 'idle'
	const busy = state === 'queued' || state === 'running'
	const done = state === 'done'

	// The preview is only useful before the import; after it, the report is.
	const previewQ = useMigrationPreview( source, {
		enabled: !! status && ! done && ! busy,
	} )

	// When the background job finishes, the old preview is stale.
	const previous = useRef( state )
	useEffect( () => {
		if ( previous.current !== state && ! busy ) {
			previewQ.refetch()
		}
		previous.current = state
	}, [ state, busy ] ) // eslint-disable-line react-hooks/exhaustive-deps

	const run = useRunMigration( source )
	const deactivate = useDeactivateSource( source )

	if ( statusQ.isPending ) {
		return (
			<Box padding="500">
				<Spinner accessibilityLabel="Loading" size="small" />
			</Box>
		)
	}
	if ( statusQ.isError ) {
		return (
			<Banner tone="critical">
				{ errorMessage( statusQ.error.code ) }
			</Banner>
		)
	}

	return (
		<BlockStack gap="400">
			<Card>
				<BlockStack gap="300">
					<InlineStack gap="200" blockAlign="center">
						<Text as="h2" variant="headingMd">
							1. Import your { label } translations
						</Text>
						{ done && <Badge tone="success">Done</Badge> }
					</InlineStack>

					{ done && <Report status={ status } /> }

					{ busy && (
						<InlineStack gap="200" blockAlign="center">
							<Spinner
								accessibilityLabel="Importing"
								size="small"
							/>
							<Text as="p">
								Importing your translations in the background.
								You can leave this page; it keeps going.
							</Text>
						</InlineStack>
					) }

					{ state === 'failed' && (
						<BlockStack gap="200">
							<Banner tone="critical">
								{ status.error ||
									'The migration stopped. Try again.' }
							</Banner>
							{ /* What was written before the stop stays written. */ }
							<Report status={ status } partial />
						</BlockStack>
					) }

					{ ! done && ! busy && (
						<Preview
							label={ label }
							previewQ={ previewQ }
							running={ run.isPending }
							onRun={ () => run.mutate() }
							retry={ state === 'failed' }
						/>
					) }

					{ run.isError && (
						<Banner tone="critical">
							{ errorMessage( run.error.code ) }
						</Banner>
					) }
				</BlockStack>
			</Card>

			<Card>
				<BlockStack gap="300">
					<InlineStack gap="200" blockAlign="center">
						<Text as="h2" variant="headingMd">
							2. Disable { label }
						</Text>
						{ ! status.source?.active && (
							<Badge tone="success">Done</Badge>
						) }
					</InlineStack>
					<Text as="p" tone="subdued">
						{ `Both plugins would otherwise translate your site. ${ label }'s data is kept, so you can reactivate it at any time.` }
					</Text>
					{ status.source?.active ? (
						<InlineStack>
							<Button
								onClick={ () => deactivate.mutate() }
								loading={ deactivate.isPending }
								disabled={ ! done }
							>
								{ `Disable ${ label }` }
							</Button>
						</InlineStack>
					) : (
						<Text as="p">{ label } is inactive.</Text>
					) }
					{ status.source?.active && ! done && (
						<Text as="p" variant="bodySm" tone="subdued">
							Available once step 1 is done.
						</Text>
					) }
					{ deactivate.isError && (
						<Banner tone="critical">
							{ errorMessage( deactivate.error.code ) }
						</Banner>
					) }
				</BlockStack>
			</Card>
		</BlockStack>
	)
}

function Preview( { label, previewQ, running, onRun, retry } ) {
	if ( previewQ.isPending ) {
		return (
			<InlineStack gap="200" blockAlign="center">
				<Spinner accessibilityLabel="Counting" size="small" />
				<Text as="p" tone="subdued">
					Counting what can be imported…
				</Text>
			</InlineStack>
		)
	}
	if ( previewQ.isError ) {
		return (
			<Banner tone="critical">
				{ errorMessage( previewQ.error.code ) }
			</Banner>
		)
	}
	if ( previewQ.data.blocked ) {
		return <Banner tone="warning">{ previewQ.data.blocked }</Banner>
	}

	const { totals, languages } = previewQ.data.summary

	return (
		<BlockStack gap="300">
			<Text as="p" variant="headingLg">
				{ totals.imported === 1
					? '1 text can be imported'
					: `${ totals.imported } texts can be imported` }
			</Text>
			<BlockStack gap="100">
				{ languages.map( ( lang ) => (
					<LanguageLine key={ lang.locale } lang={ lang } preview />
				) ) }
			</BlockStack>
			<Text as="p" variant="bodySm" tone="subdued">
				Translations LingoWP already has are kept. Texts assembled from
				several { label } pieces are marked for review.
			</Text>
			<InlineStack>
				<Button variant="primary" loading={ running } onClick={ onRun }>
					{ retry ? 'Try again' : 'Migrate now' }
				</Button>
			</InlineStack>
		</BlockStack>
	)
}

function Report( { status, partial = false } ) {
	const summary = status.summary
	if (
		! summary ||
		( partial &&
			summary.totals.imported === 0 &&
			summary.totals.gettext === 0 )
	) {
		return null
	}
	const { totals, languages } = summary

	return (
		<BlockStack gap="300">
			<Text as="p">
				{ partial
					? `Imported before the stop: ${ totals.imported } texts, ${ totals.gettext } interface strings`
					: `${ totals.imported } texts imported` }
				{ totals.needs_review > 0 &&
					`, ${ totals.needs_review } to review` }
				{ status.finished_at &&
					` · ${ new Date( status.finished_at ).toLocaleString() }` }
			</Text>
			<BlockStack gap="100">
				{ languages.map( ( lang ) => (
					<LanguageLine key={ lang.locale } lang={ lang } />
				) ) }
			</BlockStack>
			<InlineStack>
				<Button url="admin.php?page=lingowp">
					Review on the board
				</Button>
			</InlineStack>
		</BlockStack>
	)
}

function LanguageLine( { lang, preview = false } ) {
	const parts = [
		`${ lang.imported } texts`,
		lang.needs_review > 0 && `${ lang.needs_review } to review`,
		lang.already > 0 && `${ lang.already } already translated (kept)`,
		lang.gettext > 0 && `${ lang.gettext } interface strings`,
	].filter( Boolean )

	// Not imported: worth a look on the board.
	const skipped = [
		lang.unmatched > 0 && `${ lang.unmatched } not found on this site`,
		lang.conflicts > 0 &&
			`${ lang.conflicts } with conflicting translations (oldest kept)`,
		lang.mismatch > 0 && `${ lang.mismatch } with different formatting`,
		lang.failed_checks > 0 &&
			`${ lang.failed_checks } failing LingoWP's checks (shortcodes, markup)`,
		lang.incomplete > 0 && `${ lang.incomplete } only partly translated`,
	].filter( Boolean )

	return (
		<InlineStack gap="200" blockAlign="center">
			<Text as="span" fontWeight="semibold">
				{ lang.code || lang.locale }
			</Text>
			{ lang.usable ? (
				<Text as="span" tone="subdued">
					{ parts.join( ' · ' ) }
				</Text>
			) : (
				<Text as="span" tone="caution">
					{ lang.language }
				</Text>
			) }
			{ lang.added && (
				<Badge tone="info">
					{ preview ? 'Language will be added' : 'Language added' }
				</Badge>
			) }
			{ lang.usable && skipped.length > 0 && (
				<Text as="span" variant="bodySm" tone="subdued">
					{ `Not imported: ${ skipped.join( ' · ' ) }` }
				</Text>
			) }
		</InlineStack>
	)
}
