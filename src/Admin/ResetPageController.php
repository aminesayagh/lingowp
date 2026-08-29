<?php

namespace LingoWP\Admin;

use LingoWP\Backend\BackendEndpointResolver;
use LingoWP\Backend\ConnectionRepository;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Backend\SiteMetadataProvider;
use LingoWP\Database\OrphanTranslationSweep;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Extraction\Html\HtmlDiscoveryCrawl;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\PhpTemplateRenderer;

/**
 * Dev-only "reset everything" tool — drops every wp_tl_* table (including the
 * legacy static-text ones, no longer supported), deletes every lingowp_*
 * option/transient (config AND the API connection), and deactivates the
 * plugin. This is the button-triggered equivalent of a real uninstall minus
 * the file deletion: the admin is expected to reactivate manually afterwards,
 * which runs Activator::run() and starts setup over from a clean slate.
 *
 * Registered only when WP_DEBUG is on (see Bootstrap/Plugin.php) — a real
 * customer's site admin should never be able to wipe their connection and
 * translated content with one click; that is what uninstall.php is for.
 */
class ResetPageController
{
    public const CAPABILITY = 'manage_options';
    public const SLUG       = 'lingowp-reset';
    private const PARENT    = 'lingowp';

    public function __construct(
        private \wpdb $wpdb,
        private ConnectionRepository $connection,
        private BackendEndpointResolver $endpointResolver,
        private ConnectionStatus $connectionStatus,
        private SiteMetadataProvider $siteMetadata,
        private OptionLanguageRegistry $languageRegistry,
        private LanguageMetadataStore $languageMetadata
    ) {
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
        add_submenu_page(
            self::PARENT,
            __('Reset Translation Data', 'lingowp'),
            __('Reset Data', 'lingowp'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lingowp'));
        }

        PhpTemplateRenderer::render('reset.php', []);
    }

    /**
     * Drop every table, delete every option, then deactivate. Table names are
     * derived from TranslationMemorySchema, the single source of truth,
     * rather than a second hardcoded list. Nothing is re-seeded here —
     * Activator::run() does that fresh when the admin reactivates.
     */
    public function handleReset(): void
    {
        if (
            !current_user_can(self::CAPABILITY)
            || !isset($_POST['lingowp_reset_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['lingowp_reset_nonce']), 'lingowp_reset_data')
        ) {
            wp_die(esc_html__('Security check failed. Please try again.', 'lingowp'));
        }

        TranslationMemorySchema::dropAllTables($this->wpdb);

        $this->deleteConnection();
        $this->deleteConfiguration();

        deactivate_plugins(LINGOWP_BASENAME);

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /** Delete the API connection entirely: credentials and cached status. */
    private function deleteConnection(): void
    {
        $this->connection->forgetAll();
        $this->endpointResolver->resetOrigin();
        $this->connectionStatus->clearCache();
    }

    /**
     * Delete every remaining piece of Settings-page configuration: schema
     * version, scan/sweep bookkeeping, language roster, source locale,
     * behavior toggles, categories, language metadata.
     */
    private function deleteConfiguration(): void
    {
        delete_option(TranslationMemorySchema::OPTION_DB_VERSION);
        delete_option('lingowp_rewrite_rules_version');
        delete_option(OrphanTranslationSweep::OPTION_LAST_RUN);
        delete_option(InitialDiscoveryScan::OPTION_FLAG);
        delete_option(HtmlDiscoveryCrawl::OPTION_FLAG);
        TranslationMemorySchema::deleteConfigOptions();
        $this->languageRegistry->resetToDefaults();
        $this->siteMetadata->resetCategories();
        $this->languageMetadata->reset();
    }
}
