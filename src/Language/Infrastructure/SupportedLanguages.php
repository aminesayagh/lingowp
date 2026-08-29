<?php

namespace LingoWP\Language\Infrastructure;

/**
 * Compatibility facade over WordPress's Site Language catalog.
 */
class SupportedLanguages
{
    public function __construct(private LanguageMetadataStore $store)
    {
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
     * The language's name in its own language, e.g. "Français", "Español".
     * Falls back to the English name, then the uppercased code.
     */
    public function nativeLabel(string $code): string
    {
        $stored = $this->store->get($code);
        if ($stored !== null) {
            return (string) ($stored['display_name'] ?: ($stored['name'] ?? strtoupper($code)));
        }
        $all = self::all();

        return $all[$code]['native'] ?? ($all[$code]['name'] ?? strtoupper($code));
    }

    public function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
