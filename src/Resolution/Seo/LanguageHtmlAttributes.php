<?php

namespace LingoWP\Resolution\Seo;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\Resolution\RequestContext;

/**
 * Sets <html lang> and dir to the current request's language on translated
 * front-end pages. WordPress builds language_attributes() from
 * get_bloginfo('language') / is_rtl(), both of which reflect the site's own
 * locale, so a /fr/ page would otherwise announce itself as the source language.
 *
 * Same gate as HreflangPresenter: real front-end page views only, and only when
 * the resolved language differs from the source.
 */
class LanguageHtmlAttributes
{
    private ResolveRequestLanguage $resolver;
    private RequestContext $context;
    private SupportedLanguages $languages;

    public function __construct(
        ResolveRequestLanguage $resolver,
        RequestContext $context,
        SupportedLanguages $languages
    ) {
        $this->resolver  = $resolver;
        $this->context   = $context;
        $this->languages = $languages;
    }

    public function register(): void
    {
        add_filter('language_attributes', [$this, 'filter'], 20);
    }

    /**
     * @param mixed $output e.g. 'lang="en-US"' or 'dir="rtl" lang="ar"'
     */
    public function filter($output): string
    {
        $output = (string) $output;

        if (!$this->context->shouldTranslate() || !$this->resolver->isTranslatedRequest()) {
            return $output;
        }

        $code = $this->resolver->resolve();
        $tag  = esc_attr(str_replace('_', '-', $code));

        $result = preg_replace('/\blang="[^"]*"/', 'lang="' . $tag . '"', $output, 1, $replaced);
        $result = (string) $result;
        if ($replaced === 0) {
            $result = trim($result . ' lang="' . $tag . '"');
        }

        // Drop any inherited dir, then set it only for an RTL target.
        $result = (string) preg_replace('/\s*\bdir="[^"]*"/', '', $result);
        if ($this->languages->direction($code) === 'rtl') {
            $result .= ' dir="rtl"';
        }

        return trim($result);
    }
}
