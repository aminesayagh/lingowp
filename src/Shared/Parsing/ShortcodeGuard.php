<?php

namespace LingoWP\Shared\Parsing;

final class ShortcodeGuard
{
    private function __construct() {}

    public static function validate(string $source, string $submission): array
    {
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
