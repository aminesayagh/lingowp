<?php

namespace TransLangs\Language\Domain;

/**
 * Converts WordPress locale strings to short language codes.
 *
 * e.g. en_US → en, fr_FR → fr, pt_BR → pt, ar → ar.
 */
final class LocaleNormalizer
{
    public function normalize(string $locale): string
    {
        $pos = strpos($locale, '_');
        return $pos !== false ? substr($locale, 0, $pos) : $locale;
    }

    public function current(): string
    {
        return $this->normalize((string) get_locale());
    }
}
