<?php

namespace LingoWP\Language\Infrastructure;

use LingoWP\Language\Domain\LocaleNormalizer;

/**
 * Local source of truth for target-language metadata.
 *
 * A single JSON option `lingowp_languages` holds a map keyed by language code.
 * Each entry carries the display/routing metadata the SPA and workspace need:
 *   { code, language, locale, region, name, display_name, slug, fallback_language,
 *     ai_style, direction, created_at, updated_at }
 *
 * Active/enabled runtime state is deliberately NOT stored here — that stays in
 * OptionLanguageRegistry (lingowp_active_lang). The legacy code lists remain a
 * migration bridge; this map is the metadata authority.
 */
final class LanguageMetadataStore
{
    public const OPTION = 'lingowp_languages';

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


    /**
     * Point every configured language at one AI model in a single option write,
     * refreshing updated_at on the ones that change. A per-language PATCH loop
     * from the client races itself — each PATCH is a read-modify-write of this
     * same option, so concurrent requests clobber each other's change. This is
     * the atomic bulk path. Returns how many entries actually changed.
     */
    public function setAiModelForAll(string $model): int
    {
        $map = $this->all();
        $now     = current_time('mysql', true);
        $changed = 0;

        foreach ($map as $code => $entry) {
            if (! is_array($entry) || (string) ($entry['ai_model'] ?? '') === $model) {
                continue;
            }
            $entry['ai_model']   = $model;
            $entry['updated_at'] = $now;
            $map[$code]          = $entry;
            $changed++;
        }

        if ($changed > 0) {
            update_option(self::OPTION, $map);
        }

        return $changed;
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

    /** Dev-only reset tool: drop every language's metadata. */
    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * The backend's per-target-language config block — a REQUIRED field on
     * both /translations and /translations/tasks (Pydantic "field required"
     * 422 if omitted; every AI-translate call site must send it). Provider
     * resolves from ai_model (openai/anthropic pass through, anything else —
     * including the default 'auto' — becomes 'platform'); writing_style is
     * included only when set, matching the backend's optional field.
     *
     * @param string[] $langs
     * @return array<string, array{provider: string, writing_style?: string}>
     */
    public function providerConfig(array $langs): array
    {
        $map = [];
        foreach ($langs as $lang) {
            $meta     = $this->get($lang) ?? [];
            $model    = (string) ($meta['ai_model'] ?? '');
            $provider = in_array($model, ['openai', 'anthropic'], true) ? $model : 'platform';

            $entry = ['provider' => $provider];
            $style = trim((string) ($meta['ai_style'] ?? ''));
            if ($style !== '') {
                $entry['writing_style'] = $style;
            }
            $map[$lang] = $entry;
        }

        return $map;
    }
}
