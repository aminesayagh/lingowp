<?php

namespace LingoWP\Extraction\Structured;

use LingoWP\Extraction\OptionSourceScanner;

/**
 * Initial / activation discovery scan (data pipeline §2, Path B1).
 *
 * Save-time hooks only cover content touched after activation. This walks all
 * existing published posts and terms in Action Scheduler batches, running the
 * same per-entity Path-A logic via StructuredSourceScanner — so a site is fully
 * discovered within minutes of install. Idempotent (uniq_key); re-running is
 * harmless.
 *
 * The first batch is enqueued lazily (on init, when the activation flag is set)
 * rather than at activation time, because Action Scheduler's store may not be
 * ready during the activation request.
 */
final class InitialDiscoveryScan
{
    public const HOOK        = 'lingowp_initial_discovery_batch';
    public const GROUP       = 'lingowp';
    public const OPTION_FLAG = 'lingowp_needs_initial_scan';

    /**
     * Bumped when discovery starts producing units it did not before
     * (2: text inside HTML attributes). Existing content is then rescanned once,
     * on its own: left to each post's next save, the new units would land in
     * the same reconcile as the editor's change, and reconcile's
     * one-removed/one-inserted carry-forward would copy a just-deleted
     * paragraph's translation onto an unrelated alt.
     *
     * The version is stamped only when a full pass completes (runBatch()); a
     * pass that never started or died mid-chain is re-requested, at most once
     * a day (RETRY_TRANSIENT), so a batch that keeps failing cannot loop.
     */
    public const DISCOVERY_VERSION = 2;
    public const VERSION_OPTION    = 'lingowp_discovery_version';
    public const RETRY_TRANSIENT   = 'lingowp_discovery_rescan_requested';

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

    /** Kick off the first batch once, when activation or a discovery upgrade requested a scan. */
    public function maybeEnqueue(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) < self::DISCOVERY_VERSION
            && get_transient(self::RETRY_TRANSIENT) === false
            && function_exists('as_has_scheduled_action')
            && !as_has_scheduled_action(self::HOOK, null, self::GROUP)
        ) {
            set_transient(self::RETRY_TRANSIENT, 1, DAY_IN_SECONDS);
            update_option(self::OPTION_FLAG, 1);
        }

        if (!get_option(self::OPTION_FLAG)) {
            return;
        }
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        delete_option(self::OPTION_FLAG);
        $this->enqueue(['phase' => 'posts', 'after' => 0]);
    }

    /**
     * @param array{phase?: string, after?: int, v?: int} $cursor
     */
    public function runBatch($cursor = []): void
    {
        $cursor = is_array($cursor) ? $cursor : [];

        // A chain queued by an older discovery (no or lower 'v' — e.g. an
        // activation scan still running when the plugin was updated) already
        // skipped the posts behind its cursor with the old rules. Restart it as
        // a full current pass rather than let it stamp the new version.
        if ((int) ($cursor['v'] ?? 1) < self::DISCOVERY_VERSION) {
            $this->enqueue(['phase' => 'posts', 'after' => 0]);
            return;
        }

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
            // ponytail: options are one unbatched pass — the allowlist is branding
            // plus widget/theme_mod rows, tens of rows not thousands. Batch it like
            // posts if a site ever shows up with a pathological widget count.
            $this->optionScanner->scan();
            update_option(self::VERSION_OPTION, self::DISCOVERY_VERSION);
            return; // done
        }
        foreach ($terms as [$ttId, $taxonomy, $name, $description]) {
            $this->scanner->refreshTerm($ttId, $taxonomy, $name, $description);
        }
        $last = end($terms);
        $this->enqueue(['phase' => 'terms', 'after' => (int) $last[0]]);
    }

    /**
     * @param array{phase: string, after: int} $cursor
     */
    private function enqueue(array $cursor): void
    {
        $cursor['v'] = self::DISCOVERY_VERSION; // which discovery rules this chain runs

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, ['cursor' => $cursor], self::GROUP);
        }
    }

    /**
     * @return int[] Next published/inherit post IDs after the cursor.
     */
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

    /**
     * @return list<array{0:int,1:string,2:string,3:string}> [tt_id, taxonomy, name, description]
     */
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
