<?php

namespace TransLangs\Rendering\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;

/**
 * Adds language-prefixed copies of WordPress's compiled rewrite rules and
 * exposes the active language as the query var "translangs_lang".
 *
 * Examples (enabled = en, fr, es; default = en, no default prefix):
 *   /fr            -> front page, lang=fr
 *   /fr/about      -> same native rule as /about, lang=fr
 *   /fr/my-account/orders -> same native endpoint rule as /my-account/orders
 *
 * The language prefix is literal, not a regex capture, so native $matches[n]
 * references keep the same indexes after prefixing.
 */
class LanguageRouteRegistrar
{
    public const REWRITE_RULES_VERSION = '3';

    private const REWRITE_RULES_FILTER_PRIORITY = 9999;

    private static bool $rewriteRulesFilterRegistered = false;

    public function __construct(private ResolveRequestLanguage $resolver)
    {
    }

    public function register(): void
    {
        add_filter('query_vars', [$this, 'addQueryVar']);
        $this->registerRewriteRulesFilter();
        add_action('init', [$this, 'maybeRefreshRewriteRules'], self::REWRITE_RULES_FILTER_PRIORITY);
        add_filter('request', [$this, 'filterRequest']);
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
        $currentVersion = (string) get_option('translangs_rewrite_rules_version', '');
        if ($currentVersion === self::REWRITE_RULES_VERSION) {
            return;
        }

        update_option('rewrite_rules', '');
        update_option('translangs_rewrite_rules_version', self::REWRITE_RULES_VERSION, false);

    }

    /**
     * Keep translated front-page URLs aligned with the site's front-page mode.
     * All non-front paths are resolved by the compiled rewrite rules.
     *
     * @param array<string, mixed> $vars Parsed query vars.
     * @return array<string, mixed>
     */
    public function filterRequest(array $vars): array
    {
        if (is_admin()) {
            return $vars;
        }

        $vars = $this->normalizeWooCommerceEndpointVars($vars);

        if (($vars['translangs_is_front'] ?? '') !== '1') {
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

    /**
     * WooCommerce account endpoints like /orders/ and /downloads/ can be parsed
     * as empty strings on prefixed routes. Keep only those base endpoints truthy
     * so WooCommerce treats them as the endpoint landing view.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function normalizeWooCommerceEndpointVars(array $vars): array
    {
        if (empty($vars['translangs_lang'])) {
            return $vars;
        }

        foreach (['orders', 'downloads'] as $endpoint) {
            if (isset($vars[$endpoint]) && $vars[$endpoint] === '') {
                $vars[$endpoint] = '1';
            }
        }

        return $vars;
    }

    /**
     * Make WordPress recognize our custom query vars.
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
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function addLanguageRules(array $rules): array
    {
        $languages = $this->prefixedLanguages();
        if ($languages === [] || $rules === []) {
            return $rules;
        }

        $languageRules = [];

        foreach ($languages as $lang) {
            $languageRules[$this->languageHomeRegex($lang)] = $this->languageHomeQuery($lang);

            foreach ($rules as $regex => $query) {
                if (!is_string($regex) || !is_string($query) || !$this->isIndexQuery($query)) {
                    continue;
                }

                $prefixedRegex = $this->prefixRegex($regex, $lang);
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
        return 'index.php?translangs_lang=' . rawurlencode($lang) . '&translangs_is_front=1';
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
        $languageQuery = 'translangs_lang=' . rawurlencode($lang);

        if ($query === 'index.php') {
            return 'index.php?' . $languageQuery;
        }

        return 'index.php?' . $languageQuery . '&' . substr($query, strlen('index.php?'));
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
