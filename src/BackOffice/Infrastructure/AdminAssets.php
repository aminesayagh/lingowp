<?php

namespace TransLangs\BackOffice\Infrastructure;

/**
 * Loads the centralized admin stylesheet, but only on TransLangs screens.
 *
 * Every plugin admin page hook is prefixed "translangs_page_" by WordPress,
 * plus the top-level "toplevel_page_translangs". We match those so the CSS
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
            'translangs-admin',
            TRANSLANGS_URL . 'assets/admin/admin.css',
            [],
            TRANSLANGS_VERSION
        );
    }

    private function isPluginScreen(string $hook): bool
    {
        return $hook === 'toplevel_page_translangs'
            || strpos($hook, 'translangs_page_') === 0;
    }
}
