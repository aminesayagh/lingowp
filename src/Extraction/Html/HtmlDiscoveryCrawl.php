<?php

namespace LingoWP\Extraction\Html;

final class HtmlDiscoveryCrawl
{
    public const HOOK        = 'lingowp_html_crawl_batch';
    public const GROUP       = 'lingowp';
    public const OPTION_FLAG = 'lingowp_needs_html_crawl';

    private const STALE_GRACE_DAYS = 30;

    private const BATCH = 15;

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

        add_action('activated_plugin', [$this, 'onPluginOrThemeChanged']);
        add_action('switch_theme', [$this, 'onPluginOrThemeChanged']);

        add_filter('comment_class', [$this, 'skipCommentClass'], 10, 1);

        foreach (CrawlSkipHooks::redundantWithStructuredResolvers() as $hook) {
            add_filter($hook, [$this, 'skipInlineField'], 20, 1);
        }
        foreach (CrawlSkipHooks::dynamicallyComputed() as $hook) {
            add_filter($hook, [$this, 'skipInlineField'], 20, 1);
        }
        foreach (CrawlSkipHooks::blockLevel() as $hook) {
            add_filter($hook, [$this, 'skipBlockField'], 20, 1);
        }
    }

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

    public function onPluginOrThemeChanged($plugin = ''): void
    {
        unset($plugin);
        $this->start();
    }

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

        $this->crawlUrl(home_url('/'));

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
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body !== '') {
            $this->htmlDiscovery->discover($body, $url);
        }
    }

    private function nextPostIds(int $after): array
    {
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

        return array_map('intval', (array) $ids);
    }

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

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(
            static fn(array $r): array => [(int) $r['tt_id'], (int) $r['term_id'], (string) $r['taxonomy']],
            (array) $rows
        );
    }

    public function skipCommentClass($classes): array
    {
        if (!CrawlRequestMarker::isCrawlRequest() || !is_array($classes)) {
            return (array) $classes;
        }

        $classes[] = 'notranslate';

        return $classes;
    }

    public function skipInlineField($value): string
    {
        if (!CrawlRequestMarker::isCrawlRequest()) {
            return (string) $value;
        }

        return '<span data-lingowp-skip="1">' . $value . '</span>';
    }

    public function skipBlockField($value): string
    {
        if (!CrawlRequestMarker::isCrawlRequest()) {
            return (string) $value;
        }

        return '<div data-lingowp-skip="1">' . $value . '</div>';
    }
}
