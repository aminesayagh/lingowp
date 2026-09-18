<?php

namespace LingoWP\Backend;

use LingoWP\Backend\ConnectionRepository;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Backend\SiteConnector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Connection lifecycle REST endpoints (namespace lingowp/v1).
 *
 * manage_options only; never returns the raw license key, and never accepts
 * one from the client either — confirm() only ever takes an opaque confirm
 * token and exchanges it server-to-server. A dead key (401/402/410) is
 * self-healed by SiteConnector::ensureValid() — there is no "leave it alone"
 * case anymore, domain no longer factors into auth at all. Every state
 * change refreshes the cached AI-availability status.
 */
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
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/connection/status', ['methods' => 'GET', 'callback' => [$this, 'status']] + $guard);
        register_rest_route(self::NS, '/connection/ping', ['methods' => 'GET', 'callback' => [$this, 'ping']] + $guard);
        register_rest_route(self::NS, '/connection/check', ['methods' => 'POST', 'callback' => [$this, 'check']] + $guard);
        register_rest_route(self::NS, '/connection/connect', ['methods' => 'POST', 'callback' => [$this, 'connect']] + $guard);
        register_rest_route(self::NS, '/connection/confirm', ['methods' => 'POST', 'callback' => [$this, 'confirm']] + $guard);
        register_rest_route(self::NS, '/connection/recover', ['methods' => 'POST', 'callback' => [$this, 'recover']] + $guard);
        register_rest_route(self::NS, '/connection/disconnect', ['methods' => 'POST', 'callback' => [$this, 'disconnect']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(): WP_REST_Response
    {
        return $this->respond(true, $this->status->current());
    }

    /**
     * Is the backend reachable right now? Onboarding asks this once, before
     * offering "Connect to LingoWP Cloud" as the primary action — when the
     * cloud is dark the form falls back to a plain Continue. Unauthenticated
     * (/health needs no key) and on a short timeout: a slow answer must not
     * hold the form.
     */
    public function ping(): WP_REST_Response
    {
        return rest_ensure_response(['ok' => $this->backendClient->getHealth(5)['ok']]);
    }

    /**
     * Verify the credential against GET /me/entitlements and refresh the
     * cached AI status — self-healing a stale key (401/410) in the process.
     */
    public function check(): WP_REST_Response
    {
        $status = $this->siteConnector->ensureValid();

        return $this->respond($status['available'], $status);
    }

    /** @param array{available: bool, error: string} $status */
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

    /**
     * Connect (or reconnect) using the current site metadata. Used to finish
     * connecting after an offline onboarding, or as an explicit user-initiated
     * retry. Never blocks and never emails anything itself: a fresh, working
     * key is issued synchronously on every success — returns an ERR_* code
     * on failure only.
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

        return $this->respond($status['available'], $status, ['recovery_available' => $result['recoveryAvailable']]);
    }

    /**
     * Applies a confirm_token deep-linked from an email — the SPA reads the
     * opaque token off the URL and posts it here; this exchanges it with the
     * backend server-to-server and applies whatever comes back. The raw
     * license key is never accepted from the client, only relayed from the
     * backend's own response.
     */
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

        // SiteConnector::confirm() already called status->refresh() — read the
        // now-warm cache instead of probing the backend a second time.
        $status = $this->status->current();

        return $this->respond($status['available'], $status);
    }

    /**
     * "Create a new key" (Account page's Danger zone). Branches server-side
     * (SiteConnector::recover()) between an instant key rotation and a
     * fallback through the normal connect() ladder — both outcomes are
     * synchronous now. Deliberately still doesn't return the full
     * connected/masked-key envelope: the caller re-fetches /connection/status
     * itself if it needs the updated masked key.
     */
    public function recover(): WP_REST_Response
    {
        $result = $this->siteConnector->recover();

        return new WP_REST_Response(['ok' => $result['ok'], 'error' => $result['error']], 200);
    }

    /**
     * Account page's "Disconnect from LingoWP Cloud" (Danger zone). Drops the
     * local credential. Nothing is sent to the backend; there is no
     * self-service "revoke this key" route, and there doesn't need to be —
     * an unused key just sits there until reconnected. A missing key is
     * never auto-connected (SiteConnector::ensureValid()), so this stays
     * disconnected until an explicit "Connect" click.
     */
    public function disconnect(): WP_REST_Response
    {
        $this->connection->clear();
        $this->status->clearCache();

        return $this->respond(false, ['available' => false, 'error' => 'ERR_NOT_CONNECTED']);
    }
}
