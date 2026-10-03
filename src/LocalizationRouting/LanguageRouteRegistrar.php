<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

class LanguageRouteRegistrar
{
    public const REWRITE_RULES_VERSION = '4';

    private const REWRITE_RULES_FILTER_PRIORITY = 9999;

    private static bool $rewriteRulesFilterRegistered = false;

    private ResolveRequestLanguage $resolver;

    private ?SlugMap $slugs;

    private ?string $originalRequestUri = null;
    private ?string $originalPathInfo = null;

    public function __construct(ResolveRequestLanguage $resolver, ?SlugMap $slugs = null)
    {
        $this->resolver = $resolver;
        $this->slugs    = $slugs;
    }

    public function register(): void
    {
        add_filter('query_vars', [$this, 'addQueryVar']);
        $this->registerRewriteRulesFilter();
        add_action('init', [$this, 'maybeRefreshRewriteRules'], self::REWRITE_RULES_FILTER_PRIORITY);
        add_filter('request', [$this, 'filterRequest']);

        add_filter('do_parse_request', [$this, 'normalizeRequestPath'], 10, 1);
        add_action('parse_request', [$this, 'restoreRequestPath'], 0);
        add_action('wp', [$this, 'restoreRequestPath'], 0);
    }

    public function registerRewriteRulesFilter(): void
    {
        if (self::$rewriteRulesFilterRegistered) {
            return;
        }

        add_filter('rewrite_rules_array', [$this, 'addLanguageRules'], self::REWRITE_RULES_FILTER_PRIORITY);
        self::$rewriteRulesFilterRegistered = true;
    }

    public function maybeRefreshRewriteRules(): void
    {
        $currentVersion = (string) get_option('lingowp_rewrite_rules_version', '');
        if ($currentVersion === self::REWRITE_RULES_VERSION) {
            return;
        }

        update_option('rewrite_rules', '');
        update_option('lingowp_rewrite_rules_version', self::REWRITE_RULES_VERSION, false);
    }

    public function filterRequest(array $vars): array
    {
        if (is_admin()) {
            return $vars;
        }

        $vars = $this->normalizeWooCommerceEndpointVars($vars);

        if (($vars['lingowp_is_front'] ?? '') !== '1') {
            return $vars;
        }

        if ('page' === get_option('show_on_front')) {
            $frontPageId = (int) get_option('page_on_front');
            if ($frontPageId > 0) {
                unset($vars['pagename'], $vars['name']);
                $vars['page_id'] = $frontPageId;
            }
        }

        return $vars;
    }

    public function normalizeRequestPath($continue = true)
    {
        if ($this->slugs === null || is_admin() || $continue === false) {
            return $continue;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- restored verbatim; normalizeOne() reads a sanitized copy for every decision.
        $rawUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $newUri = $rawUri === '' ? null : $this->normalizeOne($rawUri);

        if ($newUri !== null) {
            $this->originalRequestUri = $rawUri;
            $_SERVER['REQUEST_URI']   = $newUri;
        }

        if (isset($_SERVER['PATH_INFO'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- same raw/restore contract as REQUEST_URI above.
            $rawPathInfo = (string) $_SERVER['PATH_INFO'];
            $newPathInfo = $rawPathInfo === '' ? null : $this->normalizeOne($rawPathInfo);

            if ($newPathInfo !== null) {
                $this->originalPathInfo = $rawPathInfo;
                $_SERVER['PATH_INFO']   = $newPathInfo;
            }
        }

        return $continue;
    }

    private function normalizeOne(string $raw): ?string
    {
        $cut     = strcspn($raw, '?#');
        $rawPath = substr($raw, 0, $cut);
        $suffix  = substr($raw, $cut);

        if (esc_url_raw(wp_unslash($rawPath)) !== $rawPath) {
            return null;
        }

        $trimmed = trim($rawPath, '/');
        if ($trimmed === '') {
            return null;
        }

        $segments = explode('/', $trimmed);
        $prefix   = array_shift($segments);
        $lang     = $this->resolver->languageForSlug(rawurldecode($prefix));
        if ($lang === null) {
            return null;
        }

        $rest = implode('/', $segments);
        if ($rest === '') {
            return null;
        }

        $restPath   = '/' . $rest . (substr($rawPath, -1) === '/' ? '/' : '');
        $sourceRest = $this->slugs->toSource($restPath, $lang);
        if ($sourceRest === $restPath) {
            return null;
        }

        return '/' . $prefix . $sourceRest . $suffix;
    }

    public function restoreRequestPath(): void
    {
        if ($this->originalRequestUri !== null) {
            $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
            $this->originalRequestUri = null;
        }

        if ($this->originalPathInfo !== null) {
            $_SERVER['PATH_INFO'] = $this->originalPathInfo;
            $this->originalPathInfo = null;
        }
    }

    private function normalizeWooCommerceEndpointVars(array $vars): array
    {
        if (empty($vars['lingowp_lang'])) {
            return $vars;
        }

        foreach (['orders', 'downloads'] as $endpoint) {
            if (isset($vars[$endpoint]) && $vars[$endpoint] === '') {
                $vars[$endpoint] = '1';
            }
        }

        return $vars;
    }

    public function addQueryVar(array $vars): array
    {
        $vars[] = 'lingowp_lang';
        $vars[] = 'lingowp_is_front';

        return $vars;
    }

    public function addLanguageRules(array $rules): array
    {
        $languages = $this->prefixedLanguages();
        if ($languages === [] || $rules === []) {
            return $rules;
        }

        $languageRules = [];

        foreach ($languages as $lang) {
            $slug = $this->resolver->slugForLanguage($lang);
            $languageRules[$this->languageHomeRegex($slug)] = $this->languageHomeQuery($lang);

            foreach ($rules as $regex => $query) {
                if (!is_string($regex) || !is_string($query) || !$this->isIndexQuery($query)) {
                    continue;
                }

                $prefixedRegex = $this->prefixRegex($regex, $slug);
                if ($prefixedRegex === null || isset($languageRules[$prefixedRegex])) {
                    continue;
                }

                $languageRules[$prefixedRegex] = $this->injectLanguageQueryVar($query, $lang);
            }
        }

        return $languageRules + $rules;
    }

    private function languageHomeRegex(string $lang): string
    {
        return '^' . preg_quote($lang, '#') . '/?$';
    }

    private function languageHomeQuery(string $lang): string
    {
        return 'index.php?lingowp_lang=' . rawurlencode($lang) . '&lingowp_is_front=1';
    }

    private function isIndexQuery(string $query): bool
    {
        return $query === 'index.php' || str_starts_with($query, 'index.php?');
    }

    private function prefixRegex(string $regex, string $lang): ?string
    {
        $regex = trim($regex);
        if ($regex === '') {
            return null;
        }

        $body = ltrim($regex, '^/');

        if ($body === '' || $body === '$' || $body === '?$') {
            return null;
        }

        return '^' . preg_quote($lang, '#') . '/' . $body;
    }

    private function injectLanguageQueryVar(string $query, string $lang): string
    {
        $languageQuery = 'lingowp_lang=' . rawurlencode($lang);

        if ($query === 'index.php') {
            return 'index.php?' . $languageQuery;
        }

        return 'index.php?' . $languageQuery . '&' . substr($query, strlen('index.php?'));
    }

    private function prefixedLanguages(): array
    {
        $enabled = $this->resolver->getEnabledLanguages();
        $default = $this->resolver->getDefaultLanguage();
        $prefixDefault = (bool) get_option('lingowp_prefix_default_language', false);

        if ($prefixDefault) {
            return $enabled;
        }

        return array_values(array_filter(
            $enabled,
            static fn(string $lang): bool => $lang !== $default
        ));
    }
}
