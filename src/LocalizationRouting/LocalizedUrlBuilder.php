<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

class LocalizedUrlBuilder
{
    private ResolveRequestLanguage $resolver;

    public function __construct(ResolveRequestLanguage $resolver)
    {
        $this->resolver = $resolver;
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

        return '/' . $rest;
    }

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
