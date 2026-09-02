<?php
/**
 * Runs only when the plugin is deleted from the WordPress admin.
 *
 * This is where destructive cleanup belongs (not deactivation). The actual
 * work — dropping tables, deleting options and transients, cancelling
 * scheduled work — lives in PluginDataCleanup so the WP_DEBUG-only reset
 * button (ResetPageController) runs the exact same purge and the two can
 * never drift apart.
 *
 * @package LingoWP
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// The plugin is not booted during uninstall, so load the autoloader here.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'LingoWP\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

global $wpdb;

\LingoWP\Database\PluginDataCleanup::run($wpdb);
