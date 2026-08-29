<?php

namespace LingoWP\Shared\Parsing;

/**
 * The one validation entry point every translation write goes through — manual
 * REST saves and AI batch writes alike (plan-seo-integration-layer §2d).
 *
 * Two writers previously disagreed: TranslationRestController validated inline-tag
 * structure, AiTranslationController validated nothing at all, so the AI could
 * store markup-breaking translations. Composing both checks here is what stops
 * the paths drifting apart again.
 */
final class TranslationSubmissionGuard
{
    private function __construct() {}

    /**
     * @param  string|null $metaKey source meta key when the row is post meta;
     *                              null for post/term/option/html sources.
     * @return string[] error codes, empty array means valid. Inline-tag shapes
     *                  come from UnitStructureGuard; SEO shapes are
     *                  missing_variable:{token} / extra_variable:{token}.
     */
    public static function validate(string $source, string $submission, ?string $metaKey = null): array
    {
        $errors = UnitStructureGuard::validate($source, $submission);

        if ($metaKey !== null && SeoTemplateVariables::supports($metaKey)) {
            $errors = array_merge($errors, self::variables($metaKey, $source, $submission));
        }

        return $errors;
    }

    /**
     * Multiset equality on replacement variables.
     *
     * Deliberately NOT mirroring UnitStructureGuard's "no markers in source →
     * nothing to guard" shortcut: a source with no variables that gains one in
     * translation is exactly the dangerous case. `About our company` translated
     * as `À propos de %title%` would otherwise be accepted, and the SEO plugin
     * would expand the injected variable into the rendered title.
     *
     * @return string[]
     */
    private static function variables(string $metaKey, string $source, string $submission): array
    {
        $src = self::countByToken(SeoTemplateVariables::extract($metaKey, $source));
        $sub = self::countByToken(SeoTemplateVariables::extract($metaKey, $submission));

        $errors = [];
        foreach ($src as $token => $n) {
            if (($sub[$token] ?? 0) < $n) {
                $errors[] = "missing_variable:$token";
            }
        }
        foreach ($sub as $token => $n) {
            if (($src[$token] ?? 0) < $n) {
                $errors[] = "extra_variable:$token";
            }
        }

        return $errors;
    }

    /**
     * @param  string[] $tokens
     * @return array<string,int>
     */
    private static function countByToken(array $tokens): array
    {
        $counts = [];
        foreach ($tokens as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }
}
