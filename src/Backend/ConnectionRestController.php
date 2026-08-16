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
 * manage_options only; never returns the raw token. On a 401 the token is left in
 * place (cleared only on explicit disconnect/reconnect) so a transient backend
 * hiccup does not silently disconnect the site. Every state change refreshes the
 * cached AI-availability status.
 */
final class ConnectionRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private ConnectionRepository $connection,
        private ConnectionStatus $status,
        private SiteConnector $siteConnector,
        private AiQuotaService $quota
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
        register_rest_route(self::NS, '/connection/apply-license', ['methods' => 'POST', 'callback' => [$this, 'applyLicense']] + $guard);
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

    /** Verify the credential against GET /usage and refresh the cached AI status. */
    public function check(): WP_REST_Response
    {
        $status = $this->status->refresh();

        return $this->respond($status['available'], $status);
    }

    /** @param array{available: bool, error: string} $status */
    private function respond(bool $ok, array $status): WP_REST_Response
    {
        return rest_ensure_response([
            'ok'           => $ok,
            'connected'    => $this->connection->isConnected(),
            'masked_key'   => $this->connection->maskedKey(),
            'ai_available' => $status['available'],
            'ai_error'     => $status['error'],
            'quota'        => $this->quota->data(),
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

        return $this->respond($status['available'], $status);
    }

    /**
     * Applies a license key recovered out-of-band — the "Refresh license in
     * WordPress" link in a /license/recover email deep-links here with the new
     * raw key as a query param; the SPA reads it and posts it to this route.
     * The site id is left as whatever is already stored (recovery repoints the
     * same site at a new license, it never creates a new one), or empty on a
     * fully wiped install — site_id isn't sent on any request, so an empty
     * value is harmless.
     */
    public function applyLicense(WP_REST_Request $request): WP_REST_Response
    {
        $licenseKey = sanitize_text_field((string) $request->get_param('license_key'));

        if ($licenseKey === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $this->siteConnector->applyCredential($this->connection->siteId(), $licenseKey);

        // applyCredential() already called status->refresh() — read the
        // now-warm cache instead of probing the backend a second time.
        $status = $this->status->current();

        return $this->respond($status['available'], $status);
    }

    /**
     * Manually request a recovery email for this domain (Billing page's
     * "Create a new key"). Deliberately does not call respond(): nothing about
     * local connection state changes here — the current key keeps working
     * normally (backend-side it's now only a *pending* rotation) until either
     * the admin follows the emailed link into applyLicense() above, or the
     * backend's 24h grace window lapses unused, so returning the full
     * connected/masked-key/quota envelope would imply a change that hasn't
     * happened yet.
     */
    public function recover(): WP_REST_Response
    {
        $result = $this->siteConnector->recover();

        return new WP_REST_Response(['ok' => $result['ok'], 'error' => $result['error']], 200);
    }
}
