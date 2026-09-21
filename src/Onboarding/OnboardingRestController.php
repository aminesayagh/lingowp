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
        register_rest_route(self::NS, '/onboarding/state', ['methods' => 'GET', 'callback' => [$this, 'state'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/onboarding/configure', ['methods' => 'POST', 'callback' => [$this, 'configure'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/onboarding/complete', ['methods' => 'POST', 'callback' => [$this, 'complete'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/settings', ['methods' => 'POST', 'callback' => [$this, 'saveSettings'], 'permission_callback' => [$this, 'canManage']]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function state(): WP_REST_Response
    {
        $status = $this->status->current();
        $onboardingCompleted = $this->connection->isOnboardingComplete();

        return rest_ensure_response([
            'ok'                    => true,
            'onboarding_completed'  => $onboardingCompleted,
            'connected'             => $this->connection->isConnected(),
            'ai_available'          => $status['available'],
            'ai_error'              => $status['error'],
            'default_language'      => $this->defaultLanguage(),
            'default_language_slug' => $this->languages->slugForLanguage($this->languages->getDefaultLanguage()),
            'settings'              => $this->currentSettings(),
            'has_target_language'   => $this->languages->getTargetLanguages() !== [],
            'has_any_language'      => (new LanguageMetadataStore())->all() !== [],
            'recommended_prefix_default_language' => $onboardingCompleted
                ? null
                : $this->recommendedPrefixDefaultLanguage(),
        ]);
    }

    public function configure(WP_REST_Request $request): WP_REST_Response
    {
        $browserDetection = (bool) $request->get_param('browser_detection');
        $autoRedirect     = (bool) $request->get_param('auto_redirect') && $browserDetection;

        update_option('lingowp_prefix_default_language', (bool) $request->get_param('prefix_default_language'));
        update_option('lingowp_cookie_preference', (bool) $request->get_param('cookie_preference'));
        update_option('lingowp_browser_detection', $browserDetection);
        update_option('lingowp_auto_redirect', $autoRedirect);
        update_option('lingowp_url_structure_initialized', true);

        $this->connection->markOnboardingComplete();

        return rest_ensure_response([
            'ok'        => true,
            'next'      => 'onboarding_setting_up',
            'connected' => $this->connection->isConnected(),
            'warning'   => '',
            'pending'   => false,
        ]);
    }

    public function complete(): WP_REST_Response
    {
        $this->connection->markOnboardingComplete();

        $next = $this->languages->getTargetLanguages() !== []
            ? 'dashboard_workspace'
            : 'dashboard_empty_language';

        return rest_ensure_response(['ok' => true, 'next' => $next]);
    }

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

    private function recommendedPrefixDefaultLanguage(): bool
    {
        if ((bool) get_option('lingowp_url_structure_initialized', false)) {
            return (bool) get_option('lingowp_prefix_default_language', false);
        }

        return $this->urlChangeRisk() === 'low';
    }

    private function urlChangeRisk(): string
    {
        if (!(bool) get_option('blog_public')) {
            return 'low';
        }

        $siteIsNew   = $this->oldestPublicContentAgeDays() < self::URL_RISK_NEW_SITE_DAYS;
        $siteIsSmall = $this->publishedPublicUrlCount() < self::URL_RISK_SMALL_SITE_MAX_URLS;

        return $siteIsNew && $siteIsSmall ? 'low' : 'high';
    }

    private function publicPostTypes(): array
    {
        $builtin = ['post', 'page'];

        if (!function_exists('get_post_types')) {
            return $builtin;
        }

        $custom = array_diff(get_post_types(['public' => true], 'names'), $builtin, ['attachment']);

        return array_merge($builtin, array_values($custom));
    }

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

    private function publishedPublicUrlCount(): int
    {
        $count = 0;
        foreach ($this->publicPostTypes() as $postType) {
            $count += (int) (wp_count_posts($postType)->publish ?? 0);
        }

        return $count;
    }

    private function defaultLanguage(): array
    {
        return $this->languages->getDefaultLanguageDetails();
    }

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
