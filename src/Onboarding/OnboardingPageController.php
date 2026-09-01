<?php

namespace LingoWP\Onboarding;

use LingoWP\Insights\NotifyEmailResolver;

/**
 * Mounts the React + Polaris onboarding/dashboard SPA on the top-level LingoWP
 * admin page. The SPA (built by @wordpress/scripts into assets/admin/build) renders
 * into a single root node and talks to the lingowp/v1 REST API. The REST root
 * and nonce are handed to the SPA via wp_localize_script — the api_token never is.
 */
final class OnboardingPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'lingowp';
    private const BILLING_SLUG      = 'lingowp-billing';
    private const INSIGHTS_SLUG     = 'lingowp-insights';
    private const INTEGRATIONS_SLUG = 'lingowp-integrations';
    private const HANDLE    = 'lingowp-app';

    private string $hookSuffix = '';
    private string $billingHookSuffix = '';
    private string $insightsHookSuffix = '';
    private string $integrationsHookSuffix = '';

    private NotifyEmailResolver $notifyEmailResolver;

    public function __construct(NotifyEmailResolver $notifyEmailResolver)
    {
        $this->notifyEmailResolver = $notifyEmailResolver;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMenu(): void
    {
        $hook = add_menu_page(
            __('LingoWP', 'lingowp'),
            __('LingoWP', 'lingowp'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage'],
            $this->menuIcon(),
            30
        );

        add_submenu_page(
            self::SLUG,
            __('LingoWP', 'lingowp'),
            __('Workspace', 'lingowp'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );

        $insightsHook = add_submenu_page(
            self::SLUG,
            __('Insights', 'lingowp'),
            __('Insights', 'lingowp'),
            self::CAPABILITY,
            self::INSIGHTS_SLUG,
            [$this, 'renderInsightsPage']
        );

        $integrationsHook = add_submenu_page(
            self::SLUG,
            __('Integrations', 'lingowp'),
            __('Integrations', 'lingowp'),
            self::CAPABILITY,
            self::INTEGRATIONS_SLUG,
            [$this, 'renderIntegrationsPage']
        );

        $billingHook = add_submenu_page(
            self::SLUG,
            __('Billing', 'lingowp'),
            __('Billing', 'lingowp'),
            self::CAPABILITY,
            self::BILLING_SLUG,
            [$this, 'renderBillingPage']
        );

        $this->hookSuffix = is_string($hook) ? $hook : '';
        $this->insightsHookSuffix = is_string($insightsHook) ? $insightsHook : '';
        $this->integrationsHookSuffix = is_string($integrationsHook) ? $integrationsHook : '';
        $this->billingHookSuffix = is_string($billingHook) ? $billingHook : '';
    }

    private function menuIcon(): string
    {
        $logo = LINGOWP_DIR . 'assets/images/lingowp-white-logo.svg';
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
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        echo '<div class="wrap"><div id="lingowp-app"></div></div>';
    }

    public function renderBillingPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        echo '<div class="wrap"><div id="lingowp-billing-app"></div></div>';
    }

    public function renderInsightsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        echo '<div class="wrap"><div id="lingowp-insights-app"></div></div>';
    }

    public function renderIntegrationsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        echo '<div class="wrap"><div id="lingowp-integrations-app"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        $known = [$this->hookSuffix, $this->billingHookSuffix, $this->insightsHookSuffix, $this->integrationsHookSuffix];
        if (!in_array($hook, $known, true)) {
            return;
        }

        $base  = LINGOWP_DIR . 'assets/admin/build/';
        $asset = $base . 'index.asset.php';

        if (!is_readable($asset)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__('LingoWP admin assets are not built. Run "npm install && npm run build" in the plugin.', 'lingowp')
                    . '</p></div>';
            });
            return;
        }

        /** @var array{dependencies: string[], version: string} $meta */
        $meta = require $asset;

        wp_enqueue_script(
            self::HANDLE,
            LINGOWP_URL . 'assets/admin/build/index.js',
            $meta['dependencies'] ?? [],
            $meta['version'] ?? LINGOWP_VERSION,
            true
        );

        $css = $base . 'index.css';
        if (is_readable($css)) {
            wp_enqueue_style(
                self::HANDLE,
                LINGOWP_URL . 'assets/admin/build/index.css',
                [],
                $meta['version'] ?? LINGOWP_VERSION
            );

            // Swap to the generated index-rtl.css on RTL admin locales (Arabic).
            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        }

        $notifyEmail = $this->notifyEmailResolver->resolve();

        wp_localize_script(self::HANDLE, 'LingoWP', [
            'rest'                => esc_url_raw(rest_url('lingowp/v1')),
            'nonce'               => wp_create_nonce('wp_rest'),
            'logo'                => esc_url_raw(LINGOWP_URL . 'assets/images/lingowp-logo.svg'),
            'flags_base_url'      => esc_url_raw(LINGOWP_URL . 'assets/flags/'),
            // GET /billing/addons now returns `image` as a bare filename
            // ("lingowp-elementor.png") — the backend no longer hosts these,
            // so the SPA prepends this base itself. Not every filename has a
            // real PNG yet; IntegrationsApp.jsx falls back to the LingoWP
            // logo above on a 404.
            'addons_asset_url'    => esc_url_raw(LINGOWP_URL . 'assets/addons/'),
            'billing_url'         => esc_url_raw(admin_url('admin.php?page=' . self::BILLING_SLUG)),
            'notify_email'        => $notifyEmail['email'],
            'notify_email_source' => $notifyEmail['source'],
            'cloud_terms_url'     => esc_url_raw((string) apply_filters('lingowp_cloud_terms_url', '')),
            'cloud_privacy_url'   => esc_url_raw((string) apply_filters('lingowp_cloud_privacy_url', '')),
            'installed_addons'    => $this->installedAddons(),
        ]);

        wp_set_script_translations(self::HANDLE, 'lingowp', LINGOWP_DIR . 'languages');
    }

    /**
     * product slug (matches the billing catalog's `product`) => is its
     * lingowp-<product> plugin active on this site. Narrower purpose than a
     * past version of this map: `owned` (license-level, from GET
     * /billing/addons) alone decides Your-integrations vs Recommended; this
     * only decides whether an *owned* add-on still needs an "Install now"
     * button — ownership is per-license, so a license can own an add-on
     * that was installed on a different site under the same license and
     * never touched this one's filesystem.
     */
    private function installedAddons(): array
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $map = [];
        foreach (['elementor', 'woocommerce', 'acf', 'seo'] as $product) {
            $map[$product] = is_plugin_active("lingowp-{$product}/lingowp-{$product}.php");
        }

        return $map;
    }
}
