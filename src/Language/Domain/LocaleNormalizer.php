<?php

namespace TransLangs\Language\Domain;

/**
 * Converts WordPress locale strings to short language codes.
 *
 * e.g. en_US → en, fr_FR → fr, pt_BR → pt, ar → ar.
 */
final class LocaleNormalizer
{
    private function __construct()
    {
    }

    public static function normalize(string $locale): string
    {
        $pos = strpos($locale, '_');
        return $pos !== false ? substr($locale, 0, $pos) : $locale;
    }

    public static function current(): string
    {
        return self::normalize((string) get_locale());
    }
}
