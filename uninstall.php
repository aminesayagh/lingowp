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
    $wpdb->prefix . 'tl_staticText_translation',
    $wpdb->prefix . 'tl_html_translation',
    $wpdb->prefix . 'tl_option_translation',
    // v5 parents
    $wpdb->prefix . 'tl_post',
    $wpdb->prefix . 'tl_meta',
    $wpdb->prefix . 'tl_term',
    $wpdb->prefix . 'tl_staticText',
    $wpdb->prefix . 'tl_html',
    $wpdb->prefix . 'tl_option',
];

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $table); // phpcs:ignore WordPress.DB
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
];

foreach ($options as $option) {
    delete_option($option);
}
