<?php

namespace LingoWP\Onboarding;

use LingoWP\Backend\BackendEndpointResolver;
use LingoWP\Insights\NotifyEmailResolver;

final class OnboardingPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'lingowp';
    private const ACCOUNT_SLUG      = 'lingowp-account';
    private const INSIGHTS_SLUG     = 'lingowp-insights';
    private const INSIGHTS_ENABLED  = false;
    private const HANDLE    = 'lingowp-app';

    private string $hookSuffix = '';
    private string $accountHookSuffix = '';
    private string $insightsHookSuffix = '';

    private NotifyEmailResolver $notifyEmailResolver;

    public function __construct(NotifyEmailResolver $notifyEmailResolver)
    {
        $this->notifyEmailResolver = $notifyEmailResolver;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_init', [$this, 'maybeRedirectAfterActivation']);
    }

    public function maybeRedirectAfterActivation(): void
    {
        if (
            (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || ! get_transient('lingowp_activation_redirect')
        ) {
            return;
        }
        delete_transient('lingowp_activation_redirect');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress' own bulk-activate marker, presence check only, no state change.
        if (isset($_GET['activate-multi']) || is_network_admin() || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
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

        $insightsHook = self::INSIGHTS_ENABLED ? add_submenu_page(
            self::SLUG,
            __('Insights', 'lingowp'),
            __('Insights', 'lingowp'),
            self::CAPABILITY,
            self::INSIGHTS_SLUG,
            [$this, 'renderInsightsPage']
        ) : false;

        $accountHook = add_submenu_page(
            self::SLUG,
            __('Account', 'lingowp'),
            __('Account', 'lingowp'),
            self::CAPABILITY,
            self::ACCOUNT_SLUG,
            [$this, 'renderAccountPage']
        );

        $this->hookSuffix = is_string($hook) ? $hook : '';
        $this->insightsHookSuffix = is_string($insightsHook) ? $insightsHook : '';
        $this->accountHookSuffix = is_string($accountHook) ? $accountHook : '';
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

    public function renderAccountPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        echo '<div class="wrap"><div id="lingowp-account-app"></div></div>';
    }

    public function renderInsightsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        echo '<div class="wrap"><div id="lingowp-insights-app"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        $known = [$this->hookSuffix, $this->accountHookSuffix, $this->insightsHookSuffix];
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

            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        }

        $notifyEmail = $this->notifyEmailResolver->resolve();

        wp_localize_script(self::HANDLE, 'LingoWP', [
            'rest'                => esc_url_raw(rest_url('lingowp/v1')),
            'nonce'               => wp_create_nonce('wp_rest'),
            'logo'                => esc_url_raw(LINGOWP_URL . 'assets/images/lingowp-logo.svg'),
            'flags_base_url'      => esc_url_raw(LINGOWP_URL . 'assets/flags/'),
            'addons_asset_url'    => esc_url_raw(
                (new BackendEndpointResolver())->baseUrl() . '/addons/icons/'
            ),
            'account_url'         => esc_url_raw(admin_url('admin.php?page=' . self::ACCOUNT_SLUG)),
            'notify_email'        => $notifyEmail['email'],
            'notify_email_source' => $notifyEmail['source'],
            'cloud_terms_url'     => esc_url_raw((string) apply_filters(
                'lingowp_cloud_terms_url',
                'https://www.lingowp.com/legal/terms-of-service/'
            )),
            'cloud_privacy_url'   => esc_url_raw((string) apply_filters(
                'lingowp_cloud_privacy_url',
                'https://www.lingowp.com/legal/privacy-policy/'
            )),
        ]);

        wp_set_script_translations(self::HANDLE, 'lingowp', LINGOWP_DIR . 'languages');
    }
}
