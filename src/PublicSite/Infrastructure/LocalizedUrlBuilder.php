<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;

/**
 * One place that knows how a language prefix maps onto a URL path.
 *
 * Both the LanguageRouteRegistrar (incoming: strip prefix to resolve the real route) and the
 * LinkRewriter (outgoing: add prefix so links stay in-language) depend on this,
 * so the two can never disagree about whether a language is prefixed or how the
 * prefix is shaped.
 */
class LocalizedUrlBuilder
{
    public function __construct(private ResolveRequestLanguage $resolver)
    {
    }

    /**
     * Languages that carry a URL prefix. The default language is excluded
     * unless the admin enabled "prefix default language".
     *
     * @return string[]
     */
    public function prefixedLanguages(): array
    {
        $enabled = $this->resolver->getEnabledLanguages();
        $default = $this->resolver->getDefaultLanguage();

        if ((bool) get_option('translangs_prefix_default_language', false)) {
            return $enabled;
        }

        return array_values(array_filter(
            $enabled,
            static fn(string $lang): bool => $lang !== $default
        ));
    }

    public function isPrefixed(string $lang): bool
    {
        return in_array($lang, $this->prefixedLanguages(), true);
    }

    /**
     * The leading language segment of a path, or null if none/!enabled.
     * "/fr/about" => "fr",  "/about" => null
     */
    public function detectPrefix(string $path): ?string
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return null;
        }

        $first = strtolower(explode('/', $trimmed)[0]);

        return $this->resolver->isEnabledLanguage($first) ? $first : null;
    }

    /**
     * Remove any leading language segment from a path.
     * "/fr/about" => "/about",  "/about" => "/about"
     */
    public function stripPrefix(string $path): string
    {
        $prefix = $this->detectPrefix($path);

        if ($prefix === null) {
            return $path;
        }

        $trimmed = trim($path, '/');
        $parts   = explode('/', $trimmed);
        array_shift($parts);

        $rest = implode('/', $parts);

        // Preserve a leading slash; keep root as "/".
        return '/' . $rest;
    }

    /**
     * Apply the given language prefix to a path, replacing any existing one.
     *
     * For a non-prefixed language (e.g. the default), returns the bare path.
     *   addPrefix("/about", "fr")        => "/fr/about"
     *   addPrefix("/es/about", "fr")     => "/fr/about"
     *   addPrefix("/fr/about", "en")     => "/about"   (en not prefixed)
     */
    public function addPrefix(string $path, string $lang): string
    {
        $bare = $this->stripPrefix($path);

        if (!$this->isPrefixed($lang)) {
            return $bare === '' ? '/' : $bare;
        }

        $bare = '/' . ltrim($bare, '/');

        return $bare === '/' ? '/' . $lang : '/' . $lang . $bare;
    }
}
