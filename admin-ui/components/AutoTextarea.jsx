import { useRef, useLayoutEffect } from '@wordpress/element';

/**
 * Textarea that grows to fit its content (no inner scrollbar). Height is
 * recomputed whenever the value changes — including programmatic updates
 * (copy-from-language, row patches) — not just on keystrokes.
 */
export default function AutoTextarea( { value, onChange, ...rest } ) {
	const ref = useRef( null );

	const resize = () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}
		el.style.height = 'auto';
		el.style.height = `${ el.scrollHeight }px`;
	};

	useLayoutEffect( resize, [ value ] );

	return (
		<textarea
			ref={ ref }
			value={ value }
			onChange={ ( e ) => {
				if ( onChange ) {
					onChange( e );
				}
				resize();
			} }
			{ ...rest }
		/>
	);
}
