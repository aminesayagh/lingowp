<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;

/**
 * Captures the final rendered HTML and runs it through the translation
 * pipeline before it reaches the browser.
 *
 * This is the heart of the TranslatePress-like model: WordPress renders the
 * page normally, we buffer the complete output, then translate the visible
 * strings in place.
 *
 * Responsibilities are intentionally narrow:
 *   - decide whether to start a buffer (delegated to RequestContext)
 *   - start it as early as safely possible
 *   - on flush, validate the payload (HtmlResponseGuard) and hand it to a pipeline
 *
 * The actual parsing/extraction/replacement is a separate concern, injected as
 * a callable "pipeline". For Step 12 the default pipeline is a pass-through, so
 * /fr/about returns identical HTML. Steps 13-14 replace the pipeline with the
 * HTML parser + string extractor without changing this class.
 */
class OutputBufferMiddleware
{
    /**
     * @var callable(string $html, string $targetLang): string
     */
    private $pipeline;

    private bool $started = false;

    public function __construct(
        private ResolveRequestLanguage $resolver,
        private RequestContext $context,
        private HtmlResponseGuard $guard,
        ?callable $pipeline = null
    ) {
        // Default pipeline: return the HTML unchanged (Step 12 deliverable).
        $this->pipeline = $pipeline ?? static fn(string $html, string $lang): string => $html;
    }

    /**
     * Allow later phases to swap in the real translation pipeline.
     *
     * @param callable(string, string): string $pipeline
     */
    public function setPipeline(callable $pipeline): void
    {
        $this->pipeline = $pipeline;
    }

    public function register(): void
    {
        // template_redirect fires after the main query is parsed but before the
        // theme renders, so starting the buffer here captures the whole page.
        add_action('template_redirect', [$this, 'start'], 0);
    }

    /**
     * Start output buffering if this request qualifies.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (!$this->context->shouldTranslate()) {
            return;
        }

        // Only translated requests need processing. The default-language page
        // is served as-is, with no buffering overhead.
        if (!$this->resolver->isTranslatedRequest()) {
            return;
        }

        ob_start([$this, 'process']);
        $this->started = true;
    }

    /**
     * Output buffer callback. Receives the full page HTML, returns the
     * (possibly translated) HTML. Must never throw — a failure here would
     * blank the page, so we fail open and return the original on any problem.
     *
     * @param string $html Captured output.
     * @return string
     */
    public function process(string $html): string
    {
        try {
            $contentType = $this->guard->detectContentType();

            if (!$this->guard->isTranslatableHtml($html, $contentType)) {
                return $html;
            }

            $targetLang = $this->resolver->resolve();
            $result     = ($this->pipeline)($html, $targetLang);

            // A pipeline that returns an empty string would blank the page;
            // guard against that and fall back to the original.
            return $result !== '' ? $result : $html;
        } catch (\Throwable $e) {
            // Fail open: never let a translation error break the page.
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('[TransLangs] Output buffer error: ' . $e->getMessage());
            }

            return $html;
        }
    }
}
