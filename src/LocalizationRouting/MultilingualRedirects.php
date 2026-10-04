<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Resolution\RequestContext;
use LingoWP\Language\Application\ResolveRequestLanguage;

class MultilingualRedirects
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
        add_filter('wp_redirect', [$this, 'preserveLanguagePrefix'], 10, 2);

        add_filter('redirect_canonical', [$this, 'preserveLanguagePrefix'], 10, 2);

        add_action('template_redirect', [$this, 'redirectMiscasedPrefix'], -10);
    }

    public function preserveLanguagePrefix($location, $unused)
    {
        unset($unused);

        if (!is_string($location) || $location === '') {
            return $location;
        }

        if (!$this->context->shouldTranslate() || !$this->resolver->isTranslatedRequest()) {
            return $location;
        }

        $parts = wp_parse_url($location);
        if ($parts === false) {
            return $location;
        }

        if (isset($parts['host']) && $parts['host'] !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return $location;
        }

        $path = $parts['path'] ?? '/';

        if ($this->isNonPublicPath($this->prefixer->splitSiteFolder($path)[1] ?? $path)) {
            return $location;
        }

        $active = $this->resolver->resolve();

        if ($this->prefixer->detectPrefix($path) === $active) {
            return $location;
        }

        $newPath = $this->prefixer->addPrefix($path, $active);

        if ($newPath === $path) {
            return $location;
        }

        $rebuilt = $newPath . $this->queryFragment($parts);

        return isset($parts['host']) ? $this->prefixer->absoluteUrl($rebuilt) : $rebuilt;
    }

    public function redirectMiscasedPrefix(): void
    {
        if (!$this->context->shouldTranslate()) {
            return;
        }

        if (!function_exists('is_404') || !is_404()) {
            return;
        }

        $uri    = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $target = $this->miscasedPrefixTarget($uri);
        if ($target === null) {
            return;
        }

        wp_safe_redirect($this->prefixer->absoluteUrl($target), 301);
        exit;
    }

    public function miscasedPrefixTarget(string $uri): ?string
    {
        $split = $this->prefixer->splitSiteFolder((string) wp_parse_url($uri, PHP_URL_PATH));
        if ($split === null) {
            return null;
        }

        [$folder, $path] = $split;
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return null;
        }

        $segments    = explode('/', $trimmed);
        $correctSlug = $this->correctedPrefixSlug(rawurldecode($segments[0]));
        if ($correctSlug === null) {
            return null;
        }

        $segments[0] = $correctSlug;
        $corrected   = $folder . '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
        $query       = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $corrected . ($query !== '' ? '?' . $query : '');
    }

    public function correctedPrefixSlug(string $requestedSlug): ?string
    {
        foreach ($this->prefixer->prefixedLanguages() as $lang) {
            $slug = $this->resolver->slugForLanguage($lang);
            if ($slug !== '' && strcasecmp($slug, $requestedSlug) === 0) {
                return $slug === $requestedSlug ? null : $slug;
            }
        }

        return null;
    }

    private function isNonPublicPath(string $path): bool
    {
        $trimmed = trim($path, '/');

        return str_starts_with($trimmed, 'wp-admin') || str_starts_with($trimmed, 'wp-login.php');
    }

    private function queryFragment(array $parts): string
    {
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $query . $fragment;
    }
}
