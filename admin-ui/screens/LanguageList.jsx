import { ActionList, BlockStack, Button, Text } from '@shopify/polaris'
import { MenuHorizontalIcon } from '@shopify/polaris-icons'
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element'
import { AnimatePresence, motion } from 'motion/react'
import { flagIconUrl, FlagIcon } from './FlagIcon'

const baseLanguage = ( code ) =>
	( code || '' ).split( /[_-]/ )[ 0 ]?.toLowerCase() || ''

// Group by base language (fr, es, pt, …); within a group the region-less
// "General" variant (fr vs fr_CA) always leads, then oldest-added first.
// Groups themselves are ordered by their earliest-created member — a group
// whose first language was added before another group's first language
// sorts first, regardless of which is the site's original language.
// Exported so every other place a language list is shown (e.g. the header's
// language-switcher popover) can match this same order.
export function sortLanguages( languages ) {
	const groupFirstSeen = new Map()
	for ( const lang of languages ) {
		const base = lang.language || baseLanguage( lang.code )
		const createdAt = lang.created_at || ''
		const seen = groupFirstSeen.get( base )
		if ( seen === undefined || createdAt < seen ) {
			groupFirstSeen.set( base, createdAt )
		}
	}

	return [ ...languages ].sort( ( a, b ) => {
		const aBase = a.language || baseLanguage( a.code )
		const bBase = b.language || baseLanguage( b.code )
		if ( aBase !== bBase ) {
			return groupFirstSeen
				.get( aBase )
				.localeCompare( groupFirstSeen.get( bBase ) )
		}
		const aGeneral = ! a.region
		const bGeneral = ! b.region
		if ( aGeneral !== bGeneral ) {
			return aGeneral ? -1 : 1
		}
		return ( a.created_at || '' ).localeCompare( b.created_at || '' )
	} )
}

// Below this translated percentage, publishing a language asks for confirmation
// first (Dashboard.jsx / LanguageManagerModal.jsx).
export const PUBLISH_MIN_PERCENT = 90

// Animated dropdown (motion): a gap below the activator + a fade/slide on open,
// with click-outside to close. Portaled to <body> and fixed-positioned off the
// activator so no ancestor (e.g. a Polaris Card's overflow) can clip it.
export function AnimatedMenu( { open, onClose, activator, children } ) {
	const anchorRef = useRef( null )
	const menuRef = useRef( null )
	const [ pos, setPos ] = useState( null )

	const place = useCallback( () => {
		const el = anchorRef.current
		if ( el ) {
			const r = el.getBoundingClientRect()
			setPos( { top: r.bottom + 8, right: window.innerWidth - r.right } )
		}
	}, [] )

	useEffect( () => {
		if ( ! open ) {
			return undefined
		}
		place()
		function onDoc( e ) {
			if (
				anchorRef.current?.contains( e.target ) ||
				menuRef.current?.contains( e.target )
			) {
				return
			}
			onClose()
		}
		document.addEventListener( 'mousedown', onDoc )
		window.addEventListener( 'resize', place )
		window.addEventListener( 'scroll', place, true )
		return () => {
			document.removeEventListener( 'mousedown', onDoc )
			window.removeEventListener( 'resize', place )
			window.removeEventListener( 'scroll', place, true )
		}
	}, [ open, onClose, place ] )

	return (
		<>
			<span ref={ anchorRef } style={ { display: 'inline-flex' } }>
				{ activator }
			</span>
			{ createPortal(
				<AnimatePresence>
					{ open && pos && (
						<motion.div
							ref={ menuRef }
							initial={ { opacity: 0, y: -6 } }
							animate={ { opacity: 1, y: 0 } }
							exit={ { opacity: 0, y: -6 } }
							transition={ { duration: 0.15, ease: 'easeOut' } }
							style={ {
								position: 'fixed',
								top: pos.top,
								right: pos.right,
								zIndex: 520,
								minWidth: 168,
								background: 'var(--p-color-bg-surface, #fff)',
								borderRadius:
									'var(--p-border-radius-300, 12px)',
								boxShadow:
									'var(--p-shadow-400, 0 6px 16px rgba(0,0,0,0.16))',
								border: '1px solid var(--p-color-border-secondary, rgba(0,0,0,0.08))',
								overflow: 'hidden',
							} }
						>
							{ children }
						</motion.div>
					) }
				</AnimatePresence>,
				document.getElementById( 'lingowp-portals' ) || document.body
			) }
		</>
	)
}

// One language row: flag + name, % on the right that swaps to a ⋯ menu on hover.
// Every action is optional — only handlers that are passed produce a menu item, so
// the same row works in the dashboard rail and inside the language manager.
export function LanguageRow( {
	lang,
	selected,
	percent,
	showProgress,
	onSelect,
	onEdit,
	onTranslateAll,
	onToggleStatus,
	onUseFallback,
	onSwitchToInternal,
	onDelete,
	// Draft languages show a desaturated flag in the sidebar rail so their
	// unpublished state reads at a glance. The language manager modal turns
	// this off (flagGrayscale={false}) — there the row's own status control
	// already says it, and the greyed flag just looked broken next to it.
	flagGrayscale = true,
} ) {
	const [ menuOpen, setMenuOpen ] = useState( false )
	// The canonical label, "Português (Brasil)" — never the workspace-board
	// nickname (lang.display_name), which belongs on the board alone.
	const name =
		( lang.native_display || lang.name || lang.code ).trim() || lang.code

	const items = [
		onEdit && {
			content: 'Settings',
			onAction: () => {
				setMenuOpen( false )
				onEdit()
			},
		},
		// AI-translate every still-missing text in this language, without having
		// to select it first. Parent only passes the handler when AI is usable.
		onTranslateAll && {
			content: 'Translate all',
			onAction: () => {
				setMenuOpen( false )
				onTranslateAll()
			},
		},
		// Only shown when this language currently translates through the user's
		// own provider key AND they also have a subscription — the parent only
		// passes onSwitchToInternal in that case, so this just checks the value.
		onSwitchToInternal &&
			( lang.ai_model === 'openai' || lang.ai_model === 'anthropic' ) && {
				content: 'Switch to Lingostack model',
				onAction: () => {
					setMenuOpen( false )
					onSwitchToInternal()
				},
			},
		onToggleStatus && {
			content: lang.enabled ? 'Set to draft' : 'Publish',
			onAction: () => {
				setMenuOpen( false )
				onToggleStatus()
			},
		},
		// fallback_ready (from GET /languages) is true only when the fallback
		// shares the base language AND actually has something this language is
		// missing — a same-base pointer with nothing new to offer stays disabled too.
		onUseFallback && {
			content: 'Use fallback translation',
			disabled: ! lang.fallback_ready,
			onAction: () => {
				setMenuOpen( false )
				onUseFallback()
			},
		},
		onDelete && {
			content: 'Delete',
			destructive: true,
			onAction: () => {
				setMenuOpen( false )
				onDelete()
			},
		},
	].filter( Boolean )

	const hasMenu = items.length > 0
	// The %-to-⋯ swap is done in CSS (see .lingowp-row-* in tailwind.css), not
	// with a React `hover` state: mouseleave never fires when a modal opens over
	// the cursor or the list scrolls under it, and the ⋯ then stuck on rows the
	// cursor had long left. Only menuOpen — driven by clicks — stays in JS, to
	// hold the button visible while its dropdown is up.
	const rowClass = [
		hasMenu && 'lingowp-row--has-menu',
		menuOpen && 'lingowp-row--menu-open',
	]
		.filter( Boolean )
		.join( ' ' )

	return (
		<div
			role="button"
			tabIndex={ 0 }
			className={ rowClass }
			onClick={ ( event ) => {
				if ( ! event.target.closest( '[data-language-actions]' ) ) {
					onSelect()
				}
			} }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault()
					onSelect()
				}
			} }
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: 8,
				height: 32,
				paddingInline: 8,
				borderRadius: 8,
				cursor: 'pointer',
				background: selected
					? 'var(--p-color-bg-surface-selected, rgba(0,0,0,0.06))'
					: 'transparent',
			} }
		>
			<FlagIcon
				src={ flagIconUrl( lang ) }
				size={ 16 }
				grayscale={ flagGrayscale && ! lang.enabled }
			/>
			<span
				style={ {
					flex: 1,
					minWidth: 0,
					overflow: 'hidden',
					textOverflow: 'ellipsis',
					whiteSpace: 'nowrap',
				} }
			>
				<Text as="span" variant="bodyMd">
					{ name }
				</Text>
			</span>
			{ ( showProgress || hasMenu ) && (
				// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events -- not an interactive control; this wrapper only stops click propagation to the parent row (the real control is the menu inside it), so a keyboard handler / tab stop here would be spurious
				<div
					data-language-actions
					// The ActionList's items render into a portal — physically
					// elsewhere in the DOM, so the row's own `.closest(
					// '[data-language-actions]')` guard above never matches a
					// click on one of them. React still bubbles the click
					// through the *React* tree though (portals are transparent
					// to React's own event bubbling), so without this the row's
					// onClick fired anyway — e.g. clicking "Settings" also
					// switched the working language to the one being edited.
					onClick={ ( event ) => event.stopPropagation() }
					style={ {
						width: 36,
						height: 24,
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'flex-end',
					} }
				>
					<AnimatedMenu
						open={ menuOpen }
						onClose={ () => setMenuOpen( false ) }
						activator={
							<>
								{ showProgress && (
									<span className="lingowp-row-percent">
										<Text
											as="span"
											tone="subdued"
											variant="bodySm"
										>
											{ percent }%
										</Text>
									</span>
								) }
								{ hasMenu && (
									<span className="lingowp-row-actions">
										<Button
											variant="tertiary"
											size="micro"
											icon={ MenuHorizontalIcon }
											accessibilityLabel="More actions"
											onClick={ () =>
												setMenuOpen( ( o ) => ! o )
											}
										/>
									</span>
								) }
							</>
						}
					>
						<ActionList items={ items } />
					</AnimatedMenu>
				</div>
			) }
		</div>
	)
}

// The reusable language list: rows + an optional "add" affordance. Shared by the
// dashboard rail and the language manager modal.
export default function LanguageList( {
	languages,
	selectedCode,
	onSelect,
	onEdit,
	onTranslateAll,
	onToggleStatus,
	onUseFallback,
	onSwitchToInternal,
	onDelete,
	onAdd,
	addLabel = '+ Add language',
	showProgress = true,
	// Passed straight to each row — see LanguageRow. Sidebar keeps the default
	// (draft flags desaturated); the language manager modal sets it false.
	flagGrayscale = true,
	// Caps the row list to its own scrollbar instead of growing forever —
	// e.g. the dashboard rail's Target language section, so a long roster
	// doesn't push the "+ Add language" button and everything below it (the
	// Sources section) out of view. Unset (the language manager modal's own
	// sidebar) keeps the old unbounded behavior.
	maxHeight,
} ) {
	const sorted = useMemo( () => sortLanguages( languages ), [ languages ] )

	return (
		<BlockStack gap="300">
			<div
				style={
					maxHeight ? { maxHeight, overflowY: 'auto' } : undefined
				}
			>
				{ sorted.map( ( lang ) => (
					<LanguageRow
						key={ lang.code }
						lang={ lang }
						selected={ lang.code === selectedCode }
						percent={ lang.translated_percent ?? 0 }
						showProgress={ showProgress }
						flagGrayscale={ flagGrayscale }
						onSelect={
							onSelect ? () => onSelect( lang ) : undefined
						}
						onEdit={ onEdit ? () => onEdit( lang ) : undefined }
						onTranslateAll={
							onTranslateAll
								? () => onTranslateAll( lang )
								: undefined
						}
						onToggleStatus={
							onToggleStatus
								? () => onToggleStatus( lang )
								: undefined
						}
						onUseFallback={
							onUseFallback
								? () => onUseFallback( lang )
								: undefined
						}
						onSwitchToInternal={
							onSwitchToInternal
								? () => onSwitchToInternal( lang )
								: undefined
						}
						onDelete={
							onDelete ? () => onDelete( lang ) : undefined
						}
					/>
				) ) }
			</div>
			{ onAdd && (
				<Button variant="plain" textAlign="left" onClick={ onAdd }>
					{ addLabel }
				</Button>
			) }
		</BlockStack>
	)
}
