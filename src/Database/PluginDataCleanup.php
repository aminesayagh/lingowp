<?php

namespace LingoWP\Database;

class PluginDataCleanup
{
    private const SCHEDULED_HOOKS = [
        'lingowp_initial_discovery_batch',
        'lingowp_html_crawl_batch',
        'lingowp_seo_rescan_batch',
        'lingowp_orphan_sweep',
    ];

    private const OPTIONS = [
        'lingowp_db_version',
        'lingowp_url_structure_initialized',
        'lingowp_rewrite_rules_version',
        'lingowp_age_out_last_run',
        'lingowp_needs_initial_scan',
        'lingowp_needs_html_crawl',
        'lingowp_enabled_languages',
        'lingowp_prefix_default_language',
        'lingowp_cookie_preference',
        'lingowp_browser_detection',
        'lingowp_auto_redirect',
        'lingowp_source_locale',
        'lingowp_source_language',
        'lingowp_languages',
        'lingowp_active_lang',
        'lingowp_existing_lang',
        'lingowp_default_language',
        'lingowp_onboarding_completed',
        'lingowp_categories',
        'lingowp_api_token',
        'lingowp_site_id',
        'lingowp_owner_email',
        'lingowp_synced_admin_email',
        'lingowp_api_origin',
        'lingowp_pending_addon_activations',
        'lingowp_woo_attribute_labels',
        'lingowp_woo_tax_rate_names',
        'lingowp_gettext_domains',
        'lingowp_gettext_ignored_units',
        'lingowp_gettext_scanned_domains',
        'lingowp_gettext_translated_counts',
    ];

    private const TRANSIENTS = [
        'lingowp_last_site_sync_error',
        'lingowp_backend_status',
        'lingowp_entitlements_cache',
        'lingowp_wplang_transition_notice',
    ];

    private const TRANSIENT_PREFIXES = [
        'lingowp_gettext_percent_',
        'lingowp_gettext_pack_',
    ];

    public static function run(\wpdb $wpdb): void
    {
        self::cancelScheduledActions();

        TranslationMemorySchema::dropAllTables($wpdb);

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }

        foreach (self::TRANSIENTS as $transient) {
            delete_transient($transient);
        }

        self::deletePrefixedTransients($wpdb);
        self::removeTranslationFiles();
    }

    private static function removeTranslationFiles(): void
    {
        $root = \LingoWP\GettextDomains\GettextPoImporter::baseDir();
        if (!is_dir($root)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- deleting the plugin's own files on uninstall
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    private static function cancelScheduledActions(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        foreach (self::SCHEDULED_HOOKS as $hook) {
            as_unschedule_all_actions($hook, [], 'lingowp');
        }
    }

    private static function deletePrefixedTransients(\wpdb $wpdb): void
    {
        foreach (self::TRANSIENT_PREFIXES as $prefix) {
            $likeValue   = $wpdb->esc_like('_transient_' . $prefix) . '%';
            $likeTimeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $likeValue,
                    $likeTimeout
                )
            );
        }
    }
}
