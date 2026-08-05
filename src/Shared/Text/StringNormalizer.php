<?php

namespace TransLangs\Shared\Text;

/**
 * Makes strings deterministic before hashing and storage.
 *
 * The same logical text always produces the same hash:
 * "About us", " About us ", and "About\r\n  us" all normalize to "About us".
 *
 * Order is load-bearing:
 *   1. strip \r  (deleted outright — unlike \n, it leaves no space behind)
 *   2. decode HTML entities as UTF-8 (must precede steps 3 and 5: &Eacute; has
 *      to fold to é, and &#10; has to end up collapsed to a single space)
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
 * Note: PHP's html_entity_decode follows the HTML5 spec and does NOT decode
 * &#13; (CR), though it does decode &#10;, &#9; and &#32;. A literal "&#13;" in
 * source text therefore survives verbatim into the hash. Deterministic, so the
 * contract holds — but do not assume entity decoding covers carriage returns.
 *
 * FROZEN CONTRACT: original_hash links parent rows to children, so this
 * algorithm defines row relationships. It is the ONE shared normalizer used by
 * discovery, the render resolvers, gettext capture, and the HTML parser —
 * identical bytes in, identical hash out, everywhere. Changing any rule re-hashes
 * every string, so existing tl_* rows must be re-discovered (drop the tables) or
 * migrated by hand; there is no automatic versioning.
 */
final class StringNormalizer
{
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
