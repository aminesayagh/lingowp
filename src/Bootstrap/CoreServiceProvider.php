<?php

namespace TransLangs\Bootstrap;

use TransLangs\PageTranslation\Application\ExtractTranslationCandidates;
use TransLangs\Shared\Infrastructure\CacheManager;
use TransLangs\TranslationMemory\Application\IgnoreTranslation;
use TransLangs\TranslationMemory\Application\PutTranslationForReview;
use TransLangs\TranslationMemory\Application\QueryTranslations;
use TransLangs\TranslationMemory\Application\ResolveTranslations;
use TransLangs\TranslationMemory\Application\ResetIgnoredTranslation;
use TransLangs\TranslationMemory\Application\ReviewTranslation;
use TransLangs\TranslationMemory\Application\SaveTranslation;
use TransLangs\TranslationMemory\Application\TranslateStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;
use TransLangs\TranslationMemory\Infrastructure\WpDbTranslationMemoryRepository;

class CoreServiceProvider
{
    public function register(ServiceContainer $container): void
    {
        TranslationMemorySchema::maybeUpgrade();

        $repository = new WpDbTranslationMemoryRepository();
        $normalizer = new StringNormalizer();
        $hasher     = new StringHashGenerator();
        $cache      = new CacheManager();

        $container->set('translation_memory.repository', $repository);
        $container->set('translation_memory.normalizer', $normalizer);
        $container->set('translation_memory.hasher', $hasher);
        $container->set('translation_memory.cache', $cache);

        $container->set('translation_memory.translate_strings', new TranslateStrings(
            $repository,
            $normalizer,
            $hasher,
            $cache
        ));

        $container->set('page_translation.extract_candidates', new ExtractTranslationCandidates(
            $normalizer,
            $hasher
        ));

        $container->set('translation_memory.resolve_translations', new ResolveTranslations($repository));
        $container->set('translation_memory.query_translations', new QueryTranslations($repository));
        $container->set('translation_memory.save_translation', new SaveTranslation($repository));
        $container->set('translation_memory.put_translation_for_review', new PutTranslationForReview($repository));
        $container->set('translation_memory.review_translation', new ReviewTranslation($repository));
        $container->set('translation_memory.ignore_translation', new IgnoreTranslation($repository));
        $container->set('translation_memory.reset_ignored_translation', new ResetIgnoredTranslation($repository));
    }
}
