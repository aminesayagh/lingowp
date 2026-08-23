<?php

namespace TransLangs\Backend;

use TransLangs\Backend\ConnectionRepository;
use TransLangs\Backend\ConnectionStatus;
use TransLangs\Backend\SiteConnector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Connection lifecycle REST endpoints (namespace translangs/v1).
 *
 * manage_options only; never returns the raw license key, and never accepts
 * one from the client either — confirm() only ever takes an opaque confirm
 * token and exchanges it server-to-server. A stale key (401/410) is self-healed
 * by SiteConnector::ensureValid(); a still-valid-but-blocked key (402/409) is
 * left in place and only surfaced as a notice. Every state change refreshes the
 * cached AI-availability status.
 */
final class ConnectionRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
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
        register_rest_route(self::NS, '/connection/confirm', ['methods' => 'POST', 'callback' => [$this, 'confirm']] + $guard);
        register_rest_route(self::NS, '/connection/recover', ['methods' => 'POST', 'callback' => [$this, 'recover']] + $guard);
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
     * Verify the credential against GET /me/entitlements and refresh the
     * cached AI status — self-healing a stale key (401/410) in the process.
     */
    public function check(): WP_REST_Response
    {
        $status = $this->siteConnector->ensureValid();

        return $this->respond($status['available'], $status, $status['pending']);
    }

    /** @param array{available: bool, error: string} $status */
    private function respond(bool $ok, array $status, bool $pending = false): WP_REST_Response
    {
        return rest_ensure_response([
            'ok'           => $ok,
            'connected'    => $this->connection->isConnected(),
            'pending'      => $pending,
            'masked_key'   => $this->connection->maskedKey(),
            'ai_available' => $status['available'],
            'ai_error'     => $status['error'],
        ]);
    }

    /**
     * Connect (or reconnect) using the current site metadata. Used to finish
     * connecting after an offline onboarding, or as an explicit user-initiated
     * retry. Never blocks: returns an ERR_* code on failure. A pending result
     * means the backend emailed a confirm token — nothing is connected yet,
     * so this must never be reported as ERR_NOT_CONNECTED.
     */
    public function connect(): WP_REST_Response
    {
        $result = $this->siteConnector->connect();

        if ($result['pending']) {
            return $this->respond(true, ['available' => false, 'error' => ''], true);
        }

        if (!$result['ok']) {
            return new WP_REST_Response(['ok' => false, 'connected' => false, 'pending' => false, 'error' => $result['error']], 200);
        }

        // SiteConnector::connect() already called status->refresh() — read the
        // now-warm cache instead of probing the backend a second time.
        $status = $this->status->current();

        return $this->respond($status['available'], $status);
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
     * Manually request a recovery email for this domain (Billing page's
     * "Create a new key"). Deliberately does not call respond(): nothing about
     * local connection state changes here — the current key keeps working
     * normally (backend-side it's now only a *pending* rotation) until either
     * the admin follows the emailed link into confirm() above, or the
     * backend's 24h grace window lapses unused, so returning the full
     * connected/masked-key envelope would imply a change that hasn't
     * happened yet.
     */
    public function recover(): WP_REST_Response
    {
        $result = $this->siteConnector->recover();

        return new WP_REST_Response(['ok' => $result['ok'], 'error' => $result['error']], 200);
    }
}
