<?php

namespace LingoWP\Setup;

use LingoWP\Admin\AdminAssets;
use LingoWP\Admin\ForeignNoticeSuppressor;
use LingoWP\GettextDomains\InstalledGettextComponentDiscovery;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Application\SourceLanguageTransition;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\Resolution\GettextResolver;
use LingoWP\Resolution\DateResolver;
use LingoWP\Resolution\NumberResolver;
use LingoWP\Resolution\Seo\HreflangPresenter;
use LingoWP\LocalizationRouting\MultilingualRedirects;
use LingoWP\Resolution\HtmlRenderer;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Resolution\OptionResolver;
use LingoWP\Resolution\PageOwnedHashes;
use LingoWP\Extraction\OwnedStringIndex;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\LanguageSwitcherShortcode;
use LingoWP\LocalizationRouting\SwitcherRenderer;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\Resolution\Seo\LanguageHtmlAttributes;
use LingoWP\Admin\PermalinkNotice;
use LingoWP\Resolution\PublicLinkRewriter;
use LingoWP\Resolution\PublicRenderPipeline;
use LingoWP\Resolution\Seo\SeoHeadTranslator;
use LingoWP\Resolution\Seo\LanguageAwareRobotsMeta;
use LingoWP\Resolution\RequestContext;
use LingoWP\Resolution\StructuredRenderResolver;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Extraction\Html\HtmlDiscovery;
use LingoWP\Extraction\Html\HtmlDiscoveryCrawl;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
use LingoWP\Extraction\OptionSourceScanner;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Admin\DatabaseHealthReport;
use LingoWP\Database\DatabaseHealthCheck;
use LingoWP\Onboarding\OnboardingPageController;
use LingoWP\Admin\ResetPageController;
use LingoWP\Extraction\Structured\SourceLifecycleRefresher;
use LingoWP\Extraction\Structured\StructuredSourceScanner;
use LingoWP\Database\OrphanTranslationSweep;
use LingoWP\Database\SourceLanguageSnapshot;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Backend\BackendClient;
use LingoWP\Backend\BackendEndpointResolver;
use LingoWP\Backend\ConnectionRepository;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Backend\SiteConnector;
use LingoWP\Backend\SiteMetadataProvider;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Onboarding\OnboardingRestController;
use LingoWP\Backend\ConnectionRestController;
use LingoWP\Backend\ProviderKeyRestController;
use LingoWP\Backend\BillingEntitlementsService;
use LingoWP\Backend\BillingRestController;
use LingoWP\Backend\AddonSiteMatcher;
use LingoWP\Language\LanguageRestController;
use LingoWP\Board\CollectionsRestController;
use LingoWP\Board\CollectionBoard;
use LingoWP\Board\TranslationRestController;
use LingoWP\AiTranslation\AiScopeUnits;
use LingoWP\AiTranslation\AiTranslationController;
use LingoWP\AiTranslation\AiTaskSubmitter;
use LingoWP\AiTranslation\AiResultCollector;
use LingoWP\Extraction\ScanRestController;
use LingoWP\GettextDomains\GettextAiQueue;
use LingoWP\GettextDomains\GettextDomainRestController;
use LingoWP\GettextDomains\GettextDomainRegistry;
use LingoWP\GettextDomains\GettextUnitRepository;
use LingoWP\GettextDomains\GettextOnlineTranslationFetcher;
use LingoWP\GettextDomains\GettextPoImporter;
use LingoWP\GettextDomains\GettextTemplateGenerator;
use LingoWP\GettextDomains\GettextTranslatedPercentCache;
use LingoWP\GettextDomains\GettextTranslationUnitsService;
use LingoWP\Insights\InsightsRestController;
use LingoWP\Insights\NotifyEmailResolver;
use LingoWP\Privacy\PrivacyPolicyContent;
use LingoWP\Support\SupportRestController;

class Plugin
{
    public function boot(): void
    {
        global $wpdb;

        (new PrivacyPolicyContent())->register();

        [$languageRegistry, $languageResolver, $urlBuilder, $context] = $this->bootLanguage($wpdb);
        $this->bootLocalizationRouting($languageResolver, $urlBuilder);

        $sourceRepository = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
        WpDbSourceRepository::prime($sourceRepository);

        [$initialScan, $htmlCrawl, $htmlDiscovery, $htmlRenderer, $unitExtractor, $pageOwnedHashes] =
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

        if (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            $this->bootBackendAndBoard($wpdb, $languageRegistry, $sourceRepository, $initialScan, $htmlCrawl);
        } else {
            add_action('rest_api_init', function () use ($wpdb, $languageRegistry, $sourceRepository, $initialScan, $htmlCrawl): void {
                $this->bootBackendAndBoard($wpdb, $languageRegistry, $sourceRepository, $initialScan, $htmlCrawl);
            }, 1);
        }

        if (is_admin()) {
            $this->bootAdmin($wpdb, $languageRegistry);
        }
    }

    private function bootLanguage(\wpdb $wpdb): array
    {
        TranslationMemorySchema::maybeUpgrade();

        $languageRegistry = new OptionLanguageRegistry();

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

    private function bootLocalizationRouting(ResolveRequestLanguage $languageResolver, LocalizedUrlBuilder $urlBuilder): void
    {
        (new LanguageRouteRegistrar($languageResolver))->register();

        $languageLinks = new LanguageLinks(
            $languageResolver,
            $urlBuilder,
            new SupportedLanguages(new LanguageMetadataStore())
        );
        LanguageLinks::prime($languageLinks);

        $switcherRenderer = new SwitcherRenderer($languageLinks);
        SwitcherRenderer::prime($switcherRenderer);
        $switcherRenderer->register();

        (new LanguageSwitcherShortcode($switcherRenderer))->register();
    }

    private function bootExtraction(\wpdb $wpdb, WpDbSourceRepository $sourceRepository): array
    {
        $pageOwnedHashes  = new PageOwnedHashes();
        $ownedStringIndex = new OwnedStringIndex($wpdb);

        $unitExtractor     = new HtmlUnitExtractor(new TranslationUnitCodec());
        $structuredScanner = new StructuredSourceScanner($sourceRepository, $unitExtractor);

        $htmlRenderer  = new HtmlRenderer($unitExtractor);
        $htmlDiscovery = new HtmlDiscovery($htmlRenderer, $ownedStringIndex, $sourceRepository);

        $optionScanner = new OptionSourceScanner($sourceRepository, $wpdb);

        add_action('update_option_blogname', static fn() => $optionScanner->scan('blogname'));
        add_action('update_option_blogdescription', static fn() => $optionScanner->scan('blogdescription'));

        $rescanIfWidgetOrThemeMod = static function (string $option) use ($optionScanner): void {
            if ($optionScanner->isDynamicallyAllowlisted($option)) {
                $optionScanner->scan($option);
            }
        };
        add_action('added_option', $rescanIfWidgetOrThemeMod);
        add_action('updated_option', $rescanIfWidgetOrThemeMod);

        (new SourceLifecycleRefresher($structuredScanner))->register();

        $initialScan = new InitialDiscoveryScan($wpdb, $structuredScanner, $optionScanner);
        $initialScan->register();

        $htmlCrawl = new HtmlDiscoveryCrawl($wpdb, $htmlDiscovery);
        $htmlCrawl->register();

        return [$initialScan, $htmlCrawl, $htmlDiscovery, $htmlRenderer, $unitExtractor, $pageOwnedHashes];
    }

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

        (new LanguageHtmlAttributes($languageResolver, $context, new SupportedLanguages(new LanguageMetadataStore())))->register();

        (new StructuredRenderResolver($languageResolver, $context, $sourceRepository, $unitExtractor, $pageOwnedHashes, $languageRegistry))->register();

        (new GettextResolver($languageResolver, $context))->register();

        (new OptionResolver($languageResolver, $context, $sourceRepository, $languageRegistry))->register();

        (new DateResolver($languageResolver, $context))->register();

        (new NumberResolver($languageResolver, $context))->register();

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

    private function bootBackendAndBoard(
        \wpdb $wpdb,
        OptionLanguageRegistry $languageRegistry,
        WpDbSourceRepository $sourceRepository,
        InitialDiscoveryScan $initialScan,
        HtmlDiscoveryCrawl $htmlCrawl
    ): void {
        (new OrphanTranslationSweep($wpdb))->register();

        $connection       = new ConnectionRepository();
        $backendClient    = new BackendClient(new BackendEndpointResolver(), $connection);
        $siteMetadata     = new SiteMetadataProvider($languageRegistry, $connection);
        $languageMetadata = new LanguageMetadataStore();
        $billingEntitlements = new BillingEntitlementsService($backendClient);
        $connectionStatus    = new ConnectionStatus($billingEntitlements, $connection);
        $siteConnector       = new SiteConnector($backendClient, $connection, $siteMetadata, $connectionStatus, $billingEntitlements);

        add_action('update_option_admin_email', static fn ($old, $new) => $siteConnector->syncAdminEmailIfChanged((string) $new), 10, 2);

        (new OnboardingRestController($connection, $languageRegistry, $connectionStatus))->register();
        (new ConnectionRestController($connection, $connectionStatus, $siteConnector, $backendClient))->register();
        (new ProviderKeyRestController($backendClient))->register();
        $addonMatcher = new AddonSiteMatcher();
        $addonMatcher->register();
        (new BillingRestController($backendClient, $billingEntitlements, $connection, $addonMatcher))->register();
        (new LanguageRestController($languageMetadata, $languageRegistry, $sourceRepository))->register();

        $gettextComponents = new InstalledGettextComponentDiscovery();
        $collectionBoard   = new CollectionBoard($sourceRepository, $languageRegistry);
        $aiResultCollector = new AiResultCollector($backendClient, $sourceRepository);
        (new CollectionsRestController($sourceRepository, $languageRegistry, $collectionBoard, $aiResultCollector))->register();
        (new TranslationRestController($sourceRepository, $languageRegistry, $collectionBoard))->register();

        $aiTaskSubmitter = new AiTaskSubmitter($backendClient, $sourceRepository, $languageRegistry, $languageMetadata);
        (new AiTranslationController(new AiScopeUnits($sourceRepository), $languageRegistry, $collectionBoard, $billingEntitlements, $aiTaskSubmitter, $connectionStatus))->register();

        (new ScanRestController($initialScan, $htmlCrawl))->register();

        (new InsightsRestController($backendClient, $connection))->register();

        (new SupportRestController($backendClient, $connection))->register();

        $gettextImporter          = new GettextPoImporter();
        $gettextRegistry          = new GettextDomainRegistry();
        $gettextTranslationUnits  = new GettextTranslationUnitsService(new GettextTemplateGenerator(), $gettextImporter, new GettextUnitRepository($wpdb));
        $gettextPercentCache      = new GettextTranslatedPercentCache();
        $gettextAiQueue           = new GettextAiQueue($wpdb, $backendClient, $gettextTranslationUnits, $gettextRegistry, $gettextPercentCache, $languageRegistry, $languageMetadata);
        (new GettextDomainRestController(
            $gettextComponents,
            $gettextRegistry,
            $languageRegistry,
            new GettextOnlineTranslationFetcher($gettextImporter),
            $gettextImporter,
            $gettextTranslationUnits,
            $billingEntitlements,
            $connectionStatus,
            $gettextPercentCache,
            $gettextAiQueue
        ))->register();
    }

    private function bootAdmin(\wpdb $wpdb, OptionLanguageRegistry $languageRegistry): void
    {
        (new DatabaseHealthReport(new DatabaseHealthCheck($wpdb)))->register();
        (new AdminAssets())->register();
        (new ForeignNoticeSuppressor())->register();
        (new OnboardingPageController(new NotifyEmailResolver()))->register();
        (new PermalinkNotice($languageRegistry))->register();

        if (defined('WP_DEBUG') && WP_DEBUG && class_exists(ResetPageController::class)) {
            (new ResetPageController($wpdb))->register();
        }
    }
}
