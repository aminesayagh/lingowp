import { Icon } from '@shopify/polaris'
import { GlobeIcon } from '@shopify/polaris-icons'
import { useState } from '@wordpress/element'

// Flag fallback per language code, for WordPress locales that carry no region
// segment (LocaleNormalizer::region() returns '' — real for ~56 catalog
// entries: Greek `el`, Japanese `ja`, Finnish `fi`, Esperanto `eo`, …). Values
// are a flag-icons filename stem: a 2-letter country code, a subdivision slug
// (`gb-wls`, `es-ct`, `es-pv`, `gb-sct`), or `eo` for the bundled Esperanto
// flag. Only used when the locale itself has no region — a regioned locale
// like fr_CA still resolves to `ca`, not the `fr` fallback.
const REGION_FALLBACK = {
	fr: 'FR',
	es: 'ES',
	de: 'DE',
	it: 'IT',
	pt: 'PT',
	nl: 'NL',
	ar: 'MA',
	en: 'US',
	af: 'ZA',
	am: 'ET',
	arg: 'ES',
	ary: 'MA',
	as: 'IN',
	az: 'AZ',
	azb: 'IR',
	bel: 'BY',
	ca: 'es-ct',
	ceb: 'PH',
	ckb: 'IQ',
	cy: 'gb-wls',
	dsb: 'DE',
	dzo: 'BT',
	el: 'GR',
	eo: 'EO',
	et: 'EE',
	eu: 'es-pv',
	fi: 'FI',
	fur: 'IT',
	fy: 'NL',
	gd: 'gb-sct',
	gu: 'IN',
	haz: 'AF',
	hr: 'HR',
	hsb: 'DE',
	hy: 'AM',
	ja: 'JP',
	kab: 'DZ',
	kir: 'KG',
	kk: 'KZ',
	km: 'KH',
	kn: 'IN',
	lo: 'LA',
	lv: 'LV',
	mn: 'MN',
	mr: 'IN',
	oci: 'FR',
	pcm: 'NG',
	ps: 'AF',
	sah: 'RU',
	skr: 'PK',
	snd: 'PK',
	sq: 'AL',
	sw: 'TZ',
	szl: 'PL',
	tah: 'PF',
	te: 'IN',
	th: 'TH',
	tl: 'PH',
	uk: 'UA',
	ur: 'PK',
	vi: 'VN',
	yor: 'NG',
}

/**
 * Resolve a language to its flag image URL, or null when nothing maps (globe
 * fallback territory — e.g. Tibetan `bo`, Rohingya `rhg`).
 * @param {Object} lang Language metadata.
 */
export function flagIconUrl( lang ) {
	let cc = ( lang.region || '' ).trim()
	if ( cc.length !== 2 && lang.locale ) {
		cc = ( lang.locale.split( /[_-]/ )[ 1 ] || '' ).trim()
	}
	if ( cc.length !== 2 ) {
		// Curated fallback — its values may be a subdivision slug, not just a
		// 2-letter code, so this branch isn't length-checked.
		cc = REGION_FALLBACK[ lang.code ] || ''
	}
	if ( ! cc ) {
		return null
	}

	return `${ window.LingoWP?.flags_base_url || '' }${ cc.toLowerCase() }.svg`
}

// Flags are 4:3, not square — a fixed height with width derived from it avoids
// squashing them into a square box.
// `grayscale`: draft languages show a desaturated flag so their unpublished
// state is visible at a glance, without a separate badge. Accepts `true`
// (the usual 0.8, sidebar/manager rows) or a 0-1 amount for a lighter touch
// (e.g. the header switcher's popover, where full desaturation read as too
// heavy against that surface).
export function FlagIcon( { src, size = 16, grayscale = false } ) {
	const grayscaleAmount =
		typeof grayscale === 'number' ? grayscale : grayscale ? 0.8 : 0
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
				filter: grayscaleAmount
					? `grayscale(${ grayscaleAmount })`
					: undefined,
			} }
		/>
	)
}
