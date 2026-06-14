const TOKEN = /(\{\/?\d+\/?\})/g;
const IS_TOKEN = /^\{\/?\d+\/?\}$/;

/**
 * Render text with {n}/{/n}/{n/} markers shown as locked chips (UC-AD14). The
 * chips are visual only; the API validates their presence on save.
 */
export default function Placeholders( { text } ) {
	const parts = String( text || '' ).split( TOKEN );

	return (
		<>
			{ parts.map( ( part, i ) =>
				IS_TOKEN.test( part ) ? (
					<span className="tl-chip" key={ i }>
						{ part }
					</span>
				) : (
					<span key={ i }>{ part }</span>
				)
			) }
		</>
	);
}
