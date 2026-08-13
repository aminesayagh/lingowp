<?php

namespace TransLangs\Admin;

use TransLangs\Backend\BackendEndpointResolver;
use TransLangs\Backend\ConnectionRepository;
use TransLangs\Backend\ConnectionStatus;
use TransLangs\Backend\SiteMetadataProvider;
use TransLangs\Backend\SiteMetadataSyncJob;
use TransLangs\Backend\TokenRotationJob;
use TransLangs\Extraction\Html\HtmlDiscoveryCrawl;
use TransLangs\Extraction\Structured\InitialDiscoveryScan;
use TransLangs\Database\TranslationMemorySchema;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Shared\PhpTemplateRenderer;

/**
 * Dev-only "reset everything" tool — truncates every wp_tl_* table, deletes
 * every translangs_* option/transient (config AND the API connection), and
 * re-arms both discovery scans. This is the button-triggered equivalent of
 * uninstall.php followed by reactivation, without ever touching the plugin's
 * files on disk (uninstall.php itself only runs via a real plugin delete,
 * which removes the code — this is for repeatable testing, not that).
 *
 * The end state after clicking this is meant to be indistinguishable from a
 * genuine first activation: onboarding un-completed, no credentials, no
 * language configuration, no discovered text — a true "first start" without
 * ever having to delete and reinstall the plugin for real.
 *
 * Registered only when WP_DEBUG is on (see Bootstrap/Plugin.php) — a real
 * customer's site admin should never be able to wipe their connection and
 * translated content with one click; that is what uninstall.php is for, and
 * even that only runs on an explicit, deliberate plugin deletion.
 */
class ResetPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'translangs-reset';
    private const PARENT    = 'translangs';

    public function __construct(
        private \wpdb $wpdb,
        private ConnectionRepository $connection,
        private BackendEndpointResolver $endpointResolver,
        private ConnectionStatus $connectionStatus,
        private SiteMetadataSyncJob $siteMetadataSyncJob,
        private SiteMetadataProvider $siteMetadata,
        private OptionLanguageRegistry $languageRegistry,
        private LanguageMetadataStore $languageMetadata,
        private TokenRotationJob $tokenRotationJob
    ) {
    }

    public function register(): void
    {
        // Priority 11, same reasoning as SettingsPageController: attaches
        // after OnboardingPageController's top-level menu (default priority
        // 10) already exists.
        add_action('admin_menu', [$this, 'addMenu'], 11);

        // Posts to admin-post.php so it can redirect before any output.
        add_action('admin_post_translangs_reset_data', [$this, 'handleReset']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            self::PARENT,
            __('Reset Translation Data', 'translangs'),
            __('Reset Data', 'translangs'),
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

        PhpTemplateRenderer::render('reset.php', []);
    }

    /**
     * Truncate every discovered/translated string and re-arm both scans.
     * Children before parents (FK-safe, same order as uninstall.php's DROP)
     * — table names are derived from TranslationMemorySchema, the single
     * source of truth, rather than a second hardcoded list.
     */
    public function handleReset(): void
    {
        if (
            !current_user_can(self::CAPABILITY)
            || !isset($_POST['translangs_reset_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['translangs_reset_nonce']), 'translangs_reset_data')
        ) {
            wp_die(esc_html__('Security check failed. Please try again.', 'translangs'));
        }

        TranslationMemorySchema::truncateAllTables($this->wpdb);

        $this->resetConnection();
        $this->resetConfiguration();

        // Same lazy-enqueue pattern as Activator::run(): request both scans;
        // the batched jobs pick them up on the next init.
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);
        update_option(HtmlDiscoveryCrawl::OPTION_FLAG, 1);

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    /**
     * Delete the API connection entirely: credentials, cached status, the
     * health-check job. Deliberately deletes (not resets-to-default) —
     * credentials are never seeded with a default (see Activator's own
     * comment: "written on connect with autoload=false and are deliberately
     * not seeded here"), so absence IS the first-activation state.
     */
    private function resetConnection(): void
    {
        $this->connection->forgetAll();
        $this->endpointResolver->resetOrigin();
        $this->connectionStatus->unschedule();
        $this->siteMetadataSyncJob->clearError();
        $this->tokenRotationJob->unschedule();
    }

    /**
     * Reset Settings-page configuration to its just-activated defaults:
     * language roster, source locale, behavior toggles, categories, language
     * metadata. Reuses TranslationMemorySchema::setDefaultOptions() to
     * reinstate every default this deleted — including re-arming
     * onboarding-incomplete, since resetConnection() above already deleted
     * that flag too.
     */
    private function resetConfiguration(): void
    {
        TranslationMemorySchema::deleteConfigOptions();
        delete_option('translangs_rewrite_rules_version');
        $this->languageRegistry->resetToDefaults();
        $this->siteMetadata->resetCategories();
        $this->languageMetadata->reset();

        // Re-add every default this (and resetConnection()) deleted.
        TranslationMemorySchema::setDefaultOptions();

        // Language routing changed, and the filter that builds language-
        // prefixed rules (LanguageRouteRegistrar) is already registered for
        // this request by Plugin::boot(), unlike at activation.
        flush_rewrite_rules();
    }
}
