import {
	ActionList,
	Button,
	ButtonGroup,
	Icon,
	InlineStack,
	Modal,
	Popover,
	Text,
	Tooltip,
} from '@shopify/polaris'
import {
	CheckIcon,
	ChevronDownIcon,
	ChevronLeftIcon,
	ChevronRightIcon,
	DuplicateIcon,
} from '@shopify/polaris-icons'
import { useEffect, useRef, useState } from '@wordpress/element'
import { api } from '../api'
import { AiIcon } from './AiIcon'
import { flagIconUrl, FlagIcon } from './FlagIcon'

/*
 * Gettext string editor overlay — same two-pane shell as Content's
 * CompareOverlay (original read-only left, editable translation right,
 * prev/next through the current list), including its header chrome: a
 * flag+name on each pane, AI translate + copy-original on the left, a
 * language-switch popover on the right. No composed/chip editing (gettext
 * strings are plain text, never HTML with markers).
 */

const PANE_HEIGHT = 'min(62vh, 620px)'
const PANE_CLASS = 'flex-1 min-w-0 flex flex-col gap-2 px-4 py-3'
// Polaris' Modal dialog sits at a z-index (519) above a Tooltip's own portal
// (400) — inside a Modal a Tooltip renders but paints behind it unless
// explicitly overridden higher than the dialog.
const TOOLTIP_Z_INDEX = 1000

const textareaStyle = ( muted ) => ( {
	all: 'unset',
	boxSizing: 'border-box',
	flex: 1,
	minHeight: 0,
	overflowY: 'auto',
	whiteSpace: 'pre-wrap',
	padding: '8px 4px',
	fontSize: '13px',
	lineHeight: '22px',
	color: muted ? 'var(--p-color-text-subdued)' : 'var(--p-color-text)',
} )

function PaneHeader( { left, right } ) {
	return (
		<div
			style={ {
				display: 'grid',
				alignItems: 'center',
				height: '24px',
				minHeight: '24px',
				flex: '0 0 24px',
			} }
		>
			<InlineStack
				align="space-between"
				blockAlign="center"
				gap="200"
				wrap={ false }
			>
				{ left }
				{ right }
			</InlineStack>
		</div>
	)
}

export default function GettextOverlay( {
	open,
	unit,
	row,
	domainLabel,
	locale,
	sourceLabel = 'Original',
	sourceFlag = '',
	languages = [],
	aiAvailable,
	onPickLanguage,
	onAiUnavailable,
	onClose,
	onSaved,
	onPrev,
	onNext,
	hasPrev,
	hasNext,
} ) {
	const [ text, setText ] = useState( '' )
	const [ saving, setSaving ] = useState( false )
	const [ savedTick, setSavedTick ] = useState( 0 )
	const [ translating, setTranslating ] = useState( false )
	const [ langOpen, setLangOpen ] = useState( false )
	const [ copied, setCopied ] = useState( false )
	const savedRef = useRef( '' )

	useEffect( () => {
		if ( ! open || ! unit ) {
			return
		}
		savedRef.current = unit.msgstr
		setText( unit.msgstr )
	}, [ open, unit ] )

	if ( ! unit ) {
		return null
	}

	async function persist( value ) {
		if ( value === savedRef.current ) {
			return
		}
		setSaving( true )
		const res = await api.post( '/gettext-domains/save-unit', {
			type: row.type,
			domain: row.domain,
			locale,
			msgid: unit.msgid,
			context: unit.context,
			msgstr: value,
		} )
		setSaving( false )
		if ( res.ok ) {
			savedRef.current = value
			setSavedTick( ( t ) => t + 1 )
			onSaved( value )
		}
	}

	async function flush() {
		await persist( text )
	}

	async function withFlush( fn ) {
		await flush()
		fn()
	}

	// Translates this one string into EVERY configured language (matches
	// Content board's CompareOverlay), one request at a time — only the
	// response for the currently-viewed `locale` updates what's shown here;
	// the rest just save server-side (each locale's own gettext-units query
	// is fetched fresh whenever that locale is later viewed, so there's
	// nothing else to invalidate). Stops on the first failure instead of
	// firing more doomed requests for the remaining languages.
	async function translate() {
		setTranslating( true )
		for ( const lang of languages ) {
			const res = await api.post( '/gettext-domains/translate-unit', {
				type: row.type,
				domain: row.domain,
				locale: lang.code,
				msgid: unit.msgid,
				context: unit.context,
			} )
			if ( ! res.ok ) {
				if ( res.error !== 'ERR_INVALID_INPUT' ) {
					onAiUnavailable?.( res.error )
				}
				break
			}
			if ( lang.code === locale ) {
				setText( res.msgstr )
				savedRef.current = res.msgstr
				setSavedTick( ( t ) => t + 1 )
				onSaved( res.msgstr )
			}
		}
		setTranslating( false )
	}

	async function copyOriginal() {
		await navigator.clipboard.writeText( unit.msgid )
		setCopied( true )
		setTimeout( () => setCopied( false ), 1500 )
	}

	const targetLang = languages.find( ( l ) => l.code === locale )
	const targetLabel = targetLang?.display_name || targetLang?.name || locale
	const targetFlag = flagIconUrl( targetLang || { code: locale, locale } )
	const matchesOriginal = text === unit.msgid
	let copyTooltipContent = 'Copy original to clipboard'
	if ( matchesOriginal ) {
		copyTooltipContent = 'Translation already matches the original'
	}
	if ( copied ) {
		copyTooltipContent = 'Copied!'
	}

	let saveStatus = ''
	if ( saving ) {
		saveStatus = 'Saving…'
	} else if ( savedTick > 0 ) {
		saveStatus = 'Saved'
	}

	const header = (
		<InlineStack
			align="center"
			gap="300"
			blockAlign="center"
			wrap={ false }
		>
			<ButtonGroup variant="segmented">
				<Button
					icon={ ChevronLeftIcon }
					disabled={ ! hasPrev }
					onClick={ () => withFlush( onPrev ) }
					accessibilityLabel="Previous string"
				/>
				<Button
					icon={ ChevronRightIcon }
					disabled={ ! hasNext }
					onClick={ () => withFlush( onNext ) }
					accessibilityLabel="Next string"
				/>
			</ButtonGroup>
			<Text as="span" variant="headingXs" tone="subdued">
				{ domainLabel }
			</Text>
		</InlineStack>
	)

	return (
		<Modal
			open={ open }
			onClose={ () => withFlush( onClose ) }
			title={ header }
			size="large"
			noScroll
		>
			<div
				style={ {
					display: 'flex',
					height: PANE_HEIGHT,
					alignItems: 'stretch',
				} }
			>
				<div className={ PANE_CLASS }>
					<PaneHeader
						left={
							<InlineStack
								gap="150"
								blockAlign="center"
								wrap={ false }
							>
								<FlagIcon src={ sourceFlag } />
								<Text
									as="span"
									variant="headingXs"
									tone="subdued"
								>
									{ sourceLabel }
								</Text>
							</InlineStack>
						}
						right={
							<InlineStack
								gap="100"
								blockAlign="center"
								wrap={ false }
							>
								<Tooltip
									zIndexOverride={ TOOLTIP_Z_INDEX }
									content={
										aiAvailable
											? 'Translate with AI (all languages)'
											: 'AI translation is not available'
									}
								>
									<Button
										size="micro"
										icon={ ( props ) => (
											<AiIcon { ...props } size={ 12 } />
										) }
										disabled={ ! aiAvailable }
										loading={ translating }
										onClick={ translate }
									>
										Translate
									</Button>
								</Tooltip>
								<Tooltip
									zIndexOverride={ TOOLTIP_Z_INDEX }
									content={ copyTooltipContent }
								>
									<Button
										size="micro"
										variant="tertiary"
										icon={
											copied ? CheckIcon : DuplicateIcon
										}
										disabled={ matchesOriginal }
										accessibilityLabel="Copy original to clipboard"
										onClick={ copyOriginal }
									/>
								</Tooltip>
							</InlineStack>
						}
					/>
					<div style={ textareaStyle( false ) }>{ unit.msgid }</div>
				</div>

				<div
					style={ { width: 1, background: 'var(--p-color-border)' } }
				/>

				<div className={ PANE_CLASS }>
					<PaneHeader
						left={
							<Popover
								active={ langOpen }
								onClose={ () => setLangOpen( false ) }
								activator={
									<button
										type="button"
										onClick={ () =>
											setLangOpen( ( o ) => ! o )
										}
										style={ {
											all: 'unset',
											cursor: 'pointer',
											display: 'inline-flex',
											alignItems: 'center',
											gap: '6px',
										} }
									>
										<FlagIcon src={ targetFlag } />
										<Text
											as="span"
											variant="headingXs"
											tone="subdued"
										>
											{ targetLabel }
										</Text>
										<span className="w-3.5 h-3.5">
											<Icon
												source={ ChevronDownIcon }
												tone="subdued"
											/>
										</span>
									</button>
								}
							>
								<ActionList
									items={ languages.map( ( l ) => ( {
										content:
											l.display_name || l.name || l.code,
										active: l.code === locale,
										onAction: () =>
											withFlush( () => {
												setLangOpen( false )
												onPickLanguage( l.code )
											} ),
									} ) ) }
								/>
							</Popover>
						}
						right={
							<Text as="span" variant="bodyXs" tone="subdued">
								{ saveStatus }
							</Text>
						}
					/>
					<textarea
						value={ text }
						onChange={ ( e ) => setText( e.target.value ) }
						onBlur={ flush }
						placeholder={ unit.msgid }
						aria-label="Translation"
						style={ { ...textareaStyle( false ), resize: 'none' } }
					/>
				</div>
			</div>
		</Modal>
	)
}
