<?php

namespace TransLangs;

use TransLangs\Core\CacheManager;
use TransLangs\Core\HashGenerator;
use TransLangs\Core\Normalizer;
use TransLangs\Core\TranslationRepository;
use TransLangs\Core\Translator;
use TransLangs\Frontend\HtmlGuard;
use TransLangs\Frontend\LanguageResolver;
use TransLangs\Frontend\OutputBuffer;
use TransLangs\Frontend\Parser\ParserFactory;
use TransLangs\Frontend\Parser\SkipRules;
use TransLangs\Frontend\Renderer;
use TransLangs\Frontend\RequestContext;
use TransLangs\Frontend\Router;
use TransLangs\Frontend\StringExtractor;
use TransLangs\Frontend\TranslationLookup;

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
        $this->services['cache']      = $cache;
        $this->services['translator'] = new Translator(
            $repository,
            $normalizer,
            $hasher,
            $cache
        );
    }

    private function registerFrontend(): void
    {
        $resolver = new LanguageResolver();
        $router   = new Router($resolver);
        $context  = new RequestContext();
        $guard    = new HtmlGuard();

        /** @var TranslationRepository $repository */
        $repository = $this->services['repository'];
        /** @var Normalizer $normalizer */
        $normalizer = $this->services['normalizer'];
        /** @var HashGenerator $hasher */
        $hasher = $this->services['hasher'];

        $rules         = new SkipRules();
        $parserFactory = new ParserFactory($rules);
        $extractor     = new StringExtractor($normalizer, $hasher);
        $lookup        = new TranslationLookup($repository);
        $renderer      = new Renderer($parserFactory, $extractor, $lookup, $resolver);
        $buffer        = new OutputBuffer($resolver, $context, $guard, [$renderer, 'render']);

        $this->services['resolver']       = $resolver;
        $this->services['router']         = $router;
        $this->services['requestContext'] = $context;
        $this->services['htmlGuard']      = $guard;
        $this->services['parserRules']    = $rules;
        $this->services['parserFactory']  = $parserFactory;
        $this->services['extractor']      = $extractor;
        $this->services['lookup']         = $lookup;
        $this->services['renderer']       = $renderer;
        $this->services['outputBuffer']   = $buffer;

        $router->register();
        $buffer->register();
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
