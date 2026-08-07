const wordpressConfig = require( '@wordpress/scripts/config/.eslintrc' )

module.exports = {
	...wordpressConfig,
	globals: {
		...wordpressConfig.globals,
		navigator: 'readonly',
		NodeFilter: 'readonly',
		ResizeObserver: 'readonly',
	},
	rules: {
		...wordpressConfig.rules,
		semi: [ 'error', 'never' ],
		'no-unused-vars': 'error',
	},
}
