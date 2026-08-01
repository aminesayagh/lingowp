<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Api\BackendClient;
use TransLangs\Api\ConnectionRepository;
use TransLangs\Api\ConnectionStatus;
use TransLangs\Api\SiteMetadataProvider;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Infrastructure\WordPressLanguageCatalog;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Onboarding REST endpoints (namespace translangs/v1).
 *
 * Every route requires manage_options; WordPress cookie auth + the X-WP-Nonce the
 * SPA sends establish the user. The raw api_token is never returned. All errors
 * are ERR_* codes (see the arch doc's Error Code Convention); the SPA localizes.
 */
final class OnboardingRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private BackendClient $backend,
        private ConnectionRepository $connection,
        private SiteMetadataProvider $metadata,
        private LanguageRegistry $languages,
        private ConnectionStatus $status
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/onboarding/state', ['methods' => 'GET', 'callback' => [$this, 'state']] + $guard);
        register_rest_route(self::NS, '/onboarding/categories', ['methods' => 'GET', 'callback' => [$this, 'categories']] + $guard);
        register_rest_route(self::NS, '/onboarding/configure', ['methods' => 'POST', 'callback' => [$this, 'configure']] + $guard);
        register_rest_route(self::NS, '/onboarding/complete', ['methods' => 'POST', 'callback' => [$this, 'complete']] + $guard);
        register_rest_route(self::NS, '/settings', ['methods' => 'POST', 'callback' => [$this, 'saveSettings']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** Single bootstrap read for the SPA. */
    public function state(): WP_REST_Response
    {
        $status = $this->status->current();
        // A stored connection proves configuration was already submitted. This
        // also repairs installs that completed configuration before the
        // completion flag was moved earlier in the flow.
        $onboardingCompleted = $this->connection->isOnboardingComplete()
            || $this->connection->isConnected();

        return rest_ensure_response([
            'ok'                    => true,
            'onboarding_completed'  => $onboardingCompleted,
            'connected'             => $this->connection->isConnected(),
            'ai_available'          => $status['available'],
            'ai_error'              => $status['error'],
            'default_language'      => $this->defaultLanguage(),
            'settings'              => $this->currentSettings(),
            'has_target_language'   => $this->languages->getTargetLanguages() !== [],
        ]);
    }

    /** Server-side proxy so React never sees the provision key. */
    public function categories(): WP_REST_Response
    {
        $result = $this->backend->getCategories();

        if (!$result['ok']) {
            return $this->fail($result['error']);
        }

        // Backend returns code-only categories, e.g. ["ecommerce","blog"]; labels are frontend i18n.
        return rest_ensure_response(['ok' => true, 'categories' => $result['data']['categories'] ?? $result['data']]);
    }

    /**
     * Save config locally, store the category selection, then attempt to connect.
     * Onboarding proceeds regardless of the connect result (v1.1): a down backend
     * only means AI stays disabled until a later retry — it never blocks setup.
     */
    public function configure(WP_REST_Request $request): WP_REST_Response
    {
        // Categories are optional — the catalog is only available when the backend
        // is reachable, so onboarding must accept an empty selection.
        $categories = array_values(array_filter(array_map(
            'sanitize_key',
            (array) $request->get_param('categories')
        )));

        // Persist toggles + the category selection first, so config survives even if
        // the backend never answers. Categories are stored locally (pending) and
        // synced on the first successful connect.
        $browserDetection = (bool) $request->get_param('browser_detection');
        $autoRedirect     = (bool) $request->get_param('auto_redirect') && $browserDetection; // auto-redirect requires detection

        update_option(SiteMetadataProvider::OPTION_CATEGORIES, $categories);
        update_option('translangs_prefix_default_language', (bool) $request->get_param('prefix_default_language'));
        update_option('translangs_cookie_preference', (bool) $request->get_param('cookie_preference'));
        update_option('translangs_browser_detection', $browserDetection);
        update_option('translangs_auto_redirect', $autoRedirect);

        // Configuration is the last required user action. Persist completion
        // before starting the scan so navigating to Workspace (or reloading)
        // cannot send the user back to the first configuration screen.
        $this->connection->markOnboardingComplete();

        $warning = $this->attemptConnect();

        // Always advance to setting-up. `connected` tells the SPA whether AI is live;
        // `warning` carries the ERR_* reason when the connect did not succeed.
        return rest_ensure_response([
            'ok'        => true,
            'next'      => 'onboarding_setting_up',
            'connected' => $this->connection->isConnected(),
            'warning'   => $warning,
        ]);
    }

    /**
     * Runs POST /auth/site with the stored metadata + categories, storing the token
     * on success. Returns an ERR_* code on failure ('' on success) — never throws,
     * so callers can proceed offline.
     */
    private function attemptConnect(): string
    {
        $result = $this->backend->createSite($this->metadata->build());

        if (!$result['ok']) {
            return $result['error'];
        }

        $data   = is_array($result['data']) ? $result['data'] : [];
        $siteId = (string) ($data['site_id'] ?? '');
        $token  = (string) ($data['api_token'] ?? $data['token'] ?? '');

        if ($token === '') {
            return 'ERR_BACKEND';
        }

        $this->connection->store($siteId, $token);
        $this->status->refresh();     // AI becomes available immediately
        $this->status->syncSchedule(); // start the recurring health check

        return '';
    }

    /** Mark completed and hand the SPA its dashboard destination. Does not wait for the scan. */
    public function complete(): WP_REST_Response
    {
        $this->connection->markOnboardingComplete();

        $next = $this->languages->getTargetLanguages() !== []
            ? 'dashboard_workspace'
            : 'dashboard_empty_language';

        return rest_ensure_response(['ok' => true, 'next' => $next]);
    }

    /** Save the WordPress-owned behavior toggles post-onboarding (settings modal). */
    public function saveSettings(WP_REST_Request $request): WP_REST_Response
    {
        $browserDetection = (bool) $request->get_param('browser_detection');

        update_option('translangs_prefix_default_language', (bool) $request->get_param('prefix_default_language'));
        update_option('translangs_cookie_preference', (bool) $request->get_param('cookie_preference'));
        update_option('translangs_browser_detection', $browserDetection);
        update_option('translangs_auto_redirect', (bool) $request->get_param('auto_redirect') && $browserDetection);

        return rest_ensure_response(['ok' => true, 'settings' => $this->currentSettings()]);
    }

    /** @return array{code: string, direction: string} */
    private function defaultLanguage(): array
    {
        $code = $this->languages->getDefaultLanguage();
        $dir  = WordPressLanguageCatalog::get($code)['direction'] ?? 'ltr';

        return ['code' => $code, 'direction' => $dir];
    }

    /** @return array<string, bool> */
    private function currentSettings(): array
    {
        return [
            'prefix_default_language' => (bool) get_option('translangs_prefix_default_language', false),
            'cookie_preference'       => (bool) get_option('translangs_cookie_preference', true),
            'browser_detection'       => (bool) get_option('translangs_browser_detection', false),
            'auto_redirect'           => (bool) get_option('translangs_auto_redirect', false),
        ];
    }

    private function fail(string $code, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => $code], $status);
    }
}
