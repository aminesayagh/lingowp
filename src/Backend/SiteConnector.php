<?php

namespace TransLangs\Backend;

/**
 * Registers (or re-provisions) this site with the backend using its current
 * metadata, storing the returned token and warming the status cache.
 *
 * Extracted so the same sequence isn't hand-copied at every call site: onboarding's
 * first connect, the manual /connection/connect retry, and TokenRotationJob's
 * fallback when a token can no longer rotate itself (401).
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
     * @return array{ok: bool, error: string} error is '' on success.
     */
    public function connect(): array
    {
        $result = $this->backend->createSite($this->metadata->buildForCreate());

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $data   = is_array($result['data']) ? $result['data'] : [];
        $siteId = (string) ($data['site_id'] ?? '');
        $token  = (string) ($data['api_token'] ?? $data['token'] ?? '');

        if ($token === '') {
            return ['ok' => false, 'error' => 'ERR_BACKEND'];
        }

        $this->connection->store($siteId, $token);
        $this->status->refresh();
        $this->status->syncSchedule();

        return ['ok' => true, 'error' => ''];
    }
}
