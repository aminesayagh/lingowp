<?php

namespace LingoWP\Admin;

class AdminAssets
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if (!$this->isPluginScreen($hook)) {
            return;
        }

        wp_enqueue_style(
            'lingowp-admin',
            LINGOWP_URL . 'assets/admin/admin.css',
            [],
            LINGOWP_VERSION
        );
    }

    private function isPluginScreen(string $hook): bool
    {
        return $hook === 'toplevel_page_lingowp'
            || strpos($hook, 'lingowp_page_') === 0;
    }
}
