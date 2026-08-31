<?php
/**
 * Runs only when the plugin is deleted from the WordPress admin.
 *
 * This is where destructive cleanup belongs (not deactivation).
 * Tables are dropped in FK-safe order: child tables first, parent last.
 *
 * @package LingoWP
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Deactivation normally clears these first. Repeat the cleanup when Action
// Scheduler is available so direct/programmatic uninstalls are safe too.
if (function_exists('as_unschedule_all_actions')) {
    foreach ([
        'lingowp_initial_discovery_batch',
        'lingowp_html_crawl_batch',
        'lingowp_seo_rescan_batch',
        'lingowp_orphan_sweep',
    ] as $hook) {
        as_unschedule_all_actions($hook, [], 'lingowp');
    }
}

// Drop tables in FK-safe order: children before the parents they reference.
$tables = [
    // children
    $wpdb->prefix . 'lingowp_post_translation',
    $wpdb->prefix . 'lingowp_meta_translation',
    $wpdb->prefix . 'lingowp_term_translation',
    $wpdb->prefix . 'lingowp_html_translation',
    $wpdb->prefix . 'lingowp_option_translation',
    $wpdb->prefix . 'lingowp_user_translation',
    $wpdb->prefix . 'lingowp_term_meta_translation',
    // parents
    $wpdb->prefix . 'lingowp_post',
    $wpdb->prefix . 'lingowp_meta',
    $wpdb->prefix . 'lingowp_term',
    $wpdb->prefix . 'lingowp_html',
    $wpdb->prefix . 'lingowp_option',
    $wpdb->prefix . 'lingowp_user',
    $wpdb->prefix . 'lingowp_term_meta',
];

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $table); // nosemgrep: wpdb-interpolated-sql -- table name only, from the hardcoded $tables list above; no values interpolated
}

// Delete all plugin options.
$options = [
    'lingowp_db_version',
    'lingowp_active_lang',
    'lingowp_existing_lang',
    'lingowp_source_locale',
    'lingowp_source_language',
    'lingowp_default_language',
    'lingowp_enabled_languages',
    'lingowp_prefix_default_language',
    'lingowp_cookie_preference',
    'lingowp_browser_detection',
    'lingowp_auto_redirect',
    'lingowp_url_structure_initialized',
    'lingowp_rewrite_rules_version',
    'lingowp_age_out_last_run',
    'lingowp_needs_initial_scan',
    'lingowp_needs_html_crawl',
    // WooCommerce-owned data (add-on): stored as ordinary options, not
    // dedicated tables — cleaned up here like any other plugin option.
    'lingowp_woo_attribute_labels',
    'lingowp_woo_tax_rate_names',
    // Onboarding / backend connection.
    'lingowp_languages',
    'lingowp_categories',
    'lingowp_onboarding_completed',
    'lingowp_api_token',
    'lingowp_site_id',
    'lingowp_owner_email',
    'lingowp_api_origin',
    'lingowp_cloud_opt_out',
    // Gettext workspace state.
    'lingowp_gettext_domains',
    'lingowp_gettext_ignored_units',
];

foreach ($options as $option) {
    delete_option($option);
}

// Onboarding transients (if present).
delete_transient('lingowp_last_site_sync_error');
delete_transient('lingowp_backend_status');
delete_transient('lingowp_entitlements_cache');
delete_transient('lingowp_wplang_transition_notice');

// Per-domain gettext percentage transients have hashed suffixes.
$transientPrefix        = '_transient_lingowp_gettext_percent_';
$transientTimeoutPrefix = '_transient_timeout_lingowp_gettext_percent_';
$likeTransient          = $wpdb->esc_like($transientPrefix) . '%';
$likeTimeout            = $wpdb->esc_like($transientTimeoutPrefix) . '%';
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $likeTransient,
        $likeTimeout
    )
);
