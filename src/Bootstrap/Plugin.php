<?php

namespace TransLangs\Bootstrap;

use TransLangs\Admin\AdminAssets;
use TransLangs\Admin\InstalledGettextComponentDiscovery;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Application\SourceLanguageTransition;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Rendering\StaticText\GettextResolver;
use TransLangs\Rendering\Date\DateResolver;
use TransLangs\Rendering\Number\NumberResolver;
use TransLangs\Localization\HreflangPresenter;
use TransLangs\Localization\MultilingualRedirects;
use TransLangs\Rendering\Html\HtmlRenderer;
use TransLangs\Core\Parsing\HtmlUnitExtractor;
use TransLangs\Rendering\Option\OptionResolver;
use TransLangs\Rendering\PageOwnedHashes;
use TransLangs\Core\Repository\OwnedStringIndex;
use TransLangs\Localization\LanguageRouteRegistrar;
use TransLangs\Localization\LanguageSwitcherShortcode;
use TransLangs\Localization\LocalizedUrlBuilder;
use TransLangs\Localization\PublicLinkRewriter;
use TransLangs\Rendering\PublicRenderPipeline;
use TransLangs\Seo\SeoHeadTranslator;
use TransLangs\Rendering\RequestContext;
use TransLangs\Rendering\Primary\StructuredRenderResolver;
use TransLangs\Core\Parsing\TranslationUnitCodec;
use TransLangs\Discovery\HtmlDiscovery;
use TransLangs\Discovery\HtmlDiscoveryCrawl;
use TransLangs\Discovery\InitialDiscoveryScan;
use TransLangs\Discovery\Option\OptionSourceScanner;
use TransLangs\Core\Repository\WpDbSourceTranslationRepository;
use TransLangs\Admin\DatabaseHealthReport;
use TransLangs\Admin\OnboardingPageController;
use TransLangs\Admin\ResetPageController;
use TransLangs\Discovery\SeoDiscoveryRescan;
use TransLangs\Discovery\SourceLifecycleRefresher;
use TransLangs\Discovery\StructuredSourceScanner;
use TransLangs\Database\OrphanTranslationSweep;
use TransLangs\Database\SourceLanguageSnapshot;
use TransLangs\Database\TranslationMemorySchema;
use TransLangs\Api\AiQuotaService;
use TransLangs\Api\BackendClient;
use TransLangs\Api\BackendEndpointResolver;
use TransLangs\Api\ConnectionRepository;
use TransLangs\Api\ConnectionStatus;
use TransLangs\Api\SiteMetadataProvider;
use TransLangs\Api\SiteMetadataSyncJob;
use TransLangs\Api\SiteMetadataSyncScheduler;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Admin\Rest\OnboardingRestController;
use TransLangs\Admin\Rest\ConnectionRestController;
use TransLangs\Admin\Rest\LanguageRestController;
use TransLangs\Admin\Rest\CollectionRestController;
use TransLangs\Admin\Rest\TranslationRestController;
use TransLangs\Admin\Rest\AiTranslationController;
use TransLangs\Admin\Rest\ScanRestController;
use TransLangs\Admin\Rest\ExportImportRestController;
use TransLangs\Admin\Rest\GettextDomainRestController;
use TransLangs\Language\Gettext\GettextDomainRegistry;
use TransLangs\Language\Gettext\GettextIgnoredUnitsStore;
use TransLangs\Language\Gettext\GettextOnlineTranslationFetcher;
use TransLangs\Language\Gettext\GettextPoImporter;
use TransLangs\Language\Gettext\GettextTemplateGenerator;
use TransLangs\Language\Gettext\GettextTranslationUnitsService;

class Plugin
{
    public function boot(): void
    {
        global $wpdb;

        // If the version is current or higher, do nothing. Otherwise, run a full install.
        TranslationMemorySchema::maybeUpgrade();

        $languageRegistry = new OptionLanguageRegistry();

        // Reacts to WordPress's own Site Language changing after setup. Not
        // gated to isBackendContext()/is_admin(): WPLANG can change via
        // wp-admin, but also WP-CLI or an importer, neither of which is
        // is_admin(), REST_REQUEST, or wp_doing_cron() — same reasoning as
        // SeoDiscoveryRescan/HtmlDiscoveryCrawl below being registered
        // unconditionally.
        (new SourceLanguageTransition(
            $languageRegistry,
            new LanguageMetadataStore(),
            new SourceLanguageSnapshot($wpdb),
            $wpdb
        ))->register();

        $languageResolver = new ResolveRequestLanguage($languageRegistry);

        $urlBuilder = new LocalizedUrlBuilder($languageResolver);
        $context    = new RequestContext();

        // v5 persistence: per-type repository over the parent/child tables.
        $sourceRepository  = new WpDbSourceTranslationRepository($wpdb);

        // Two-tier ownership gate: Tier-1 request memory + Tier-2 cross-table index.
        $pageOwnedHashes   = new PageOwnedHashes();
        $ownedStringIndex  = new OwnedStringIndex($wpdb);

        // Shared block-unit extractor (type-5 + split fields): one walk, three callers.
        $unitExtractor     = new HtmlUnitExtractor(new TranslationUnitCodec());
        $structuredScanner = new StructuredSourceScanner($sourceRepository, $unitExtractor);

        // Shared hardcoded-HTML discovery walk: used by marking mode (below) and
        // the background crawl (backend-context block below).
        $htmlRenderer  = new HtmlRenderer($unitExtractor);
        $htmlDiscovery = new HtmlDiscovery($htmlRenderer, $ownedStringIndex, $sourceRepository);

        // Options discovery (Path E): allowlisted wp_options text → tl_option.
        $optionScanner = new OptionSourceScanner($sourceRepository, $wpdb);

        // Path E's save-time trigger, mirroring SourceLifecycleRefresher for
        // posts/terms: a branding edit re-discovers just that option, so the old
        // translation moves to the new text as needs_review instead of stranding.
        add_action('update_option_blogname', static fn() => $optionScanner->scan('blogname'));
        add_action('update_option_blogdescription', static fn() => $optionScanner->scan('blogdescription'));

        (new LanguageRouteRegistrar($languageResolver))->register();
        (new LanguageSwitcherShortcode($languageResolver, $urlBuilder))->register();
        (new HreflangPresenter($languageResolver, $urlBuilder, $context))->register();
        (new MultilingualRedirects($languageResolver, $urlBuilder, $context))->register();
        (new SourceLifecycleRefresher($structuredScanner))->register();
        // Kept as a named instance (not built inline) so the backend-context block
        // below can reuse it for the manual "refresh" REST action.
        $initialScan = new InitialDiscoveryScan($wpdb, $structuredScanner, $optionScanner);
        // Narrow SEO-only rescan: catches meta written by direct SQL (importers)
        // or predating the plugin, which fire no save hook. Registered on the
        // public side too — `activated_plugin` fires outside admin contexts.
        $seoRescan = new SeoDiscoveryRescan($wpdb, $structuredScanner);
        $seoRescan->register();
        $initialScan->register();

        // Background HTML crawl: sweeps every known public URL so hardcoded
        // markup is discovered even on pages nobody with edit rights has
        // opened — marking mode alone only covers pages an editor visits.
        // Registered unconditionally (not gated to backend context), same
        // reasoning as SeoDiscoveryRescan above: `activated_plugin` fires
        // outside admin contexts on some update flows.
        $htmlCrawl = new HtmlDiscoveryCrawl($wpdb, $htmlDiscovery);
        $htmlCrawl->register();

        // v5 structured render: post/term by text hash, meta by location.
        (new StructuredRenderResolver($languageResolver, $context, $sourceRepository, $unitExtractor, $pageOwnedHashes, $languageRegistry))->register();

        // gettext: WordPress-native resolution via switch_to_locale() —
        // no filters, no DB, no runtime discovery.
        (new GettextResolver($languageResolver, $context))->register();

        // Branding: translate site name + tagline at display time (never at option read).
        (new OptionResolver($languageResolver, $context, $sourceRepository, $languageRegistry))->register();

        // Dates: hooks wp_date() (the one function get_the_date(), date_i18n(),
        // and a block theme's core Post Date block all funnel through) and
        // reformats into the target language via IntlDateFormatter, so a date
        // is never left in the source language (and never becomes discovered
        // text a crawler would pick up as "untranslated" in the first place).
        (new DateResolver($languageResolver, $context))->register();

        // Numbers and WooCommerce prices: hooks number_format_i18n() and
        // wc_price()'s formatted_woocommerce_price filter, both of which
        // format using the site's own locale/settings rather than the
        // visitor's target language — same bug shape as dates, same fix.
        (new NumberResolver($languageResolver, $context))->register();

        // Output buffer: hardcoded-HTML REPLACE pass (block units), the SEO <head>
        // pass (same DOM, one parse), and link rewriting.
        (new PublicRenderPipeline(
            $languageResolver,
            $context,
            new PublicLinkRewriter($urlBuilder),
            $sourceRepository,
            $htmlRenderer,
            $htmlDiscovery,
            new SeoHeadTranslator($sourceRepository),
            $languageRegistry
        ))->register();

        // Onboarding / backend connection. Never loaded on a public visitor page
        // view — only where the backend is actually reachable: wp-admin, the REST
        // API the SPA calls, and the cron/loopback request that runs the sync job.
        // (A bare is_admin() check would be wrong: REST and cron are not is_admin().)
        if ($this->isBackendContext()) {
            // Hash-link children have no FK to their parents (many parents share one
            // child), so a source edit or a deleted post leaves them behind. Reclaimed
            // by a daily job. Here for the context gate — admin/REST/cron, never a
            // visitor page view — not because it talks to the backend.
            (new OrphanTranslationSweep($wpdb))->register();

            $connection       = new ConnectionRepository();
            $backendClient    = new BackendClient(new BackendEndpointResolver(), $connection);
            $siteMetadata     = new SiteMetadataProvider($languageRegistry);
            $languageMetadata = new LanguageMetadataStore();
            $connectionStatus = new ConnectionStatus($backendClient, $connection);
            $aiQuota          = new AiQuotaService($backendClient);

            // Active AI-availability health check (recurring while connected).
            $connectionStatus->register();

            // REST API for the onboarding SPA (registers on rest_api_init).
            (new OnboardingRestController($backendClient, $connection, $siteMetadata, $languageRegistry, $connectionStatus))->register();
            (new ConnectionRestController($backendClient, $connection, $siteMetadata, $connectionStatus))->register();
            (new LanguageRestController($languageMetadata, $languageRegistry, $sourceRepository))->register();
            $gettextComponents     = new InstalledGettextComponentDiscovery();
            $collectionsController = new CollectionRestController($sourceRepository, $languageRegistry, $gettextComponents);
            $collectionsController->register();
            (new TranslationRestController($sourceRepository, $languageRegistry, $collectionsController))->register();
            (new AiTranslationController($backendClient, $sourceRepository, $languageRegistry, $languageMetadata, $collectionsController, $aiQuota))->register();
            (new ExportImportRestController($sourceRepository, $languageRegistry))->register();
            (new ScanRestController($initialScan, $seoRescan, $htmlCrawl))->register();
            $gettextImporter = new GettextPoImporter();
            (new GettextDomainRestController(
                $gettextComponents,
                new GettextDomainRegistry(),
                $languageRegistry,
                new GettextOnlineTranslationFetcher($gettextImporter),
                $gettextImporter,
                new GettextTranslationUnitsService(new GettextTemplateGenerator(), $gettextImporter, new GettextIgnoredUnitsStore()),
                $backendClient,
                $languageMetadata,
                $aiQuota
            ))->register();

            // Background site-metadata sync (job runs off-request via Action Scheduler).
            (new SiteMetadataSyncJob($backendClient, $connection, $siteMetadata))->register();
            (new SiteMetadataSyncScheduler($connection))->register();
        }

        if (is_admin()) {
            (new DatabaseHealthReport($wpdb))->register();
            (new AdminAssets())->register();
            (new OnboardingPageController())->register();

            // Dev-only "reset translation data" tool. Never registered on a
            // real customer site — see ResetPageController's class doc.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                (new ResetPageController($wpdb))->register();
            }
        }
    }

    /**
     * True for requests that may legitimately talk to the Translangs backend:
     * the WordPress admin, the REST API (the SPA's calls), and cron/loopback
     * (the Action Scheduler queue runner). False for ordinary front-end page
     * views, so visitor traffic never loads the connection layer.
     */
    private function isBackendContext(): bool
    {
        if (is_admin()) {
            return true;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // rest_api_init has not run yet at plugins_loaded, so match the REST URL prefix.
        $prefix = trim(rest_get_url_prefix(), '/');
        $uri    = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        return $prefix !== '' && strpos($uri, '/' . $prefix . '/') !== false;
    }
}
