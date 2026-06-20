<?php

namespace TransLangs\Bootstrap;

use TransLangs\Admin\Infrastructure\AdminAssets;
use TransLangs\Admin\Infrastructure\BoardPageController;
use TransLangs\Admin\Infrastructure\Rest\AdminRestController;
use TransLangs\Admin\Infrastructure\SettingsPageController;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Rendering\Infrastructure\StaticText\GettextResolver;
use TransLangs\Localization\Infrastructure\HreflangPresenter;
use TransLangs\Rendering\Infrastructure\Html\HtmlRenderer;
use TransLangs\Source\Infrastructure\HtmlUnitExtractor;
use TransLangs\Rendering\Infrastructure\Option\OptionResolver;
use TransLangs\Rendering\Infrastructure\PageOwnedHashes;
use TransLangs\Admin\Infrastructure\PreviewHighlighter;
use TransLangs\Source\Infrastructure\OwnedStringIndex;
use TransLangs\Localization\Infrastructure\LanguageRouteRegistrar;
use TransLangs\Localization\Infrastructure\LanguageSwitcherShortcode;
use TransLangs\Localization\Infrastructure\LocalizedUrlBuilder;
use TransLangs\Localization\Infrastructure\PublicLinkRewriter;
use TransLangs\Rendering\Infrastructure\PublicRenderPipeline;
use TransLangs\Rendering\Infrastructure\RequestContext;
use TransLangs\Rendering\Infrastructure\Primary\StructuredRenderResolver;
use TransLangs\Source\Infrastructure\TranslationUnitCodec;
use TransLangs\Discovery\Infrastructure\StaticText\GettextCaptureWorker;
use TransLangs\Discovery\Infrastructure\InitialDiscoveryScan;
use TransLangs\Discovery\Infrastructure\Option\OptionSourceScanner;
use TransLangs\Admin\Infrastructure\AdminBoardQuery;
use TransLangs\Source\Infrastructure\Repositories\WpDbSourceTranslationRepository;
use TransLangs\Admin\Infrastructure\DatabaseHealthReport;
use TransLangs\Discovery\Infrastructure\SourceLifecycleRefresher;
use TransLangs\Discovery\Infrastructure\StructuredSourceScanner;
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
