<?php

namespace TransLangs\Backend;

use TransLangs\Backend\BackendClient;
use TransLangs\Backend\ConnectionRepository;
use TransLangs\Backend\ConnectionStatus;
use TransLangs\Backend\SiteConnector;
use WP_REST_Response;

/**
 * Connection lifecycle REST endpoints (namespace translangs/v1).
 *
 * manage_options only; never returns the raw token. On a 401 the token is left in
 * place (cleared only on explicit disconnect/reconnect) so a transient backend
 * hiccup does not silently disconnect the site. Every state change refreshes the
 * cached AI-availability status.
 */
final class ConnectionRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private BackendClient $backend,
        private ConnectionRepository $connection,
        private ConnectionStatus $status,
        private SiteConnector $siteConnector
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/connection/status', ['methods' => 'GET', 'callback' => [$this, 'status']] + $guard);
        register_rest_route(self::NS, '/connection/check', ['methods' => 'POST', 'callback' => [$this, 'check']] + $guard);
        register_rest_route(self::NS, '/connection/connect', ['methods' => 'POST', 'callback' => [$this, 'connect']] + $guard);
        register_rest_route(self::NS, '/connection/rotate-token', ['methods' => 'POST', 'callback' => [$this, 'rotate']] + $guard);
        register_rest_route(self::NS, '/connection/disconnect', ['methods' => 'POST', 'callback' => [$this, 'disconnect']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(): WP_REST_Response
    {
        $status = $this->status->current();

        return rest_ensure_response([
            'ok'           => true,
            'connected'    => $this->connection->isConnected(),
            'ai_available' => $status['available'],
            'ai_error'     => $status['error'],
        ]);
    }

    /** Verify the token against GET /auth/me and refresh the cached AI status. */
    public function check(): WP_REST_Response
    {
        $status = $this->status->refresh();

        return rest_ensure_response([
            'ok'           => $status['available'],
            'connected'    => $this->connection->isConnected(),
            'ai_available' => $status['available'],
            'ai_error'     => $status['error'],
        ]);
    }

    /**
     * Connect (or reconnect) using the current site metadata. Used to finish
     * connecting after an offline onboarding, or to retry when the backend
     * recovers. Never blocks: returns an ERR_* code on failure.
     */
    public function connect(): WP_REST_Response
    {
        $result = $this->siteConnector->connect();

        if (!$result['ok']) {
            return new WP_REST_Response(['ok' => false, 'connected' => false, 'error' => $result['error']], 200);
        }

        // SiteConnector::connect() already called status->refresh() — read the
        // now-warm cache instead of probing the backend a second time.
        $status = $this->status->current();

        return rest_ensure_response(['ok' => true, 'connected' => true, 'ai_available' => $status['available']]);
    }

    public function rotate(): WP_REST_Response
    {
        if (!$this->connection->isConnected()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_CONNECTED'], 200);
        }

        $result = $this->backend->rotateToken();

        if (!$result['ok']) {
            return new WP_REST_Response(['ok' => false, 'error' => $result['error']], 200);
        }

        $data  = is_array($result['data']) ? $result['data'] : [];
        $token = (string) ($data['api_token'] ?? $data['token'] ?? '');

        if ($token !== '') {
            $this->connection->store($this->connection->siteId(), $token);
            $this->status->refresh();
        }

        return rest_ensure_response(['ok' => true]);
    }

    /** Revoke remotely if possible, then always clear local credentials. */
    public function disconnect(): WP_REST_Response
    {
        $warning = '';

        if ($this->connection->isConnected()) {
            $result = $this->backend->revokeToken();
            if (!$result['ok']) {
                $warning = $result['error']; // surface once; do not persist
            }
        }

        $this->connection->clear();
        $this->status->refresh();      // now ERR_NOT_CONNECTED → AI disabled
        $this->status->syncSchedule(); // stop the recurring health check

        return rest_ensure_response(['ok' => true, 'warning' => $warning]);
    }
}
