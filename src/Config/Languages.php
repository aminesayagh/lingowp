<?php

namespace TransLangs\Support;

/**
 * Static catalog of supported languages.
 *
 * Keeps a single source of truth for code => name/native/direction, so the
 * settings screen, languages screen, and switcher all agree. Expand as needed.
 */
class Languages
{
    /**
     * @return array<string, array{name: string, native: string, dir: string}>
     */
    public static function all(): array
    {
        return [
            'en' => ['name' => 'English',    'native' => 'English',  'dir' => 'ltr'],
            'fr' => ['name' => 'French',     'native' => 'Français', 'dir' => 'ltr'],
            'es' => ['name' => 'Spanish',    'native' => 'Español',  'dir' => 'ltr'],
            'de' => ['name' => 'German',     'native' => 'Deutsch',  'dir' => 'ltr'],
            'it' => ['name' => 'Italian',    'native' => 'Italiano', 'dir' => 'ltr'],
            'pt' => ['name' => 'Portuguese', 'native' => 'Português','dir' => 'ltr'],
            'nl' => ['name' => 'Dutch',      'native' => 'Nederlands','dir' => 'ltr'],
            'ar' => ['name' => 'Arabic',     'native' => 'العربية',  'dir' => 'rtl'],
        ];
    }

    public static function label(string $code): string
    {
        $all = self::all();

        return $all[$code]['name'] ?? strtoupper($code);
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
