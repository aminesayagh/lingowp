<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\GettextSource;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Domain\TL_Key;
use TransLangs\TranslationMemory\Infrastructure\GettextCaptureWorker;

/**
 * v4 gettext (type 4) resolution + render-time discovery (data pipeline §1 row
 * 11, Path C).
 *
 * On the gettext filters this both (a) RESOLVES the string to its translation by
 * key — only on a translated (target-language) request — and (b) CAPTURES unknown
 * strings as discovery candidates on any genuine frontend load (independent of
 * the target language, so default-language views discover too).
 *
 * Capture is gated to real visitor views: an editor/admin browsing the front
 * would feed the admin toolbar + editor chrome into the table, so at flush time
 * an editor request discards its whole buffer (decided once, late — never a
 * per-call current_user_can that would recurse through wp_salt()). New strings
 * are throttled once/day per key, then written OFF the request via an Action
 * Scheduler job (GettextCaptureWorker) — never at shutdown.
 */
final class GettextResolver
{
    private ?bool $active = null;
    private string $lang = '';

    /**
     * Re-entrancy guard. Computing activation calls conditional tags
     * (is_feed/is_sitemap/…); before the main query those tags emit __() via
     * core's "Conditional query tags…" _doing_it_wrong, which re-fires the
     * gettext filter. Without this guard that recurses until the stack blows.
     */
    private bool $activating = false;

    /** Memoized "may this request discover gettext strings?" verdict. */
    private ?bool $capturing = null;

    /** Transient pause flag — true while a widget sidebar is rendering. */
    private bool $capturePaused = false;

    /** @var array<string, string> hex(source_key) => translated_text */
    private array $map = [];

    /** @var array<string, true> hex(source_key) => already-queried (negative cache) */
    private array $loaded = [];

    /** @var array<string, array{domain: string, context: ?string, text: string}> deduped per request */
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

        // Don't learn widget-sidebar strings: pause capture while a sidebar
        // renders (the sidebar still displays — we just skip its strings).
        add_action('dynamic_sidebar_before', [$this, 'pauseCapture']);
        add_action('dynamic_sidebar_after', [$this, 'resumeCapture']);
    }

    public function pauseCapture(): void
    {
        $this->capturePaused = true;
    }

    public function resumeCapture(): void
    {
        $this->capturePaused = false;
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
        if ($text === '') {
            return $incoming;
        }

        $key = TL_Key::make(TL_Key::TYPE_I18N, null, $this->domain($domain), $context, $text);
        $hex = bin2hex($key);

        // Resolve only on a translated (target-language) request.
        if ($this->isActive()) {
            $this->ensureKeysLoaded([$key]);
            if (isset($this->map[$hex])) {
                return $this->map[$hex];
            }
        }

        // Discover on any genuine frontend load, regardless of target language.
        if ($this->shouldCapture()) {
            $this->capture($hex, $domain, $context, $text);
        }

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
        $forms = array_values(array_filter([$single, $plural], static fn(string $f): bool => $f !== ''));
        if ($forms === []) {
            return $incoming;
        }

        $keys = [];
        foreach ($forms as $form) {
            $keys[$form] = TL_Key::make(TL_Key::TYPE_I18N, null, $this->domain($domain), $context, $form);
        }

        $active = $this->isActive();
        if ($active) {
            $this->ensureKeysLoaded(array_values($keys));
        }

        if ($this->shouldCapture()) {
            foreach ($forms as $form) {
                $hex = bin2hex($keys[$form]);
                if (!($active && isset($this->map[$hex]))) {
                    $this->capture($hex, $domain, $context, $form);
                }
            }
        }

        if (!$active) {
            return $incoming;
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
            $this->candidates[$hex] = ['domain' => $domain, 'context' => $context, 'text' => $text];
        }
    }

    /**
     * Hand the request's newly-seen gettext strings to the background worker,
     * skipping keys touched within 24h (object-cache throttle). No DB write here;
     * the worker does the bulk upsert. Capture runs for any frontend view
     * (logged-in admins included) — admin chrome is kept out by suppressing it
     * during render, not by discarding the buffer.
     */
    public function flush(): void
    {
        if ($this->candidates === []) {
            return;
        }

        $payload = [];
        foreach ($this->candidates as $hex => $data) {
            if ($this->recentlySeen($hex)) {
                continue;
            }
            $payload[] = $data;
        }
        $this->candidates = [];

        if ($payload !== []) {
            GettextCaptureWorker::enqueue($payload);
        }
    }

    private function shouldCapture(): bool
    {
        if ($this->capturePaused) {
            return false;
        }

        if ($this->capturing !== null) {
            return $this->capturing;
        }

        // Only once page rendering has begun (`wp` fired) — skips bootstrap/system
        // strings (salts, cookie checks) emitted during plugins_loaded. All
        // visitor-facing text renders after this point.
        if (!did_action('wp')) {
            return false;
        }

        return $this->capturing = $this->context->shouldCaptureGettext();
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

        // The conditional tags shouldTranslate() relies on (is_feed/is_sitemap/…)
        // and the language resolver's get_query_var() read the main query, which
        // does not exist until the `wp` action. __() fires long before then
        // (cookie/salt checks during plugins_loaded). Defer without memoizing:
        // those early strings are never page content, and the real verdict is
        // computed once the query is up.
        if (!did_action('wp')) {
            return false;
        }

        // A gettext call fired while we're still deciding activation (a
        // conditional tag emitting __() before the main query). Treat it as
        // inactive without memoizing, so the real verdict is computed once the
        // outer call completes.
        if ($this->activating) {
            return false;
        }

        $this->activating = true;
        try {
            $active = $this->context->shouldTranslate() && $this->language->isTranslatedRequest();
        } finally {
            $this->activating = false;
        }

        $this->active = $active;
        if ($this->active) {
            $this->lang = $this->language->resolve();
        }

        return $this->active;
    }
}
