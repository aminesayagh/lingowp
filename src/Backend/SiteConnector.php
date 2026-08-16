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
     * @return array{ok: bool, error: string} error is '' on success.
     */
    public function connect(): array
    {
        $result = $this->backend->issueLicense($this->metadata->buildForCreate());

        if (!$result['ok']) {
            // This exact domain already has an active site under some license —
            // issuing a fresh one is refused, and the existing key is never
            // revealed. The only way back in is a recovery email, so trigger it
            // automatically: recover() has no rate limit/cooldown, and the caller
            // already knows (from this very error) that a matching site exists,
            // so this is never a wasted/speculative call.
            if ($result['error'] === 'ERR_DOMAIN_ALREADY_LICENSED') {
                $this->backend->recover(['domain' => $this->connection->domain()]);
            }

            return ['ok' => false, 'error' => $result['error']];
        }

        $data       = is_array($result['data']) ? $result['data'] : [];
        $siteId     = (string) ($data['site_id'] ?? '');
        $licenseKey = (string) ($data['license_key'] ?? '');

        if ($licenseKey === '') {
            return ['ok' => false, 'error' => 'ERR_BACKEND'];
        }

        $this->applyCredential($siteId, $licenseKey);

        return ['ok' => true, 'error' => ''];
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
     * Manually triggers the same /license/recover flow connect() already
     * fires automatically on ERR_DOMAIN_ALREADY_LICENSED — except here the
     * caller's current key is still valid when this runs (e.g. the Billing
     * page's "Create a new key" button). The backend mints a new *pending*
     * key and emails it; the current key keeps authenticating normally in the
     * meantime — the cutover only happens once the new key is used
     * successfully for the first time (via the emailed link →
     * ConnectionRestController::applyLicense()), or automatically after its
     * 24h grace window, whichever comes first. Local state is untouched
     * either way until that cutover actually happens.
     *
     * @return array{ok: bool, error: string}
     */
    public function recover(): array
    {
        $result = $this->backend->recover(['domain' => $this->connection->domain()]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }
}
