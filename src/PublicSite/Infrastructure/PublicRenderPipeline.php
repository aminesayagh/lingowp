<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;

/**
 * Frontend output buffer (v4). Structured text (types 1–4) is already translated
 * by the hooks before the buffer closes; here we run the type-5 REPLACE pass over
 * the final HTML and rewrite links to their localized URLs.
 *
 * The DOM parse is the one heavy step, so it is gated: when the language has no
 * type-5 translations at all, the per-language map is empty and the parse is
 * skipped entirely (parsing spec §3 step 5 / P8). Type-5 discovery is marking-mode
 * only and is not done here.
 */
final class PublicRenderPipeline
{
    private ?string $targetLang = null;

    public function __construct(
        private ResolveRequestLanguage $languageResolver,
        private RequestContext $requestContext,
        private PublicLinkRewriter $linkRewriter,
        private SourceTranslationRepository $repository,
        private HtmlRenderer $htmlRenderer
    ) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeStart'], 0);
    }

    public function maybeStart(): void
    {
        if (!$this->requestContext->shouldTranslate() || !$this->languageResolver->isTranslatedRequest()) {
            return;
        }

        $this->targetLang = $this->languageResolver->resolve();

        ob_start(
            [$this, 'finish'],
            0,
            PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
        );
    }

    public function finish(string $html, int $phase = 0): string
    {
        if (
            ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0
            || $this->targetLang === null
            || !$this->isHtmlResponse()
        ) {
            return $html;
        }

        $map = $this->htmlTranslations($this->targetLang);
        if ($map !== []) {
            $html = $this->htmlRenderer->render($html, $map);
        }

        return $this->linkRewriter->rewrite($html, $this->targetLang);
    }

    /**
     * Per-language type-5 map, object-cached. Empty array is a valid cached value
     * (means "skip the parse"); a cache miss returns false and triggers a fetch.
     *
     * @return array<string, string>
     */
    private function htmlTranslations(string $lang): array
    {
        $cacheKey = 'tl:html:' . $lang;

        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get($cacheKey, 'translangs');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $map = $this->repository->fetchHtmlTranslations($lang);

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $map, 'translangs', 300);
        }

        return $map;
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
