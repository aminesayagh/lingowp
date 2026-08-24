<?php

namespace TransLangs\Setup;

use TransLangs\Admin\AdminAssets;
use TransLangs\GettextDomains\InstalledGettextComponentDiscovery;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Application\SourceLanguageTransition;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Language\Infrastructure\SupportedLanguages;
use TransLangs\Resolution\GettextResolver;
use TransLangs\Resolution\DateResolver;
use TransLangs\Resolution\NumberResolver;
use TransLangs\Resolution\Seo\HreflangPresenter;
use TransLangs\LocalizationRouting\MultilingualRedirects;
use TransLangs\Resolution\HtmlRenderer;
use TransLangs\Shared\Parsing\HtmlUnitExtractor;
use TransLangs\Resolution\OptionResolver;
use TransLangs\Resolution\PageOwnedHashes;
use TransLangs\Extraction\OwnedStringIndex;
use TransLangs\LocalizationRouting\LanguageRouteRegistrar;
use TransLangs\LocalizationRouting\LanguageSwitcherShortcode;
use TransLangs\LocalizationRouting\LocalizedUrlBuilder;
use TransLangs\Resolution\PublicLinkRewriter;
use TransLangs\Resolution\PublicRenderPipeline;
use TransLangs\Resolution\Seo\SeoHeadTranslator;
use TransLangs\Resolution\Seo\LanguageAwareRobotsMeta;
use TransLangs\Resolution\RequestContext;
use TransLangs\Resolution\StructuredRenderResolver;
use TransLangs\Shared\Parsing\TranslationUnitCodec;
use TransLangs\Extraction\Html\HtmlDiscovery;
use TransLangs\Extraction\Html\HtmlDiscoveryCrawl;
use TransLangs\Extraction\Structured\InitialDiscoveryScan;
use TransLangs\Extraction\OptionSourceScanner;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Database\Repository\WpDbBoardQueries;
use TransLangs\Admin\DatabaseHealthReport;
use TransLangs\Database\DatabaseHealthCheck;
use TransLangs\Onboarding\OnboardingPageController;
use TransLangs\Admin\ResetPageController;
use TransLangs\Extraction\Structured\SeoDiscoveryRescan;
use TransLangs\Extraction\Structured\SourceLifecycleRefresher;
use TransLangs\Extraction\Structured\StructuredSourceScanner;
use TransLangs\Database\OrphanTranslationSweep;
use TransLangs\Database\SourceLanguageSnapshot;
use TransLangs\Database\TranslationMemorySchema;
use TransLangs\Backend\BackendClient;
use TransLangs\Backend\BackendEndpointResolver;
use TransLangs\Backend\ConnectionRepository;
use TransLangs\Backend\ConnectionStatus;
use TransLangs\Backend\SiteConnector;
use TransLangs\Backend\SiteMetadataProvider;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Onboarding\OnboardingRestController;
use TransLangs\Backend\ConnectionRestController;
use TransLangs\Backend\ProviderKeyRestController;
use TransLangs\Backend\BillingEntitlementsService;
use TransLangs\Backend\BillingRestController;
use TransLangs\Language\LanguageRestController;
use TransLangs\Board\CollectionsRestController;
use TransLangs\Board\CollectionBoard;
use TransLangs\Board\TranslationRestController;
use TransLangs\AiTranslation\AiTranslationController;
use TransLangs\AiTranslation\AiTranslationRunner;
use TransLangs\Extraction\ScanRestController;
use TransLangs\GettextDomains\GettextDomainRestController;
use TransLangs\GettextDomains\GettextDomainRegistry;
use TransLangs\GettextDomains\GettextIgnoredUnitsStore;
use TransLangs\GettextDomains\GettextOnlineTranslationFetcher;
use TransLangs\GettextDomains\GettextPoImporter;
use TransLangs\GettextDomains\GettextTemplateGenerator;
use TransLangs\GettextDomains\GettextTranslatedPercentCache;
use TransLangs\GettextDomains\GettextTranslationUnitsService;
use TransLangs\Insights\InsightsRestController;
use TransLangs\Insights\NotifyEmailResolver;
use TransLangs\Privacy\PrivacyPolicyContent;

/**
 * @phpstan-type BackendBundle array{connection: ConnectionRepository, backendClient: BackendClient,
 *   siteMetadata: SiteMetadataProvider, connectionStatus: ConnectionStatus,
 *   languageMetadata: LanguageMetadataStore}
 */
class Plugin
{
    /**
     * Composition root. Each phase below is a private method named after the
     * workflow level it wires (Language, LocalizationRouting, Extraction,
     * Resolution, Backend+Board, Admin) — no container, just plain objects
     * constructed once and passed to whoever needs them, same as before this
     * was one long method.
     */
    public function boot(): void
    {
        global $wpdb;

        (new PrivacyPolicyContent())->register();

        [$languageRegistry, $languageResolver, $urlBuilder, $context] = $this->bootLanguage($wpdb);
        $this->bootLocalizationRouting($languageResolver, $urlBuilder);

        // Per-type repository over the parent/child tables.
        $sourceRepository = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));

        [$initialScan, $seoRescan, $htmlCrawl, $htmlDiscovery, $htmlRenderer, $unitExtractor, $pageOwnedHashes] =
            $this->bootExtraction($wpdb, $sourceRepository);

        $this->bootResolution(
            $languageResolver,
            $context,
            $urlBuilder,
            $languageRegistry,
            $sourceRepository,
            $unitExtractor,
            $pageOwnedHashes,
            $htmlRenderer,
            $htmlDiscovery
        );

        // Onboarding / backend connection. Never loaded on a public visitor page
        // view — only where the backend is actually reachable: wp-admin, the REST
        // API the SPA calls, and the cron/loopback request that runs the sync job.
        // (A bare is_admin() check would be wrong: REST and cron are not is_admin().)
        $backend = $this->isBackendContext()
            ? $this->bootBackendAndBoard($wpdb, $languageRegistry, $sourceRepository, $initialScan, $seoRescan, $htmlCrawl)
            : null;

        // is_admin() implies isBackendContext() (see that method), so $backend is
        // always set by the time we get here.
        if (is_admin()) {
            $this->bootAdmin($wpdb, $languageRegistry, $backend);
        }
    }

    /**
     * Language registry, the frozen-source-locale transition, and the
     * per-request resolver/url-builder/context every later phase depends on.
     *
     * @return array{0: OptionLanguageRegistry, 1: ResolveRequestLanguage, 2: LocalizedUrlBuilder, 3: RequestContext}
     */
    private function bootLanguage(\wpdb $wpdb): array
    {
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
        $urlBuilder       = new LocalizedUrlBuilder($languageResolver);
        $context          = new RequestContext();

        return [$languageRegistry, $languageResolver, $urlBuilder, $context];
    }

    /** Language-prefixed permalinks + the [tls_language_switcher] shortcode. */
    private function bootLocalizationRouting(ResolveRequestLanguage $languageResolver, LocalizedUrlBuilder $urlBuilder): void
    {
        (new LanguageRouteRegistrar($languageResolver))->register();
        (new LanguageSwitcherShortcode($languageResolver, $urlBuilder, new SupportedLanguages(new LanguageMetadataStore())))->register();
    }

    /**
     * Phase 1: everything that discovers and writes sources, never translations.
     *
     * @return array{0: InitialDiscoveryScan, 1: SeoDiscoveryRescan, 2: HtmlDiscoveryCrawl,
     *   3: HtmlDiscovery, 4: HtmlRenderer, 5: HtmlUnitExtractor, 6: PageOwnedHashes}
     */
    private function bootExtraction(\wpdb $wpdb, WpDbSourceRepository $sourceRepository): array
    {
        // Two-tier ownership gate: Tier-1 request memory + Tier-2 cross-table index.
        $pageOwnedHashes  = new PageOwnedHashes();
        $ownedStringIndex = new OwnedStringIndex($wpdb);

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

        return [$initialScan, $seoRescan, $htmlCrawl, $htmlDiscovery, $htmlRenderer, $unitExtractor, $pageOwnedHashes];
    }

    /** Phase 2: reads translations back onto the page at render time. */
    private function bootResolution(
        ResolveRequestLanguage $languageResolver,
        RequestContext $context,
        LocalizedUrlBuilder $urlBuilder,
        OptionLanguageRegistry $languageRegistry,
        WpDbSourceRepository $sourceRepository,
        HtmlUnitExtractor $unitExtractor,
        PageOwnedHashes $pageOwnedHashes,
        HtmlRenderer $htmlRenderer,
        HtmlDiscovery $htmlDiscovery
    ): void {
        (new HreflangPresenter($languageResolver, $urlBuilder, $context))->register();
        (new MultilingualRedirects($languageResolver, $urlBuilder, $context))->register();
        (new LanguageAwareRobotsMeta($languageResolver, $context, $sourceRepository))->register();

        // Structured render: post/term by text hash, meta by location.
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
    }

    /**
     * Phase 3: the backend connection, the Collections board, AI translation,
     * gettext domain management, and export/import — everything that only
     * runs where the backend is actually reachable (wp-admin, REST, cron).
     *
     * @return BackendBundle handed to bootAdmin() for the dev reset tool
     */
    private function bootBackendAndBoard(
        \wpdb $wpdb,
        OptionLanguageRegistry $languageRegistry,
        WpDbSourceRepository $sourceRepository,
        InitialDiscoveryScan $initialScan,
        SeoDiscoveryRescan $seoRescan,
        HtmlDiscoveryCrawl $htmlCrawl
    ): array {
        // Hash-link children have no FK to their parents (many parents share one
        // child), so a source edit or a deleted post leaves them behind. Reclaimed
        // by a daily job. Here for the context gate — admin/REST/cron, never a
        // visitor page view — not because it talks to the backend.
        (new OrphanTranslationSweep($wpdb))->register();

        $connection       = new ConnectionRepository();
        $backendClient    = new BackendClient(new BackendEndpointResolver(), $connection);
        $siteMetadata     = new SiteMetadataProvider($languageRegistry, $connection);
        $languageMetadata = new LanguageMetadataStore();
        $billingEntitlements = new BillingEntitlementsService($backendClient);
        $connectionStatus    = new ConnectionStatus($billingEntitlements, $connection);
        $siteConnector       = new SiteConnector($backendClient, $connection, $siteMetadata, $connectionStatus);

        // REST API for the onboarding SPA (registers on rest_api_init).
        (new OnboardingRestController($connection, $languageRegistry, $connectionStatus))->register();
        (new ConnectionRestController($connection, $connectionStatus, $siteConnector))->register();
        (new ProviderKeyRestController($backendClient))->register();
        (new BillingRestController($backendClient, $billingEntitlements, $connection))->register();
        (new LanguageRestController($languageMetadata, $languageRegistry, $sourceRepository))->register();

        $gettextComponents = new InstalledGettextComponentDiscovery();
        $collectionBoard   = new CollectionBoard($sourceRepository, $languageRegistry);
        (new CollectionsRestController($sourceRepository, $languageRegistry, $collectionBoard))->register();
        (new TranslationRestController($sourceRepository, $languageRegistry, $collectionBoard))->register();

        $aiTranslationRunner = new AiTranslationRunner($backendClient, $sourceRepository, $languageRegistry);
        (new AiTranslationController($sourceRepository, $languageRegistry, $collectionBoard, $billingEntitlements, $aiTranslationRunner, $connectionStatus))->register();

        (new ScanRestController($initialScan, $seoRescan, $htmlCrawl))->register();

        (new InsightsRestController($backendClient, $connection))->register();

        $gettextImporter = new GettextPoImporter();
        (new GettextDomainRestController(
            $gettextComponents,
            new GettextDomainRegistry(),
            $languageRegistry,
            new GettextOnlineTranslationFetcher($gettextImporter),
            $gettextImporter,
            new GettextTranslationUnitsService(new GettextTemplateGenerator(), $gettextImporter, new GettextIgnoredUnitsStore()),
            $backendClient,
            $billingEntitlements,
            $connectionStatus,
            new GettextTranslatedPercentCache()
        ))->register();

        return [
            'connection'       => $connection,
            'backendClient'    => $backendClient,
            'siteMetadata'     => $siteMetadata,
            'connectionStatus' => $connectionStatus,
            'languageMetadata' => $languageMetadata,
        ];
    }

    /**
     * Thin wp-admin chrome: assets, the Site Health panel, the onboarding
     * page shell, and (WP_DEBUG only) the dev reset tool.
     *
     * @param BackendBundle $backend from bootBackendAndBoard()
     */
    private function bootAdmin(\wpdb $wpdb, OptionLanguageRegistry $languageRegistry, array $backend): void
    {
        (new DatabaseHealthReport(new DatabaseHealthCheck($wpdb)))->register();
        (new AdminAssets())->register();
        (new OnboardingPageController(new NotifyEmailResolver()))->register();

        // Dev-only "reset translation data" tool. Never registered on a
        // real customer site — see ResetPageController's class doc.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            (new ResetPageController(
                $wpdb,
                $backend['connection'],
                new BackendEndpointResolver(),
                $backend['connectionStatus'],
                $backend['siteMetadata'],
                $languageRegistry,
                $backend['languageMetadata']
            ))->register();
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
        if (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron()) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        // rest_api_init has not run yet at plugins_loaded, so match the REST URL prefix.
        $prefix = trim(rest_get_url_prefix(), '/');
        $uri    = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        return $prefix !== '' && strpos($uri, '/' . $prefix . '/') !== false;
    }
}
