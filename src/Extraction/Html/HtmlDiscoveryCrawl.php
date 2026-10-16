<?php

namespace LingoWP\Extraction\Html;

/**
 * Background sweep of every known public URL (posts/pages, term archives,
 * home), so hardcoded/third-party HTML is discovered even on pages nobody
 * with edit rights has ever opened — marking mode (PublicRenderPipeline) only
 * runs when a logged-in editor visits a page, which does not guarantee full
 * site coverage on its own.
 *
 * Kept as its own scanner with its own activation flag rather than folded
 * into InitialDiscoveryScan, for the same reason SeoDiscoveryRescan stays
 * separate from it: a network-dependent, partially-failable scan should not
 * share one "is the scan finished?" status with a fast DB-only one.
 *
 * Each fetched URL carries CrawlRequestMarker's token, which the crawler-only
 * data-lingowp-skip filters registered below check for — see CrawlSkipHooks for
 * which hooks are skipped and why. Gated on the crawl marker so this never
 * touches marking-mode or real visitor output. Terms are deliberately not
 * included: get_term()/get_terms() return WP_Term objects, not rendered
 * strings, and wrapping $term->name here would corrupt the value everywhere
 * else it is used.
 */
final class HtmlDiscoveryCrawl
{
    public const HOOK        = 'lingowp_html_crawl_batch';
    public const GROUP       = 'lingowp';
    public const OPTION_FLAG = 'lingowp_needs_html_crawl';

    /** Staleness grace period: see WpDbSourceRepository::flagStaleHtmlSources(). */
    private const STALE_GRACE_DAYS = 30;

    /**
     * Deliberately much smaller than InitialDiscoveryScan's 200: each unit of
     * work here is a blocking wp_remote_get() round-trip, not a cheap DB read,
     * so a large batch risks the single Action Scheduler action running long
     * enough to hit PHP's max_execution_time mid-batch (killing it before the
     * next batch is even enqueued). Many small, fast batches let Action
     * Scheduler's own cadence pace the crawl instead.
     */
    private const BATCH = 15;

    /** Per-URL fetch timeout. Short on purpose — a slow/hanging page should
     * not block the rest of a batch; it just gets deferred to marking mode. */
    private const FETCH_TIMEOUT_SECONDS = 8;

    private \wpdb $wpdb;
    private HtmlDiscovery $htmlDiscovery;

    public function __construct(
        \wpdb $wpdb,
        HtmlDiscovery $htmlDiscovery
    ) {
        $this->wpdb          = $wpdb;
        $this->htmlDiscovery = $htmlDiscovery;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runBatch'], 10, 1);
        add_action('init', [$this, 'maybeEnqueue']);

        // Any plugin/theme can inject arbitrary hardcoded markup, so — unlike
        // SeoDiscoveryRescan's narrow SEO-plugin allow-list — there is no
        // meaningful allow-list to build here; any activation/switch is a
        // reason to re-sweep.
        add_action('activated_plugin', [$this, 'onPluginOrThemeChanged']);
        add_action('switch_theme', [$this, 'onPluginOrThemeChanged']);

        add_filter('comment_class', [$this, 'skipCommentClass'], 10, 1);

        foreach (CrawlSkipHooks::redundantWithStructuredResolvers() as $hook) {
            add_filter($hook, [$this, 'skipInlineField'], 20, 1);
        }
        // Priority 20 must stay after DateResolver's own `wp_date` hook
        // (priority 10) so this wraps whatever string DateResolver produced.
        // Known limitation: every dynamically-computed hook shares this one
        // priority — a future entry needing a different one would need
        // CrawlSkipHooks::DYNAMIC to carry a priority per hook, not just a
        // resolver name. Not worth building until a second entry needs it.
        foreach (CrawlSkipHooks::dynamicallyComputed() as $hook) {
            add_filter($hook, [$this, 'skipInlineField'], 20, 1);
        }
        foreach (CrawlSkipHooks::blockLevel() as $hook) {
            add_filter($hook, [$this, 'skipBlockField'], 20, 1);
        }
    }

    /**
     * Kick off the first batch once, when activation requested a crawl.
     *
     * The flag is only cleared when start() actually queued something. It
     * returns false when Action Scheduler is missing (a plugin bundling a
     * newer copy can lose the race on one request, or the bundled copy failed
     * to load at all) — clearing the flag there would burn the activation
     * request and the crawl would never run again on that site.
     */
    public function maybeEnqueue(): void
    {
        if (!get_option(self::OPTION_FLAG)) {
            return;
        }

        if (!$this->start()) {
            return;
        }

        delete_option(self::OPTION_FLAG);
    }

    /**
     * Entry point for every trigger (activation, plugin/theme change, manual
     * admin refresh). Coalesces: if a batch for this crawl is already pending
     * or in progress, several triggers firing close together (e.g. installing
     * a handful of plugins while setting up a site) enqueue only one sweep.
     */
    public function start(): bool
    {
        if (!function_exists('as_enqueue_async_action')) {
            return false;
        }
        if ($this->isRunning()) {
            return true;
        }

        $this->enqueue(['phase' => 'posts', 'after' => 0]);

        return true;
    }

    /** @param mixed $plugin unused — any plugin/theme change re-triggers, no allow-list */
    public function onPluginOrThemeChanged($plugin = ''): void
    {
        unset($plugin);
        $this->start();
    }

    /** @param array{phase?: string, after?: int} $cursor */
    public function runBatch($cursor = []): void
    {
        $cursor = is_array($cursor) ? $cursor : [];
        $phase  = $cursor['phase'] ?? 'posts';
        $after  = (int) ($cursor['after'] ?? 0);

        if ($phase === 'posts') {
            $ids = $this->nextPostIds($after);
            if ($ids === []) {
                $this->enqueue(['phase' => 'terms', 'after' => 0]);
                return;
            }
            foreach ($ids as $id) {
                $url = get_permalink($id);
                if (is_string($url) && $url !== '') {
                    $this->crawlUrl($url);
                }
            }
            $this->enqueue(['phase' => 'posts', 'after' => (int) end($ids)]);
            return;
        }

        if ($phase === 'terms') {
            $terms = $this->nextTermLinks($after);
            if ($terms === []) {
                $this->enqueue(['phase' => 'home', 'after' => 0]);
                return;
            }
            foreach ($terms as [$ttId, $termId, $taxonomy]) {
                unset($ttId);
                $url = get_term_link($termId, $taxonomy);
                if (is_string($url)) {
                    $this->crawlUrl($url);
                }
            }
            $last = end($terms);
            $this->enqueue(['phase' => 'terms', 'after' => (int) $last[0]]);
            return;
        }

        // 'home' phase: a single one-off pass, then the sweep is complete.
        $this->crawlUrl(home_url('/'));

        // Every URL in scope has had a chance to refresh last_seen_at this
        // cycle, so staleness is judged against a complete pass, not a partial
        // one interrupted mid-crawl.
        $this->htmlDiscovery->flagStale(self::STALE_GRACE_DAYS);
    }

    private function isRunning(): bool
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }

        foreach (['pending', 'in-progress'] as $status) {
            $ids = as_get_scheduled_actions([
                'hook'     => self::HOOK,
                'group'    => self::GROUP,
                'status'   => $status,
                'per_page' => 1,
            ], 'ids');
            if (is_array($ids) && $ids !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param array{phase: string, after: int} $cursor */
    private function enqueue(array $cursor): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, ['cursor' => $cursor], self::GROUP);
        }
    }

    private function crawlUrl(string $url): void
    {
        $response = wp_remote_get(CrawlRequestMarker::urlWithMarker($url), [
            'timeout'   => self::FETCH_TIMEOUT_SECONDS,
            // Loopback request to the site's own domain — matches core's own
            // convention for self-requests (e.g. spawn_cron()'s loopback ping).
            'sslverify' => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied so other plugins' callbacks still run.
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Fail-open: a blocked loopback request, timeout, or non-200 on this
            // one host just defers discovery of that URL to marking mode instead.
            return;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body !== '') {
            $this->htmlDiscovery->discover($body, $url);
        }
    }

    /**
     * @return int[] Next published post/page IDs after the cursor. Narrower
     *   than InitialDiscoveryScan::nextPostIds() on purpose: this only needs
     *   entities with their own fetchable front-end permalink, not every
     *   structured-content-bearing post type.
     */
    private function nextPostIds(int $after): array
    {
        // phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $ids = $this->wpdb->get_col( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT ID FROM {$this->wpdb->posts}
                 WHERE ID > %d AND post_type IN ('post', 'page') AND post_status = 'publish'
                 ORDER BY ID ASC
                 LIMIT %d",
                $after,
                self::BATCH
            )
        );
        // phpcs:enable

        return array_map('intval', (array) $ids);
    }

    /**
     * @return list<array{0:int,1:int,2:string}> [term_taxonomy_id, term_id,
     *   taxonomy] for public/viewable taxonomies only — a non-public taxonomy
     *   (e.g. post formats, nav menus) has no real front-end archive URL to
     *   crawl. Cursor pagination uses term_taxonomy_id (the table's own
     *   sequential key); get_term_link() needs term_id, not
     *   term_taxonomy_id — the two are distinct columns and not
     *   interchangeable once a term is shared across taxonomies.
     */
    private function nextTermLinks(int $after): array
    {
        $taxonomies = function_exists('get_taxonomies') ? get_taxonomies(['public' => true], 'names') : [];
        if ($taxonomies === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($taxonomies), '%s'));

        $sql = "SELECT tt.term_taxonomy_id AS tt_id, tt.term_id AS term_id, tt.taxonomy AS taxonomy
                FROM {$this->wpdb->term_taxonomy} tt
                WHERE tt.term_taxonomy_id > %d AND tt.taxonomy IN ({$placeholders})
                ORDER BY tt.term_taxonomy_id ASC
                LIMIT %d";

        $args = array_merge([$after], array_values($taxonomies), [self::BATCH]);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
        // phpcs:enable

        return array_map(
            static fn(array $r): array => [(int) $r['tt_id'], (int) $r['term_id'], (string) $r['taxonomy']],
            (array) $rows
        );
    }

    // ── crawler-only skip marking ─────────────────────────────────────────────

    /** @param mixed $classes */
    public function skipCommentClass($classes): array
    {
        if (!CrawlRequestMarker::isCrawlRequest() || !is_array($classes)) {
            return (array) $classes;
        }

        $classes[] = 'notranslate';

        return $classes;
    }

    /** @param mixed $value */
    public function skipInlineField($value): string
    {
        if (!CrawlRequestMarker::isCrawlRequest()) {
            return (string) $value;
        }

        return '<span data-lingowp-skip="1">' . $value . '</span>';
    }

    /** @param mixed $value */
    public function skipBlockField($value): string
    {
        if (!CrawlRequestMarker::isCrawlRequest()) {
            return (string) $value;
        }

        // A div, not a span: this wraps block-level markup (post_content), and
        // a span wrapping block children is invalid nesting.
        return '<div data-lingowp-skip="1">' . $value . '</div>';
    }
}
