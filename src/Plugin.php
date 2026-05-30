<?php

namespace TransLangs;

use TransLangs\Core\CacheManager;
use TransLangs\Core\HashGenerator;
use TransLangs\Core\Normalizer;
use TransLangs\Core\TranslationRepository;
use TransLangs\Core\Translator;
use TransLangs\Frontend\LanguageResolver;

/**
 * Plugin container.
 *
 * Builds each service once, injects dependencies through constructors,
 * and registers WordPress hooks. Nothing here reaches for globals, so the
 * same service instances can serve the frontend, admin, and (later) REST.
 */
class Plugin
{
    /**
     * Built service instances, keyed by short name.
     *
     * @var array<string, object>
     */
    private array $services = [];

    public function boot(): void
    {
        $this->registerCore();
        $this->registerFrontend();

        if (is_admin()) {
            $this->registerAdmin();
        }
    }

    /**
     * Retrieve a built service by key. Useful for tests and later wiring.
     */
    public function get(string $key): ?object
    {
        return $this->services[$key] ?? null;
    }

    /**
     * Core engine. Knows nothing about WordPress routing or screens.
     */
    private function registerCore(): void
    {
        $repository = new TranslationRepository();
        $normalizer = new Normalizer();
        $hasher     = new HashGenerator();
        $cache      = new CacheManager();

        $this->services['repository'] = $repository;
        $this->services['normalizer'] = $normalizer;
        $this->services['hasher']     = $hasher;
        $this->services['translator'] = new Translator(
            $repository,
            $normalizer,
            $hasher,
            $cache
        );

        // Extractor turns parser segments into candidates; lookup
        // resolves them against the memory (bulk select + idempotent insert).
        $this->services['extractor'] = new \TransLangs\Frontend\StringExtractor(
            $normalizer,
            $hasher
        );
        $this->services['lookup'] = new \TransLangs\Frontend\TranslationLookup($repository);
    }

    /**
     * Public translation layer. Filled out in later phases.
     */
    private function registerFrontend(): void
    {
        $resolver = new LanguageResolver();
        $this->services['resolver'] = $resolver;

        (new \TransLangs\Frontend\Router($resolver))->register();

        $context = new \TransLangs\Frontend\RequestContext();
        $guard   = new \TransLangs\Frontend\HtmlGuard();

        $buffer = new \TransLangs\Frontend\OutputBuffer($resolver, $context, $guard);

        // Step 16: assemble the real pipeline and inject it. parser -> extract
        // -> lookup -> replace. The buffer calls this only on translated,
        // HTML, front-end GET requests (see RequestContext + HtmlGuard).
        $renderer = new \TransLangs\Frontend\Renderer(
            new \TransLangs\Frontend\Parser\ParserFactory(
                new \TransLangs\Frontend\Parser\SkipRules()
            ),
            $this->services['extractor'],
            $this->services['lookup'],
            $resolver,
            new \TransLangs\Frontend\LinkRewriter(
                $resolver,
                new \TransLangs\Frontend\UrlPrefixer($resolver)
            )
        );

        $buffer->setPipeline(
            static fn(string $html, string $lang): string => $renderer->render($html, $lang)
        );

        $buffer->register();

        $this->services['renderer']      = $renderer;
        $this->services['output_buffer'] = $buffer;

        // language switcher shortcode [tls_language_switcher].
        (new \TransLangs\Frontend\LanguageSwitcher(
            $resolver,
            new \TransLangs\Frontend\UrlPrefixer($resolver)
        ))->register();

        // hreflang alternate tags in <head>.
        (new \TransLangs\Frontend\SeoManager(
            $resolver,
            new \TransLangs\Frontend\UrlPrefixer($resolver),
            $context
        ))->register();
    }

    /**
     * Admin management screens. Filled out in Phase 3 and Phase 7.
     */
    private function registerAdmin(): void
    {
        (new \TransLangs\Admin\Assets())->register();
        (new \TransLangs\Admin\SettingsPage())->register();
        (new \TransLangs\Admin\TranslationsPage($this->services['repository']))->register();
    }
}
