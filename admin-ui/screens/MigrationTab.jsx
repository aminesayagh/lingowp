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

	const blocked = previewQ.data?.blocked
	const error =
		( run.isError && errorMessage( run.error.code ) ) ||
		( deactivate.isError && errorMessage( deactivate.error.code ) ) ||
		( state === 'failed' && ( status.error || 'Migration stopped.' ) )

	return (
		<Card>
			<BlockStack gap="300">
				<BlockStack gap="100">
					<Text as="h2" variant="headingMd">
						{ `Migrate from ${ label }` }
					</Text>
					<Text as="p" tone="subdued">
						{ `Bring your ${ label } translations into LingoWP, then disable ${ label } so only LingoWP translates your site. Its data is kept.` }
					</Text>
				</BlockStack>

				<StatusLine
					state={ state }
					summary={ done ? status.summary : previewQ.data?.summary }
					counting={ ! done && ! busy && previewQ.isPending }
					blocked={ blocked }
				/>

				{ error && (
					<Text as="p" tone="critical">
						{ error }
					</Text>
				) }

				<InlineStack gap="200">
					{ done ? (
						<Button url="admin.php?page=lingowp">Review</Button>
					) : (
						<Button
							variant="primary"
							loading={ busy || run.isPending }
							disabled={ !! blocked || previewQ.isPending }
							onClick={ () => run.mutate() }
						>
							{ state === 'failed' ? 'Try again' : 'Migrate now' }
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

// One short line: what can be imported, what was, or why not.
function StatusLine( { state, summary, counting, blocked } ) {
	if ( state === 'queued' || state === 'running' ) {
		return (
			<Text as="p" tone="subdued">
				Importing your translations… you can leave this page.
			</Text>
		)
	}
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
				Counting your translations…
			</Text>
		)
	}

	const { totals, languages } = summary
	const review =
		totals.needs_review > 0
			? ` · ${ totals.needs_review } need a review on the board`
			: ''
	const texts = ( n ) => ( n === 1 ? '1 translation' : `${ n } translations` )
	const main =
		state === 'done'
			? `Done: ${ texts( totals.imported ) } imported${ review }`
			: `${ texts( totals.imported ) } ready to import${ review }`

	// Per language only when there is more than one, or one gets added.
	const perLanguage =
		languages.length > 1 || languages.some( ( l ) => l.added )
			? languages
					.map(
						( l ) =>
							`${ l.code || l.locale } ${ l.imported }${
								l.added ? ' (new)' : ''
							}`
					)
					.join( ' · ' )
			: ''

	return (
		<Text as="p" tone="subdued">
			{ perLanguage ? `${ main } — ${ perLanguage }` : main }
		</Text>
	)
}
