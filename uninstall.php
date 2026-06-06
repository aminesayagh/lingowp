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

// Drop translation tables in FK-safe order (child tables before the
// languages table they reference, and before the WP tables they reference).
$tables = [
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
    'translangs_default_language',
    'translangs_enabled_languages',
    'translangs_prefix_default_language',
    'translangs_cookie_preference',
    'translangs_browser_detection',
    'translangs_auto_redirect',
];

foreach ($options as $option) {
    delete_option($option);
}
