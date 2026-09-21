<?php

namespace LingoWP\Setup;

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
            'lingowp_initial_discovery_batch',
            'lingowp_html_crawl_batch',
            'lingowp_seo_rescan_batch',
            'lingowp_orphan_sweep',
        ] as $hook) {
            as_unschedule_all_actions($hook, [], 'lingowp');
        }
    }
}
