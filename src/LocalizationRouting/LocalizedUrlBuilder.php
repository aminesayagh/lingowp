<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

/**
 * One place that knows how a language prefix maps onto a URL path.
 *
 * LanguageRouteRegistrar uses this for incoming prefixed URLs. Future public
 * link rewriting should use the same builder so incoming and outgoing URL
 * rules stay aligned.
 */
class LocalizedUrlBuilder
{
    private ResolveRequestLanguage $resolver;

    public function __construct(ResolveRequestLanguage $resolver)
    {
        $this->resolver = $resolver;
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

        if ((bool) get_option('lingowp_prefix_default_language', false)) {
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

        return $this->resolver->languageForSlug(rawurldecode(explode('/', $trimmed)[0]));
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

        $slug = $this->resolver->slugForLanguage($lang);

        return $bare === '/' ? '/' . $slug : '/' . $slug . $bare;
    }
}
