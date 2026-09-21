<?php

namespace LingoWP\Shared\Text;

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
