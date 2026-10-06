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
 * language's sitemap — listing a noindex URL is a Search Console warning. Term
 * and author archives are never noindex, so they are always listed.
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

        // Core's entries carry no post id; note it per <loc> while core builds
        // them. Last priority, so the loc recorded is the final one.
        $postIds = [];
        $capture = static function ($entry, $post) use (&$postIds) {
            if (is_array($entry) && isset($entry['loc']) && $post instanceof \WP_Post) {
                $postIds[(string) $entry['loc']] = (int) $post->ID;
            }

            return $entry;
        };

        add_filter('wp_sitemaps_posts_entry', $capture, PHP_INT_MAX, 2);
        try {
            $list = $core->get_url_list($page_num, $sitemap['subtype']);
        } finally {
            remove_filter('wp_sitemaps_posts_entry', $capture, PHP_INT_MAX);
        }

        $translated = $postIds !== [] ? $this->repository->postIdsWithAnyTranslation(array_values($postIds), $sitemap['lang']) : [];

        $out = [];
        foreach ((array) $list as $entry) {
            if (!is_array($entry) || !isset($entry['loc'])) {
                continue;
            }

            $postId = $postIds[(string) $entry['loc']] ?? 0;
            if ($postId > 0 && !isset($translated[$postId])) {
                continue; // noindex in this language — see class doc
            }

            $entry['loc'] = $this->localize((string) $entry['loc'], $sitemap['lang']);
            $out[]        = $entry;
        }

        return $out;
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
     * subtype name => what it mirrors. Built once per request: core's type
     * data runs a count query per subtype, wanted once, not per language.
     *
     * A posts subtype with zero translated posts in a language is skipped (its
     * sitemap would be empty, and an index entry that 404s is a Search Console
     * error) — except "page" while the front page shows latest posts, since
     * core adds the home URL to that sitemap, and home is never noindex.
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
            if ($core !== null) {
                $types[$name] = (array) $core->get_sitemap_type_data();
            }
        }

        $homeInPages = get_option('show_on_front') === 'posts';

        $this->sitemaps = [];
        foreach ($languages as $lang) {
            $translatedTypes = isset($types['posts']) ? $this->repository->postTypesWithAnyTranslation($lang) : [];

            foreach ($types as $provider => $typeData) {
                foreach ($typeData as $type) {
                    $subtype = (string) ($type['name'] ?? '');
                    $pages   = (int) ($type['pages'] ?? 0);
                    if ($pages < 1) {
                        continue;
                    }
                    if ($provider === 'posts' && !isset($translatedTypes[$subtype]) && !($subtype === 'page' && $homeInPages)) {
                        continue;
                    }

                    $name = strtolower($lang) . '-' . $provider . ($subtype !== '' ? '-' . $subtype : '');
                    $this->sitemaps[$name] = ['lang' => $lang, 'provider' => $provider, 'subtype' => $subtype, 'pages' => $pages];
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
