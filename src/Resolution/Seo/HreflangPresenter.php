<?php

namespace LingoWP\Resolution\Seo;
use LingoWP\Resolution\RequestContext;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;

/**
 * Emits hreflang alternate tags so search engines know the language variants
 * of the current page.
 *
 * For the current path it outputs one <link rel="alternate" hreflang="..">
 * per enabled language, plus an x-default pointing at the default language.
 * URLs are built with the same LocalizedUrlBuilder the switcher and link rewriter use,
 * so every surface agrees on what a page's URL is in each language.
 *
 * Absolute URLs are required by search engines, so each path is run through
 * home_url(). Only emitted on real, translatable front-end pages.
 */
class HreflangPresenter
{
    private ResolveRequestLanguage $resolver;
    private LocalizedUrlBuilder $prefixer;
    private RequestContext $context;

    public function __construct(
        ResolveRequestLanguage $resolver,
        LocalizedUrlBuilder $prefixer,
        RequestContext $context
    ) {
        $this->resolver = $resolver;
        $this->prefixer = $prefixer;
        $this->context  = $context;
    }

    public function register(): void
    {
        add_action('wp_head', [$this, 'renderHreflangTags'], 1);

        // Make every translated page self-canonical instead of pointing back at
        // the default-language original. Core's own canonical filter is always
        // hooked; a third-party SEO plugin's own canonical filter (Yoast's
        // wpseo_canonical, Rank Math's rank_math/frontend/canonical, …) is
        // added via lingowp_seo_canonical_filters instead of hardcoded here —
        // see the lingowp-seo add-on. Whichever filter actually fires wins,
        // and they never double-emit because each replaces the same single
        // canonical value.
        add_filter('get_canonical_url', [$this, 'filterCanonical'], 20);
        foreach (self::extraCanonicalFilters() as $hook) {
            add_filter($hook, [$this, 'filterCanonical'], 20);
        }
    }

    /** @return string[] */
    private static function extraCanonicalFilters(): array
    {
        $hooks = function_exists('apply_filters')
            ? apply_filters('lingowp_seo_canonical_filters', [])
            : [];

        return array_values(array_filter(
            array_map('strval', is_array($hooks) ? $hooks : []),
            static fn(string $hook): bool => $hook !== ''
        ));
    }

    /**
     * Rewrite a canonical URL to the current language's version of the page.
     *
     * Given WordPress's canonical (which is built from the original post, so it
     * is unprefixed), re-apply the active language prefix so /fr/about stays
     * canonical to /fr/about — never collapsing to /about.
     *
     * @param string|false|null $canonical
     * @return string|false|null
     */
    public function filterCanonical($canonical)
    {
        if (!is_string($canonical) || $canonical === '') {
            return $canonical;
        }

        if (!$this->context->shouldTranslate()) {
            return $canonical;
        }

        $active = $this->resolver->resolve();

        // Default language: leave the canonical exactly as WordPress built it.
        if ($active === $this->resolver->getDefaultLanguage()) {
            return $canonical;
        }

        $parts = wp_parse_url($canonical);

        if ($parts === false || !isset($parts['path'])) {
            return $canonical;
        }

        // Re-prefix the path for the active language; keep host/query/fragment.
        $newPath = $this->prefixer->addPrefix($parts['path'], $active);

        if ($newPath === $parts['path']) {
            return $canonical; // already correct
        }

        return $this->absoluteUrl($newPath . $this->queryFragment($parts));
    }

    public function renderHreflangTags(): void
    {
        // Only on normal front-end HTML pages (no feeds, REST, 404s, etc.).
        if (!$this->context->shouldTranslate()) {
            return;
        }

        if (function_exists('is_404') && is_404()) {
            return;
        }

        $languages = $this->resolver->getEnabledLanguages();

        if (count($languages) < 2) {
            return; // single language: alternates add nothing
        }

        $basePath = $this->currentBasePath();
        $query    = $this->currentQuery();
        $default  = $this->resolver->getDefaultLanguage();

        $out = '';

        foreach ($languages as $lang) {
            $url = $this->absoluteUrl($this->prefixer->addPrefix($basePath, $lang) . $query);

            $out .= sprintf(
                '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
                esc_attr(str_replace('_', '-', $lang)),
                esc_url($url)
            );
        }

        // x-default points at the default-language version of the page.
        $defaultUrl = $this->absoluteUrl($this->prefixer->addPrefix($basePath, $default) . $query);
        $out .= sprintf(
            '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
            esc_url($defaultUrl)
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $out is built only from esc_url()'d values in fixed <link> markup; wp_kses would strip the tags.
        echo $out;
    }

    // ------------------------------------------------------------------

    /**
     * Current request path with any language prefix removed, so each language
     * can apply its own prefix from a clean base.
     */
    private function currentBasePath(): string
    {
        // esc_url_raw, NOT sanitize_text_field: that one strips every
        // percent-encoded octet in a loop (see wp-includes/formatting.php), so
        // a non-Latin slug like /ar/%D8%B9%D9%86/ collapsed to /ar/-/ and every
        // switcher href, hreflang target and redirect built from it pointed at
        // the site root. esc_url_raw keeps %XX and still satisfies the input
        // sanitization sniff.
        $uri  = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        if ($path === '') {
            $path = '/';
        }

        return $this->prefixer->stripPrefix($path);
    }

    private function currentQuery(): string
    {
        $uri   = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $query !== '' ? '?' . $query : '';
    }

    /**
     * Turn a site-relative path into an absolute URL on this site.
     */
    private function absoluteUrl(string $path): string
    {
        return home_url($path);
    }

    /**
     * Reassemble the query + fragment portion from parsed URL parts.
     *
     * @param array<string, mixed> $parts
     */
    private function queryFragment(array $parts): string
    {
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $query . $fragment;
    }
}
