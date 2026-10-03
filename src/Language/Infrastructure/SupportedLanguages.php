<?php

namespace LingoWP\Language\Infrastructure;

/**
 * Compatibility facade over WordPress's Site Language catalog.
 */
class SupportedLanguages
{
    private LanguageMetadataStore $store;

    public function __construct(LanguageMetadataStore $store)
    {
        $this->store = $store;
    }

    /**
     * @return array<string, array{name: string, native: string, dir: string}>
     */
    public static function all(): array
    {
        return array_map(static fn(array $item): array => [
            'name'   => $item['english_name'],
            'native' => $item['native_name'],
            'dir'    => $item['direction'],
        ], WordPressLanguageCatalog::all());
    }

    public function label(string $code): string
    {
        $stored = $this->store->get($code);
        if ($stored !== null && !empty($stored['name'])) {
            return (string) $stored['name'];
        }
        $all = self::all();

        return $all[$code]['name'] ?? strtoupper($code);
    }

    /**
     * The language written in its own language, with its region:
     * "Português (Brasil)", "Français (Canada)", "日本語". Every visitor- and
     * admin-facing surface shows this. A user-typed workspace name is a
     * separate label that lives only on the workspace board — it is applied
     * there, never here.
     *
     * Falls back to the stored English name, then the uppercased code.
     */
    public function nativeLabel(string $code): string
    {
        $stored   = $this->store->get($code);
        $fallback = (string) ($stored['name'] ?? '');

        $native = WordPressLanguageCatalog::nativeDisplay($code, $fallback);
        if ($native !== '') {
            return $native;
        }

        $all = self::all();

        return $all[$code]['native'] ?? ($all[$code]['name'] ?? strtoupper($code));
    }

    /**
     * Text direction for a language: 'rtl' or 'ltr'. Stored metadata wins, then
     * WordPress's catalog, then 'ltr'.
     */
    public function direction(string $code): string
    {
        $stored = $this->store->get($code);
        if ($stored !== null && !empty($stored['direction'])) {
            return $stored['direction'] === 'rtl' ? 'rtl' : 'ltr';
        }

        return (self::all()[$code]['dir'] ?? '') === 'rtl' ? 'rtl' : 'ltr';
    }

    public function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
