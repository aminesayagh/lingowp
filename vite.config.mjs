import { defineConfig } from 'vite'

/**
 * Public language switcher bundle (assets/switcher/src -> assets/switcher/build).
 *
 * The admin board keeps its own wp-scripts/webpack build; this one only
 * produces the front-end switcher: one self-contained IIFE plus its CSS, with
 * fixed file names so PHP can register them without reading a manifest.
 */
export default defineConfig( {
	// Inline (empty) PostCSS config: stops Vite from picking up the admin's
	// postcss.config.js and running Tailwind over the switcher stylesheet.
	css: { postcss: {} },
	build: {
		outDir: 'assets/switcher/build',
		emptyOutDir: true,
		target: 'es2018',
		lib: {
			entry: 'assets/switcher/src/index.js',
			// Exposed as window.lingowpSwitcher — init() for builder previews
			// that insert switchers after page load.
			name: 'lingowpSwitcher',
			formats: [ 'iife' ],
			fileName: () => 'switcher.js',
			cssFileName: 'switcher',
		},
	},
} )
