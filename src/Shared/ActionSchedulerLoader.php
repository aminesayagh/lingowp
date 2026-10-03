<?php

namespace LingoWP\Shared;

final class ActionSchedulerLoader
{
    private function __construct() {}

    public static function loadBundled(string $pluginDir): void
    {
        $path = rtrim($pluginDir, '/\\') . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
}
