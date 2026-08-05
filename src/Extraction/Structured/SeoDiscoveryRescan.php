<?php

namespace TransLangs\Extraction\Structured;

use TransLangs\Shared\Source\TranslatableMetaKeys;

/**
 * Re-discovers SEO meta that arrived without firing a save hook
 * (plan-seo-integration-layer, Gap 1).
 *
 * The normal editing loop needs nothing: added/updated/deleted_post_meta already
 * reconcile any key written through update_post_meta(). What they miss is meta
 * written by direct SQL — importers and migration tools — and SEO data that
 * predates Translangs being installed.
 *
 * Deliberately NOT folded into InitialDiscoveryScan: that owns a posts -> terms
 * -> options workflow and a single activation flag, and overloading its status
 * would make "is the initial scan finished?" unanswerable.
 *
 * Scope is narrow on purpose — only posts that already hold one of the SEO keys,
 * only those keys — so this stays cheap enough to run on a plugin activation.
 */
final class SeoDiscoveryRescan
{
    public const HOOK  = 'translangs_seo_rescan_batch';
    public const GROUP = 'translangs';

    /** Mirrors InitialDiscoveryScan::BATCH — same keyset-pagination shape. */
    private const BATCH = 200;

    /**
     * Plugin entrypoints worth reacting to. Rescanning after EVERY activation on
     * the site would be gratuitous.
     */
    private const SEO_PLUGIN_FILES = [
        'wordpress-seo/wp-seo.php',
        'wordpress-seo-premium/wp-seo-premium.php',
        'seo-by-rank-math/rank-math.php',
        'seo-by-rank-math-pro/rank-math-pro.php',
        'wp-seopress/seopress.php',
        'wp-seopress-pro/seopress-pro.php',
    ];

    /**
     * The SEO subset of the translatable allow-list, named explicitly rather
     * than derived from SeoTemplateVariables::supports() — that predicate means
     * "has a known template grammar", not "is an SEO key". Focus-keyword keys
     * are SEO but never templated, so filtering on supports() would silently
     * drop them from this rescan while leaving the normal save-hook discovery
     * path (which reads TranslatableMetaKeys::keys() directly) unaffected.
     */
    private const SEO_KEYS = [
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        '_seopress_titles_title',
        '_seopress_titles_desc',
        '_seopress_analysis_target_kw',
        '_seopress_social_fb_title',
        '_seopress_social_fb_desc',
        '_seopress_social_twitter_title',
        '_seopress_social_twitter_desc',
    ];

    public function __construct(
        private \wpdb $wpdb,
        private StructuredSourceScanner $scanner
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runBatch'], 10, 1);
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 1);
    }

    /**
     * @param mixed $plugin plugin basename, e.g. 'seo-by-rank-math/rank-math.php'
     */
    public function onPluginActivated($plugin = ''): void
    {
        if (in_array((string) $plugin, self::SEO_PLUGIN_FILES, true)) {
            $this->start();
        }
    }

    /**
     * Queue the rescan. Always async: an activation request must not pay for a
     * full-site meta sweep.
     *
     * Also the entry point for the manual action — `activated_plugin` does not
     * fire for silent activation (some update flows), so a user-triggered path is
     * required, not a convenience.
     */
    public function start(): bool
    {
        if (!function_exists('as_enqueue_async_action') || self::seoKeys() === []) {
            return false;
        }

        $this->enqueue(0);

        return true;
    }

    /** @param mixed $after keyset cursor: last post id handled */
    public function runBatch($after = 0): void
    {
        $keys = self::seoKeys();
        if ($keys === []) {
            return;
        }

        $ids = $this->nextPostIds((int) $after, $keys);
        if ($ids === []) {
            return; // done
        }

        foreach ($ids as $postId) {
            foreach ($keys as $key) {
                // Reconciles one key's scope: upserts what is there now and prunes
                // paths that no longer exist. Idempotent, so re-running is safe.
                $this->scanner->refreshPostMetaKey($postId, $key);
            }
        }

        $this->enqueue((int) end($ids));
    }

    private function enqueue(int $after): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, ['after' => $after], self::GROUP);
        }
    }

    /**
     * Posts that actually hold one of the SEO keys, after the cursor. Driving off
     * postmeta rather than off every published post keeps this proportional to the
     * SEO data present, not to the size of the site.
     *
     * @param  string[] $keys
     * @return int[]
     */
    private function nextPostIds(int $after, array $keys): array
    {
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

        $sql = "SELECT DISTINCT post_id FROM {$this->wpdb->postmeta}
                WHERE meta_key IN ({$placeholders}) AND post_id > %d
                ORDER BY post_id ASC LIMIT %d";

        $args = array_merge($keys, [$after, self::BATCH]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$args));

        return array_map('intval', (array) $rows);
    }

    /**
     * SEO_KEYS narrowed to what's actually still allow-listed — never the whole
     * list. A rescan of every allow-listed key would sweep WooCommerce and site
     * custom fields for no reason; intersecting (rather than using SEO_KEYS
     * directly) also respects a site removing one of these via the
     * `translangs_translatable_meta_keys` filter.
     *
     * @return string[]
     */
    private static function seoKeys(): array
    {
        return array_values(array_intersect(TranslatableMetaKeys::keys(), self::SEO_KEYS));
    }
}
