<?php

namespace TransLangs\Frontend\Parser;

/**
 * Selects the best available parser driver for the current environment.
 *
 * Order of preference:
 *   1. WpHtmlParser      core HTML API — fastest, safest, zero-dependency
 *   2. Html5Parser       Masterminds — only if core API absent but lib present
 *
 * Returns null if nothing usable exists, in which case the caller leaves the
 * HTML untranslated rather than risk corrupting it.
 */
class ParserFactory
{
    public function __construct(private SkipRules $rules)
    {
    }

    public function create(): ?ParserInterface
    {
        $wp = new WpHtmlParser($this->rules);
        if ($wp->isAvailable()) {
            return $wp;
        }

        $html5 = new Html5Parser($this->rules);
        if ($html5->isAvailable()) {
            return $html5;
        }

        return null;
    }
}