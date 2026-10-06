<?php

namespace LingoWP\Resolution\Seo;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;

/**
 * Translated pages in WordPress core's XML sitemap (wp-sitemap.xml).
 *
 * One extra core sitemap provider, "languages", listed in the core index like
 * any other. For every published non-default language it mirrors core's own
 * posts / taxonomies / users sitemaps — same paging, same query rules, since
 * it asks core's providers for their URL lists — and rewrites each <loc> with
 * the same LocalizedUrlBuilder call HreflangPresenter makes, so the sitemap and
 * the hreflang tags can never disagree on a page's URL. Draft languages are
 * never listed: getEnabledLanguages() is published languages only.
 *
 * Sitemap URL: wp-sitemap-languages-{lang}-{provider}[-{subtype}]-{page}.xml,
 * e.g. wp-sitemap-languages-fr_fr-posts-page-1.xml — core's route only allows
 * [a-z\d_-] in the subtype, hence the lowercased language code.
 *
 * Untranslated pages: a singular post with no translated unit in a language is
 * noindex there (LanguageAwareRobotsMeta), so it is left out of that
 * language's sitemap — listing a noindex URL is a Search Console warning. Done
 * inside core's own posts query (post__in, see withTranslatedOnly()), so
 * core's paging, published-status and query-arg rules apply to exactly the
 * listed set: no index entry for a page that would come back empty and 404.
 * Term and author archives are never noindex, so they are always listed.
 *
 * With "prefix default language" on, core's own entries get the default
 * language's prefix too (localizeDefaultEntry()), again matching hreflang.
 *
 * No <xhtml:link> alternates: core's renderer accepts only loc/lastmod/
 * changefreq/priority, and hreflang is already in every page's <head>.
 */
final class LanguageSitemapProvider extends \WP_Sitemaps_Provider
{
    /** Core providers mirrored per language. */
    private const CORE_PROVIDERS = ['posts', 'taxonomies', 'users'];

    private ResolveRequestLanguage $resolver;
    private LocalizedUrlBuilder $urls;
    private WpDbSourceRepository $repository;
    private ?\WP_Sitemaps_Registry $registry = null;

    /** @var array<string, array{lang: string, provider: string, subtype: string, pages: int}>|null */
    private ?array $sitemaps = null;

    /** @var array<string, int[]> lang => translated post ids, per request */
    private array $translatedIds = [];

    public function __construct(
        ResolveRequestLanguage $resolver,
        LocalizedUrlBuilder $urls,
        WpDbSourceRepository $repository
    ) {
        $this->name        = 'languages';
        $this->object_type = 'languages';
        $this->resolver    = $resolver;
        $this->urls        = $urls;
        $this->repository  = $repository;
    }

    public function register(): void
    {
        // Fires after core registered its own providers (wp_sitemaps_get_server()).
        add_action('wp_sitemaps_init', function (\WP_Sitemaps $server): void {
            $this->registry = $server->registry;
            $server->registry->add_provider($this->name, $this);
        });

        // Core's own (default-language) entries: with "prefix default language"
        // on, hreflang says /en/about/, so core's /about/ must say so too.
        foreach (['wp_sitemaps_posts_entry', 'wp_sitemaps_posts_show_on_front_entry', 'wp_sitemaps_taxonomies_entry', 'wp_sitemaps_users_entry'] as $hook) {
            add_filter($hook, [$this, 'localizeDefaultEntry'], 10, 1);
        }
    }

    /**
     * @param mixed $entry
     * @return mixed
     */
    public function localizeDefaultEntry($entry)
    {
        $default = $this->resolver->getDefaultLanguage();
        if (!is_array($entry) || !isset($entry['loc']) || !$this->urls->isPrefixed($default)) {
            return $entry;
        }

        $entry['loc'] = $this->localize((string) $entry['loc'], $default);

        return $entry;
    }

    /** @return array<int, array{name: string, pages: int}> */
    public function get_sitemap_type_data()
    {
        $data = [];
        foreach ($this->sitemaps() as $name => $sitemap) {
            $data[] = ['name' => $name, 'pages' => $sitemap['pages']];
        }

        return $data;
    }

    /** @return array<string, true> */
    public function get_object_subtypes()
    {
        return array_fill_keys(array_keys($this->sitemaps()), true);
    }

    /** @param string $object_subtype */
    public function get_max_num_pages($object_subtype = '')
    {
        return $this->sitemaps()[(string) $object_subtype]['pages'] ?? 0;
    }

    /**
     * @param int    $page_num
     * @param string $object_subtype
     * @return array<int, array<string, string>>
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        $sitemap = $this->sitemaps()[(string) $object_subtype] ?? null;
        $core    = $sitemap !== null ? $this->coreProvider($sitemap['provider']) : null;
        if ($sitemap === null || $core === null) {
            return [];
        }

        if ($this->isVetoed($sitemap, (int) $page_num)) {
            return [];
        }

        $list = $this->withTranslatedOnly($sitemap, static fn() => $core->get_url_list($page_num, $sitemap['subtype']));

        $out = [];
        foreach ((array) $list as $entry) {
            if (!is_array($entry) || !isset($entry['loc'])) {
                continue;
            }

            $entry['loc'] = $this->localize((string) $entry['loc'], $sitemap['lang']);
            $out[]        = $entry;
        }

        return $out;
    }

    /**
     * Run a core posts-provider call restricted to the posts translated in
     * $sitemap's language (the same rule as LanguageAwareRobotsMeta's
     * noindex), by adding post__in to core's own query. The pre_url_list /
     * pre_max_num_pages short-circuits are forced off for the call: a caching
     * plugin answering them would hand back the unrestricted default-language
     * list. An explicit veto through them (0 pages / an empty list — "no
     * sitemap for this type") is honored by the callers via isVetoed() first.
     * Other providers run untouched.
     *
     * ponytail: post__in holds every translated id of the language — fine into
     * the tens of thousands; a join on the translation tables if a site outgrows it.
     *
     * @param array{lang: string, provider: string, subtype: string, pages: int} $sitemap
     * @return mixed
     */
    private function withTranslatedOnly(array $sitemap, callable $call)
    {
        if ($sitemap['provider'] !== 'posts') {
            return $call();
        }

        $ids      = $this->translatedPostIds($sitemap['lang']);
        $restrict = static function ($args) use ($ids) {
            $args = is_array($args) ? $args : [];

            // Narrow, never widen, what other plugins already asked of core's
            // query: WP_Query ignores post__not_in once post__in is set, so an
            // exclusion is folded into the list instead of being lost.
            $allowed = $ids;
            if (!empty($args['post__in'])) {
                $allowed = array_intersect($allowed, array_map('intval', (array) $args['post__in']));
            }
            if (!empty($args['post__not_in'])) {
                $allowed = array_diff($allowed, array_map('intval', (array) $args['post__not_in']));
            }
            unset($args['post__not_in']);
            $args['post__in'] = $allowed !== [] ? array_values($allowed) : [0]; // [] would mean "no restriction"

            return $args;
        };

        add_filter('wp_sitemaps_posts_query_args', $restrict, PHP_INT_MAX, 1);
        add_filter('wp_sitemaps_posts_pre_url_list', '__return_null', PHP_INT_MAX);
        add_filter('wp_sitemaps_posts_pre_max_num_pages', '__return_null', PHP_INT_MAX);
        try {
            return $call();
        } finally {
            remove_filter('wp_sitemaps_posts_query_args', $restrict, PHP_INT_MAX);
            remove_filter('wp_sitemaps_posts_pre_url_list', '__return_null', PHP_INT_MAX);
            remove_filter('wp_sitemaps_posts_pre_max_num_pages', '__return_null', PHP_INT_MAX);
        }
    }

    /**
     * Whether another plugin explicitly suppressed this posts sitemap through
     * core's short-circuits: 0 from pre_max_num_pages, or (for one page) an
     * empty array from pre_url_list. A populated answer is not a veto — it is
     * a cached default-language list, rebuilt restricted by withTranslatedOnly().
     *
     * @param array{lang: string, provider: string, subtype: string, pages: int} $sitemap
     */
    private function isVetoed(array $sitemap, ?int $page): bool
    {
        if ($sitemap['provider'] !== 'posts') {
            return false;
        }

        $pages = apply_filters('wp_sitemaps_posts_pre_max_num_pages', null, $sitemap['subtype']);
        if ($pages !== null && (int) $pages === 0) {
            return true;
        }

        return $page !== null && apply_filters('wp_sitemaps_posts_pre_url_list', null, $sitemap['subtype'], $page) === [];
    }

    /** @return int[] */
    private function translatedPostIds(string $lang): array
    {
        return $this->translatedIds[$lang] ??= $this->repository->translatedPostIds($lang);
    }

    /**
     * $url as its $lang version: the language-neutral path, then the same
     * addPrefix()/absoluteUrl() pair HreflangPresenter uses. Query kept.
     */
    public function localize(string $url, string $lang): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path  = $this->urls->stripPrefix((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $this->urls->absoluteUrl($this->urls->addPrefix($path === '' ? '/' : $path, $lang)) . $query;
    }

    /**
     * subtype name => what it mirrors, with its page count. Built once per
     * request. Taxonomies/users mirror core's type data as-is; a posts subtype
     * is counted by core under withTranslatedOnly(), so a type with nothing
     * translated has 0 pages and is not listed (core itself keeps "page" at one
     * page while the front page shows latest posts — the home URL lives there).
     *
     * @return array<string, array{lang: string, provider: string, subtype: string, pages: int}>
     */
    private function sitemaps(): array
    {
        if ($this->sitemaps !== null) {
            return $this->sitemaps;
        }

        $default   = $this->resolver->getDefaultLanguage();
        $languages = array_values(array_filter(
            $this->resolver->getEnabledLanguages(),
            static fn(string $lang): bool => $lang !== $default
        ));

        $types = [];
        foreach ($languages === [] ? [] : self::CORE_PROVIDERS as $name) {
            $core = $this->coreProvider($name);
            if ($core === null) {
                continue;
            }
            $types[$name] = $name === 'posts'
                ? array_map(static fn($subtype): array => ['name' => (string) $subtype], array_keys((array) $core->get_object_subtypes()))
                : (array) $core->get_sitemap_type_data();
        }

        $this->sitemaps = [];
        foreach ($languages as $lang) {
            foreach ($types as $provider => $typeData) {
                foreach ($typeData as $type) {
                    $subtype = (string) ($type['name'] ?? '');
                    $entry   = ['lang' => $lang, 'provider' => $provider, 'subtype' => $subtype, 'pages' => (int) ($type['pages'] ?? 0)];
                    if ($provider === 'posts') {
                        $core           = $this->coreProvider('posts');
                        $entry['pages'] = $this->isVetoed($entry, null)
                            ? 0
                            : (int) $this->withTranslatedOnly($entry, static fn() => $core->get_max_num_pages($subtype));
                    }
                    if ($entry['pages'] < 1) {
                        continue;
                    }

                    $this->sitemaps[strtolower($lang) . '-' . $provider . ($subtype !== '' ? '-' . $subtype : '')] = $entry;
                }
            }
        }

        return $this->sitemaps;
    }

    private function coreProvider(string $name): ?\WP_Sitemaps_Provider
    {
        $registry = $this->registry ?? (function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server()->registry : null);
        $provider = $registry !== null ? $registry->get_provider($name) : null;

        return $provider instanceof \WP_Sitemaps_Provider ? $provider : null;
    }
}
