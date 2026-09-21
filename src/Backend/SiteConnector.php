<?php

namespace LingoWP\Backend;

final class SiteConnector
{
    private BackendClient $backend;
    private ConnectionRepository $connection;
    private SiteMetadataProvider $metadata;
    private ConnectionStatus $status;
    private BillingEntitlementsService $entitlements;

    public function __construct(
        BackendClient $backend,
        ConnectionRepository $connection,
        SiteMetadataProvider $metadata,
        ConnectionStatus $status,
        BillingEntitlementsService $entitlements
    ) {
        $this->backend      = $backend;
        $this->connection   = $connection;
        $this->metadata     = $metadata;
        $this->status       = $status;
        $this->entitlements = $entitlements;
    }

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

    public function applyCredential(string $siteId, string $licenseKey): void
    {
        $this->connection->store($siteId, $licenseKey);
        $this->connection->storeSyncedAdminEmail((string) get_option('admin_email', ''));
        $this->status->refresh();
    }

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

    public function ensureValid(): array
    {
        $status = $this->status->refresh();

        $needsConnect = in_array($status['error'], [
            'ERR_INVALID_TOKEN', 'ERR_LICENSE_DETACHED', 'ERR_LICENSE_REVOKED',
        ], true);

        if (!$needsConnect) {
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
