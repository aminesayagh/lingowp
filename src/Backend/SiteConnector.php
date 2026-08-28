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
        private ConnectionStatus $status,
        private BillingEntitlementsService $entitlements
    ) {}

    /**
     * @return array{ok: bool, error: string, recoveryAvailable: bool}
     *   error is '' on success. connect() never blocks and never emails
     *   anything itself — every success response carries a working key,
     *   issued and stored synchronously. attach-website (a *different* site
     *   added from the owner's own dashboard) is a confirm()-only concern
     *   now, never something this call waits on.
     *   recoveryAvailable is true when a *separate* reclaim email also went
     *   to the verified owner of a matching older licence — this install
     *   already works either way, it's a non-blocking bonus signal.
     */
    public function connect(): array
    {
        $result = $this->backend->connect($this->metadata->buildForCreate());

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'recoveryAvailable' => false];
        }

        $data       = is_array($result['data']) ? $result['data'] : [];
        $licenseKey = (string) ($data['license_key'] ?? '');

        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'ERR_BACKEND', 'recoveryAvailable' => false];
        }

        $siteId = (string) ($data['site_id'] ?? '');
        $this->applyCredential($siteId, $licenseKey);

        return ['ok' => true, 'error' => '', 'recoveryAvailable' => !empty($data['recovery_available'])];
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
        // The connect payload just told the backend this site's current
        // admin_email — cache it as synced so the very next per-load check
        // doesn't immediately re-push the same value it was just given.
        $this->connection->storeSyncedAdminEmail((string) get_option('admin_email', ''));
        $this->status->refresh();
    }

    /**
     * "Create a new key" (Billing → Danger zone). Branches on whether the
     * stored key still authenticates: a live key rotates in place via
     * POST /license/rotate-key (synchronous, old key dies immediately); a
     * dead key (not connected, invalid, detached, or revoked) falls back to
     * the normal connect() ladder instead — connect() must never be used to
     * rotate a key that's still working, or a paid licence would be swapped
     * for a fresh free one.
     *
     * @return array{ok: bool, error: string}
     */
    public function recover(): array
    {
        $probe        = $this->status->refresh();
        $needsConnect = in_array($probe['error'], [
            'ERR_NOT_CONNECTED', 'ERR_INVALID_TOKEN', 'ERR_LICENSE_DETACHED', 'ERR_LICENSE_REVOKED',
        ], true);

        if ($needsConnect) {
            $result = $this->connect();

            return ['ok' => $result['ok'], 'error' => $result['error']];
        }

        $result = $this->backend->rotateKey();
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $data       = is_array($result['data']) ? $result['data'] : [];
        $licenseKey = (string) ($data['license_key'] ?? '');
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'ERR_BACKEND'];
        }

        $this->connection->store($this->connection->siteId(), $licenseKey);
        $this->status->refresh();

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Applies a confirm_token deep-linked from an email. The backend's
     * confirm endpoint is one unified route for many kinds of pending action
     * — this is the plugin's matching single relay point, branching on which
     * shape the response actually carries rather than needing the caller to
     * know in advance what kind of token it's handing over:
     *   - { owner_email } — POST /owner/assign-owner's confirm. There is no
     *     backend read endpoint for this yet, so this is the only moment the
     *     plugin ever learns the verified address; it's cached locally
     *     (ConnectionRepository::storeVerifiedOwnerEmail()) so the Billing
     *     page can read it back on a later visit.
     *   - { license_key, site_id } — attach-website: a *different* site was
     *     just added from the owner's own Translangs dashboard, not
     *     something this install initiated. Never trusts a key handed in by
     *     the browser — the raw key only ever comes from this
     *     server-to-server exchange.
     *   - { site_id } alone, no license_key — a licence reclaim. The key
     *     already stored on this install is correct as-is (it's now bound to
     *     the reclaimed licence server-side); nothing local to change beyond
     *     making sure the next entitlements read isn't served from the
     *     pre-reclaim cache.
     *
     * @return array{ok: bool, error: string}
     */
    public function confirm(string $token): array
    {
        $result = $this->backend->confirmToken($token);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $data = is_array($result['data']) ? $result['data'] : [];

        $ownerEmail = isset($data['owner_email']) ? (string) $data['owner_email'] : '';
        if ($ownerEmail !== '') {
            $this->connection->storeVerifiedOwnerEmail($ownerEmail);

            return ['ok' => true, 'error' => ''];
        }

        $licenseKey = (string) ($data['license_key'] ?? '');
        if ($licenseKey !== '') {
            $siteId = isset($data['site_id']) ? (string) $data['site_id'] : $this->connection->siteId();
            $this->applyCredential($siteId, $licenseKey);

            return ['ok' => true, 'error' => ''];
        }

        if (isset($data['site_id'])) {
            $this->entitlements->invalidate();
            $this->status->refresh();

            return ['ok' => true, 'error' => ''];
        }

        return ['ok' => false, 'error' => 'ERR_BACKEND'];
    }

    /**
     * The one place a stale local credential self-heals. A 401 (unrecognised),
     * 402 (detached from its billing account), or 410 (revoked) key are all
     * dead the same way — the fix in every case is to drop it and connect()
     * again, which either issues a fresh working key synchronously or, for
     * attach-website, waits on a confirm email. There is no more "leave it
     * alone" case: domain no longer factors into auth at all.
     *
     * On a healthy key, this is also where the two out-of-band sync checks
     * run once per admin page load (see syncDomainIfChanged()/
     * syncAdminEmailIfChanged()) — this method is already the single place
     * hit once per load via /connection/check, from every SPA root.
     *
     * @return array{available: bool, error: string}
     */
    public function ensureValid(): array
    {
        $status = $this->status->refresh();

        if (!in_array($status['error'], ['ERR_INVALID_TOKEN', 'ERR_LICENSE_DETACHED', 'ERR_LICENSE_REVOKED'], true)) {
            if ($status['available']) {
                $this->syncDomainIfChanged();
                $this->syncAdminEmailIfChanged();
            }

            return $status;
        }

        $this->connection->clear();
        $result = $this->connect();

        if (!$result['ok']) {
            return ['available' => false, 'error' => $result['error']];
        }

        return $this->status->current();
    }

    /**
     * Domain drift self-heal (localhost → production migrations, staging
     * clones that inherited this site's key, or an outright DNS/URL change):
     * compares what the backend has on file (GET /me/entitlements's
     * site_domain) against this site's current host, and pushes the update
     * when they differ. Reads the *raw* entitlements payload rather than
     * BillingEntitlementsService::data()'s typed shape — site_domain stays an
     * internal-only field, nothing in the frontend needs it. Piggybacks on
     * the entitlements cache ConnectionStatus::refresh() just warmed, so this
     * costs nothing extra in the common case.
     */
    public function syncDomainIfChanged(): void
    {
        $raw = $this->entitlements->rawEntitlements();
        if (!$raw['ok'] || !is_array($raw['data'])) {
            return;
        }

        $backendDomain = (string) ($raw['data']['site_domain'] ?? '');
        $currentDomain = $this->connection->domain();
        if ($backendDomain === '' || $backendDomain === $currentDomain) {
            return;
        }

        $result = $this->backend->updateDomain($currentDomain);
        if ($result['ok']) {
            $this->entitlements->invalidate();
        }
    }

    /**
     * Admin-email drift self-heal — the WP-native counterpart to
     * syncDomainIfChanged(). Called once per admin page load (via
     * ensureValid()) and directly from the update_option_admin_email hook
     * (Plugin.php) so an in-session change lands immediately instead of
     * waiting for the next load.
     */
    public function syncAdminEmailIfChanged(?string $newEmail = null): void
    {
        if (!$this->connection->isConnected()) {
            return;
        }

        $newEmail ??= (string) get_option('admin_email', '');
        if ($newEmail === '' || $newEmail === $this->connection->syncedAdminEmail()) {
            return;
        }

        $result = $this->backend->updateAdminEmail($newEmail);
        if ($result['ok']) {
            $this->connection->storeSyncedAdminEmail($newEmail);
        }
    }
}
