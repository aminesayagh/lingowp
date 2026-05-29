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
        $this->services['translator'] = new Translator(
            $repository,
            $normalizer,
            $hasher,
            $cache
        );
    }

    /**
     * Public translation layer. Filled out in later phases.
     */
    private function registerFrontend(): void
    {
        $resolver = new LanguageResolver();
        $this->services['resolver'] = $resolver;

        // Phase 5+ will register the output buffer here, e.g.:
        // (new Frontend\OutputBuffer($resolver, $this->services['translator']))->register();
    }

    /**
     * Admin management screens. Filled out in Phase 3 and Phase 7.
     */
    private function registerAdmin(): void
    {
        (new \TransLangs\Admin\Assets())->register();
        (new \TransLangs\Admin\SettingsPage())->register();

        // (new Admin\TranslationsPage($this->services['translator']))->register();
    }
}