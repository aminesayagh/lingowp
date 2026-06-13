<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;

/**
 * Frontend output buffer — link rewriting only (v4).
 *
 * Structured translation now happens via identity hooks (StructuredRenderResolver),
 * so this no longer parses the HTML for text replacement. The buffer is retained
 * to rewrite links to their localized URLs on translated pages; the type-5 HTML
 * fallback (and its gettext companion) will be rebuilt on this buffer in a later
 * step.
 */
final class PublicRenderPipeline
{
    private ?string $targetLang = null;

    public function __construct(
        private ResolveRequestLanguage $languageResolver,
        private RequestContext $requestContext,
        private PublicLinkRewriter $linkRewriter
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

        return $this->linkRewriter->rewrite($html, $this->targetLang);
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
