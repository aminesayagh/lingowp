<?php

namespace LingoWP\Resolution\Seo;
use LingoWP\Resolution\RequestContext;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;

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

        add_filter('get_canonical_url', [$this, 'filterCanonical'], 20);
        foreach (self::extraCanonicalFilters() as $hook) {
            add_filter($hook, [$this, 'filterCanonical'], 20);
        }
    }

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

    public function filterCanonical($canonical)
    {
        if (!is_string($canonical) || $canonical === '') {
            return $canonical;
        }

        if (!$this->context->shouldTranslate()) {
            return $canonical;
        }

        $active = $this->resolver->resolve();

        if ($active === $this->resolver->getDefaultLanguage()) {
            return $canonical;
        }

        $parts = wp_parse_url($canonical);

        if ($parts === false || !isset($parts['path'])) {
            return $canonical;
        }

        $newPath = $this->prefixer->addPrefix($parts['path'], $active);

        if ($newPath === $parts['path']) {
            return $canonical;
        }

        return $this->absoluteUrl($newPath . $this->queryFragment($parts));
    }

    public function renderHreflangTags(): void
    {
        if (!$this->context->shouldTranslate()) {
            return;
        }

        if (function_exists('is_404') && is_404()) {
            return;
        }

        $languages = $this->resolver->getEnabledLanguages();

        if (count($languages) < 2) {
            return;
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

        $defaultUrl = $this->absoluteUrl($this->prefixer->addPrefix($basePath, $default) . $query);
        $out .= sprintf(
            '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
            esc_url($defaultUrl)
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $out is built only from esc_url()'d values in fixed <link> markup; wp_kses would strip the tags.
        echo $out;
    }

    private function currentBasePath(): string
    {
        $uri  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        if ($path === '') {
            $path = '/';
        }

        return $this->prefixer->stripPrefix($path);
    }

    private function currentQuery(): string
    {
        $uri   = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $query !== '' ? '?' . $query : '';
    }

    private function absoluteUrl(string $path): string
    {
        return home_url($path);
    }

    private function queryFragment(array $parts): string
    {
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $query . $fragment;
    }
}
