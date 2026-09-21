<?php

namespace LingoWP\Shared\Parsing;

final class TranslationSubmissionGuard
{
    private function __construct() {}

    public static function validate(string $source, string $submission, ?string $metaKey = null): array
    {
        $errors = UnitStructureGuard::validate($source, $submission);
        $errors = array_merge($errors, ShortcodeGuard::validate($source, $submission));

        if ($metaKey !== null && SeoTemplateVariables::supports($metaKey)) {
            $errors = array_merge($errors, self::variables($metaKey, $source, $submission));
        }

        return $errors;
    }

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

    private static function countByToken(array $tokens): array
    {
        $counts = [];
        foreach ($tokens as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }
}
