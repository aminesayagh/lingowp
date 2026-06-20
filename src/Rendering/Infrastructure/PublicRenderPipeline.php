<?php

namespace TransLangs\Rendering\Infrastructure;
use TransLangs\Rendering\Infrastructure\Html\HtmlRenderer;
use TransLangs\Localization\Infrastructure\PublicLinkRewriter;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Source\Domain\HtmlSource;
use TransLangs\Source\Domain\SourceTranslationRepository;
use TransLangs\Source\Infrastructure\OwnedStringIndex;

/**
 * Frontend output buffer (v5). Structured text (post/term/meta + gettext) is
 * already translated by the hooks before the buffer closes; here we only rewrite
 * internal links to their localized URLs on a translated request.
 *
 * The same buffer also powers MARKING MODE: an editor loads a frontend page in the
 * SOURCE language with ?translangs_mark, and every block-level unit not already
 * owned by a structured source is minted as a tl_html parent. It runs on the
 * source language on purpose — captured text is then genuine source text, and the
 * ownership gate (post/term/meta/html source hashes) excludes structured content.
 */
final class PublicRenderPipeline
{
    private ?string $targetLang = null;
    private bool $marking = false;

    public function __construct(
        private ResolveRequestLanguage $languageResolver,
        private RequestContext $requestContext,
        private PublicLinkRewriter $linkRewriter,
        private SourceTranslationRepository $repository,
        private HtmlRenderer $htmlRenderer,
        private OwnedStringIndex $ownedIndex
    ) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeStart'], 0);
    }

    public function maybeStart(): void
    {
        if (!$this->requestContext->shouldTranslate()) {
            return;
        }

        $translated    = $this->languageResolver->isTranslatedRequest();
        $this->marking = !$translated && $this->isMarking();

        // Translated visitor view (rewrite/preview) or a source-language editor
        // marking pass — otherwise leave the response untouched.
        if (!$translated && !$this->marking) {
            return;
        }

        $this->targetLang = $translated ? $this->languageResolver->resolve() : null;

        ob_start(
            [$this, 'finish'],
            0,
            PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
        );
    }

    public function finish(string $html, int $phase = 0): string
    {
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0 || !$this->isHtmlResponse()) {
            return $html;
        }

        // Marking mode: discover hardcoded HTML, leave the (source-language) output
        // as-is. The DB write happens inside the buffer callback (before flush),
        // so it is safe on managed hosts; it is an editor-only, low-frequency pass.
        if ($this->marking) {
            $this->discoverHtml($html);

            return $html;
        }

        if ($this->targetLang === null) {
            return $html;
        }

        // Final-HTML REPLACE pass. Covers hardcoded template text (tl_html) and
        // visitor-facing wp_options text (tl_option) — both hash-linked, so one
        // map drives the output rewrite. The map is object-cached per language and
        // the DOM parse is skipped entirely when it is empty, so a site that uses
        // neither never pays the parse cost.
        $map = $this->pageOutputTranslations($this->targetLang);
        if ($map !== []) {
            $html = $this->htmlRenderer->render($html, $map);
        }

        // Structured text (post/term/meta + gettext) is already translated by the
        // hooks; here we only localize internal links for the translated request.
        return $this->linkRewriter->rewrite($html, $this->targetLang);
    }

    /**
     * Per-language map for the final-HTML replace pass, object-cached. Merges the
     * hash-linked template text (tl_html) and wp_options text (tl_option) maps;
     * rewriting the page output (never option retrieval) keeps business logic on
     * the original value. Empty array is a valid cached value (means "skip the
     * parse"); a cache miss triggers a fetch.
     *
     * @return array<string, string>
     */
    private function pageOutputTranslations(string $lang): array
    {
        $cacheKey = 'tl:html:' . $lang;

        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get($cacheKey, 'translangs');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $map = $this->repository->fetchAllHashTranslations('tl_html', $lang)
            + $this->repository->fetchAllHashTranslations('tl_option', $lang);

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $map, 'translangs', 300);
        }

        return $map;
    }

    /**
     * Walk the page's block units and mint a tl_html parent for each one no
     * structured source already owns (so the same string is never stored as both
     * structured content and html). INSERT IGNORE makes a re-mark idempotent.
     */
    private function discoverHtml(string $html): void
    {
        $sourceUrl = $this->currentUrl();

        // Stamp the daily throttle up front so even a page with nothing new to
        // discover is not re-parsed on every admin view today.
        set_transient($this->markThrottleKey(), 1, DAY_IN_SECONDS);

        $units = [];

        $this->htmlRenderer->render($html, [], static function (string $selector, string $text) use (&$units, $sourceUrl): void {
            $units[$selector . "\0" . $text] = new HtmlSource($selector, $sourceUrl, $text);
        });

        if ($units === []) {
            return;
        }

        $owned   = $this->ownedIndex->ownedHexes(array_map(static fn(HtmlSource $s): string => $s->originalHash(), $units));
        $records = array_values(array_filter(
            $units,
            static fn(HtmlSource $s): bool => !isset($owned[bin2hex($s->originalHash())])
        ));

        if ($records !== []) {
            $this->repository->upsertSources($records);
        }
    }

    /**
     * Marking = a logged-in editor viewing a source-language page. It runs
     * automatically, throttled to once per day per URL (a DB-backed transient,
     * since the object cache may be non-persistent), so ordinary admin browsing
     * gradually discovers hardcoded template text without re-parsing every view.
     * An explicit ?translangs_mark forces a re-mark, bypassing the throttle.
     */
    private function isMarking(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
            return false;
        }

        if (!empty($_GET['translangs_mark'])) {
            return true;
        }

        return get_transient($this->markThrottleKey()) === false;
    }

    /** Per-URL daily throttle key for auto-marking. */
    private function markThrottleKey(): string
    {
        return 'tl_html_marked_' . md5((string) $this->currentUrl());
    }

    private function currentUrl(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '' || !function_exists('home_url')) {
            return $uri === '' ? null : $uri;
        }

        return home_url($uri);
    }

    private function isHtmlResponse(): bool
    {
        foreach (headers_list() as $header) {
            [$name, $value] = array_pad(explode(':', strtolower($header), 2), 2, '');
            if (trim($name) !== 'content-type') {
                continue;
            }

            $mediaType = trim(strtok($value, ';') ?: '');
            return in_array($mediaType, ['text/html', 'application/xhtml+xml'], true);
        }

        return true;
    }
}
