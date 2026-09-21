<?php

namespace LingoWP\Extraction\Structured;

use LingoWP\Extraction\OptionSourceScanner;

final class InitialDiscoveryScan
{
    public const HOOK        = 'lingowp_initial_discovery_batch';
    public const GROUP       = 'lingowp';
    public const OPTION_FLAG = 'lingowp_needs_initial_scan';

    private const BATCH = 200;

    private \wpdb $wpdb;
    private StructuredSourceScanner $scanner;
    private OptionSourceScanner $optionScanner;

    public function __construct(
        \wpdb $wpdb,
        StructuredSourceScanner $scanner,
        OptionSourceScanner $optionScanner
    ) {
        $this->wpdb          = $wpdb;
        $this->scanner       = $scanner;
        $this->optionScanner = $optionScanner;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runBatch'], 10, 1);
        add_action('init', [$this, 'maybeEnqueue']);
    }

    public function maybeEnqueue(): void
    {
        if (!get_option(self::OPTION_FLAG)) {
            return;
        }
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        delete_option(self::OPTION_FLAG);
        $this->enqueue(['phase' => 'posts', 'after' => 0]);
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
                $this->scanner->refreshPost($id);
            }
            $this->enqueue(['phase' => 'posts', 'after' => (int) end($ids)]);
            return;
        }

        $terms = $this->nextTerms($after);
        if ($terms === []) {
            $this->optionScanner->scan();
            return;
        }
        foreach ($terms as [$ttId, $taxonomy, $name, $description]) {
            $this->scanner->refreshTerm($ttId, $taxonomy, $name, $description);
        }
        $last = end($terms);
        $this->enqueue(['phase' => 'terms', 'after' => (int) $last[0]]);
    }

    private function enqueue(array $cursor): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, ['cursor' => $cursor], self::GROUP);
        }
    }

    private function nextPostIds(int $after): array
    {
        $ids = $this->wpdb->get_col( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT ID FROM {$this->wpdb->posts}
                 WHERE ID > %d AND post_status IN ('publish', 'inherit')
                 ORDER BY ID ASC
                 LIMIT %d",
                $after,
                self::BATCH
            )
        );

        return array_map('intval', (array) $ids);
    }

    private function nextTerms(int $after): array
    {
        $rows = $this->wpdb->get_results( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT tt.term_taxonomy_id AS tt_id, tt.taxonomy AS taxonomy,
                        t.name AS name, tt.description AS description
                 FROM {$this->wpdb->term_taxonomy} tt
                 INNER JOIN {$this->wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tt.term_taxonomy_id > %d
                 ORDER BY tt.term_taxonomy_id ASC
                 LIMIT %d",
                $after,
                self::BATCH
            ),
            ARRAY_A
        );

        return array_map(
            static fn(array $r): array => [
                (int) $r['tt_id'],
                (string) $r['taxonomy'],
                (string) $r['name'],
                (string) $r['description'],
            ],
            (array) $rows
        );
    }
}
