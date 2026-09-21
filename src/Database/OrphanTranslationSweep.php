<?php

namespace LingoWP\Database;

final class OrphanTranslationSweep
{
    public const HOOK  = 'lingowp_orphan_sweep';
    public const GROUP = 'lingowp';

    public const OPTION_LAST_RUN = 'lingowp_age_out_last_run';

    private const BATCH = 2000;

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

    public function run(): int
    {
        $parents = TranslationMemorySchema::parentTables();

        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::MIN_AGE_SECONDS); // phpcs:ignore WordPress.DateTime

        $deleted  = 0;
        $hitLimit = false;

        foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $parentKey) {
            $parent = $parents[$parentKey] ?? null;
            if ($parent === null) {
                continue;
            }
            $child = $parent . '_translation';

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

            $deleted += $rows;
            $hitLimit = $hitLimit || $rows >= self::BATCH;
        }

        update_option(self::OPTION_LAST_RUN, current_time('mysql'), false);

        if ($hitLimit && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [], self::GROUP);
        }

        return $deleted;
    }
}
