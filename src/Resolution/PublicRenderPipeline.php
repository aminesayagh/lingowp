<?php

namespace LingoWP\Resolution;
use LingoWP\Resolution\HtmlRenderer;
use LingoWP\Resolution\PublicLinkRewriter;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Extraction\Html\HtmlDiscovery;
use LingoWP\Resolution\Seo\SeoHeadTranslator;

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

        if ($this->marking) {
            set_transient($this->markThrottleKey(), 1, DAY_IN_SECONDS);
            $this->htmlDiscovery->discover($html, $this->currentUrl());

            return $html;
        }

        if ($this->targetLang === null) {
            return $html;
        }

        $lang = $this->targetLang;
        $html = $this->htmlRenderer->render(
            $html,
            $this->pageOutputTranslations($lang),
            null,
            function (\DOMDocument $dom) use ($lang): void {
                $this->seoHead->translate($dom, $lang);
            }
        );

        return $this->linkRewriter->rewrite($html, $this->targetLang);
    }

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
            $map += $this->repository->fetchAllHashTranslations('post', $tryLang)
                + $this->repository->fetchAllHashTranslations('option', $tryLang)
                + $this->repository->fetchAllHashTranslations('html', $tryLang);
        }

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $map, 'lingowp', 300);
        }

        return $map;
    }

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

    private function markThrottleKey(): string
    {
        return 'lingowp_html_marked_' . md5((string) $this->currentUrl());
    }

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
