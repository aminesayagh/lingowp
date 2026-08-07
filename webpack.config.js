const defaultConfig = require( '@wordpress/scripts/config/webpack.config' )

/**
 * wp-scripts' default performance budget (244 KiB) is calibrated for a
 * public, page-load-sensitive bundle. This is a wp-admin-only tool, and the
 * bulk of the size is Shopify Polaris' own CSS (~487 KiB) — not something to
 * "fix" by code-splitting an admin screen. Raised to a real budget instead of
 * silencing warnings outright, so genuine future bloat still gets flagged.
 */
const MAX_ASSET_SIZE = 600 * 1024
const MAX_ENTRYPOINT_SIZE = 1.6 * 1024 * 1024

function withRealisticPerformanceBudget( config ) {
	return {
		...config,
		performance: {
			...config.performance,
			maxAssetSize: MAX_ASSET_SIZE,
			maxEntrypointSize: MAX_ENTRYPOINT_SIZE,
		},
	}
}

module.exports = Array.isArray( defaultConfig )
	? defaultConfig.map( withRealisticPerformanceBudget )
	: withRealisticPerformanceBudget( defaultConfig )
