<?php

namespace LingoWP\Language\Infrastructure;

use LingoWP\Language\Domain\LocaleNormalizer;

/** WordPress core's Site Language catalog, shaped for the LingoWP UI. */
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
        // transient. Reading it directly keeps the LingoWP REST request
        // local: wp_get_available_translations() may perform a synchronous
        // WordPress.org request when the transient is cold, which must never
        // hold the workspace response open.
        $cached = get_site_transient('available_translations');
        self::$cache = self::shape(is_array($cached) ? $cached : []);
        return self::$cache;
    }

    /**
     * Ask WordPress for its full core-language catalog.
     *
     * WordPress serves a warm site transient locally and contacts WordPress.org
     * only when that transient is cold. A null result keeps lookup failure
     * distinct from a successfully loaded catalog.
     *
     * @return array<string, array<string, string>>|null
     */
    public static function refresh(): ?array
    {
        require_once ABSPATH . 'wp-admin/includes/translation-install.php';

        $translations = wp_get_available_translations();
        if ($translations === []) {
            return null;
        }

        self::$cache = self::shape($translations);
        return self::$cache;
    }

    /**
     * @param array<string, array<string, mixed>> $translations
     * @return array<string, array<string, string>>
     */
    private static function shape(array $translations): array
    {
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
                'native_display' => self::nativeDisplay($locale, $native),
                'direction' => self::direction($language),
            ];
        }

        uasort($catalog, static fn(array $a, array $b): int => strcasecmp($a['english_name'], $b['english_name']));
        return $catalog;
    }

    /** @return array<string, string>|null */
    public static function get(string $locale): ?array
    {
        $locale = LocaleNormalizer::normalize($locale);
        return self::all()[$locale] ?? null;
    }

    /**
     * The language's own name for itself, plus its region written in that same
     * language: "Português (Brasil)", "Français (Canada)", "日本語". This is the
     * canonical label every language-name surface shows — the front-end
     * switcher, the admin language list, the pickers. A user-typed workspace
     * name is a separate thing and never replaces this.
     *
     * Built from PHP's ICU data so the region word is in the language itself
     * ("Brasil", not "Brazil") — it needs only the locale string, never the
     * WordPress catalog, so it is safe on a cold front-end request. Without the
     * intl extension it returns $fallback (WordPress's native_name, which
     * already carries a region for many locales, e.g. "Français du Canada").
     */
    public static function nativeDisplay(string $locale, string $fallback = ''): string
    {
        return self::displayIn($locale, $locale, $fallback);
    }

    /**
     * A locale's name written in another language: displayIn('ar', 'fr_FR') is
     * "Arabe", displayIn('pt_BR', 'en_US') is "Portuguese (Brazil)". Same shape
     * and capitalization as nativeDisplay(), which is this with both locales
     * equal. Used by the switcher's "name in the page's language" mode.
     * Without the intl extension it returns $fallback.
     */
    public static function displayIn(string $locale, string $displayLocale, string $fallback = ''): string
    {
        if (!class_exists(\Locale::class)) {
            return $fallback;
        }

        $language = (string) \Locale::getDisplayLanguage(LocaleNormalizer::language($locale), $displayLocale);
        if ($language === '') {
            return $fallback;
        }
        // ICU lowercases language names in French, Portuguese, Spanish, …
        // ("français"); every surface here wants it capitalized. Non-cased
        // scripts (Japanese, Arabic) are unaffected.
        $language = mb_strtoupper(mb_substr($language, 0, 1)) . mb_substr($language, 1);

        $regionName = (string) \Locale::getDisplayRegion($locale, $displayLocale);

        return $regionName !== '' ? $language . ' (' . $regionName . ')' : $language;
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
