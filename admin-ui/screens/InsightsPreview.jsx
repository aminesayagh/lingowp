import {
	BlockStack,
	Button,
	Card,
	Divider,
	InlineGrid,
	InlineStack,
	Text,
} from '@shopify/polaris'
import { HERO } from './insightsContent.mjs'

export function GhostBars( { items } ) {
	const max = Math.max( ...items.map( ( i ) => i.width ) )

	return (
		<BlockStack gap="150" aria-hidden="true">
			{ items.map( ( item ) => (
				<InlineStack
					key={ item.label }
					gap="200"
					blockAlign="center"
					wrap={ false }
				>
					<div style={ { flex: '0 0 38%', minWidth: 0 } }>
						<Text as="span" variant="bodySm" truncate>
							<span
								style={
									item.mono
										? { fontFamily: 'monospace' }
										: undefined
								}
							>
								{ item.label }
							</span>
						</Text>
					</div>
					<div
						style={ {
							flex: 1,
							height: 8,
							borderRadius: 4,
							background: 'var(--p-color-bg-surface-secondary)',
							overflow: 'hidden',
						} }
					>
						<div
							className="tl-ghost-shimmer"
							style={ {
								height: '100%',
								width: `${ ( item.width / max ) * 100 }%`,
								borderRadius: 4,
							} }
						/>
					</div>
					<div style={ { flex: '0 0 24px', textAlign: 'right' } }>
						<Text as="span" variant="bodySm" tone="subdued">
							—
						</Text>
					</div>
				</InlineStack>
			) ) }
		</BlockStack>
	)
}

function GhostStatGrid( { items } ) {
	return (
		<InlineGrid columns={ 2 } gap="200" aria-hidden="true">
			{ items.map( ( item ) => (
				<BlockStack key={ item.label } gap="100">
					<Text as="span" variant="bodySm" tone="subdued">
						{ item.label }
					</Text>
					<div
						className="tl-ghost-shimmer"
						style={ { height: 24, borderRadius: 6 } }
					/>
				</BlockStack>
			) ) }
		</InlineGrid>
	)
}

function GhostHealth( { items } ) {
	return (
		<BlockStack gap="150" aria-hidden="true">
			{ items.map( ( item ) => (
				<InlineStack
					key={ item.label }
					gap="200"
					blockAlign="center"
					align="space-between"
				>
					<InlineStack gap="150" blockAlign="center">
						<span
							style={ {
								display: 'inline-block',
								width: 8,
								height: 8,
								borderRadius: '50%',
								background: 'var(--p-color-icon-subdued)',
							} }
						/>
						<Text as="span" variant="bodySm">
							{ item.label }
						</Text>
					</InlineStack>
					<div
						className="tl-ghost-shimmer"
						style={ { width: 32, height: 12, borderRadius: 4 } }
					/>
				</InlineStack>
			) ) }
		</BlockStack>
	)
}

export function GhostKpiRow( { items } ) {
	return (
		<InlineGrid columns={ { xs: 2, md: 4 } } gap="300" aria-hidden="true">
			{ items.map( ( item ) => (
				<Card key={ item.label }>
					<BlockStack gap="100">
						<Text
							as="span"
							variant="bodySm"
							tone="subdued"
							fontWeight="bold"
						>
							{ item.label }
						</Text>
						<div
							className="tl-ghost-shimmer"
							style={ { height: 28, borderRadius: 6 } }
						/>
						<div
							className="tl-ghost-shimmer"
							style={ {
								height: 10,
								width: '60%',
								borderRadius: 4,
							} }
						/>
					</BlockStack>
				</Card>
			) ) }
		</InlineGrid>
	)
}

function GhostBody( { body } ) {
	if ( body.type === 'stat_grid' ) {
		return <GhostStatGrid items={ body.items } />
	}
	if ( body.type === 'health' ) {
		return <GhostHealth items={ body.items } />
	}
	return <GhostBars items={ body.items } />
}

function PreviewPanel( { panel } ) {
	return (
		<Card>
			<BlockStack gap="300">
				<Text as="h4" variant="headingSm">
					{ panel.title }
				</Text>
				<Text as="p" tone="subdued" variant="bodySm">
					{ panel.lede }
				</Text>
				<GhostBody body={ panel.body } />
			</BlockStack>
		</Card>
	)
}

export function TopicSection( { topic } ) {
	return (
		<BlockStack gap="300">
			<InlineStack gap="200" blockAlign="center" wrap={ false }>
				<Text as="h3" variant="headingMd">
					{ topic.heading }
				</Text>
				<div style={ { flex: 1 } }>
					<Divider />
				</div>
			</InlineStack>
			<Text as="p" tone="subdued">
				{ topic.note }
			</Text>
			<InlineGrid columns={ { xs: 1, sm: 2, lg: 3 } } gap="300">
				{ topic.panels.map( ( panel ) => (
					<PreviewPanel key={ panel.id } panel={ panel } />
				) ) }
			</InlineGrid>
		</BlockStack>
	)
}

export function Hero( { notified, notifying, notifyError, onNotify } ) {
	let label = 'Notify me at launch'
	if ( notifying ) {
		label = 'Sending…'
	} else if ( notified ) {
		label = "You're on the list"
	}

	return (
		<Card>
			<BlockStack gap="400">
				<BlockStack gap="200">
					<Text as="span" tone="subdued" variant="bodySm">
						{ HERO.eyebrow }
					</Text>
					<Text as="h1" variant="headingLg">
						{ HERO.headline }
					</Text>
					<Text as="p">{ HERO.body }</Text>
				</BlockStack>
				<InlineStack gap="200">
					<Button
						variant="primary"
						size="large"
						fullWidth={ false }
						loading={ notifying }
						disabled={ notifying || notified }
						onClick={ onNotify }
					>
						{ label }
					</Button>
				</InlineStack>
				{ notifyError && (
					<Text as="span" tone="critical" variant="bodySm">
						{ notifyError }
					</Text>
				) }
			</BlockStack>
		</Card>
	)
}
