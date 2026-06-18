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
 *   3. lowercase (UTF-8 aware), so "About Us" and "about us" share one slot
 *   4. trim leading/trailing whitespace
 *   5. collapse internal whitespace runs to a single space
 *
 * Lowercasing is intentional: display text is kept separately on the parent
 * (original_text), so folding case only affects matching, not what is shown.
 * Accents are deliberately KEPT — they are lexical in the languages we translate
 * (fr "ou"/"où", es "si"/"sí"), so stripping them would silently merge strings
 * that need different translations.
 *
 * FROZEN CONTRACT (v5 §6): original_hash links parent rows to children, so this
 * algorithm defines row relationships. It is the ONE shared normalizer used by
 * discovery, the render resolvers, gettext capture, and the HTML parser —
 * identical bytes in, identical hash out, everywhere. Any change to the rules
 * MUST bump HASH_ALGORITHM_VERSION and ship a re-hash migration; otherwise
 * previously-stored hashes silently stop matching freshly-computed ones.
 */
final class StringNormalizer
{
    /** Stamped on every parent row; bump (+ re-hash migration) on any rule change. */
    public const HASH_ALGORITHM_VERSION = 2;

    private function __construct() {}

    public static function normalize(string $text): string
    {
        $text = str_replace("\r", '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return $text;
    }
}
