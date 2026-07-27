<?php

namespace TransLangs\Admin;

use TransLangs\Api\ConnectionRepository;
use TransLangs\Api\ConnectionStatus;
use TransLangs\Api\SiteMetadataProvider;
use TransLangs\Discovery\InitialDiscoveryScan;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Language\Infrastructure\SupportedLanguages;
use TransLangs\Shared\PhpTemplateRenderer;

/**
 * The TransLangs settings screen.
 *
 * Language management writes to/reads from the translangs_active_lang /
 * translangs_existing_lang code lists via LanguageRegistry.
 * Behavior toggles (cookie, browser detection, etc.) remain as WP options.
 */
class SettingsPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'translangs-settings';
    private const PARENT    = 'translangs';

    public function __construct(
        private LanguageRegistry $registry
    ) {}

    public function register(): void
    {
        // Priority 11: after OnboardingPageController (default 10) registers the
        // top-level "translangs" menu, so this submenu attaches to an existing parent.
        add_action('admin_menu', [$this, 'addMenu'], 11);

        // Restart posts to admin-post.php so it can redirect before any output.
        add_action('admin_post_translangs_restart', [$this, 'handleRestart']);
    }

    /**
     * Full onboarding restart (config only): re-enter onboarding at Step 1.
     * Marks onboarding incomplete, re-requests the initial scan, disconnects the
     * backend, and clears categories + the language roster/metadata. Does NOT drop
     * translation-memory tables — that is reserved for uninstall.
     */
    public function handleRestart(): void
    {
        if (
            !current_user_can(self::CAPABILITY)
            || !isset($_POST['translangs_restart_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['translangs_restart_nonce']), 'translangs_restart')
        ) {
            wp_die(esc_html__('Security check failed. Please try again.', 'translangs'));
        }

        // Onboarding + scan.
        update_option(ConnectionRepository::OPTION_COMPLETED, false);
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);

        // Disconnect: clear credentials, drop cached status, stop the health check.
        delete_option(ConnectionRepository::OPTION_TOKEN);
        delete_option(ConnectionRepository::OPTION_SITE_ID);
        delete_transient('translangs_backend_status');
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(ConnectionStatus::HOOK, [], ConnectionStatus::GROUP);
        }

        // Clear config: categories + language roster/metadata (translations survive).
        update_option(SiteMetadataProvider::OPTION_CATEGORIES, []);
        update_option(LanguageMetadataStore::OPTION, []);
        update_option(OptionLanguageRegistry::OPTION_ACTIVE, '');
        update_option(OptionLanguageRegistry::OPTION_EXISTING, '');

        wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT));
        exit;
    }

    public function addMenu(): void
    {
        // The onboarding SPA owns the top-level page; the legacy settings form is a
        // submenu (kept for language management until the SPA settings drawer covers it).
        add_submenu_page(
            self::PARENT,
            __('TransLangs Settings', 'translangs'),
            __('Settings', 'translangs'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        $this->maybeHandleSave();

        PhpTemplateRenderer::render('settings.php', [
            'settings'  => $this->currentSettings(),
            'languages' => SupportedLanguages::all(),
        ]);
    }

    /**
     * Process the form only on a valid POST.
     */
    private function maybeHandleSave(): void
    {
        if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return;
        }

        if (
            !isset($_POST['translangs_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['translangs_nonce']), 'translangs_save_settings')
        ) {
            $this->notice(__('Security check failed. Please try again.', 'translangs'), 'error');
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $source         = $this->registry->getDefaultLanguage();
        $currentTargets = $this->registry->getTargetLanguages();
        $oldPrefix      = (bool) get_option('translangs_prefix_default_language', false);

        // Sanitize and validate posted target languages.
        // Source language is excluded — it is never stored as a target.
        $postedRaw = isset($_POST['enabled_languages'])
            ? array_map('sanitize_key', (array) wp_unslash($_POST['enabled_languages']))
            : [];

        $postedTargets = array_values(array_filter(
            $postedRaw,
            static fn(string $c): bool => $c !== '' && $c !== $source && SupportedLanguages::exists($c)
        ));

        // Diff against current targets and apply changes.
        $toEnable  = array_diff($postedTargets, $currentTargets);
        $toDisable = array_diff($currentTargets, $postedTargets);

        foreach ($toEnable as $code) {
            $this->registry->enableLanguage($code);
        }

        foreach ($toDisable as $code) {
            $this->registry->disableLanguage($code);
        }

        // Behavior options (not language routing — kept as WP options).
        $prefixDefault = !empty($_POST['prefix_default_language']);
        update_option('translangs_prefix_default_language', $prefixDefault);
        update_option('translangs_cookie_preference', !empty($_POST['cookie_preference']));
        update_option('translangs_browser_detection', !empty($_POST['browser_detection']));
        update_option('translangs_auto_redirect', !empty($_POST['auto_redirect']));

        if ($toEnable !== [] || $toDisable !== [] || $oldPrefix !== $prefixDefault) {
            // Do NOT flush per request. Emptying the option forces WordPress to
            // regenerate the compiled rewrite rules on the next request, after
            // rewrite_rules_array can add the updated language-prefixed copies.
            update_option('rewrite_rules', '');
        }

        $this->notice(__('Settings saved.', 'translangs'), 'success');
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSettings(): array
    {
        return [
            'default_language'        => $this->registry->getDefaultLanguage(),
            'enabled_languages'       => $this->registry->getTargetLanguages(),
            'prefix_default_language' => (bool) get_option('translangs_prefix_default_language', false),
            'cookie_preference'       => (bool) get_option('translangs_cookie_preference', true),
            'browser_detection'       => (bool) get_option('translangs_browser_detection', false),
            'auto_redirect'           => (bool) get_option('translangs_auto_redirect', false),
        ];
    }

    private function notice(string $message, string $type = 'success'): void
    {
        add_action('admin_notices', static function () use ($message, $type): void {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        });
    }
}
