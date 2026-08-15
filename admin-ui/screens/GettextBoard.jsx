import {
	ActionList,
	Badge,
	BlockStack,
	Box,
	Button,
	Collapsible,
	Divider,
	InlineStack,
	Popover,
	Spinner,
	Text,
} from '@shopify/polaris'
import {
	CheckIcon,
	DuplicateIcon,
	MenuHorizontalIcon,
	PageIcon,
} from '@shopify/polaris-icons'
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query'
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element'
import { AnimatePresence, motion } from 'motion/react'
import { api } from '../api'
import { AiIcon } from './AiIcon'
import GettextOverlay from './GettextOverlay'

const oneLineStyle = {
	all: 'unset',
	cursor: 'pointer',
	display: 'block',
	width: '100%',
	whiteSpace: 'nowrap',
	overflow: 'hidden',
	textOverflow: 'ellipsis',
}

// Plugins & Themes board: every installed plugin/theme, scoped to the same
// single working language as the Content tab, is its own row/collapse
// (closed by default). Same list chrome as Content: a language-pair column
// header on top, 50 rows per page with a "Load more" button — not the whole
// site's plugins in one shot. Gettext strings (`__()`, `_e()`, etc.) are
// served by WordPress itself once a `.mo` is in place — this board only
// manages that file:
//   - rows with no translation yet are auto-checked against wordpress.org in
//     the background, one at a time, as each page of the list loads;
//   - still nothing found -> Import .po, or Translate manually (loads every
//     string this component's source scan finds, blank or already-translated,
//     into the same editable original/translation rows the Content tab uses);
//   - anything found (online, imported, or typed here) -> the row list is
//     always the editable view, never read-only — same row style, spacing,
//     inline input, and ⋯ menu (Open in overlay / Ignore) as the Content tab.
const PAGE_SIZE = 50

function rowKey( row ) {
	return `${ row.type }:${ row.domain }:${ row.locale }`
}

export default function GettextBoard( {
	language,
	search,
	originalLabel,
	translationLabel,
	sourceLabel,
	sourceFlag,
	languages,
	aiAvailable,
	onPickLanguage,
	onConfirm,
	onAiUnavailable,
} ) {
	const queryClient = useQueryClient()
	const [ openKeys, setOpenKeys ] = useState( () => new Set() )
	const [ manualKeys, setManualKeys ] = useState( () => new Set() )
	const checkedRef = useRef( new Set() )
	const runningRef = useRef( false )

	const rowsQ = useInfiniteQuery( {
		queryKey: [ 'gettext-domains', language, search ],
		initialPageParam: 0,
		queryFn: async ( { pageParam, signal } ) => {
			const params = new URLSearchParams( {
				language,
				offset: String( pageParam ),
				limit: String( PAGE_SIZE ),
				...( search ? { search } : {} ),
			} )
			const res = await api.get(
				`/gettext-domains?${ params.toString() }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		getNextPageParam: ( last ) =>
			last.has_more ? last.next_offset : undefined,
		enabled: !! language,
	} )

	const rows = ( rowsQ.data?.pages ?? [] ).flatMap( ( p ) => p.rows )

	const reload = useCallback( () => {
		queryClient.invalidateQueries( {
			queryKey: [ 'gettext-domains', language ],
		} )
	}, [ language, queryClient ] )

	// Auto-check online, one row at a time (not in parallel — each check is a
	// real wordpress.org round-trip plus a local language-pack install), only
	// for rows this session hasn't already checked and that have no file yet.
	useEffect( () => {
		if ( rowsQ.isPending || runningRef.current ) {
			return
		}
		const pending = rows.filter(
			( r ) =>
				r.status === null && ! checkedRef.current.has( rowKey( r ) )
		)
		if ( pending.length === 0 ) {
			return
		}

		runningRef.current = true
		;( async () => {
			for ( const row of pending ) {
				checkedRef.current.add( rowKey( row ) )
				try {
					await api.post( '/gettext-domains/find-online', {
						type: row.type,
						domain: row.domain,
						locale: row.locale,
					} )
				} catch {
					// best-effort; this row just stays "not found" until reloaded
				}
			}
			runningRef.current = false
			reload()
		} )()
	}, [ reload, rows, rowsQ.isPending ] )

	function toggle( key ) {
		setOpenKeys( ( prev ) => {
			const next = new Set( prev )
			if ( next.has( key ) ) {
				next.delete( key )
			} else {
				next.add( key )
			}
			return next
		} )
	}

	function startManual( key ) {
		setManualKeys( ( prev ) => new Set( prev ).add( key ) )
	}

	let paginationAction = null
	if ( rowsQ.isFetchNextPageError ) {
		paginationAction = (
			<Button onClick={ () => rowsQ.fetchNextPage() }>
				Retry loading more
			</Button>
		)
	} else if ( rowsQ.hasNextPage ) {
		paginationAction = (
			<Button
				onClick={ () => rowsQ.fetchNextPage() }
				loading={ rowsQ.isFetchingNextPage }
				disabled={ rowsQ.isFetchingNextPage }
			>
				Load more
			</Button>
		)
	}

	if ( rowsQ.isPending ) {
		return (
			<Box padding="500">
				<Spinner
					accessibilityLabel="Loading plugins & themes"
					size="small"
				/>
			</Box>
		)
	}

	if ( rowsQ.isError && rows.length === 0 ) {
		return (
			<Box padding="500">
				<BlockStack gap="200">
					<Text as="p" tone="critical">
						Could not load plugins & themes for this language.
					</Text>
					<InlineStack>
						<Button onClick={ () => rowsQ.refetch() }>Retry</Button>
					</InlineStack>
				</BlockStack>
			</Box>
		)
	}

	if ( rows.length === 0 ) {
		return (
			<Box padding="500">
				<Text as="p" tone="subdued">
					{ search
						? `No plugin or theme matches “${ search }”.`
						: 'No plugin/theme domains found.' }
				</Text>
			</Box>
		)
	}

	return (
		<BlockStack gap="0">
			<Box paddingInlineStart="1000">
				<Box paddingInline="300" paddingBlock="200">
					<InlineStack
						gap="300"
						align="space-between"
						blockAlign="center"
						wrap={ false }
					>
						<Box width="42%" minWidth="0">
							<Text
								as="span"
								variant="bodySm"
								tone="subdued"
								fontWeight="medium"
							>
								{ originalLabel }
							</Text>
						</Box>
						<Box width="42%" minWidth="0">
							<Text
								as="span"
								variant="bodySm"
								tone="subdued"
								fontWeight="medium"
							>
								{ translationLabel }
							</Text>
						</Box>
						<Box width="16%" />
					</InlineStack>
				</Box>
			</Box>
			<Divider />
			{ rows.map( ( row, i ) => {
				const key = rowKey( row )
				const open = openKeys.has( key )
				const showEditor = row.status !== null || manualKeys.has( key )

				return (
					<Box key={ key }>
						{ i > 0 && <Divider /> }
						<Box
							padding="300"
							background={
								open ? 'bg-surface-secondary' : undefined
							}
						>
							<button
								type="button"
								onClick={ () => toggle( key ) }
								style={ {
									all: 'unset',
									cursor: 'pointer',
									display: 'flex',
									width: '100%',
									alignItems: 'center',
									gap: '8px',
								} }
							>
								<Text
									as="span"
									variant="bodyMd"
									fontWeight="medium"
								>
									{ open ? '▾' : '▸' }
								</Text>
								<span
									style={ {
										display: 'inline-flex',
										flexShrink: 0,
										width: 16,
										height: 16,
										color: 'var(--p-color-icon-subdued)',
									} }
								>
									<PageIcon
										fill="currentColor"
										width={ 16 }
										height={ 16 }
									/>
								</span>
								<Text
									as="span"
									variant="bodyMd"
									fontWeight="medium"
								>
									{ row.label }{ ' ' }
									<Text as="span" tone="subdued">
										({ row.type })
									</Text>
								</Text>
								{ row.status === null && (
									<Box paddingInlineStart="200">
										<Badge>Not found</Badge>
									</Box>
								) }
								{ row.translated_percent !== null &&
									row.translated_percent !== undefined && (
										<span
											style={ {
												marginLeft: 'auto',
												flexShrink: 0,
											} }
										>
											<Text
												as="span"
												variant="bodySm"
												tone={
													row.translated_percent ===
													100
														? 'success'
														: 'subdued'
												}
											>
												{ `${ row.translated_percent }%` }
											</Text>
										</span>
									) }
							</button>
						</Box>
						<Collapsible open={ open }>
							<Box
								paddingInlineStart="1000"
								paddingBlockEnd="300"
							>
								{ showEditor ? (
									<GettextUnitList
										row={ row }
										onConfirm={ onConfirm }
										sourceLabel={ sourceLabel }
										sourceFlag={ sourceFlag }
										languages={ languages }
										aiAvailable={ aiAvailable }
										onPickLanguage={ onPickLanguage }
										onAiUnavailable={ onAiUnavailable }
									/>
								) : (
									<GettextRowActions
										row={ row }
										onImported={ reload }
										onManual={ () => startManual( key ) }
									/>
								) }
							</Box>
						</Collapsible>
					</Box>
				)
			} ) }
			{ paginationAction && (
				<>
					<Divider />
					<Box padding="300">
						<InlineStack align="center">
							{ paginationAction }
						</InlineStack>
					</Box>
				</>
			) }
		</BlockStack>
	)
}

function GettextRowActions( { row, onImported, onManual } ) {
	const [ importing, setImporting ] = useState( false )
	const [ error, setError ] = useState( '' )
	const fileRef = useRef( null )

	async function onFileChosen( e ) {
		const file = e.target.files?.[ 0 ]
		e.target.value = ''
		if ( ! file ) {
			return
		}
		if ( ! file.name.toLowerCase().endsWith( '.po' ) ) {
			setError( 'Unsupported format — only .po is accepted.' )
			return
		}

		setImporting( true )
		setError( '' )
		const body = new FormData()
		body.append( 'file', file )
		body.append( 'type', row.type )
		body.append( 'domain', row.domain )
		body.append( 'locale', row.locale )
		try {
			const res = await fetch(
				`${ ( window.Translangs || {} ).rest }/gettext-domains/import`,
				{
					method: 'POST',
					headers: {
						'X-WP-Nonce': ( window.Translangs || {} ).nonce,
					},
					body,
				}
			)
			const data = await res.json()
			if ( data.ok ) {
				onImported()
			} else {
				setError(
					'Import failed — please check the file and try again.'
				)
			}
		} catch {
			setError( 'Import failed — please check the file and try again.' )
		}
		setImporting( false )
	}

	return (
		<BlockStack gap="200">
			<InlineStack gap="200">
				<Button
					size="slim"
					loading={ importing }
					onClick={ () => fileRef.current?.click() }
				>
					Import .po
				</Button>
				<input
					ref={ fileRef }
					type="file"
					accept=".po"
					hidden
					onChange={ onFileChosen }
				/>
				<Button size="slim" onClick={ onManual }>
					Translate manually
				</Button>
			</InlineStack>
			{ error && (
				<Text as="p" tone="critical" variant="bodySm">
					{ error }
				</Text>
			) }
		</BlockStack>
	)
}

// Server-side pagination, same as the domain/locale row list above: the
// server only ever scans+returns one page's worth of strings per request,
// not the whole component in one response.
const TEXT_PAGE_SIZE = 50

function unitKey( u ) {
	return `${ u.context || '' }\x04${ u.msgid }`
}

function GettextUnitList( {
	row,
	onConfirm,
	sourceLabel,
	sourceFlag,
	languages,
	aiAvailable,
	onPickLanguage,
	onAiUnavailable,
} ) {
	const queryClient = useQueryClient()
	const queryKey = [ 'gettext-units', row.type, row.domain, row.locale ]
	const [ overlayIndex, setOverlayIndex ] = useState( null )

	const unitsQ = useInfiniteQuery( {
		queryKey,
		initialPageParam: 0,
		queryFn: async ( { pageParam, signal } ) => {
			const params = new URLSearchParams( {
				type: row.type,
				domain: row.domain,
				locale: row.locale,
				offset: String( pageParam ),
				limit: String( TEXT_PAGE_SIZE ),
			} )
			const res = await api.get(
				`/gettext-domains/units?${ params.toString() }`,
				{ signal }
			)
			if ( ! res.ok ) {
				throw new Error( res.error || 'ERR_UNREACHABLE' )
			}
			return res
		},
		getNextPageParam: ( last ) =>
			last.has_more ? last.next_offset : undefined,
	} )

	const units = ( unitsQ.data?.pages ?? [] ).flatMap( ( p ) => p.units )
	const total = unitsQ.data?.pages?.at( -1 )?.total ?? units.length

	function updateUnit( i, msgstr ) {
		queryClient.setQueryData( queryKey, ( old ) => {
			let idx = 0
			return {
				...old,
				pages: old.pages.map( ( p ) => ( {
					...p,
					units: p.units.map( ( u ) =>
						idx++ === i ? { ...u, msgstr } : u
					),
				} ) ),
			}
		} )
	}

	async function ignoreUnit( i ) {
		const unit = units[ i ]
		await api.post( '/gettext-domains/ignore-unit', {
			type: row.type,
			domain: row.domain,
			locale: row.locale,
			msgid: unit.msgid,
			context: unit.context,
		} )
		setOverlayIndex( null )
		// Removing one item shifts every following page's offset — simplest
		// correct fix is to refetch from page 0 rather than patch pages in place.
		queryClient.invalidateQueries( { queryKey } )
	}

	function confirmIgnore( i ) {
		onConfirm( {
			title: 'Ignore this string?',
			body: "Ignored strings are excluded from this component's translatable list.",
			actionLabel: 'Ignore',
			destructive: true,
			onConfirm: () => ignoreUnit( i ),
		} )
	}

	if ( unitsQ.isPending ) {
		return <Spinner accessibilityLabel="Loading strings" size="small" />
	}
	if ( unitsQ.isError && units.length === 0 ) {
		return (
			<InlineStack gap="200" blockAlign="center">
				<Text as="p" tone="critical" variant="bodySm">
					Could not scan this component’s source.
				</Text>
				<Button size="slim" onClick={ () => unitsQ.refetch() }>
					Retry
				</Button>
			</InlineStack>
		)
	}
	if ( units.length === 0 ) {
		return (
			<Text as="p" tone="subdued" variant="bodySm">
				No translatable strings found in this component’s source.
			</Text>
		)
	}

	const overlayUnit = overlayIndex !== null ? units[ overlayIndex ] : null
	let paginationAction = null
	if ( unitsQ.isFetchNextPageError ) {
		paginationAction = (
			<Button onClick={ () => unitsQ.fetchNextPage() }>
				Retry loading more
			</Button>
		)
	} else if ( unitsQ.hasNextPage ) {
		paginationAction = (
			<Button
				onClick={ () => unitsQ.fetchNextPage() }
				loading={ unitsQ.isFetchingNextPage }
				disabled={ unitsQ.isFetchingNextPage }
			>
				Load more
			</Button>
		)
	}

	return (
		<BlockStack gap="0">
			{ units.map( ( unit, i ) => (
				<GettextUnitRow
					key={ unitKey( unit ) }
					unit={ unit }
					row={ row }
					aiAvailable={ aiAvailable }
					onSaved={ ( msgstr ) => updateUnit( i, msgstr ) }
					onOpenOverlay={ () => setOverlayIndex( i ) }
					onIgnore={ () => confirmIgnore( i ) }
					onAiUnavailable={ onAiUnavailable }
				/>
			) ) }
			<Box padding="300">
				<InlineStack align="space-between" blockAlign="center">
					{ paginationAction }
					<Text as="p" tone="subdued" variant="bodySm">
						{ units.length } of { total }
					</Text>
				</InlineStack>
			</Box>
			<GettextOverlay
				open={ overlayUnit !== null }
				unit={ overlayUnit }
				row={ row }
				domainLabel={ row.label }
				locale={ row.locale }
				sourceLabel={ sourceLabel }
				sourceFlag={ sourceFlag }
				languages={ languages }
				aiAvailable={ aiAvailable }
				onPickLanguage={ onPickLanguage }
				onAiUnavailable={ onAiUnavailable }
				onClose={ () => setOverlayIndex( null ) }
				onSaved={ ( msgstr ) => updateUnit( overlayIndex, msgstr ) }
				onPrev={ () =>
					setOverlayIndex( ( i ) => Math.max( 0, i - 1 ) )
				}
				onNext={ () =>
					setOverlayIndex( ( i ) =>
						Math.min( units.length - 1, i + 1 )
					)
				}
				hasPrev={ overlayIndex > 0 }
				hasNext={ overlayIndex < units.length - 1 }
			/>
		</BlockStack>
	)
}

function GettextUnitRow( {
	unit,
	row,
	aiAvailable,
	onSaved,
	onOpenOverlay,
	onIgnore,
	onAiUnavailable,
} ) {
	const [ draft, setDraft ] = useState( unit.msgstr )
	const [ menuOpen, setMenuOpen ] = useState( false )
	const [ hovered, setHovered ] = useState( false )
	const [ originalHovered, setOriginalHovered ] = useState( false )
	const [ copiedOriginal, setCopiedOriginal ] = useState( false )
	const [ translating, setTranslating ] = useState( false )
	const moreActionsRef = useRef( null )

	const hasTranslation = unit.msgstr !== ''
	const shownText = draft || unit.msgid

	const originalRef = useRef( null )
	const [ isLong, setIsLong ] = useState( false )
	useLayoutEffect( () => {
		const el = originalRef.current
		if ( ! el ) {
			return
		}
		const check = () => setIsLong( el.scrollWidth > el.clientWidth + 1 )
		check()
		const ro = new ResizeObserver( check )
		ro.observe( el )
		return () => ro.disconnect()
	}, [ unit.msgid ] )

	useEffect( () => {
		setDraft( unit.msgstr )
	}, [ unit.msgstr ] )

	async function save() {
		if ( draft === unit.msgstr ) {
			return
		}
		const res = await api.post( '/gettext-domains/save-unit', {
			type: row.type,
			domain: row.domain,
			locale: row.locale,
			msgid: unit.msgid,
			context: unit.context,
			msgstr: draft,
		} )
		if ( res.ok ) {
			onSaved( draft )
		}
	}

	async function copyOriginal() {
		await navigator.clipboard.writeText( unit.msgid )
		setCopiedOriginal( true )
		setTimeout( () => setCopiedOriginal( false ), 1500 )
	}

	async function translateUnit() {
		setTranslating( true )
		const res = await api.post( '/gettext-domains/translate-unit', {
			type: row.type,
			domain: row.domain,
			locale: row.locale,
			msgid: unit.msgid,
			context: unit.context,
		} )
		setTranslating( false )
		if ( res.ok ) {
			setDraft( res.msgstr )
			onSaved( res.msgstr )
		} else if (
			res.error !== 'ERR_QUOTA' &&
			res.error !== 'ERR_INVALID_INPUT'
		) {
			onAiUnavailable?.( res.error )
		}
	}

	const menuItems = [
		{
			content: 'Open in overlay',
			onAction: () => {
				setMenuOpen( false )
				onOpenOverlay()
			},
		},
		{
			content: 'Ignore',
			destructive: true,
			onAction: () => {
				setMenuOpen( false )
				onIgnore()
			},
		},
	]

	// The AI-translate button unmounts while generating (AnimatePresence) —
	// move focus to the adjacent "More actions" button first so a keyboard
	// user never loses focus to <body>.
	function handleAiClick() {
		moreActionsRef.current?.querySelector( 'button' )?.focus()
		translateUnit()
	}

	return (
		<div
			onMouseEnter={ () => setHovered( true ) }
			onMouseLeave={ () => setHovered( false ) }
		>
			<Box paddingInline="300" paddingBlock="200">
				<InlineStack
					gap="300"
					align="space-between"
					blockAlign="center"
					wrap={ false }
				>
					<Box width="42%" minWidth="0">
						<div
							onMouseEnter={ () => setOriginalHovered( true ) }
							onMouseLeave={ () => setOriginalHovered( false ) }
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: '4px',
								minWidth: 0,
							} }
						>
							<button
								ref={ originalRef }
								type="button"
								onClick={ isLong ? onOpenOverlay : undefined }
								disabled={ ! isLong }
								style={ {
									...oneLineStyle,
									flex: 1,
									minWidth: 0,
									cursor: isLong ? 'pointer' : 'default',
								} }
							>
								<Text as="span" variant="bodySm">
									{ unit.msgid }
								</Text>
							</button>
							<div
								style={ {
									opacity: originalHovered ? 1 : 0,
									visibility: originalHovered
										? 'visible'
										: 'hidden',
									transition: 'opacity 120ms ease',
									flexShrink: 0,
								} }
							>
								<Tooltip
									content={
										copiedOriginal
											? 'Copied!'
											: 'Copy to clipboard'
									}
								>
									<Button
										size="micro"
										variant="tertiary"
										icon={
											copiedOriginal
												? CheckIcon
												: DuplicateIcon
										}
										accessibilityLabel="Copy original to clipboard"
										onClick={ copyOriginal }
									/>
								</Tooltip>
							</div>
						</div>
					</Box>

					<Box width="42%" minWidth="0">
						<div style={ { position: 'relative', minWidth: 0 } }>
							{ isLong ? (
								<motion.button
									type="button"
									onClick={ onOpenOverlay }
									animate={ {
										opacity: translating ? 0 : 1,
									} }
									transition={ { duration: 0.15 } }
									style={ oneLineStyle }
								>
									<Text
										as="span"
										variant="bodySm"
										tone={
											hasTranslation
												? undefined
												: 'subdued'
										}
									>
										{ unit.msgstr || unit.msgid }
									</Text>
								</motion.button>
							) : (
								<motion.input
									type="text"
									aria-label="Translation"
									value={ draft }
									onChange={ ( e ) =>
										setDraft( e.target.value )
									}
									onBlur={ save }
									placeholder={ unit.msgid }
									animate={ {
										opacity: translating ? 0 : 1,
									} }
									transition={ { duration: 0.15 } }
									style={ {
										all: 'unset',
										display: 'block',
										width: '100%',
										minWidth: 0,
										cursor: 'text',
										fontSize:
											'var(--p-font-size-300, 0.75rem)',
										lineHeight:
											'var(--p-font-line-height-300, 1rem)',
										color: hasTranslation
											? 'var(--p-color-text)'
											: 'var(--p-color-text-subdued)',
									} }
								/>
							) }
							{ /* No AnimatePresence here on purpose — an exit
							transition would keep this mounted over the just-
							arrived real text and read as a stall. It gets an
							enter fade only; completion removes it instantly. */ }
							{ translating && (
								<motion.span
									aria-hidden="true"
									className="tl-shimmer"
									initial={ { opacity: 0 } }
									animate={ { opacity: 1 } }
									transition={ { duration: 0.15 } }
									style={ {
										position: 'absolute',
										inset: 0,
										whiteSpace: 'nowrap',
										overflow: 'hidden',
										textOverflow: 'ellipsis',
										fontSize:
											'var(--p-font-size-300, 0.75rem)',
										lineHeight:
											'var(--p-font-line-height-300, 1rem)',
									} }
								>
									{ shownText }
								</motion.span>
							) }
						</div>
					</Box>

					<Box width="16%">
						<InlineStack
							gap="100"
							align="end"
							blockAlign="center"
							wrap={ false }
						>
							{ hasTranslation && (
								<Badge tone="success" size="small">
									Translated
								</Badge>
							) }
							<motion.div
								animate={ {
									opacity: hovered || menuOpen ? 1 : 0,
								} }
								transition={ { duration: 0.12 } }
								style={ {
									visibility:
										hovered || menuOpen
											? 'visible'
											: 'hidden',
								} }
							>
								<InlineStack
									gap="100"
									blockAlign="center"
									wrap={ false }
								>
									<div
										style={ {
											position: 'relative',
											flexShrink: 0,
										} }
									>
										{ /* Invisible ghost, same markup as the
										real button below — reserves its exact
										footprint so the slot never shifts when
										the real button mounts/unmounts. */ }
										<div
											aria-hidden="true"
											style={ { visibility: 'hidden' } }
										>
											<Tooltip content="AI translate">
												<Button
													variant="secondary"
													size="micro"
													icon={ AiIcon }
												/>
											</Tooltip>
										</div>
										<AnimatePresence initial={ false }>
											{ ! translating && (
												<motion.div
													key="ai-translate"
													initial={ {
														opacity: 0,
														scale: 0.92,
													} }
													animate={ {
														opacity: 1,
														scale: 1,
													} }
													exit={ {
														opacity: 0,
														scale: 0.92,
													} }
													transition={ {
														duration: 0.2,
														ease: [
															0.4, 0, 0.2, 1,
														],
													} }
													style={ {
														position: 'absolute',
														inset: 0,
													} }
												>
													<Tooltip
														content={
															aiAvailable
																? 'AI translate'
																: 'AI translation is not available'
														}
													>
														<Button
															variant="secondary"
															size="micro"
															disabled={
																! aiAvailable
															}
															icon={ AiIcon }
															accessibilityLabel="AI translate"
															onClick={
																handleAiClick
															}
														/>
													</Tooltip>
												</motion.div>
											) }
										</AnimatePresence>
									</div>
									<span
										ref={ moreActionsRef }
										style={ { display: 'contents' } }
									>
										<Popover
											active={ menuOpen }
											onClose={ () =>
												setMenuOpen( false )
											}
											activator={
												<Button
													variant="secondary"
													size="micro"
													icon={ ( props ) => (
														<MenuHorizontalIcon
															{ ...props }
															style={ {
																...props.style,
																transform:
																	'scale(0.7)',
															} }
														/>
													) }
													accessibilityLabel="More actions"
													onClick={ () =>
														setMenuOpen(
															( o ) => ! o
														)
													}
												/>
											}
										>
											<ActionList items={ menuItems } />
										</Popover>
									</span>
								</InlineStack>
							</motion.div>
						</InlineStack>
					</Box>
				</InlineStack>
			</Box>
		</div>
	)
}
