<?php

namespace LingoWP\Language\Infrastructure;

class SupportedLanguages
{
    private LanguageMetadataStore $store;

    public function __construct(LanguageMetadataStore $store)
    {
        $this->store = $store;
    }

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
