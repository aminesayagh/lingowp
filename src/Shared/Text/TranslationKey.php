<?php

namespace LingoWP\Shared\Text;

final class TranslationKey
{
    private function __construct() {}

    public static function currentHash(string $text): string
    {
        return hash('sha256', self::normalize($text), true);
    }

    public static function normalize(string $text): string
    {
        return StringNormalizer::normalize($text);
    }
}
