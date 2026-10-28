<?php

namespace LingoWP\Admin;

use LingoWP\Database\PluginDataCleanup;
use LingoWP\Shared\PhpTemplateRenderer;

/**
 * Dev-only "reset everything" tool — runs the exact same purge as a real
 * uninstall (PluginDataCleanup::run: drop every table, delete every option
 * and transient, cancel scheduled work) minus the file deletion, then
 * deactivates the plugin. The admin is expected to reactivate manually
 * afterwards, which runs Activator::run() and starts setup over from a clean
 * slate.
 *
 * Registered only when WP_DEBUG is on (see Setup/Plugin.php) — a real
 * customer's site admin should never be able to wipe their connection and
 * translated content with one click; that is what uninstall.php is for.
 */
class ResetPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'lingowp-reset';
    private const PARENT    = 'lingowp';

    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function register(): void
    {
        // Priority 11, same reasoning as SettingsPageController: attaches
        // after OnboardingPageController's top-level menu (default priority
        // 10) already exists.
        add_action('admin_menu', [$this, 'addMenu'], 11);

        // Posts to admin-post.php so it can redirect before any output.
        add_action('admin_post_lingowp_reset_data', [$this, 'handleReset']);
    }

    public function addMenu(): void
    {
        // null parent: the page stays reachable at admin.php?page=lingowp-reset
        // but gets no sidebar link — this dev tool should never be one click
        // away for anyone browsing the menu. Swap null for self::PARENT to
        // bring the "Reset Data" nav item back.
        add_submenu_page(
            null,
            __('Reset Translation Data', 'linvara'),
            __('Reset Data', 'linvara'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'linvara'));
        }

        PhpTemplateRenderer::render('reset.php', []);
    }

    /**
     * Full purge, then deactivate. Nothing is re-seeded here — Activator::run()
     * does that fresh when the admin reactivates.
     */
    public function handleReset(): void
    {
        if (
            !current_user_can(self::CAPABILITY)
            || !isset($_POST['lingowp_reset_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['lingowp_reset_nonce']), 'lingowp_reset_data')
        ) {
            wp_die(esc_html__('Security check failed. Please try again.', 'linvara'));
        }

        PluginDataCleanup::run($this->wpdb);

        deactivate_plugins(LINGOWP_BASENAME);

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }
}
