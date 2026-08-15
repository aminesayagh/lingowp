<?php

namespace TransLangs\Onboarding;

/**
 * Mounts the React + Polaris onboarding/dashboard SPA on the top-level TransLangs
 * admin page. The SPA (built by @wordpress/scripts into assets/admin/build) renders
 * into a single root node and talks to the translangs/v1 REST API. The REST root
 * and nonce are handed to the SPA via wp_localize_script — the api_token never is.
 */
final class OnboardingPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'translangs';
    private const REPORT_SLUG = 'translangs-report';
    private const BILLING_SLUG = 'translangs-billing';
    private const HANDLE    = 'translangs-app';

    private string $hookSuffix = '';
    private string $billingHookSuffix = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMenu(): void
    {
        $hook = add_menu_page(
            __('TransLangs', 'translangs'),
            __('TransLangs', 'translangs'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage'],
            $this->menuIcon(),
            30
        );

        add_submenu_page(
            self::SLUG,
            __('TransLangs', 'translangs'),
            __('Workspace', 'translangs'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );

        add_submenu_page(
            self::SLUG,
            __('Report', 'translangs'),
            __('Report', 'translangs'),
            self::CAPABILITY,
            self::REPORT_SLUG,
            [$this, 'renderReportPage']
        );

        $billingHook = add_submenu_page(
            self::SLUG,
            __('Billing', 'translangs'),
            __('Billing', 'translangs'),
            self::CAPABILITY,
            self::BILLING_SLUG,
            [$this, 'renderBillingPage']
        );

        $this->hookSuffix = is_string($hook) ? $hook : '';
        $this->billingHookSuffix = is_string($billingHook) ? $billingHook : '';
    }

    private function menuIcon(): string
    {
        $logo = TRANSLANGS_DIR . 'assets/images/logo-white.svg';
        if (!is_readable($logo)) {
            return 'dashicons-translation';
        }

        $svg = file_get_contents($logo);
        if (!is_string($svg) || $svg === '') {
            return 'dashicons-translation';
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        echo '<div class="wrap"><div id="translangs-app"></div></div>';
    }

    public function renderReportPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        echo '<div class="wrap"></div>';
    }

    public function renderBillingPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        echo '<div class="wrap"><div id="translangs-billing-app"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hookSuffix && $hook !== $this->billingHookSuffix) {
            return;
        }

        $base  = TRANSLANGS_DIR . 'assets/admin/build/';
        $asset = $base . 'index.asset.php';

        if (!is_readable($asset)) {
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

            // Swap to the generated index-rtl.css on RTL admin locales (Arabic).
            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        }

        wp_localize_script(self::HANDLE, 'Translangs', [
            'rest'           => esc_url_raw(rest_url('translangs/v1')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'logo'           => esc_url_raw(TRANSLANGS_URL . 'assets/images/Logo-blue.svg'),
            'flags_base_url' => esc_url_raw(TRANSLANGS_URL . 'assets/flags/'),
        ]);
    }
}
