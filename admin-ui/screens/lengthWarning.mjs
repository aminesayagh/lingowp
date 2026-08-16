// Goal 4.8 (length warnings) — flag a translation whose length deviates sharply
// from its original, rather than checking against a fixed per-field character
// range. A flat percentage breaks at both ends: long strings commonly expand
// 20-35% in German/French/Spanish and shrink 30-50% in CJK languages, so a
// tight percentage would flag routine translations; short strings are
// dominated by percentage noise ("Go" -> "Aller" is +150% but 3 characters),
// so a minimum character floor keeps those quiet unless the absolute
// difference is real.
const RELATIVE_THRESHOLD = 0.3
const MIN_CHAR_FLOOR = 15

/**
 * @param {string} original
 * @param {string} translated
 * @return {string|null} a warning message, or null when within tolerance.
 */
export function lengthWarningMessage( original, translated ) {
	const originalLen = Array.from( original || '' ).length
	const translatedLen = Array.from( translated || '' ).length
	if ( originalLen === 0 || translatedLen === 0 ) {
		return null
	}

	const delta = translatedLen - originalLen
	const allowed = Math.max( originalLen * RELATIVE_THRESHOLD, MIN_CHAR_FLOOR )
	if ( Math.abs( delta ) <= allowed ) {
		return null
	}

	const direction = delta > 0 ? 'longer' : 'shorter'
	return `Translation is much ${ direction } than the original.`
}
