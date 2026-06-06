<?php

namespace TransLangs\BackOffice\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Infrastructure\SupportedLanguages;
use TransLangs\Shared\Infrastructure\PhpTemplateRenderer;

/**
 * The TransLangs settings screen.
 *
 * Language management writes to/reads from wp_tl_languages via LanguageRegistry.
 * Behavior toggles (cookie, browser detection, etc.) remain as WP options.
 */
class SettingsPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'translangs-settings';

    public function __construct(private LanguageRegistry $registry) {}

    /** @return array<string, string> */
    private static function tabs(): array
    {
        return [
            self::SLUG => __('General', 'translangs'),
        ];
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('TransLangs Settings', 'translangs'),
            __('TransLangs', 'translangs'),
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

        $source         = $this->registry->getDefaultLanguage();
        $currentTargets = $this->registry->getTargetLanguages();
        $oldPrefix      = (bool) get_option('translangs_prefix_default_language', false);

        // Sanitize and validate posted target languages.
        // Source language is excluded — it is never stored as a target.
        $postedRaw = isset($_POST['enabled_languages'])
            ? (array) wp_unslash($_POST['enabled_languages'])
            : [];

        $postedTargets = array_values(array_filter(
            array_map('sanitize_key', $postedRaw),
            static fn(string $c): bool => $c !== '' && $c !== $source && SupportedLanguages::exists($c)
        ));

        // Diff against current targets and apply changes.
        $allLangs = SupportedLanguages::all();
        $toEnable = array_diff($postedTargets, $currentTargets);
        $toDisable = array_diff($currentTargets, $postedTargets);

        foreach ($toEnable as $code) {
            if (isset($allLangs[$code])) {
                $this->registry->enableLanguage(
                    $code,
                    $allLangs[$code]['name'],
                    $allLangs[$code]['native'],
                    $allLangs[$code]['dir']
                );
            }
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
