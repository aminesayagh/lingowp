<?php

namespace TransLangs\Frontend;

/**
 * Registers rewrite rules that map language-prefixed URLs onto the original
 * WordPress routes, and exposes the active language as the query var
 * "translangs_lang" (read by the LanguageResolver).
 *
 * Examples (enabled = en, fr, es; default = en, no default prefix):
 *   /fr            -> front page, lang=fr
 *   /fr/about      -> /about,      lang=fr
 *   /fr/blog/post  -> /blog/post,  lang=fr
 *
 * The rule only matches enabled, non-default language codes, so ordinary URLs
 * like /about or /blog are never hijacked.
 */
class Router
{
    public function __construct(private LanguageResolver $resolver)
    {
    }

    public function register(): void
    {
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('init', [$this, 'addRewriteRules']);
        add_filter('request', [$this, 'filterRequest']);
    }

    /**
     * Safety net that runs for every request after WordPress parses it.
     *
     * The rewrite rules capture the language for clean permalinks, but they
     * lean on "pagename", which only resolves pages. Here we instead detect a
     * leading language segment in the raw request path, set the language, and
     * rewrite the internal path so WordPress resolves it normally — whether it
     * is a page, post, category, archive, or custom post type.
     *
     * @param array<string, mixed> $vars Parsed query vars.
     * @return array<string, mixed>
     */
    public function filterRequest(array $vars): array
    {
        if (is_admin()) {
            return $vars;
        }

        $segment = $this->firstPathSegment();

        if ($segment === null || !in_array($segment, $this->prefixedLanguages(), true)) {
            return $vars;
        }

        // Record the active language.
        $vars['translangs_lang'] = $segment;

        // Drop the misleading pagename hint from the rewrite rule; let WordPress
        // re-resolve the remaining path itself.
        unset($vars['pagename']);

        $rest = $this->pathAfterPrefix($segment);

        if ($rest === '') {
            // Language home page maps to the site front page.
            $vars['translangs_is_front'] = '1';
        } else {
            // Re-feed the remaining path through WordPress's own parser.
            $vars['pagename'] = $rest;
        }

        return $vars;
    }

    /**
     * The first path segment of the current request, lowercased, or null.
     */
    private function firstPathSegment(): ?string
    {
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        return strtolower(explode('/', $path)[0]);
    }

    /**
     * The request path with the language prefix removed.
     */
    private function pathAfterPrefix(string $lang): string
    {
        $path  = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $parts = explode('/', $path);

        array_shift($parts); // remove the language segment

        return implode('/', $parts);
    }

    /**
     * Make WordPress recognize our custom query var.
     *
     * @param string[] $vars
     * @return string[]
     */
    public function addQueryVar(array $vars): array
    {
        $vars[] = 'translangs_lang';
        $vars[] = 'translangs_is_front';

        return $vars;
    }

    /**
     * Register one pair of rules per prefixed language.
     *
     * We add specific rules per language instead of a generic ^([a-z]{2})/
     * catch-all, so non-language paths (e.g. /id/something) are never captured.
     */
    public function addRewriteRules(): void
    {
        foreach ($this->prefixedLanguages() as $lang) {
            // Language home page: /fr  or  /fr/
            add_rewrite_rule(
                '^' . $lang . '/?$',
                'index.php?translangs_lang=' . $lang,
                'top'
            );

            // Everything under the prefix: /fr/anything/here
            // pagename is set as a best-effort hint; the real route is resolved
            // by WordPress from the captured path on the rewritten request.
            add_rewrite_rule(
                '^' . $lang . '/(.+?)/?$',
                'index.php?translangs_lang=' . $lang . '&pagename=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Languages that should carry a URL prefix.
     *
     * The default language is excluded unless the admin enabled
     * "prefix default language" in settings.
     *
     * @return string[]
     */
    private function prefixedLanguages(): array
    {
        $enabled = $this->resolver->getEnabledLanguages();
        $default = $this->resolver->getDefaultLanguage();
        $prefixDefault = (bool) get_option('translangs_prefix_default_language', false);

        if ($prefixDefault) {
            return $enabled;
        }

        return array_values(array_filter(
            $enabled,
            static fn(string $lang): bool => $lang !== $default
        ));
    }
}
