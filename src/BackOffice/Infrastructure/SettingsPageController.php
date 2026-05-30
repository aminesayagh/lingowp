<?php

namespace TransLangs\BackOffice\Infrastructure;

use TransLangs\Language\Infrastructure\SupportedLanguages;
use TransLangs\Shared\Infrastructure\PhpTemplateRenderer;

/**
 * The TransLangs settings screen.
 *
 * Registers the top-level admin menu, handles the settings form submission
 * (nonce + capability + sanitization), and renders the view.
 */
class SettingsPageController
{
    private const CAPABILITY = 'manage_options';
    private const SLUG       = 'translangs';

    /**
     * Tabs shown in the shared navbar. Other screens register their own slugs.
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'translangs-translations' => __('Translations', 'translangs'),
            'translangs'              => __('Settings', 'translangs'),
        ];
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('TransLangs', 'translangs'),
            __('TransLangs', 'translangs'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage'],
            'dashicons-translation',
            58
        );

        // Rename the auto-created first submenu item from "TransLangs" to "Settings".
        add_submenu_page(
            self::SLUG,
            __('Settings', 'translangs'),
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
            'tabs'      => self::tabs(),
            'current'   => self::SLUG,
        ]);
    }

    /**
     * Process the form only on a valid POST.
     */
    private function maybeHandleSave(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

        $oldDefault       = (string) get_option('translangs_default_language', 'en');
        $oldEnabled       = $this->normalizeLanguageList((array) get_option('translangs_enabled_languages', ['en', 'fr']));
        $oldPrefixDefault = (bool) get_option('translangs_prefix_default_language', false);

        $default = isset($_POST['default_language'])
            ? sanitize_key(wp_unslash($_POST['default_language']))
            : 'en';

        $enabledRaw = isset($_POST['enabled_languages'])
            ? (array) wp_unslash($_POST['enabled_languages'])
            : [];

        $enabled = array_values(array_filter(
            array_map('sanitize_key', $enabledRaw),
            [SupportedLanguages::class, 'exists']
        ));

        // The default language must always be enabled.
        if (!in_array($default, $enabled, true) && SupportedLanguages::exists($default)) {
            $enabled[] = $default;
        }

        $default       = SupportedLanguages::exists($default) ? $default : 'en';
        $enabled       = $this->normalizeLanguageList($enabled ?: ['en']);
        $prefixDefault = !empty($_POST['prefix_default_language']);

        update_option('translangs_default_language', $default);
        update_option('translangs_enabled_languages', $enabled);
        update_option('translangs_prefix_default_language', $prefixDefault);
        update_option('translangs_cookie_preference', !empty($_POST['cookie_preference']));
        update_option('translangs_browser_detection', !empty($_POST['browser_detection']));
        update_option('translangs_auto_redirect', !empty($_POST['auto_redirect']));

        if (
            $oldDefault !== $default
            || $oldEnabled !== $enabled
            || $oldPrefixDefault !== $prefixDefault
        ) {
            flush_rewrite_rules(false);
        }

        $this->notice(__('Settings saved.', 'translangs'), 'success');
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSettings(): array
    {
        return [
            'default_language'        => (string) get_option('translangs_default_language', 'en'),
            'enabled_languages'       => (array) get_option('translangs_enabled_languages', ['en', 'fr']),
            'prefix_default_language' => (bool) get_option('translangs_prefix_default_language', false),
            'cookie_preference'       => (bool) get_option('translangs_cookie_preference', true),
            'browser_detection'       => (bool) get_option('translangs_browser_detection', false),
            'auto_redirect'           => (bool) get_option('translangs_auto_redirect', false),
        ];
    }

    private function notice(string $message, string $type): void
    {
        $class = $type === 'error' ? 'notice-error' : 'notice-success';

        printf(
            '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    /**
     * @param string[] $languages
     * @return string[]
     */
    private function normalizeLanguageList(array $languages): array
    {
        $languages = array_values(array_unique(array_filter(
            array_map('sanitize_key', $languages),
            [SupportedLanguages::class, 'exists']
        )));

        sort($languages);

        return $languages;
    }
}
