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
use TransLangs\Source\Infrastructure\HtmlUnitExtractor;
use TransLangs\PublicSite\Infrastructure\OptionResolver;
use TransLangs\PublicSite\Infrastructure\PageOwnedHashes;
use TransLangs\PublicSite\Infrastructure\PreviewHighlighter;
use TransLangs\Source\Infrastructure\OwnedStringIndex;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
use TransLangs\PublicSite\Infrastructure\LanguageSwitcherShortcode;
use TransLangs\PublicSite\Infrastructure\LocalizedUrlBuilder;
use TransLangs\PublicSite\Infrastructure\PublicLinkRewriter;
use TransLangs\PublicSite\Infrastructure\PublicRenderPipeline;
use TransLangs\PublicSite\Infrastructure\RequestContext;
use TransLangs\PublicSite\Infrastructure\StructuredRenderResolver;
use TransLangs\Source\Infrastructure\TranslationUnitCodec;
use TransLangs\TranslationMemory\Infrastructure\GettextCaptureWorker;
use TransLangs\TranslationMemory\Infrastructure\InitialDiscoveryScan;
use TransLangs\TranslationMemory\Infrastructure\OptionSourceScanner;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminBoardQuery;
use TransLangs\Source\Infrastructure\Repositories\WpDbSourceTranslationRepository;
use TransLangs\TranslationMemory\Infrastructure\Schema\DatabaseHealthReport;
use TransLangs\TranslationMemory\Infrastructure\SourceLifecycleRefresher;
use TransLangs\TranslationMemory\Infrastructure\StructuredSourceScanner;
use TransLangs\Source\Infrastructure\TranslationMemorySchema;

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

        // v5 persistence: per-type repository over the parent/child tables.
        $sourceRepository  = new WpDbSourceTranslationRepository($wpdb);

        // Two-tier ownership gate: Tier-1 request memory + Tier-2 cross-table index.
        $pageOwnedHashes   = new PageOwnedHashes();
        $ownedStringIndex  = new OwnedStringIndex($wpdb);

        // Shared block-unit extractor (type-5 + split fields): one walk, three callers.
        $unitExtractor     = new HtmlUnitExtractor(new TranslationUnitCodec());
        $structuredScanner = new StructuredSourceScanner($languageRegistry, $sourceRepository, $unitExtractor);

        // Options discovery (Path E): allowlisted wp_options text → tl_option.
        $optionScanner = new OptionSourceScanner($sourceRepository, $wpdb);

        // Admin board REST API (translangs/v1) — backs the React admin SPA.
        (new AdminRestController(
            new AdminBoardQuery($wpdb),
            $sourceRepository,
            $languageRegistry,
            $structuredScanner,
            $optionScanner
        ))->register();

        (new LanguageRouteRegistrar($languageResolver))->register();
        (new LanguageSwitcherShortcode($languageResolver, $urlBuilder))->register();
        (new HreflangPresenter($languageResolver, $urlBuilder, $context))->register();
        (new SourceLifecycleRefresher($structuredScanner))->register();
        (new InitialDiscoveryScan($wpdb, $structuredScanner))->register();

        // v5 structured render: post/term by text hash, meta by location.
        (new StructuredRenderResolver($languageResolver, $context, $sourceRepository, $unitExtractor, $pageOwnedHashes))->register();

        // gettext: resolve + render-time discovery (Tier-1 ownership-gated).
        (new GettextResolver($languageResolver, $context, $sourceRepository, $pageOwnedHashes))->register();

        // Branding: translate site name + tagline at display time (never at option read).
        (new OptionResolver($languageResolver, $context, $sourceRepository))->register();

        // Off-request writer for discovered gettext strings (Action Scheduler job,
        // Tier-2 ownership-gated).
        (new GettextCaptureWorker($sourceRepository, $ownedStringIndex))->register();

        // Output buffer: hardcoded-HTML REPLACE pass (block units) + link rewriting.
        (new PublicRenderPipeline(
            $languageResolver,
            $context,
            new PublicLinkRewriter($urlBuilder),
            $sourceRepository,
            new HtmlRenderer($unitExtractor),
            $ownedStringIndex
        ))->register();

        // Front-end highlight for the admin "Preview on site" action (self-gates
        // to preview mode + logged-in editors).
        (new PreviewHighlighter(new AdminBoardQuery($wpdb)))->register();

        if (is_admin()) {
            (new DatabaseHealthReport($wpdb))->register();
            (new AdminAssets())->register();
            (new SettingsPageController($languageRegistry))->register();
            (new BoardPageController())->register();
        }
    }
}
