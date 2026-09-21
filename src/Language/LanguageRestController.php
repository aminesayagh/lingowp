<?php

namespace LingoWP\Language;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
use LingoWP\Language\Domain\LocaleNormalizer;
use WP_REST_Request;
use WP_REST_Response;

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
        $stored    = $this->store->all();
        $progressAll = $this->repo->languageProgressAll(array_keys($stored));

        foreach ($stored as $code => $entry) {
            $entry['enabled'] = in_array($code, $active, true);

            $entry['native_display'] = WordPressLanguageCatalog::nativeDisplay(
                $code,
                (string) ($entry['name'] ?? strtoupper($code))
            );

            $progress                    = $progressAll[$code] ?? ['total' => 0, 'translated' => 0, 'pending' => 0];
            $entry['total_texts']        = $progress['total'];
            $entry['translated_texts']   = $progress['translated'];
            $entry['pending_texts']      = $progress['pending'];
            $entry['translated_percent'] = $progress['total'] > 0
                ? (int) round($progress['translated'] / $progress['total'] * 100)
                : 100;

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
                    'display_name'      => '',
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

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $code = LocaleNormalizer::normalize((string) $request->get_param('code'));

        $current = $this->store->get($code);
        if ($code === '' || $current === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_FOUND'], 404);
        }

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
