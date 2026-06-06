<?php

namespace TransLangs\Bootstrap;

use TransLangs\BackOffice\Infrastructure\AdminAssets;
use TransLangs\BackOffice\Infrastructure\SettingsPageController;
use TransLangs\BackOffice\Infrastructure\TranslationMemoryPageController;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LocaleNormalizer;
use TransLangs\Language\Infrastructure\WpDbLanguageRegistry;
use TransLangs\PublicSite\Infrastructure\HreflangPresenter;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
use TransLangs\PublicSite\Infrastructure\LanguageSwitcherShortcode;
use TransLangs\PublicSite\Infrastructure\LocalizedUrlBuilder;
use TransLangs\PublicSite\Infrastructure\RequestContext;
use TransLangs\TranslationMemory\Application\IgnoreTranslation;
use TransLangs\TranslationMemory\Application\PutTranslationForReview;
use TransLangs\TranslationMemory\Application\QueryTranslations;
use TransLangs\TranslationMemory\Application\ResetIgnoredTranslation;
use TransLangs\TranslationMemory\Application\ReviewTranslation;
use TransLangs\TranslationMemory\Application\SaveTranslation;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminTranslationsQuery;
use TransLangs\TranslationMemory\Infrastructure\Repositories\I18nTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostmetaTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\TermsTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;
use TransLangs\TranslationMemory\Infrastructure\WpDbTranslationMemoryRepository;

class Plugin
{
    public function boot(): void
    {
        global $wpdb;

        TranslationMemorySchema::maybeUpgrade();

        $languageRegistry = new WpDbLanguageRegistry($wpdb, new LocaleNormalizer());
        $languageResolver = new ResolveRequestLanguage($languageRegistry);

        $postRepo     = new PostTranslationsRepository($wpdb);
        $postmetaRepo = new PostmetaTranslationsRepository($wpdb);
        $termsRepo    = new TermsTranslationsRepository($wpdb);
        $i18nRepo     = new I18nTranslationsRepository($wpdb);
        $adminQuery   = new AdminTranslationsQuery($wpdb);

        $translationMemory = new WpDbTranslationMemoryRepository(
            $postRepo,
            $postmetaRepo,
            $termsRepo,
            $i18nRepo,
            $adminQuery,
        );

        $urlBuilder = new LocalizedUrlBuilder($languageResolver);
        $context    = new RequestContext();

        (new LanguageRouteRegistrar($languageResolver))->register();
        (new LanguageSwitcherShortcode($languageResolver, $urlBuilder))->register();
        (new HreflangPresenter($languageResolver, $urlBuilder, $context))->register();

        if (is_admin()) {
            (new AdminAssets())->register();
            (new SettingsPageController($languageRegistry))->register();
            (new TranslationMemoryPageController(
                $languageRegistry,
                new QueryTranslations($translationMemory),
                new SaveTranslation($translationMemory),
                new PutTranslationForReview($translationMemory),
                new ReviewTranslation($translationMemory),
                new IgnoreTranslation($translationMemory),
                new ResetIgnoredTranslation($translationMemory)
            ))->register();
        }
    }
}
