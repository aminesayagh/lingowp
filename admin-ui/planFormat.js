// Plan keys are always {tier}_monthly or {tier}_yearly (see GET /billing/plans)
// — derive a client-facing label from the key itself rather than needing a
// lookup, so a new tier the SPA doesn't otherwise know about still renders
// sensibly. Anything that doesn't match this shape (a word-pack purchase id,
// for instance) rides through unchanged rather than being misparsed.
export function formatPlanKey( planKey ) {
	if ( ! planKey ) {
		return '—'
	}
	const match = planKey.match( /^(.+)_(monthly|yearly)$/ )
	if ( ! match ) {
		return planKey
	}
	const [ , tier, interval ] = match
	const label = tier.charAt( 0 ).toUpperCase() + tier.slice( 1 )
	return `${ label } · ${ interval === 'yearly' ? 'Yearly' : 'Monthly' }`
}
