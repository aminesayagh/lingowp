const defaultConfig = require( '@wordpress/scripts/config/webpack.config' )

/**
 * wp-scripts' default performance budget (244 KiB) is calibrated for a
 * public, page-load-sensitive bundle. This is a wp-admin-only tool, and the
 * bulk of the size is Shopify Polaris' own CSS (~503 KiB) — not something to
 * "fix" by code-splitting an admin screen. Raised to a real budget instead of
 * silencing warnings outright, so genuine future bloat still gets flagged.
 *
 * `index-rtl.css` is excluded from that budget below (assetFilter), not
 * raised for. It's a near-duplicate of index.css that RtlCssPlugin emits
 * alongside every build; OnboardingPageController wires
 * wp_style_add_data($handle, 'rtl', 'replace') so WordPress swaps it in only
 * for RTL-locale admins — no admin ever downloads both. Without the filter,
 * webpack sums it into the entrypoint anyway and reports a real bundle
 * roughly 2x its actual weight for anyone actually loading the page.
 */
const MAX_ASSET_SIZE = 768 * 1024
const MAX_ENTRYPOINT_SIZE = 1.25 * 1024 * 1024

function withRealisticPerformanceBudget( config ) {
	return {
		...config,
		performance: {
			...config.performance,
			maxAssetSize: MAX_ASSET_SIZE,
			maxEntrypointSize: MAX_ENTRYPOINT_SIZE,
			assetFilter: ( assetFilename ) =>
				! assetFilename.endsWith( '-rtl.css' ),
		},
	}
}

module.exports = Array.isArray( defaultConfig )
	? defaultConfig.map( withRealisticPerformanceBudget )
	: withRealisticPerformanceBudget( defaultConfig )
