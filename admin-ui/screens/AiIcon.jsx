const VIEW_SIZE = 44
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
					d="M38.5203 15.9592L36.0536 14.7242C33.0703 13.2454 30.687 10.9217 29.1703 8.01293L27.9036 5.60793C26.787 3.46293 24.5536 2.13043 22.087 2.13043C19.6203 2.13043 17.387 3.46293 16.2703 5.60793L15.0036 8.01293C13.487 10.9217 11.1036 13.2454 8.12031 14.7242L5.65364 15.9592C3.45364 17.0479 2.08698 19.2254 2.08698 21.6304C2.08698 24.0354 3.45364 26.2129 5.65364 27.3017L8.12031 28.5367C11.1036 30.0154 13.487 32.3392 15.0036 35.2479L16.2703 37.6529C17.387 39.7979 19.6203 41.1304 22.087 41.1304C24.5536 41.1304 26.787 39.7979 27.9036 37.6529L29.1703 35.2479C30.687 32.3392 33.0703 30.0154 36.0536 28.5367L38.5203 27.3017C40.7203 26.2129 42.087 24.0354 42.087 21.6304C42.087 19.2254 40.7203 17.0479 38.5203 15.9592ZM36.2536 22.9629L33.787 24.1979C29.8536 26.1479 26.7203 29.2029 24.7203 33.0379L23.4536 35.4429C23.0703 36.1742 22.3703 36.2554 22.087 36.2554C21.8036 36.2554 21.1036 36.1742 20.7203 35.4429L19.4536 33.0379C17.4536 29.2029 14.3203 26.1479 10.387 24.1979L7.92031 22.9629C7.17031 22.5892 7.08697 21.9067 7.08697 21.6304C7.08697 21.3542 7.17031 20.6717 7.92031 20.2979L10.387 19.0629C14.3203 17.1129 17.4536 14.0579 19.4536 10.2229L20.7203 7.81793C21.1036 7.08668 21.8036 7.00543 22.087 7.00543C22.3703 7.00543 23.0703 7.08668 23.4536 7.81793L24.7203 10.2229C26.7203 14.0579 29.8536 17.1129 33.787 19.0629L36.2536 20.2979C37.0036 20.6717 37.087 21.3542 37.087 21.6304C37.087 21.9067 37.0036 22.5892 36.2536 22.9629Z"
					fill="currentColor"
				/>
			</g>
		</svg>
	)
}
