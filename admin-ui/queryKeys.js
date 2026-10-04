// The one place query keys are defined. Nothing else in admin-ui/ should write
// an inline key array — import `qk` and call the factory.
//
// Shape convention:
//
//     [ domain, resource, ...scopeIds (coarsest -> finest), paramsObj? ]
//
//  - The segments you invalidate BY come first: domain -> language/locale ->
//    collection/component id. So a mutation invalidates exactly the right slice
//    with a prefix match, never a positional predicate.
//  - Everything volatile (search term, status filter, the advanced-filter
//    object, pagination limit) goes into ONE trailing params object, so it can
//    never block a prefix match.
//  - Every domain exposes an `.all` prefix for blanket invalidation.
//
// Examples:
//   invalidate one collection's texts, every filter variant:
//     queryClient.invalidateQueries({ queryKey: qk.board.texts(lang, cid) })
//   invalidate the whole board for a language:
//     queryClient.invalidateQueries({ queryKey: [ 'board', 'texts', lang ] })
//   nuke everything board-related after a rescan:
//     queryClient.invalidateQueries({ queryKey: qk.board.all })

export const qk = {
	languages: {
		all: [ 'languages' ],
		list: () => [ 'languages', 'list' ], // GET /languages (with progress %)
		targets: () => [ 'languages', 'targets' ], // GET /languages/targets
		catalog: () => [ 'languages', 'catalog' ], // GET /languages/catalog
	},

	// The content board and the gettext board each spread across a few query
	// roots that Dashboard.jsx / GettextBoard.jsx invalidate with predicates on
	// queryKey[0] (they read filter state that would make positional-arg key
	// factories here more error-prone than helpful), so those keep their inline
	// literal keys at the useQuery call site. What lives here is only the
	// cross-cutting "match every board query" predicate, used by mutations in
	// other modules (e.g. a language add/delete) that need the whole board to
	// refetch.
	board: {
		// ['collections', …] | ['texts', …] | ['text-order', …] | ['html-stale', …]
		matchAll: ( q ) =>
			[ 'collections', 'texts', 'text-order', 'html-stale' ].includes(
				q.queryKey[ 0 ]
			),
	},

	gettext: {
		// ['gettext-domains', …] | ['gettext-units', …]
		matchAll: ( q ) =>
			[ 'gettext-domains', 'gettext-units' ].includes( q.queryKey[ 0 ] ),
	},

	billing: {
		all: [ 'billing' ],
		entitlements: () => [ 'billing', 'entitlements' ],
		usage: () => [ 'billing', 'usage' ],
		plans: () => [ 'billing', 'plans' ],
		packs: () => [ 'billing', 'packs' ],
		transactions: ( page ) =>
			page === undefined
				? [ 'billing', 'transactions' ]
				: [ 'billing', 'transactions', page ],
		addons: ( lang ) =>
			lang === undefined
				? [ 'billing', 'addons' ]
				: [ 'billing', 'addons', lang ],
		owner: () => [ 'billing', 'owner' ],
		websites: () => [ 'billing', 'websites' ],
	},

	connection: {
		all: [ 'connection' ],
		status: () => [ 'connection', 'status' ],
	},
	// Deliberately outside connection.all: a successful connect invalidates
	// that family, and re-pinging the backend right after is pointless.
	backendPing: () => [ 'backend-ping' ],

	providerKeys: () => [ 'provider-keys' ],

	onboarding: {
		state: () => [ 'onboarding', 'state' ],
	},

	scan: {
		status: () => [ 'scan', 'status' ],
	},

	// Language switcher design (Account page, "Language switcher" tab).
	switcher: {
		all: [ 'switcher' ],
		settings: () => [ 'switcher', 'settings' ], // GET /switcher-settings
		// POST /switcher-settings/preview — keyed by the draft + layout, so an
		// unchanged draft reuses the last render.
		preview: ( params ) => [ 'switcher', 'preview', params ],
	},
}
