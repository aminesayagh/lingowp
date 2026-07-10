<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Language\Infrastructure\SupportedLanguages;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Target-language REST endpoints (namespace translangs/v1).
 *
 * The JSON metadata store is the local source of truth for which languages exist;
 * the registry tracks which are enabled (active) for runtime routing. Creating an
 * "active" language enables it and fires translangs_language_roster_changed so the
 * background metadata sync (P4) picks up the new active_languages list.
 */
final class LanguageRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private LanguageMetadataStore $store,
        private OptionLanguageRegistry $registry,
        private SourceTranslationRepository $repo
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/languages', ['methods' => 'GET', 'callback' => [$this, 'index']] + $guard);
        register_rest_route(self::NS, '/languages', ['methods' => 'POST', 'callback' => [$this, 'create']] + $guard);
        register_rest_route(self::NS, '/languages/(?P<code>[a-zA-Z_-]+)', ['methods' => 'PATCH', 'callback' => [$this, 'update']] + $guard);
        register_rest_route(self::NS, '/languages/(?P<code>[a-zA-Z_-]+)', ['methods' => 'DELETE', 'callback' => [$this, 'delete']] + $guard);
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

            $languages[] = $entry;
        }

        return rest_ensure_response([
            'ok'               => true,
            'default_language' => $this->registry->getDefaultLanguage(),
            'languages'        => $languages,
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $code = sanitize_key((string) $request->get_param('code'));

        if ($code === '' || $code === $this->registry->getDefaultLanguage()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $status = $request->get_param('status') === 'draft' ? 'draft' : 'active';

        // v1 add form: no region/locale/status inputs. Locale defaults to the code;
        // direction is auto-derived client-side; status defaults to active.
        $this->store->save([
            'code'              => $code,
            'locale'            => (string) ($request->get_param('locale') ?: $code),
            'region'            => (string) $request->get_param('region'),
            'name'              => SupportedLanguages::label($code),
            'display_name'      => (string) $request->get_param('display_name'),
            'slug'              => sanitize_title((string) ($request->get_param('slug') ?: $code)),
            'fallback_language' => sanitize_key((string) $request->get_param('fallback_language')),
            'ai_style'          => (string) $request->get_param('ai_style'),
            'ai_model'          => (string) ($request->get_param('ai_model') ?: 'auto'),
            'direction'         => $request->get_param('direction') === 'rtl' ? 'rtl' : 'ltr',
        ]);

        // A draft language exists in metadata only; an active one is enabled for
        // runtime routing. ponytail: enableLanguage silently ignores codes outside
        // SupportedLanguages — expand that catalog if the backend offers more codes.
        if ($status === 'active') {
            $this->registry->enableLanguage($code);
            do_action('translangs_language_roster_changed', $this->registry->getTargetLanguages());
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $this->store->get($code),
            'next'     => 'dashboard_workspace',
        ]);
    }


    /**
     * Edit one language (PATCH: only provided fields change) and/or move it between
     * active and draft. Enabling/disabling fires translangs_language_roster_changed
     * so the background metadata sync (P4) pushes the new active_languages list.
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $code = sanitize_key((string) $request->get_param('code'));

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
            if ($value !== null) {
                $entry[$field] = (string) $value;
            }
        }
        $slug = $request->get_param('slug');
        if ($slug !== null) {
            $entry['slug'] = sanitize_title((string) $slug);
        }

        $this->store->save($entry);

        // status active↔draft = enabled/disabled for runtime routing.
        $status = $request->get_param('status');
        if ($status === 'active') {
            $this->registry->enableLanguage($code);
            do_action('translangs_language_roster_changed', $this->registry->getTargetLanguages());
        } elseif ($status === 'draft') {
            $this->registry->disableLanguage($code);
            do_action('translangs_language_roster_changed', $this->registry->getTargetLanguages());
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $this->store->get($code),
            'enabled'  => in_array($code, $this->registry->getTargetLanguages(), true),
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
        $code = sanitize_key((string) $request->get_param('code'));

        if ($code === '' || ! $this->store->has($code)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_FOUND'], 404);
        }

        $this->registry->disableLanguage($code);
        $this->store->remove($code);
        do_action('translangs_language_roster_changed', $this->registry->getTargetLanguages());

        return rest_ensure_response(['ok' => true]);
    }
}
