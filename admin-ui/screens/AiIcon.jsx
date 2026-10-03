const VIEW_SIZE = 56
const CENTER = VIEW_SIZE / 2
// Polaris' own Icon chrome always renders its icon slot at this size
// (`.Polaris-Icon{width:1.25rem;height:1.25rem}`) — `size` is expressed
// relative to that, not as a literal pixel value on the svg itself.
const SLOT_SIZE = 20

// The outer svg's box is never touched — Polaris' Icon/Button chrome sizes
// and centers it via CSS, exactly like every @shopify/polaris-icons icon
// (which is authored as viewBox-only, no width/height). Fighting that box
// with our own width/height/margin is what kept landing off-center. Instead,
// a smaller `size` shrinks the artwork itself around its own center, via an
// inner transform — the outer box stays put, so centering can't drift.
export const AiIcon = ( { size, ...props } ) => {
	const scale = size ? size / SLOT_SIZE : 1
	const transform =
		scale === 1
			? undefined
			: `translate(${ CENTER * ( 1 - scale ) } ${
					CENTER * ( 1 - scale )
			  }) scale(${ scale })`

	return (
		<svg viewBox={ `0 0 ${ VIEW_SIZE } ${ VIEW_SIZE }` } { ...props }>
			<g transform={ transform }>
				<path
					d="M24.4563 2.45625C25.6562 -0.81875 30.2812 -0.81875 31.4812 2.45625L36.8313 16.8812C37.2063 17.9062 38.0312 18.7062 39.0563 19.1063L53.4813 24.4563C56.7562 25.6562 56.7562 30.2812 53.4813 31.4812L39.0563 36.8312C38.0312 37.2062 37.2313 38.0312 36.8313 39.0563L31.4812 53.4812C30.2812 56.7562 25.6562 56.7562 24.4563 53.4812L19.1063 39.0563C18.7313 38.0312 17.9062 37.2312 16.8812 36.8312L2.45625 31.4812C-0.81875 30.2812 -0.81875 25.6562 2.45625 24.4563L16.8812 19.1063C17.9062 18.7312 18.7063 17.9062 19.1063 16.8812L24.4563 2.45625Z"
					fill="currentColor"
				/>
			</g>
		</svg>
	)
}
