<?php

namespace TransLangs\Language\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Domain\LocaleNormalizer;

/**
 * Option-backed language registry.
 *
 * There is no languages table: no FK, join, or SQL integrity rule a table
 * would satisfy. Config is two autoloaded, pipe-delimited code lists:
 *
 *   translangs_active_lang    'fr_FR|ar'       currently enabled targets
 *   translangs_existing_lang  'fr_FR|ar|es_ES' every target ever added (append-only)
 *
 * Languages are identified by code everywhere — tl_translation stores the code
 * directly, so no numeric dictionary is needed. The existing roster is
 * append-only: a soft-deleted language leaves the active list but stays in
 * existing, so its translations (keyed by code) survive and re-enabling is
 * instant.
 *
 * Display metadata is stored when a locale is added from WordPress' language
 * catalog, so runtime requests do not depend on fetching that catalog again.
 *
 * Source language is the normalized site locale, captured ONCE into the
 * translangs_source_locale option at setup. It is read from that option
 * at runtime — never live from get_locale(), which is filtered and can switch
 * mid-request, which would silently re-key everything. Never stored as a target.
 *
 * Concurrent admin writes are last-write-wins on the options — acceptable for a
 * settings screen touched a few times a year.
 */
final class OptionLanguageRegistry implements LanguageRegistry
{
    public const OPTION_ACTIVE          = 'translangs_active_lang';
    public const OPTION_EXISTING        = 'translangs_existing_lang';
    public const OPTION_SOURCE_LOCALE   = 'translangs_source_locale';
    public const OPTION_SOURCE_METADATA = 'translangs_source_language';

    public function getDefaultLanguage(): string
    {
        $stored = (string) get_option(self::OPTION_SOURCE_LOCALE, '');

        // Fallback to the live locale only until the option is seeded at setup.
        return $stored !== '' ? LocaleNormalizer::normalize($stored) : LocaleNormalizer::current();
    }

    /** @return array{code: string, language: string, region: string, name: string, display_name: string, direction: string} */
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

    /** @return string[] */
    public function getTargetLanguages(): array
    {
        return $this->readList(self::OPTION_ACTIVE);
    }

    /** @return array<string, array{name: string, native: string, dir: string}> */
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

    /** @return string[] */
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

    /** @return string[] */
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

        // Append-only roster: a code only ever joins existing, never leaves here.
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

    /**
     * Fully retire a code from both rosters — unlike disableLanguage(), which
     * deliberately keeps a soft-disabled target in `existing` so re-enabling is
     * instant. Used when a target is promoted to source: it is not a retired
     * target, it IS the source now, so it should not linger in either list.
     */
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

    /** @return string[] */
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

    /**
     * Dev-only reset tool: wipe the target roster and the frozen source locale,
     * back to the just-activated state. Call TranslationMemorySchema::setDefaultOptions()
     * afterward to re-seed the source locale, same as a fresh activation.
     */
    public function resetToDefaults(): void
    {
        delete_option(self::OPTION_SOURCE_LOCALE);
        delete_option(self::OPTION_SOURCE_METADATA);
        $this->writeList(self::OPTION_ACTIVE, []);
        $this->writeList(self::OPTION_EXISTING, []);
    }

    /** @return string[] */
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

    /** @param string[] $codes */
    private function writeList(string $option, array $codes): void
    {
        update_option($option, implode('|', $codes));
    }

    private function sanitize(string $code): string
    {
        return LocaleNormalizer::normalize($code);
    }
}
