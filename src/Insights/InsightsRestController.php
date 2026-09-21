<?php

namespace LingoWP\Insights;

use LingoWP\Backend\BackendClient;
use LingoWP\Backend\ConnectionRepository;
use WP_REST_Response;

final class InsightsRestController
{
    private const NS = 'lingowp/v1';
    private const SUGGESTION_MESSAGE = 'feature_interest:translangs_insights';

    private BackendClient $backendClient;
    private ConnectionRepository $connection;

    public function __construct(
        BackendClient $backendClient,
        ConnectionRepository $connection
    ) {
        $this->backendClient = $backendClient;
        $this->connection    = $connection;
    }

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
        if (!$this->connection->isConnected()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_CONNECTED'], 200);
        }

        $result = $this->backendClient->submitSupportSuggestion(self::SUGGESTION_MESSAGE);

        return rest_ensure_response([
            'ok'         => $result['ok'],
            'error'      => $result['error'],
            'suggestion' => $result['ok'] ? $result['data'] : null,
        ]);
    }
}
