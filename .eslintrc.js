const wordpressConfig = require( '@wordpress/scripts/config/.eslintrc' )

// Every REST call must go through a React Query hook (or the shared fetch
// helpers) in admin-ui/queries/**. The raw client, admin-ui/api.js, is only
// allowed to be imported from there — this rule fails the build on a stray
// `import { api } from '.../api'` anywhere else.
const noRawApiImport = {
	'no-restricted-imports': [
		'error',
		{
			patterns: [
				{
					group: [ '**/api', '**/api.js', '../api', './api' ],
					message:
						'Do not import the raw REST client. Add or reuse a hook in admin-ui/queries/** instead.',
				},
			],
		},
	],
}

module.exports = {
	...wordpressConfig,
	globals: {
		...wordpressConfig.globals,
		getComputedStyle: 'readonly',
		navigator: 'readonly',
		NodeFilter: 'readonly',
		ResizeObserver: 'readonly',
		sessionStorage: 'readonly',
	},
	rules: {
		...wordpressConfig.rules,
		semi: [ 'error', 'never' ],
		'no-unused-vars': 'error',
		...noRawApiImport,
	},
	overrides: [
		{
			// The one place the raw client is allowed.
			files: [ 'admin-ui/queries/**' ],
			rules: { 'no-restricted-imports': 'off' },
		},
	],
}
