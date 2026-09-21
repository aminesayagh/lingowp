<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

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
