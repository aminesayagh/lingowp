<?php

namespace LingoWP\Language;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
use LingoWP\Language\Domain\LocaleNormalizer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Target-language REST endpoints (namespace lingowp/v1).
 *
 * The JSON metadata store is the local source of truth for which languages exist;
 * the registry tracks which are enabled (active) for runtime routing. Creating an
 * "active" language enables it and fires lingowp_language_roster_changed so the
 * background metadata sync (P4) picks up the new active_languages list.
 */
final class LanguageRestController
{
    private const NS = 'lingowp/v1';

    private LanguageMetadataStore $store;
    private OptionLanguageRegistry $registry;
    private WpDbSourceRepository $repo;

    public function __construct(
        LanguageMetadataStore $store,
        OptionLanguageRegistry $registry,
        WpDbSourceRepository $repo
    ) {
        $this->store    = $store;
        $this->registry = $registry;
        $this->repo     = $repo;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/languages', ['methods' => 'GET', 'callback' => [$this, 'index']] + $guard);
        register_rest_route(self::NS, '/languages', ['methods' => 'POST', 'callback' => [$this, 'create']] + $guard);
        register_rest_route(self::NS, '/languages/catalog', ['methods' => 'GET', 'callback' => [$this, 'catalog']] + $guard);
        register_rest_route(self::NS, '/languages/targets', ['methods' => 'GET', 'callback' => [$this, 'targets']] + $guard);
        register_rest_route(self::NS, '/languages/ai-model', ['methods' => 'POST', 'callback' => [$this, 'updateAiModelForAll']] + $guard);
        register_rest_route(self::NS, '/languages/(?P<code>[a-zA-Z0-9_-]+)', ['methods' => 'PATCH', 'callback' => [$this, 'update']] + $guard);
        register_rest_route(self::NS, '/languages/(?P<code>[a-zA-Z0-9_-]+)', ['methods' => 'DELETE', 'callback' => [$this, 'delete']] + $guard);
        register_rest_route(self::NS, '/languages/(?P<code>[a-zA-Z0-9_-]+)/use-fallback', ['methods' => 'POST', 'callback' => [$this, 'useFallback']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function index(): WP_REST_Response
    {
        $active    = $this->registry->getTargetLanguages();
        $languages = [];

        foreach ($this->store->all() as $code => $entry) {
            $entry['enabled'] = in_array($code, $active, true);

            $progress                    = $this->repo->languageProgress($code);
            $entry['total_texts']        = $progress['total'];
            $entry['translated_texts']   = $progress['translated'];
            // No content yet ⇒ nothing left to translate ⇒ 100% (hides "Translate All").
            $entry['translated_percent'] = $progress['total'] > 0
                ? (int) round($progress['translated'] / $progress['total'] * 100)
                : 100;

            // Backs the rail's "Use fallback translation" button: only ready when
            // the fallback shares the base language (cheap check first) AND it
            // actually has at least one translation this language is missing.
            $fallback               = (string) ($entry['fallback_language'] ?? '');
            $entry['fallback_ready'] = $fallback !== ''
                && LocaleNormalizer::language($fallback) === LocaleNormalizer::language($code)
                && $this->repo->hasFallbackTranslationsToCopy($fallback, $code);

            $languages[] = $entry;
        }

        return rest_ensure_response([
            'ok'               => true,
            'default_language' => $this->registry->getDefaultLanguage(),
            'languages'        => $languages,
            'catalog'          => array_values(WordPressLanguageCatalog::all()),
        ]);
    }

    /** GET /languages/targets — active target languages only, with display names. Light: no DB progress queries. */
    public function targets(): WP_REST_Response
    {
        return rest_ensure_response(['ok' => true, 'languages' => $this->registry->getTargetLanguageDetails()]);
    }

    public function catalog(): WP_REST_Response
    {
        $catalog = WordPressLanguageCatalog::refresh();
        if ($catalog === null) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'ERR_LANGUAGE_CATALOG_UNAVAILABLE',
            ], 503);
        }

        return rest_ensure_response([
            'ok'      => true,
            'catalog' => array_values($catalog),
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $code    = LocaleNormalizer::normalize((string) $request->get_param('code'));
        $catalog = WordPressLanguageCatalog::get($code);

        if ($code !== '' && $code === $this->registry->getDefaultLanguage()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SOURCE_LANGUAGE'], 409);
        }

        // Locale is the unique translation identity. Never trust the form's
        // disabled state: reject a duplicate canonical locale at the REST edge.
        if ($code !== '' && $this->store->has($code)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_LANGUAGE_EXISTS'], 409);
        }

        if ($code !== '' && $catalog === null) {
            $refreshed = WordPressLanguageCatalog::refresh();
            if ($refreshed === null) {
                return new WP_REST_Response([
                    'ok'    => false,
                    'error' => 'ERR_LANGUAGE_CATALOG_UNAVAILABLE',
                ], 503);
            }
            $catalog = $refreshed[$code] ?? null;
        }

        $slug     = sanitize_title((string) ($request->get_param('slug') ?: $code));
        $fallback = LocaleNormalizer::normalize((string) $request->get_param('fallback_language'));
        if (
            $code === '' || $catalog === null || !$this->slugAvailable($slug)
            || !$this->fallbackAvailable($fallback, $code)
        ) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $status = $request->get_param('status') === 'active' ? 'active' : 'draft';

        // The canonical WordPress locale is the language identity and translation
        // key; language, region, display metadata, and direction come from core's
        // Site Language catalog rather than trusting client-supplied metadata.
        $this->store->save([
            'code'              => $code,
            'language'          => $catalog['language'],
            'locale'            => $code,
            'region'            => $catalog['region'],
            'name'              => $catalog['english_name'],
            'display_name'      => (string) $request->get_param('display_name'),
            'slug'              => $slug,
            'fallback_language' => $fallback,
            'ai_style'          => (string) $request->get_param('ai_style'),
            'ai_model'          => (string) ($request->get_param('ai_model') ?: 'auto'),
            'direction'         => $catalog['direction'],
        ]);

        // A draft language exists in metadata only; an active one is enabled for
        // runtime routing.
        if ($status === 'active') {
            $this->registry->enableLanguage($code);
            $this->invalidateLanguageRoutes();
            do_action('lingowp_language_roster_changed', $this->registry->getTargetLanguages());
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $this->store->get($code),
            'next'     => 'dashboard_workspace',
        ]);
    }


    /**
     * Edit one language (PATCH: only provided fields change) and/or move it between
     * active and draft. Enabling/disabling fires lingowp_language_roster_changed
     * so the background metadata sync (P4) pushes the new active_languages list.
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $code = LocaleNormalizer::normalize((string) $request->get_param('code'));

        $current = $this->store->get($code);
        if ($code === '' || $current === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_FOUND'], 404);
        }

        // Merge only the fields the request actually sent (PATCH semantics).
        $editable = ['display_name', 'region', 'locale', 'fallback_language', 'ai_style', 'ai_model', 'direction'];
        $entry    = $current;
        $entry['code'] = $code;
        foreach ($editable as $field) {
            $value = $request->get_param($field);
            if ($value === null) {
                continue;
            }
            if ($field === 'fallback_language') {
                $fallback = LocaleNormalizer::normalize((string) $value);
                if (!$this->fallbackAvailable($fallback, $code)) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
                }
                $entry[$field] = $fallback;
            } else {
                $entry[$field] = (string) $value;
            }
        }
        $slug = $request->get_param('slug');
        if ($slug !== null) {
            $slug = sanitize_title((string) $slug);
            if ($slug === '' || !$this->slugAvailable($slug, $code)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
            }
            $entry['slug'] = $slug;
        }

        $this->store->save($entry);

        // status active↔draft = enabled/disabled for runtime routing.
        $status = $request->get_param('status');
        if ($status === 'active') {
            $this->registry->enableLanguage($code);
            $this->invalidateLanguageRoutes();
            do_action('lingowp_language_roster_changed', $this->registry->getTargetLanguages());
        } elseif ($status === 'draft') {
            $this->registry->disableLanguage($code);
            $this->invalidateLanguageRoutes();
            do_action('lingowp_language_roster_changed', $this->registry->getTargetLanguages());
        } elseif ($slug !== null) {
            $this->invalidateLanguageRoutes();
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $this->store->get($code),
            'enabled'  => in_array($code, $this->registry->getTargetLanguages(), true),
        ]);
    }

    /**
     * Set every configured language's AI model in one option write. Backs the
     * settings-popover "Use LingoWP AI model" / "Use your <provider> key" bulk
     * action — a client PATCH-per-language loop races itself on the shared
     * lingowp_languages option, so this does it atomically.
     */
    public function updateAiModelForAll(WP_REST_Request $request): WP_REST_Response
    {
        $model   = (string) $request->get_param('ai_model');
        $allowed = ['internal', 'auto', 'openai', 'anthropic'];
        if (!in_array($model, $allowed, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        return rest_ensure_response([
            'ok'      => true,
            'changed' => $this->store->setAiModelForAll($model),
        ]);
    }

    /**
     * Delete a target language: disable it (drop from active routing) and remove its
     * metadata so it leaves the workspace. Its translations, keyed by lang_code, are
     * left in place — the roster is append-only by design, so re-adding the code is
     * instant. A hard purge of the child rows is a separate maintenance job.
     */
    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $code = LocaleNormalizer::normalize((string) $request->get_param('code'));

        if ($code === '' || ! $this->store->has($code)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_FOUND'], 404);
        }

        $this->registry->disableLanguage($code);
        $this->store->remove($code);
        $this->invalidateLanguageRoutes();
        do_action('lingowp_language_roster_changed', $this->registry->getTargetLanguages());

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * Bulk-copy every translation the configured fallback_language already has
     * into $code wherever $code is missing one (never overwrites), so a
     * newly-added regional variant doesn't start from zero. Re-validates the
     * same-base-language condition server-side — the UI only disables the
     * button for a cross-language fallback, it doesn't prevent the request.
     */
    public function useFallback(WP_REST_Request $request): WP_REST_Response
    {
        $code  = LocaleNormalizer::normalize((string) $request->get_param('code'));
        $entry = $code === '' ? null : $this->store->get($code);
        if ($entry === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_FOUND'], 404);
        }

        $fallback = (string) ($entry['fallback_language'] ?? '');
        if ($fallback === '' || LocaleNormalizer::language($fallback) !== LocaleNormalizer::language($code)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NO_FALLBACK'], 400);
        }

        $copied = $this->repo->copyFallbackTranslations($fallback, $code);

        return rest_ensure_response(['ok' => true, 'copied' => $copied]);
    }

    /** A fallback is valid if empty, or if it points at the default language or an already-added one, and not at itself. */
    private function fallbackAvailable(string $fallback, string $code): bool
    {
        if ($fallback === '') {
            return true;
        }

        return $fallback !== $code
            && ($fallback === $this->registry->getDefaultLanguage() || $this->store->has($fallback));
    }

    private function slugAvailable(string $slug, string $exceptCode = ''): bool
    {
        if ($slug === '') {
            return false;
        }
        $defaultSlug = sanitize_title(str_replace('_', '-', strtolower($this->registry->getDefaultLanguage())));
        if ($slug === $defaultSlug) {
            return false;
        }
        foreach ($this->store->all() as $code => $entry) {
            if ($code !== $exceptCode && sanitize_title((string) ($entry['slug'] ?? '')) === $slug) {
                return false;
            }
        }

        return true;
    }

    private function invalidateLanguageRoutes(): void
    {
        delete_option('rewrite_rules');
        delete_option('lingowp_rewrite_rules_version');
    }
}
