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
        register_rest_route(self::NS, '/languages/bulk', ['methods' => 'POST', 'callback' => [$this, 'bulkCreate']] + $guard);
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
        if ($code === '' || $catalog === null || !$this->fallbackAvailable($fallback, $code)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        if (!$this->slugAvailable($slug)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SLUG_TAKEN'], 409);
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
     * Create languages from an arbitrary set of base-language + region picks in
     * one call — the onboarding "Add languages" picker's basket of checkboxes
     * across several base languages at once (e.g. fr: [fr_FR, fr_CA] and
     * de: [de_DE] in the same request). Per-locale metadata (display name,
     * slug, fallback) is derived the same way a single create() would derive
     * it for that locale. An already-added locale, one whose declared base
     * language doesn't match its own catalog entry, or one whose derived slug
     * collides, is skipped rather than failing the whole batch.
     */
    public function bulkCreate(WP_REST_Request $request): WP_REST_Response
    {
        $selections = $request->get_param('selections');
        if (!is_array($selections) || $selections === []) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $catalog          = WordPressLanguageCatalog::all();
        $catalogRefreshed = false;
        $defaultCode      = $this->registry->getDefaultLanguage();
        $aiModel          = (string) ($request->get_param('ai_model') ?: 'auto');
        $aiStyle          = (string) $request->get_param('ai_style');
        $status           = $request->get_param('status') === 'active' ? 'active' : 'draft';

        $created = [];
        // Every locale the client asked for that didn't become a language,
        // with why — so the frontend can tell a genuine partial success
        // (some skipped, most likely already added by another tab/session)
        // apart from silently pretending the whole batch went through.
        $skipped = [];
        foreach ($selections as $selection) {
            if (!is_array($selection)) {
                continue;
            }
            $language = sanitize_key((string) ($selection['language'] ?? ''));
            $locales  = $selection['locales'] ?? [];
            if ($language === '' || !is_array($locales)) {
                continue;
            }

            foreach ($locales as $rawLocale) {
                $code = LocaleNormalizer::normalize((string) $rawLocale);
                if ($code === '') {
                    $skipped[] = ['locale' => (string) $rawLocale, 'reason' => 'invalid_locale'];
                    continue;
                }
                if ($code === $defaultCode || $this->store->has($code)) {
                    $skipped[] = ['locale' => $code, 'reason' => 'already_exists'];
                    continue;
                }

                $entry = $catalog[$code] ?? null;
                if ($entry === null && !$catalogRefreshed) {
                    $refreshed = WordPressLanguageCatalog::refresh();
                    if ($refreshed === null) {
                        return new WP_REST_Response(['ok' => false, 'error' => 'ERR_LANGUAGE_CATALOG_UNAVAILABLE'], 503);
                    }
                    $catalog          = $refreshed;
                    $catalogRefreshed = true;
                    $entry            = $catalog[$code] ?? null;
                }
                // Never trust the client's declared base language for a locale —
                // same "don't trust the form" edge create() applies to its own
                // single code/catalog pairing.
                if ($entry === null) {
                    $skipped[] = ['locale' => $code, 'reason' => 'unknown_locale'];
                    continue;
                }
                if ($entry['language'] !== $language) {
                    $skipped[] = ['locale' => $code, 'reason' => 'language_mismatch'];
                    continue;
                }

                $slug = sanitize_title(str_replace('_', '-', strtolower($code)));
                if (!$this->slugAvailable($slug)) {
                    $skipped[] = ['locale' => $code, 'reason' => 'slug_taken'];
                    continue;
                }

                $this->store->save([
                    'code'              => $code,
                    'language'          => $entry['language'],
                    'locale'            => $code,
                    'region'            => $entry['region'],
                    'name'              => $entry['english_name'],
                    'display_name'      => $entry['language_name'] . ' (' . $this->regionLabel($entry) . ')',
                    'slug'              => $slug,
                    'fallback_language' => $this->smartFallback($entry['language'], $defaultCode),
                    'ai_style'          => $aiStyle,
                    'ai_model'          => $aiModel,
                    'direction'         => $entry['direction'],
                ]);

                if ($status === 'active') {
                    $this->registry->enableLanguage($code);
                }

                $created[] = $this->store->get($code);
            }
        }

        if ($created === []) {
            return new WP_REST_Response([
                'ok'      => false,
                'error'   => 'ERR_NO_LANGUAGES_ADDED',
                'skipped' => $skipped,
            ], 400);
        }

        if ($status === 'active') {
            $this->invalidateLanguageRoutes();
            do_action('lingowp_language_roster_changed', $this->registry->getTargetLanguages());
        }

        return rest_ensure_response([
            'ok'        => true,
            'languages' => $created,
            'skipped'   => $skipped,
            'next'      => 'dashboard_workspace',
        ]);
    }

    /** Same "region — variant" label shape the SPA's own regionLabel() builds for the region <Select>. */
    private function regionLabel(array $catalogEntry): string
    {
        $label = $catalogEntry['region_name'] !== '' ? $catalogEntry['region_name'] : ($catalogEntry['region'] !== '' ? $catalogEntry['region'] : 'General');

        return $catalogEntry['variant_name'] !== '' ? $label . ' — ' . $catalogEntry['variant_name'] : $label;
    }

    /**
     * Same rule the SPA's own smartFallback() uses for a single create(): a
     * same-base-language language already on the site is a better fallback
     * than the site default (e.g. fr_CA should fall back to fr_FR, not the
     * English source); the default only wins when it's itself that base
     * language, or nothing else qualifies. store->all() reflects every locale
     * saved earlier in this same request too (save() writes through
     * immediately), so within one bulkCreate() batch each locale after the
     * first sibling of its own base language falls back to that sibling, not
     * all the way to the site default — regardless of which selections
     * entry either one came from.
     */
    private function smartFallback(string $language, string $defaultCode): string
    {
        $defaultEntry = WordPressLanguageCatalog::get($defaultCode);
        if ($defaultEntry !== null && $defaultEntry['language'] === $language) {
            return $defaultCode;
        }
        foreach ($this->store->all() as $code => $entry) {
            if (($entry['language'] ?? '') === $language) {
                return $code;
            }
        }

        return $defaultCode;
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
            if ($slug === '') {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
            }
            if (!$this->slugAvailable($slug, $code)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SLUG_TAKEN'], 409);
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
     * Set every configured language's AI model (and, optionally, writing
     * style) in one option write. Backs both the settings-popover's model-only
     * "Use LingoWP AI model" / "Use your <provider> key" bulk action, and the
     * AI settings tab's own "Apply to all languages" (model + style). A
     * per-language PATCH loop from the client races itself on the shared
     * lingowp_languages option, so this does it atomically. Omitting ai_style
     * entirely leaves each language's own style untouched — only sending it
     * (even as an empty string, to clear it) overwrites it everywhere.
     */
    public function updateAiModelForAll(WP_REST_Request $request): WP_REST_Response
    {
        $model   = (string) $request->get_param('ai_model');
        $allowed = ['internal', 'auto', 'openai', 'anthropic'];
        if (!in_array($model, $allowed, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        $style = $request->get_param('ai_style');
        $style = $style === null ? null : (string) $style;

        return rest_ensure_response([
            'ok'      => true,
            'changed' => $this->store->setAiConfigForAll($model, $style),
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
