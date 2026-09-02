<?php

namespace LingoWP\Onboarding;

use LingoWP\Backend\ConnectionRepository;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Backend\SiteConnector;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Language\Domain\LocaleNormalizer;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Onboarding REST endpoints (namespace lingowp/v1).
 *
 * Every route requires manage_options; WordPress cookie auth + the X-WP-Nonce the
 * SPA sends establish the user. The raw api_token is never returned. All errors
 * are ERR_* codes (see the arch doc's Error Code Convention); the SPA localizes.
 */
final class OnboardingRestController
{
    private const NS = 'lingowp/v1';
    private const URL_RISK_NEW_SITE_DAYS = 30;
    private const URL_RISK_SMALL_SITE_MAX_URLS = 20;

    private ConnectionRepository $connection;
    private LanguageRegistry $languages;
    private ConnectionStatus $status;

    public function __construct(
        ConnectionRepository $connection,
        LanguageRegistry $languages,
        ConnectionStatus $status
    ) {
        $this->connection = $connection;
        $this->languages  = $languages;
        $this->status     = $status;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/onboarding/state', ['methods' => 'GET', 'callback' => [$this, 'state']] + $guard);
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
            // The actual URL segment (e.g. "en"), distinct from default_language.code
            // (the WordPress locale, e.g. "en_US") — this is what the prefix toggle
            // adds to URLs, via the same lookup LocalizedUrlBuilder uses.
            'default_language_slug' => $this->languages->slugForLanguage($this->languages->getDefaultLanguage()),
            'settings'              => $this->currentSettings(),
            'has_target_language'   => $this->languages->getTargetLanguages() !== [],
            // Distinct from has_target_language above (which is active-only):
            // a language just created by the "add your first language" step
            // starts as a draft, not active, so that flag alone would keep
            // re-opening the step on every reload until the user separately
            // publishes it. This just asks "does one exist at all yet".
            'has_any_language'      => (new LanguageMetadataStore())->all() !== [],
            // Only meaningful pre-onboarding: Configuration.jsx uses it to seed the
            // "Prefix default language" checkbox. Skip the post-count/age queries once
            // onboarding is done — no screen reads this value afterwards.
            'recommended_prefix_default_language' => $onboardingCompleted
                ? null
                : $this->recommendedPrefixDefaultLanguage(),
        ]);
    }

    /**
     * Save config locally, then attempt to connect. Onboarding proceeds
     * regardless of the connect result: a down backend only means AI stays
     * disabled until a later retry — it never blocks setup.
     */
    public function configure(WP_REST_Request $request): WP_REST_Response
    {
        // Persist toggles first, so config survives even if the backend never answers.
        $browserDetection = (bool) $request->get_param('browser_detection');
        $autoRedirect     = (bool) $request->get_param('auto_redirect') && $browserDetection; // auto-redirect requires detection

        update_option('lingowp_prefix_default_language', (bool) $request->get_param('prefix_default_language'));
        update_option('lingowp_cookie_preference', (bool) $request->get_param('cookie_preference'));
        update_option('lingowp_browser_detection', $browserDetection);
        update_option('lingowp_auto_redirect', $autoRedirect);
        // The URL structure just went live: freeze it so later visits never
        // recompute the recommendation from the site's (changing) risk profile.
        update_option('lingowp_url_structure_initialized', true);

        // Configuration is the last required user action. Persist completion
        // before starting the scan so navigating to Workspace (or reloading)
        // cannot send the user back to the first configuration screen.
        $this->connection->markOnboardingComplete();

        // Cloud connection is deliberately separate from onboarding. Manual
        // translation remains fully local until an administrator explicitly
        // chooses "Connect to LingoWP Cloud" in the Workspace or Billing UI.
        // Keep the existing response fields for compatibility with older builds.
        return rest_ensure_response([
            'ok'        => true,
            'next'      => 'onboarding_setting_up',
            'connected' => $this->connection->isConnected(),
            'warning'   => '',
            'pending'   => false,
        ]);
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

    /** Save WordPress-owned behavior toggles and optionally change Site Language. */
    public function saveSettings(WP_REST_Request $request): WP_REST_Response
    {
        $browserDetection = (bool) $request->get_param('browser_detection');
        $requestedLocale  = LocaleNormalizer::normalize((string) $request->get_param('default_language'));
        $currentLocale    = $this->languages->getDefaultLanguage();

        if ($requestedLocale !== '' && $requestedLocale !== $currentLocale) {
            $catalog = WordPressLanguageCatalog::refresh();
            if ($catalog === null) {
                return new WP_REST_Response([
                    'ok'    => false,
                    'error' => 'ERR_LANGUAGE_CATALOG_UNAVAILABLE',
                ], 503);
            }
            if (!isset($catalog[$requestedLocale])) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_LANGUAGE'], 400);
            }

            if ($requestedLocale !== 'en_US' && !in_array($requestedLocale, get_available_languages(), true)) {
                if (!current_user_can('install_languages')) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'ERR_LANGUAGE_PACK_PERMISSION'], 403);
                }
                if (!wp_can_install_language_pack() || wp_download_language_pack($requestedLocale) === false) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'ERR_LANGUAGE_PACK_INSTALL'], 500);
                }
            }

            // Core represents en_US with an empty WPLANG option. Updating this
            // option synchronously runs SourceLanguageTransition.
            update_option('WPLANG', $requestedLocale === 'en_US' ? '' : $requestedLocale);
        }

        update_option('lingowp_prefix_default_language', (bool) $request->get_param('prefix_default_language'));
        update_option('lingowp_cookie_preference', (bool) $request->get_param('cookie_preference'));
        update_option('lingowp_browser_detection', $browserDetection);
        update_option('lingowp_auto_redirect', (bool) $request->get_param('auto_redirect') && $browserDetection);

        return rest_ensure_response([
            'ok'               => true,
            'settings'         => $this->currentSettings(),
            'default_language' => $this->defaultLanguage(),
        ]);
    }

    /**
     * Once a URL structure has gone live, preserving it IS the recommendation —
     * never recompute from the site's current (possibly changed) risk profile.
     */
    private function recommendedPrefixDefaultLanguage(): bool
    {
        if ((bool) get_option('lingowp_url_structure_initialized', false)) {
            return (bool) get_option('lingowp_prefix_default_language', false);
        }

        return $this->urlChangeRisk() === 'low';
    }

    /**
     * Deliberately simple first pass: non-indexable, or new-and-small, sites have
     * little to lose from a URL change, so prefixing the default language is
     * recommended there. Everything else is treated as high risk.
     */
    private function urlChangeRisk(): string
    {
        if (!(bool) get_option('blog_public')) {
            return 'low';
        }

        $siteIsNew   = $this->oldestPublicContentAgeDays() < self::URL_RISK_NEW_SITE_DAYS;
        $siteIsSmall = $this->publishedPublicUrlCount() < self::URL_RISK_SMALL_SITE_MAX_URLS;

        return $siteIsNew && $siteIsSmall ? 'low' : 'high';
    }

    /** @return string[] Built-in + public custom post types, excluding attachments. */
    private function publicPostTypes(): array
    {
        $builtin = ['post', 'page'];

        if (!function_exists('get_post_types')) {
            return $builtin;
        }

        $custom = array_diff(get_post_types(['public' => true], 'names'), $builtin, ['attachment']);

        return array_merge($builtin, array_values($custom));
    }

    /** Age, in days, of the oldest published post/page/public-CPT entry. No public content yet → 0 (newest possible). */
    private function oldestPublicContentAgeDays(): int
    {
        $oldest = get_posts([
            'post_type'      => $this->publicPostTypes(),
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if ($oldest === []) {
            return 0;
        }

        $publishedGmt = get_post_field('post_date_gmt', $oldest[0]);

        return (int) floor((time() - strtotime($publishedGmt . ' UTC')) / DAY_IN_SECONDS);
    }

    /** Count of published posts/pages/public-CPT entries. */
    private function publishedPublicUrlCount(): int
    {
        $count = 0;
        foreach ($this->publicPostTypes() as $postType) {
            $count += (int) (wp_count_posts($postType)->publish ?? 0);
        }

        return $count;
    }

    /** @return array{code: string, language: string, region: string, name: string, display_name: string, direction: string} */
    private function defaultLanguage(): array
    {
        return $this->languages->getDefaultLanguageDetails();
    }

    /** @return array<string, bool> */
    private function currentSettings(): array
    {
        return [
            'prefix_default_language' => (bool) get_option('lingowp_prefix_default_language', false),
            'cookie_preference'       => (bool) get_option('lingowp_cookie_preference', true),
            'browser_detection'       => (bool) get_option('lingowp_browser_detection', false),
            'auto_redirect'           => (bool) get_option('lingowp_auto_redirect', false),
        ];
    }
}
