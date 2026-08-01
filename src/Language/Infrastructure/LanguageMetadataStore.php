<?php

namespace TransLangs\Language\Infrastructure;

use TransLangs\Language\Domain\LocaleNormalizer;

/**
 * Local source of truth for target-language metadata (v1 §P3).
 *
 * A single JSON option `translangs_languages` holds a map keyed by language code.
 * Each entry carries the display/routing metadata the SPA and workspace need:
 *   { code, language, locale, region, name, display_name, slug, fallback_language,
 *     ai_style, direction, created_at, updated_at }
 *
 * Active/enabled runtime state is deliberately NOT stored here — that stays in
 * OptionLanguageRegistry (translangs_active_lang). The legacy code lists remain a
 * migration bridge; this map is the metadata authority.
 */
final class LanguageMetadataStore
{
    public const OPTION = 'translangs_languages';

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $raw = get_option(self::OPTION, []);

        return is_array($raw) ? $raw : [];
    }

    public function has(string $code): bool
    {
        return isset($this->all()[LocaleNormalizer::normalize($code)]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $code): ?array
    {
        return $this->all()[LocaleNormalizer::normalize($code)] ?? null;
    }

    /** @return string[] */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    /**
     * Upsert one language by code. created_at is preserved on update; updated_at
     * is always refreshed. Unknown keys are dropped to keep the shape stable.
     *
     * @param array<string, mixed> $entry
     */
    public function save(array $entry): void
    {
        $code = LocaleNormalizer::normalize((string) ($entry['code'] ?? ''));
        if ($code === '') {
            return;
        }

        $map      = $this->all();
        $existing = $map[$code] ?? [];
        $now      = current_time('mysql', true);

        $map[$code] = [
            'code'              => $code,
            'language'          => (string) ($entry['language'] ?? LocaleNormalizer::language($code)),
            'locale'            => (string) ($entry['locale'] ?? ''),
            'region'            => (string) ($entry['region'] ?? ''),
            'name'              => (string) ($entry['name'] ?? ''),
            'display_name'      => (string) ($entry['display_name'] ?? ''),
            'slug'              => (string) ($entry['slug'] ?? $code),
            'fallback_language' => (string) ($entry['fallback_language'] ?? ''),
            'ai_style'          => mb_substr((string) ($entry['ai_style'] ?? ''), 0, 500),
            'ai_model'          => (string) ($entry['ai_model'] ?? 'auto'),
            'direction'         => ($entry['direction'] ?? '') === 'rtl' ? 'rtl' : 'ltr',
            'created_at'        => (string) ($existing['created_at'] ?? $now),
            'updated_at'        => $now,
        ];

        update_option(self::OPTION, $map);
    }


    /** Remove one language's metadata entry. No-op if absent. */
    public function remove(string $code): void
    {
        $code = LocaleNormalizer::normalize($code);
        $map  = $this->all();

        if (isset($map[$code])) {
            unset($map[$code]);
            update_option(self::OPTION, $map);
        }
    }
}
