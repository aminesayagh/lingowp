<?php

namespace TransLangs\Bootstrap;

use TransLangs\ContentSource\Application\BuildScanTargets;
use TransLangs\ContentSource\Application\ScanRenderedArchetypes;
use TransLangs\ContentSource\Application\ScanStructuredSources;
use TransLangs\ContentSource\Infrastructure\WordPress\ArchetypeUrlSampler;
use TransLangs\ContentSource\Infrastructure\WordPress\MediaSourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\MenuSourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\PostTypeSourceReader;
use TransLangs\ContentSource\Infrastructure\WordPress\TaxonomySourceReader;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LocaleNormalizer;
use TransLangs\Language\Infrastructure\WpDbLanguageRegistry;
use TransLangs\PageTranslation\Application\ClassifyRenderedSegments;
use TransLangs\PageTranslation\Application\ExtractTranslationCandidates;
use TransLangs\PageTranslation\Domain\SkipRules;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\Shared\Infrastructure\CacheManager;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Application\IgnoreTranslation;
use TransLangs\TranslationMemory\Application\PreloadTranslations;
use TransLangs\TranslationMemory\Application\PutTranslationForReview;
use TransLangs\TranslationMemory\Application\QueryTranslations;
use TransLangs\TranslationMemory\Application\ResetIgnoredTranslation;
use TransLangs\TranslationMemory\Application\ResolveTranslations;
use TransLangs\TranslationMemory\Application\ReviewTranslation;
use TransLangs\TranslationMemory\Application\SaveTranslation;
use TransLangs\TranslationMemory\Application\TranslateStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminTranslationsQuery;
use TransLangs\TranslationMemory\Infrastructure\Repositories\I18nTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostmetaTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\TermsTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\TranslationLifecycleHooks;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;
use TransLangs\TranslationMemory\Infrastructure\WpDbTranslationMemoryRepository;

class CoreServiceProvider
{
    public function register(ServiceContainer $container): void
    {
        global $wpdb;

        TranslationMemorySchema::maybeUpgrade();

        // ── 1. Split table repositories ───────────────────────────────────────

        $postRepo     = new PostTranslationsRepository($wpdb);
        $postmetaRepo = new PostmetaTranslationsRepository($wpdb);
        $termsRepo    = new TermsTranslationsRepository($wpdb);
        $i18nRepo     = new I18nTranslationsRepository($wpdb);
        $adminQuery   = new AdminTranslationsQuery($wpdb);

        $container->set('translation_memory.repo.post',     $postRepo);
        $container->set('translation_memory.repo.postmeta', $postmetaRepo);
        $container->set('translation_memory.repo.terms',    $termsRepo);
        $container->set('translation_memory.repo.i18n',     $i18nRepo);
        $container->set('translation_memory.repo.admin',    $adminQuery);

        // ── 2. Repository facade ──────────────────────────────────────────────

        $repository = new WpDbTranslationMemoryRepository(
            $postRepo,
            $postmetaRepo,
            $termsRepo,
            $i18nRepo,
            $adminQuery,
        );

        $container->set('translation_memory.repository', $repository);

        // ── 3. Shared value objects ───────────────────────────────────────────

        $normalizer = new StringNormalizer();
        $hasher     = new StringHashGenerator();
        $cache      = new CacheManager();

        $container->set('translation_memory.normalizer', $normalizer);
        $container->set('translation_memory.hasher', $hasher);
        $container->set('translation_memory.cache', $cache);

        // ── 4. Language registry and resolver ────────────────────────────────

        $localeNormalizer = new LocaleNormalizer();
        $languageRegistry = new WpDbLanguageRegistry($wpdb, $localeNormalizer);
        $languageResolver = new ResolveRequestLanguage($languageRegistry);

        $container->set('language.locale_normalizer', $localeNormalizer);
        $container->set('language.registry',          $languageRegistry);
        $container->set('language.resolve_request',   $languageResolver);

        // ── 5. Discovery service ──────────────────────────────────────────────

        $discovery = new DiscoverSourceStrings($repository, $languageResolver);
        $container->set('translation_memory.discover_source_strings', $discovery);

        // ── 6. Render-time lookup pipeline ────────────────────────────────────

        $preloader  = new PreloadTranslations($repository);
        $classifier = new ClassifyRenderedSegments($normalizer, $hasher);

        $container->set('translation_memory.preload_translations', $preloader);
        $container->set('page_translation.classify_rendered_segments', $classifier);

        $container->set('translation_memory.resolve_translations', new ResolveTranslations(
            $preloader,
            $classifier,
            $discovery,
            $normalizer,
            $hasher,
        ));

        // ── 7. Extraction helper (admin scan) ─────────────────────────────────

        $container->set('page_translation.extract_candidates', new ExtractTranslationCandidates(
            $normalizer,
            $hasher,
        ));

        // ── 8. Translation memory write services ──────────────────────────────

        $container->set('translation_memory.translate_strings', new TranslateStrings(
            $repository,
            $normalizer,
            $hasher,
            $cache,
        ));

        $container->set('translation_memory.query_translations',        new QueryTranslations($repository));
        $container->set('translation_memory.save_translation',          new SaveTranslation($repository));
        $container->set('translation_memory.put_translation_for_review', new PutTranslationForReview($repository));
        $container->set('translation_memory.review_translation',        new ReviewTranslation($repository));
        $container->set('translation_memory.ignore_translation',        new IgnoreTranslation($repository));
        $container->set('translation_memory.reset_ignored_translation', new ResetIgnoredTranslation($repository));

        // ── 9. Content source scanners ────────────────────────────────────────

        $this->registerContentSourceScanner($container, $discovery, $normalizer, $hasher);
        $this->registerArchetypeScanner($container, $discovery, $normalizer, $hasher);

        // ── 10. Lifecycle hooks ───────────────────────────────────────────────

        $lifecycleHooks = new TranslationLifecycleHooks(
            $postRepo,
            $postmetaRepo,
            $discovery,
            $normalizer,
            $hasher,
        );
        $lifecycleHooks->register();
        $container->set('translation_memory.lifecycle_hooks', $lifecycleHooks);
    }

    private function registerArchetypeScanner(
        ServiceContainer $container,
        DiscoverSourceStrings $discovery,
        StringNormalizer $normalizer,
        StringHashGenerator $hasher
    ): void {
        $buildTargets = new BuildScanTargets(new ArchetypeUrlSampler());

        $scanner = new ScanRenderedArchetypes(
            $buildTargets,
            new HtmlParserFactory(new SkipRules()),
            $discovery,
            $normalizer,
            $hasher,
        );

        $container->set('content_source.build_scan_targets', $buildTargets);
        $container->set('content_source.scan_rendered_archetypes', $scanner);
    }

    private function registerContentSourceScanner(
        ServiceContainer $container,
        DiscoverSourceStrings $discovery,
        StringNormalizer $normalizer,
        StringHashGenerator $hasher
    ): void {
        // Fixed readers in prescribed order: media → taxonomy → menu → page → post.
        // CPTs are added on 'init' (priority 99), after all post types are registered.
        // Readers are always constructed; isAvailable() guards execution.
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
            $hasher,
        );

        // Post types registered by other plugins arrive on 'init', which fires after
        // 'plugins_loaded'. Hook late (priority 99) to catch all of them.
        add_action('init', static function () use ($scanner): void {
            // Post types owned by other readers or not publicly translatable.
            $skip = [
                'attachment', 'page', 'post',
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
        }, 99);

        $container->set('content_source.scan_structured_sources', $scanner);
    }
}
