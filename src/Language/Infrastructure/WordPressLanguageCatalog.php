<?php

namespace TransLangs\Language\Infrastructure;

use TransLangs\Language\Domain\LocaleNormalizer;

/** WordPress core's Site Language catalog, shaped for the TransLangs UI. */
final class WordPressLanguageCatalog
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // WordPress stores the Site Language catalog in this network-level
        // transient. Reading it directly keeps the TransLangs REST request
        // local: wp_get_available_translations() may perform a synchronous
        // WordPress.org request when the transient is cold, which must never
        // hold the workspace response open.
        $cached = get_site_transient('available_translations');
        $translations = is_array($cached) ? $cached : [];
        $translations['en_US'] = $translations['en_US'] ?? [
            'language' => 'en_US', 'english_name' => 'English (United States)',
            'native_name' => 'English (United States)', 'iso' => ['en'],
        ];

        $installed = array_merge(get_available_languages(), [get_locale()]);
        foreach ($installed as $locale) {
            $translations[$locale] = $translations[$locale] ?? [
                'language' => $locale, 'english_name' => $locale,
                'native_name' => $locale, 'iso' => [LocaleNormalizer::language($locale)],
            ];
        }

        $catalog = [];
        foreach ($translations as $key => $translation) {
            $locale = LocaleNormalizer::normalize((string) ($translation['language'] ?? $key));
            if ($locale === '') {
                continue;
            }
            $language = LocaleNormalizer::language($locale);
            $region   = LocaleNormalizer::region($locale);
            $parts    = explode('_', $locale);
            $variant  = implode(' ', array_slice($parts, $region !== '' ? 2 : 1));
            $english  = (string) ($translation['english_name'] ?? $locale);
            $native   = (string) ($translation['native_name'] ?? $english);

            $catalog[$locale] = [
                'locale' => $locale, 'language' => $language, 'region' => $region,
                'language_name' => self::displayLanguage($locale, $english),
                'region_name' => self::displayRegion($locale, $region),
                'variant_name' => $variant === '' ? '' : ucwords(str_replace(['-', '_'], ' ', $variant)),
                'english_name' => $english, 'native_name' => $native,
                'direction' => self::direction($language),
            ];
        }

        uasort($catalog, static fn(array $a, array $b): int => strcasecmp($a['english_name'], $b['english_name']));
        self::$cache = $catalog;
        return self::$cache;
    }

    /** @return array<string, string>|null */
    public static function get(string $locale): ?array
    {
        $locale = LocaleNormalizer::normalize($locale);
        return self::all()[$locale] ?? null;
    }

    private static function displayLanguage(string $locale, string $fallback): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($locale, 'en');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $fallback));
    }

    private static function displayRegion(string $locale, string $fallback): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion($locale, 'en');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return $fallback;
    }

    private static function direction(string $language): string
    {
        return in_array($language, ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'], true)
            ? 'rtl' : 'ltr';
    }
}
