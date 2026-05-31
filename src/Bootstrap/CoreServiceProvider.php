<?php

namespace TransLangs\Bootstrap;

use TransLangs\ContentSource\Application\ScanStructuredSources;
use TransLangs\ContentSource\Infrastructure\WordPress\MediaSourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\MenuSourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\PostTypeSourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\TaxonomySourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\WooCommerceProductSourceReader;
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

        $languageResolver = new \TransLangs\Language\Application\ResolveRequestLanguage();

        $discovery = new \TransLangs\TranslationMemory\Application\DiscoverSourceStrings(
            $repository,
            $languageResolver
        );
        $container->set('translation_memory.discover_source_strings', $discovery);

        $container->set('translation_memory.resolve_translations', new ResolveTranslations(
            $repository,
            $languageResolver,
            $discovery
        ));
        $container->set('translation_memory.query_translations', new QueryTranslations($repository));
        $container->set('translation_memory.save_translation', new SaveTranslation($repository));
        $container->set('translation_memory.put_translation_for_review', new PutTranslationForReview($repository));
        $container->set('translation_memory.review_translation', new ReviewTranslation($repository));
        $container->set('translation_memory.ignore_translation', new IgnoreTranslation($repository));
        $container->set('translation_memory.reset_ignored_translation', new ResetIgnoredTranslation($repository));

        $this->registerContentSourceScanner($container, $discovery, $normalizer, $hasher);
    }

    private function registerContentSourceScanner(
        ServiceContainer $container,
        \TransLangs\TranslationMemory\Application\DiscoverSourceStrings $discovery,
        StringNormalizer $normalizer,
        StringHashGenerator $hasher
    ): void {
        // Fixed readers in the prescribed order: media → taxonomy → menu → page → post.
        // CPTs (including WooCommerce products) are added on 'init', after all post types
        // are registered. Readers are always constructed; isAvailable() guards execution.
        $scanner = new ScanStructuredSources(
            [
                new MediaSourceReader(),
                new TaxonomySourceReader(),
                new MenuSourceReader(),
                new PostTypeSourceReader('page'),
                new PostTypeSourceReader('post'),
            ],
            $discovery,
            $normalizer,
            $hasher
        );

        // Post types registered by other plugins arrive on 'init', which fires after
        // 'plugins_loaded'. We hook late (priority 99) to catch all of them.
        add_action('init', static function () use ($scanner): void {
            // Post types owned by other readers or not publicly translatable.
            $skip = [
                'attachment', 'page', 'post', 'product',
                'nav_menu_item', 'revision', 'custom_css', 'customize_changeset',
                'oembed_cache', 'user_request', 'wp_block', 'wp_template',
                'wp_template_part', 'wp_global_styles', 'wp_navigation',
                'wp_font_face', 'wp_font_family', 'wp_pattern',
            ];

            foreach (get_post_types(['public' => true, '_builtin' => false], 'names') as $cptName) {
                if (!in_array($cptName, $skip, true)) {
                    $scanner->addReader(new PostTypeSourceReader($cptName));
                }
            }

            // WooCommerce products have additional owned fields; handled by the
            // dedicated reader which checks wc_get_product() availability itself.
            $woo = new WooCommerceProductSourceReader();
            if ($woo->isAvailable()) {
                $scanner->addReader($woo);
            }
        }, 99);

        $container->set('content_source.scan_structured_sources', $scanner);
    }
}
