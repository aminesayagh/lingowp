<?php

namespace LingoWP\Language\Domain;

/**
 * Maps a WordPress locale to one of the bundled flag-icons SVGs in
 * assets/flags/ (the flag-icons 4:3 set, plus a few subdivision and
 * constructed-language files: gb-wls, gb-sct, es-ct, es-pv, eo, arab).
 *
 * Region comes from the locale itself when it carries one (en_AU -> au); for
 * the ~60 region-less catalog locales (ar, ja, el, cy, eo, …) a curated
 * language -> flag map fills the gap. Anything left unmapped returns null —
 * the caller shows a globe instead.
 *
 * This is the PHP source of truth. admin-ui/screens/FlagIcon.jsx carries a
 * hand-kept copy of REGION_FALLBACK for the React admin; keep the two in sync.
 */
final class FlagResolver
{
    /**
     * language subtag => flag-icons filename stem (a 2-letter country code, a
     * subdivision slug, or `eo`). Only consulted when the locale has no region
     * segment of its own. Mirrors REGION_FALLBACK in FlagIcon.jsx.
     *
     * @var array<string, string>
     */
    private const REGION_FALLBACK = [
        'fr' => 'FR', 'es' => 'ES', 'de' => 'DE', 'it' => 'IT', 'pt' => 'PT',
        'nl' => 'NL', 'ar' => 'arab', 'en' => 'US', 'af' => 'ZA', 'am' => 'ET',
        'arg' => 'ES', 'ary' => 'MA', 'as' => 'IN', 'az' => 'AZ', 'azb' => 'IR',
        'bel' => 'BY', 'ca' => 'es-ct', 'ceb' => 'PH', 'ckb' => 'IQ', 'cy' => 'gb-wls',
        'dsb' => 'DE', 'dzo' => 'BT', 'el' => 'GR', 'eo' => 'EO', 'et' => 'EE',
        'eu' => 'es-pv', 'fi' => 'FI', 'fur' => 'IT', 'fy' => 'NL', 'gd' => 'gb-sct',
        'gu' => 'IN', 'haz' => 'AF', 'hr' => 'HR', 'hsb' => 'DE', 'hy' => 'AM',
        'ja' => 'JP', 'kab' => 'DZ', 'kir' => 'KG', 'kk' => 'KZ', 'km' => 'KH',
        'kn' => 'IN', 'lo' => 'LA', 'lv' => 'LV', 'mn' => 'MN', 'mr' => 'IN',
        'oci' => 'FR', 'pcm' => 'NG', 'ps' => 'AF', 'sah' => 'RU', 'skr' => 'PK',
        'snd' => 'PK', 'sq' => 'AL', 'sw' => 'TZ', 'szl' => 'PL', 'tah' => 'PF',
        'te' => 'IN', 'th' => 'TH', 'tl' => 'PH', 'uk' => 'UA', 'ur' => 'PK',
        'vi' => 'VN', 'yor' => 'NG',
    ];

    private function __construct()
    {
    }

    /**
     * Absolute URL of the bundled flag SVG for a locale, or null when nothing
     * maps or the file is not actually bundled.
     */
    public static function url(string $locale): ?string
    {
        $cc = LocaleNormalizer::region($locale);
        if ($cc === '') {
            $cc = self::REGION_FALLBACK[LocaleNormalizer::language($locale)] ?? '';
        }
        if ($cc === '') {
            return null;
        }

        $file = strtolower($cc) . '.svg';
        if (!file_exists(LINGOWP_DIR . 'assets/flags/' . $file)) {
            return null;
        }

        return LINGOWP_URL . 'assets/flags/' . $file;
    }
}
