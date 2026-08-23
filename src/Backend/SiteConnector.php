<?php

namespace TransLangs\Backend;

/**
 * Registers (or re-provisions) this site with the backend using its current
 * metadata, storing the returned token and warming the status cache.
 *
 * Extracted so the same sequence isn't hand-copied at every call site: onboarding's
 * first connect and the manual /connection/connect retry.
 */
final class SiteConnector
{
    public function __construct(
        private BackendClient $backend,
        private ConnectionRepository $connection,
        private SiteMetadataProvider $metadata,
        private ConnectionStatus $status
    ) {}

    /**
     * @return array{ok: bool, pending: bool, error: string} error is '' on success.
     *   pending is true when the backend emailed a confirm token instead of
     *   returning a key directly — nothing is stored yet, the caller must
     *   wait for /connection/confirm. pending is always false when ok is false.
     */
    public function connect(): array
    {
        $result = $this->backend->connect($this->metadata->buildForCreate());

        if (!$result['ok']) {
            return ['ok' => false, 'pending' => false, 'error' => $result['error']];
        }

        $data = is_array($result['data']) ? $result['data'] : [];

        // A token email went out (recover/rotate, domain_reuse_claim, or
        // attach_website) — nothing to store until it's confirmed.
        if (!empty($data['sent'])) {
            return ['ok' => true, 'pending' => true, 'error' => ''];
        }

        $siteId     = (string) ($data['site_id'] ?? '');
        $licenseKey = (string) ($data['license_key'] ?? '');

        if ($licenseKey === '') {
            return ['ok' => false, 'pending' => false, 'error' => 'ERR_BACKEND'];
        }

        $this->applyCredential($siteId, $licenseKey);

        return ['ok' => true, 'pending' => false, 'error' => ''];
    }

    /**
     * Store a (newly issued or recovered) credential and warm the AI-status
     * cache immediately instead of waiting for the next page load. Shared by
     * connect()'s success path and applying a recovered license
     * (ConnectionRestController::applyLicense()) — same aftermath either way.
     */
    public function applyCredential(string $siteId, string $licenseKey): void
    {
        $this->connection->store($siteId, $licenseKey);
        $this->status->refresh();
    }

    /**
     * Manually triggers a rotate (e.g. the Billing page's "Create a new key"
     * button). There is no separate recover endpoint anymore — this just
     * calls connect() again with the site's own current domain+admin_email,
     * which deterministically lands on the ladder's row 1 (an exact site
     * match) and emails a rotate token to that site's own admin_email. The
     * current key keeps authenticating normally in the meantime; the cutover
     * only happens once the emailed link is confirmed (via
     * ConnectionRestController::confirm()). Local state is untouched until
     * that confirm actually happens.
     *
     * @return array{ok: bool, error: string}
     */
    public function recover(): array
    {
        $result = $this->connect();

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * Applies a confirm_token deep-linked from an email (recover/rotate,
     * domain_reuse_claim, or attach_website). Never trusts a key handed in
     * by the browser — the raw key only ever comes from this server-to-server
     * exchange with the backend. site_id falls back to whatever is already on
     * file when the response omits it (recover/rotate and domain_reuse_claim
     * repoint the existing site rather than creating a new one).
     *
     * @return array{ok: bool, error: string}
     */
    public function confirm(string $token): array
    {
        $result = $this->backend->confirmToken($token);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $data       = is_array($result['data']) ? $result['data'] : [];
        $licenseKey = (string) ($data['license_key'] ?? '');

        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'ERR_BACKEND'];
        }

        $siteId = isset($data['site_id']) ? (string) $data['site_id'] : $this->connection->siteId();

        $this->applyCredential($siteId, $licenseKey);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * The one place a stale local credential self-heals. ERR_INVALID_TOKEN
     * (401 — unrecognised) and ERR_LICENSE_REVOKED (410) both mean the key
     * itself is dead, so the fix is the same either way: drop it and connect
     * again. ERR_LICENSE_DETACHED (402) and ERR_DOMAIN_MISMATCH (409) are
     * deliberately left alone — the key is still valid, and the backend
     * contract requires keeping it and only surfacing a notice, never
     * auto-reconnecting.
     *
     * @return array{available: bool, error: string, pending: bool}
     */
    public function ensureValid(): array
    {
        $status = $this->status->refresh();

        if (!in_array($status['error'], ['ERR_INVALID_TOKEN', 'ERR_LICENSE_REVOKED'], true)) {
            return $status + ['pending' => false];
        }

        $this->connection->clear();
        $result = $this->connect();

        if (!$result['ok']) {
            return ['available' => false, 'error' => $result['error'], 'pending' => false];
        }

        // A stale key's own domain+admin_email almost always still matches its
        // own site record, so this heal typically re-lands on a fresh rotate
        // email rather than an immediate key — that pending signal must reach
        // the caller, or it looks indistinguishable from "never connected".
        if ($result['pending']) {
            return ['available' => false, 'error' => '', 'pending' => true];
        }

        return $this->status->current() + ['pending' => false];
    }
}
