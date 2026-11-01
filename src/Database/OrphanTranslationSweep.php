<?php

namespace LingoWP\Database;

/**
 * Reclaims hash-link translation children whose last parent source is gone.
 *
 * The four hash-link children (post/term/html/option translations) carry no FK
 * to their parents, and cannot carry one: many parent rows deliberately share a
 * single child — the same string on 40 posts is 40 sources and one translation —
 * and `original_hash` is only the trailing column of each parent's composite
 * unique key. So `ON DELETE CASCADE` is both undeclarable and semantically
 * wrong here: deleting one of those 40 posts must not destroy the translation
 * the other 39 still render.
 *
 * The correct rule is "drop the child once the LAST parent holding its hash
 * disappears" — reference counting, which foreign keys cannot express. Hence
 * this sweep. Orphans arise from two places: carry-forward after a source edit
 * (the old hash keeps its child) and deletion of a post/term/option (the parent
 * cascades from WordPress, the hash-link child does not).
 *
 * `KEY idx_hash (original_hash)` exists on every parent to make this join cheap.
 */
final class OrphanTranslationSweep
{
    public const HOOK  = 'lingowp_orphan_sweep';
    public const GROUP = 'lingowp';

    /** Read by DatabaseHealthReport as "Orphan sweep (types 4-5)". */
    public const OPTION_LAST_RUN = 'lingowp_age_out_last_run';

    /**
     * Rows per table per run. A multi-table DELETE cannot take LIMIT, so each
     * table is swept with a single-table correlated DELETE instead of a JOIN.
     */
    private const BATCH = 2000;

    /**
     * Never reclaim a child younger than this. Discovery always writes the parent
     * before the translation, but a grace period means any flow that ever writes
     * them in the other order loses nothing — the row simply survives to the next
     * run. Cheap insurance against silently destroying a fresh translation.
     */
    private const MIN_AGE_SECONDS = 3600;

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', [$this, 'maybeSchedule']);
    }

    /** Ensure the daily job exists. Idempotent; the guard is one indexed lookup. */
    public function maybeSchedule(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
            return;
        }

        as_schedule_recurring_action(time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, [], self::GROUP);
    }

    /**
     * Delete orphaned children across the four hash-link tables.
     *
     * @return int rows reclaimed this run
     */
    public function run(): int
    {
        $parents = TranslationMemorySchema::parentTables();

        // created_at is written with current_time('mysql') — site-local, not GMT —
        // so the cutoff has to be built in the same frame to compare correctly.
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::MIN_AGE_SECONDS); // phpcs:ignore WordPress.DateTime

        $deleted  = 0;
        $hitLimit = false;

        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $parentKey) {
            $parent = $parents[$parentKey] ?? null;
            if ($parent === null) {
                continue;
            }
            $child = $parent . '_translation';

            // phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
            $rows = (int) $this->wpdb->query($this->wpdb->prepare( // phpcs:ignore WordPress.DB
                "DELETE FROM {$child}
                 WHERE created_at < %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$parent} p
                       WHERE p.original_hash = {$child}.original_hash
                   )
                 LIMIT %d",
                $cutoff,
                self::BATCH
            ));
            // phpcs:enable

            $deleted += $rows;
            $hitLimit = $hitLimit || $rows >= self::BATCH;
        }

        update_option(self::OPTION_LAST_RUN, current_time('mysql'), false);

        // A full batch means more remain; drain now instead of waiting a day.
        if ($hitLimit && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [], self::GROUP);
        }

        return $deleted;
    }
}
