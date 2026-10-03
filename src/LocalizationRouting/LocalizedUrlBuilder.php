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

    public function detectPrefix(string $path): ?string
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return null;
        }

        return $this->resolver->languageForSlug(rawurldecode(explode('/', $trimmed)[0]));
    }

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
            return '/';
        }

        $bare = '/' . $rest . (substr($path, -1) === '/' ? '/' : '');

        return $this->slugs === null ? $bare : $this->slugs->toSource($bare, $prefix);
    }

    public function addPrefix(string $path, string $lang): string
    {
        $bare = $this->stripPrefix($path);

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
