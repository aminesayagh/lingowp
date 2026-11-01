<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

/**
 * Adds language-prefixed copies of WordPress's compiled rewrite rules and
 * exposes the active language as the query var "lingowp_lang".
 *
 * Examples (enabled = en, fr, es; default = en, no default prefix):
 *   /fr            -> front page, lang=fr
 *   /fr/about      -> same native rule as /about, lang=fr
 *   /fr/my-account/orders -> same native endpoint rule as /my-account/orders
 *
 * The language prefix is literal, not a regex capture, so native $matches[n]
 * references keep the same indexes after prefixing.
 *
 * When slug translation is on, this class also normalizes the incoming path's
 * translated segments back to real slugs before WordPress matches a rule — see
 * normalizeRequestPath(). The two belong together: the rules below are what
 * makes /fr/<anything>/ reachable, and that normalization is what makes
 * <anything> a slug WordPress can actually find.
 */
class LanguageRouteRegistrar
{
    public const REWRITE_RULES_VERSION = '4';

    private const REWRITE_RULES_FILTER_PRIORITY = 9999;

    private static bool $rewriteRulesFilterRegistered = false;

    private ResolveRequestLanguage $resolver;

    private ?SlugMap $slugs;

    /** Set only while core is parsing a slug-translated request. */
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

        // Translated path segments have to become real slugs BEFORE core
        // matches a rewrite rule — see normalizeRequestPath()'s doc for why
        // rewriting the matched query var afterwards cannot work.
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

    /**
     * Rewrite the request path's translated slugs back to the slugs WordPress
     * actually stores, before core looks at the path at all.
     *
     * This is a filter on 'do_parse_request' purely for its timing: it fires at
     * the top of WP::parse_request(), before core reads $_SERVER['PATH_INFO']
     * and $_SERVER['REQUEST_URI']. The answer is always "yes, carry on parsing";
     * only the side effect matters.
     *
     * WHY NOT the matched query var, which is where this logic used to live:
     * matching the regex is not the same as matching the route. With a
     * %postname%-leading permalink structure WordPress enables verbose page
     * rules, and before accepting a page rule it calls get_page_by_path() with
     * the path as requested — the TRANSLATED path — and skips the rule when no
     * such page exists. Execution then falls through to the post rule, so
     * correcting 'name' afterwards hands a page's slug to a post query: a 404,
     * or another post entirely. Custom post types fail a second way, since
     * WP_Query::parse_query() re-copies the untranslated CPT query var over
     * 'name'/'pagename' after the 'request' filter has run. Both are covered by
     * tests/db/slug_routing_bugs_it.php. Normalizing the path instead fixes
     * every route at once and needs no list of query vars to keep complete.
     *
     * Still no new rewrite rules, no REWRITE_RULES_VERSION bump and no flush:
     * the cloned rules match a source-slug path exactly as they always did.
     *
     * Only ever acts on a prefixed route, so the language is published by
     * construction — the prefix must resolve to an enabled language, and
     * SlugMap refuses anything else on its own account.
     *
     * @param mixed $continue Whether core should parse the request (untouched).
     * @return mixed
     */
    public function normalizeRequestPath($continue = true)
    {
        // Honour a short circuit: a plugin that already said "do not parse"
        // gets no $_SERVER rewritten underneath it, and core would never fire
        // the parse_request action that restores it.
        if ($this->slugs === null || is_admin() || $continue === false) {
            return $continue;
        }

        // Each copy is normalized on its OWN spelling. Some servers expose
        // PATH_INFO decoded while REQUEST_URI stays percent-encoded, and core
        // prefers PATH_INFO when it is set — so deriving one from the other
        // left the preferred copy still carrying the translated slug, and a
        // non-Latin URL 404ed.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- restored verbatim; normalizeOne() reads a sanitized copy for every decision.
        $rawUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $newUri = $rawUri === '' ? null : $this->normalizeOne($rawUri, true);

        if ($newUri !== null) {
            $this->originalRequestUri = $rawUri;
            $_SERVER['REQUEST_URI']   = $newUri;
        }

        if (isset($_SERVER['PATH_INFO'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- same raw/restore contract as REQUEST_URI above.
            $rawPathInfo = (string) $_SERVER['PATH_INFO'];
            $newPathInfo = $rawPathInfo === '' ? null : $this->normalizeOne($rawPathInfo, false);

            if ($newPathInfo !== null) {
                $this->originalPathInfo = $rawPathInfo;
                $_SERVER['PATH_INFO']   = $newPathInfo;
            }
        }

        return $continue;
    }

    /**
     * One request-path value with its translated segments resolved back to real
     * slugs, or null when there is nothing to change.
     *
     * Only the PATH portion is rebuilt, and it is rebuilt wholesale rather than
     * searched-and-replaced: a str_replace() over the whole value also rewrote
     * matching text inside the query string, so /zz/a-propos/?return=/zz/a-propos/
     * came back with both occurrences changed and the query no longer agreed
     * with the already-parsed $_GET.
     */
    private function normalizeOne(string $raw, bool $hasSiteFolder): ?string
    {
        $cut     = strcspn($raw, '?#');
        $rawPath = substr($raw, 0, $cut);
        $suffix  = substr($raw, $cut);

        // Decisions are made on a sanitized copy — esc_url_raw rather than
        // sanitize_text_field, which strips percent-encoded octets and would
        // destroy a non-Latin path. If sanitizing changed anything, bail:
        // writing the sanitized spelling back would hand core a URL the
        // visitor never sent.
        if (esc_url_raw(wp_unslash($rawPath)) !== $rawPath) {
            return null;
        }

        // REQUEST_URI carries any site folder (/blog/fr/a-propos/), and the
        // language prefix is the segment AFTER it; PATH_INFO is relative to
        // index.php and never carries one. Set the folder aside, map what
        // follows, and put it back as it arrived. "" on a root install.
        $folder = '';
        if ($hasSiteFolder) {
            $split = LocalizedUrlBuilder::splitSiteFolder($rawPath);
            if ($split === null) {
                return null; // outside the WordPress folder: not ours to map
            }
            [$folder, $rawPath] = $split;
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
            return null; // a bare language home has no object slug in it
        }

        // Map ONLY what follows the prefix, and put the prefix back exactly as
        // it arrived. The prefix is a route token, not an object slug: handing
        // the whole path to toSource() let a page whose title translation
        // happened to sanitize to a language slug rewrite the prefix itself,
        // turning /fr/about/ into /french/about/ and /fr/ into /french/.
        $restPath   = '/' . $rest . (substr($rawPath, -1) === '/' ? '/' : '');
        $sourceRest = $this->slugs->toSource($restPath, $lang);
        if ($sourceRest === $restPath) {
            return null;
        }

        return $folder . '/' . $prefix . $sourceRest . $suffix;
    }

    /**
     * Put the real request path back once core has finished parsing, so
     * everything downstream — redirect_canonical's comparison against the
     * requested URL, hreflang, the switcher, analytics — still sees the URL the
     * visitor actually asked for.
     *
     * Armed on both 'parse_request' and 'wp', and idempotent. A plugin that
     * returns false from 'do_parse_request' makes core return before firing
     * 'parse_request' at all, and leaving $_SERVER rewritten for the rest of
     * the request would be far worse than restoring twice.
     */
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

    /**
     * Make WordPress recognize our custom query vars.
     *
     * @param string[] $vars
     * @return string[]
     */
    public function addQueryVar(array $vars): array
    {
        $vars[] = 'lingowp_lang';
        $vars[] = 'lingowp_is_front';

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
