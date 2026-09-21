<?php

namespace LingoWP\Language\Domain;

final class LocaleNormalizer
{
    private function __construct()
    {
    }

    public static function normalize(string $locale): string
    {
        $parts = array_values(array_filter(explode('_', str_replace('-', '_', trim($locale))), 'strlen'));
        if ($parts === [] || !preg_match('/^[A-Za-z]{2,3}$/', $parts[0])) {
            return '';
        }

        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1]) && preg_match('/^[A-Za-z]{2}$/', $parts[1])) {
            $parts[1] = strtoupper($parts[1]);
        }
        for ($i = 2; $i < count($parts); $i++) {
            $parts[$i] = strtolower($parts[$i]);
        }

        return implode('_', $parts);
    }

    public static function language(string $locale): string
    {
        return explode('_', self::normalize($locale))[0] ?? '';
    }

    public static function region(string $locale): string
    {
        $parts = explode('_', self::normalize($locale));

        return isset($parts[1]) && preg_match('/^[A-Z]{2}$/', $parts[1]) ? $parts[1] : '';
    }

    public static function current(): string
    {
        return self::normalize((string) get_locale());
    }
}
