<?php

namespace LingoWP\Language\Infrastructure;

use LingoWP\Language\Domain\LocaleNormalizer;

final class LanguageMetadataStore
{
    public const OPTION = 'lingowp_languages';

    public function all(): array
    {
        $raw = get_option(self::OPTION, []);

        return is_array($raw) ? $raw : [];
    }

    public function has(string $code): bool
    {
        return isset($this->all()[LocaleNormalizer::normalize($code)]);
    }

    public function get(string $code): ?array
    {
        return $this->all()[LocaleNormalizer::normalize($code)] ?? null;
    }

    public function codes(): array
    {
        return array_keys($this->all());
    }

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

    public function setAiConfigForAll(string $model, ?string $style = null): int
    {
        $map = $this->all();
        $now     = current_time('mysql', true);
        $changed = 0;

        foreach ($map as $code => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $modelChanged = (string) ($entry['ai_model'] ?? '') !== $model;
            $styleChanged = $style !== null && (string) ($entry['ai_style'] ?? '') !== $style;
            if (! $modelChanged && ! $styleChanged) {
                continue;
            }
            $entry['ai_model'] = $model;
            if ($style !== null) {
                $entry['ai_style'] = $style;
            }
            $entry['updated_at'] = $now;
            $map[$code]          = $entry;
            $changed++;
        }

        if ($changed > 0) {
            update_option(self::OPTION, $map);
        }

        return $changed;
    }

    public function remove(string $code): void
    {
        $code = LocaleNormalizer::normalize($code);
        $map  = $this->all();

        if (isset($map[$code])) {
            unset($map[$code]);
            update_option(self::OPTION, $map);
        }
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    public function providerConfig(array $langs): array
    {
        $map = [];
        foreach ($langs as $lang) {
            $meta     = $this->get($lang) ?? [];
            $model    = (string) ($meta['ai_model'] ?? '');
            $provider = in_array($model, ['openai', 'anthropic'], true) ? $model : 'platform';

            $entry = ['provider' => $provider];
            $style = trim((string) ($meta['ai_style'] ?? ''));
            if ($provider === 'platform' && $style !== '') {
                $entry['writing_style'] = $style;
            }
            $map[$lang] = $entry;
        }

        return $map;
    }
}
