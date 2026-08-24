<?php

namespace TransLangs\Setup;

/**
 * Runs once on plugin deactivation.
 *
 * Deactivation is reversible, so we do NOT drop tables or delete options here.
 * Destructive cleanup belongs in uninstall.php, which runs only on delete.
 * Pending background work is cancelled so deactivated code is never invoked.
 */
class Deactivator
{
    public static function run(): void
    {
        self::cancelScheduledActions();
        flush_rewrite_rules();
    }

    private static function cancelScheduledActions(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        foreach ([
            'translangs_initial_discovery_batch',
            'translangs_html_crawl_batch',
            'translangs_seo_rescan_batch',
            'translangs_orphan_sweep',
        ] as $hook) {
            as_unschedule_all_actions($hook, [], 'translangs');
        }
    }
}
