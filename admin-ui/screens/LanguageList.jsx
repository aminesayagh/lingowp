import { ActionList, BlockStack, Button, Text } from '@shopify/polaris';
import {
	createPortal,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { AnimatePresence, motion } from 'motion/react';

// Country code → flag emoji (regional indicator letters). Falls back per language
// code, then a globe.
const REGION_FALLBACK = { fr: 'FR', es: 'ES', de: 'DE', it: 'IT', pt: 'PT', nl: 'NL', ar: 'MA', en: 'US' };

export function flagEmoji( lang ) {
	let cc = ( lang.region || '' ).trim();
	if ( cc.length !== 2 && lang.locale ) {
		cc = ( lang.locale.split( /[_-]/ )[ 1 ] || '' ).trim();
	}
	if ( cc.length !== 2 ) {
		cc = REGION_FALLBACK[ lang.code ] || '';
	}
	if ( cc.length !== 2 ) {
		return '🌐';
	}
	const base = 0x1f1e6;
	const up = cc.toUpperCase();
	return String.fromCodePoint( base + up.charCodeAt( 0 ) - 65, base + up.charCodeAt( 1 ) - 65 );
}

// Animated dropdown (motion): a gap below the activator + a fade/slide on open,
// with click-outside to close. Portaled to <body> and fixed-positioned off the
// activator so no ancestor (e.g. a Polaris Card's overflow) can clip it.
export function AnimatedMenu( { open, onClose, activator, children } ) {
	const anchorRef = useRef( null );
	const menuRef = useRef( null );
	const [ pos, setPos ] = useState( null );

	const place = useCallback( () => {
		const el = anchorRef.current;
		if ( el ) {
			const r = el.getBoundingClientRect();
			setPos( { top: r.bottom + 8, right: window.innerWidth - r.right } );
		}
	}, [] );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		place();
		function onDoc( e ) {
			if ( anchorRef.current?.contains( e.target ) || menuRef.current?.contains( e.target ) ) {
				return;
			}
			onClose();
		}
		document.addEventListener( 'mousedown', onDoc );
		window.addEventListener( 'resize', place );
		window.addEventListener( 'scroll', place, true );
		return () => {
			document.removeEventListener( 'mousedown', onDoc );
			window.removeEventListener( 'resize', place );
			window.removeEventListener( 'scroll', place, true );
		};
	}, [ open, onClose, place ] );

	return (
		<>
			<span ref={ anchorRef } style={ { display: 'inline-flex' } }>{ activator }</span>
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
								borderRadius: 'var(--p-border-radius-300, 12px)',
								boxShadow: 'var(--p-shadow-400, 0 6px 16px rgba(0,0,0,0.16))',
								border: '1px solid var(--p-color-border-secondary, rgba(0,0,0,0.08))',
								overflow: 'hidden',
							} }
						>
							{ children }
						</motion.div>
					) }
				</AnimatePresence>,
				document.body
			) }
		</>
	);
}

// One language row: flag + name, % on the right that swaps to a ⋯ menu on hover.
// Every action is optional — only handlers that are passed produce a menu item, so
// the same row works in the dashboard rail and inside the language manager.
export function LanguageRow( { lang, selected, percent, aiAvailable, onSelect, onEdit, onToggleStatus, onTranslateAll, onDelete } ) {
	const [ hover, setHover ] = useState( false );
	const [ menuOpen, setMenuOpen ] = useState( false );
	const name = ( lang.display_name || lang.name || lang.code ) + ( lang.enabled ? '' : ' (Draft)' );

	const items = [
		onEdit && { content: 'Settings', onAction: () => { setMenuOpen( false ); onEdit(); } },
		onToggleStatus && { content: lang.enabled ? 'Set to draft' : 'Publish', onAction: () => { setMenuOpen( false ); onToggleStatus(); } },
		onTranslateAll && percent < 100 && { content: 'Translate All', disabled: ! aiAvailable, onAction: () => { setMenuOpen( false ); onTranslateAll(); } },
		onDelete && { content: 'Delete', destructive: true, onAction: () => { setMenuOpen( false ); onDelete(); } },
	].filter( Boolean );

	const hasMenu = items.length > 0;
	const showMenu = hasMenu && ( hover || menuOpen );

	return (
		<div
			onMouseEnter={ () => setHover( true ) }
			onMouseLeave={ () => setHover( false ) }
			onClick={ onSelect }
			style={ {
				display: 'flex',
				alignItems: 'center',
				gap: 8,
				height: 32,
				paddingInline: 8,
				borderRadius: 8,
				cursor: 'pointer',
				background: selected ? 'var(--p-color-bg-surface-selected, rgba(0,0,0,0.06))' : 'transparent',
			} }
		>
			<span style={ { fontSize: 16, lineHeight: 1 } }>{ flagEmoji( lang ) }</span>
			<span style={ { flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
				<Text as="span" variant="bodyMd">{ name }</Text>
			</span>
			<div
				onClick={ ( e ) => e.stopPropagation() }
				style={ { width: 36, height: 24, display: 'flex', alignItems: 'center', justifyContent: 'flex-end' } }
			>
				<AnimatedMenu
					open={ menuOpen }
					onClose={ () => setMenuOpen( false ) }
					activator={
						showMenu ? (
							<Button variant="tertiary" size="micro" onClick={ () => setMenuOpen( ( o ) => ! o ) }>⋯</Button>
						) : (
							<Text as="span" tone="subdued" variant="bodySm">{ percent }%</Text>
						)
					}
				>
					<ActionList items={ items } />
				</AnimatedMenu>
			</div>
		</div>
	);
}

// The reusable language list: rows + an optional "add" affordance. Shared by the
// dashboard rail and the language manager modal.
export default function LanguageList( {
	languages,
	selectedCode,
	aiAvailable,
	onSelect,
	onEdit,
	onToggleStatus,
	onTranslateAll,
	onDelete,
	onAdd,
	addLabel = '+ Add language',
} ) {
	return (
		<BlockStack gap="300">
			<div>
				{ languages.map( ( lang ) => (
					<LanguageRow
						key={ lang.code }
						lang={ lang }
						selected={ lang.code === selectedCode }
						percent={ lang.translated_percent ?? 0 }
						aiAvailable={ aiAvailable }
						onSelect={ onSelect ? () => onSelect( lang ) : undefined }
						onEdit={ onEdit ? () => onEdit( lang ) : undefined }
						onToggleStatus={ onToggleStatus ? () => onToggleStatus( lang ) : undefined }
						onTranslateAll={ onTranslateAll ? () => onTranslateAll( lang ) : undefined }
						onDelete={ onDelete ? () => onDelete( lang ) : undefined }
					/>
				) ) }
			</div>
			{ onAdd && (
				<Button variant="plain" textAlign="left" onClick={ onAdd }>{ addLabel }</Button>
			) }
		</BlockStack>
	);
}
