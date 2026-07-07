<?php

namespace TransLangs\Admin\Rest;

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
        private OptionLanguageRegistry $registry
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
            $languages[]      = $entry;
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

        $this->store->save([
            'code'              => $code,
            'locale'            => (string) $request->get_param('locale'),
            'region'            => (string) $request->get_param('region'),
            'name'              => SupportedLanguages::label($code),
            'display_name'      => (string) $request->get_param('display_name'),
            'slug'              => sanitize_title((string) ($request->get_param('slug') ?: $code)),
            'fallback_language' => sanitize_key((string) $request->get_param('fallback_language')),
            'ai_style'          => (string) $request->get_param('ai_style'),
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
}
