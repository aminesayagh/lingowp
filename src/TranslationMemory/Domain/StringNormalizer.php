<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Makes strings deterministic before hashing and storage.
 *
 * The same logical text always produces the same hash:
 * "About us", " About us ", and "About\r\n  us" all normalize to "About us".
 *
 * Order is load-bearing:
 *   1. strip \r  (before entity decode, so &#13; is handled by step 2)
 *   2. decode HTML entities as UTF-8
 *   3. trim leading/trailing whitespace
 *   4. collapse internal whitespace runs to a single space
 */
class StringNormalizer
{
    public function normalize(string $text): string
    {
        $text = str_replace("\r", '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return $text;
    }
}
