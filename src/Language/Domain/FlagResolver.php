<?php

namespace LingoWP\Language\Domain;

final class FlagResolver
{
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
