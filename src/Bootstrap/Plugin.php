<?php

namespace TransLangs\Bootstrap;

use TransLangs\BackOffice\Infrastructure\AdminAssets;
use TransLangs\BackOffice\Infrastructure\BoardPageController;
use TransLangs\BackOffice\Infrastructure\Rest\AdminRestController;
use TransLangs\BackOffice\Infrastructure\SettingsPageController;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\PublicSite\Infrastructure\GettextResolver;
use TransLangs\PublicSite\Infrastructure\HreflangPresenter;
use TransLangs\PublicSite\Infrastructure\HtmlRenderer;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
use TransLangs\PublicSite\Infrastructure\LanguageSwitcherShortcode;
use TransLangs\PublicSite\Infrastructure\LocalizedUrlBuilder;
use TransLangs\PublicSite\Infrastructure\PreviewHighlighter;
use TransLangs\PublicSite\Infrastructure\PublicLinkRewriter;
use TransLangs\PublicSite\Infrastructure\PublicRenderPipeline;
use TransLangs\PublicSite\Infrastructure\RequestContext;
use TransLangs\PublicSite\Infrastructure\StructuredRenderResolver;
use TransLangs\PublicSite\Infrastructure\TranslationUnitCodec;
use TransLangs\TranslationMemory\Infrastructure\InitialDiscoveryScan;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminBoardQuery;
use TransLangs\TranslationMemory\Infrastructure\Repositories\WpDbSourceTranslationRepository;
use TransLangs\TranslationMemory\Infrastructure\Schema\DatabaseHealthReport;
use TransLangs\TranslationMemory\Infrastructure\SourceLifecycleRefresher;
use TransLangs\TranslationMemory\Infrastructure\StructuredSourceScanner;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;

class Plugin
{
    public function boot(): void
    {
        global $wpdb;

        // If the version is current or higher, do nothing. Otherwise, run a full install.
        TranslationMemorySchema::maybeUpgrade();

        $languageRegistry = new OptionLanguageRegistry();
        $languageResolver = new ResolveRequestLanguage($languageRegistry);

        $urlBuilder = new LocalizedUrlBuilder($languageResolver);
        $context    = new RequestContext();

        // v4 persistence: one repository over tl_source / tl_translation.
        $sourceRepository  = new WpDbSourceTranslationRepository($wpdb);
        $structuredScanner = new StructuredSourceScanner($languageRegistry, $sourceRepository);

        // Admin board REST API (translangs/v1) — backs the React admin SPA.
        (new AdminRestController(
            new AdminBoardQuery($wpdb),
            $sourceRepository,
            $languageRegistry,
            $structuredScanner
        ))->register();

        (new LanguageRouteRegistrar($languageResolver))->register();
        (new LanguageSwitcherShortcode($languageResolver, $urlBuilder))->register();
        (new HreflangPresenter($languageResolver, $urlBuilder, $context))->register();
        (new SourceLifecycleRefresher($structuredScanner))->register();
        (new InitialDiscoveryScan($wpdb, $structuredScanner))->register();

        // v4 structured render: resolve types 1-3 by location via identity hooks.
        (new StructuredRenderResolver($languageResolver, $context, $sourceRepository))->register();

        // gettext: resolve + render-time discovery on the gettext filters.
        (new GettextResolver($languageResolver, $context, $sourceRepository))->register();

        // Front-end highlight for the admin "Preview on site" action (self-gates
        // to preview mode + logged-in editors).
        (new PreviewHighlighter(new AdminBoardQuery($wpdb)))->register();

        // Output buffer: hardcoded-HTML REPLACE pass (block units) + link rewriting.
        (new PublicRenderPipeline(
            $languageResolver,
            $context,
            new PublicLinkRewriter($urlBuilder),
            $sourceRepository,
            new HtmlRenderer(new TranslationUnitCodec())
        ))->register();

        if (is_admin()) {
            (new DatabaseHealthReport($wpdb))->register();
            (new AdminAssets())->register();
            (new SettingsPageController($languageRegistry))->register();
            (new BoardPageController())->register();
        }
    }
}
