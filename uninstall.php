<?php
/**
 * Runs only when the plugin is deleted from the WordPress admin.
 *
 * This is where destructive cleanup belongs (not deactivation).
 *
 * @package TransLangs
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop plugin tables.
$table = $wpdb->prefix . 'translangs_strings';
$wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB

// Delete plugin options.
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
