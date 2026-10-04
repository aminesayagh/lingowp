import {
	Banner,
	BlockStack,
	Box,
	Button,
	ButtonGroup,
	Card,
	Checkbox,
	ColorPicker,
	InlineGrid,
	InlineStack,
	Popover,
	Select,
	Spinner,
	Text,
	TextField,
	hexToRgb,
	hsbToHex,
	hsbToRgb,
	rgbString,
	rgbToHsb,
} from '@shopify/polaris'
import { useEffect, useMemo, useRef, useState } from '@wordpress/element'
import {
	useSaveSwitcherSettings,
	useSwitcherPreview,
	useSwitcherSettings,
} from '../queries/switcher'
import { errorMessage } from '../errors'

// Account page -> "Language switcher" tab: the site-wide design of the
// front-end language switcher (SwitcherSettings on the server). The form edits
// a local draft; Save stores it, Revert returns to the last saved settings.
// The preview renders the REAL switcher (PHP template, switcher.css/js) with
// the unsaved draft, in a sandboxed iframe so admin styles and custom CSS
// never mix with it.

const COLOR_KEYS = [
	[ 'bg_color', 'Background' ],
	[ 'bg_hover_color', 'Background on hover' ],
	[ 'text_color', 'Text' ],
	[ 'text_hover_color', 'Text on hover' ],
	[ 'border_color', 'Border' ],
]

// [key, label, placeholder (= the stylesheet's default), help]
const SHAPE_KEYS = [
	[ 'border_width', 'Border width', '0', 'Up to 20px.' ],
	[
		'border_radius',
		'Button corners',
		'0',
		'The dropdown button, or the whole list.',
	],
	[ 'dropdown_radius', 'Open list corners', '4px', '' ],
	[
		'flag_radius',
		'Flag corners',
		'2px',
		'Above 8px the flags become square (50% = round).',
	],
]

const SPACING_KEYS = [
	[ 'button_gap', 'Flag to text, button', '10px', '' ],
	[ 'item_gap', 'Flag to text, list items', '10px', '' ],
]

const BOOL_KEYS = [
	'show_arrow',
	'dropdown_animation',
	'floating_enabled',
	'custom_css_enabled',
]
const ENUM_KEYS = [
	'size',
	'dropdown_shadow',
	'button_width',
	'floating_position',
]
const DESIGN_KEYS = [
	...COLOR_KEYS.map( ( [ key ] ) => key ),
	...SHAPE_KEYS.map( ( [ key ] ) => key ),
	...SPACING_KEYS.map( ( [ key ] ) => key ),
]

// Appearance only: a preset never touches spacing, behavior or custom CSS.
const PRESET_KEYS = [
	'bg_color',
	'bg_hover_color',
	'text_color',
	'text_hover_color',
	'border_color',
	'border_width',
	'border_radius',
	'dropdown_radius',
]
const PRESETS = {
	default: {
		label: 'Default',
		values: {},
	},
	dark: {
		label: 'Dark',
		values: {
			bg_color: '#1f2937',
			bg_hover_color: '#374151',
			text_color: '#f9fafb',
			text_hover_color: '#ffffff',
			border_radius: '8px',
			dropdown_radius: '8px',
		},
	},
	border: {
		label: 'Border',
		values: {
			bg_hover_color: '#f3f4f6',
			border_color: '#d1d5db',
			border_width: '1px',
			border_radius: '6px',
			dropdown_radius: '6px',
		},
	},
	transparent: {
		label: 'Transparent',
		values: {
			bg_color: 'transparent',
			bg_hover_color: 'rgba(0, 0, 0, 0.05)',
			border_radius: '6px',
			dropdown_radius: '6px',
		},
	},
}

// The editable part of a settings response, in the shape POST accepts.
function toDraft( settings ) {
	const draft = {}
	for ( const key of [
		...DESIGN_KEYS,
		...ENUM_KEYS,
		'floating_offset',
		'custom_css',
	] ) {
		draft[ key ] = settings?.[ key ] ?? ''
	}
	for ( const key of BOOL_KEYS ) {
		draft[ key ] = !! settings?.[ key ]
	}
	return draft
}

// What to send: never the custom CSS for someone who may not edit it.
function toBody( draft, canEditCss ) {
	const body = { ...draft }
	if ( ! canEditCss ) {
		delete body.custom_css
		delete body.custom_css_enabled
	}
	return body
}

// ── Color field ────────────────────────────────────────────────────────────

// "#abc", "#aabbcc", "#aabbccdd", "rgb(…)", "rgba(…)" -> Polaris HSBA.
function toHsba( value ) {
	const v = ( value || '' ).trim().toLowerCase()
	let rgb = null
	let alpha = 1
	let m = v.match( /^#([0-9a-f]{3}|[0-9a-f]{6})([0-9a-f]{2})?$/ )
	if ( m ) {
		rgb = hexToRgb( '#' + m[ 1 ] )
		alpha = m[ 2 ] ? parseInt( m[ 2 ], 16 ) / 255 : 1
	}
	m = v.match(
		/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+))?\s*\)$/
	)
	if ( m ) {
		rgb = { red: +m[ 1 ], green: +m[ 2 ], blue: +m[ 3 ] }
		alpha = m[ 4 ] !== undefined ? +m[ 4 ] : 1
	}
	return rgb
		? { ...rgbToHsb( rgb ), alpha }
		: { hue: 0, saturation: 0, brightness: 0, alpha: 1 }
}

// Polaris HSBA -> "#rrggbb", or "rgba(…)" once it has transparency.
function fromHsba( color ) {
	return color.alpha < 1 ? rgbString( hsbToRgb( color ) ) : hsbToHex( color )
}

// A checkerboard under the color, so transparency is visible.
function Swatch( { value } ) {
	return (
		<span
			aria-hidden="true"
			style={ {
				display: 'inline-block',
				width: 18,
				height: 18,
				borderRadius: 4,
				border: '1px solid rgba(0,0,0,0.2)',
				background: `linear-gradient(${ value || 'transparent' }, ${
					value || 'transparent'
				}), repeating-conic-gradient(#ddd 0 25%, #fff 0 50%) 0 0 / 8px 8px`,
			} }
		/>
	)
}

function ColorField( { label, value, onChange, error } ) {
	const [ open, setOpen ] = useState( false )

	// Label, then the Pick button (swatch inside) and the value as plain
	// text — no input: colors are chosen in the picker, cleared with
	// "Use default".
	return (
		<BlockStack gap="100">
			<Text as="span">{ label }</Text>
			<InlineStack gap="200" blockAlign="center" wrap={ false }>
				<Popover
					active={ open }
					onClose={ () => setOpen( false ) }
					preferredAlignment="left"
					activator={
						// A bare native button: swatch and "Pick" centered on
						// one line, in the text color — no link blue, no
						// underline, no padding/border/shadow. Keyboard focus
						// keeps the browser's ring.
						<button
							type="button"
							onClick={ () => setOpen( ( o ) => ! o ) }
							aria-label={ `Pick ${ label.toLowerCase() }, current: ${
								value || 'default'
							}` }
							style={ {
								display: 'inline-flex',
								alignItems: 'center',
								gap: 6,
								margin: 0,
								padding: 0,
								border: 0,
								background: 'none',
								font: 'inherit',
								color: 'inherit',
								cursor: 'pointer',
							} }
						>
							<Swatch value={ value } />
							<span>Pick</span>
							{ /* In the same row as the swatch, so all three
							     are centered together. */ }
							<span
								style={ {
									marginInlineStart: 6,
									color: value
										? 'inherit'
										: 'var(--p-color-text-secondary, #616161)',
								} }
							>
								{ value || 'Default' }
							</span>
						</button>
					}
				>
					<Box padding="300">
						<BlockStack gap="200">
							<ColorPicker
								color={ toHsba( value ) }
								onChange={ ( color ) =>
									onChange( fromHsba( color ) )
								}
								allowAlpha
							/>
							<InlineStack align="space-between">
								<Button
									variant="plain"
									onClick={ () => onChange( '' ) }
								>
									Use default
								</Button>
								<Button onClick={ () => setOpen( false ) }>
									Done
								</Button>
							</InlineStack>
						</BlockStack>
					</Box>
				</Popover>
			</InlineStack>
			{ error && (
				<Text as="p" tone="critical" variant="bodySm">
					{ error }
				</Text>
			) }
		</BlockStack>
	)
}

// ── Custom CSS editor ──────────────────────────────────────────────────────

const cfg = window.LingoWP || {}

// WordPress's own CodeMirror CSS editor (wp.codeEditor, as in Additional CSS):
// highlighting, line numbers, bracket matching, CSS linting. Its settings come
// from wp_enqueue_code_editor() via window.LingoWP.css_editor; false when the
// user turned syntax highlighting off — then a plain text box.
function CssEditor( { value, onChange } ) {
	const textareaRef = useRef( null )
	const editorRef = useRef( null )
	const onChangeRef = useRef( onChange )
	onChangeRef.current = onChange

	const settings = cfg.css_editor
	const available = !! ( settings && window.wp?.codeEditor )

	useEffect( () => {
		if ( ! available || ! textareaRef.current ) {
			return undefined
		}
		const editor = window.wp.codeEditor.initialize(
			textareaRef.current,
			settings
		)
		const cm = editor.codemirror
		cm.on( 'change', () => onChangeRef.current( cm.getValue() ) )
		editorRef.current = cm
		return () => {
			cm.toTextArea()
			editorRef.current = null
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ available ] )

	// Follow outside changes (Revert) without disturbing typing.
	useEffect( () => {
		const cm = editorRef.current
		if ( cm && cm.getValue() !== value ) {
			cm.setValue( value )
		}
	}, [ value ] )

	if ( ! available ) {
		return (
			<TextField
				label="CSS"
				labelHidden
				value={ value }
				onChange={ onChange }
				multiline={ 8 }
				monospaced
				autoComplete="off"
			/>
		)
	}

	return (
		<div
			style={ {
				border: '1px solid #c9cccf',
				borderRadius: 8,
				overflow: 'hidden',
			} }
		>
			<textarea
				ref={ textareaRef }
				defaultValue={ value }
				aria-label="Custom CSS"
			/>
		</div>
	)
}

// ── Preview ────────────────────────────────────────────────────────────────

function PreviewFrame( { preview } ) {
	const frameRef = useRef( null )
	const [ ready, setReady ] = useState( false )

	// The frame's page is built ONCE (per asset version) and its content is
	// then swapped in place via postMessage — rebuilding srcDoc on every change
	// reloaded the whole frame (blank, restyle, re-run): a visible flicker.
	// The page fills the frame exactly (no scrollbar) and centers the
	// switcher; the open list floats (position: fixed) and Floating UI keeps it
	// inside. A full-width button keeps the whole width.
	const css = preview?.assets?.css || ''
	const js = preview?.assets?.js || ''
	const srcDoc = useMemo( () => {
		if ( ! css ) {
			return ''
		}
		return `<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="${ css }">
<style>html,body{height:100%;margin:0;overflow:hidden}body{box-sizing:border-box;display:flex;align-items:center;justify-content:center;padding:24px;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2937;background:#fff}#lp-root{display:contents}.lingowp-switcher--full{width:100%}</style>
</head><body><div id="lp-css" hidden></div><div id="lp-root"></div><div id="lp-floating"></div>
<script src="${ js }"></script>
<script>
document.addEventListener('click',function(e){if(e.target.closest('a')){e.preventDefault()}},true);
window.addEventListener('message',function(e){
	if(e.source!==window.parent||!e.data||e.data.type!=='lingowp-preview'){return}
	document.getElementById('lp-css').innerHTML=e.data.css;
	var root=document.getElementById('lp-root');
	root.innerHTML=e.data.html;
	var floating=document.getElementById('lp-floating');
	floating.innerHTML=e.data.floating||'';
	if(window.lingowpSwitcher){window.lingowpSwitcher.init(root);window.lingowpSwitcher.init(floating)}
});
window.parent.postMessage({type:'lingowp-preview-ready'},'*');
</script></body></html>`
	}, [ css, js ] )

	// A (re)built page announces itself once its script runs.
	useEffect( () => {
		setReady( false )
		const onMessage = ( e ) => {
			if (
				e.source === frameRef.current?.contentWindow &&
				e.data?.type === 'lingowp-preview-ready'
			) {
				setReady( true )
			}
		}
		window.addEventListener( 'message', onMessage )
		return () => window.removeEventListener( 'message', onMessage )
	}, [ srcDoc ] )

	// Send each new render into the loaded page. The sandbox has no origin,
	// so the preference cookie cannot be written there: drop the attribute
	// instead of erroring.
	useEffect( () => {
		if ( ! ready || ! preview?.html ) {
			return
		}
		frameRef.current?.contentWindow?.postMessage(
			{
				type: 'lingowp-preview',
				html: preview.html.replace( /\sdata-cookie="[^"]*"/g, '' ),
				css: preview.head_css || '',
				// The floating switcher, when the draft enables it: fixed in
				// its corner of the frame.
				floating: ( preview.floating_html || '' ).replace(
					/\sdata-cookie="[^"]*"/g,
					''
				),
			},
			'*'
		)
	}, [ ready, preview ] )

	return (
		<iframe
			ref={ frameRef }
			title="Language switcher preview"
			srcDoc={ srcDoc }
			sandbox="allow-scripts"
			style={ {
				width: '100%',
				height: 340,
				border: '1px solid var(--p-color-border, #e3e3e3)',
				borderRadius: 8,
				background: '#fff',
			} }
		/>
	)
}

// ── Screen ─────────────────────────────────────────────────────────────────

export default function SwitcherSettings() {
	const settingsQuery = useSwitcherSettings()
	const save = useSaveSwitcherSettings()
	const saved = settingsQuery.data
	const canEditCss = !! saved?.can_edit_css

	const [ draft, setDraft ] = useState( null )
	const [ debouncedDraft, setDebouncedDraft ] = useState( null )

	// Start from the saved settings, and follow them again after a save — but
	// never overwrite edits in progress with a background refetch.
	const savedDraft = useMemo(
		() => ( saved ? toDraft( saved ) : null ),
		[ saved ]
	)
	const dirty =
		draft !== null &&
		savedDraft !== null &&
		JSON.stringify( draft ) !== JSON.stringify( savedDraft )
	useEffect( () => {
		if ( savedDraft && ( draft === null || ! dirty ) ) {
			setDraft( savedDraft )
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ savedDraft ] )

	// Re-render the preview ~0.3s after the last change.
	useEffect( () => {
		const t = setTimeout( () => setDebouncedDraft( draft ), 300 )
		return () => clearTimeout( t )
	}, [ draft ] )

	const previewQuery = useSwitcherPreview(
		debouncedDraft ? toBody( debouncedDraft, canEditCss ) : null,
		'dropdown',
		{ enabled: debouncedDraft !== null }
	)

	if ( settingsQuery.isError ) {
		return (
			<Banner tone="critical">
				{ errorMessage( settingsQuery.error?.code ) }
			</Banner>
		)
	}
	if ( ! draft ) {
		return (
			<Box padding="800">
				<InlineStack align="center">
					<Spinner accessibilityLabel="Loading" size="large" />
				</InlineStack>
			</Box>
		)
	}

	const set = ( key ) => ( value ) =>
		setDraft( ( d ) => ( { ...d, [ key ]: value } ) )

	// Errors: what the last save rejected, plus what the preview flags live.
	const invalid = new Set( [
		...( save.error?.fields || [] ),
		...( previewQuery.data?.invalid || [] ),
	] )
	const fieldError = ( key ) =>
		invalid.has( key )
			? 'Not a valid value — it will be ignored.'
			: undefined

	// The preset whose appearance values the draft matches exactly — shown
	// as active; none once any of them is edited (a custom look).
	const activePreset = Object.keys( PRESETS ).find( ( id ) =>
		PRESET_KEYS.every(
			( key ) => ( PRESETS[ id ].values[ key ] ?? '' ) === draft[ key ]
		)
	)

	const applyPreset = ( id ) =>
		setDraft( ( d ) => {
			const next = { ...d }
			for ( const key of PRESET_KEYS ) {
				next[ key ] = PRESETS[ id ].values[ key ] ?? ''
			}
			return next
		} )

	const lengthField = ( [ key, label, placeholder, help ] ) => (
		<TextField
			key={ key }
			label={ label }
			value={ draft[ key ] }
			onChange={ set( key ) }
			placeholder={ `Default (${ placeholder })` }
			helpText={ help || undefined }
			autoComplete="off"
			error={ fieldError( key ) }
		/>
	)

	return (
		<BlockStack gap="400">
			<BlockStack gap="100">
				<Text as="h2" variant="headingMd">
					Language switcher
				</Text>
				<Text as="p" tone="subdued">
					The look of every language switcher on the site. A shortcode
					can still override any of these for one switcher.
				</Text>
			</BlockStack>

			<div
				style={ {
					display: 'flex',
					flexWrap: 'wrap',
					gap: 16,
					alignItems: 'flex-start',
				} }
			>
				{ /* ── Settings ──────────────────────────────────────── */ }
				<div style={ { flex: '1 1 420px', minWidth: 0 } }>
					<BlockStack gap="400">
						<Card>
							<BlockStack gap="300">
								<Text as="h3" variant="headingSm">
									Presets
								</Text>
								<Text as="p" tone="subdued">
									A starting look for colors, border and
									corners — adjust anything afterwards.
								</Text>
								<ButtonGroup>
									{ Object.entries( PRESETS ).map(
										( [ id, preset ] ) => (
											<Button
												key={ id }
												pressed={ activePreset === id }
												variant={
													// Filled, like the active tab in
													// the Account sidebar.
													activePreset === id
														? 'primary'
														: undefined
												}
												onClick={ () =>
													applyPreset( id )
												}
											>
												{ preset.label }
											</Button>
										)
									) }
								</ButtonGroup>
							</BlockStack>
						</Card>

						<Card>
							<BlockStack gap="300">
								<Text as="h3" variant="headingSm">
									Colors
								</Text>
								{ COLOR_KEYS.map( ( [ key, label ] ) => (
									<ColorField
										key={ key }
										label={ label }
										value={ draft[ key ] }
										onChange={ set( key ) }
										error={ fieldError( key ) }
									/>
								) ) }
							</BlockStack>
						</Card>

						<Card>
							<BlockStack gap="300">
								<Text as="h3" variant="headingSm">
									Border and corners
								</Text>
								{ /* Two per line; one on narrow screens. */ }
								<InlineGrid
									columns={ { xs: 1, sm: 2 } }
									gap="300"
								>
									{ SHAPE_KEYS.map( lengthField ) }
								</InlineGrid>
							</BlockStack>
						</Card>

						<Card>
							<BlockStack gap="300">
								<Text as="h3" variant="headingSm">
									Spacing
								</Text>
								<Select
									label="Padding size"
									helpText="Inside the button and the language items, once the switcher has a border, a background or a hover background."
									options={ [
										{ label: 'Small', value: 'sm' },
										{ label: 'Medium', value: 'md' },
										{ label: 'Large', value: 'lg' },
									] }
									value={ draft.size }
									onChange={ set( 'size' ) }
								/>
								{ SPACING_KEYS.map( lengthField ) }
							</BlockStack>
						</Card>

						<Card>
							<BlockStack gap="300">
								<Text as="h3" variant="headingSm">
									Dropdown
								</Text>
								<Checkbox
									label="Show the arrow"
									checked={ draft.show_arrow }
									onChange={ set( 'show_arrow' ) }
								/>
								<Checkbox
									label="Animate opening"
									helpText="Never for visitors who ask their device for reduced motion."
									checked={ draft.dropdown_animation }
									onChange={ set( 'dropdown_animation' ) }
								/>
								<Select
									label="Shadow of the open list"
									options={ [
										{ label: 'None', value: 'none' },
										{ label: 'Small', value: 'sm' },
										{ label: 'Medium', value: 'md' },
										{ label: 'Large', value: 'lg' },
									] }
									value={ draft.dropdown_shadow }
									onChange={ set( 'dropdown_shadow' ) }
								/>
								<Select
									label="Button width"
									helpText="Full width needs a border, a background or a hover background to show."
									options={ [
										{
											label: 'Fit the content',
											value: 'fit',
										},
										{ label: 'Full width', value: 'full' },
									] }
									value={ draft.button_width }
									onChange={ set( 'button_width' ) }
								/>
							</BlockStack>
						</Card>

						<Card>
							<BlockStack gap="300">
								<Text as="h3" variant="headingSm">
									Floating switcher
								</Text>
								<Text as="p" tone="subdued">
									A dropdown fixed in a bottom corner of every
									page, with the same design. It opens upward.
								</Text>
								<Checkbox
									label="Show a floating switcher"
									checked={ draft.floating_enabled }
									onChange={ set( 'floating_enabled' ) }
								/>
								{ draft.floating_enabled && (
									<InlineGrid
										columns={ { xs: 1, sm: 2 } }
										gap="300"
									>
										<Select
											label="Corner"
											options={ [
												{
													label: 'Bottom right',
													value: 'bottom-right',
												},
												{
													label: 'Bottom left',
													value: 'bottom-left',
												},
											] }
											value={ draft.floating_position }
											onChange={ set(
												'floating_position'
											) }
										/>
										<TextField
											label="Distance from the edges"
											value={ draft.floating_offset }
											onChange={ set(
												'floating_offset'
											) }
											placeholder="Default (20px)"
											helpText="Move it away from a chat bubble or cookie banner. Up to 100px."
											autoComplete="off"
											error={ fieldError(
												'floating_offset'
											) }
										/>
									</InlineGrid>
								) }
							</BlockStack>
						</Card>

						{ canEditCss && (
							<Card>
								<BlockStack gap="300">
									<Text as="h3" variant="headingSm">
										Custom CSS
									</Text>
									<Checkbox
										label="Add custom CSS"
										checked={ draft.custom_css_enabled }
										onChange={ set( 'custom_css_enabled' ) }
									/>
									{ draft.custom_css_enabled && (
										<BlockStack gap="100">
											<CssEditor
												value={ draft.custom_css }
												onChange={ set( 'custom_css' ) }
											/>
											<Text
												as="p"
												tone="subdued"
												variant="bodySm"
											>
												Printed on every page. Start
												your rules with
												.lingowp-switcher so they only
												reach the switcher.
											</Text>
										</BlockStack>
									) }
								</BlockStack>
							</Card>
						) }
					</BlockStack>
				</div>

				{ /* ── Preview ───────────────────────────────────────── */ }
				<div
					style={ {
						flex: '1 1 360px',
						minWidth: 0,
						position: 'sticky',
						top: 48,
					} }
				>
					<BlockStack gap="300">
						<Card>
							<BlockStack gap="300">
								<InlineStack
									align="space-between"
									blockAlign="center"
									gap="200"
								>
									<InlineStack gap="200" blockAlign="center">
										<Text as="h3" variant="headingSm">
											Preview
										</Text>
										{ previewQuery.isFetching && (
											<Spinner
												accessibilityLabel="Updating preview"
												size="small"
											/>
										) }
									</InlineStack>
									{ /* Disabled when there is nothing to save. */ }
									<ButtonGroup>
										<Button
											disabled={
												! dirty || save.isPending
											}
											onClick={ () => {
												save.reset()
												setDraft( savedDraft )
											} }
										>
											Revert
										</Button>
										<Button
											variant="primary"
											disabled={ ! dirty }
											loading={ save.isPending }
											onClick={ () =>
												save.mutate(
													toBody( draft, canEditCss )
												)
											}
										>
											Save
										</Button>
									</ButtonGroup>
								</InlineStack>
								{ save.isError && (
									<Banner tone="critical">
										{ save.error?.code ===
										'ERR_INVALID_INPUT'
											? 'Some values are not valid — see the marked fields.'
											: errorMessage( save.error?.code ) }
									</Banner>
								) }
								{ previewQuery.isError ? (
									<Banner tone="warning">
										{ errorMessage(
											previewQuery.error?.code
										) }
									</Banner>
								) : (
									<PreviewFrame
										preview={ previewQuery.data }
									/>
								) }
								<Text as="p" tone="subdued" variant="bodySm">
									The real switcher with your unsaved changes
									— open it, hover, use the keyboard. Your
									theme’s font applies on the site.
								</Text>
							</BlockStack>
						</Card>
					</BlockStack>
				</div>
			</div>
		</BlockStack>
	)
}
