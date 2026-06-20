<?php

namespace TransLangs\Admin;

/**
 * Mounts the React admin board (the Translations screen) under the TransLangs
 * menu. The screen is a single root node; the SPA (built by @wordpress/scripts
 * into assets/admin/build) renders into it and talks to the translangs/v1 REST
 * API. Nonce + REST root are auto-configured by the `wp-api-fetch` handle.
 */
final class BoardPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'translangs-translations';
    private const HANDLE    = 'translangs-board';

    private string $hookSuffix = '';

    public function register(): void
    {
        // Priority 11: after SettingsPageController (default 10) registers the
        // parent "translangs" menu. Registering the submenu before the parent
        // exists mis-resolves its page hook, which makes WordPress reject the
        // page ("not allowed") and link the sidebar item to a bare slug (404).
        add_action('admin_menu', [$this, 'addMenu'], 11);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMenu(): void
    {
        $hook = add_submenu_page(
            'translangs',
            __('Translations', 'translangs'),
            __('Translations', 'translangs'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage'],
            0
        );

        $this->hookSuffix = is_string($hook) ? $hook : '';
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        echo '<div class="wrap"><div id="translangs-board"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hookSuffix) {
            return;
        }

        $base  = TRANSLANGS_DIR . 'assets/admin/build/';
        $asset = $base . 'index.asset.php';
        if (!is_readable($asset)) {
            // Build not produced yet — surface a clear notice instead of a blank screen.
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__('TransLangs admin assets are not built. Run "npm install && npm run build" in the plugin.', 'translangs')
                    . '</p></div>';
            });
            return;
        }

        /** @var array{dependencies: string[], version: string} $meta */
        $meta = require $asset;

        wp_enqueue_script(
            self::HANDLE,
            TRANSLANGS_URL . 'assets/admin/build/index.js',
            $meta['dependencies'] ?? [],
            $meta['version'] ?? TRANSLANGS_VERSION,
            true
        );

        $css = $base . 'index.css';
        if (is_readable($css)) {
            wp_enqueue_style(
                self::HANDLE,
                TRANSLANGS_URL . 'assets/admin/build/index.css',
                [],
                $meta['version'] ?? TRANSLANGS_VERSION
            );
        }

        wp_localize_script(self::HANDLE, 'TranslangsBoard', [
            'rest'        => esc_url_raw(rest_url('translangs/v1')),
            'settingsUrl' => esc_url_raw(admin_url('admin.php?page=translangs')),
        ]);
    }
}
