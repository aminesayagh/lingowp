<?php

namespace TransLangs\Resolution;
use TransLangs\Resolution\HtmlRenderer;
use TransLangs\Resolution\PublicLinkRewriter;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Extraction\Html\HtmlDiscovery;
use TransLangs\Resolution\Seo\SeoHeadTranslator;

/**
 * Frontend output buffer. Structured text (post/term/meta + gettext) is
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
        private WpDbSourceRepository $repository,
        private HtmlRenderer $htmlRenderer,
        private HtmlDiscovery $htmlDiscovery,
        private SeoHeadTranslator $seoHead,
        private LanguageRegistry $languageRegistry
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
            // Stamp the daily throttle up front so even a page with nothing new
            // to discover is not re-parsed on every admin view today.
            set_transient($this->markThrottleKey(), 1, DAY_IN_SECONDS);
            $this->htmlDiscovery->discover($html, $this->currentUrl());

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
        // One parse for both passes: the body replace map and the SEO <head> pass
        // share the DOM. Note this is no longer gated on a non-empty $map — a site
        // with no tl_html/tl_option rows still has SEO tags to translate.
        $lang = $this->targetLang;
        $html = $this->htmlRenderer->render(
            $html,
            $this->pageOutputTranslations($lang),
            null,
            function (\DOMDocument $dom) use ($lang): void {
                $this->seoHead->translate($dom, $lang);
            }
        );

        // Structured text (post/term/meta + gettext) is already translated by the
        // hooks; here we only localize internal links for the translated request.
        return $this->linkRewriter->rewrite($html, $this->targetLang);
    }

    /**
     * Per-language map for the final-HTML replace pass, object-cached. Merges
     * tl_html, tl_option, and tl_post (structured content) hash-link maps —
     * tl_post is needed too since block-theme templates/parts render their
     * post_content via do_blocks(), never the_content, so StructuredRenderResolver
     * never substitutes them; this is the only pass that reaches them. Ordinary
     * post/page text is already translated upstream by the time it reaches this
     * buffer, so it's a no-op there. Rewriting the page output (never option
     * retrieval) keeps business logic on the original value. Empty array is a
     * valid cached value (means "skip the parse"); a cache miss triggers a fetch.
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

        $map = [];
        foreach ($this->languageRegistry->resolutionChain($lang) as $tryLang) {
            // + is first-wins: tl_post (structured) outranks tl_option, which
            // outranks tl_html (generic template-text sweep) on a hash collision.
            $map += $this->repository->fetchAllHashTranslations('tl_post', $tryLang)
                + $this->repository->fetchAllHashTranslations('tl_option', $tryLang)
                + $this->repository->fetchAllHashTranslations('tl_html', $tryLang);
        }

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $map, 'translangs', 300);
        }

        return $map;
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- edit_posts is checked above and this only forces marking that the throttle below performs anyway; no state change is gated on it.
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

    /** Path only (no domain), so stored source_url survives a domain change (dev to prod). */
    private function currentUrl(): ?string
    {
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        return $uri === '' ? null : $uri;
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
