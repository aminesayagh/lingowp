// Pure list logic for the Integrations tab and the add-on toasts. Kept out of
// the .jsx so tests/addon_lists_check.mjs can run it under plain node.
//
// Every addon carries: `relevant` (a plugin/theme it targets is active on
// this site), `included` (the licence tier covers it), `installed` (the
// lingowp-<product> plugin is active here), `tier`, `detects`.

// Three lists, no overlap. Add-ons that target nothing on this site are
// dropped — a site without WooCommerce has no reason to see that card.
export function partitionAddons( addons ) {
	const installed = []
	const ready = []
	const upgrade = []
	for ( const addon of addons ) {
		if ( addon.installed ) {
			installed.push( addon )
		} else if ( ! addon.relevant ) {
			continue
		} else if ( addon.included ) {
			ready.push( addon )
		} else {
			upgrade.push( addon )
		}
	}
	return { installed, ready, upgrade }
}

// One toast per add-on whose `detects` list hits something just activated
// and that isn't installed yet. `kind` picks the wording and the target tab.
export function activationToasts( addons, recentlyActivated ) {
	if ( ! recentlyActivated?.length ) {
		return []
	}
	const recent = new Set( recentlyActivated )
	return addons
		.filter(
			( a ) =>
				! a.installed &&
				( a.detects || [] ).some( ( d ) => recent.has( d ) )
		)
		.map( ( addon ) => ( {
			addon,
			kind: addon.included ? 'install' : 'upgrade',
		} ) )
}

// After a subscription activates: everything the new plan covers that this
// site could use right now.
export function subscriptionToasts( addons ) {
	return partitionAddons( addons ).ready
}

export function tierLabel( tier ) {
	return tier ? tier.charAt( 0 ).toUpperCase() + tier.slice( 1 ) : ''
}
