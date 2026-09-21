<?php

namespace LingoWP\Resolution\Seo;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\Resolution\RequestContext;

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

        $result = (string) preg_replace('/\s*\bdir="[^"]*"/', '', $result);
        if ($this->languages->direction($code) === 'rtl') {
            $result .= ' dir="rtl"';
        }

        return trim($result);
    }
}
