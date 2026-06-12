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
// The v4 tables come first; the legacy v3 split tables are dropped too so
// deleting the plugin from a pre-v4 install still cleans up completely.
$tables = [
    // v4
    $wpdb->prefix . 'tl_translation',
    $wpdb->prefix . 'tl_source',
    // legacy v3 (clean reset)
    $wpdb->prefix . 'tl_translations_html',
    $wpdb->prefix . 'tl_translations_i18n_meta',
    $wpdb->prefix . 'tl_translations_i18n',
    $wpdb->prefix . 'tl_translations_terms',
    $wpdb->prefix . 'tl_translations_postmeta',
    $wpdb->prefix . 'tl_translations_post',
    $wpdb->prefix . 'tl_languages',
];

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $table); // phpcs:ignore WordPress.DB
}

// Delete all plugin options.
$options = [
    'translangs_db_version',
    'translangs_active_lang',
    'translangs_existing_lang',
    'translangs_default_language',
    'translangs_enabled_languages',
    'translangs_prefix_default_language',
    'translangs_cookie_preference',
    'translangs_browser_detection',
    'translangs_auto_redirect',
    'translangs_rewrite_rules_version',
    'translangs_age_out_last_run',
];

foreach ($options as $option) {
    delete_option($option);
}
