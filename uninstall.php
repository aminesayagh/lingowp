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

// Drop tables in FK-safe order: children before the parents they reference.
$tables = [
    // v5 children
    $wpdb->prefix . 'tl_post_translation',
    $wpdb->prefix . 'tl_meta_translation',
    $wpdb->prefix . 'tl_term_translation',
    $wpdb->prefix . 'tl_static_text_translation',
    $wpdb->prefix . 'tl_html_translation',
    $wpdb->prefix . 'tl_option_translation',
    // gettext plural (references the static text parent, so drop before it)
    $wpdb->prefix . 'tl_static_plural_text',
    // v5 parents
    $wpdb->prefix . 'tl_post',
    $wpdb->prefix . 'tl_meta',
    $wpdb->prefix . 'tl_term',
    $wpdb->prefix . 'tl_static_text',
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
    'translangs_default_language',
    'translangs_enabled_languages',
    'translangs_prefix_default_language',
    'translangs_cookie_preference',
    'translangs_browser_detection',
    'translangs_auto_redirect',
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
    'translangs_api_origin',
];

foreach ($options as $option) {
    delete_option($option);
}

// Onboarding transients (if present).
delete_transient('translangs_last_site_sync_error');
delete_transient('translangs_backend_status');
