import {
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

// Account page, "Migration" tab: one card, one status line, two buttons —
// import the translations, then disable the old plugin. Shown only when a
// migration add-on reports something to migrate from (window.LingoWP.migration).
export default function MigrationTab() {
	const { source, label } = cfg.migration
	const statusQ = useMigrationStatus( source )
	const status = statusQ.data
	const state = status?.state ?? 'idle'
	const busy = state === 'queued' || state === 'running'
	const done = state === 'done'

	// The preview counts what's left to import; after the import, the report does.
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
			<Card>
				<Text as="p" tone="critical">
					{ errorMessage( statusQ.error.code ) }
				</Text>
			</Card>
		)
	}

	const failed = state === 'failed'
	// Done or stopped: what the run recorded. Before a run: the preview —
	// whose "blocked" reason no longer applies once a run has succeeded.
	const recorded = done || failed ? status.summary : null
	const blocked = done ? null : previewQ.data?.blocked
	const previewFailed = ! done && ! busy && previewQ.isError
	const error =
		( run.isError && errorMessage( run.error.code ) ) ||
		( deactivate.isError && errorMessage( deactivate.error.code ) ) ||
		( previewFailed && errorMessage( previewQ.error.code ) ) ||
		( failed && ( status.error || 'Migration stopped.' ) )

	return (
		<Card>
			<BlockStack gap="300">
				<BlockStack gap="100">
					<Text as="h2" variant="headingMd">
						{ `Migrate from ${ label }` }
					</Text>
					<Text as="p" tone="subdued">
						{ `Bring your ${ label } translations into Lingostack, then disable ${ label } so only Lingostack translates your site. Its data is kept.` }
					</Text>
				</BlockStack>

				{ busy ? (
					<Text as="p" tone="subdued">
						Importing your translations… you can leave this page.
					</Text>
				) : (
					<Outcome
						state={ state }
						summary={
							recorded && ! ( failed && isEmpty( recorded ) )
								? recorded
								: previewQ.data?.summary
						}
						recorded={
							!! recorded && ! ( failed && isEmpty( recorded ) )
						}
						blocked={ blocked }
						counting={ ! recorded && previewQ.isPending }
					/>
				) }

				{ error && (
					<Text as="p" tone="critical">
						{ error }
					</Text>
				) }

				<InlineStack gap="200">
					{ done && (
						<Button url="admin.php?page=lingowp">Review</Button>
					) }
					{ ! done && previewFailed && (
						<Button onClick={ () => previewQ.refetch() }>
							Retry
						</Button>
					) }
					{ ! done && ! previewFailed && (
						<Button
							variant="primary"
							loading={ busy || run.isPending }
							disabled={ !! blocked || ! previewQ.isSuccess }
							onClick={ () => run.mutate() }
						>
							{ failed ? 'Try again' : 'Migrate now' }
						</Button>
					) }
					<Button
						onClick={ () => deactivate.mutate() }
						loading={ deactivate.isPending }
						disabled={ ! status.source?.active }
					>
						{ `Disable ${ label }` }
					</Button>
				</InlineStack>
			</BlockStack>
		</Card>
	)
}

function isEmpty( summary ) {
	return summary.totals.imported === 0 && summary.totals.gettext === 0
}

const translations = ( n ) =>
	n === 1 ? '1 translation' : `${ n } translations`

// What can be imported, what was (done, or before a stop), or why not — the
// main line, then one short line for what wasn't imported, only when there is.
function Outcome( { state, summary, recorded, blocked, counting } ) {
	if ( blocked ) {
		return (
			<Text as="p" tone="caution">
				{ blocked }
			</Text>
		)
	}
	if ( counting || ! summary ) {
		return (
			<Text as="p" tone="subdued">
				{ counting ? 'Counting your translations…' : '' }
			</Text>
		)
	}

	const { totals, languages } = summary
	// Interface strings (plugins, themes) count as translations too.
	const count = totals.imported + totals.gettext
	const review =
		totals.needs_review > 0
			? ` · ${ totals.needs_review } need a review on the board`
			: ''
	let main = `${ translations( count ) } ready to import${ review }`
	if ( recorded && state === 'done' ) {
		main = `Done: ${ translations( count ) } imported${ review }`
	} else if ( recorded ) {
		main = `Imported before the stop: ${ translations( count ) }${ review }`
	}

	// Per language only when there is more than one, or one gets added.
	const perLanguage =
		languages.length > 1 || languages.some( ( l ) => l.added )
			? languages
					.map(
						( l ) =>
							`${ l.code || l.locale } ${
								l.imported + l.gettext
							}${ l.added ? ' (new)' : '' }`
					)
					.join( ' · ' )
			: ''

	const notImported = [
		totals.unmatched > 0 && `${ totals.unmatched } not found on this site`,
		totals.conflicts > 0 &&
			`${ totals.conflicts } with conflicting translations`,
		totals.mismatch > 0 && `${ totals.mismatch } with different formatting`,
		totals.failed_checks > 0 &&
			`${ totals.failed_checks } failing Lingostack's checks`,
		totals.incomplete > 0 &&
			`${ totals.incomplete } only partly translated`,
		...languages
			.filter( ( l ) => ! l.usable )
			.map( ( l ) => `${ l.code || l.locale }: ${ l.language }` ),
	].filter( Boolean )

	return (
		<BlockStack gap="100">
			<Text as="p" tone="subdued">
				{ perLanguage ? `${ main } — ${ perLanguage }` : main }
			</Text>
			{ notImported.length > 0 && (
				<Text as="p" variant="bodySm" tone="subdued">
					{ `Not imported: ${ notImported.join( ' · ' ) }` }
				</Text>
			) }
		</BlockStack>
	)
}
