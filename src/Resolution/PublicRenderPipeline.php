<?php

namespace LingoWP\Resolution;
use LingoWP\Resolution\HtmlRenderer;
use LingoWP\Resolution\PublicLinkRewriter;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Extraction\Html\HtmlDiscovery;
use LingoWP\Resolution\Seo\SeoHeadTranslator;

/**
 * Frontend output buffer. Structured text (post/term/meta + gettext) is
 * already translated by the hooks before the buffer closes; here we only rewrite
 * internal links to their localized URLs on a translated request.
 *
 * On WordPress 6.9+ the buffer is core's own template enhancement output buffer
 * (wp_start_template_enhancement_output_buffer()), which hands the finished
 * HTML to the wp_template_enhancement_output_buffer filter and is opened and
 * closed by core. On 6.2–6.8, and on 6.9+ when another plugin has vetoed
 * core's buffer (Elementor does), maybeStart() opens an identical buffer
 * itself — same callback signature, chunk size and flags as core's — and
 * closes it on shutdown one priority ahead of core's wp_ob_end_flush_all().
 *
 * The same buffer also powers MARKING MODE: an editor loads a frontend page in the
 * SOURCE language with ?lingowp_mark, and every block-level unit not already
 * owned by a structured source is minted as a html parent. It runs on the
 * source language on purpose — captured text is then genuine source text, and the
 * ownership gate (post/term/meta/html source hashes) excludes structured content.
 */
final class PublicRenderPipeline
{
    private ?string $targetLang = null;
    private bool $marking = false;

    private ResolveRequestLanguage $languageResolver;
    private RequestContext $requestContext;
    private PublicLinkRewriter $linkRewriter;
    private WpDbSourceRepository $repository;
    private HtmlRenderer $htmlRenderer;
    private HtmlDiscovery $htmlDiscovery;
    private SeoHeadTranslator $seoHead;
    private LanguageRegistry $languageRegistry;

    public function __construct(
        ResolveRequestLanguage $languageResolver,
        RequestContext $requestContext,
        PublicLinkRewriter $linkRewriter,
        WpDbSourceRepository $repository,
        HtmlRenderer $htmlRenderer,
        HtmlDiscovery $htmlDiscovery,
        SeoHeadTranslator $seoHead,
        LanguageRegistry $languageRegistry
    ) {
        $this->languageResolver = $languageResolver;
        $this->requestContext   = $requestContext;
        $this->linkRewriter     = $linkRewriter;
        $this->repository       = $repository;
        $this->htmlRenderer     = $htmlRenderer;
        $this->htmlDiscovery    = $htmlDiscovery;
        $this->seoHead          = $seoHead;
        $this->languageRegistry = $languageRegistry;
    }

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

        // 6.9+: core opens one shared buffer around the template (see class
        // doc) and runs this filter on the complete HTML. Registering the
        // filter is what makes core decide to open it — but that decision is
        // itself filterable, and Elementor vetoes it unconditionally on 6.9+
        // (includes/frontend.php, __return_false). So ask core for its final
        // answer, and only rely on it when the answer is yes.
        if (function_exists('wp_start_template_enhancement_output_buffer') && function_exists('wp_should_output_buffer_template_for_enhancement')) {
            add_filter('wp_template_enhancement_output_buffer', [$this, 'finish'], 10, 1);
            if (wp_should_output_buffer_template_for_enhancement()) {
                return;
            }
            remove_filter('wp_template_enhancement_output_buffer', [$this, 'finish'], 10);
        }

        // 6.2–6.8, or 6.9+ with core's buffer vetoed: the same buffer, opened
        // here. Not flushable, so ob_flush() from a theme cannot split the
        // document across two callback runs; removable, so it can be closed
        // before the object cache shuts down.
        ob_start(
            [$this, 'finish'],
            0,
            PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
        );
        $level = ob_get_level();

        // Explicit close, one priority ahead of core's own wp_ob_end_flush_all()
        // (shutdown, 1). Flushes any buffer opened after ours first, so nesting
        // stays intact, and stops at ours. Same loop as core's, hence the same
        // silenced call: a handler that refuses to flush must end the loop, not
        // raise a notice into the response.
        add_action('shutdown', static function () use ($level): void {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            while (ob_get_level() >= $level && @ob_end_flush()) {
                continue;
            }
        }, 0);
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

        // Final-HTML REPLACE pass. Covers hardcoded template text (html) and
        // visitor-facing wp_options text (option) — both hash-linked, so one
        // map drives the output rewrite. The map is object-cached per language and
        // the DOM parse is skipped entirely when it is empty, so a site that uses
        // neither never pays the parse cost.
        // One parse for both passes: the body replace map and the SEO <head> pass
        // share the DOM. Note this is no longer gated on a non-empty $map — a site
        // with no html/option rows still has SEO tags to translate.
        $lang = $this->targetLang;
        $html = $this->htmlRenderer->render(
            $html,
            $this->pageOutputTranslations($lang),
            null,
            function (\DOMDocument $dom) use ($lang): void {
                $this->seoHead->translate($dom, $lang);
                // After the body is translated: core's lightbox froze the
                // source alt into Interactivity state at block render.
                (new ImageLightboxState())->sync($dom);
            }
        );

        // Structured text (post/term/meta + gettext) is already translated by the
        // hooks; here we only localize internal links for the translated request.
        return $this->linkRewriter->rewrite($html, $this->targetLang);
    }

    /**
     * Per-language map for the final-HTML replace pass, object-cached. Merges
     * html, option, and post (structured content) hash-link maps —
     * post is needed too since block-theme templates/parts render their
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
        $cacheKey = 'lingowp:html:' . $lang;

        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get($cacheKey, 'lingowp');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $map = [];
        foreach ($this->languageRegistry->resolutionChain($lang) as $tryLang) {
            // + is first-wins: post (structured) outranks option, which
            // outranks html (generic template-text sweep) on a hash collision.
            $map += $this->repository->fetchAllHashTranslations('post', $tryLang)
                + $this->repository->fetchAllHashTranslations('option', $tryLang)
                + $this->repository->fetchAllHashTranslations('html', $tryLang);
        }

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $map, 'lingowp', 300);
        }

        return $map;
    }

    /**
     * Marking = a logged-in editor viewing a source-language page. It runs
     * automatically, throttled to once per day per URL (a DB-backed transient,
     * since the object cache may be non-persistent), so ordinary admin browsing
     * gradually discovers hardcoded template text without re-parsing every view.
     * An explicit ?lingowp_mark forces a re-mark, bypassing the throttle.
     */
    private function isMarking(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- edit_posts is checked above and this only forces marking that the throttle below performs anyway; no state change is gated on it.
        if (!empty($_GET['lingowp_mark'])) {
            return true;
        }

        return get_transient($this->markThrottleKey()) === false;
    }

    /** Per-URL daily throttle key for auto-marking. */
    private function markThrottleKey(): string
    {
        return 'lingowp_html_marked_' . md5((string) $this->currentUrl());
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
