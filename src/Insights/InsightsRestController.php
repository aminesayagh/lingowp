<?php

namespace TransLangs\Insights;

use TransLangs\Backend\BackendClient;
use TransLangs\Backend\ConnectionRepository;
use WP_REST_Response;

/**
 * Insights preview screen's single interaction (namespace translangs/v1):
 * the "Notify me at launch" button. Forwards straight to the backend on
 * click — no local persistence, no retry queue. A reload always shows the
 * button in its default state; this only reports whether this one click
 * landed.
 */
final class InsightsRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private BackendClient $backendClient,
        private ConnectionRepository $connection,
        private NotifyEmailResolver $notifyEmailResolver
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/insights/notify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'notify'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function notify(): WP_REST_Response
    {
        $email = $this->notifyEmailResolver->resolve()['email'];
        if ($email === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->notifyInsightsLaunch([
            'domain'         => $this->connection->domain(),
            'email'          => $email,
            'plugin_version' => TRANSLANGS_VERSION,
        ]);

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }
}
