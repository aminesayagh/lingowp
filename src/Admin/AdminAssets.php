<?php

namespace LingoWP\Admin;

/**
 * Loads the centralized admin stylesheet, but only on LingoWP screens.
 *
 * Every plugin admin page hook is prefixed "lingowp_page_" by WordPress,
 * plus the top-level "toplevel_page_lingowp". We match those so the CSS
 * never leaks onto other admin pages.
 */
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
