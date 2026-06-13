<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\GettextSource;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Domain\TL_Key;

/**
 * v4 gettext (type 4) resolution + render-time discovery (data pipeline §1 row
 * 11, Path C).
 *
 * On the gettext filters this both (a) resolves the string to its translation by
 * key and (b) collects unknown strings as discovery candidates, flushed to
 * wp_tl_source after the response. Gettext identity is textual (the key tail is
 * the text hash), so the live text IS hashed into the key here — unlike the
 * structured (locational) resolver.
 *
 * Resolution is lazy + negative-cached. Hook callbacks never write to the DB;
 * discovery is batched and written on shutdown, throttled to once/day per key.
 */
final class GettextResolver
{
    private ?bool $active = null;
    private string $lang = '';

    /** @var array<string, string> hex(source_key) => translated_text */
    private array $map = [];

    /** @var array<string, true> hex(source_key) => already-queried (negative cache) */
    private array $loaded = [];

    /** @var array<string, GettextSource> hex(source_key) => candidate (deduped per request) */
    private array $candidates = [];

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context,
        private SourceTranslationRepository $repository
    ) {}

    public function register(): void
    {
        add_filter('gettext', [$this, 'gettext'], 10, 3);
        add_filter('gettext_with_context', [$this, 'gettextWithContext'], 10, 4);
        add_filter('ngettext', [$this, 'ngettext'], 10, 5);
        add_filter('ngettext_with_context', [$this, 'ngettextWithContext'], 10, 6);
        add_action('shutdown', [$this, 'flush'], 0);
    }

    /**
     * @param mixed $translation
     * @return mixed
     */
    public function gettext($translation, $text = '', $domain = 'default')
    {
        return $this->resolve($translation, (string) $text, null, (string) $domain);
    }

    /**
     * @param mixed $translation
     * @return mixed
     */
    public function gettextWithContext($translation, $text = '', $context = '', $domain = 'default')
    {
        $context = (string) $context;

        return $this->resolve($translation, (string) $text, $context === '' ? null : $context, (string) $domain);
    }

    /**
     * @param mixed $translation
     * @return mixed
     */
    public function ngettext($translation, $single = '', $plural = '', $number = 1, $domain = 'default')
    {
        return $this->resolvePlural($translation, (string) $single, (string) $plural, (int) $number, null, (string) $domain);
    }

    /**
     * @param mixed $translation
     * @return mixed
     */
    public function ngettextWithContext($translation, $single = '', $plural = '', $number = 1, $context = '', $domain = 'default')
    {
        $context = (string) $context;

        return $this->resolvePlural($translation, (string) $single, (string) $plural, (int) $number, $context === '' ? null : $context, (string) $domain);
    }

    /**
     * @param mixed $incoming
     * @return mixed
     */
    private function resolve($incoming, string $text, ?string $context, string $domain)
    {
        if (!$this->isActive() || $text === '') {
            return $incoming;
        }

        $key = TL_Key::make(TL_Key::TYPE_I18N, null, $this->domain($domain), $context, $text);
        $this->ensureKeysLoaded([$key]);
        $hex = bin2hex($key);

        if (isset($this->map[$hex])) {
            return $this->map[$hex];
        }

        $this->capture($hex, $domain, $context, $text);

        return $incoming;
    }

    /**
     * Singular and plural are two independent strings: capture both, resolve the
     * form WordPress would display for $number.
     *
     * @param mixed $incoming
     * @return mixed
     */
    private function resolvePlural($incoming, string $single, string $plural, int $number, ?string $context, string $domain)
    {
        if (!$this->isActive()) {
            return $incoming;
        }

        $forms = array_values(array_filter([$single, $plural], static fn(string $f): bool => $f !== ''));
        if ($forms === []) {
            return $incoming;
        }

        $keys = [];
        foreach ($forms as $form) {
            $keys[$form] = TL_Key::make(TL_Key::TYPE_I18N, null, $this->domain($domain), $context, $form);
        }
        $this->ensureKeysLoaded(array_values($keys));

        foreach ($forms as $form) {
            $hex = bin2hex($keys[$form]);
            if (!isset($this->map[$hex])) {
                $this->capture($hex, $domain, $context, $form);
            }
        }

        $chosen = $number === 1 ? $single : $plural;
        if ($chosen === '' || !isset($keys[$chosen])) {
            return $incoming;
        }

        return $this->map[bin2hex($keys[$chosen])] ?? $incoming;
    }

    private function capture(string $hex, string $domain, ?string $context, string $text): void
    {
        if (!isset($this->candidates[$hex])) {
            $this->candidates[$hex] = new GettextSource($domain, $context, $text);
        }
    }

    /**
     * Write the request's newly-seen gettext sources after the response, skipping
     * keys whose last-seen was touched within 24h (object-cache throttle).
     */
    public function flush(): void
    {
        if (!$this->isActive() || $this->candidates === []) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $records = [];
        foreach ($this->candidates as $hex => $record) {
            if ($this->recentlySeen($hex)) {
                continue;
            }
            $records[] = $record;
        }
        $this->candidates = [];

        if ($records !== []) {
            $this->repository->upsertSources($records);
        }
    }

    /**
     * @param string[] $rawKeys
     */
    private function ensureKeysLoaded(array $rawKeys): void
    {
        $todo = [];
        foreach ($rawKeys as $raw) {
            $hex = bin2hex($raw);
            if (!isset($this->loaded[$hex])) {
                $todo[$hex] = $raw;
            }
        }
        if ($todo === []) {
            return;
        }

        $fetched = $this->repository->fetchTranslations(array_values($todo), $this->lang);

        foreach ($todo as $hex => $raw) {
            $this->loaded[$hex] = true;
            if (isset($fetched[$hex])) {
                $this->map[$hex] = $fetched[$hex];
            }
        }
    }

    /** True if this key's last-seen was recorded within the last day. */
    private function recentlySeen(string $hex): bool
    {
        if (!function_exists('wp_cache_get') || !function_exists('wp_cache_set')) {
            return false;
        }

        if (wp_cache_get($hex, 'translangs_gettext_seen') !== false) {
            return true;
        }

        wp_cache_set($hex, 1, 'translangs_gettext_seen', DAY_IN_SECONDS);

        return false;
    }

    /** Must match GettextSource's normalization exactly, or keys diverge. */
    private function domain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            $domain = GettextSource::DEFAULT_DOMAIN;
        }

        return mb_substr($domain, 0, 191);
    }

    private function isActive(): bool
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $this->active = $this->context->shouldTranslate() && $this->language->isTranslatedRequest();
        if ($this->active) {
            $this->lang = $this->language->resolve();
        }

        return $this->active;
    }
}
