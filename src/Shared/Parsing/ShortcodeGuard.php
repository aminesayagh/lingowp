<?php

namespace LingoWP\Shared\Parsing;

/**
 * Enforces that a submitted translation keeps the same registered shortcode
 * tags as its source unit (checklist §21 finding: a shortcode embedded in
 * translatable text — e.g. `Book a [contact-form-7 id="5"] today` — had no
 * protection at all; a translation that dropped the shortcode entirely passed
 * validation with zero errors, silently removing whatever it rendered).
 *
 * Same philosophy as UnitStructureGuard for inline HTML tags: STRUCTURE must
 * survive, not exact content. Counted by tag NAME only, not the full bracket
 * text — a translator must stay free to edit a shortcode's own attribute
 * values (some carry translatable text, e.g. title="..."); this only catches
 * the tag being added or removed entirely. Multiset comparison (not
 * UnitStructureGuard's "nothing in source, nothing to guard" shortcut) —
 * see validate()'s own comment for why an ADDED shortcode must be caught too.
 *
 * Uses WordPress's own get_shortcode_regex(), which only matches CURRENTLY
 * REGISTERED shortcode tags — arbitrary bracketed text that isn't a real
 * shortcode (e.g. a citation like "see [1] for details") is never mistaken
 * for one and never has to survive verbatim.
 */
final class ShortcodeGuard
{
    private function __construct() {}

    /**
     * @return string[] error codes, empty array means valid.
     *   missing_shortcode:{tag} / extra_shortcode:{tag}.
     */
    public static function validate(string $source, string $submission): array
    {
        // Deliberately NOT mirroring UnitStructureGuard's "no markers in source →
        // nothing to guard" shortcut (same reasoning as
        // TranslationSubmissionGuard::variables()): a source with NO shortcode
        // that gains one in translation is exactly the dangerous case — an
        // injected shortcode would execute on render just as readily as one
        // that was already there.
        $src    = self::tagCounts($source);
        $sub    = self::tagCounts($submission);
        $errors = [];

        foreach ($src as $tag => $n) {
            if (($sub[$tag] ?? 0) < $n) {
                $errors[] = "missing_shortcode:$tag";
            }
        }
        foreach ($sub as $tag => $n) {
            if (($src[$tag] ?? 0) < $n) {
                $errors[] = "extra_shortcode:$tag";
            }
        }

        return $errors;
    }

    /** @return array<string,int> tag name => occurrence count */
    private static function tagCounts(string $text): array
    {
        if (!function_exists('get_shortcode_regex')) {
            return [];
        }

        preg_match_all('/' . get_shortcode_regex() . '/', $text, $matches);
        $counts = [];
        foreach ($matches[2] as $tag) {
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }

        return $counts;
    }
}
