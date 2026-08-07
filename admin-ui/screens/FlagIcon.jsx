import { Icon } from '@shopify/polaris'
import { GlobeIcon } from '@shopify/polaris-icons'
import { useState } from '@wordpress/element'

// Country code fallback per language code, for locales whose region can't be
// resolved any other way (LocaleNormalizer::region() returns '' for a locale
// with no region segment — real for some catalog entries, not hypothetical).
const REGION_FALLBACK = {
	fr: 'FR',
	es: 'ES',
	de: 'DE',
	it: 'IT',
	pt: 'PT',
	nl: 'NL',
	ar: 'MA',
	en: 'US',
}

/**
 * Resolve a language to its flag image URL, or null when no 2-letter region
 * can be determined at all (globe fallback territory).
 * @param {Object} lang Language metadata.
 */
export function flagIconUrl( lang ) {
	let cc = ( lang.region || '' ).trim()
	if ( cc.length !== 2 && lang.locale ) {
		cc = ( lang.locale.split( /[_-]/ )[ 1 ] || '' ).trim()
	}
	if ( cc.length !== 2 ) {
		cc = REGION_FALLBACK[ lang.code ] || ''
	}
	if ( cc.length !== 2 ) {
		return null
	}

	return `${
		window.Translangs?.flags_base_url || ''
	}${ cc.toLowerCase() }.svg`
}

// Flags are 4:3, not square — a fixed height with width derived from it avoids
// squashing them into a square box.
// `grayscale`: draft languages show a desaturated flag so their unpublished
// state is visible at a glance, without a separate badge.
export function FlagIcon( { src, size = 16, grayscale = false } ) {
	const [ broken, setBroken ] = useState( false )

	if ( ! src || broken ) {
		return (
			<span
				style={ { display: 'inline-flex', width: size, height: size } }
			>
				<Icon source={ GlobeIcon } tone="subdued" />
			</span>
		)
	}

	return (
		<img
			src={ src }
			alt=""
			onError={ () => setBroken( true ) }
			style={ {
				height: size,
				width: Math.round( ( size * 4 ) / 3 ),
				borderRadius: 2,
				objectFit: 'cover',
				flexShrink: 0,
				filter: grayscale ? 'grayscale(0.8)' : undefined,
			} }
		/>
	)
}
