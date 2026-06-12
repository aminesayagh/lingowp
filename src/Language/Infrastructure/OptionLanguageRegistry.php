<?php

namespace TransLangs\Language\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Domain\LocaleNormalizer;

/**
 * Option-backed language registry (architecture §3, code-based variant).
 *
 * v4 has no languages table: there is no FK, join, or SQL integrity rule a
 * table would satisfy. Config is two autoloaded, pipe-delimited code lists:
 *
 *   translangs_active_lang    'fr|ar'      currently enabled targets
 *   translangs_existing_lang  'fr|ar|es'   every target ever added (append-only)
 *
 * Languages are identified by code everywhere — tl_translation stores the code
 * directly, so no numeric dictionary is needed. The existing roster is
 * append-only: a soft-deleted language leaves the active list but stays in
 * existing, so its translations (keyed by code) survive and re-enabling is
 * instant.
 *
 * Display metadata comes from SupportedLanguages by code; it is never stored.
 * Source language = get_locale(); never stored, never a target.
 *
 * Concurrent admin writes are last-write-wins on the options — acceptable for a
 * settings screen touched a few times a year.
 */
final class OptionLanguageRegistry implements LanguageRegistry
{
    public const OPTION_ACTIVE   = 'translangs_active_lang';
    public const OPTION_EXISTING = 'translangs_existing_lang';

    public function getDefaultLanguage(): string
    {
        return LocaleNormalizer::current();
    }

    /** @return string[] */
    public function getTargetLanguages(): array
    {
        return $this->readList(self::OPTION_ACTIVE);
    }

    /** @return array<string, array{name: string, native: string, dir: string}> */
    public function getTargetLanguageDetails(): array
    {
        $catalog = SupportedLanguages::all();
        $details = [];

        foreach ($this->getTargetLanguages() as $code) {
            $meta          = $catalog[$code] ?? null;
            $details[$code] = [
                'name'   => $meta['name']   ?? strtoupper($code),
                'native' => $meta['native'] ?? ($meta['name'] ?? strtoupper($code)),
                'dir'    => $meta['dir']    ?? 'ltr',
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

    /** @return string[] */
    public function existingLanguages(): array
    {
        return $this->readList(self::OPTION_EXISTING);
    }

    public function enableLanguage(string $code): void
    {
        $code = $this->sanitize($code);

        if ($code === '' || $code === $this->getDefaultLanguage() || !SupportedLanguages::exists($code)) {
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
     * One-time migration: seed the code lists from the legacy
     * translangs_enabled_languages option. No-op once a roster exists.
     */
    public function seedFromLegacyOption(): void
    {
        if ($this->existingLanguages() !== [] || $this->getTargetLanguages() !== []) {
            return;
        }

        foreach ((array) get_option('translangs_enabled_languages', []) as $code) {
            $this->enableLanguage((string) $code);
        }
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
        return sanitize_key(trim($code));
    }
}
