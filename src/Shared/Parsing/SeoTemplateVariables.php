<?php

namespace LingoWP\Shared\Parsing;

final class SeoTemplateVariables
{
    private const DESCRIPTORS = [];

    private const GRAMMARS = [];

    private static function descriptors(): array
    {
        $descriptors = function_exists('apply_filters')
            ? apply_filters('lingowp_seo_template_descriptors', self::DESCRIPTORS)
            : self::DESCRIPTORS;

        return is_array($descriptors) ? $descriptors : self::DESCRIPTORS;
    }

    private static function grammars(): array
    {
        $grammars = function_exists('apply_filters')
            ? apply_filters('lingowp_seo_template_grammars', self::GRAMMARS)
            : self::GRAMMARS;

        return is_array($grammars) ? $grammars : self::GRAMMARS;
    }

    private const MAX_TEMPLATE_LENGTH = 1000;
    private const MAX_VARIABLES       = 20;

    private function __construct() {}

    public static function pattern(string $metaKey, string $template): ?array
    {
        if (!self::supports($metaKey) || $template === '' || strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            return null;
        }

        $grammar = self::descriptors()[$metaKey]['grammar'];
        if (preg_match_all(self::grammars()[$grammar], $template, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $found = $matches[0];
        if ($found === [] || count($found) > self::MAX_VARIABLES) {
            return null;
        }

        $regex              = '';
        $tokens             = [];
        $cursor             = 0;
        $literalText        = '';
        $previousWasCapture = false;

        foreach ($found as $i => [$token, $offset]) {
            $literal = substr($template, $cursor, $offset - $cursor);

            if ($literal === '' && $previousWasCapture) {
                return null;
            }

            $literalText       .= $literal;
            $regex             .= preg_quote($literal, '/') . '(?<v' . $i . '>.+?)';
            $tokens['v' . $i]   = $token;
            $cursor             = $offset + strlen($token);
            $previousWasCapture = true;
        }

        $tail         = substr($template, $cursor);
        $literalText .= $tail;
        $regex       .= preg_quote($tail, '/');

        if (preg_match('/\p{L}/u', $literalText) !== 1) {
            return null;
        }

        return ['regex' => '/^' . $regex . '$/u', 'tokens' => $tokens];
    }

    public static function tokenName(string $token): string
    {
        return strtolower(trim($token, '#% '));
    }

    public static function supports(string $metaKey): bool
    {
        return isset(self::descriptors()[$metaKey]);
    }

    public static function fieldFor(string $metaKey): ?string
    {
        return self::descriptors()[$metaKey]['field'] ?? null;
    }

    public static function extract(string $metaKey, string $text): array
    {
        $grammar = self::descriptors()[$metaKey]['grammar'] ?? null;
        if ($grammar === null) {
            return [];
        }

        preg_match_all(self::grammars()[$grammar], $text, $matches);

        return $matches[0];
    }

    public static function hasTranslatableProse(string $metaKey, string $text): bool
    {
        if (!self::supports($metaKey)) {
            return true;
        }

        $grammar = self::descriptors()[$metaKey]['grammar'];
        $bare    = preg_replace(self::grammars()[$grammar], '', $text) ?? $text;

        return trim($bare) !== '' && preg_match('/\p{L}/u', $bare) === 1;
    }
}
