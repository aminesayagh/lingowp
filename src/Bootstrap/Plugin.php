<?php

namespace TransLangs\Bootstrap;

use TransLangs\BackOffice\Infrastructure\AdminAssets;
use TransLangs\BackOffice\Infrastructure\SettingsPageController;
use TransLangs\BackOffice\Infrastructure\TranslationMemoryPageController;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\PublicSite\Infrastructure\FocusHighlighter;
use TransLangs\PublicSite\Infrastructure\GettextCaptureBuffer;
use TransLangs\PublicSite\Infrastructure\HreflangPresenter;
use TransLangs\PublicSite\Infrastructure\HtmlRenderParserFactory;
use TransLangs\PublicSite\Infrastructure\HtmlTextTools;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
use TransLangs\PublicSite\Infrastructure\LanguageSwitcherShortcode;
use TransLangs\PublicSite\Infrastructure\LocalizedUrlBuilder;
use TransLangs\PublicSite\Infrastructure\PublicLinkRewriter;
use TransLangs\PublicSite\Infrastructure\PublicRenderPipeline;
use TransLangs\PublicSite\Infrastructure\RequestContext;
use TransLangs\TranslationMemory\Application\ApplyTranslationToSameText;
use TransLangs\TranslationMemory\Application\IgnoreTranslation;
use TransLangs\TranslationMemory\Application\PutTranslationForReview;
use TransLangs\TranslationMemory\Application\QueryTranslations;
use TransLangs\TranslationMemory\Application\ResetIgnoredTranslation;
use TransLangs\TranslationMemory\Application\ReviewTranslation;
use TransLangs\TranslationMemory\Application\SaveTranslation;
use TransLangs\TranslationMemory\Infrastructure\CaptureBatchQueue;
use TransLangs\TranslationMemory\Infrastructure\CaptureBatchWorker;
use TransLangs\TranslationMemory\Infrastructure\OwnedStringIndex;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminTranslationsQuery;
use TransLangs\TranslationMemory\Infrastructure\Repositories\HtmlTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\I18nTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostmetaTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\TermsTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\WpDbSourceTranslationRepository;
use TransLangs\TranslationMemory\Infrastructure\Schema\DatabaseHealthReport;
use TransLangs\TranslationMemory\Infrastructure\SourceLifecycleRefresher;
use TransLangs\TranslationMemory\Infrastructure\StructuredSourceScanner;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;
use TransLangs\TranslationMemory\Infrastructure\WpDbTranslationMemoryRepository;

class Plugin
{
    public function boot(): void
    {
        global $wpdb;

        // If the version is current or higher, do nothing. Otherwise, run a full install.
        TranslationMemorySchema::maybeUpgrade();

        $languageRegistry = new OptionLanguageRegistry();
        $languageResolver = new ResolveRequestLanguage($languageRegistry);

        $postRepo     = new PostTranslationsRepository($wpdb);
        $postmetaRepo = new PostmetaTranslationsRepository($wpdb);
        $termsRepo    = new TermsTranslationsRepository($wpdb);
        $i18nRepo     = new I18nTranslationsRepository($wpdb);
        $htmlRepo     = new HtmlTranslationsRepository($wpdb);
        $adminQuery   = new AdminTranslationsQuery($wpdb);

        $translationMemory = new WpDbTranslationMemoryRepository(
            $postRepo,
            $postmetaRepo,
            $termsRepo,
            $i18nRepo,
            $htmlRepo,
            $adminQuery,
        );

        (new CaptureBatchWorker(
            $languageRegistry,
            $translationMemory,
            new OwnedStringIndex($wpdb)
        ))->register();

        $urlBuilder = new LocalizedUrlBuilder($languageResolver);
        $context    = new RequestContext();

        // v4 source discovery writes to tl_source via the source-key repository.
        // Render and admin still run on the legacy repo above until their sub-batches.
        $sourceRepository  = new WpDbSourceTranslationRepository($wpdb);
        $structuredScanner = new StructuredSourceScanner($languageRegistry, $sourceRepository);

        (new LanguageRouteRegistrar($languageResolver))->register();
        (new LanguageSwitcherShortcode($languageResolver, $urlBuilder))->register();
        (new HreflangPresenter($languageResolver, $urlBuilder, $context))->register();
        (new SourceLifecycleRefresher($structuredScanner))->register();
        (new FocusHighlighter($translationMemory))->register();

        (new PublicRenderPipeline(
            $languageResolver,
            $context,
            $translationMemory,
            new HtmlRenderParserFactory(new HtmlTextTools()),
            new PublicLinkRewriter($urlBuilder),
            new GettextCaptureBuffer(),
            new CaptureBatchQueue()
        ))->register();

        if (is_admin()) {
            (new DatabaseHealthReport($wpdb))->register();
            (new AdminAssets())->register();
            (new SettingsPageController($languageRegistry))->register();
            (new TranslationMemoryPageController(
                $languageRegistry,
                new QueryTranslations($translationMemory),
                new SaveTranslation($translationMemory),
                new PutTranslationForReview($translationMemory),
                new ReviewTranslation($translationMemory),
                new IgnoreTranslation($translationMemory),
                new ResetIgnoredTranslation($translationMemory),
                $structuredScanner,
                $urlBuilder,
                new ApplyTranslationToSameText($translationMemory)
            ))->register();
        }
    }
}
