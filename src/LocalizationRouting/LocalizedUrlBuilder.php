<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

/**
 * One place that knows how a language prefix maps onto a URL path.
 *
 * LanguageRouteRegistrar uses this for incoming prefixed URLs. Future public
 * link rewriting should use the same builder so incoming and outgoing URL
 * rules stay aligned.
 *
 * With a SlugMap attached it also translates the path's own segments, giving
 * the two methods below one invariant each:
 *
 *   stripPrefix() returns a SOURCE-LANGUAGE path — prefix gone, segments
 *                 normalized back to their source slugs.
 *   addPrefix()   returns a path fully expressed in the target language —
 *                 prefix added, segments localized.
 *
 * Callers already pair them that way (LanguageLinks::links(),
 * HreflangPresenter::currentBasePath() both strip then add), so slug
 * translation reaches every public URL surface without a single caller change.
 */
class LocalizedUrlBuilder
{
    private ResolveRequestLanguage $resolver;

    /**
     * Optional on purpose: this class is constructible without WordPress (it is
     * unit-tested that way, and LanguageLinks::instance() builds a bare one as
     * a pre-boot fallback). Absent, every path below behaves exactly as it did
     * before slug translation existed.
     */
    private ?SlugMap $slugs;

    public function __construct(ResolveRequestLanguage $resolver, ?SlugMap $slugs = null)
    {
        $this->resolver = $resolver;
        $this->slugs    = $slugs;
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
     * Remove any leading language segment from a path, keeping its
     * trailing-slash shape.
     *   "/fr/about/" => "/about/",  "/fr/about" => "/about"
     *   "/about"     => "/about",   "/fr/"      => "/"
     *
     * The shape matters: whatever this returns is handed to addPrefix() to be
     * re-prefixed, and the result is published as an hreflang target, a
     * switcher href or a redirect location. Dropping the slash here made every
     * one of those point at a URL that WordPress answers with a 301.
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

        if ($rest === '') {
            return '/'; // a bare language home ('/fr', '/fr/') strips to root
        }

        // trim() above took both ends, so the trailing slash has to be put
        // back deliberately — the leading one is re-added either way.
        $bare = '/' . $rest . (substr($path, -1) === '/' ? '/' : '');

        // The prefix told us which language the segments are written in, so
        // this is the only place that can undo slug translation. Everything
        // downstream — hreflang alternates, the switcher, a redirect target —
        // gets a source-language path it can re-localize to any language.
        return $this->slugs === null ? $bare : $this->slugs->toSource($bare, $prefix);
    }

    /**
     * Apply the given language prefix to a path, replacing any existing one.
     *
     * For a non-prefixed language (e.g. the default), returns the bare path.
     *   addPrefix("/about", "fr")        => "/fr/about"
     *   addPrefix("/about/", "fr")       => "/fr/about/"  (shape preserved)
     *   addPrefix("/es/about", "fr")     => "/fr/about"
     *   addPrefix("/fr/about", "en")     => "/about"   (en not prefixed)
     *   addPrefix("/", "fr")             => "/fr/"     (or "/fr" — see below)
     *
     * Every path but the root carries its own trailing-slash shape through
     * untouched. The root is the one case with no shape to carry: its leading
     * and trailing slash are the same character, so the language home has to
     * be built. Which form is canonical depends on the site's permalink
     * structure, so ask WordPress rather than guess — user_trailingslashit()
     * reads $wp_rewrite->use_trailing_slashes and adds or removes to match.
     * Guessing wrong costs a 301 on the most-linked page of the site.
     */
    public function addPrefix(string $path, string $lang): string
    {
        $bare = $this->stripPrefix($path);

        if (!$this->isPrefixed($lang)) {
            return $bare === '' ? '/' : $bare;
        }

        $bare = '/' . ltrim($bare, '/');

        // Source-language path in (stripPrefix() guarantees it), target-language
        // path out. Only reached for a prefixed — therefore published —
        // language; SlugMap refuses the default language and any unpublished
        // one on its own account.
        if ($this->slugs !== null) {
            $bare = $this->slugs->localize($bare, $lang);
        }

        $slug = $this->resolver->slugForLanguage($lang);

        if ($bare !== '/') {
            return '/' . $slug . $bare;
        }

        $home = '/' . $slug;

        // function_exists(): this class is constructible without WordPress.
        // The root path never carries a file extension, so the helper cannot
        // mangle one here the way it would on "/sitemap.xml".
        return function_exists('user_trailingslashit') ? user_trailingslashit($home) : $home . '/';
    }
}
