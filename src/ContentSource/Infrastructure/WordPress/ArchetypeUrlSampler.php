<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\ScanTarget;
use TransLangs\ContentSource\Domain\UrlSampler;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Generates representative scan targets by querying WordPress data.
 *
 * Sampling rules:
 *   - Front page          always (page or blog roll).
 *   - Static pages        up to MAX_STATIC_PAGES; if the site has more, only
 *                         that many are sampled (one per unique template is
 *                         enough — not one per page).
 *   - Posts               1 to MAX_POSTS_SAMPLE recent published posts.
 *   - Product archive     the WooCommerce shop page, when WC is active.
 *   - Product archetypes  one simple, one variable, one sale, one out-of-stock
 *                         product — covers the distinct rendering branches.
 *   - CPTs                one archive + up to MAX_CPT_SAMPLE singles per type.
 *   - Taxonomy archives   top category and top tag by post count.
 *   - Search page         home/?s=a (one word triggers the template).
 *   - 404 page            a URL that cannot exist.
 *
 * All WordPress function calls are in this class; nothing above this layer
 * touches the WP API directly.
 */
class ArchetypeUrlSampler implements UrlSampler
{
    /** Do not sample more static pages than this. */
    private const MAX_STATIC_PAGES = 100;

    /** Blog posts sample size. */
    private const MAX_POSTS_SAMPLE = 3;

    /** Singles per CPT. */
    private const MAX_CPT_SAMPLE = 2;

    /**
     * CPT names excluded from the CPT scan (handled by dedicated readers or
     * not meaningfully translatable as rendered templates).
     */
    private const SKIP_CPT = [
        'attachment', 'page', 'post', 'product', 'product_variation',
        'nav_menu_item', 'revision', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template',
        'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_face', 'wp_font_family', 'wp_pattern',
    ];

    /**
     * @return ScanTarget[]
     */
    public function sample(): array
    {
        $targets = [];

        $this->addFrontPage($targets);
        $this->addStaticPages($targets);
        $this->addPosts($targets);

        if ($this->isWooCommerceActive()) {
            $this->addProductArchive($targets);
            $this->addProductSamples($targets);
        }

        $this->addCptSamples($targets);
        $this->addTaxonomyArchives($targets);
        $this->addSearchPage($targets);
        $this->addNotFoundPage($targets);

        return $targets;
    }

    // ── Front page ─────────────────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addFrontPage(array &$targets): void
    {
        $showOnFront = (string) get_option('show_on_front', 'posts');
        $frontPageId = (int) get_option('page_on_front', 0);

        if ($showOnFront === 'page' && $frontPageId > 0) {
            $targets[] = new ScanTarget(
                home_url('/'),
                'front_page',
                TranslationSourceType::PAGE,
                $frontPageId
            );
        } else {
            // Blog roll: no single owning entity.
            $targets[] = new ScanTarget(
                home_url('/'),
                'front_page',
                TranslationSourceType::POST,
                0
            );
        }
    }

    // ── Static pages ───────────────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addStaticPages(array &$targets): void
    {
        $ids = get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => self::MAX_STATIC_PAGES,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'fields'           => 'ids',
        ]);

        foreach ($ids as $id) {
            $url = get_permalink($id);
            if ($url === false || $url === '') {
                continue;
            }

            $targets[] = new ScanTarget((string) $url, 'static_page', TranslationSourceType::PAGE, (int) $id);
        }
    }

    // ── Blog posts ─────────────────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addPosts(array &$targets): void
    {
        $ids = get_posts([
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => self::MAX_POSTS_SAMPLE,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'fields'           => 'ids',
        ]);

        foreach ($ids as $id) {
            $url = get_permalink($id);
            if ($url === false || $url === '') {
                continue;
            }

            $targets[] = new ScanTarget((string) $url, 'post', TranslationSourceType::POST, (int) $id);
        }
    }

    // ── WooCommerce ────────────────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addProductArchive(array &$targets): void
    {
        $url = get_post_type_archive_link('product');
        if ($url === false || $url === '') {
            return;
        }

        $targets[] = new ScanTarget((string) $url, 'product_archive', TranslationSourceType::PRODUCT, 0);
    }

    /** @param ScanTarget[] $targets */
    private function addProductSamples(array &$targets): void
    {
        $this->addProductByType($targets, 'simple',   'product_simple');
        $this->addProductByType($targets, 'variable', 'product_variable');
        $this->addSaleProduct($targets);
        $this->addOutOfStockProduct($targets);
    }

    /** @param ScanTarget[] $targets */
    private function addProductByType(array &$targets, string $typeTerm, string $archetype): void
    {
        $ids = get_posts([
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'suppress_filters' => true,
            'fields'           => 'ids',
            'tax_query'        => [[
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => [$typeTerm],
            ]],
        ]);

        foreach ($ids as $id) {
            $url = get_permalink($id);
            if ($url !== false && $url !== '') {
                $targets[] = new ScanTarget(
                    (string) $url,
                    $archetype,
                    TranslationSourceType::PRODUCT,
                    (int) $id
                );
            }
        }
    }

    /** @param ScanTarget[] $targets */
    private function addSaleProduct(array &$targets): void
    {
        if (!function_exists('wc_get_product_ids_on_sale')) {
            return;
        }

        $saleIds = wc_get_product_ids_on_sale();

        if (empty($saleIds)) {
            return;
        }

        $id  = (int) $saleIds[0];
        $url = get_permalink($id);

        if ($url !== false && $url !== '') {
            $targets[] = new ScanTarget(
                (string) $url,
                'product_sale',
                TranslationSourceType::PRODUCT,
                $id
            );
        }
    }

    /** @param ScanTarget[] $targets */
    private function addOutOfStockProduct(array &$targets): void
    {
        $ids = get_posts([
            'post_type'        => ['product', 'product_variation'],
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'suppress_filters' => true,
            'fields'           => 'ids',
            'meta_query'       => [[
                'key'   => '_stock_status',
                'value' => 'outofstock',
            ]],
        ]);

        foreach ($ids as $id) {
            $url = get_permalink($id);
            if ($url !== false && $url !== '') {
                $targets[] = new ScanTarget(
                    (string) $url,
                    'product_out_of_stock',
                    TranslationSourceType::PRODUCT,
                    (int) $id
                );
                break;
            }
        }
    }

    // ── Custom post types ──────────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addCptSamples(array &$targets): void
    {
        $cpts = get_post_types(['public' => true, '_builtin' => false], 'names');

        foreach ($cpts as $cpt) {
            if (in_array($cpt, self::SKIP_CPT, true)) {
                continue;
            }

            // Archive (if the post type declares one).
            $archiveUrl = get_post_type_archive_link($cpt);
            if ($archiveUrl !== false && $archiveUrl !== '') {
                $targets[] = new ScanTarget((string) $archiveUrl, $cpt . '_archive', $cpt, 0);
            }

            // A handful of singles.
            $ids = get_posts([
                'post_type'        => $cpt,
                'post_status'      => 'publish',
                'posts_per_page'   => self::MAX_CPT_SAMPLE,
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => true,
                'fields'           => 'ids',
            ]);

            foreach ($ids as $id) {
                $url = get_permalink($id);
                if ($url !== false && $url !== '') {
                    $targets[] = new ScanTarget((string) $url, $cpt, $cpt, (int) $id);
                }
            }
        }
    }

    // ── Taxonomy archive pages ─────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addTaxonomyArchives(array &$targets): void
    {
        foreach (['category' => 'category_archive', 'post_tag' => 'tag_archive'] as $taxonomy => $archetype) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => 1,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ]);

            if (!is_array($terms) || empty($terms)) {
                continue;
            }

            $term = $terms[0];

            if (!$term instanceof \WP_Term) {
                continue;
            }

            $url = get_term_link($term);
            if (!is_wp_error($url) && $url !== '') {
                $targets[] = new ScanTarget((string) $url, $archetype, TranslationSourceType::TAXONOMY, 0);
            }
        }
    }

    // ── Search and 404 pages ───────────────────────────────────────────────────

    /** @param ScanTarget[] $targets */
    private function addSearchPage(array &$targets): void
    {
        $url = add_query_arg('s', 'a', home_url('/'));
        $targets[] = new ScanTarget((string) $url, 'search', TranslationSourceType::PAGE, 0);
    }

    /** @param ScanTarget[] $targets */
    private function addNotFoundPage(array &$targets): void
    {
        // A URL that is guaranteed to return a 404 regardless of site content.
        $slug = 'translangs-not-found-' . substr(md5(__FILE__), 0, 8);
        $url  = home_url('/' . $slug . '/');
        $targets[] = new ScanTarget($url, 'not_found', TranslationSourceType::PAGE, 0);
    }

    // ── Guard ──────────────────────────────────────────────────────────────────

    private function isWooCommerceActive(): bool
    {
        return function_exists('wc_get_product') || class_exists('WooCommerce');
    }
}
