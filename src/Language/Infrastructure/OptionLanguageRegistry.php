<?php

namespace LingoWP\Language\Infrastructure;

use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Language\Domain\LocaleNormalizer;

final class OptionLanguageRegistry implements LanguageRegistry
{
    public const OPTION_ACTIVE          = 'lingowp_active_lang';
    public const OPTION_EXISTING        = 'lingowp_existing_lang';
    public const OPTION_SOURCE_LOCALE   = 'lingowp_source_locale';
    public const OPTION_SOURCE_METADATA = 'lingowp_source_language';

    public function getDefaultLanguage(): string
    {
        $stored = (string) get_option(self::OPTION_SOURCE_LOCALE, '');

        return $stored !== '' ? LocaleNormalizer::normalize($stored) : LocaleNormalizer::current();
    }

    public function getDefaultLanguageDetails(): array
    {
        $code   = $this->getDefaultLanguage();
        $stored = get_option(self::OPTION_SOURCE_METADATA, []);

        if (is_array($stored) && ($stored['code'] ?? '') === $code && !empty($stored['display_name'])) {
            return $stored;
        }

        $catalog = WordPressLanguageCatalog::get($code);
        $languageName = (string) ($catalog['language_name'] ?? $code);
        $regionName   = (string) ($catalog['region_name'] ?? '');
        $name         = (string) ($catalog['english_name'] ?? '');

        if ($name === '' || $name === $code) {
            $name = $regionName !== '' ? sprintf('%s (%s)', $languageName, $regionName) : $languageName;
        }

        $details = [
            'code'         => $code,
            'language'     => (string) ($catalog['language'] ?? LocaleNormalizer::language($code)),
            'region'       => (string) ($catalog['region'] ?? LocaleNormalizer::region($code)),
            'name'         => $name,
            'display_name' => $name,
            'direction'    => (string) ($catalog['direction'] ?? 'ltr'),
        ];

        update_option(self::OPTION_SOURCE_METADATA, $details);

        return $details;
    }

    public function getTargetLanguages(): array
    {
        return $this->readList(self::OPTION_ACTIVE);
    }

    public function getTargetLanguageDetails(): array
    {
        $catalog = (new LanguageMetadataStore())->all();
        $details = [];

        foreach ($this->getTargetLanguages() as $code) {
            $meta          = $catalog[$code] ?? null;
            $details[$code] = [
                'name'   => $meta['name'] ?? strtoupper($code),
                'native' => !empty($meta['display_name']) ? $meta['display_name'] : ($meta['name'] ?? strtoupper($code)),
                'dir'    => $meta['direction'] ?? 'ltr',
            ];
        }

        return $details;
    }

    public function getEnabledLanguages(): array
    {
        return array_values(array_unique(
            array_merge([$this->getDefaultLanguage()], $this->getTargetLanguages())
        ));
    }

    public function isEnabledLanguage(string $code): bool
    {
        return $code !== '' && in_array($code, $this->getEnabledLanguages(), true);
    }

    public function slugForLanguage(string $code): string
    {
        $code = $this->sanitize($code);
        $meta = (new LanguageMetadataStore())->get($code);

        return sanitize_title((string) ($meta['slug'] ?? str_replace('_', '-', strtolower($code))));
    }

    public function languageForSlug(string $slug): ?string
    {
        $slug = sanitize_title($slug);
        foreach ($this->getEnabledLanguages() as $code) {
            if ($this->slugForLanguage($code) === $slug) {
                return $code;
            }
        }

        return null;
    }

    public function existingLanguages(): array
    {
        return $this->readList(self::OPTION_EXISTING);
    }

    public function enableLanguage(string $code): void
    {
        $code = $this->sanitize($code);

        if ($code === '' || $code === $this->getDefaultLanguage()) {
            return;
        }

        $active = $this->getTargetLanguages();
        if (!in_array($code, $active, true)) {
            $active[] = $code;
            $this->writeList(self::OPTION_ACTIVE, $active);
        }

        $existing = $this->existingLanguages();
        if (!in_array($code, $existing, true)) {
            $existing[] = $code;
            $this->writeList(self::OPTION_EXISTING, $existing);
        }
    }

    public function disableLanguage(string $code): void
    {
        $code   = $this->sanitize($code);
        $active = $this->getTargetLanguages();

        $remaining = array_values(array_filter(
            $active,
            static fn(string $c): bool => $c !== $code
        ));

        if ($remaining !== $active) {
            $this->writeList(self::OPTION_ACTIVE, $remaining);
        }
    }

    public function forgetLanguage(string $code): void
    {
        $code = $this->sanitize($code);

        $active = array_values(array_filter(
            $this->getTargetLanguages(),
            static fn(string $c): bool => $c !== $code
        ));
        $this->writeList(self::OPTION_ACTIVE, $active);

        $existing = array_values(array_filter(
            $this->existingLanguages(),
            static fn(string $c): bool => $c !== $code
        ));
        $this->writeList(self::OPTION_EXISTING, $existing);
    }

    public function fallbackChain(string $code): array
    {
        $default = $this->getDefaultLanguage();
        $store   = new LanguageMetadataStore();
        $chain   = [];
        $seen    = [$code => true, $default => true];
        $current = $code;

        while (true) {
            $next = (string) ($store->get($current)['fallback_language'] ?? '');
            if ($next === '' || isset($seen[$next])) {
                break;
            }
            $chain[]     = $next;
            $seen[$next] = true;
            $current     = $next;
        }

        return $chain;
    }

    public function resolutionChain(string $code): array
    {
        return array_merge([$code], $this->fallbackChain($code));
    }

    public function resetToDefaults(): void
    {
        delete_option(self::OPTION_SOURCE_LOCALE);
        delete_option(self::OPTION_SOURCE_METADATA);
        $this->writeList(self::OPTION_ACTIVE, []);
        $this->writeList(self::OPTION_EXISTING, []);
    }

    private function readList(string $option): array
    {
        $raw = (string) get_option($option, '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map([$this, 'sanitize'], explode('|', $raw)),
            static fn(string $c): bool => $c !== ''
        )));
    }

    private function writeList(string $option, array $codes): void
    {
        update_option($option, implode('|', $codes));
    }

    private function sanitize(string $code): string
    {
        return LocaleNormalizer::normalize($code);
    }
}
