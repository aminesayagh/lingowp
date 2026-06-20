<?php

namespace TransLangs\Rendering\Infrastructure\StaticText;
use TransLangs\Rendering\Infrastructure\PageOwnedHashes;
use TransLangs\Rendering\Infrastructure\RequestContext;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\PluralRules;
use TransLangs\Core\Source\GettextSource;
use TransLangs\Core\Text\IdentityKey;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Text\TL_Key;
use TransLangs\Discovery\Infrastructure\StaticText\GettextCaptureWorker;

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

    /** @var array<string, string> IdentityKey => translated_text */
    private array $map = [];

    /** @var array<string, true> IdentityKey => already-queried (negative cache) */
    private array $loaded = [];

    /** @var array<string, array{domain: string, context: ?string, text: string}> deduped per request */
    private array $candidates = [];

    /** @var array<string, array<int, string>> IdentityKey(singular) => [form_index => text] */
    private array $pluralForms = [];

    /** @var array<string, true> IdentityKey(singular) => already-queried (negative cache) */
    private array $pluralLoaded = [];

    /** @var array<string, array{domain: string, context: ?string, single: string, plural: string}> */
    private array $pluralCandidates = [];

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context,
        private SourceTranslationRepository $repository,
        private PageOwnedHashes $pageOwned
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
     * @param mixed $translation already the collapsed English form ($number==1 ?
     *                            $single : $plural), since no target .mo is loaded
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

        return $this->resolvePlural(
            $translation,
            (string) $single,
            (string) $plural,
            (int) $number,
            $context === '' ? null : $context,
            (string) $domain
        );
    }

    /**
     * Resolve a plural to the stored form for this language + count, and discover
     * the message. The anchor (identity) is the SINGULAR string, exactly like a
     * normal gettext string; the form is picked with the target language's CLDR
     * rule (PluralRules → WordPress's \Plural_Forms). Capture records BOTH forms so
     * the message is complete regardless of which one this $number rendered.
     *
     * @param mixed $incoming
     * @return mixed
     */
    private function resolvePlural($incoming, string $single, string $plural, int $number, ?string $context, string $domain)
    {
        if ($single === '' || $plural === '') {
            return $incoming;
        }

        $lookup = $this->staticTextLookup($domain, $context, $single);
        $key    = IdentityKey::of($lookup);

        if ($this->isActive()) {
            $this->ensurePluralLoaded([$lookup]);
            if (isset($this->pluralForms[$key])) {
                $idx = PluralRules::indexFor($this->lang, $number);
                if (($this->pluralForms[$key][$idx] ?? '') !== '') {
                    return $this->pluralForms[$key][$idx];
                }
            }
        }

        if ($this->shouldCapture() && !$this->pageOwned->owns($lookup['original_hash'])) {
            $this->capturePlural($key, $domain, $context, $single, $plural);
        }

        return $incoming;
    }

    private function capturePlural(string $key, string $domain, ?string $context, string $single, string $plural): void
    {
        if (!isset($this->pluralCandidates[$key])) {
            $this->pluralCandidates[$key] = [
                'domain'  => $domain,
                'context' => $context,
                'single'  => $single,
                'plural'  => $plural,
            ];
        }
    }

    /**
     * @param array<int, array<string, scalar>> $lookups
     */
    private function ensurePluralLoaded(array $lookups): void
    {
        $todo = [];
        foreach ($lookups as $lookup) {
            $key = IdentityKey::of($lookup);
            if (!isset($this->pluralLoaded[$key])) {
                $todo[$key] = $lookup;
            }
        }
        if ($todo === []) {
            return;
        }

        $fetched = $this->repository->fetchPluralForms(array_values($todo), $this->lang);

        foreach ($todo as $key => $lookup) {
            $this->pluralLoaded[$key] = true;
            if (isset($fetched[$key])) {
                $this->pluralForms[$key] = $fetched[$key];
            }
        }
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

        $lookup = $this->staticTextLookup($domain, $context, $text);
        $key    = IdentityKey::of($lookup);

        // Resolve only on a translated (target-language) request.
        if ($this->isActive()) {
            $this->ensureLoaded([$lookup]);
            if (isset($this->map[$key])) {
                return $this->map[$key];
            }
        }

        // Discover on any genuine frontend load, regardless of target language —
        // unless a structured source already owns this text (Tier-1 gate).
        if ($this->shouldCapture() && !$this->pageOwned->owns($lookup['original_hash'])) {
            $this->capture($key, $domain, $context, $text);
        }

        return $incoming;
    }

    private function capture(string $key, string $domain, ?string $context, string $text): void
    {
        if (!isset($this->candidates[$key])) {
            $this->candidates[$key] = ['domain' => $domain, 'context' => $context, 'text' => $text];
        }
    }

    /**
     * The tl_staticText render-lookup columns for a gettext string — must match
     * GettextSource::identity() exactly, or render keys diverge from discovery.
     *
     * @return array<string, scalar>
     */
    private function staticTextLookup(string $domain, ?string $context, string $text): array
    {
        $reference = mb_substr($this->domain($domain) . '|' . ($context ?? ''), 0, 191);

        return [
            'source'         => GettextSource::SOURCE,
            'reference_hash' => hash('sha256', $reference, true),
            'original_hash'  => TL_Key::currentHash($text),
        ];
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
        $this->flushSingular();
        $this->flushPlural();
    }

    private function flushSingular(): void
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

    private function flushPlural(): void
    {
        if ($this->pluralCandidates === []) {
            return;
        }

        $payload = [];
        foreach ($this->pluralCandidates as $key => $data) {
            if ($this->recentlySeen('pl:' . $key)) {
                continue;
            }
            $payload[] = $data;
        }
        $this->pluralCandidates = [];

        if ($payload !== []) {
            GettextCaptureWorker::enqueuePlural($payload);
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
     * @param array<int, array<string, scalar>> $lookups
     */
    private function ensureLoaded(array $lookups): void
    {
        $todo = [];
        foreach ($lookups as $lookup) {
            $key = IdentityKey::of($lookup);
            if (!isset($this->loaded[$key])) {
                $todo[$key] = $lookup;
            }
        }
        if ($todo === []) {
            return;
        }

        $fetched = $this->repository->fetchParentTranslations('tl_staticText', array_values($todo), $this->lang);

        foreach ($todo as $key => $lookup) {
            $this->loaded[$key] = true;
            if (isset($fetched[$key])) {
                $this->map[$key] = $fetched[$key];
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
