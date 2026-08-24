<?php
/**
 * Runs only when the plugin is deleted from the WordPress admin.
 *
 * This is where destructive cleanup belongs (not deactivation).
 * Tables are dropped in FK-safe order: child tables first, parent last.
 *
 * @package TransLangs
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Deactivation normally clears these first. Repeat the cleanup when Action
// Scheduler is available so direct/programmatic uninstalls are safe too.
if (function_exists('as_unschedule_all_actions')) {
    foreach ([
        'translangs_initial_discovery_batch',
        'translangs_html_crawl_batch',
        'translangs_seo_rescan_batch',
        'translangs_orphan_sweep',
    ] as $hook) {
        as_unschedule_all_actions($hook, [], 'translangs');
    }
}

// Drop tables in FK-safe order: children before the parents they reference.
$tables = [
    // v5 children
    $wpdb->prefix . 'tl_post_translation',
    $wpdb->prefix . 'tl_meta_translation',
    $wpdb->prefix . 'tl_term_translation',
    $wpdb->prefix . 'tl_html_translation',
    $wpdb->prefix . 'tl_option_translation',
    // v5 parents
    $wpdb->prefix . 'tl_post',
    $wpdb->prefix . 'tl_meta',
    $wpdb->prefix . 'tl_term',
    $wpdb->prefix . 'tl_html',
    $wpdb->prefix . 'tl_option',
];

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $table); // nosemgrep: wpdb-interpolated-sql -- table name only, from the hardcoded $tables list above; no values interpolated
}

// Delete all plugin options.
$options = [
    'translangs_db_version',
    'translangs_active_lang',
    'translangs_existing_lang',
    'translangs_source_locale',
    'translangs_source_language',
    'translangs_default_language',
    'translangs_enabled_languages',
    'translangs_prefix_default_language',
    'translangs_cookie_preference',
    'translangs_browser_detection',
    'translangs_auto_redirect',
    'translangs_url_structure_initialized',
    'translangs_rewrite_rules_version',
    'translangs_age_out_last_run',
    'translangs_needs_initial_scan',
    'translangs_needs_html_crawl',
    // Onboarding / backend connection.
    'translangs_languages',
    'translangs_categories',
    'translangs_onboarding_completed',
    'translangs_api_token',
    'translangs_site_id',
    'translangs_owner_email',
    'translangs_api_origin',
    // Gettext workspace state.
    'translangs_gettext_domains',
    'translangs_gettext_ignored_units',
];

foreach ($options as $option) {
    delete_option($option);
}

// Onboarding transients (if present).
delete_transient('translangs_last_site_sync_error');
delete_transient('translangs_backend_status');
delete_transient('translangs_entitlements_cache');
delete_transient('translangs_wplang_transition_notice');

// Per-domain gettext percentage transients have hashed suffixes.
$transientPrefix        = '_transient_translangs_gettext_percent_';
$transientTimeoutPrefix = '_transient_timeout_translangs_gettext_percent_';
$likeTransient          = $wpdb->esc_like($transientPrefix) . '%';
$likeTimeout            = $wpdb->esc_like($transientTimeoutPrefix) . '%';
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $likeTransient,
        $likeTimeout
    )
);
