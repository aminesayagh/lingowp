<?php

namespace LingoWP\Backend;

use LingoWP\Backend\ConnectionRepository;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Backend\SiteConnector;
use WP_REST_Request;
use WP_REST_Response;

final class ConnectionRestController
{
    private const NS = 'lingowp/v1';

    private ConnectionRepository $connection;
    private ConnectionStatus $status;
    private SiteConnector $siteConnector;
    private BackendClient $backendClient;

    public function __construct(
        ConnectionRepository $connection,
        ConnectionStatus $status,
        SiteConnector $siteConnector,
        BackendClient $backendClient
    ) {
        $this->connection    = $connection;
        $this->status        = $status;
        $this->siteConnector = $siteConnector;
        $this->backendClient = $backendClient;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/connection/status', ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/connection/ping', ['methods' => 'GET', 'callback' => [$this, 'ping'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/connection/check', ['methods' => 'POST', 'callback' => [$this, 'check'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/connection/connect', ['methods' => 'POST', 'callback' => [$this, 'connect'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/connection/confirm', ['methods' => 'POST', 'callback' => [$this, 'confirm'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/connection/recover', ['methods' => 'POST', 'callback' => [$this, 'recover'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/connection/disconnect', ['methods' => 'POST', 'callback' => [$this, 'disconnect'], 'permission_callback' => [$this, 'canManage']]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(): WP_REST_Response
    {
        return $this->respond(true, $this->status->current());
    }

    public function ping(): WP_REST_Response
    {
        return rest_ensure_response(['ok' => $this->backendClient->getHealth(5)['ok']]);
    }

    public function check(): WP_REST_Response
    {
        $status = $this->siteConnector->ensureValid();

        return $this->respond($status['available'], $status);
    }

    private function respond(bool $ok, array $status, array $extra = []): WP_REST_Response
    {
        return rest_ensure_response(array_merge([
            'ok'           => $ok,
            'connected'    => $this->connection->isConnected(),
            'masked_key'   => $this->connection->maskedKey(),
            'ai_available' => $status['available'],
            'ai_error'     => $status['error'],
        ], $extra));
    }

    public function connect(): WP_REST_Response
    {
        $result = $this->siteConnector->connect();

        if (!$result['ok']) {
            return new WP_REST_Response(['ok' => false, 'connected' => false, 'error' => $result['error']], 200);
        }

        $status = $this->status->current();

        return $this->respond($status['available'], $status, ['recovery_available' => $result['recoveryAvailable']]);
    }

    public function confirm(WP_REST_Request $request): WP_REST_Response
    {
        $token = sanitize_text_field((string) $request->get_param('token'));

        if ($token === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->siteConnector->confirm($token);

        if (!$result['ok']) {
            return new WP_REST_Response(['ok' => false, 'connected' => false, 'error' => $result['error']], 200);
        }

        $status = $this->status->current();

        return $this->respond($status['available'], $status);
    }

    public function recover(): WP_REST_Response
    {
        $result = $this->siteConnector->recover();

        return new WP_REST_Response(['ok' => $result['ok'], 'error' => $result['error']], 200);
    }

    public function disconnect(): WP_REST_Response
    {
        $this->connection->clear();
        $this->status->clearCache();

        return $this->respond(false, ['available' => false, 'error' => 'ERR_NOT_CONNECTED']);
    }
}
