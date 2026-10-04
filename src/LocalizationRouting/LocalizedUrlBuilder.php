<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

class LocalizedUrlBuilder
{
    private ResolveRequestLanguage $resolver;

    private ?SlugMap $slugs;

    public function __construct(ResolveRequestLanguage $resolver, ?SlugMap $slugs = null)
    {
        $this->resolver = $resolver;
        $this->slugs    = $slugs;
    }

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

    public static function sitePath(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        return rtrim((string) parse_url((string) home_url('/'), PHP_URL_PATH), '/');
    }

    public function absoluteUrl(string $path): string
    {
        $parts = function_exists('home_url') ? parse_url((string) home_url('/')) : false;
        if (!is_array($parts) || empty($parts['host'])) {
            return $path;
        }

        $scheme = $parts['scheme'] ?? ((function_exists('is_ssl') && is_ssl()) ? 'https' : 'http');
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $parts['host'] . $port . $path;
    }

    public function detectPrefix(string $path): ?string
    {
        $split = $this->splitSiteFolder($path);

        return $split === null ? null : $this->detectIn($split[1]);
    }

    public function stripPrefix(string $path): string
    {
        $split = $this->splitSiteFolder($path);

        return $split === null ? $path : $split[0] . $this->stripIn($split[1]);
    }

    public function addPrefix(string $path, string $lang): string
    {
        $split = $this->splitSiteFolder($path);

        return $split === null ? $path : $split[0] . $this->addIn($split[1], $lang);
    }

    public static function splitSiteFolder(string $path): ?array
    {
        $folder = self::sitePath();
        if ($folder === '') {
            return ['', $path];
        }

        $length = strlen($folder);
        $head   = substr($path, 0, $length);
        $next   = substr($path, $length, 1);

        if (strcasecmp($head, $folder) !== 0 || ($next !== '' && $next !== '/')) {
            return null;
        }

        $rest = (string) substr($path, $length);

        return [$head, $rest === '' ? '/' : $rest];
    }

    private function detectIn(string $path): ?string
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return null;
        }

        return $this->resolver->languageForSlug(rawurldecode(explode('/', $trimmed)[0]));
    }

    private function stripIn(string $path): string
    {
        $prefix = $this->detectIn($path);

        if ($prefix === null) {
            return $path;
        }

        $trimmed = trim($path, '/');
        $parts   = explode('/', $trimmed);
        array_shift($parts);

        $rest = implode('/', $parts);

        if ($rest === '') {
            return '/';
        }

        $bare = '/' . $rest . (substr($path, -1) === '/' ? '/' : '');

        return $this->slugs === null ? $bare : $this->slugs->toSource($bare, $prefix);
    }

    private function addIn(string $path, string $lang): string
    {
        $bare = $this->stripIn($path);

        if (!$this->isPrefixed($lang)) {
            return $bare === '' ? '/' : $bare;
        }

        $bare = '/' . ltrim($bare, '/');

        if ($this->slugs !== null) {
            $bare = $this->slugs->localize($bare, $lang);
        }

        $slug = $this->resolver->slugForLanguage($lang);

        if ($bare !== '/') {
            return '/' . $slug . $bare;
        }

        $home = '/' . $slug;

        return function_exists('user_trailingslashit') ? user_trailingslashit($home) : $home . '/';
    }
}
